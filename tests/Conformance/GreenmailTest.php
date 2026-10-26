<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Conformance;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\FolderNotEmpty;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\Capability;
use CoolMS\Imap\Value\FetchItem;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\UidSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function getenv;
use function random_bytes;

/** Against GreenMail (compose.yaml): the plain IMAP server -- no CONDSTORE, no SPECIAL-USE -- that a sync must also handle. */
final class GreenmailTest extends TestCase
{
    #[Test]
    public function withoutCondstoreThereIsNoModSequenceAndNothingToEnable(): void
    {
        $client = $this->signedIn();

        self::assertSame([], $client->enable(Capability::Condstore, Capability::Qresync));
        self::assertNull($client->select(FolderName::fromWire('INBOX'))->highestModseq);
    }

    #[Test]
    public function aMessageComesBackByteForByteAndAFlagSticks(): void
    {
        $client = $this->signedIn();
        $message = "From: a@x.test\r\nSubject: hi\r\n\r\nbody\r\n";

        $uid = $client->append(FolderName::fromWire('INBOX'), $message);
        $client->select(FolderName::fromWire('INBOX'));
        $client->uidStore(UidSet::of([(int) $uid]), '+', ['\Seen']);
        $fetched = $client->uidFetch(UidSet::of([(int) $uid]), [FetchItem::flags(), FetchItem::body()])->messages;

        self::assertNotNull($uid, 'greenmail has UIDPLUS');
        self::assertSame($message, $fetched[0]->section());
        self::assertContains('\Seen', $fetched[0]->flags ?? []);
    }

    #[Test]
    public function theOnlyFolderIsInbox(): void
    {
        $folders = $this->signedIn()->folders();

        self::assertCount(1, $folders);
        self::assertSame('INBOX', $folders[0]->name->wire);
        self::assertSame('.', $folders[0]->delimiter, 'greenmail\'s delimiter is not Dovecot\'s');
    }

    #[Test]
    public function aFolderIsCreatedRenamedAndDeletedWhenEmptyButNotWithMail(): void
    {
        $client = $this->signedIn();
        $client->create(FolderName::fromWire('Projects'));
        $client->rename(FolderName::fromWire('Projects'), FolderName::fromWire('Projects 2026'));
        $client->append(FolderName::fromWire('Projects 2026'), "Subject: kept\r\n\r\nbody\r\n");

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects 2026'));
            self::fail('a folder holding mail is not deleted');
        } catch (FolderNotEmpty $notEmpty) {
            self::assertSame(1, $notEmpty->messages);
        }
        self::assertSame(1, $client->counts(FolderName::fromWire('Projects 2026'))->messages, 'put back with its mail');

        $client->create(FolderName::fromWire('Empty'));
        $client->deleteEmpty(FolderName::fromWire('Empty'));
        $names = array_map(static fn ($folder) => $folder->name->wire, $client->folders());
        self::assertNotContains('Empty', $names);
        self::assertContains('Projects 2026', $names);
    }

    private function signedIn(): Client
    {
        $client = Client::open(new Endpoint(
            getenv('GREENMAIL_TEST_HOST') ?: '127.0.0.1',
            (int) (getenv('GREENMAIL_TEST_PORT') ?: 43143),
            Security::PlainForTestsOnly,
            readTimeout: 10.0,
        ));
        $client->login('conformance-' . bin2hex(random_bytes(4)) . '@x.test', 'x');

        return $client;
    }
}
