<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;

use function count;
use function in_array;
use function strcasecmp;
use function strtoupper;

/**
 * A STATUS response (RFC 9051 s.7.2.4, RFC 7162 s.3.1.3): a folder's counts without selecting it. Each item is null
 * when it was not asked for or not given -- never 0 in its place: "no unseen mail" and "not asked" differ.
 */
final readonly class FolderCounts
{
    public function __construct(
        public FolderName $name,
        public ?int $messages,
        public ?int $unseen,
        public ?int $uidNext,
        public ?int $uidValidity,
        public ?int $highestModseq,
    ) {
    }

    public static function fromStatus(Response $status): self
    {
        $data = $status->data;
        if (count($data) < 3 || TokenKind::Atom !== $data[0]->kind || 0 !== strcasecmp($data[0]->string(), 'STATUS')
            || !in_array($data[1]->kind, [TokenKind::Quoted, TokenKind::Literal, TokenKind::Atom], true)
            || TokenKind::List !== $data[2]->kind) {
            throw new ProtocolError('STATUS', 0, 'a STATUS response is "STATUS name (item value ...)"');
        }
        $items = $data[2]->items();
        if (0 !== count($items) % 2) {
            throw new ProtocolError('STATUS', 0, 'a STATUS item without its value');
        }
        $values = [];
        for ($i = 0; $i < count($items); $i += 2) {
            if (TokenKind::Atom !== $items[$i]->kind || TokenKind::Number !== $items[$i + 1]->kind) {
                throw new ProtocolError('STATUS', 0, 'a STATUS item is a name and a number');
            }
            $values[strtoupper($items[$i]->string())] = (int) $items[$i + 1]->value;
        }
        $wire = $data[1]->string();

        return new self(
            0 === strcasecmp($wire, 'INBOX') ? FolderName::fromWire('INBOX') : FolderName::fromWire($wire),
            $values['MESSAGES'] ?? null,
            $values['UNSEEN'] ?? null,
            $values['UIDNEXT'] ?? null,
            $values['UIDVALIDITY'] ?? null,
            $values['HIGHESTMODSEQ'] ?? null,
        );
    }
}
