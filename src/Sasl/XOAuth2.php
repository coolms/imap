<?php

declare(strict_types=1);

namespace CoolMS\Imap\Sasl;

use InvalidArgumentException;
use SensitiveParameter;
use SensitiveParameterValue;

use function str_contains;

/**
 * Google's and Microsoft's XOAUTH2: "user=<user>^Aauth=Bearer <token>^A^A", in one response. When the token is
 * refused the server sends a challenge -- a JSON error -- and waits: the client answers with an empty response, and
 * the server then answers NO. The challenge is the only place the reason is given, so it is kept.
 */
final class XOAuth2 implements Mechanism
{
    /** The server's JSON error, when it sent one. */
    public private(set) ?string $error = null;

    /** Where no dump shows it: a trace that passes this mechanism along, printed, shows nothing of the token. */
    private readonly SensitiveParameterValue $accessToken;

    public function __construct(
        private readonly string $username,
        #[SensitiveParameter]
        string $accessToken,
    ) {
        if (str_contains($username, "\x01") || str_contains($accessToken, "\x01")) {
            throw new InvalidArgumentException('An XOAUTH2 credential cannot hold a ^A byte.');
        }
        $this->accessToken = new SensitiveParameterValue($accessToken);
    }

    public function name(): string
    {
        return 'XOAUTH2';
    }

    public function initialResponse(): string
    {
        return 'user=' . $this->username . "\x01auth=Bearer " . $this->accessToken->getValue() . "\x01\x01";
    }

    public function respond(string $challenge): string
    {
        $this->error = $challenge;

        return '';
    }
}
