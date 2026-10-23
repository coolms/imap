<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Exception\CouldNotOpen;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fwrite;
use function is_resource;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrchr;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/** TLS itself is proved against Dovecot (the conformance suite); here, every step up to the handshake. */
final class OpenerTest extends TestCase
{
    /** @var resource */
    private mixed $server;

    /** @var resource|null */
    private mixed $client = null;

    #[Test]
    public function capabilitiesInTheGreetingAreNotAskedAgain(): void
    {
        $opened = $this->opener("* OK [CAPABILITY IMAP4rev1 SASL-IR AUTH=PLAIN AUTH=XOAUTH2 LITERAL+] ready\r\n")
            ->open($this->endpoint(Security::Tls));

        self::assertTrue($opened->capabilities->has('literal+'));
        self::assertSame(['PLAIN', 'XOAUTH2'], $opened->capabilities->mechanisms());
        self::assertFalse($opened->preauthenticated());
        self::assertSame('', $this->written());
    }

    #[Test]
    public function withoutThemInTheGreetingTheyAreAsked(): void
    {
        $opened = $this->opener("* OK ready\r\n* CAPABILITY IMAP4rev1 IDLE\r\na1 OK\r\n")
            ->open($this->endpoint(Security::Tls));

        self::assertTrue($opened->capabilities->has('IDLE'));
        self::assertFalse($opened->capabilities->has('CAPABILITY'), 'the word itself is not a capability');
        self::assertSame("a1 CAPABILITY\r\n", $this->written());
    }

    #[Test]
    public function aServerWithoutStartTlsIsNotSpokenToInClear(): void
    {
        $this->refused("* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready\r\n", 'does not offer STARTTLS');
        self::assertSame('', $this->written(), 'nothing sent -- least of all a password');
    }

    #[Test]
    public function aStartTlsTheServerRefusesIsNotCarriedOnInClear(): void
    {
        $this->refused("* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\na1 NO not now\r\n", 'refused STARTTLS: not now');
    }

    #[Test]
    public function bytesBetweenStartTlsAndTheHandshakeAreRefused(): void
    {
        $this->refused(
            "* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\na1 OK begin TLS\r\n* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\n",
            'sent 35 bytes after STARTTLS',
        );
        self::assertSame("a1 STARTTLS\r\n", $this->written());
    }

    #[Test]
    public function controlWithoutThemItGoesOnToTheHandshake(): void
    {
        $this->refused("* OK [CAPABILITY IMAP4rev1 STARTTLS] ready\r\na1 OK begin TLS\r\n", 'TLS handshake after STARTTLS failed');
    }

    #[Test]
    public function preauthCannotBeSecuredByStartTls(): void
    {
        $this->refused("* PREAUTH [CAPABILITY IMAP4rev1 STARTTLS] welcome back\r\n", 'before STARTTLS');
        self::assertSame('', $this->written());
    }

    #[Test]
    public function preauthOverTlsIsAuthenticatedAlready(): void
    {
        self::assertTrue(
            $this->opener("* PREAUTH [CAPABILITY IMAP4rev1] hi\r\n")
                ->open($this->endpoint(Security::Tls))
                ->preauthenticated(),
        );
    }

    #[Test]
    public function aByeGreetingIsARefusal(): void
    {
        $this->refused("* BYE too many connections\r\n", 'refused the connection: too many connections', Security::Tls);
    }

    #[Test]
    public function aPortNothingListensOnCannotBeReached(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);

        $this->expectException(CouldNotOpen::class);
        $this->expectExceptionMessage('127.0.0.1:' . $port . ' could not be reached');
        new Opener()->open(new Endpoint('127.0.0.1', $port, Security::PlainForTestsOnly, connectTimeout: 1.0));
    }

    #[Test]
    public function aServerThatNeverGreetsTimesOutAtTheReadTimeout(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);

        try {
            new Opener()->open(new Endpoint('127.0.0.1', $port, Security::PlainForTestsOnly, readTimeout: 0.2));
            self::fail('a silent server is never a greeting');
        } catch (TimedOut) {
            $this->addToAssertionCount(1);
        } finally {
            fclose($listener);
        }
    }

    #[Test]
    public function theCertificateIsVerifiedAgainstTheNameWhenTheSocketGoesToAnAddress(): void
    {
        $pinned = Opener::tlsContext(new Endpoint('203.0.113.7', 993, Security::Tls, peerName: 'imap.x.test'));
        $named = Opener::tlsContext(new Endpoint('imap.x.test', 993, Security::Tls));

        self::assertSame(['imap.x.test', 'imap.x.test'], [$pinned['peer_name'], $pinned['SNI_server_name']]);
        self::assertSame(['imap.x.test', 'imap.x.test'], [$named['peer_name'], $named['SNI_server_name']]);
        foreach ([$pinned, $named] as $context) {
            self::assertSame([true, true, false], [
                $context['verify_peer'],
                $context['verify_peer_name'],
                $context['allow_self_signed'],
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            fclose($this->server);
        }
    }

    private function refused(string $script, string $reason, Security $security = Security::StartTls): void
    {
        try {
            $this->opener($script)->open($this->endpoint($security));
            self::fail('opened, where it is refused: ' . $reason);
        } catch (CouldNotOpen $refused) {
            self::assertStringContainsString($reason, $refused->getMessage());
            self::assertFalse(is_resource($this->client), 'a refused connection is closed');
        }
    }

    private function opener(string $script): Opener
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$this->client, $this->server] = $pair;
        fwrite($this->server, $script);

        return new Opener(fn (Endpoint $endpoint): mixed => $this->client);
    }

    private function endpoint(Security $security): Endpoint
    {
        return new Endpoint('imap.x.test', Security::Tls === $security ? 993 : 143, $security, readTimeout: 1.0);
    }

    private function written(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }
}
