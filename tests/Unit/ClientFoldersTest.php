<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\CommandFailed;
use CoolMS\Imap\Exception\FolderLeftAside;
use CoolMS\Imap\Exception\FolderNotEmpty;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Folders made, renamed, subscribed to and removed. Removing one never takes mail with it: the folder is first renamed
 * to a name only this client knows, so nothing can be put in it, and it is deleted only when it is found empty there.
 */
final class ClientFoldersTest extends TestCase
{
    private const string GREETING = "* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready\r\na1 OK [CAPABILITY IMAP4rev1] signed in\r\n";

    /** @var resource */
    private mixed $server;

    #[Test]
    public function aFolderIsCreatedUnderItsEncodedName(): void
    {
        $client = $this->signedIn("a2 OK created\r\n");

        $client->create(FolderName::fromDisplay('Отчёты'));

        self::assertSame("a2 CREATE \"&BB4EQgRHBFEEQgRL-\"\r\n", $this->afterSignIn());
    }

    #[Test]
    public function aFolderIsRenamed(): void
    {
        $client = $this->signedIn("a2 OK renamed\r\n");

        $client->rename(FolderName::fromWire('Projects/Old'), FolderName::fromWire('Projects/New'));

        self::assertSame("a2 RENAME \"Projects/Old\" \"Projects/New\"\r\n", $this->afterSignIn());
    }

    #[Test]
    public function aFolderIsSubscribedToAndLeft(): void
    {
        $client = $this->signedIn("a2 OK\r\na3 OK\r\n");

        $client->subscribe(FolderName::fromWire('Projects'));
        $client->unsubscribe(FolderName::fromWire('Projects'));

        self::assertSame("a2 SUBSCRIBE \"Projects\"\r\na3 UNSUBSCRIBE \"Projects\"\r\n", $this->afterSignIn());
    }

    #[Test]
    public function aRefusalCarriesTheServersCode(): void
    {
        $client = $this->signedIn("a2 NO [ALREADYEXISTS] there is one\r\n");

        try {
            $client->create(FolderName::fromWire('Projects'));
            self::fail('a refused CREATE throws');
        } catch (CommandFailed $failed) {
            self::assertSame('ALREADYEXISTS', $failed->code());
        }
    }

    #[Test]
    public function anEmptyFolderIsDeletedFromANameOnlyThisClientKnows(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\na3 OK\r\n"
            . "* STATUS coolms-removing-x (MESSAGES 0)\r\na4 OK\r\n"
            . "a5 OK deleted\r\n",
        );

        $client->deleteEmpty(FolderName::fromWire('Projects/Old'));

