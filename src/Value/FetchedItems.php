<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;

use function array_key_exists;
use function count;
use function preg_match;
use function strcmp;
use function strlen;
use function strtoupper;

/**
 * One FETCH response (RFC 9051 s.7.5.2, RFC 7162 s.3.1.4): the items the server gave, each null when it did not give
 * it. A FETCH without FLAGS says nothing about flags -- an unsolicited FETCH may carry only some items -- so null
 * flags is never "no flags".
 *
 * Body sections are kept by their section, "" for the whole message: BODY.PEEK[HEADER] comes back as BODY[HEADER],
 * and a partial's "<origin>" is not part of the name.
 *
 * Gmail's ids (X-GM-EXT-1) are unsigned 64-bit numbers, kept as their digits: one past PHP_INT_MAX is still an id.
 * Its labels are kept as sent: a system label starts with a backslash (\Inbox, \Important, \Sent), any other is a
 * folder's wire name (FolderName::fromWire() reads it).
 */
final readonly class FetchedItems
{
    /**
     * @param list<string>|null          $flags
     * @param array<string, string|null> $sections
     * @param list<string>|null          $gmailLabels
     */
    public function __construct(
        public int $sequence,
        public ?int $uid,
        public ?array $flags,
        public ?int $modseq,
        public ?int $size,
        public ?string $internalDate,
        private array $sections,
        public ?string $gmailMessageId = null,
        public ?string $gmailThreadId = null,
        public ?array $gmailLabels = null,
    ) {
    }

    public static function fromFetch(Response $fetch): self
    {
        $data = $fetch->data;
        if (3 !== count($data) || TokenKind::Number !== $data[0]->kind || TokenKind::Atom !== $data[1]->kind
            || 'FETCH' !== strtoupper($data[1]->string()) || TokenKind::List !== $data[2]->kind) {
            throw new ProtocolError('FETCH', 0, 'a FETCH response is "n FETCH (item value ...)"');
        }
        $items = $data[2]->items();
        if (0 !== count($items) % 2) {
            throw new ProtocolError('FETCH', 0, 'a FETCH item without its value');
        }
        $uid = $flags = $modseq = $size = $internalDate = $gmailMessageId = $gmailThreadId = $gmailLabels = null;
        $sections = [];
        for ($i = 0; $i < count($items); $i += 2) {
            [$name, $value] = [$items[$i], $items[$i + 1]];
            if (TokenKind::Atom !== $name->kind) {
                throw new ProtocolError('FETCH', 0, 'a FETCH item name is an atom');
            }
            $key = strtoupper($name->string());
            if (1 === preg_match('/\A(?:BODY|BINARY)\[([^\]]*)\](?:<\d+>)?\z/', $key, $m)) {
                $sections[$m[1]] = self::body($value);

                continue;
            }
            match ($key) {
                'UID' => $uid = self::number($value, 'UID'),
                'RFC822.SIZE' => $size = self::number($value, 'RFC822.SIZE'),
                'MODSEQ' => $modseq = self::modseq($value),
                'FLAGS' => $flags = self::flags($value),
                'INTERNALDATE' => $internalDate = TokenKind::Quoted === $value->kind
                    ? $value->string()
                    : throw new ProtocolError('FETCH', 0, 'INTERNALDATE is a quoted date'),
                'X-GM-MSGID' => $gmailMessageId = self::gmailId($value, 'X-GM-MSGID'),
                'X-GM-THRID' => $gmailThreadId = self::gmailId($value, 'X-GM-THRID'),
                'X-GM-LABELS' => $gmailLabels = self::gmailLabels($value),
                default => null,
            };
        }

        return new self(
            (int) $data[0]->value,
            $uid,
            $flags,
            $modseq,
            $size,
            $internalDate,
            $sections,
            $gmailMessageId,
            $gmailThreadId,
            $gmailLabels,
        );
    }

    /** By the Section asked for, or by its spec as text; the whole message when none is named. */
    public function hasSection(Section|string $section = ''): bool
    {
        return array_key_exists(self::spec($section), $this->sections);
    }

    /** A section's bytes; null when the server sent NIL for it, or did not send it (hasSection() tells which). */
    public function section(Section|string $section = ''): ?string
    {
        return $this->sections[self::spec($section)] ?? null;
    }

    private static function spec(Section|string $section): string
    {
        return $section instanceof Section ? $section->spec : strtoupper($section);
    }

    private static function number(Token $value, string $item): int
    {
        return TokenKind::Number === $value->kind
            ? (int) $value->value
            : throw new ProtocolError('FETCH', 0, $item . ' is a number');
    }

    private static function modseq(Token $value): int
    {
        $items = TokenKind::List === $value->kind ? $value->items() : [];

        return 1 === count($items) && TokenKind::Number === $items[0]->kind
            ? (int) $items[0]->value
            : throw new ProtocolError('FETCH', 0, 'MODSEQ is a list of one number');
    }

    /** @return list<string> */
    private static function flags(Token $value): array
    {
        if (TokenKind::List !== $value->kind) {
            throw new ProtocolError('FETCH', 0, 'FLAGS is a list');
        }
        $flags = [];
        foreach ($value->items() as $flag) {
            $flags[] = TokenKind::Atom === $flag->kind ? $flag->string() : throw new ProtocolError('FETCH', 0, 'a flag is an atom');
        }

        return $flags;
    }

    /** A number, or -- past PHP_INT_MAX -- the digits the tokenizer left as an atom; never past 2^64-1. */
    private static function gmailId(Token $value, string $item): string
    {
        $digits = TokenKind::Number === $value->kind || TokenKind::Atom === $value->kind ? $value->string() : '';
        $fits = strlen($digits) < 20 || strcmp($digits, '18446744073709551615') <= 0;

        return 1 === preg_match('/\A[0-9]{1,20}\z/', $digits) && $fits
            ? $digits
            : throw new ProtocolError('FETCH', 0, $item . ' is a number');
    }

    /** @return list<string> */
    private static function gmailLabels(Token $value): array
    {
        if (TokenKind::List !== $value->kind) {
            throw new ProtocolError('FETCH', 0, 'X-GM-LABELS is a list');
        }
        $labels = [];
        foreach ($value->items() as $label) {
            $labels[] = match ($label->kind) {
                TokenKind::Atom, TokenKind::Quoted, TokenKind::Literal, TokenKind::Number => $label->string(),
                default => throw new ProtocolError('FETCH', 0, 'a label is a string'),
            };
        }

        return $labels;
    }

    private static function body(Token $value): ?string
    {
        return match ($value->kind) {
            TokenKind::Nil => null,
            TokenKind::Literal, TokenKind::Quoted => $value->string(),
            default => throw new ProtocolError('FETCH', 0, 'a body section is a string, a literal or NIL'),
        };
    }
}
