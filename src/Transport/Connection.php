<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use Closure;
use CoolMS\Imap\Exception\CommandFailed;
use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\ResponseKind;
use CoolMS\Imap\Sasl\Mechanism;
use LogicException;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function count;
use function fwrite;
use function hrtime;
use function in_array;
use function intdiv;
use function is_resource;
use function stream_get_meta_data;
use function stream_select;
use function strlen;
use function substr;

/**
 * One IMAP connection, one command at a time (RFC 9051 s.2.2, s.5.5): it tags each command, sends a synchronizing
 * literal only after the server's "+", and collects the untagged responses up to the command's tagged completion.
 *
 * A NO or BAD completion is a Reply like an OK; what it means is the caller's. What is not a reply is: a completion
 * with another command's tag, a "+" nothing asked for -- a ProtocolError -- and the stream ending -- ConnectionClosed,
 * naming the server's BYE when one came first.
 *
 * The log hook, when given, sees each command's log form (Command::logged) and each server response cut at 200
 * bytes; what the client answers inside AUTHENTICATE it sees as "<secret>" only.
 */
final class Connection
{
    private const string DONE_SENT = 'done-sent';

    private const string IDLE_ASKED = 'idle-asked';

    private int $sent = 0;
    private ?string $idleTag = null;

    /** While an IDLE is renewed without blocking: DONE sent and its completion awaited, or IDLE asked and its "+". */
    private ?string $renewing = null;

    /**
     * @param resource                             $stream
     * @param (Closure(string, string): void)|null $log    "C" or "S", and the line
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly ResponseReader $reader,
        private readonly ?Closure $log = null,
    ) {
    }

    /** The server's first words: OK, PREAUTH -- or BYE, after which nothing else is coming. */
    public function greeting(): Response
    {
        $greeting = $this->read();
        if (
            ResponseKind::UntaggedStatus !== $greeting->kind
            || !in_array($greeting->status, ['OK', 'PREAUTH', 'BYE'], true)
        ) {
            throw new ProtocolError($greeting->text, 0, 'a greeting is "* OK", "* PREAUTH" or "* BYE"');
        }

        return $greeting;
    }

    public function run(Command $command, bool $literalPlus = false): Reply
    {
        $tag = $this->tag();
        $this->log('C', $command->logged($tag));
        $pieces = $command->encode($tag, $literalPlus);
        $untagged = [];
        $last = count($pieces) - 1;
        foreach ($pieces as $i => $piece) {
            $this->send($piece);
            if ($i === $last) {
                break;
            }
            // The server answers a literal's announcement with "+", or -- refusing it -- with the completion.
            $response = $this->until($tag, $untagged, true);
            if (ResponseKind::Tagged === $response->kind) {
                return new Reply($response, $untagged);
            }
        }

        return new Reply($this->until($tag, $untagged, false), $untagged);
    }

    /**
     * AUTHENTICATE (RFC 9051 s.6.2.2): with SASL-IR (RFC 4959) the first response rides on the command line; without
     * it, it answers the server's first "+". A challenge that is not base64 is cancelled with "*", and the server's
     * completion to that is the reply.
     */
    public function authenticate(Mechanism $mechanism, bool $saslIr): Reply
    {
        $tag = $this->tag();
        $initial = $mechanism->initialResponse();
        $inline = $saslIr && null !== $initial;
        $line = $tag . ' AUTHENTICATE ' . $mechanism->name();
        $this->log('C', $line . ($inline ? ' <secret>' : ''));
        $this->send($line . ($inline ? ' ' . ('' === $initial ? '=' : base64_encode($initial)) : '') . "\r\n");
        $pending = $inline ? null : $initial;
        $untagged = [];
        while (true) {
            $response = $this->until($tag, $untagged, true);
            if (ResponseKind::Tagged === $response->kind) {
                return new Reply($response, $untagged);
            }
            if (null !== $pending) {
                $answer = base64_encode($pending);
                $pending = null;
            } else {
                $challenge = base64_decode($response->text, true);
                $answer = false === $challenge ? '*' : base64_encode($mechanism->respond($challenge));
            }
            $this->log('C', '*' === $answer ? '*' : '<secret>');
            $this->send($answer . "\r\n");
        }
    }

