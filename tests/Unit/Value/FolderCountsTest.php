<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\FolderCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FolderCountsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'an item without a value' => ['* STATUS x (MESSAGES)'];
        yield 'a value that is not a number' => ['* STATUS x (MESSAGES many)'];
        yield 'no list' => ['* STATUS x'];
        yield 'not STATUS' => ['* LIST x (MESSAGES 1)'];
    }

    #[Test]
    public function eachItemGivenIsRead(): void
    {
        // RFC 7162 s.3.1.3's example, with UNSEEN and UIDVALIDITY added.
        $counts = FolderCounts::fromStatus(Response::parse('* STATUS blurdybloop (MESSAGES 231 UIDNEXT 44292 HIGHESTMODSEQ 7011231777 unseen 0 UIDVALIDITY 9)'));

        self::assertSame('blurdybloop', $counts->name->display);
        self::assertSame(231, $counts->messages);
        self::assertSame(44292, $counts->uidNext);
        self::assertSame(7011231777, $counts->highestModseq);
        self::assertSame(0, $counts->unseen, 'none unseen, and asked');
        self::assertSame(9, $counts->uidValidity);
    }

    #[Test]
    public function anItemNotGivenIsNullNeverZero(): void
    {
        $counts = FolderCounts::fromStatus(Response::parse('* STATUS "&BB8EQAQ+BDUEOgRCBEs-" (MESSAGES 2)'));

        self::assertSame('Проекты', $counts->name->display);
        self::assertNull($counts->unseen);
        self::assertNull($counts->highestModseq);
    }

    #[Test]
    public function inboxIsInboxInAnyCase(): void
    {
        self::assertSame('INBOX', FolderCounts::fromStatus(Response::parse('* STATUS inbox (MESSAGES 0)'))->name->wire);
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBrokenStatusIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        FolderCounts::fromStatus(Response::parse($line));
    }
}
