<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\IoPoller;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Poller;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use CoolMS\Imap\Watch;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_merge;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function is_file;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function proc_close;
use function proc_open;
use function stream_context_create;
use function stream_socket_client;
use function strtoupper;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const STREAM_CLIENT_CONNECT;

/**
 * A Watch waits on several idling clients at once. Over TLS a record can carry two responses: once the first is read,
 * the second is already in PHP's buffer, where a native poll() on the socket never sees it -- so the Watch looks at
 * every client's buffer BEFORE it polls. The stand-in poller here is the native view: it reports the stream once,
 * when the record arrives, and nothing after.
 */
final class WatchTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<string> */
    private array $files = [];

    #[Test]
    public function twoResponsesInOneTlsRecordBothArriveWithoutWaitingOutTheTimeout(): void
    {
        $client = $this->idlingClientOver('two');
        $poller = new class implements Poller {
            public int $asked = 0;

            public function readable(array $streams, float $seconds): array
            {
                ++$this->asked;
                // The record arrives once: the first ask sees it (after the server's 100 ms), every later one sees
                // nothing new on the socket, as a native poll() would, and waits out its time.
                if (1 === $this->asked) {
                    usleep(300_000);

                    return array_keys($streams);
                }
                usleep((int) ($seconds * 1e6));

                return [];
            }
        };
        $watch = new Watch($poller);
        $watch->add('inbox', $client);

        $start = hrtime(true);
        $seen = [];
        for ($i = 0; $i < 3 && count($seen) < 2; ++$i) {
            $seen = array_merge($seen, self::texts($watch->wait(1.0)['inbox'] ?? []));
        }
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertSame(['4 EXISTS', '5 EXISTS'], $seen, 'both responses of the one record');
        self::assertLessThan(900, $ms, 'never a timeout waited out while a response sat in the buffer');
        $watch->end();
    }

    #[Test]
    public function aResponseInTheRecordThatCarriedThePlusIsReadyWithoutAPoll(): void
    {
        // Starting IDLE read the "+"; the response beside it in the record is in PHP's buffer, and nothing more will
        // come to the socket. A native poll() would sleep the whole wait: the buffer is looked at first.
        $client = $this->idlingClientOver('with-plus');
        $poller = new class implements Poller {
            public int $asked = 0;

            public function readable(array $streams, float $seconds): array
            {
                ++$this->asked;
                usleep((int) ($seconds * 1e6));

                return [];
            }
        };
        $watch = new Watch($poller);
        $watch->add('inbox', $client);

        $start = hrtime(true);
        $seen = self::texts($watch->wait(1.0)['inbox'] ?? []);
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertSame(['4 EXISTS'], $seen);
        self::assertSame(0, $poller->asked, 'buffered: no poll made');
        self::assertLessThan(500, $ms);
        $watch->end();
    }

    #[Test]
    public function aSilentServerGivesNothingAfterTheTimeAndThePollerWasAsked(): void
    {
        $client = $this->idlingClientOver('silent');
        $poller = new class implements Poller {
            public int $asked = 0;

            public function readable(array $streams, float $seconds): array
            {
                ++$this->asked;
                usleep((int) ($seconds * 1e6));

                return [];
            }
        };
        $watch = new Watch($poller);
        $watch->add('inbox', $client);

        self::assertSame([], $watch->wait(0.2));
        self::assertSame(1, $poller->asked, 'nothing buffered: it polled');
        $watch->end();
    }

    #[Test]
    public function theIoPollPollerWakesWhenTheRecordArrives(): void
    {
        $client = $this->idlingClientOver('two');
        $watch = new Watch(new IoPoller());
        $watch->add('inbox', $client);

        $start = hrtime(true);
        $seen = self::texts($watch->wait(2.0)['inbox'] ?? []);
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertSame(['4 EXISTS', '5 EXISTS'], $seen);
        self::assertLessThan(1500, $ms, 'woken by the data, not by the timeout');
        $watch->end();
    }

    #[Test]
    public function eachClientIsRenewedWhenItsOwnTimeIsUpWithoutEndingTheOthers(): void
    {
        $early = $this->idlingClientOver('silent');
        $watch = new Watch(new IoPoller(), renewAfter: 0.3, answerWithin: 2.0);
        $watch->add('early', $early);
        usleep(200_000);
        $watch->add('late', $this->idlingClientOver('silent'));

        $watch->wait(0.15);
        self::assertSame(['early'], $watch->renewing(), 'only the one whose time is up');
        $left = 10;
        do {
            $watch->wait(0.05);
        } while ([] !== $watch->renewing() && --$left > 0);
        self::assertSame([], $watch->renewing(), 'the renewal answered: idling again');
        self::assertSame([], $watch->failures());
        $watch->end();
        $early->logout();
        self::assertSame(['IDLE', 'DONE', 'IDLE', 'DONE'], $this->idleLines(0), 'DONE, then IDLE again, on its own');
    }

    #[Test]
    public function mailThatArrivesDuringARenewalIsStillReported(): void
    {
        $watch = new Watch(new IoPoller(), renewAfter: 0.1, answerWithin: 2.0);
        $watch->add('inbox', $this->idlingClientOver('news-in-renewal'));
        usleep(150_000);

        $seen = [];
        $left = 20;
        do {
            $seen = array_merge($seen, self::texts($watch->wait(0.05)['inbox'] ?? []));
        } while ((count($seen) < 2 || [] !== $watch->renewing()) && --$left > 0);

        self::assertSame(['6 EXISTS', '7 EXISTS'], $seen, 'before the DONE\'s OK, and before the new IDLE\'s "+"');
        self::assertSame([], $watch->failures());
        $watch->end();
    }

    #[Test]
    public function aServerThatSaysByeWhileIdlingDropsThatClient(): void
    {
        $watch = new Watch(new IoPoller());
        $watch->add('inbox', $this->idlingClientOver('bye'));

        for ($i = 0; $i < 10 && [] === $watch->failures(); ++$i) {
            $watch->wait(0.2);
        }

        self::assertInstanceOf(ConnectionClosed::class, $watch->failures()['inbox'] ?? null);
        self::assertStringContainsString('shutting down', $watch->failures()['inbox']->getMessage());
        self::assertSame([], $watch->keys());
    }

    #[Test]
    public function aClientRemovedFromAServerThatStoppedAnsweringIsATimeOutNotAThrow(): void
    {
        $watch = new Watch(new IoPoller());
        $watch->add('inbox', $this->idlingClientOver('mute-done'));

        self::assertSame([], $watch->remove('inbox'));

        self::assertInstanceOf(TimedOut::class, $watch->failures()['inbox'] ?? null);
    }

    #[Test]
    public function aWaitNeverSleepsPastTheNextRenewal(): void
    {
        $poller = new class implements Poller {
            /** @var list<float> */
            public array $asked = [];

            public function readable(array $streams, float $seconds): array
            {
                $this->asked[] = $seconds;

                return [];
            }
        };
        $watch = new Watch($poller, renewAfter: 0.25, answerWithin: 2.0);
        $watch->add('inbox', $this->idlingClientOver('silent'));

        $watch->wait(30.0);

        self::assertCount(1, $poller->asked);
        self::assertLessThanOrEqual(0.25, $poller->asked[0], 'woken for the renewal, not after the whole wait');
        $watch->end();
    }

    #[Test]
    public function aRenewalTheServerNeverAnswersDropsThatClientAsFailed(): void
    {
        $watch = new Watch(new IoPoller(), renewAfter: 0.1, answerWithin: 0.3);
        $watch->add('mute', $this->idlingClientOver('mute-done'));
        $watch->add('fine', $this->idlingClientOver('silent'));

        for ($i = 0; $i < 20 && [] === $watch->failures(); ++$i) {
            $watch->wait(0.05);
        }

        self::assertSame(['mute'], array_keys($watch->failures()));
        self::assertStringContainsString('unanswered', $watch->failures()['mute']->getMessage());
        self::assertSame(['fine'], $watch->keys(), 'the other goes on');
        $watch->end();
    }

    #[Test]
    public function oneClientIsRemovedAndTheOthersGoOn(): void
    {
        $watch = new Watch(new IoPoller());
        $gone = $this->idlingClientOver('silent');
        $watch->add('gone', $gone);
        $watch->add('stays', $this->idlingClientOver('two'));

        self::assertSame([], $watch->remove('gone'));
        $gone->logout();
        $seen = [];
        for ($i = 0; $i < 3 && count($seen) < 2; ++$i) {
            $seen = array_merge($seen, self::texts($watch->wait(1.0)['stays'] ?? []));
        }

        self::assertSame(['stays'], $watch->keys());
        self::assertSame(['4 EXISTS', '5 EXISTS'], $seen);
        $watch->end();
        self::assertSame(['IDLE', 'DONE'], $this->idleLines(0), 'the removed one ended its IDLE');
    }

    #[Test]
    public function aClientRemovedWhileItsRenewalIsUnansweredSendsNoSecondDone(): void
    {
        $watch = new Watch(new IoPoller(), renewAfter: 0.1, answerWithin: 2.0);
        $client = $this->idlingClientOver('slow-done');
        $watch->add('inbox', $client);
        usleep(150_000);
        $watch->wait(0.01);
        self::assertSame(['inbox'], $watch->renewing(), 'DONE sent, its answer 300 ms away');

        $watch->remove('inbox');
        $client->logout();

        self::assertSame(['IDLE', 'DONE'], $this->idleLines(0));
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_close($process);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param list<Response> $responses
     *
     * @return list<string>
     */
    private static function texts(array $responses): array
    {
        return array_map(
            static fn (Response $r): string => trim($r->data[0]->string() . ' ' . $r->data[1]->string()),
            $responses,
        );
    }

    /**
     * The IDLE and DONE lines the n-th test server read, once its client has left.
     *
     * @return list<string>
     */
    private function idleLines(int $server): array
    {
        $read = $this->files[$server * 3 + 2];
        // The server writes the file as its client leaves: wait for its content, not only for the file to exist.
        $content = '';
        for ($i = 0; $i < 100 && '' === $content; ++$i) {
            usleep(20_000);
            $content = is_file($read) ? (string) file_get_contents($read) : '';
        }
        $lines = [];
        foreach (explode("\n", $content) as $line) {
            $word = 'DONE' === $line ? 'DONE' : (explode(' ', $line . ' ')[1] ?? '');
            if ('IDLE' === strtoupper($word) || 'DONE' === $word) {
                $lines[] = strtoupper($word);
            }
        }

        return $lines;
    }

    private function idlingClientOver(string $mode): Client
    {
        $cert = (string) tempnam(sys_get_temp_dir(), 'imap-cert');
        $port = (string) tempnam(sys_get_temp_dir(), 'imap-port');
        $this->files = [...$this->files, $cert, $port, $port . '.read'];
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $signed = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($signed);
        openssl_x509_export($signed, $pem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($cert, $pem . $keyPem);
        file_put_contents($port, '');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/tls-idle-server.php', $cert, $port, $mode],
            [],
            $pipes,
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
        for ($i = 0; $i < 100 && '' === trim((string) file_get_contents($port)); ++$i) {
            usleep(20_000);
        }
        $errno = 0;
        $error = '';
        $stream = stream_socket_client(
            'tls://127.0.0.1:' . trim((string) file_get_contents($port)),
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
        );
        self::assertNotFalse($stream, 'the TLS client could not connect: ' . $error);
        $client = Client::open(
            new Endpoint('localhost', 993, Security::Tls, readTimeout: 2.0),
            new Opener(static fn (): mixed => $stream),
        );
        $client->select(FolderName::fromWire('INBOX'));

        return $client;
    }
}
