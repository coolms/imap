<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use InvalidArgumentException;

use function array_map;
use function implode;
use function preg_match;
use function strtoupper;

/**
 * A body section (RFC 9051 s.6.4.5): the whole message, its header -- all of it, some fields, all but some -- its
 * text, or a MIME part by its path and that part's own MIME header. The spec is what goes between the brackets, and
 * is also the name a FETCH response echoes it under: field names upper-cased, as both sides then spell them.
 *
 * A field name is letters, digits and "-": the grammar would carry more as a quoted string, but no header a mail
 * client asks for needs it, and a name that could close the list is refused here, never sent.
 */
final readonly class Section
{
    private function __construct(public string $spec)
    {
    }

    public static function whole(): self
    {
        return new self('');
    }

    public static function header(): self
    {
        return new self('HEADER');
    }

    public static function headerFields(string ...$names): self
    {
        return new self('HEADER.FIELDS (' . self::names($names) . ')');
    }

    public static function headerFieldsNot(string ...$names): self
    {
        return new self('HEADER.FIELDS.NOT (' . self::names($names) . ')');
    }

    public static function text(): self
    {
        return new self('TEXT');
    }

    /** A MIME part by its path from the top: part(1, 2) is the second part of the first. */
    public static function part(int ...$path): self
    {
        return new self(self::path($path));
    }

    /** A MIME part's own header (RFC 9051 s.6.4.5 "MIME"). */
    public static function partMime(int ...$path): self
    {
        return new self(self::path($path) . '.MIME');
    }

    /** @param array<int|string, string> $names */
    private static function names(array $names): string
    {
        if ([] === $names) {
            throw new InvalidArgumentException('A header field list names at least one field.');
        }
        foreach ($names as $name) {
            if (1 !== preg_match('/\A[A-Za-z0-9-]+\z/', $name)) {
                throw new InvalidArgumentException('"' . $name . '" is not a header field name this library sends.');
            }
        }

        return implode(' ', array_map(strtoupper(...), $names));
    }

    /** @param array<int|string, int> $path */
    private static function path(array $path): string
    {
        if ([] === $path) {
            throw new InvalidArgumentException('A part path names at least one part.');
        }
        foreach ($path as $part) {
            if ($part < 1) {
                throw new InvalidArgumentException('Parts are numbered from 1.');
            }
        }

        return implode('.', $path);
    }
}
