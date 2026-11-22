<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use Closure;
use CoolMS\Imap\Exception\CouldNotOpen;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;
use CoolMS\Imap\Value\Capabilities;
use CoolMS\Imap\Value\Capability;
use Throwable;

use function fclose;
use function hrtime;
use function intdiv;
use function is_resource;
use function min;
use function sprintf;
use function strcasecmp;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function usleep;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

/**
 * Opens a connection to the point where it can authenticate: reached, greeted, secured as the endpoint asks, and its
 * capabilities read -- read again after STARTTLS, since what came in clear may have been changed on the way.
 *
 * Refused, as CouldNotOpen, rather than carried on less safely:
 *  - STARTTLS asked of a server that does not offer it, or that refuses it;
 *  - bytes after STARTTLS's OK and before the handshake -- they came in clear and would read as if they were secured
 *    (the STARTTLS command injection of CVE-2011-0411 and its kin);
 *  - PREAUTH on a connection that was to be secured by STARTTLS: it can no longer be (RFC 9051 s.7.1.4);
 *  - a BYE greeting.
 */
final class Opener
{
    /** How long a bounded TLS handshake pauses when the socket is writable but nothing came to read, in nanoseconds. */
    private const int WRITABLE_PAUSE = 10_000_000;

    private const int TLS = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var Closure(Endpoint): resource */
    private readonly Closure $connect;

    /**
     * @param (Closure(Endpoint): resource)|null   $connect the socket, already connected; tests give their own
     * @param (Closure(string, string): void)|null $log
     */
    public function __construct(
        ?Closure $connect = null,
        private readonly ?Closure $log = null,
    ) {
        $this->connect = $connect ?? self::connect(...);
    }

    /**
     * The TLS context: the certificate verified, against the peer name -- the host's name when the socket goes to an
     * address checked before (a mail egress guard), the host otherwise -- and that name sent as SNI.
     *
     * @return array<string, mixed>
     */
    public static function tlsContext(Endpoint $endpoint): array
    {
        $name = $endpoint->peerName ?? $endpoint->host;

        return [
            'peer_name' => $name,
            'SNI_server_name' => $name,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'crypto_method' => self::TLS,
        ] + (null === $endpoint->caFile ? [] : ['cafile' => $endpoint->caFile]);
    }

