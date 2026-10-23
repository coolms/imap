<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

use CoolMS\Imap\Exception\ProtocolError;
use LogicException;

use function is_array;
use function is_int;
use function is_string;

/**
 * One token of a server response (RFC 9051 s.9 / RFC 3501 s.9): what kind it is and its value. A list's value is
 * its tokens; a response code -- "[...]" -- is a list too, of kind Code; NIL has no value.
 */
final readonly class Token
{
    /** @param string|int|list<Token>|null $value */
    public function __construct(
        public TokenKind $kind,
        public string|int|array|null $value,
    ) {
    }

    /** The token as text: an atom, a string, a literal or a number; null for NIL. */
    public function text(): ?string
    {
        return match ($this->kind) {
            TokenKind::Nil => null,
            TokenKind::Atom, TokenKind::Number, TokenKind::Quoted, TokenKind::Literal => is_array($this->value)
                ? throw new LogicException('A list has no text.') : $this->string(),
            TokenKind::List, TokenKind::Code => throw new LogicException('A list has no text.'),
        };
    }

    /**
     * The value of an atom, a number, a string or a literal, as text. Where a response's grammar puts one of those,
     * a list or NIL is a response that does not parse: a ProtocolError, never "Array" or "".
     */
    public function string(): string
    {
        return match (true) {
            is_string($this->value) => $this->value,
            is_int($this->value) => (string) $this->value,
            default => throw new ProtocolError($this->kind->name, 0, 'a ' . $this->kind->name . ' where text was due'),
        };
    }

    /** @return list<Token> */
    public function items(): array
    {
        return is_array($this->value) ? $this->value : throw new LogicException('Only a list has items.');
    }
}
