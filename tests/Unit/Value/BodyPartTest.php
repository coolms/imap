<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\BodyPart;
use CoolMS\Imap\Value\FetchedItems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

final class BodyPartTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'not a list' => ['* 1 FETCH (BODYSTRUCTURE NIL)'];
        yield 'an empty list' => ['* 1 FETCH (BODYSTRUCTURE ())'];
        yield 'a part of six fields' => ['* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT"))'];
        yield 'a size that is not a number' => ['* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" "x" 1))'];
        yield 'parameters of odd length' => ['* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("A") NIL NIL "7BIT" 1 1))'];
        yield 'a type that is NIL' => ['* 1 FETCH (BODYSTRUCTURE (NIL "PLAIN" NIL NIL NIL "7BIT" 1 1))'];
    }

    #[Test]
    public function aMessageThatIsNotMultipartIsOnePartNumberedOne(): void
    {
        $body = self::structure('("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 1152 23 NIL NIL NIL NIL)');

        self::assertSame('1', $body->section);
        self::assertSame('text/plain', $body->mimeType());
        self::assertSame(['charset' => 'utf-8'], $body->params);
        self::assertSame('7bit', $body->encoding);
        self::assertSame(1152, $body->size);
        self::assertSame(23, $body->lines);
        self::assertSame($body, $body->firstText('plain'));
        self::assertSame([], $body->attachments());
    }

    #[Test]
    public function anAlternativeWithAnAttachmentIsNumberedAsTheRfcNumbersIt(): void
    {
        // multipart/mixed ( multipart/alternative (plain, html), application/pdf attachment )
        $body = self::structure(
            '((("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 310 9 NIL NIL NIL NIL)'
            . '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 2048 40 NIL NIL NIL NIL) "ALTERNATIVE" '
            . '("BOUNDARY" "b2") NIL NIL NIL)'
            . '("APPLICATION" "PDF" ("NAME" "q3.pdf") NIL NIL "BASE64" 74000 NIL ("ATTACHMENT" ("FILENAME" "q3.pdf")) '
            . 'NIL NIL) "MIXED" ("BOUNDARY" "b1") NIL NIL NIL)',
        );

        self::assertTrue($body->isMultipart());
        self::assertSame('multipart/mixed', $body->mimeType());
        self::assertSame('', $body->section);
        self::assertSame(['1.1', '1.2', '2'], array_map(static fn (BodyPart $p): string => $p->section, $body->leaves()));
        self::assertSame('1.1', $body->firstText('plain')?->section);
        $html = $body->firstText('html');
        self::assertNotNull($html);
        self::assertSame('1.2', $html->section);
        self::assertSame('1.2', $html->fetchSection()->spec, 'fetched as BODY.PEEK[1.2]');
        self::assertSame('TEXT', $body->fetchSection()->spec, 'the top multipart: its text');
        $attachments = $body->attachments();
        self::assertCount(1, $attachments);
        self::assertSame('q3.pdf', $attachments[0]->filename());
        self::assertSame('application/pdf', $attachments[0]->mimeType());
        self::assertSame(74000, $attachments[0]->size);
        self::assertSame('attachment', $attachments[0]->disposition);
    }

    #[Test]
    public function aForwardedMessageIsOneAttachmentAndItsPartsAreNumberedUnderIt(): void
    {
        $body = self::structure(
            '(("TEXT" "PLAIN" NIL NIL NIL "7BIT" 20 1 NIL NIL NIL NIL)'
            . '("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 900 (NIL "Fwd" NIL NIL NIL NIL NIL NIL NIL NIL) '
            . '(("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 1 NIL NIL NIL NIL)("IMAGE" "PNG" ("NAME" "a.png") NIL NIL '
            . '"BASE64" 400 NIL NIL NIL NIL) "MIXED" NIL NIL NIL NIL) 30 NIL NIL NIL NIL) "MIXED" NIL NIL NIL NIL)',
        );

        $forwarded = $body->parts[1];
        self::assertSame('2', $forwarded->section);
        self::assertSame('Fwd', $forwarded->envelope?->subject);
        self::assertSame(30, $forwarded->lines);
        $inner = $forwarded->message;
        self::assertNotNull($inner);
        self::assertSame(['2.1', '2.2'], array_map(static fn (BodyPart $p): string => $p->section, $inner->parts));
        self::assertSame(['2'], array_map(static fn (BodyPart $p): string => $p->section, $body->attachments()), 'one attachment: the message');
        self::assertSame('1', $body->firstText('plain')?->section, 'the forwarded message\'s own text is not this one\'s');
    }

    #[Test]
    public function aForwardedSinglePartMessageHasItsPartAtOneUnderIt(): void
    {
        $body = self::structure(
            '("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 300 (NIL "x" NIL NIL NIL NIL NIL NIL NIL NIL) '
            . '("TEXT" "HTML" NIL NIL NIL "7BIT" 120 4 NIL NIL NIL NIL) 9 NIL NIL NIL NIL)',
        );

        self::assertSame('1', $body->section);
        $inner = $body->message;
        self::assertNotNull($inner);
        self::assertSame('1.1', $inner->section);
        self::assertSame('text/html', $inner->mimeType());
    }

    #[Test]
    public function anRfc2231FileNameIsDecodedAndItsContinuationsJoined(): void
    {
        $single = self::structure(
            '("APPLICATION" "OCTET-STREAM" NIL NIL NIL "BASE64" 10 NIL ("ATTACHMENT" ("FILENAME*" '
            . '"utf-8\'\'%D0%BE%D1%82%D1%87%D1%91%D1%82.txt")) NIL NIL)',
        );
        self::assertSame('отчёт.txt', $single->filename());

        $continued = self::structure(
            '("APPLICATION" "OCTET-STREAM" ("NAME*0" "a very long " "NAME*1" "name.bin") NIL NIL "BASE64" 10 NIL '
            . 'NIL NIL NIL)',
        );
        self::assertSame('a very long name.bin', $continued->filename());
        self::assertTrue($continued->isAttachment(), 'a named part that is not text, with no disposition');
    }

    #[Test]
    public function anInlineImageAndANamedTextPartAreNotAttachments(): void
    {
        $inline = self::structure(
            '("IMAGE" "PNG" ("NAME" "logo.png") "<logo@x>" NIL "BASE64" 10 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL)',
        );
        self::assertFalse($inline->isAttachment());
        self::assertSame('<logo@x>', $inline->id);

        $text = self::structure('("TEXT" "PLAIN" ("NAME" "notes.txt") NIL NIL "7BIT" 10 1 NIL NIL NIL NIL)');
        self::assertFalse($text->isAttachment(), 'a named text part with no disposition is read inline');
    }

    #[Test]
    public function aBodyStructureWithoutExtensionDataIsRead(): void
    {
        // BODY (not BODYSTRUCTURE) and some servers send no extension fields at all.
        $body = self::structure('(("TEXT" "PLAIN" NIL NIL NIL "7BIT" 5 1)("TEXT" "HTML" NIL NIL NIL "7BIT" 9 1) "ALTERNATIVE")');

        self::assertSame('multipart/alternative', $body->mimeType());
        self::assertSame(['1', '2'], array_map(static fn (BodyPart $p): string => $p->section, $body->leaves()));
        self::assertNull($body->parts[0]->disposition);
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBodyStructureThatDoesNotParseIsAProtocolError(string $line): void
    {
        $this->expectException(ProtocolError::class);
        FetchedItems::fromFetch(Response::parse($line));
    }

    private static function structure(string $structure): BodyPart
    {
        $body = FetchedItems::fromFetch(Response::parse('* 1 FETCH (UID 1 BODYSTRUCTURE ' . $structure . ')'))->bodyStructure;
        self::assertNotNull($body);

        return $body;
    }
}
