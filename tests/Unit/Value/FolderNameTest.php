<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\MalformedFolderName;
use CoolMS\Imap\Value\FolderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FolderNameTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pairs(): iterable
    {
        yield 'RFC 3501 s.5.1.3' => ['~peter/mail/台北/日本語', '~peter/mail/&U,BTFw-/&ZeVnLIqe-'];
        yield 'plain ASCII' => ['INBOX', 'INBOX'];
        yield 'an ampersand' => ['Tom & Jerry', 'Tom &- Jerry'];
        yield 'Gmail, Russian' => ['[Gmail]/Входящие', '[Gmail]/&BBIERQQ+BDQETwRJBDgENQ-'];
        yield 'German' => ['Entwürfe', 'Entw&APw-rfe'];
        yield 'outside the BMP' => ['Mail 📬', 'Mail &2D3c7A-'];
        yield 'a tab is shifted' => ["a\tb", 'a&AAk-b'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'a shift never closed' => ['&U,BTFw', 'never closed'];
        yield 'a printable character inside a shift' => ['&AGE-', 'printable character inside a shift'];
        yield 'padding bits that are not zero' => ['&U,BTFx-', 'not zero'];
        yield 'a lone high surrogate' => ['&2D0-', 'high surrogate'];
        yield 'a lone low surrogate' => ['&3KA-', 'low surrogate'];
        yield 'a character outside the alphabet' => ['&U/BTFw-', 'outside its alphabet'];
        yield 'a raw 8-bit byte' => ["Entw\xC3\xBCrfe", 'outside printable US-ASCII'];
        yield 'a half UTF-16 unit' => ['&AA-', 'whole UTF-16 units'];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function aNameReadsTheSameBothWays(string $display, string $wire): void
    {
        self::assertSame($wire, FolderName::fromDisplay($display)->wire, 'to the server');
        self::assertSame($display, FolderName::fromWire($wire)->display, 'from the server');
    }

    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedServerNameIsRefusedByName(string $wire, string $reason): void
    {
        try {
            FolderName::fromWire($wire);
            self::fail('accepted: ' . $wire);
        } catch (MalformedFolderName $e) {
            self::assertSame($wire, $e->name);
            self::assertStringContainsString($reason, $e->reason);
        }
    }

    #[Test]
    public function aDisplayNameThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(MalformedFolderName::class);
        FolderName::fromDisplay("Entw\xFCrfe");
    }
}
