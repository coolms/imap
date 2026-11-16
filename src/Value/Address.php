<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

/**
 * One address of an ENVELOPE (RFC 9051 s.7.5.2): its display name, its mailbox (the local part) and its host, each
 * as the server sent it. A display name may still be an RFC 2047 encoded word ("=?utf-8?q?...?="): decoding it is the
 * caller's, so the library never guesses a charset.
 */
final readonly class Address
{
    public function __construct(
        public ?string $name,
        public ?string $mailbox,
        public ?string $host,
    ) {
    }

    /** "mailbox@host", or the mailbox alone when the server gave no host; null when it gave neither. */
    public function email(): ?string
    {
        if (null === $this->mailbox || '' === $this->mailbox) {
            return null;
        }

        return null === $this->host || '' === $this->host ? $this->mailbox : $this->mailbox . '@' . $this->host;
    }
}
