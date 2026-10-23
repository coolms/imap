<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use Closure;
use CoolMS\Imap\Value\FetchItem;
use CoolMS\Imap\Value\Section;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FetchItemTest extends TestCase
{
    /** @return iterable<string, array{FetchItem, string}> */
    public static function items(): iterable
    {
        yield 'UID' => [FetchItem::uid(), 'UID'];
        yield 'FLAGS' => [FetchItem::flags(), 'FLAGS'];
        yield 'MODSEQ' => [FetchItem::modseq(), 'MODSEQ'];
        yield 'size' => [FetchItem::size(), 'RFC822.SIZE'];
        yield 'INTERNALDATE' => [FetchItem::internalDate(), 'INTERNALDATE'];
        yield 'the whole message' => [FetchItem::body(), 'BODY.PEEK[]'];
        yield 'the header' => [FetchItem::body(Section::header()), 'BODY.PEEK[HEADER]'];
        yield 'some header fields, any case' => [
            FetchItem::body(Section::headerFields('From', 'message-id')),
            'BODY.PEEK[HEADER.FIELDS (FROM MESSAGE-ID)]',
        ];
        yield 'all header fields but some' => [
            FetchItem::body(Section::headerFieldsNot('Received')),
            'BODY.PEEK[HEADER.FIELDS.NOT (RECEIVED)]',
        ];
        yield 'the text' => [FetchItem::body(Section::text()), 'BODY.PEEK[TEXT]'];
        yield 'a part' => [FetchItem::body(Section::part(1, 2)), 'BODY.PEEK[1.2]'];
        yield 'a part\'s MIME header' => [FetchItem::body(Section::partMime(2)), 'BODY.PEEK[2.MIME]'];
        yield 'the first KiB' => [FetchItem::body(Section::whole(), 0, 1024), 'BODY.PEEK[]<0.1024>'];
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'no header field named' => [static fn (): mixed => Section::headerFields()];
        yield 'a field name that would close the list' => [static fn (): mixed => Section::headerFields('From)')];
        yield 'a field name with a space' => [static fn (): mixed => Section::headerFieldsNot('X Y')];
        yield 'no part named' => [static fn (): mixed => Section::part()];
        yield 'a part numbered 0' => [static fn (): mixed => Section::part(1, 0)];
        yield 'a MIME header of no part' => [static fn (): mixed => Section::partMime()];
        yield 'a start without a length' => [static fn (): mixed => FetchItem::body(Section::whole(), 0)];
        yield 'a length without a start' => [static fn (): mixed => FetchItem::body(Section::whole(), null, 10)];
        yield 'a negative start' => [static fn (): mixed => FetchItem::body(Section::whole(), -1, 10)];
        yield 'an empty length' => [static fn (): mixed => FetchItem::body(Section::whole(), 0, 0)];
    }

    #[Test]
    #[DataProvider('items')]
    public function eachItemIsSentAsTheGrammarSpellsIt(FetchItem $item, string $wire): void
    {
        self::assertSame($wire, $item->wire);
    }

    #[Test]
    public function aSectionIsLookedUpByTheNameTheServerEchoes(): void
    {
        // BODY.PEEK[...] comes back as BODY[...] (RFC 9051 s.7.5.2), its field names as they were asked.
        self::assertSame('HEADER.FIELDS (FROM MESSAGE-ID)', Section::headerFields('From', 'message-id')->spec);
        self::assertSame('', Section::whole()->spec);
        self::assertSame('1.2', Section::part(1, 2)->spec);
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('refused')]
    public function whatTheGrammarCannotCarryIsRefusedWhereItIsBuilt(Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }
}
