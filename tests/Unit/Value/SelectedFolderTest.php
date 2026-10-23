<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\Reply;
use CoolMS\Imap\Value\SelectedFolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_pop;
use function array_values;

use const PHP_INT_MAX;

final class SelectedFolderTest extends TestCase
{
    #[Test]
    public function aCondstoreSelect(): void
    {
        // RFC 7162 s.3.1.2.1's example, with Dovecot's FLAGS and PERMANENTFLAGS.
        $folder = SelectedFolder::fromReply(self::reply(
            '* 172 EXISTS',
            '* 1 RECENT',
            '* OK [UNSEEN 12] Message 12 is first unseen',
            '* OK [UIDVALIDITY 3857529045] UIDs valid',
            '* OK [UIDNEXT 4392] Predicted next UID',
            '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)',
            '* OK [PERMANENTFLAGS (\Deleted \Seen \*)] Limited',
            '* OK [HIGHESTMODSEQ 715194045007] Highest',
            'a1 OK [READ-WRITE] SELECT completed',
        ));

        self::assertSame(172, $folder->exists);
        self::assertSame(3857529045, $folder->uidValidity);
        self::assertSame(4392, $folder->uidNext);
        self::assertSame(715194045007, $folder->highestModseq);
        self::assertSame(['\Answered', '\Flagged', '\Deleted', '\Seen', '\Draft'], $folder->flags);
        self::assertSame(['\Deleted', '\Seen', '\*'], $folder->permanentFlags);
        self::assertFalse($folder->readOnly);
    }

    #[Test]
    public function aServerWithoutCondstoreHasNoModseqAndAnExamineIsReadOnly(): void
    {
        // Greenmail: no HIGHESTMODSEQ at all.
        $folder = SelectedFolder::fromReply(self::reply(
            '* FLAGS (\Answered \Deleted \Draft \Flagged \Seen)',
            '* 3 EXISTS',
            '* OK [UIDVALIDITY 1792490770]',
            'a1 OK [READ-ONLY] EXAMINE completed',
        ));

        self::assertNull($folder->highestModseq);
        self::assertNull($folder->uidNext);
        self::assertSame([], $folder->permanentFlags);
        self::assertTrue($folder->readOnly);
    }

    #[Test]
    public function noModseqIsNoModseqWhateverElseWasSaid(): void
    {
        $folder = SelectedFolder::fromReply(self::reply('* 0 EXISTS', '* OK [UIDVALIDITY 7]', '* OK [HIGHESTMODSEQ 5]', '* OK [NOMODSEQ] Sorry', 'a1 OK done'));

        self::assertNull($folder->highestModseq);
        self::assertSame(0, $folder->exists, 'an empty folder');
    }

    #[Test]
    public function theLargestModseqIsANumber(): void
    {
        $folder = SelectedFolder::fromReply(self::reply('* 1 EXISTS', '* OK [UIDVALIDITY 7]', '* OK [HIGHESTMODSEQ 9223372036854775807]', 'a1 OK'));

        self::assertSame(PHP_INT_MAX, $folder->highestModseq);
    }

    #[Test]
    public function withoutUidValidityNoUidCanBeTrusted(): void
    {
        $this->expectException(ProtocolError::class);
        $this->expectExceptionMessage('no UIDVALIDITY');
        SelectedFolder::fromReply(self::reply('* 3 EXISTS', '* OK [UIDNEXT 4]', 'a1 OK'));
    }

    #[Test]
    public function aUidValidityThatIsNotANumberIsNone(): void
    {
        $this->expectException(ProtocolError::class);
        SelectedFolder::fromReply(self::reply('* 3 EXISTS', '* OK [UIDVALIDITY x]', 'a1 OK'));
    }

    #[Test]
    public function aRefusedSelectSelectedNothing(): void
    {
        $this->expectException(ProtocolError::class);
        SelectedFolder::fromReply(self::reply('a1 NO [NONEXISTENT] Unknown folder'));
    }

    private static function reply(string ...$lines): Reply
    {
        $responses = array_map(Response::parse(...), $lines);
        $completion = array_pop($responses);
        self::assertNotNull($completion);

        return new Reply($completion, array_values($responses));
    }
}
