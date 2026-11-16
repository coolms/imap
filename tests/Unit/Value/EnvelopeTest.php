<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\FetchedItems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

final class EnvelopeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'not a list' => ['* 1 FETCH (ENVELOPE NIL)'];
        yield 'nine fields' => ['* 1 FETCH (ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL))'];
        yield 'an address of three fields' => ['* 1 FETCH (ENVELOPE (NIL NIL ((NIL "a" "b")) NIL NIL NIL NIL NIL NIL NIL))'];
        yield 'an address list that is a string' => ['* 1 FETCH (ENVELOPE (NIL NIL "a" NIL NIL NIL NIL NIL NIL NIL))'];
        yield 'a subject that is a list' => ['* 1 FETCH (ENVELOPE (NIL (x) NIL NIL NIL NIL NIL NIL NIL NIL))'];
    }

    #[Test]
    public function anEnvelopeIsReadFieldByField(): void
    {
        $items = FetchedItems::fromFetch(Response::parse(
            '* 3 FETCH (UID 41 ENVELOPE ("Mon, 16 Nov 2026 01:02:03 +0300" "=?utf-8?q?Hi_there?=" '
            . '(("Ann Lee" NIL "ann" "example.com")) (("Ann Lee" NIL "ann" "example.com")) NIL '
            . '((NIL NIL "bob" "example.org") ("Cy" NIL "cy" "example.net")) NIL NIL '
            . '"<parent@example.com>" "<41@example.com>"))',
        ));

        $envelope = $items->envelope;
        self::assertNotNull($envelope);
        self::assertSame('Mon, 16 Nov 2026 01:02:03 +0300', $envelope->date);
        self::assertSame('=?utf-8?q?Hi_there?=', $envelope->subject, 'kept as sent: decoding is the caller\'s');
        self::assertSame('ann@example.com', $envelope->from[0]->email());
        self::assertSame('Ann Lee', $envelope->from[0]->name);
        self::assertSame([], $envelope->replyTo, 'NIL is no addresses');
        self::assertSame(['bob@example.org', 'cy@example.net'], [$envelope->to[0]->email(), $envelope->to[1]->email()]);
        self::assertNull($envelope->to[0]->name);
        self::assertSame('<parent@example.com>', $envelope->inReplyTo);
        self::assertSame('<41@example.com>', $envelope->messageId);
        self::assertSame(41, $items->uid);
    }

    #[Test]
    public function aGroupGivesItsMembersAndNeverItsNameAsAnAddress(): void
    {
        $items = FetchedItems::fromFetch(Response::parse(
            '* 1 FETCH (ENVELOPE (NIL "x" NIL NIL NIL '
            . '((NIL NIL "team" NIL) (NIL NIL "a" "x.org") (NIL NIL NIL NIL)) '
            . '((NIL NIL "undisclosed-recipients" NIL) (NIL NIL NIL NIL)) NIL NIL NIL))',
        ));

        self::assertNotNull($items->envelope);
        self::assertCount(1, $items->envelope->to, 'the member, without the group\'s name or its end');
        self::assertSame('a@x.org', $items->envelope->to[0]->email());
        self::assertSame([], $items->envelope->cc, 'an empty group is no one');
        self::assertNull($items->envelope->date);
    }

    #[Test]
    public function aSubjectSentAsALiteralIsRead(): void
    {
        $subject = 'Line with "quotes"';
        $items = FetchedItems::fromFetch(Response::parse(
            '* 1 FETCH (ENVELOPE (NIL {' . strlen($subject) . "}\r\n" . $subject
            . ' NIL NIL NIL NIL NIL NIL NIL NIL))',
        ));

        self::assertSame($subject, $items->envelope?->subject);
    }

    #[Test]
    #[DataProvider('broken')]
    public function anEnvelopeThatDoesNotParseIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        FetchedItems::fromFetch(Response::parse($line));
    }

    #[Test]
    public function withoutTheItemThereIsNoEnvelope(): void
    {
        $items = FetchedItems::fromFetch(Response::parse('* 1 FETCH (UID 9)'));

        self::assertNull($items->envelope);
        self::assertNull($items->bodyStructure);
    }
}
