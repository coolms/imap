<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Protocol;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\ResponseKind;
use CoolMS\Imap\Protocol\Token;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

final class ResponseTest extends TestCase
{
    #[Test]
    public function aTaggedCompletionWithACodeAndFreeText(): void
    {
        $r = Response::parse('a7 OK [COPYUID 38505 304 3956] Moved, "really" (done) [sic');

        self::assertSame(ResponseKind::Tagged, $r->kind);
        self::assertSame('a7', $r->tag);
        self::assertSame('OK', $r->status);
        self::assertSame(['COPYUID', '38505', '304', '3956'], self::texts($r->code?->items() ?? []));
        self::assertSame('Moved, "really" (done) [sic', $r->text, 'free text is kept, never tokenized');
    }

    #[Test]
    public function anUntaggedStatusWithAListInsideItsCode(): void
    {
        $r = Response::parse('* OK [PERMANENTFLAGS (\Deleted \Seen \*)] Limited');

        self::assertSame(ResponseKind::UntaggedStatus, $r->kind);
        self::assertNull($r->tag);
        $items = $r->code?->items() ?? [];
        self::assertSame('PERMANENTFLAGS', $items[0]->text());
        self::assertSame(['\Deleted', '\Seen', '\*'], self::texts($items[1]->items()));
        self::assertSame('Limited', $r->text);
    }

    #[Test]
    public function aNoWithoutACodeAndAByeInLowerCase(): void
    {
        $no = Response::parse('a8 NO Mailbox does not exist');
        self::assertNull($no->code);
        self::assertSame('NO', $no->status);
        self::assertSame('Mailbox does not exist', $no->text);

        self::assertSame('BYE', Response::parse('* bye Autologout')->status);
    }

    #[Test]
    public function untaggedDataIsTokenized(): void
    {
        $r = Response::parse('* 23 EXISTS');

        self::assertSame(ResponseKind::UntaggedData, $r->kind);
        self::assertSame(23, $r->data[0]->value);
        self::assertSame('EXISTS', $r->data[1]->text());
    }

    #[Test]
    public function aContinuationKeepsItsText(): void
    {
        $r = Response::parse('+ idling');

        self::assertSame(ResponseKind::Continuation, $r->kind);
        self::assertSame('idling', $r->text);
        self::assertSame('', Response::parse('+')->text);
    }

    #[Test]
    public function aTaggedResponseWithNoStatusIsAProtocolError(): void
    {
        $this->expectException(ProtocolError::class);
        $this->expectExceptionMessage('carries no OK, NO or BAD');
        Response::parse('a9 FETCH (UID 1)');
    }

    #[Test]
    public function aCodeNeverClosedIsAProtocolError(): void
    {
        $this->expectException(ProtocolError::class);
        $this->expectExceptionMessage('never closed');
        Response::parse('* OK [UIDNEXT 4392 predicted next UID');
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
