<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\FetchedItems;
use CoolMS\Imap\Value\Vanished;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

final class FetchedItemsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'an item without a value' => ['* 1 FETCH (UID)'];
        yield 'a UID that is not a number' => ['* 1 FETCH (UID x)'];
        yield 'MODSEQ not a list' => ['* 1 FETCH (MODSEQ 5)'];
        yield 'MODSEQ of two' => ['* 1 FETCH (MODSEQ (5 6))'];
        yield 'FLAGS not a list' => ['* 1 FETCH (FLAGS \Seen)'];
        yield 'a flag that is a string' => ['* 1 FETCH (FLAGS ("x"))'];
        yield 'a body that is a list' => ['* 1 FETCH (BODY[] (x))'];
        yield 'no sequence number' => ['* FETCH (UID 1)'];
    }

    /** @return iterable<string, array{string}> */
    public static function brokenVanished(): iterable
    {
        yield 'no set' => ['* VANISHED (EARLIER)'];
        yield 'another tag' => ['* VANISHED (LATER) 1'];
        yield 'two sets' => ['* VANISHED 1 2'];
        yield 'a star' => ['* VANISHED 1:*'];
        yield 'not a set' => ['* VANISHED x'];
    }

    #[Test]
    public function aMessageFetchedWhole(): void
    {
        $raw = "From: a@x\r\nSubject: hi\r\n\r\nbody";
        $items = FetchedItems::fromFetch(Response::parse(
            '* 12 FETCH (UID 4827313 FLAGS (\Seen $Forwarded) MODSEQ (624140003) RFC822.SIZE 4286 '
            . 'INTERNALDATE "17-Jul-1996 02:44:25 -0700" BODY[] {' . strlen($raw) . "}\r\n" . $raw . ')',
        ));

        self::assertSame(12, $items->sequence);
        self::assertSame(4827313, $items->uid);
        self::assertSame(['\Seen', '$Forwarded'], $items->flags);
        self::assertSame(624140003, $items->modseq);
        self::assertSame(4286, $items->size);
        self::assertSame('17-Jul-1996 02:44:25 -0700', $items->internalDate);
        self::assertSame($raw, $items->section());
    }

    #[Test]
    public function aFlagChangeCarriesNoBodyAndSaysNothingOfWhatItDoesNotCarry(): void
    {
        // An unsolicited FETCH after another client's STORE (RFC 7162 s.3.2.4).
        $items = FetchedItems::fromFetch(Response::parse('* 7 FETCH (UID 31 MODSEQ (900) FLAGS ())'));

        self::assertSame([], $items->flags, 'no flags: said');
        self::assertFalse($items->hasSection());
        self::assertNull($items->size);

        $partial = FetchedItems::fromFetch(Response::parse('* 7 FETCH (MODSEQ (901))'));
        self::assertNull($partial->flags, 'flags not given: null, never "no flags"');
        self::assertNull($partial->uid);
    }

    #[Test]
    public function sectionsAreKeptByTheirNameAPartialsOriginIsNot(): void
    {
        $items = FetchedItems::fromFetch(Response::parse("* 1 FETCH (UID 2 body[header] {3}\r\nh:x BODY[1.MIME]<0> \"m\" BODY[2] NIL)"));

        self::assertSame('h:x', $items->section('HEADER'));
        self::assertSame('h:x', $items->section('header'));
        self::assertSame('m', $items->section('1.MIME'));
        self::assertTrue($items->hasSection('2'));
        self::assertNull($items->section('2'), 'NIL: the section is there and empty of meaning');
        self::assertFalse($items->hasSection('3'));
    }

    #[Test]
    public function anItemNotKnownIsPassedOver(): void
    {
        self::assertSame(5, FetchedItems::fromFetch(Response::parse('* 1 FETCH (X-GM-MSGID 1278455344230334865 UID 5 X-GM-LABELS ("\\\\Inbox" Work))'))->uid);
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBrokenFetchIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        FetchedItems::fromFetch(Response::parse($line));
    }

    #[Test]
    public function vanishedEarlierAndNow(): void
    {
        $earlier = Vanished::fromResponse(Response::parse('* VANISHED (EARLIER) 41,43:116,118,120:211,214:540'));
        $now = Vanished::fromResponse(Response::parse('* VANISHED 7'));

        self::assertTrue($earlier->earlier);
        self::assertSame('41,43:116,118,120:211,214:540', $earlier->uids->toString());
        self::assertFalse($now->earlier);
        self::assertSame('7', $now->uids->toString());
        self::assertSame(
            '1:4294967295',
            Vanished::fromResponse(Response::parse('* VANISHED 1:4294967295'))->uids->toString(),
            'one range, never expanded',
        );
    }

    #[Test]
    #[DataProvider('brokenVanished')]
    public function aBrokenVanishedIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        Vanished::fromResponse(Response::parse($line));
    }
}
