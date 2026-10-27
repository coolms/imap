<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Value\UidSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UidSetTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function notASet(): iterable
    {
        yield 'a star' => ['1:*'];
        yield 'empty' => [''];
        yield 'a trailing comma' => ['1,2,'];
        yield 'a word' => ['all'];
        yield 'zero' => ['0'];
    }

    #[Test]
    public function aSetIsBoundedUnlessItRunsOnToTheLastMessage(): void
    {
        self::assertTrue(UidSet::of([3, 5])->isBounded());
        self::assertTrue(UidSet::parse('3:5,9')->isBounded());
        self::assertFalse(UidSet::fromOnward(1)->isBounded(), '"1:*" is every message, whatever arrives');
    }

    #[Test]
    public function uidsAreWrittenInTheShortestForm(): void
    {
        self::assertSame('1:5,7,9:12', UidSet::of([12, 1, 2, 3, 4, 5, 7, 9, 10, 11, 3])->toString());
        self::assertSame('42', UidSet::of([42])->toString());
        self::assertSame('4392:*', UidSet::fromOnward(4392)->toString());
    }

    #[Test]
    public function aServersSetReadsBackTheSame(): void
    {
        self::assertSame('1:5,7,9:12', UidSet::parse('9:12,1:5,7,3')->toString());
        self::assertSame('3:5', UidSet::parse('5:3')->toString(), 'a range may run backwards');
    }

    #[Test]
    public function copyuidPairsByTheOrderWritten(): void
    {
        // RFC 4315: COPYUID 38505 304,319:320 3956:3958 -- 304 became 3956, 319 became 3957, 320 became 3958.
        self::assertSame([304, 319, 320], UidSet::listed('304,319:320', 3));
        self::assertSame([3956, 3957, 3958], UidSet::listed('3956:3958', 3));
        self::assertSame([5, 4, 3], UidSet::listed('5:3', 3));
    }

    #[Test]
    public function aHugeSetFromAServerIsNeverExpanded(): void
    {
        self::assertSame('1:4294967295', UidSet::parse('1:4294967295,7')->toString(), 'one range, at once');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names 4294967295 UIDs, more than the 3 asked for');
        UidSet::listed('1:4294967295', 3);
    }

    #[Test]
    #[DataProvider('notASet')]
    public function anythingElseIsRefused(string $set): void
    {
        $this->expectException(InvalidArgumentException::class);
        UidSet::parse($set);
    }

    #[Test]
    public function anEmptySetAndAUidOutOfRangeAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UidSet::of([]);
    }

    #[Test]
    public function aUidAboveThirtyTwoBitsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UidSet::of([4294967296]);
    }
}
