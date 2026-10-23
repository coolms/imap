<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;
use CoolMS\Imap\Transport\Reply;

use function array_map;
use function is_int;
use function strtoupper;

/**
 * What SELECT or EXAMINE said of a folder (RFC 9051 s.6.3.2, RFC 7162 s.3.1.2): what a sync needs to know before it
 * reads a message. UIDVALIDITY is required: without it no UID the application holds can be trusted, so a SELECT that
 * gives none is a ProtocolError, never a folder with a guessed one.
 *
 * highestModseq is null when the server said NOMODSEQ, or nothing: then CHANGEDSINCE cannot be used, and a sync
 * falls back to reading every flag.
 */
final readonly class SelectedFolder
{
    /**
     * @param list<string> $flags
     * @param list<string> $permanentFlags
     */
    public function __construct(
        public int $exists,
        public int $uidValidity,
        public ?int $uidNext,
        public ?int $highestModseq,
        public array $flags,
        public array $permanentFlags,
        public bool $readOnly,
    ) {
    }

    public static function fromReply(Reply $reply): self
    {
        if (!$reply->ok()) {
            throw new ProtocolError((string) $reply->completion->status, 0, 'a SELECT that was not OK selected nothing');
        }
        $codes = [];
        foreach ($reply->untagged as $response) {
            $code = self::code($response);
            if (null !== $code) {
                $codes[$code[0]] = $code[1];
            }
        }
        $exists = $reply->data('EXISTS');
        $flags = $reply->data('FLAGS');
        $uidValidity = $codes['UIDVALIDITY'] ?? null;
        if (!is_int($uidValidity)) {
            throw new ProtocolError('SELECT', 0, 'a SELECT gave no UIDVALIDITY');
        }
        if ([] === $exists || TokenKind::Number !== $exists[0]->data[0]->kind) {
            throw new ProtocolError('SELECT', 0, 'a SELECT gave no EXISTS');
        }
        $completion = self::code($reply->completion);

        return new self(
            (int) $exists[0]->data[0]->value,
            $uidValidity,
            self::number($codes['UIDNEXT'] ?? null),
            isset($codes['NOMODSEQ']) ? null : self::number($codes['HIGHESTMODSEQ'] ?? null),
            [] === $flags ? [] : self::texts($flags[0]->data[1] ?? null),
            self::texts($codes['PERMANENTFLAGS'] ?? null),
            'READ-ONLY' === ($completion[0] ?? null),
        );
    }

    /** @return array{0: string, 1: Token|int|true}|null a status response's code: its name, and its value */
    private static function code(Response $response): ?array
    {
        $items = $response->code?->items() ?? [];
        if ([] === $items || TokenKind::Atom !== $items[0]->kind) {
            return null;
        }
        $value = $items[1] ?? null;

        return [
            strtoupper($items[0]->string()),
            null === $value ? true : (TokenKind::Number === $value->kind ? (int) $value->value : $value),
        ];
    }

    private static function number(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** @return list<string> */
    private static function texts(mixed $list): array
    {
        if (!$list instanceof Token || TokenKind::List !== $list->kind) {
            return [];
        }

        return array_map(static fn (Token $flag): string => $flag->string(), $list->items());
    }
}