    /**
     * IDLE (RFC 2177, RFC 9051 s.6.3.13): each untagged response, as it comes, goes to $each; IDLE ends with DONE when
     * $each returns false or $seconds have passed -- measured on the monotonic clock, never the wall clock, which
     * steps -- and the reply holds every response seen.
     *
     * @param Closure(Response): bool $each
     */
    public function idle(Closure $each, float $seconds): Reply
    {
        $tag = $this->tag();
        $this->log('C', $tag . ' IDLE');
        $this->send($tag . " IDLE\r\n");
        $untagged = [];
        $response = $this->until($tag, $untagged, true);
        if (ResponseKind::Tagged === $response->kind) {
            return new Reply($response, $untagged);
        }
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        while (($left = $deadline - hrtime(true)) > 0) {
            $read = [$this->stream];
            $none = [];
            $ready = @stream_select(
                $read,
                $none,
                $none,
                intdiv($left, 1_000_000_000),
                intdiv($left % 1_000_000_000, 1000),
            );
            if (0 === $ready) {
                break;
            }
            $response = $this->read();
            if (ResponseKind::UntaggedData !== $response->kind && ResponseKind::UntaggedStatus !== $response->kind) {
                throw new ProtocolError($response->text, 0, 'only untagged responses come during IDLE');
            }
            $untagged[] = $response;
            if (!$each($response)) {
                break;
            }
        }
        $this->log('C', 'DONE');
        $this->send("DONE\r\n");

        return new Reply($this->until($tag, $untagged, false), $untagged);
    }

    /**
     * IDLE in pieces, for a Watch that waits on several connections at once: begin (IDLE, then the server's "+"),
     * each untagged response as it comes, then end (DONE, then the completion). Null from beginIdle() is "idling";
     * a Reply is the server's refusal, and nothing is idling.
     */
    public function beginIdle(): ?Reply
    {
        $tag = $this->idleTag = $this->tag();
        $this->log('C', $tag . ' IDLE');
        $this->send($tag . " IDLE\r\n");
        $untagged = [];
        $response = $this->until($tag, $untagged, true);
        if (ResponseKind::Tagged === $response->kind) {
            $this->idleTag = null;

            return new Reply($response, $untagged);
        }

        return null;
    }

    /** The next response while idling: untagged only, as RFC 2177 has it. It blocks until one comes. */
    public function idleNext(): Response
    {
        if (null === $this->idleTag) {
            throw new LogicException('Not idling.');
        }
        $response = $this->read();
        if (ResponseKind::UntaggedData !== $response->kind && ResponseKind::UntaggedStatus !== $response->kind) {
            throw new ProtocolError($response->text, 0, 'only untagged responses come during IDLE');
        }

        return $response;
    }

    /**
     * DONE, and the server's completion of the IDLE, with whatever came between. During a renewal it finishes what is
     * under way instead: a DONE already sent is not sent again, and an IDLE already asked for is let in before it ends.
     */
    public function endIdle(): Reply
    {
        $tag = $this->idleTag ?? throw new LogicException('Not idling.');
        $untagged = [];
        if (self::IDLE_ASKED === $this->renewing) {
            $plus = $this->until($tag, $untagged, true);
            if (ResponseKind::Tagged === $plus->kind) {
                $this->idleTag = $this->renewing = null;

                return new Reply($plus, $untagged);
            }
        }
        if (self::DONE_SENT !== $this->renewing) {
            $this->log('C', 'DONE');
            $this->send("DONE\r\n");
        }
        $this->idleTag = $this->renewing = null;

        return new Reply($this->until($tag, $untagged, false), $untagged);
    }

    /**
     * Renews the IDLE without waiting (RFC 2177 asks for one at least every 29 minutes): DONE is sent here, and
     * {@see idleRead()} takes the completion, asks for IDLE again and takes its "+" as they come.
     */
    public function renewIdle(): void
    {
        if (null === $this->idleTag) {
            throw new LogicException('Not idling.');
        }
        if (null !== $this->renewing) {
            return;
        }
        $this->log('C', 'DONE');
        $this->send("DONE\r\n");
        $this->renewing = self::DONE_SENT;
    }

