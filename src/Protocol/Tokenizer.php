<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

use CoolMS\Imap\Exception\ProtocolError;

use function ctype_digit;
use function ltrim;
use function ord;
use function preg_match;
use function strcasecmp;
use function strcmp;
use function strlen;
use function substr;

/**
 * A server response, read into tokens -- the whole response, its literals included: "{n}" (or "~{n}") followed by
 * CRLF and exactly n bytes, as the line reader hands it over.
 *
 *  - an atom runs to a space, "(", ")", "{", '"', or the end; inside it, "[...]" is kept whole, spaces and parentheses
 *    included (BODY[HEADER.FIELDS (DATE FROM)]), and a "\" opens a flag (\Seen);
 *  - an atom of digits only is a Number when it fits an int (2^63-1); NIL in any case is Nil;
 *  - "[" where a token starts opens a response code: a list of kind Code, closed by "]".
 *
 * A response that ends inside a string, a list, a code or a literal is a ProtocolError, naming where -- never a
 * shorter response read as if it were whole.
 */
final class Tokenizer
{
    private int $at = 0;

    private function __construct(private readonly string $bytes)
    {
    }

    /** @return list<Token> */
    public static function tokenize(string $response): array
    {
        $tokenizer = new self($response);
        $tokens = $tokenizer->sequence(null);
        if ($tokenizer->at < strlen($response)) {
            throw new ProtocolError($response, $tokenizer->at, 'a closing bracket with nothing open');
        }

        return $tokens;
    }

    /**
     * Up to 2^63-1, the largest mod-sequence (RFC 7162 s.7) and PHP's largest int; past it the digits stay an Atom,
     * never a number cast to something else.
     */
    private static function fitsAnInt(string $digits): bool
    {
        $digits = ltrim($digits, '0');

        return strlen($digits) < 19 || (19 === strlen($digits) && strcmp($digits, '9223372036854775807') <= 0);
    }

    /** @return list<Token> */
    private function sequence(?string $closer): array
    {
        $tokens = [];
        $length = strlen($this->bytes);
        while (true) {
            while ($this->at < $length && ' ' === $this->bytes[$this->at]) {
                ++$this->at;
            }
            if ($this->at >= $length) {
                if (null !== $closer) {
                    throw new ProtocolError($this->bytes, $this->at, 'the response ends before "' . $closer . '"');
                }

                return $tokens;
            }
            $char = $this->bytes[$this->at];
            if (')' === $char || ']' === $char) {
                if ($char !== $closer) {
                    return null === $closer
                        ? $tokens
                        : throw new ProtocolError($this->bytes, $this->at, '"' . $char . '" where "' . $closer . '" was due');
                }
                ++$this->at;

                return $tokens;
            }
            $tokens[] = $this->token();
        }
    }

    private function token(): Token
    {
        $char = $this->bytes[$this->at];

        return match (true) {
            '(' === $char => $this->opened(TokenKind::List, ')'),
            '[' === $char => $this->opened(TokenKind::Code, ']'),
            '"' === $char => $this->quoted(),
            '{' === $char, '~' === $char && '{' === ($this->bytes[$this->at + 1] ?? '') => $this->literal(),
            default => $this->atom(),
        };
    }

    private function opened(TokenKind $kind, string $closer): Token
    {
        ++$this->at;

        return new Token($kind, $this->sequence($closer));
    }

    private function quoted(): Token
    {
        $start = $this->at;
        $value = '';
        $length = strlen($this->bytes);
        for ($i = $this->at + 1; $i < $length; ++$i) {
            $char = $this->bytes[$i];
            if ('\\' === $char) {
                $next = $this->bytes[$i + 1] ?? '';
                if ('"' !== $next && '\\' !== $next) {
                    throw new ProtocolError($this->bytes, $i, 'a backslash in a string escapes only " and \\');
                }
                $value .= $next;
                ++$i;

                continue;
            }
            if ('"' === $char) {
                $this->at = $i + 1;

                return new Token(TokenKind::Quoted, $value);
            }
            if ("\r" === $char || "\n" === $char) {
                break;
            }
            $value .= $char;
        }

        throw new ProtocolError($this->bytes, $start, 'a string is never closed');
    }

    private function literal(): Token
    {
        $start = $this->at;
        if (1 !== preg_match('/\G~?\{(\d+)\+?\}\r\n/', $this->bytes, $m, 0, $this->at)) {
            throw new ProtocolError($this->bytes, $start, 'a literal announcement is not {n} and CRLF');
        }
        $size = (int) $m[1];
        $from = $this->at + strlen($m[0]);
        if ($from + $size > strlen($this->bytes)) {
            throw new ProtocolError($this->bytes, $start, 'a literal is shorter than its announced ' . $m[1] . ' bytes');
        }
        $this->at = $from + $size;

        return new Token(TokenKind::Literal, substr($this->bytes, $from, $size));
    }

    private function atom(): Token
    {
        $start = $this->at;
        $length = strlen($this->bytes);
        $depth = 0;
        while ($this->at < $length) {
            $char = $this->bytes[$this->at];
            if ('[' === $char) {
                ++$depth;
            } elseif (']' === $char) {
                if (0 === $depth) {
                    break;
                }
                --$depth;
            } elseif (
                0 === $depth
                && (' ' === $char || '(' === $char || ')' === $char || '{' === $char || '"' === $char)
            ) {
                break;
            } elseif (ord($char) < 0x20 || 0x7F === ord($char)) {
                throw new ProtocolError($this->bytes, $this->at, 'a control character inside an atom');
            }
            ++$this->at;
        }
        if (0 !== $depth) {
            throw new ProtocolError($this->bytes, $start, 'a "[" inside an atom is never closed');
        }
        $text = substr($this->bytes, $start, $this->at - $start);
        if ('' === $text) {
            throw new ProtocolError($this->bytes, $start, 'an empty atom');
        }

        return match (true) {
            0 === strcasecmp($text, 'NIL') => new Token(TokenKind::Nil, null),
            ctype_digit($text) && self::fitsAnInt($text) => new Token(TokenKind::Number, (int) $text),
            default => new Token(TokenKind::Atom, $text),
        };
    }
}
