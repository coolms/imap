<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;

use function array_keys;
use function in_array;
use function str_starts_with;
use function strtoupper;
use function substr;

/** What the server says it can do (RFC 9051 s.7.2.2): upper-cased atoms, asked by name, any case. */
final readonly class Capabilities
{
    /** @param list<string> $names */
    private function __construct(public array $names)
    {
    }

    /** From the tokens after the word CAPABILITY -- a "* CAPABILITY" response's or an "[CAPABILITY ...]" code's. */
    public static function fromTokens(Token ...$tokens): self
    {
        $names = [];
        foreach ($tokens as $token) {
            if (TokenKind::Atom === $token->kind) {
                $names[strtoupper($token->string())] = true;
            }
        }
        unset($names['CAPABILITY']);

        return new self(array_keys($names));
    }

    /** By the enum, or by name where it has no case: AUTH=XOAUTH2, a vendor's X-... */
    public function has(Capability|string $name): bool
    {
        return in_array($name instanceof Capability ? $name->value : strtoupper($name), $this->names, true);
    }

    /** @return list<string> the SASL mechanisms named by AUTH=, in the order given */
    public function mechanisms(): array
    {
        $mechanisms = [];
        foreach ($this->names as $name) {
            if (str_starts_with($name, 'AUTH=')) {
                $mechanisms[] = substr($name, 5);
            }
        }

        return $mechanisms;
    }
}
