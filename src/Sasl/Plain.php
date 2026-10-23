<?php

declare(strict_types=1);

namespace CoolMS\Imap\Sasl;

use InvalidArgumentException;
use SensitiveParameter;

use function str_contains;

/** RFC 4616: "authzid NUL authcid NUL passwd", in one response; no authorization identity of its own. */
final readonly class Plain implements Mechanism
{
    public function __construct(
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {
        if (str_contains($username, "\0") || str_contains($password, "\0")) {
            throw new InvalidArgumentException('A PLAIN credential cannot hold a NUL byte.');
        }
    }

    public function name(): string
    {
        return 'PLAIN';
    }

    public function initialResponse(): string
    {
        return "\0" . $this->username . "\0" . $this->password;
    }

    public function respond(string $challenge): string
    {
        return $this->initialResponse();
    }
}
