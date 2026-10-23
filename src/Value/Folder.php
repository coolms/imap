<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;

use function count;
use function in_array;
use function strcasecmp;
use function strtolower;

/**
 * One folder from a LIST response (RFC 9051 s.7.3.1): its name, the hierarchy delimiter (none on a flat server),
 * its attributes and, from them, its role.
 *
 * INBOX is INBOX in any case and is never encoded. When two special-use attributes are given -- a server may mark one
 * folder \Junk and \Trash -- the first is the role and the rest stay in the attributes.
 */
final readonly class Folder
{
    /** @param list<string> $attributes as the server wrote them */
    public function __construct(
        public FolderName $name,
        public ?string $delimiter,
        public array $attributes,
        public ?SpecialUse $role,
    ) {
    }

    public static function fromList(Response $list): self
    {
        $data = $list->data;
        if (count($data) < 4 || TokenKind::Atom !== $data[0]->kind || 0 !== strcasecmp($data[0]->string(), 'LIST')
            || TokenKind::List !== $data[1]->kind) {
            throw new ProtocolError('LIST', 0, 'a LIST response is "LIST (attributes) delimiter name"');
        }
        $attributes = [];
        foreach ($data[1]->items() as $attribute) {
            if (TokenKind::Atom !== $attribute->kind) {
                throw new ProtocolError('LIST', 0, 'a LIST attribute is an atom');
            }
            $attributes[] = $attribute->string();
        }
        $delimiter = match ($data[2]->kind) {
            TokenKind::Nil => null,
            TokenKind::Quoted => $data[2]->string(),
            default => throw new ProtocolError('LIST', 0, 'a LIST delimiter is a quoted character or NIL'),
        };
        if (!in_array($data[3]->kind, [TokenKind::Quoted, TokenKind::Literal, TokenKind::Atom], true)) {
            throw new ProtocolError('LIST', 0, 'a LIST name is a string or an atom');
        }
        $wire = $data[3]->string();
        $inbox = 'inbox' === strtolower($wire);
        $name = $inbox ? FolderName::fromWire('INBOX') : FolderName::fromWire($wire);
        $role = $inbox ? SpecialUse::Inbox : null;
        foreach ($attributes as $attribute) {
            $role ??= SpecialUse::fromAttribute($attribute);
        }

        return new self($name, $delimiter, $attributes, $role);
    }

    /** \Noselect or \NonExistent: a place in the hierarchy, not a folder that holds mail. */
    public function selectable(): bool
    {
        foreach ($this->attributes as $attribute) {
            if (0 === strcasecmp($attribute, '\Noselect') || 0 === strcasecmp($attribute, '\NonExistent')) {
                return false;
            }
        }

        return true;
    }
}
