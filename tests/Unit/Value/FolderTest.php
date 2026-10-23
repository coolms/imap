<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\MalformedFolderName;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\Folder;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\SpecialUse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FolderTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?SpecialUse, bool}> */
    public static function servers(): iterable
    {
        yield 'dovecot Sent' => ['* LIST (\HasNoChildren \Sent) "/" "Sent"', 'Sent', SpecialUse::Sent, true];
        yield 'gmail All Mail' => [
            '* LIST (\All \HasNoChildren) "/" "[Gmail]/All Mail"',
            '[Gmail]/All Mail',
            SpecialUse::All,
            true,
        ];
        yield 'gmail Spam' => [
            '* LIST (\HasNoChildren \Junk) "/" "[Gmail]/Spam"',
            '[Gmail]/Spam',
            SpecialUse::Junk,
            true,
        ];
        yield 'gmail parent' => ['* LIST (\HasChildren \Noselect) "/" "[Gmail]"', '[Gmail]', null, false];
        yield 'exchange Deleted Items' => [
            '* LIST (\HasNoChildren \Trash) "/" "Deleted Items"',
            'Deleted Items',
            SpecialUse::Trash,
            true,
        ];
        yield 'a folder with no role' => ['* LIST (\HasNoChildren) "/" "Projects"', 'Projects', null, true];
        yield 'inbox in any case' => ['* LIST (\HasNoChildren) "/" inbox', 'INBOX', SpecialUse::Inbox, true];
        yield 'two roles: the first' => ['* LIST (\Junk \Trash) "/" "Bin"', 'Bin', SpecialUse::Junk, true];
        yield 'NonExistent' => ['* LIST (\NonExistent \HasChildren) "/" "Old"', 'Old', null, false];
        yield 'attribute case' => ['* LIST (\hasnochildren \SENT) "/" "Out"', 'Out', SpecialUse::Sent, true];
    }

    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'no name' => ['* LIST (\HasNoChildren) "/"'];
        yield 'attributes not a list' => ['* LIST \HasNoChildren "/" "x"'];
        yield 'a delimiter that is a list' => ['* LIST () (a) "x"'];
        yield 'a name that is a list' => ['* LIST () "/" (x)'];
        yield 'not LIST' => ['* LSUB () "/" "x"'];
        yield 'an attribute that is a string' => ['* LIST ("x") "/" "x"'];
    }

    /** The LIST lines as each kind of server writes them (Gmail and Exchange from their documented examples). */
    #[Test]
    #[DataProvider('servers')]
    public function aFoldersRoleComesFromItsAttributes(
        string $line,
        string $display,
        ?SpecialUse $role,
        bool $selectable,
    ): void {
        $folder = Folder::fromList(Response::parse($line));

        self::assertSame($display, $folder->name->display);
        self::assertSame($role, $folder->role);
        self::assertSame($selectable, $folder->selectable());
        self::assertSame('/', $folder->delimiter);
    }

    #[Test]
    public function aNameInAnotherScriptIsDecoded(): void
    {
        $wire = FolderName::fromDisplay('Отправленные')->wire;
        $folder = Folder::fromList(Response::parse('* LIST (\HasNoChildren \Sent) "/" "' . $wire . '"'));

        self::assertSame('&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-', $wire, 'the wire form, checked independently');
        self::assertSame('Отправленные', $folder->name->display);
        self::assertSame($wire, $folder->name->wire, 'and sent back exactly as it came');
    }

    #[Test]
    public function aFlatServerHasNoDelimiterAndANameMayComeAsALiteral(): void
    {
        $folder = Folder::fromList(Response::parse("* LIST () NIL {7}\r\nA \"b\" c"));

        self::assertNull($folder->delimiter);
        self::assertSame('A "b" c', $folder->name->display);
    }

    #[Test]
    public function aNameThatDoesNotDecodeIsRefusedNotShown(): void
    {
        $this->expectException(MalformedFolderName::class);
        Folder::fromList(Response::parse('* LIST () "/" "&BB4-x&"'));
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBrokenListResponseIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        Folder::fromList(Response::parse($line));
    }
}
