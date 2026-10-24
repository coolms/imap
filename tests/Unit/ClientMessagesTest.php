<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\Capability;
use CoolMS\Imap\Value\FetchItem;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\Section;
use CoolMS\Imap\Value\UidSet;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fwrite;
use function str_replace;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/** The commands on a selected folder; each test's script starts after sign-in and SELECT, and so does what it reads. */
final class ClientMessagesTest extends TestCase
{
    private const string SELECTED = "* 9 EXISTS\r\n* OK [UIDVALIDITY 7]\r\n* OK [HIGHESTMODSEQ 100]\r\nTAG OK [READ-WRITE] in\r\n";

    /** @var resource */
    private mixed $server;

    private int $before = 0;
    private string $sent = '';

    #[Test]
    public function searchGivesUidsAndItsModSequenceIsNotOne(): void
    {
        $client = $this->selected('CONDSTORE', "* SEARCH 2 84 882 (MODSEQ 917162500)\r\na2 OK\r\n");

        self::assertSame([2, 84, 882], $client->uidSearch(Argument::atom('UNSEEN')));
        self::assertSame("a2 UID SEARCH UNSEEN\r\n", $this->written());
    }

    #[Test]
    public function changesSinceAModSequenceWithWhatVanished(): void
    {
        $client = $this->selected('ENABLE CONDSTORE QRESYNC', "* VANISHED (EARLIER) 3:5\r\n* 1 FETCH (UID 2 FLAGS (\\Seen) MODSEQ (120))\r\na3 OK\r\n", "* ENABLED CONDSTORE QRESYNC\r\na1 OK\r\n");

        $result = $client->uidFetch(UidSet::fromOnward(1), [FetchItem::flags()], changedSince: 100, vanished: true);

        self::assertSame("a3 UID FETCH 1:* (UID FLAGS) (CHANGEDSINCE 100 VANISHED)\r\n", $this->written());
        self::assertSame('3:5', $result->vanished[0]->uids->toString());
        self::assertTrue($result->vanished[0]->earlier);
        self::assertSame(
            [2, ['\Seen'], 120],
            [$result->messages[0]->uid, $result->messages[0]->flags, $result->messages[0]->modseq],
        );
    }

    #[Test]
    public function aHeaderAndAPartAreAskedAsItemsAndFoundByTheirSection(): void
    {
        $client = $this->selected('', "* 1 FETCH (UID 4 BODY[HEADER.FIELDS (FROM)] {9}\r\nFrom: a\r\n BODY[1]<0> \"hi\")\r\na2 OK\r\n");

        $result = $client->uidFetch(UidSet::of([4]), [
            FetchItem::uid(),
            FetchItem::body(Section::headerFields('from')),
            FetchItem::body(Section::part(1), 0, 2),
        ]);

        self::assertSame("a2 UID FETCH 4 (UID BODY.PEEK[HEADER.FIELDS (FROM)] BODY.PEEK[1]<0.2>)\r\n", $this->written(), 'UID once');
        self::assertSame("From: a\r\n", $result->messages[0]->section(Section::headerFields('From')));
        self::assertSame('hi', $result->messages[0]->section(Section::part(1)));
        self::assertTrue($result->messages[0]->hasSection(Section::part(1)));
        self::assertFalse($result->messages[0]->hasSection(Section::text()));
    }

    #[Test]
    public function gmailsItemsAreFetchedWhereTheServerNamesItsExtension(): void
    {
        $client = $this->selected('X-GM-EXT-1', "* 1 FETCH (UID 4 X-GM-MSGID 77 X-GM-THRID 70 X-GM-LABELS (\\Inbox))\r\na2 OK\r\n");

        $items = [FetchItem::gmailMessageId(), FetchItem::gmailThreadId(), FetchItem::gmailLabels()];
        $result = $client->uidFetch(UidSet::of([4]), $items);

        self::assertSame("a2 UID FETCH 4 (UID X-GM-MSGID X-GM-THRID X-GM-LABELS)\r\n", $this->written());
        self::assertSame('70', $result->messages[0]->gmailThreadId);
    }

    #[Test]
    public function withoutGmailsExtensionNoneOfItsItemsIsAskedNorALabelStored(): void
    {
        $client = $this->selected('MOVE', '');

        foreach ([
            static fn () => $client->uidFetch(UidSet::of([4]), [FetchItem::flags(), FetchItem::gmailLabels()]),
            static fn () => $client->uidStoreLabels(UidSet::of([4]), '+', ['\Starred']),
        ] as $asked) {
            try {
                $asked();
                self::fail('Gmail asked of a server without X-GM-EXT-1');
            } catch (LogicException $refused) {
                self::assertStringContainsString('no X-GM-EXT-1', $refused->getMessage());
            }
        }
        self::assertSame('', $this->written());
    }

    #[Test]
    public function labelsAreStoredSystemOnesAsAtomsFoldersByTheirName(): void
    {
        $client = $this->selected('X-GM-EXT-1', "a2 OK\r\na3 OK\r\n");

        $client->uidStoreLabels(UidSet::parse('4:6'), '+', ['\Starred', FolderName::fromDisplay('Clients & Co')]);
        $client->uidStoreLabels(UidSet::of([4]), '-', ['\Inbox']);

        self::assertSame(
            "a2 UID STORE 4:6 +X-GM-LABELS.SILENT (\\Starred \"Clients &- Co\")\r\n"
            . "a3 UID STORE 4 -X-GM-LABELS.SILENT (\\Inbox)\r\n",
            $this->written(),
        );
    }