        $written = $this->afterSignIn();
        self::assertSame(1, preg_match(
            '~^a2 RENAME "Projects/Old" "(coolms-removing-[0-9a-f]{16})"\r\n'
            . 'a3 LIST "" "\1\*"\r\n'
            . 'a4 STATUS "\1" \(MESSAGES\)\r\n'
            . 'a5 DELETE "\1"\r\n$~',
            $written,
        ), $written);
    }

    #[Test]
    public function aFolderWithMailIsPutBackAndNotDeleted(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\na3 OK\r\n"
            . "* STATUS coolms-removing-x (MESSAGES 2)\r\na4 OK\r\n"
            . "a5 OK renamed back\r\n",
        );

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects/Old'));
            self::fail('a folder holding mail is not deleted');
        } catch (FolderNotEmpty $notEmpty) {
            self::assertSame(2, $notEmpty->messages);
        }

        $written = $this->afterSignIn();
        self::assertStringNotContainsString('DELETE', $written);
        self::assertSame(1, preg_match('~a5 RENAME "coolms-removing-[0-9a-f]{16}" "Projects/Old"\r\n$~', $written), $written);
    }

    #[Test]
    public function aFolderWithFoldersInsideIsPutBackAndNotDeleted(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\n* LIST () \"/\" coolms-removing-x/Inner\r\na3 OK\r\n"
            . "a4 OK renamed back\r\n",
        );

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects'));
            self::fail('a folder holding folders is not deleted');
        } catch (FolderNotEmpty $notEmpty) {
            self::assertSame(1, $notEmpty->folders);
        }

        $written = $this->afterSignIn();
        self::assertStringNotContainsString('DELETE', $written);
        self::assertStringNotContainsString('STATUS', $written);
    }

    #[Test]
    public function aFolderThatCannotBePutBackSaysWhereItIs(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\na3 OK\r\n"
            . "* STATUS coolms-removing-x (MESSAGES 1)\r\na4 OK\r\n"
            . "a5 NO [ALREADYEXISTS] taken meanwhile\r\n",
        );

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects'));
            self::fail('the folder is neither deleted nor back');
        } catch (FolderNotEmpty $notEmpty) {
            self::assertNotNull($notEmpty->leftAt);
            self::assertStringStartsWith('coolms-removing-', $notEmpty->leftAt->wire);
        }
    }

    #[Test]
    public function aLookThatFailsPutsTheFolderBackAndSaysWhy(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\na3 OK\r\n"
            . "a4 NO [CANNOT] not selectable\r\n"
            . "a5 OK renamed back\r\n",
        );

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects'));
            self::fail('a refused STATUS stops the delete');
        } catch (CommandFailed $failed) {
            self::assertSame('CANNOT', $failed->code());
        }

        $written = $this->afterSignIn();
        self::assertStringNotContainsString('DELETE', $written);
        self::assertSame(1, preg_match('~a5 RENAME "coolms-removing-[0-9a-f]{16}" "Projects"\r\n$~', $written), $written);
    }

    #[Test]
    public function aRefusedDeleteThatCannotBePutBackSaysWhereTheFolderIs(): void
    {
        $client = $this->signedIn(
            "a2 OK renamed\r\n"
            . "* LIST () \"/\" coolms-removing-x\r\na3 OK\r\n"
            . "* STATUS coolms-removing-x (MESSAGES 0)\r\na4 OK\r\n"
            . "a5 NO [INUSE] in use\r\n"
            . "a6 NO [ALREADYEXISTS] taken meanwhile\r\n",
        );

        try {
            $client->deleteEmpty(FolderName::fromWire('Projects'));
            self::fail('a refused DELETE that cannot be undone says where the folder is');
        } catch (FolderLeftAside $aside) {
            self::assertStringStartsWith('coolms-removing-', $aside->leftAt->wire);
            self::assertInstanceOf(CommandFailed::class, $aside->getPrevious());
            self::assertSame('INUSE', $aside->getPrevious()->code());
        }
    }

    #[Test]
    public function theInboxIsNeverRenamedOrDeleted(): void
    {
        $client = $this->signedIn('');

        foreach (['rename', 'deleteEmpty'] as $operation) {
            try {
                'rename' === $operation
                    ? $client->rename(FolderName::fromWire('inbox'), FolderName::fromWire('Old'))
                    : $client->deleteEmpty(FolderName::fromWire('INBOX'));
                self::fail($operation . ' of the INBOX is refused');
            } catch (LogicException) {
            }
        }

        self::assertSame('', $this->afterSignIn(), 'nothing was sent');
    }

    private function signedIn(string $then): Client
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, self::GREETING . $then);
        $opened = Client::open(
            new Endpoint('imap.x.test', 993, Security::Tls, readTimeout: 1.0),
            new Opener(static fn (): mixed => $client),
        );
        $opened->login('me', 'pw');

        return $opened;
    }

    /** What the client wrote after its LOGIN (and the CAPABILITY it asks for when the completion gave none). */
    private function afterSignIn(): string
    {
        stream_set_blocking($this->server, false);
        $written = (string) stream_get_contents($this->server);

        return (string) preg_replace('~^a1 LOGIN [^\r]*\r\n~', '', $written);
    }
}
