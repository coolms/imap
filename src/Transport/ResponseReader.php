<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Exception\TimedOut;
use InvalidArgumentException;

use function fgets;
use function fread;
use function is_resource;
use function preg_match;
use function str_ends_with;
use function stream_get_meta_data;
use function strlen;
use function substr;

/**
 * Reads whole responses off a stream: a line, and when it ends in a literal's announcement -- "{n}" or "~{n}" before
 * CRLF -- exactly n more bytes, then the rest of the response, as often as it announces one. What it returns ends
 * without its final CRLF, and is what the tokenizer reads.
 *
 * Bounded: a line longer than the line limit, or a literal larger than the literal limit, is a ProtocolError before
 * it is read into memory; a stream that ends inside a response is ConnectionClosed -- never a shorter response handed
 * on as if it were whole.
 */
final readonly class ResponseReader
{
    /** @var positive-int */
    private int $lineLimit;

    /** @var positive-int */
    private int $literalLimit;

    /** @param resource $stream */
    public function __construct(
        private mixed $stream,
        int $lineLimit = 65536,
        int $literalLimit = 64 * 1024 * 1024,
    ) {
        $this->lineLimit = $lineLimit >= 1 ? $lineLimit : throw new InvalidArgumentException('A line limit is above 0.');
        $this->literalLimit = $literalLimit >= 1
            ? $literalLimit
            : throw new InvalidArgumentException('A literal limit is above 0.');
    }

    public function next(): string
    {
        $response = '';
        while (true) {
            $line = $this->line();
            if (1 === preg_match('/~?\{(\d+)\+?\}\z/', $line, $m)) {
                $size = (int) $m[1];
                if (strlen($m[1]) > 12 || $size > $this->literalLimit) {
                    throw new ProtocolError($response . $line, strlen($response . $line), 'a literal of ' . $m[1] . ' bytes is over the limit of ' . $this->literalLimit);
                }
                $response .= $line . "\r\n" . $this->bytes($size);

                continue;
            }

            return $response . $line;
        }
    }

    /** One line, its CRLF removed. */
    private function line(): string
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionClosed('The connection is closed.');
        }
        $line = fgets($this->stream, $this->lineLimit + 3);
        if (false === $line) {
            $this->timedOut();

            throw new ConnectionClosed('The server closed the connection inside a response.');
        }
        if (!str_ends_with($line, "\n")) {
            if (strlen($line) >= $this->lineLimit + 2) {
                throw new ProtocolError($line, $this->lineLimit, 'a line longer than ' . $this->lineLimit . ' bytes');
            }

            $this->timedOut();

            throw new ConnectionClosed('The server closed the connection inside a line.');
        }

        return str_ends_with($line, "\r\n") ? substr($line, 0, -2) : substr($line, 0, -1);
    }

    private function bytes(int $size): string
    {
        $read = '';
        while (($left = $size - strlen($read)) > 0) {
            $chunk = fread($this->stream, $left);
            if (false === $chunk || '' === $chunk) {
                $this->timedOut();

                throw new ConnectionClosed('The server closed the connection inside a literal of ' . $size . ' bytes.');
            }
            $read .= $chunk;
        }

        return $read;
    }

    /** A read that came back short because the wait ran out -- not because the stream ended. */
    private function timedOut(): void
    {
        if (stream_get_meta_data($this->stream)['timed_out']) {
            throw new TimedOut('The server sent nothing within the connection\'s timeout.');
        }
    }
}