    #[Test]
    public function trashAndSpamAreNeverStoredNorALabelGivenAsBareText(): void
    {
        $client = $this->selected('X-GM-EXT-1', '');

        foreach (['\Trash' => 'deletes no mail', '\spam' => 'deletes no mail', 'Work' => 'no system label'] as $label => $why) {
            try {
                $client->uidStoreLabels(UidSet::of([4]), '+', [$label]);
                self::fail($label . ' stored');
            } catch (LogicException $refused) {
                self::assertStringContainsString($why, $refused->getMessage());
            }
        }
        self::assertSame('', $this->written());
    }

    #[Test]
    public function vanishedIsNotAskedWithoutQresync(): void
    {
        $client = $this->selected('CONDSTORE', '');

        $this->expectException(LogicException::class);
        $client->uidFetch(UidSet::fromOnward(1), [FetchItem::flags()], changedSince: 100, vanished: true);
    }

    #[Test]
    public function withoutCondstoreNothingIsAskedByModSequence(): void
    {
        $client = $this->selected('MOVE', '');

        foreach ([
            static fn () => $client->uidFetch(UidSet::fromOnward(1), [FetchItem::flags()], changedSince: 1),
            static fn () => $client->uidStore(UidSet::of([1]), '+', ['\Seen'], unchangedSince: 1),
        ] as $asked) {
            try {
                $asked();
                self::fail('asked by mod-sequence without CONDSTORE');
            } catch (LogicException $refused) {
                self::assertStringContainsString('no CONDSTORE', $refused->getMessage());
            }
        }
        self::assertSame('', $this->written());
    }

    #[Test]
    public function aConditionalStoreTellsWhatItDidNotChange(): void
    {
        $client = $this->selected('CONDSTORE', "* 5 FETCH (UID 5 MODSEQ (320162350))\r\na2 OK [MODIFIED 7,9] Conditional STORE failed\r\n");

        $result = $client->uidStore(UidSet::parse('5:9'), '+', ['\Seen'], unchangedSince: 320162338);

        self::assertSame("a2 UID STORE 5:9 (UNCHANGEDSINCE 320162338) +FLAGS.SILENT (\\Seen)\r\n", $this->written());
        self::assertSame('7,9', $result->notChanged?->toString());
        self::assertSame(5, $result->reported[0]->uid);
    }

    #[Test]
    public function anUnconditionalStoreChangedEverything(): void
    {
        $client = $this->selected('', "a2 OK\r\n");

        self::assertNull($client->uidStore(UidSet::of([4]), '-', ['\Seen'])->notChanged);
        self::assertSame("a2 UID STORE 4 -FLAGS.SILENT (\\Seen)\r\n", $this->written());
    }

    #[Test]
    public function deletedIsNeverStored(): void
    {
        $client = $this->selected('', '');

        try {
            $client->uidStore(UidSet::of([4]), '+', ['\Seen', '\deleted']);
            self::fail('\Deleted stored');
        } catch (LogicException $refused) {
            self::assertStringContainsString('does not delete mail', $refused->getMessage());
            self::assertSame('', $this->written());
        }
    }

    #[Test]
    public function aMoveSaysWhereTheMessagesWent(): void
    {
        // RFC 6851 s.4.3's example: COPYUID in an untagged OK, before the expunges.
        $client = $this->selected('MOVE', "* OK [COPYUID 432432 42:43 10:11] Moved\r\n* 2 EXPUNGE\r\n* 1 EXPUNGE\r\na2 OK Done\r\n");

        $copied = $client->uidMove(UidSet::parse('42:43'), FolderName::fromDisplay('Архив'));

        self::assertSame("a2 UID MOVE 42:43 \"&BBAEQARFBDgEMg-\"\r\n", $this->written());
        self::assertSame(432432, $copied?->uidValidity);
        self::assertSame([42 => 10, 43 => 11], $copied->pairs(2));
    }

    #[Test]
    public function copyUidNamingMoreThanWasMovedIsRefused(): void
    {
        $client = $this->selected('MOVE', "a2 OK [COPYUID 1 1:1000000 1:1000000] Done\r\n");

        $copied = $client->uidMove(UidSet::of([1]), FolderName::fromDisplay('A'));

        $this->expectException(ProtocolError::class);
        $copied?->pairs(1);
    }

    #[Test]
    public function withoutMoveNothingMoves(): void
    {
        $client = $this->selected('', '');

        $this->expectException(LogicException::class);
        $client->uidMove(UidSet::of([1]), FolderName::fromDisplay('A'));
    }

    #[Test]
    public function nothingOnMessagesBeforeAFolderIsSelected(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, "* PREAUTH [CAPABILITY IMAP4rev1] hi\r\n");
        $imap = Client::open(new Endpoint('x', 993, Security::Tls), new Opener(static fn (): mixed => $client));

        $this->expectException(LogicException::class);
        $imap->uidSearch(Argument::atom('ALL'));
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            fclose($this->server);
        }
    }

    private function selected(string $capabilities, string $then, string $enable = ''): Client
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $this->server] = $pair;
        $select = str_replace('TAG', '' === $enable ? 'a1' : 'a2', self::SELECTED);
        fwrite(
            $this->server,
            '* PREAUTH [CAPABILITY IMAP4rev1 ' . $capabilities . "] hi\r\n" . $enable . $select . $then,
        );
        $imap = Client::open(
            new Endpoint('x', 993, Security::Tls, readTimeout: 1.0),
            new Opener(static fn (): mixed => $client),
        );
        if ('' !== $enable) {
            $imap->enable(Capability::Condstore, Capability::Qresync);
        }
        $imap->select(FolderName::fromWire('INBOX'));
        $this->before = strlen($this->allWritten());

        return $imap;
    }

    private function allWritten(): string
    {
        stream_set_blocking($this->server, false);
        $this->sent .= (string) stream_get_contents($this->server);

        return $this->sent;
    }

    private function written(): string
    {
        return substr($this->allWritten(), $this->before);
    }
}
