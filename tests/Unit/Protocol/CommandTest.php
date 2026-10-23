<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Protocol;

use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\UidSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class CommandTest extends TestCase
{
    /** @return iterable<string, array{callable}> */
    public static function refused(): iterable
    {
        yield 'an atom with a space' => [static fn () => Argument::atom('a b')];
        yield 'an atom with a bracket' => [static fn () => Argument::atom('BODY[]')];
        yield 'an atom with a CR' => [static fn () => Argument::atom("X\r")];
        yield 'an empty atom' => [static fn () => Argument::atom('')];
        yield 'an 8-bit atom' => [static fn () => Argument::atom('Ж')];
        yield 'a NUL in text' => [static fn () => Argument::text("a\0b")];
        yield 'a NUL in a secret' => [static fn () => Argument::secret("a\0b")];
        yield 'a NUL in a literal' => [static fn () => Argument::literal("a\0b")];
        yield 'raw syntax over two lines' => [static fn () => Argument::raw("BODY[]\r\na2 LOGOUT")];
        yield 'empty raw syntax' => [static fn () => Argument::raw('')];
        yield 'a negative number' => [static fn () => Argument::number(-1)];
        yield 'a lowercase command' => [static fn () => new Command('select')];
        yield 'a command with a CRLF' => [static fn () => new Command("NOOP\r\na2 LOGOUT")];
        yield 'a tag with a space' => [static fn () => new Command('NOOP')->encode('a 1', false)];
        yield 'a tag with a CRLF' => [static fn () => new Command('NOOP')->logged("a1\r\n")];
    }

    #[Test]
    public function textGoesQuotedItsQuoteAndBackslashEscaped(): void
    {
        $login = new Command('LOGIN', Argument::text('me@x.test'), Argument::secret('p"a\ss'));

        self::assertSame(["a1 LOGIN \"me@x.test\" \"p\\\"a\\\\ss\"\r\n"], $login->encode('a1', false));
        self::assertSame('a1 LOGIN "me@x.test" <secret>', $login->logged('a1'), 'a secret is never in the log form');
    }

    #[Test]
    public function textThatCannotBeQuotedGoesAsALiteralWaitingForTheServerWithoutLiteralPlus(): void
    {
        $search = new Command(
            'UID SEARCH',
            Argument::atom('CHARSET'),
            Argument::atom('UTF-8'),
            Argument::atom('SUBJECT'),
            Argument::text('Счёт'),
        );

        self::assertSame(
            ["a2 UID SEARCH CHARSET UTF-8 SUBJECT {8}\r\n", "Счёт\r\n"],
            $search->encode('a2', false),
            'the bytes go only after the "+" -- so the announcement ends a piece',
        );
        self::assertSame(["a2 UID SEARCH CHARSET UTF-8 SUBJECT {8+}\r\nСчёт\r\n"], $search->encode('a2', true));
        self::assertSame('a2 UID SEARCH CHARSET UTF-8 SUBJECT {8 bytes}', $search->logged('a2'));
    }

    #[Test]
    public function aLineBreakInTextCannotEndTheCommandItGoesInsideALiteral(): void
    {
        $select = new Command('SELECT', Argument::text("x\r\na9 DELETE INBOX"));

        self::assertSame(["a3 SELECT {18}\r\n", "x\r\na9 DELETE INBOX\r\n"], $select->encode('a3', false));
        self::assertSame(["a3 SELECT {18+}\r\nx\r\na9 DELETE INBOX\r\n"], $select->encode('a3', true));
    }

    #[Test]
    public function longTextGoesAsALiteral(): void
    {
        self::assertSame(
            ['a4 SELECT "' . str_repeat('x', 1000) . "\"\r\n"],
            new Command('SELECT', Argument::text(str_repeat('x', 1000)))->encode('a4', false),
        );
        self::assertSame(
            ["a4 SELECT {1001}\r\n", str_repeat('x', 1001) . "\r\n"],
            new Command('SELECT', Argument::text(str_repeat('x', 1001)))->encode('a4', false),
        );
    }

    #[Test]
    public function aLiteralIsALiteralEvenWhenItCouldBeQuoted(): void
    {
        $append = new Command(
            'APPEND',
            Argument::folder(FolderName::fromDisplay('Sent')),
            Argument::list(Argument::atom('\Seen')),
            Argument::literal('hi'),
        );

        self::assertSame(["a5 APPEND \"Sent\" (\\Seen) {2}\r\n", "hi\r\n"], $append->encode('a5', false));
        self::assertSame('a5 APPEND "Sent" (\Seen) {2 bytes}', $append->logged('a5'));
    }

    #[Test]
    public function twoLiteralsMakeThreePieces(): void
    {
        $login = new Command('LOGIN', Argument::text('я'), Argument::secret('пароль'));

        self::assertSame(["a6 LOGIN {2}\r\n", "я {12}\r\n", "пароль\r\n"], $login->encode('a6', false));
        self::assertSame('a6 LOGIN {2 bytes} <secret>', $login->logged('a6'));
    }

    #[Test]
    public function listsNestAndRawSyntaxGoesAsComposed(): void
    {
        $fetch = new Command(
            'UID FETCH',
            Argument::uids(UidSet::of([1, 2, 3, 7])),
            Argument::list(Argument::atom('UID'), Argument::atom('FLAGS'), Argument::raw('BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)]')),
            Argument::list(Argument::atom('CHANGEDSINCE'), Argument::number(12345)),
        );

        self::assertSame(["a7 UID FETCH 1:3,7 (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)]) (CHANGEDSINCE 12345)\r\n"], $fetch->encode('a7', false));
    }

    #[Test]
    public function aFolderGoesByItsWireName(): void
    {
        $create = new Command('CREATE', Argument::folder(FolderName::fromDisplay('Проекты')));

        self::assertSame(["a8 CREATE \"&BB8EQAQ+BDUEOgRCBEs-\"\r\n"], $create->encode('a8', false));
    }

    #[Test]
    #[DataProvider('refused')]
    public function whatWouldBreakTheFramingIsRefusedWhereItIsBuilt(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }
}
