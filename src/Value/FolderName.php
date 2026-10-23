<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\MalformedFolderName;

use function base64_decode;
use function base64_encode;
use function chr;
use function intdiv;
use function ord;
use function preg_match;
use function preg_split;
use function rtrim;
use function strlen;
use function strpos;
use function strtr;
use function substr;

/**
 * A folder's name as a person reads it (UTF-8) and as the server names it (modified UTF-7, RFC 3501 s.5.1.3).
 *
 * Printable US-ASCII except "&" stands for itself; "&" is "&-"; every other run of characters is UTF-16BE in base64
 * with "," for "/", no padding, between "&" and "-". The server's form is the only one sent, and the only one read:
 * a name that does not decode -- a printable character inside a shift, padding bits that are not zero, a shift left
 * open, a lone surrogate -- is refused by name, never shown as something it is not.
 */
final readonly class FolderName
{
    private function __construct(
        public string $display,
        public string $wire,
    ) {
    }

    /** A name a person typed or the application chose, in UTF-8. */
    public static function fromDisplay(string $display): self
    {
        if (1 !== preg_match('//u', $display)) {
            throw new MalformedFolderName($display, 'it is not UTF-8');
        }

        return new self($display, self::encode($display));
    }

    /** A name as the server sent it, in modified UTF-7. */
    public static function fromWire(string $wire): self
    {
        return new self(self::decode($wire), $wire);
    }

    private static function encode(string $display): string
    {
        $wire = '';
        $pending = '';
        foreach (preg_split('//u', $display, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $code = self::codePoint($char);
            if ($code >= 0x20 && $code <= 0x7E) {
                if ('' !== $pending) {
                    $wire .= '&' . self::base64($pending) . '-';
                    $pending = '';
                }
                $wire .= '&' === $char ? '&-' : $char;

                continue;
            }
            $pending .= self::utf16($code);
        }
        if ('' !== $pending) {
            $wire .= '&' . self::base64($pending) . '-';
        }

        return $wire;
    }

    private static function decode(string $wire): string
    {
        $display = '';
        $length = strlen($wire);
        $i = 0;
        while ($i < $length) {
            $byte = ord($wire[$i]);
            if ($byte < 0x20 || $byte > 0x7E) {
                throw new MalformedFolderName($wire, 'a byte outside printable US-ASCII');
            }
            if ('&' !== $wire[$i]) {
                $display .= $wire[$i];
                ++$i;

                continue;
            }
            $end = strpos($wire, '-', $i + 1);
            if (false === $end) {
                throw new MalformedFolderName($wire, 'a shift is never closed');
            }
            if ($end === $i + 1) {
                $display .= '&';
                $i = $end + 1;

                continue;
            }
            $display .= self::fromUtf16(self::unbase64($wire, substr($wire, $i + 1, $end - $i - 1)), $wire);
            $i = $end + 1;
        }

        return $display;
    }

    private static function base64(string $utf16): string
    {
        return strtr(rtrim(base64_encode($utf16), '='), '/', ',');
    }

    private static function unbase64(string $wire, string $run): string
    {
        if (1 !== preg_match('/\A[A-Za-z0-9+,]+\z/', $run)) {
            throw new MalformedFolderName($wire, 'a shift holds a character outside its alphabet');
        }
        $bits = strlen($run) * 6;
        $bytes = intdiv($bits, 8);
        if (0 !== $bytes % 2 || $bits - $bytes * 8 >= 6) {
            throw new MalformedFolderName($wire, 'a shift does not hold whole UTF-16 units');
        }
        $decoded = base64_decode(strtr($run, ',', '/'), true);
        if (false === $decoded || strlen($decoded) !== $bytes) {
            throw new MalformedFolderName($wire, 'a shift is not base64');
        }
        // The bits past the last whole byte must be zero: otherwise two encodings name one folder.
        if (self::base64(substr($decoded, 0, $bytes)) !== $run) {
            throw new MalformedFolderName($wire, 'a shift ends in bits that are not zero');
        }

        return $decoded;
    }

    private static function fromUtf16(string $utf16, string $wire): string
    {
        $out = '';
        $length = strlen($utf16);
        for ($i = 0; $i < $length; $i += 2) {
            $unit = (ord($utf16[$i]) << 8) | ord($utf16[$i + 1]);
            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                if ($i + 3 >= $length) {
                    throw new MalformedFolderName($wire, 'a high surrogate with no low one');
                }
                $low = (ord($utf16[$i + 2]) << 8) | ord($utf16[$i + 3]);
                if ($low < 0xDC00 || $low > 0xDFFF) {
                    throw new MalformedFolderName($wire, 'a high surrogate with no low one');
                }
                $unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                $i += 2;
            } elseif ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                throw new MalformedFolderName($wire, 'a low surrogate with no high one');
            }
            if ($unit >= 0x20 && $unit <= 0x7E) {
                throw new MalformedFolderName($wire, 'a printable character inside a shift');
            }
            $out .= self::utf8($unit);
        }

        return $out;
    }

    private static function codePoint(string $char): int
    {
        $first = ord($char[0]);

        return match (strlen($char)) {
            1 => $first,
            2 => (($first & 0x1F) << 6) | (ord($char[1]) & 0x3F),
            3 => (($first & 0x0F) << 12) | ((ord($char[1]) & 0x3F) << 6) | (ord($char[2]) & 0x3F),
            default => (($first & 0x07) << 18) | ((ord($char[1]) & 0x3F) << 12) | ((ord($char[2]) & 0x3F) << 6)
                | (ord($char[3]) & 0x3F),
        };
    }

    private static function utf16(int $code): string
    {
        if ($code < 0x10000) {
            return chr(($code >> 8) & 0xFF) . chr($code & 0xFF);
        }
        $code -= 0x10000;
        $high = 0xD800 | ($code >> 10);
        $low = 0xDC00 | ($code & 0x3FF);

        return chr(($high >> 8) & 0xFF) . chr($high & 0xFF) . chr(($low >> 8) & 0xFF) . chr($low & 0xFF);
    }

    private static function utf8(int $code): string
    {
        return match (true) {
            $code < 0x80 => chr($code & 0x7F),
            $code < 0x800 => chr(0xC0 | (($code >> 6) & 0x1F)) . chr(0x80 | ($code & 0x3F)),
            $code < 0x10000 => chr(0xE0 | (($code >> 12) & 0x0F)) . chr(0x80 | (($code >> 6) & 0x3F))
                . chr(0x80 | ($code & 0x3F)),
            default => chr(0xF0 | (($code >> 18) & 0x07)) . chr(0x80 | (($code >> 12) & 0x3F))
                . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F)),
        };
    }
}
