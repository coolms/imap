<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Conformance;

use CoolMS\Imap\Client;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\UidSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        self::assertSame([], $client->enable('CONDSTORE', 'QRESYNC'));
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
        $fetched = $client->uidFetch(UidSet::of([(int) $uid]), ['FLAGS', 'BODY.PEEK[]'])->messages;

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
