<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use InvalidArgumentException;

/**
 * Where a mailbox's server is and how to reach it. The certificate is always verified against the host name; a test
 * server with its own certificate authority names that authority's file, never turns verification off.
 *
 * $host is what the socket connects to. When that is an address resolved and checked beforehand -- a mail egress
 * guard pins the address, so a second lookup cannot send the connection elsewhere -- $peerName is the server's name,
 * which the certificate is verified against and SNI carries.
 */
final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public Security $security,
        public float $connectTimeout = 15.0,
        public float $readTimeout = 60.0,
        public ?string $caFile = null,
        public ?string $peerName = null,
    ) {
        if (
            '' === $host
            || '' === $peerName
            || $port < 1
            || $port > 65535
            || $connectTimeout <= 0
            || $readTimeout <= 0
        ) {
            throw new InvalidArgumentException('An endpoint needs a host, a port from 1 to 65535 and timeouts above 0.');
        }
    }
}
