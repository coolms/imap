<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use InvalidArgumentException;

use function str_starts_with;

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

    /**
     * Gmail's id of the message (X-GM-EXT-1): the same in every folder -- every label -- it shows in, where its UID
     * differs per folder.
     */
    public static function gmailMessageId(): self
    {
        return new self('X-GM-MSGID');
    }

    /** Gmail's id of the conversation the message belongs to (X-GM-EXT-1). */
    public static function gmailThreadId(): self
    {
        return new self('X-GM-THRID');
    }

    /** The message's Gmail labels (X-GM-EXT-1), but the one of the folder it was fetched in. */
    public static function gmailLabels(): self
    {
        return new self('X-GM-LABELS');
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

    /** Gmail's items need the server to name X-GM-EXT-1; Client refuses them where it does not. */
    public function isGmail(): bool
    {
        return str_starts_with($this->wire, 'X-GM-');
    }
}
