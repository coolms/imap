<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Conformance;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\CouldNotOpen;
use CoolMS\Imap\Exception\FolderNotEmpty;
use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\Capability;
use CoolMS\Imap\Value\FetchItem;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\SpecialUse;
use CoolMS\Imap\Value\UidSet;
use CoolMS\Imap\Watch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function getenv;
use function ksort;
use function random_bytes;
use function sort;
use function strlen;

/**
 * Against Dovecot 2.4 (compose.yaml): CONDSTORE, QRESYNC, SPECIAL-USE, MOVE, IDLE. Each test signs
 * in as a user of its own, so what one test leaves is never what another reads.
 */
final class DovecotTest extends TestCase
{
    private string $user;

    #[Test]
    public function signedInItOffersWhatTheSyncNeeds(): void
    {
        $client = $this->signedIn();

        foreach (['CONDSTORE', 'QRESYNC', 'SPECIAL-USE', 'LIST-EXTENDED', 'MOVE', 'IDLE', 'UIDPLUS'] as $extension) {
            self::assertTrue($client->capabilities()->has($extension), $extension);
        }
        self::assertSame([Capability::Condstore, Capability::Qresync], $client->enable(Capability::Condstore, Capability::Qresync));
    }

    #[Test]
    public function everyFolderHasItsRole(): void
    {
        $roles = array_map(static fn ($folder) => $folder->role?->value, $this->signedIn()->folders());
        sort($roles);

        self::assertSame(['drafts', 'inbox', 'junk', 'sent', 'trash'], $roles);
    }

    #[Test]
    public function anAppendedMessageComesBackByteForByte(): void
    {
        $client = $this->signedIn();
        $client->enable(Capability::Condstore);
        $message = "From: a@x.test\r\nSubject: =?UTF-8?B?0J/RgNC40LLQtdGC?=\r\n\r\nline\r\n.\r\n* 9 EXISTS\r\nend\r\n";

        $uid = $client->append(FolderName::fromWire('INBOX'), $message, ['\Seen']);
        $client->select(FolderName::fromWire('INBOX'));
        $fetched = $client->uidFetch(
            UidSet::of([(int) $uid]),
            [FetchItem::flags(), FetchItem::size(), FetchItem::body(), FetchItem::modseq()],
        )->messages;

        self::assertCount(1, $fetched);
        self::assertSame($uid, $fetched[0]->uid);
        self::assertSame($message, $fetched[0]->section(), 'a line inside the body that looks like a response is the body\'s');
        self::assertSame(strlen($message), $fetched[0]->size);
        self::assertSame(['\Seen'], $fetched[0]->flags);
        self::assertGreaterThan(0, $fetched[0]->modseq);
    }

    #[Test]
    public function whatChangedAndWhatVanishedSinceAModSequence(): void
    {
        $client = $this->signedIn();
        $client->enable(Capability::Condstore, Capability::Qresync);
        $kept = (int) $client->append(FolderName::fromWire('INBOX'), "Subject: kept\r\n\r\nx");
        $moved = (int) $client->append(FolderName::fromWire('INBOX'), "Subject: moved\r\n\r\nx");
        $since = (int) $client->select(FolderName::fromWire('INBOX'))->highestModseq;

        $client->uidStore(UidSet::of([$kept]), '+', ['\Flagged']);
        $copied = $client->uidMove(UidSet::of([$moved]), FolderName::fromWire('Trash'));
        $changes = $client->uidFetch(UidSet::fromOnward(1), [FetchItem::flags()], changedSince: $since, vanished: true);

        self::assertNotNull($copied, 'Dovecot gives COPYUID');
        self::assertSame([$moved], array_keys($copied->pairs(1)));
        self::assertSame([$kept], array_map(static fn ($m) => $m->uid, $changes->messages));
        self::assertContains('\Flagged', $changes->messages[0]->flags ?? []);
        self::assertCount(1, $changes->vanished);
        self::assertSame((string) $moved, $changes->vanished[0]->uids->toString());
        self::assertTrue($changes->vanished[0]->earlier);
    }

    #[Test]
    public function aStoreOverAChangeItHasNotSeenIsNotMade(): void
    {
        $first = $this->signedIn();
        $first->enable(Capability::Condstore);
        $uid = (int) $first->append(FolderName::fromWire('INBOX'), "Subject: x\r\n\r\nx");
        $first->select(FolderName::fromWire('INBOX'));
        $seenAt = (int) $first->uidFetch(UidSet::of([$uid]), [FetchItem::modseq()])->messages[0]->modseq;

        $other = $this->signedIn();
        $other->select(FolderName::fromWire('INBOX'));
        $other->uidStore(UidSet::of([$uid]), '+', ['\Seen']);

        $result = $first->uidStore(UidSet::of([$uid]), '+', ['\Flagged'], unchangedSince: $seenAt);

        self::assertSame((string) $uid, $result->notChanged?->toString(), 'another client changed it first');
        $flags = $first->uidFetch(UidSet::of([$uid]), [FetchItem::flags()])->messages[0]->flags ?? [];
        self::assertNotContains('\Flagged', $flags, 'and the store was not made');
    }

