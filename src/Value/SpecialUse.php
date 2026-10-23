<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use function strcasecmp;

/**
 * What a folder is for, as the server marks it (RFC 6154 s.2) -- and INBOX, which needs no mark (RFC 9051 s.5.1). A
 * role is what the application translates; a folder without one is shown by its own name.
 */
enum SpecialUse: string
{
    case Inbox = 'inbox';
    case All = 'all';
    case Archive = 'archive';
    case Drafts = 'drafts';
    case Flagged = 'flagged';
    case Junk = 'junk';
    case Sent = 'sent';
    case Trash = 'trash';

    public static function fromAttribute(string $attribute): ?self
    {
        foreach (self::cases() as $role) {
            if (self::Inbox !== $role && 0 === strcasecmp($attribute, '\\' . $role->name)) {
                return $role;
            }
        }

        return null;
    }
}