    /** Whether a renewal is under way: its DONE or its new IDLE not answered yet. */
    public function renewing(): bool
    {
        return null !== $this->renewing;
    }

    /**
     * The next response while idling or renewing: an untagged one, or null for a step of the renewal (the DONE's
     * completion, after which IDLE is asked for again, or the new IDLE's "+"). It blocks until one comes. A renewal
     * the server refuses is a CommandFailed, and nothing is idling.
     */
    public function idleRead(): ?Response
    {
        $tag = $this->idleTag ?? throw new LogicException('Not idling.');
        $response = $this->read();
        if (ResponseKind::UntaggedStatus === $response->kind && 'BYE' === $response->status) {
            $this->idleTag = $this->renewing = null;

            throw new ConnectionClosed('The server said BYE: ' . $response->text);
        }
        if (ResponseKind::UntaggedData === $response->kind || ResponseKind::UntaggedStatus === $response->kind) {
            return $response;
        }
        if (self::DONE_SENT === $this->renewing && ResponseKind::Tagged === $response->kind && $tag === $response->tag) {
            if ('OK' !== $response->status) {
                $this->idleTag = $this->renewing = null;

                throw new CommandFailed('IDLE', $response);
            }
            $next = $this->idleTag = $this->tag();
            $this->log('C', $next . ' IDLE');
            $this->send($next . " IDLE\r\n");
            $this->renewing = self::IDLE_ASKED;

            return null;
        }
        if (self::IDLE_ASKED === $this->renewing) {
            if (ResponseKind::Continuation === $response->kind) {
                $this->renewing = null;

                return null;
            }
            // Tagged, then: the new IDLE refused.
            if ($tag === $response->tag) {
                $this->idleTag = $this->renewing = null;

                throw new CommandFailed('IDLE', $response);
            }
        }

        throw new ProtocolError($response->text, 0, 'only untagged responses come during IDLE');
    }

    /**
     * Bytes PHP has already read from the socket for this connection and not handed out: a TLS record can carry two
     * responses, and after the first is read the second waits here, where a poll() on the socket never sees it.
     */
    public function buffered(): bool
    {
        return is_resource($this->stream) && stream_get_meta_data($this->stream)['unread_bytes'] > 0;
    }

    /** @return resource the stream, for a poller to wait on */
    public function stream(): mixed
    {
        return $this->stream;
    }

    /** @param list<Response> $untagged */
    private function until(string $tag, array &$untagged, bool $continuation): Response
    {
        $bye = null;
        while (true) {
            try {
                $response = $this->read();
            } catch (ConnectionClosed $closed) {
                throw null === $bye ? $closed : new ConnectionClosed('The server said BYE: ' . $bye->text, 0, $closed);
            }
            if (ResponseKind::Continuation === $response->kind) {
                return $continuation ? $response : throw new ProtocolError('+ ' . $response->text, 0, 'a "+" nothing waits for');
            }
            if (ResponseKind::Tagged === $response->kind) {
                return $response->tag === $tag
                    ? $response
                    : throw new ProtocolError((string) $response->tag, 0, 'a completion tagged "' . $response->tag . '" while "' . $tag . '" runs');
            }
            if ('BYE' === $response->status) {
                $bye = $response;
            }
            $untagged[] = $response;
        }
    }

    private function read(): Response
    {
        $raw = $this->reader->next();
        $this->log('S', strlen($raw) > 200 ? substr($raw, 0, 200) . '...' : $raw);

        return Response::parse($raw);
    }

    /** The wire bytes may hold a password or a token (LOGIN, AUTHENTICATE): never shown in a trace. */
    private function send(#[SensitiveParameter] string $bytes): void
    {
        while ('' !== $bytes) {
            $written = is_resource($this->stream) ? @fwrite($this->stream, $bytes) : false;
            if (false === $written || 0 === $written) {
                throw new ConnectionClosed('The connection closed while a command was being sent.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    private function tag(): string
    {
        return 'a' . ++$this->sent;
    }

    private function log(string $direction, string $line): void
    {
        if (null !== $this->log) {
            ($this->log)($direction, $line);
        }
    }
}