    #[Test]
    public function idleHearsWhatAnotherClientDid(): void
    {
        $listener = $this->signedIn();
        $listener->select(FolderName::fromWire('INBOX'));
        $this->signedIn()->append(FolderName::fromWire('INBOX'), "Subject: new\r\n\r\nx");
        $heard = [];

        $listener->idle(static function (Response $response) use (&$heard): bool {
            $heard[] = $response->data[1]->value ?? null;

            return 'EXISTS' !== ($response->data[1]->value ?? null);
        }, 10.0);

        self::assertContains('EXISTS', $heard);
    }

    #[Test]
    public function oneWatchHearsTwoFoldersIdlingOnTwoConnections(): void
    {
        $sent = null;
        foreach ($this->signedIn()->folders() as $folder) {
            if (SpecialUse::Sent === $folder->role) {
                $sent = $folder->name;
            }
        }
        self::assertNotNull($sent, 'the account has a \Sent folder');
        $inbox = $this->signedIn();
        $inbox->select(FolderName::fromWire('INBOX'));
        $outbox = $this->signedIn();
        $outbox->select($sent);
        $watch = new Watch();
        $watch->add('inbox', $inbox);
        $watch->add('sent', $outbox);

        $writer = $this->signedIn();
        $writer->append(FolderName::fromWire('INBOX'), "Subject: to inbox\r\n\r\nx");
        $writer->append($sent, "Subject: to sent\r\n\r\nx");
        $heard = [];
        for ($i = 0; $i < 10 && 2 > count($heard); ++$i) {
            foreach ($watch->wait(2.0) as $key => $responses) {
                foreach ($responses as $response) {
                    if ('EXISTS' === ($response->data[1]->value ?? null)) {
                        $heard[$key] = true;
                    }
                }
            }
        }
        $watch->end();
        // Two connections: which one's EXISTS comes first is the servers', not the order they were added in.
        ksort($heard);

        self::assertSame(['inbox' => true, 'sent' => true], $heard, 'each folder\'s EXISTS, through one wait');
        self::assertSame([], $watch->failures());
    }

    #[Test]
    public function aCertificateThatCannotBeVerifiedIsRefused(): void
    {
        // Dovecot's certificate is its own, self-signed: with no CA file given, verification must fail.
        try {
            Client::open(new Endpoint(self::host(), self::port(), Security::StartTls, readTimeout: 10.0));
            self::fail('a certificate nobody vouches for was accepted');
        } catch (CouldNotOpen $refused) {
            self::assertStringContainsString('TLS handshake after STARTTLS failed', $refused->getMessage());
        }
    }

    #[Test]
    public function withItsCertificateTrustedStartTlsAndTlsOpenVerifiedAgainstThePeerName(): void
    {
        // The socket goes to an address; the certificate names "localhost" -- the case of a pinned address.
        foreach ([[self::port(), Security::StartTls], [self::tlsPort(), Security::Tls]] as [$port, $security]) {
            $client = Client::open(new Endpoint(
                self::host(),
                $port,
                $security,
                readTimeout: 10.0,
                caFile: self::ca(),
                peerName: 'localhost',
            ));
            $client->login($this->user, self::password());

            self::assertTrue($client->capabilities()->has('CONDSTORE'), $security->name . ': signed in over TLS');
            $client->logout();
        }
    }

    #[Test]
    public function controlTheSameCertificateForAnotherNameIsRefused(): void
    {
        $cases = [
            [self::port(), Security::StartTls, 'TLS handshake after STARTTLS failed'],
            [self::tlsPort(), Security::Tls, 'could not be reached over TLS'],
        ];
        foreach ($cases as [$port, $security, $reason]) {
            try {
                Client::open(new Endpoint(
                    self::host(),
                    $port,
                    $security,
                    readTimeout: 10.0,
                    caFile: self::ca(),
                    peerName: 'imap.example.com',
                ));
                self::fail($security->name . ': a certificate for "localhost" accepted for "imap.example.com"');
            } catch (CouldNotOpen $refused) {
                self::assertStringContainsString($reason, $refused->getMessage());
            }
        }
    }

