<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Exception\TimedOut;
use InvalidArgumentException;

use function fread;
use function hrtime;
use function intdiv;
use function is_resource;
use function min;
use function preg_match;
use function str_ends_with;
use function stream_get_meta_data;
use function stream_set_timeout;
use function strlen;
use function strpos;
use function substr;

/**
 * Reads whole responses off a stream: a line, and when it ends in a literal's announcement -- "{n}" or "~{n}" before
 * CRLF -- exactly n more bytes, then the rest of the response, as often as it announces one. What it returns ends
 * without its final CRLF, and is what the tokenizer reads.
 *
 * Bounded: a line longer than the line limit, or a literal larger than the literal limit, is a ProtocolError before
 * it is read into memory; a stream that ends inside a response is ConnectionClosed -- never a shorter response handed
 * on as if it were whole.
 *
 * Bounded in time too. A stream's own timeout bounds each wait for the next bytes, not a read: a server sending a
 * byte at a time keeps one fgets() going for as long as it likes. So the bytes are read in chunks into a buffer of
 * the reader's own, and before each chunk the wait is cut to what is left of two deadlines, both on the monotonic
 * clock: `$responseSeconds` for each whole response, and an operation's, set with {@see within()} (the opening of a
 * connection and its sign-in, say). Past either, the read is a TimedOut. Bytes read past the end of a response wait
 * in the buffer for the next: {@see buffered()} says so, since a poll on the socket cannot see them.
 */
final class ResponseReader
{
    private const int CHUNK = 8192;

    private const string LATE = 'The server did not send a whole response in time.';

    /** @var positive-int */
    private int $lineLimit;

    /** @var positive-int */
    private int $literalLimit;

    private string $buffer = '';

    /** When the operation under way must be done by (hrtime nanoseconds); null when none is. */
    private ?int $operationEnd = null;

    /**
     * @param resource   $stream
     * @param float|null $responseSeconds how long a whole response may take; null: no bound beyond each wait's
     * @param float|null $waitSeconds     the stream's own timeout for one wait, put back after a deadline shortened it;
     *                                    to bound an operation shorter than that, use within(), not the stream's
     *                                    timeout
     */
    public function __construct(
        private readonly mixed $stream,
        int $lineLimit = 65536,
        int $literalLimit = 64 * 1024 * 1024,
        private readonly ?float $responseSeconds = null,
        private readonly ?float $waitSeconds = null,
    ) {
        $this->lineLimit = $lineLimit >= 1 ? $lineLimit : throw new InvalidArgumentException('A line limit is above 0.');
        $this->literalLimit = $literalLimit >= 1
            ? $literalLimit
            : throw new InvalidArgumentException('A literal limit is above 0.');
        if ((null !== $responseSeconds && $responseSeconds <= 0) || (null !== $waitSeconds && $waitSeconds <= 0)) {
            throw new InvalidArgumentException('A response time and a wait are above 0.');
        }
    }

    /** Every read from now on must be done within `$seconds`, until it is set again; null ends the bound. */
    public function within(?float $seconds): void
    {
        $this->operationEnd = null === $seconds ? null : self::now() + (int) ($seconds * 1e9);
    }

    /** Whether bytes of a later response are already here, read with an earlier one. */
    public function buffered(): bool
    {
        return '' !== $this->buffer;
    }

    /** How many bytes are here and not handed out yet. */
    public function pending(): int
    {
        return strlen($this->buffer);
    }

    public function next(): string
    {
        $end = $this->operationEnd;
        if (null !== $this->responseSeconds) {
            $own = self::now() + (int) ($this->responseSeconds * 1e9);
            $end = null === $end ? $own : min($end, $own);
        }
        $response = '';
        while (true) {
            $line = $this->line($end);
            if (1 === preg_match('/~?\{(\d+)\+?\}\z/', $line, $m)) {
                $size = (int) $m[1];
                if (strlen($m[1]) > 12 || $size > $this->literalLimit) {
                    throw new ProtocolError($response . $line, strlen($response . $line), 'a literal of ' . $m[1] . ' bytes is over the limit of ' . $this->literalLimit);
                }
                $response .= $line . "\r\n" . $this->bytes($size, $end);

                continue;
            }

            return $response . $line;
        }
    }

    /** The monotonic clock, in nanoseconds. */
    private static function now(): int
    {
        return (int) hrtime(true);
    }

    /** One line, its CRLF removed. */
    private function line(?int $end): string
    {
        while (false === ($at = strpos($this->buffer, "\n"))) {
            if (strlen($this->buffer) >= $this->lineLimit + 2) {
                throw new ProtocolError($this->buffer, $this->lineLimit, 'a line longer than ' . $this->lineLimit . ' bytes');
            }
            $this->fill(
                $end,
                '' === $this->buffer
                    ? 'The server closed the connection inside a response.'
                    : 'The server closed the connection inside a line.',
            );
        }
        if ($at >= $this->lineLimit + 2) {
            throw new ProtocolError($this->buffer, $this->lineLimit, 'a line longer than ' . $this->lineLimit . ' bytes');
        }
        $line = substr($this->buffer, 0, $at + 1);
        $this->buffer = substr($this->buffer, $at + 1);

        return str_ends_with($line, "\r\n") ? substr($line, 0, -2) : substr($line, 0, -1);
    }

    private function bytes(int $size, ?int $end): string
    {
        while (strlen($this->buffer) < $size) {
            $this->fill($end, 'The server closed the connection inside a literal of ' . $size . ' bytes.');
        }
        $read = substr($this->buffer, 0, $size);
        $this->buffer = substr($this->buffer, $size);

        return $read;
    }

    /** One more chunk into the buffer, waiting no longer than the deadline leaves. */
    private function fill(?int $end, string $closed): void
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionClosed('The connection is closed.');
        }
        $wait = null === $this->waitSeconds ? null : (int) ($this->waitSeconds * 1e9);
        // Whether the deadline, not the stream's own timeout, sets this wait: a wait it cut can end a hair before it.
        $cut = false;
        if (null !== $end) {
            $left = $end - self::now();
            if ($left <= 0) {
                throw new TimedOut(self::LATE);
            }
            $cut = null === $wait || $left < $wait;
            $wait = $cut ? $left : $wait;
        }
        // Only a wait the deadline cuts is set here. Otherwise the stream's timeout stays as its owner left it -- the
        // opener's read timeout, or a shorter one a caller chose -- exactly as before there were deadlines.
        if ($cut) {
            stream_set_timeout($this->stream, intdiv($wait, 1_000_000_000), intdiv($wait % 1_000_000_000, 1000));
        }
        $chunk = fread($this->stream, self::CHUNK);
        // Read before the timeout is set again, which may clear it.
        $timedOut = (false === $chunk || '' === $chunk) && stream_get_meta_data($this->stream)['timed_out'];
        if ($cut && null !== $this->waitSeconds) {
            // The cut wait is this read's alone: a write after it (a command, an APPEND's literal) waits as configured.
            $whole = (int) $this->waitSeconds;
            stream_set_timeout($this->stream, $whole, (int) (($this->waitSeconds - $whole) * 1_000_000));
        }
        if (false === $chunk || '' === $chunk) {
            if ($timedOut) {
                throw new TimedOut($cut ? self::LATE : 'The server sent nothing within the connection\'s timeout.');
            }

            throw new ConnectionClosed($closed);
        }
        $this->buffer .= $chunk;
    }
}
