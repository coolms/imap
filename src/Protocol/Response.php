<?php

declare(strict_types=1);

namespace CoolMS\Imap\Protocol;

use CoolMS\Imap\Exception\ProtocolError;

use function in_array;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

/**
 * One server response, sorted (RFC 9051 s.7):
 *  - continuation: "+ text";
 *  - tagged: "<tag> OK|NO|BAD [code] text";
 *  - untagged status: "* OK|NO|BAD|BYE|PREAUTH [code] text";
 *  - untagged data: "* <tokens>" -- CAPABILITY, LIST, FLAGS, "n EXISTS", "n FETCH (...)", ...
 *
 * A status response's text is the server's own words, kept as they came: free text holds quotes and brackets that
 * are no syntax, so it is never tokenized. Only the code before it is.
 */
final readonly class Response
{
    private const array STATUS = ['OK', 'NO', 'BAD', 'BYE', 'PREAUTH'];

    /** @param list<Token> $data */
    private function __construct(
        public ResponseKind $kind,
        public ?string $tag,
        public ?string $status,
        public ?Token $code,
        public string $text,
        public array $data,
    ) {
    }

    public static function parse(string $response): self
    {
        if (str_starts_with($response, '+')) {
            return new self(ResponseKind::Continuation, null, null, null, self::rest($response, 1), []);
        }
        if (1 !== preg_match('/\A(\*|[^\s(){%*"\\\\\]]+) /', $response, $m)) {
            throw new ProtocolError($response, 0, 'a response begins with neither "*", "+" nor a tag');
        }
        $tag = '*' === $m[1] ? null : $m[1];
        $after = substr($response, strlen($m[0]));
        if (
            1 === preg_match('/\A([A-Za-z]+)(?: |\z)/', $after, $s)
            && in_array(strtoupper($s[1]), self::STATUS, true)
        ) {
            [$code, $text] = self::codeAndText($response, substr($after, strlen($s[0])));

            return new self(
                null === $tag ? ResponseKind::UntaggedStatus : ResponseKind::Tagged,
                $tag,
                strtoupper($s[1]),
                $code,
                $text,
                [],
            );
        }
        if (null !== $tag) {
            throw new ProtocolError($response, strlen($m[0]), 'a tagged response carries no OK, NO or BAD');
        }

        return new self(ResponseKind::UntaggedData, null, null, null, '', Tokenizer::tokenize($after));
    }

    /** @return array{?Token, string} */
    private static function codeAndText(string $response, string $rest): array
    {
        if (!str_starts_with($rest, '[')) {
            return [null, $rest];
        }
        // The code ends at the first "]" that closes it; a code's own lists, e.g. PERMANENTFLAGS (\* \Seen), hold none.
        $depth = 0;
        $length = strlen($rest);
        for ($i = 0; $i < $length; ++$i) {
            $depth += match ($rest[$i]) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };
            if (0 === $depth) {
                $tokens = Tokenizer::tokenize(substr($rest, 0, $i + 1));

                return [$tokens[0], self::rest($rest, $i + 1)];
            }
        }

        throw new ProtocolError($response, strlen($response) - $length, 'a response code is never closed');
    }

    private static function rest(string $text, int $from): string
    {
        $rest = substr($text, $from);

        return str_starts_with($rest, ' ') ? substr($rest, 1) : $rest;
    }
}
