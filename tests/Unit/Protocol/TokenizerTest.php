<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Protocol;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\Tokenizer;
use CoolMS\Imap\Protocol\TokenKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strlen;

use const PHP_INT_MAX;

final class TokenizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function broken(): iterable
    {
        yield 'a list never closed' => ['* 1 FETCH (UID 1', 'ends before ")"'];
        yield 'a code never closed' => ['OK [UIDVALIDITY 1', 'ends before "]"'];
        yield 'a string never closed' => ['* LIST () "/" "INBOX', 'never closed'];
        yield 'a literal shorter than announced' => [
            "* 1 FETCH (BODY[] {10}\r\nshort)",
            'shorter than its announced 10',
        ];
        yield 'a literal with no CRLF' => ['* 1 FETCH (BODY[] {3} abc)', 'not {n} and CRLF'];
        yield 'a bracket closed out of turn' => ['* 1 FETCH (UID 1]', '"]" where ")" was due'];
        yield 'a stray closing bracket' => ['* OK done)', 'nothing open'];
        yield 'a bad escape' => ['"a\x"', 'escapes only'];
    }

    #[Test]
    public function aFetchWithFlagsModseqAndALiteralBody(): void
    {
        $body = "Subject: hi\r\n\r\n(a body with \"quotes\" and ) parens)";
        $tokens = Tokenizer::tokenize('* 12 FETCH (UID 4827313 FLAGS (\Seen $Forwarded) MODSEQ (1234567890123) '
            . 'BODY[HEADER.FIELDS (SUBJECT)] {' . strlen($body) . "}\r\n" . $body . ')');

        self::assertSame(['*', 12, 'FETCH'], [$tokens[0]->value, $tokens[1]->value, $tokens[2]->value]);
        self::assertSame(TokenKind::Number, $tokens[1]->kind);
        $items = $tokens[3]->items();
        self::assertSame('UID', $items[0]->text());
        self::assertSame(4827313, $items[1]->value);
        self::assertSame(['\Seen', '$Forwarded'], self::texts($items[3]->items()), 'flags keep their backslash');
        self::assertSame(1234567890123, $items[5]->items()[0]->value, 'a 64-bit modseq is a number');
        self::assertSame('BODY[HEADER.FIELDS (SUBJECT)]', $items[6]->text(), 'a section is one atom, spaces and all');
        self::assertSame(TokenKind::Literal, $items[7]->kind);
        self::assertSame($body, $items[7]->text(), 'a literal is its bytes, whatever they hold');
    }

    #[Test]
    public function everyModSequenceUpTo2To63Minus1IsANumberAndPastItTheDigitsStayAsTheyCame(): void
    {
        $tokens = Tokenizer::tokenize('1000000000000000000 9223372036854775807 0009223372036854775807 9223372036854775808 99999999999999999999');

        self::assertSame(TokenKind::Number, $tokens[0]->kind, '19 digits');
        self::assertSame(1000000000000000000, $tokens[0]->value);
        self::assertSame(TokenKind::Number, $tokens[1]->kind);
        self::assertSame(PHP_INT_MAX, $tokens[1]->value, 'the largest mod-sequence (RFC 7162 s.7)');
        self::assertSame(PHP_INT_MAX, $tokens[2]->value, 'leading zeros');
        self::assertSame([TokenKind::Atom, '9223372036854775808'], [$tokens[3]->kind, $tokens[3]->value], 'one past: never cast');
        self::assertSame([TokenKind::Atom, '99999999999999999999'], [$tokens[4]->kind, $tokens[4]->value]);
    }

    #[Test]
    public function aResponseCodeIsAListOfItsOwnKind(): void
    {
        $tokens = Tokenizer::tokenize('OK [COPYUID 38505 304,319:320 3956:3958]');
        $code = $tokens[1];

        self::assertSame(TokenKind::Code, $code->kind);
        self::assertSame(['COPYUID', '38505', '304,319:320', '3956:3958'], self::texts($code->items()));
    }

    #[Test]
    public function quotedStringsNilAndAnEmptyList(): void
    {
        $tokens = Tokenizer::tokenize('* LIST (\HasNoChildren \Trash) "/" "[Gmail]/Trash" NIL () "a \"q\" \\\\ b"');

        self::assertSame(['\HasNoChildren', '\Trash'], self::texts($tokens[2]->items()));
        self::assertSame('/', $tokens[3]->text());
        self::assertSame('[Gmail]/Trash', $tokens[4]->text());
        self::assertSame(TokenKind::Nil, $tokens[5]->kind);
        self::assertSame([], $tokens[6]->items());
        self::assertSame('a "q" \\ b', $tokens[7]->text());
    }

    #[Test]
    public function aLiteral8IsALiteral(): void
    {
        $tokens = Tokenizer::tokenize("* 1 FETCH (BINARY[] ~{3}\r\n\x00\x01\x02)");

        self::assertSame("\x00\x01\x02", $tokens[3]->items()[1]->text());
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBrokenResponseIsAProtocolErrorThatSaysWhere(string $response, string $reason): void
    {
        try {
            Tokenizer::tokenize($response);
            self::fail('read as if whole: ' . $response);
        } catch (ProtocolError $e) {
            self::assertStringContainsString($reason, $e->reason);
        }
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<?string>
     */
    private static function texts(array $tokens): array
    {
        return array_map(static fn (Token $t): ?string => $t->text(), $tokens);
    }
}
