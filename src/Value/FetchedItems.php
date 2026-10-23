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
use function strtoupper;

/**
 * One FETCH response (RFC 9051 s.7.5.2, RFC 7162 s.3.1.4): the items the server gave, each null when it did not give
 * it. A FETCH without FLAGS says nothing about flags -- an unsolicited FETCH may carry only some items -- so null
 * flags is never "no flags".
 *
 * Body sections are kept by their section, "" for the whole message: BODY.PEEK[HEADER] comes back as BODY[HEADER],
 * and a partial's "<origin>" is not part of the name.
 */
final readonly class FetchedItems
{
    /**
     * @param list<string>|null          $flags
     * @param array<string, string|null> $sections
     */
    public function __construct(
        public int $sequence,
        public ?int $uid,
        public ?array $flags,
        public ?int $modseq,
        public ?int $size,
        public ?string $internalDate,
        private array $sections,
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
        $uid = $flags = $modseq = $size = $internalDate = null;
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
                default => null,
            };
        }

        return new self((int) $data[0]->value, $uid, $flags, $modseq, $size, $internalDate, $sections);
    }

    public function hasSection(string $section = ''): bool
    {
        return array_key_exists(strtoupper($section), $this->sections);
    }

    /** A section's bytes; null when the server sent NIL for it, or did not send it (hasSection() tells which). */
    public function section(string $section = ''): ?string
    {
        return $this->sections[strtoupper($section)] ?? null;
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

    private static function body(Token $value): ?string
    {
        return match ($value->kind) {
            TokenKind::Nil => null,
            TokenKind::Literal, TokenKind::Quoted => $value->string(),
            default => throw new ProtocolError('FETCH', 0, 'a body section is a string, a literal or NIL'),
        };
    }
}
