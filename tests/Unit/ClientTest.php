<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\CommandFailed;
use CoolMS\Imap\Sasl\XOAuth2;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Value\SpecialUse;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function fclose;
use function fwrite;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ClientTest extends TestCase
{
    private const string GREETING = "* OK [CAPABILITY IMAP4rev1 SASL-IR AUTH=PLAIN AUTH=XOAUTH2] ready\r\n";

    /** @var resource */
    private mixed $server;

    #[Test]
    public function signingInReadsTheCapabilitiesAgainFromTheCompletion(): void
    {
        $client = $this->client(self::GREETING . "a1 OK [CAPABILITY IMAP4rev1 ENABLE CONDSTORE QRESYNC SPECIAL-USE LIST-EXTENDED] signed in\r\n");

        $client->login('me@x.test', 'pw');

        self::assertTrue($client->capabilities()->has('QRESYNC'), 'what the server offers once it knows who asks');
        self::assertSame("a1 LOGIN \"me@x.test\" \"pw\"\r\n", $this->written(), 'no CAPABILITY asked: the code gave them');
    }

    #[Test]
    public function withoutTheCodeTheyAreAsked(): void
    {
        $client = $this->client(self::GREETING . "a1 OK signed in\r\n* CAPABILITY IMAP4rev1 IDLE\r\na2 OK\r\n");

        $client->login('me', 'pw');

        self::assertTrue($client->capabilities()->has('IDLE'));
        self::assertFalse($client->capabilities()->has('SASL-IR'), 'the pre-sign-in list is replaced, not added to');
        self::assertSame("a1 LOGIN \"me\" \"pw\"\r\na2 CAPABILITY\r\n", $this->written());
    }

    #[Test]
    public function aRefusedSignInNamesItsCodeAndNeverThePassword(): void
    {
        $client = $this->client(self::GREETING . "a1 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)\r\n");

        try {
            $client->login('me', 's3cret-pw');
            self::fail('a NO is never a session');
        } catch (CommandFailed $failed) {
            self::assertSame('AUTHENTICATIONFAILED', $failed->code());
            self::assertSame('LOGIN: NO Invalid credentials (Failure)', $failed->getMessage());
            self::assertFalse(str_contains($failed->getMessage(), 's3cret'));
        }
    }

    #[Test]
    public function loginDisabledIsNeverTried(): void
    {
        $client = $this->client("* OK [CAPABILITY IMAP4rev1 LOGINDISABLED AUTH=XOAUTH2] ready\r\n");

        try {
            $client->login('me', 'pw');
            self::fail('LOGIN where it is disabled');
        } catch (LogicException) {
            self::assertSame('', $this->written(), 'the password never left');
        }
    }

    #[Test]
    public function aMechanismTheServerDoesNotOfferIsNeverTried(): void
    {
        $client = $this->client("* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready\r\n");

        $this->expectException(LogicException::class);
        $client->authenticate(new XOAuth2('me', 'token'));
    }

    #[Test]
    public function xoauth2SignsIn(): void
    {
        $client = $this->client(self::GREETING . "a1 OK [CAPABILITY IMAP4rev1] ok\r\n");

        $client->authenticate(new XOAuth2('me', 'token'));

        self::assertStringStartsWith('a1 AUTHENTICATE XOAUTH2 ', $this->written());
    }

    #[Test]
    public function nothingButSigningInBeforeSigningIn(): void
    {
        $client = $this->client(self::GREETING);

        $this->expectException(LogicException::class);
        $client->folders();
    }

    #[Test]
    public function foldersAskForTheirRolesWhereTheServerCanGiveThem(): void
    {
        $client = $this->signedIn('SPECIAL-USE LIST-EXTENDED', '* LIST (\HasNoChildren) "/" "INBOX"' . "\r\n"
            . '* LIST (\HasNoChildren \Sent) "/" "&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-"' . "\r\na2 OK\r\n");

        $folders = $client->folders();

        self::assertSame([SpecialUse::Inbox, SpecialUse::Sent], array_map(static fn ($f) => $f->role, $folders));
        self::assertSame('Отправленные', $folders[1]->name->display);
        self::assertStringEndsWith("a2 LIST \"\" \"*\" RETURN (SPECIAL-USE)\r\n", $this->written());
    }

    #[Test]
    public function withoutListExtendedItIsAPlainList(): void
    {
        $client = $this->signedIn('SPECIAL-USE', "a2 OK\r\n");

        self::assertSame([], $client->folders());
        self::assertStringEndsWith("a2 LIST \"\" \"*\"\r\n", $this->written());
    }

    #[Test]
    public function onlyWhatTheServerOffersIsEnabled(): void
    {
        $client = $this->signedIn('ENABLE CONDSTORE', "* ENABLED CONDSTORE\r\na2 OK\r\n");

        self::assertSame(['CONDSTORE'], $client->enable('CONDSTORE', 'QRESYNC'));
        self::assertTrue($client->enabled('condstore'));
        self::assertStringEndsWith("a2 ENABLE CONDSTORE\r\n", $this->written(), 'QRESYNC, not offered, not asked');
    }

    #[Test]
    public function withNothingToEnableNothingIsSent(): void
    {
        $client = $this->signedIn('ENABLE', '');

        self::assertSame([], $client->enable('QRESYNC'));
        self::assertSame("a1 LOGIN \"me\" \"pw\"\r\n", $this->written());
    }

    #[Test]
    public function countsAskForTheModSequenceWhereThereIsOne(): void
    {
        $client = $this->signedIn('CONDSTORE', "* STATUS INBOX (MESSAGES 3 UNSEEN 1 UIDNEXT 9 UIDVALIDITY 7 HIGHESTMODSEQ 40)\r\na2 OK\r\n");

        self::assertSame(40, $client->counts(FolderName::fromWire('INBOX'))->highestModseq);
        self::assertStringEndsWith(
            "a2 STATUS \"INBOX\" (MESSAGES UNSEEN UIDNEXT UIDVALIDITY HIGHESTMODSEQ)\r\n",
            $this->written(),
        );
    }

    #[Test]
    public function aSelectedFolderAndAFolderThatIsNotThere(): void
    {
        $client = $this->signedIn('', "* 2 EXISTS\r\n* OK [UIDVALIDITY 7]\r\na2 OK [READ-WRITE] ok\r\na3 NO [NONEXISTENT] Unknown\r\n");

        self::assertSame(2, $client->select(FolderName::fromWire('INBOX'))->exists);
        self::assertNotNull($client->selected());
        try {
            $client->select(FolderName::fromDisplay('Gone'), readOnly: true);
            self::fail('a folder that is not there is never selected');
        } catch (CommandFailed $failed) {
            self::assertSame('NONEXISTENT', $failed->code());
            self::assertSame('EXAMINE "Gone": NO Unknown', $failed->getMessage());
            self::assertNull($client->selected(), 'a failed SELECT leaves nothing selected (RFC 9051 s.6.3.2)');
        }
    }

    #[Test]
    public function anAppendedMessageSaysTheUidItGot(): void
    {
        // RFC 4315 s.3's example.
        $client = $this->signedIn('UIDPLUS', "+ go\r\na2 OK [APPENDUID 38505 3955] APPEND completed\r\n");

        self::assertSame(3955, $client->append(FolderName::fromWire('Sent'), "Subject: x\r\n\r\nhi", ['\Seen']));
        self::assertStringEndsWith("a2 APPEND \"Sent\" (\\Seen) {16}\r\nSubject: x\r\n\r\nhi\r\n", $this->written());
    }

    #[Test]
    public function anAppendWithoutUidplusSaysNothing(): void
    {
        self::assertNull($this->signedIn('', "+ go\r\na2 OK done\r\n")->append(FolderName::fromWire('Sent'), 'x'));
    }

    #[Test]
    public function anAppendIsNeverMarkedDeleted(): void
    {
        $client = $this->signedIn('', '');

        $this->expectException(LogicException::class);
        $client->append(FolderName::fromWire('Sent'), 'x', ['\Deleted']);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            fclose($this->server);
        }
    }

    private function signedIn(string $capabilities, string $then): Client
    {
        $client = $this->client(self::GREETING . 'a1 OK [CAPABILITY IMAP4rev1 ' . $capabilities . "] in\r\n" . $then);
        $client->login('me', 'pw');

        return $client;
    }

    private function client(string $script): Client
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, $script);

        return Client::open(
            new Endpoint('imap.x.test', 993, Security::Tls, readTimeout: 1.0),
            new Opener(static fn (): mixed => $client),
        );
    }

    private function written(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }
}