    #[Test]
    public function aFolderIsCreatedRenamedAndDeletedWhenEmpty(): void
    {
        $client = $this->signedIn();

        $client->create(FolderName::fromDisplay('Отчёты'));
        $client->subscribe(FolderName::fromDisplay('Отчёты'));
        $client->rename(FolderName::fromDisplay('Отчёты'), FolderName::fromDisplay('Отчёты 2026'));
        self::assertContains('Отчёты 2026', self::names($client));
        self::assertNotContains('Отчёты', self::names($client));

        $client->deleteEmpty(FolderName::fromDisplay('Отчёты 2026'));

        self::assertNotContains('Отчёты 2026', self::names($client));
    }

    #[Test]
    public function aFolderHoldingMailIsPutBackWithItsMail(): void
    {
        $client = $this->signedIn();
        $client->create(FolderName::fromWire('Kept'));
        $client->append(FolderName::fromWire('Kept'), "Subject: kept\r\n\r\nbody\r\n");

        try {
            $client->deleteEmpty(FolderName::fromWire('Kept'));
            self::fail('a folder holding mail is not deleted');
        } catch (FolderNotEmpty $notEmpty) {
            self::assertSame(1, $notEmpty->messages);
            self::assertNull($notEmpty->leftAt);
        }

        self::assertSame(1, $client->counts(FolderName::fromWire('Kept'))->messages);
        self::assertSame(['Drafts', 'INBOX', 'Junk', 'Kept', 'Sent', 'Trash'], self::sortedNames($client), 'nothing left aside');
    }

    #[Test]
    public function aUidExpungeRemovesTheNamedMessageAndNoOther(): void
    {
        $client = $this->signedIn();
        $gone = (int) $client->append(FolderName::fromWire('INBOX'), "Subject: run's own\r\n\r\nx");
        $kept = (int) $client->append(FolderName::fromWire('INBOX'), "Subject: someone else's\r\n\r\nx");
        $client->select(FolderName::fromWire('INBOX'));

        $client->uidExpunge(UidSet::of([$gone]));

        self::assertSame([$kept], $client->uidSearch(Argument::atom('ALL')));
    }

    #[Test]
    public function aPlainDeleteRemovesAFolderWithWhatItHolds(): void
    {
        $client = $this->signedIn();
        $client->create(FolderName::fromWire('Run'));
        $client->append(FolderName::fromWire('Run'), "Subject: the run's own\r\n\r\nx");

        $client->delete(FolderName::fromWire('Run'));

        self::assertNotContains('Run', self::names($client));
    }

    /** The measurement for deleting into Trash: a folder, with its mail, can be moved under Trash and back. */
    #[Test]
    public function aFolderWithItsMailGoesUnderTrashAndComesBack(): void
    {
        $client = $this->signedIn();
        $client->create(FolderName::fromWire('Project'));
        $client->append(FolderName::fromWire('Project'), "Subject: in the project\r\n\r\nbody\r\n");

        $trash = array_values(array_filter($client->folders(), static fn ($folder) => SpecialUse::Trash === $folder->role))[0];
        $under = FolderName::fromWire($trash->name->wire . $trash->delimiter . 'Project');

        $client->rename(FolderName::fromWire('Project'), $under);
        self::assertSame(1, $client->counts($under)->messages);
        $client->rename($under, FolderName::fromWire('Project'));

        self::assertSame(1, $client->counts(FolderName::fromWire('Project'))->messages);
    }

    protected function setUp(): void
    {
        $this->user = 'conformance-' . bin2hex(random_bytes(4));
    }

    /** @return list<string> */
    private static function names(Client $client): array
    {
        return array_map(static fn ($folder) => $folder->name->display, $client->folders());
    }

    /** @return list<string> */
    private static function sortedNames(Client $client): array
    {
        $names = self::names($client);
        sort($names);

        return $names;
    }

    private static function ca(): string
    {
        $ca = (string) getenv('DOVECOT_TEST_CA');
        self::assertFileExists($ca, 'DOVECOT_TEST_CA names Dovecot\'s certificate (run.sh copies it)');

        return $ca;
    }

    private static function host(): string
    {
        return getenv('DOVECOT_TEST_HOST') ?: '127.0.0.1';
    }

    private static function port(): int
    {
        return (int) (getenv('DOVECOT_TEST_PORT') ?: 41143);
    }

    private static function tlsPort(): int
    {
        return (int) (getenv('DOVECOT_TEST_TLS_PORT') ?: 41993);
    }

    /** compose.yaml's fixture password, public on purpose. */
    private static function password(): string
    {
        return getenv('DOVECOT_TEST_PASSWORD') ?: 'conformance';
    }

    private function signedIn(): Client
    {
        $client = Client::open(new Endpoint(self::host(), self::port(), Security::PlainForTestsOnly, readTimeout: 10.0));
        $client->login($this->user, self::password());

        return $client;
    }
}
