<?php

declare(strict_types=1);

namespace CoolMS\Imap\Sasl;

use InvalidArgumentException;
use SensitiveParameter;

use function str_contains;
use function str_replace;

/**
 * RFC 7628 OAUTHBEARER: a GS2 header naming the user, then host, port and the bearer token, separated by ^A. A refused
 * token is answered as XOAUTH2's: a JSON challenge, an empty response (RFC 7628 s.3.2.3: "%x01"), then NO.
 */
final class OAuthBearer implements Mechanism
{
    public private(set) ?string $error = null;

    public function __construct(
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $accessToken,
        private readonly string $host,
        private readonly int $port,
    ) {
        foreach ([$username, $accessToken, $host] as $part) {
            if (str_contains($part, "\x01")) {
                throw new InvalidArgumentException('An OAUTHBEARER credential cannot hold a ^A byte.');
            }
        }
    }

    public function name(): string
    {
        return 'OAUTHBEARER';
    }

    public function initialResponse(): string
    {
        // RFC 5801 saslname: "," is "=2C" and "=" is "=3D".
        $user = str_replace(['=', ','], ['=3D', '=2C'], $this->username);

        return 'n,a=' . $user . ",\x01host=" . $this->host . "\x01port=" . $this->port
            . "\x01auth=Bearer " . $this->accessToken . "\x01\x01";
    }

    public function respond(string $challenge): string
    {
        $this->error = $challenge;

        return "\x01";
    }
}
