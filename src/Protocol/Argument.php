<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\UidSet;
use InvalidArgumentException;
use SensitiveParameter;
use SensitiveParameterValue;

use function array_values;
use function preg_match;
use function str_contains;

/**
 * One argument of a client command, as the command will carry it (RFC 9051 s.4, s.9):
 *  - an atom -- a command's keyword or a flag such as \Seen -- refused if it holds a character an atom cannot;
 *  - a number;
 *  - text, which goes quoted when it can and as a literal when it cannot (8-bit, CR, LF, or long);
 *  - a literal, always -- a message to APPEND;
 *  - raw syntax the caller composed -- a fetch item such as BODY.PEEK[HEADER.FIELDS (FROM)], a UID set;
 *  - a parenthesized list of these.
 *
 * Nothing here can carry a NUL, a bare CR or LF outside a literal: what would break the command's framing is refused
 * where it is built, never sent. A secret -- a password -- is text whose value is never shown in a command's log form,
 * nor in a dump of the command: a stack trace that passes it along, printed by a logger, shows nothing of it.
 */
final readonly class Argument
{
    private const string ATOM = '/\A\\\\?[^\x00-\x20\x7f-\xff(){%*"\\\\\]]+\z/';

    /** @param string|int|list<Argument>|SensitiveParameterValue $value a secret's text is held where no dump shows it */
    private function __construct(
        public ArgumentKind $kind,
        public string|int|array|SensitiveParameterValue $value,
        public bool $secret = false,
    ) {
    }

    public static function atom(string $atom): self
    {
        return 1 === preg_match(self::ATOM, $atom)
            ? new self(ArgumentKind::Atom, $atom)
            : throw new InvalidArgumentException('"' . $atom . '" is not an atom.');
    }

    public static function number(int $number): self
    {
        return $number >= 0 ? new self(ArgumentKind::Number, $number) : throw new InvalidArgumentException('A number in a command is never negative.');
    }

    public static function text(string $text): self
    {
        return new self(ArgumentKind::Text, self::withoutNul($text));
    }

    public static function secret(#[SensitiveParameter] string $text): self
    {
        return new self(ArgumentKind::Text, new SensitiveParameterValue(self::withoutNul($text)), true);
    }

    public static function literal(string $bytes): self
    {
        return new self(ArgumentKind::Literal, self::withoutNul($bytes));
    }

    public static function raw(string $syntax): self
    {
        return 1 === preg_match('/\A[^\x00\r\n]+\z/', $syntax)
            ? new self(ArgumentKind::Raw, $syntax)
            : throw new InvalidArgumentException('Raw syntax is one line, never empty, with no NUL.');
    }

    public static function folder(FolderName $folder): self
    {
        return self::text($folder->wire);
    }

    public static function uids(UidSet $uids): self
    {
        return new self(ArgumentKind::Raw, $uids->toString());
    }

    public static function list(self ...$items): self
    {
        return new self(ArgumentKind::List, array_values($items));
    }

    private static function withoutNul(#[SensitiveParameter] string $bytes): string
    {
        return str_contains($bytes, "\0")
            ? throw new InvalidArgumentException('A command cannot carry a NUL byte outside BINARY.') : $bytes;
    }
}
