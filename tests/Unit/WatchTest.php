<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use CoolMS\Imap\Client;
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

use function array_map;
use function array_merge;
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
