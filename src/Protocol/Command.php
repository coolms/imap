<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

use InvalidArgumentException;
use LogicException;

use function array_map;
use function array_values;
use function implode;
use function is_array;
use function is_int;
use function preg_match;
use function str_replace;
use function strlen;

/**
 * A client command (RFC 9051 s.6): a name and its arguments, written as the wire wants them.
 *
 * encode() returns the command in pieces. Every piece but the last ends with a synchronizing literal's announcement,
 * "{n}" CRLF, and the next piece goes only after the server's "+" continuation; the last ends with CRLF. With
 * LITERAL+ (RFC 7888) a literal is announced "{n+}" and needs no wait, so the command is one piece.
 *
 * logged() is the same command as one line for a log: a literal's bytes are shown by their size only, a secret not
 * at all.
 */
final readonly class Command
{
    private const int QUOTED_MAX = 1000;

    /** @var list<Argument> */
    public array $arguments;

    public function __construct(
        public string $name,
        Argument ...$arguments,
    ) {
        if (1 !== preg_match('/\A[A-Z][A-Z0-9]*(?: [A-Z][A-Z0-9]*)?\z/', $name)) {
            throw new InvalidArgumentException('"' . $name . '" is not a command name.');
        }
        $this->arguments = array_values($arguments);
    }

    /** @return non-empty-list<string> */
    public function encode(string $tag, bool $literalPlus): array
    {
        self::tagged($tag);
        $pieces = [];
        $current = $tag . ' ' . $this->name;
        foreach ($this->arguments as $argument) {
            $current .= ' ';
            $this->write($argument, $literalPlus, $current, $pieces);
        }
        $pieces[] = $current . "\r\n";

        return $pieces;
    }

    public function logged(string $tag): string
    {
        self::tagged($tag);

        return $tag . ' ' . $this->described();
    }

    /** The log form without a tag: for a message about the command, sent or not. */
    public function described(): string
    {
        $line = $this->name;
        foreach ($this->arguments as $argument) {
            $line .= ' ' . self::shown($argument);
        }

        return $line;
    }

    private static function shown(Argument $argument): string
    {
        $value = $argument->value;
        if (is_array($value)) {
            return '(' . implode(' ', array_map(self::shown(...), $value)) . ')';
        }
        if ($argument->secret) {
            return '<secret>';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return match ($argument->kind) {
            ArgumentKind::Literal => '{' . strlen($value) . ' bytes}',
            ArgumentKind::Text => self::quotable($value) ? self::quoted($value) : '{' . strlen($value) . ' bytes}',
            ArgumentKind::Atom, ArgumentKind::Raw, ArgumentKind::Number => $value,
            ArgumentKind::List => throw new LogicException('A list is an array.'),
        };
    }

    /** 7-bit, no CR or LF, and short: the quoted form (RFC 9051 s.4.3) holds it. */
    private static function quotable(string $text): bool
    {
        return strlen($text) <= self::QUOTED_MAX && 1 === preg_match('/\A[\x01-\x09\x0b\x0c\x0e-\x7f]*\z/', $text);
    }

    private static function quoted(string $text): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
    }

    private static function tagged(string $tag): void
    {
        if (1 !== preg_match('/\A[A-Za-z0-9]+\z/', $tag)) {
            throw new InvalidArgumentException('"' . $tag . '" is not a tag.');
        }
    }

    /** @param list<string> $pieces */
    private function write(Argument $argument, bool $literalPlus, string &$current, array &$pieces): void
    {
        $value = $argument->value;
        if (is_array($value)) {
            $current .= '(';
            foreach ($value as $i => $item) {
                $current .= 0 === $i ? '' : ' ';
                $this->write($item, $literalPlus, $current, $pieces);
            }
            $current .= ')';

            return;
        }
        $text = (string) $value;
        if (
            ArgumentKind::Literal === $argument->kind
            || (ArgumentKind::Text === $argument->kind && !self::quotable($text))
        ) {
            if ($literalPlus) {
                $current .= '{' . strlen($text) . "+}\r\n" . $text;

                return;
            }
            $pieces[] = $current . '{' . strlen($text) . "}\r\n";
            $current = $text;

            return;
        }
        $current .= ArgumentKind::Text === $argument->kind ? self::quoted($text) : $text;
    }
}
