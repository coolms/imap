<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Transport\Connection;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\ResponseReader;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function fclose;
use function file_get_contents;
use function fwrite;
use function hrtime;
use function is_resource;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function str_repeat;
use function stream_set_timeout;
use function stream_socket_pair;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * A server that sends a byte at a time, each well inside the stream's own timeout, keeps a read going for as long as
 * it likes: only a deadline over the whole response, or over the whole opening, ends it. Each test runs that server as
 * its own process and measures, on the monotonic clock, how long the read held the caller.
 */
final class ADeadlineBoundsAWholeResponseTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    #[Test]
    public function aTricklingServerIsCutAtTheResponseDeadlineNotHeldByIt(): void
    {
        $client = $this->trickle('* 1 EXISTS and more, slowly, never a line end', 50, 3000);
        stream_set_timeout($client, 1);
        $reader = new ResponseReader($client, responseSeconds: 0.4, waitSeconds: 1.0);

        $started = hrtime(true);
        try {
            $reader->next();
            self::fail('a response that never ends in time is not handed on');
        } catch (TimedOut $e) {
            self::assertSame('The server did not send a whole response in time.', $e->getMessage());
        }
        $held = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThanOrEqual(0.38, $held, 'held for the deadline');
        self::assertLessThan(0.6, $held, 'never much past it, though every byte came inside the 1 s wait');
    }

    /** A server that sends half a line and falls silent is cut at the deadline, not held for the stream's whole wait. */
    #[Test]
    public function aServerFallingSilentMidLineIsCutAtTheDeadlineNotAtItsWait(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, '* 1 EXI');
        $reader = new ResponseReader($client, responseSeconds: 0.3, waitSeconds: 2.0);

        $started = hrtime(true);
        try {
            $reader->next();
            self::fail('half a line is never a response');
        } catch (TimedOut $e) {
            self::assertSame('The server did not send a whole response in time.', $e->getMessage());
        }
        $held = (hrtime(true) - $started) / 1e9;
        fclose($server);

        self::assertLessThan(0.5, $held, 'the 2 s wait was cut to what the 0.3 s deadline left');
    }

    /** The opening deadline runs through the sign-in and ends there: a later read is not held to it. */
    #[Test]
    public function theOpeningDeadlineEndsOnceSignedIn(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "* OK [CAPABILITY IMAP4rev1] ready\r\na1 OK [CAPABILITY IMAP4rev1] signed in\r\n");
        $signedIn = Client::open(
            new Endpoint('imap.example.test', 993, Security::Tls, readTimeout: 2.0, openTimeout: 0.3),
            new Opener(static fn (): mixed => $client),
        );
        $signedIn->login('person', 'secret');

        usleep(500_000);
        fwrite($server, "* 2 EXISTS\r\n* OK [UIDVALIDITY 7]\r\na2 OK [READ-WRITE] ok\r\n");

        self::assertSame(2, $signedIn->select(FolderName::fromWire('INBOX'))->exists, 'read after the 0.3 s opening');
        fclose($server);
    }

    /** A connection signed in by its greeting (PREAUTH) ends the opening deadline too: a later read is not held to it. */
    #[Test]
    public function aPreauthenticatedConnectionEndsTheOpeningDeadline(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "* PREAUTH [CAPABILITY IMAP4rev1] signed in already\r\n");
        $signedIn = Client::open(
            new Endpoint('imap.example.test', 993, Security::Tls, readTimeout: 2.0, openTimeout: 0.3),
            new Opener(static fn (): mixed => $client),
        );

        usleep(500_000);
        fwrite($server, "* 2 EXISTS\r\n* OK [UIDVALIDITY 7]\r\na1 OK [READ-WRITE] ok\r\n");

        self::assertSame(2, $signedIn->select(FolderName::fromWire('INBOX'))->exists, 'read after the 0.3 s opening');
        fclose($server);
    }

    /**
     * A read the deadline cut leaves the stream's own wait as it was configured: a write after it -- a command, an
     * APPEND's literal -- waits for a slow peer as long as it always did, not as long as was left of the read.
     */
    #[Test]
    public function aWriteAfterACutReadWaitsAsConfigured(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "* 1 EXISTS\r\n");
        $reader = new ResponseReader($client, responseSeconds: 0.2, waitSeconds: 2.0);
        self::assertSame('* 1 EXISTS', $reader->next(), 'a read whose wait the 0.2 s deadline cut');

        $record = (string) tempnam(sys_get_temp_dir(), 'slow-reader');
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/slow-reader.php', '600', $record],
            [0 => $server, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($server);
        $this->servers[] = $process;

        $bytes = str_repeat('x', 4 * 1024 * 1024);
        $sent = 0;
        while ($sent < strlen($bytes)) {
            $written = @fwrite($client, substr($bytes, $sent));
            if (false === $written || 0 === $written) {
                break;
            }
            $sent += $written;
        }
        fclose($client);
        proc_close($process);
        $this->servers = [];

        self::assertSame(strlen($bytes), $sent, 'the whole write went, the peer reading only after 0.6 s');
        self::assertSame((string) strlen($bytes), (string) file_get_contents($record));
        unlink($record);
    }

    /** The control: the same server, without a response deadline, holds the read until it stops sending. */
    #[Test]
    public function withoutTheDeadlineTheSameServerHoldsTheReadUntilItStops(): void
    {
        $client = $this->trickle('* 1 EXISTS, slowly\n', 50, 0);
        stream_set_timeout($client, 1);
        $reader = new ResponseReader($client, waitSeconds: 1.0);

        $started = hrtime(true);
        $response = $reader->next();
        $held = (hrtime(true) - $started) / 1e9;

        self::assertSame('* 1 EXISTS, slowly', $response);
        self::assertGreaterThan(0.8, $held, 'every byte inside the 1 s wait: nothing ended it but the server');
    }

    /** A slow server inside the deadline is read whole: the deadline cuts only what does not come in time. */
    #[Test]
    public function aSlowResponseThatComesInTimeIsReadWhole(): void
    {
        $client = $this->trickle('* 2 EXISTS\n', 20, 0);
        $reader = new ResponseReader($client, responseSeconds: 2.0, waitSeconds: 1.0);

        self::assertSame('* 2 EXISTS', $reader->next());
    }

    /** The opening -- greeting, capabilities, sign-in -- shares one deadline, whose clock starts before the connect. */
    #[Test]
    public function aServerTricklingItsGreetingIsCutAtTheOpeningDeadline(): void
    {
        $client = $this->trickle('* OK the greeting comes a byte at a time\n', 50, 3000);
        $opener = new Opener(static fn (): mixed => $client);

        $started = hrtime(true);
        try {
            $opener->open(new Endpoint('imap.example.test', 993, Security::Tls, readTimeout: 1.0, openTimeout: 0.5));
            self::fail('an opening that never ends in time is not handed on');
        } catch (TimedOut) {
            $this->addToAssertionCount(1);
        }
        $held = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThanOrEqual(0.48, $held);
        self::assertLessThan(0.7, $held, 'never much past the opening deadline');
    }

    /** Bytes of a later response, read with an earlier one, are there to read: a wait must not sleep on them. */
    #[Test]
    public function aResponseReadWithTheOneBeforeItIsBuffered(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "* 1 EXISTS\r\n* 2 EXISTS\r\n");
        $reader = new ResponseReader($client, waitSeconds: 1.0);
        $connection = new Connection($client, $reader);

        self::assertSame('* 1 EXISTS', $reader->next());
        self::assertTrue($reader->buffered(), 'the second came in the same read');
        self::assertTrue($connection->buffered(), 'and the connection says so, for the watch');
        self::assertSame('* 2 EXISTS', $reader->next());
        self::assertFalse($reader->buffered());
        fclose($server);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->servers = [];
    }

    /** @return resource the client's end of a pair whose other end the trickling server writes */
    private function trickle(string $bytes, int $everyMs, int $holdMs): mixed
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/trickle-server.php', $bytes, (string) $everyMs, (string) $holdMs],
            [0 => ['pipe', 'r'], 1 => $server, 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($server);
        $this->servers[] = $process;

        return $client;
    }
}
