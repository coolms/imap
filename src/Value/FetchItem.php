<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use InvalidArgumentException;

/**
 * One item of a FETCH (RFC 9051 s.6.4.5), as the command will carry it. A body section is always BODY.PEEK: reading a
 * message never marks it \Seen behind the caller's back -- a flag changes only by a STORE the caller sends.
 *
 * A partial fetch names both its start and its length ("<0.1024>"): the response then says where it began, and
 * FetchedItems keeps it under its section either way.
 */
final readonly class FetchItem
{
    private function __construct(public string $wire)
    {
    }

    public static function uid(): self
    {
        return new self('UID');
    }

    public static function flags(): self
    {
        return new self('FLAGS');
    }

    /** CONDSTORE's per-message mod-sequence (RFC 7162 s.3.1.4.1). */
    public static function modseq(): self
    {
        return new self('MODSEQ');
    }

    /** The message's size in octets. */
    public static function size(): self
    {
        return new self('RFC822.SIZE');
    }

    public static function internalDate(): self
    {
        return new self('INTERNALDATE');
    }

    public static function body(?Section $section = null, ?int $from = null, ?int $length = null): self
    {
        $partial = '';
        if (null !== $from || null !== $length) {
            if (null === $from || null === $length) {
                throw new InvalidArgumentException('A partial fetch names both its start and its length.');
            }
            if ($from < 0 || $length < 1) {
                throw new InvalidArgumentException('A partial fetch starts at 0 or later and is at least 1 octet long.');
            }
            $partial = '<' . $from . '.' . $length . '>';
        }

        return new self('BODY.PEEK[' . ($section ?? Section::whole())->spec . ']' . $partial);
    }
}