    public function open(Endpoint $endpoint): Opened
    {
        $started = hrtime(true);
        $stream = ($this->connect)($endpoint);
        try {
            return $this->greet($endpoint, $stream, $started);
        } catch (Throwable $failed) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $failed;
        }
    }

    private static function fromCode(Response $greeting): ?Capabilities
    {
        $code = $greeting->code?->items() ?? [];
        if ([] === $code || TokenKind::Atom !== $code[0]->kind || 0 !== strcasecmp($code[0]->string(), 'CAPABILITY')) {
            return null;
        }

        return Capabilities::fromTokens(...$code);
    }

    /**
     * The socket, over TLS when the endpoint says so. With an opening deadline, implicit TLS connects over TCP and runs
     * its handshake under what is left of the deadline ({@see handshake()}); without one, as `tls://` always did.
     *
     * @return resource
     */
    private static function connect(Endpoint $endpoint): mixed
    {
        $started = (int) hrtime(true);
        $tls = Security::Tls === $endpoint->security;
        $bounded = $tls && null !== $endpoint->openTimeout;
        $context = stream_context_create(['ssl' => self::tlsContext($endpoint)]);
        $stream = @stream_socket_client(
            ($tls && !$bounded ? 'tls://' : 'tcp://') . $endpoint->host . ':' . $endpoint->port,
            $errno,
            $error,
            null === $endpoint->openTimeout ? $endpoint->connectTimeout : min($endpoint->connectTimeout, $endpoint->openTimeout),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (false === $stream) {
            throw new CouldNotOpen(sprintf('%s:%d could not be reached%s: %s', $endpoint->host, $endpoint->port, $tls ? ' over TLS' : '', '' === $error ? 'no reason given' : $error));
        }
        if ($bounded) {
            try {
                self::handshake($stream, $started + (int) ($endpoint->openTimeout * 1e9), sprintf('%s:%d could not be reached over TLS: the handshake failed', $endpoint->host, $endpoint->port));
            } catch (Throwable $failed) {
                fclose($stream);

                throw $failed;
            }
        }

        return $stream;
    }

    /**
     * The TLS handshake, non-blocking, done by `$end` (hrtime nanoseconds) or a TimedOut. A blocking handshake waits on
     * the stream's own timeout, which bounds each wait for the server's next bytes, not the handshake: a server sending
     * them a few at a time kept it going as long as it liked.
     *
     * @param resource $stream
     */
    private static function handshake(mixed $stream, int $end, string $failed): void
    {
        stream_set_blocking($stream, false);
        try {
            while (true) {
                $done = @stream_socket_enable_crypto($stream, true, self::TLS);
                if (true === $done) {
                    return;
                }
                if (false === $done) {
                    throw new CouldNotOpen($failed . '.');
                }
                $left = $end - (int) hrtime(true);
                if ($left <= 0) {
                    throw new TimedOut('The TLS handshake did not finish in time.');
                }
                // OpenSSL may need to write as well as read, so the wait is for either. A socket is almost always
                // writable, though, and a handshake waiting to read would then be tried again at once: when only
                // writability came, a short pause, so the loop never spins while the server's bytes are on their way.
                /** @var list<resource> $read stream_select() leaves only the ready ones */
                $read = [$stream];
                /** @var list<resource> $write */
                $write = [$stream];
                $none = null;
                $ready = @stream_select($read, $write, $none, intdiv($left, 1_000_000_000), intdiv($left % 1_000_000_000, 1000));
                if (false !== $ready && [] === $read && [] !== $write) {
                    usleep(intdiv(min($left, self::WRITABLE_PAUSE), 1000));
                }
            }
        } finally {
            if (is_resource($stream)) {
                stream_set_blocking($stream, true);
            }
        }
    }

    /**
     * The greeting, the capabilities and STARTTLS. With an opening deadline, its clock started before the connect: what
     * is left of it bounds every read from here to the end of the sign-in ({@see Client::login()} and
     * {@see Client::authenticate()} lift it once signed in).
     *
     * @param resource $stream
     */
    private function greet(Endpoint $endpoint, mixed $stream, int $started): Opened
    {
        stream_set_timeout(
            $stream,
            (int) $endpoint->readTimeout,
            (int) (($endpoint->readTimeout - (int) $endpoint->readTimeout) * 1_000_000),
        );
        $reader = new ResponseReader(
            $stream,
            responseSeconds: $endpoint->responseTimeout,
            waitSeconds: $endpoint->readTimeout,
        );
        $connection = new Connection($stream, $reader, $this->log);
        if (null !== $endpoint->openTimeout) {
            $left = $endpoint->openTimeout - (hrtime(true) - $started) / 1e9;
            if ($left <= 0) {
                throw new TimedOut('The server did not open the connection in time.');
            }
            $connection->within($left);
        }
        $greeting = $connection->greeting();
        if ('BYE' === $greeting->status) {
            throw new CouldNotOpen('The server refused the connection: ' . $greeting->text);
        }
        $capabilities = self::fromCode($greeting) ?? $this->asked($connection);
        if (Security::StartTls !== $endpoint->security) {
            return new Opened($connection, $greeting, $capabilities);
        }
        if ('PREAUTH' === $greeting->status) {
            throw new CouldNotOpen('The server authenticated the connection before STARTTLS, so it can never be secured.');
        }
        if (!$capabilities->has(Capability::StartTls)) {
            throw new CouldNotOpen('The server does not offer STARTTLS; the connection is not carried on in clear.');
        }
        $reply = $connection->run(new Command('STARTTLS'));
        if (!$reply->ok()) {
            throw new CouldNotOpen('The server refused STARTTLS: ' . $reply->completion->text);
        }
        // Counted in PHP's buffer and in the reader's own, which reads ahead of the response it hands out.
        $early = stream_get_meta_data($stream)['unread_bytes'] + $reader->pending();
        if ($early > 0) {
            throw new CouldNotOpen(sprintf('The server sent %d bytes after STARTTLS\'s OK, before the handshake; they would read as secured.', $early));
        }
        if (null !== $endpoint->openTimeout) {
            self::handshake($stream, $started + (int) ($endpoint->openTimeout * 1e9), 'The TLS handshake after STARTTLS failed');
        } elseif (true !== @stream_socket_enable_crypto($stream, true, self::TLS)) {
            throw new CouldNotOpen('The TLS handshake after STARTTLS failed.');
        }

        return new Opened($connection, $greeting, $this->asked($connection));
    }

    private function asked(Connection $connection): Capabilities
    {
        $reply = $connection->run(new Command('CAPABILITY'));
        $data = $reply->data('CAPABILITY');
        if (!$reply->ok() || [] === $data) {
            throw new CouldNotOpen('The server did not say what it can do: ' . $reply->completion->text);
        }

        return Capabilities::fromTokens(...$data[0]->data);
    }
}
