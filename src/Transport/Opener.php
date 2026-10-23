<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use Closure;
use CoolMS\Imap\Exception\CouldNotOpen;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;
use CoolMS\Imap\Value\Capabilities;
use Throwable;

use function fclose;
use function is_resource;
use function sprintf;
use function strcasecmp;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;

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
        $stream = ($this->connect)($endpoint);
        try {
            return $this->greet($endpoint, $stream);
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

    /** @return resource */
    private static function connect(Endpoint $endpoint): mixed
    {
        $tls = Security::Tls === $endpoint->security;
        $context = stream_context_create(['ssl' => self::tlsContext($endpoint)]);
        $stream = @stream_socket_client(
            ($tls ? 'tls://' : 'tcp://') . $endpoint->host . ':' . $endpoint->port,
            $errno,
            $error,
            $endpoint->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (false === $stream) {
            throw new CouldNotOpen(sprintf('%s:%d could not be reached%s: %s', $endpoint->host, $endpoint->port, $tls ? ' over TLS' : '', '' === $error ? 'no reason given' : $error));
        }

        return $stream;
    }

    /** @param resource $stream */
    private function greet(Endpoint $endpoint, mixed $stream): Opened
    {
        stream_set_timeout(
            $stream,
            (int) $endpoint->readTimeout,
            (int) (($endpoint->readTimeout - (int) $endpoint->readTimeout) * 1_000_000),
        );
        $connection = new Connection($stream, new ResponseReader($stream), $this->log);
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
        if (!$capabilities->has('STARTTLS')) {
            throw new CouldNotOpen('The server does not offer STARTTLS; the connection is not carried on in clear.');
        }
        $reply = $connection->run(new Command('STARTTLS'));
        if (!$reply->ok()) {
            throw new CouldNotOpen('The server refused STARTTLS: ' . $reply->completion->text);
        }
        $early = stream_get_meta_data($stream)['unread_bytes'];
        if ($early > 0) {
            throw new CouldNotOpen(sprintf('The server sent %d bytes after STARTTLS\'s OK, before the handshake; they would read as secured.', $early));
        }
        if (true !== @stream_socket_enable_crypto($stream, true, self::TLS)) {
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
