<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Protocol\Command;
use CoolMS\Imap\Sasl\Plain;
use CoolMS\Imap\Sasl\XOAuth2;
use CoolMS\Imap\Transport\Connection;
use CoolMS\Imap\Transport\ResponseReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function fclose;
use function fwrite;
use function implode;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;
use function stream_socket_shutdown;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SHUT_WR;
use const STREAM_SOCK_STREAM;

/**
 * The server's side is scripted: its bytes are all written before the client reads, and what the client wrote is read
 * back after. A step the client must wait for -- a "+" -- is proved by a script that never sends one.
 */
final class ConnectionTest extends TestCase
{
    /** @var resource */
    private mixed $server;

    /** @var list<string> */
    private array $log = [];

    #[Test]
    public function aCommandGetsItsUntaggedDataAndItsCompletion(): void
    {
        $connection = $this->connection("* OK ready\r\n* CAPABILITY IMAP4rev1 LITERAL+ SASL-IR\r\n* 3 EXISTS\r\na1 OK done\r\n");

        self::assertSame('OK', $connection->greeting()->status);
        $reply = $connection->run(new Command('CAPABILITY'));

        self::assertTrue($reply->ok());
        self::assertCount(2, $reply->untagged);
        self::assertSame('LITERAL+', $reply->data('capability')[0]->data[2]->value);
        self::assertCount(1, $reply->data('EXISTS'), '"* n NAME" is found by its name');
        self::assertSame("a1 CAPABILITY\r\n", $this->written());
    }

    #[Test]
    public function eachCommandHasATagOfItsOwn(): void
    {
        $connection = $this->connection("a1 OK\r\na2 OK\r\n");

        $connection->run(new Command('NOOP'));
        $connection->run(new Command('NOOP'));

        self::assertSame("a1 NOOP\r\na2 NOOP\r\n", $this->written());
    }

    #[Test]
    public function aLiteralGoesAfterTheServersPlus(): void
    {
        $connection = $this->connection("+ go on\r\na1 OK appended\r\n");

        $reply = $connection->run(new Command('APPEND', Argument::text('INBOX'), Argument::literal('hello')));

        self::assertTrue($reply->ok());
        self::assertSame("a1 APPEND \"INBOX\" {5}\r\nhello\r\n", $this->written());
    }

    #[Test]
    public function aLiteralTheServerRefusesIsNeverSent(): void
    {
        $connection = $this->connection("a1 NO [TOOBIG] too big\r\n");

        $reply = $connection->run(new Command('APPEND', Argument::text('INBOX'), Argument::literal('hello')));

        self::assertFalse($reply->ok());
        self::assertSame('NO', $reply->completion->status);
        self::assertSame("a1 APPEND \"INBOX\" {5}\r\n", $this->written(), 'no "+" came, so the bytes stayed');
    }

    #[Test]
    public function withLiteralPlusTheLiteralGoesWithoutWaiting(): void
    {
        $connection = $this->connection("a1 OK\r\n");

        $connection->run(new Command('APPEND', Argument::text('INBOX'), Argument::literal('hello')), true);

        self::assertSame("a1 APPEND \"INBOX\" {5+}\r\nhello\r\n", $this->written());
    }

    #[Test]
    public function aCompletionWithAnotherTagIsAProtocolError(): void
    {
        $connection = $this->connection("a7 OK\r\n");

        $this->expectException(ProtocolError::class);
        $connection->run(new Command('NOOP'));
    }

    #[Test]
    public function aPlusNothingWaitsForIsAProtocolError(): void
    {
        $connection = $this->connection("+ what\r\n");

        $this->expectException(ProtocolError::class);
        $connection->run(new Command('NOOP'));
    }

    #[Test]
    public function aStreamEndingAfterByeNamesTheBye(): void
    {
        $connection = $this->connection("* BYE shutting down for maintenance\r\n", close: true);

        try {
            $connection->run(new Command('NOOP'));
            self::fail('a closed stream is never a reply');
        } catch (ConnectionClosed $closed) {
            self::assertSame('The server said BYE: shutting down for maintenance', $closed->getMessage());
        }
    }

    #[Test]
    public function aGreetingIsAnUntaggedStatus(): void
    {
        $this->expectException(ProtocolError::class);
        $this->connection("* CAPABILITY IMAP4rev1\r\n")->greeting();
    }

    #[Test]
    public function withSaslIrTheFirstResponseRidesOnTheCommandAndARefusalsReasonIsKept(): void
    {
        $error = '{"status":"401","schemes":"bearer","scope":"https://mail.google.com/"}';
        $connection = $this->connection('+ ' . base64_encode($error) . "\r\na1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        $mechanism = new XOAuth2('me@x.test', 'ya29.token');

        $reply = $connection->authenticate($mechanism, true);

        self::assertSame('NO', $reply->completion->status);
        self::assertSame($error, $mechanism->error);
        self::assertSame(
            'a1 AUTHENTICATE XOAUTH2 ' . base64_encode("user=me@x.test\x01auth=Bearer ya29.token\x01\x01") . "\r\n\r\n",
            $this->written(),
            'the refusal\'s "+" is answered with an empty response',
        );
        self::assertFalse(str_contains(implode("\n", $this->log), 'ya29'), 'the token is never in the log');
        self::assertContains('C a1 AUTHENTICATE XOAUTH2 <secret>', $this->log);
    }

    #[Test]
    public function withoutSaslIrTheFirstResponseAnswersTheFirstPlus(): void
    {
        $connection = $this->connection("+ \r\na1 OK signed in\r\n");

        $reply = $connection->authenticate(new Plain('me', 'pw'), false);

        self::assertTrue($reply->ok());
        self::assertSame("a1 AUTHENTICATE PLAIN\r\n" . base64_encode("\0me\0pw") . "\r\n", $this->written());
        self::assertSame(['C a1 AUTHENTICATE PLAIN', 'S + ', 'C <secret>', 'S a1 OK signed in'], $this->log);
    }

    #[Test]
    public function aChallengeThatIsNotBase64IsCancelled(): void
    {
        $connection = $this->connection("+ not!base64\r\na1 BAD cancelled\r\n");

        $reply = $connection->authenticate(new XOAuth2('me', 't'), true);

        self::assertSame('BAD', $reply->completion->status);
        self::assertStringEndsWith("\r\n*\r\n", $this->written());
    }

    protected function tearDown(): void
    {
        fclose($this->server);
    }

    private function connection(string $script, bool $close = false): Connection
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, $script);
        if ($close) {
            stream_socket_shutdown($this->server, STREAM_SHUT_WR);
        }

        return new Connection($client, new ResponseReader($client), function (string $direction, string $line): void {
            $this->log[] = $direction . ' ' . $line;
        });
    }

    private function written(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }
}
