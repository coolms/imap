<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Client;
use CoolMS\Imap\Exception\CouldNotOpen;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use CoolMS\Imap\Value\FolderName;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_values;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function is_resource;
use function is_string;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * The opening deadline covers the TLS handshake: implicit TLS's and STARTTLS's. A server that sends the first bytes of
 * a TLS record a few at a time, each well inside the stream's own timeout, is cut at the deadline instead of holding
 * the opening as long as it likes; a real handshake under the same deadline completes. Each server runs as a process
 * of its own over real TCP, so the handshake is a real one.
 */
final class TheOpeningDeadlineBoundsTheTlsHandshakeTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<string> */
    private array $files = [];

    #[Test]
    public function anImplicitTlsHandshakeTrickledIsCutAtTheOpeningDeadline(): void
    {
        $port = $this->trickler('implicit', 3000);

        [$failure, $held] = $this->open(new Endpoint('127.0.0.1', $port, Security::Tls, connectTimeout: 2.0, readTimeout: 2.0, openTimeout: 0.6));

        self::assertInstanceOf(TimedOut::class, $failure);
        self::assertSame('The TLS handshake did not finish in time.', $failure->getMessage());
        self::assertGreaterThanOrEqual(0.55, $held);
        self::assertLessThan(0.9, $held, 'never much past the 0.6 s opening, though every byte came inside the 2 s wait');
    }

    #[Test]
    public function aStartTlsHandshakeTrickledIsCutAtTheOpeningDeadline(): void
    {
        $port = $this->trickler('starttls', 3000);

        [$failure, $held] = $this->open(new Endpoint('127.0.0.1', $port, Security::StartTls, connectTimeout: 2.0, readTimeout: 2.0, openTimeout: 0.6));

        self::assertInstanceOf(TimedOut::class, $failure);
        self::assertLessThan(0.9, $held, 'the STARTTLS handshake too');
    }

    /** The control: without an opening deadline, the same server holds the implicit TLS handshake until it lets go. */
    #[Test]
    public function withoutTheDeadlineTheSameServerHoldsTheHandshake(): void
    {
        $port = $this->trickler('implicit', 1500);

        [$failure, $held] = $this->open(new Endpoint('127.0.0.1', $port, Security::Tls, connectTimeout: 5.0, readTimeout: 5.0));

        self::assertInstanceOf(CouldNotOpen::class, $failure, 'the handshake fails only when the server lets go');
        self::assertGreaterThan(1.5, $held, 'every byte inside the wait: nothing ended it but the server');
    }

    /** A real implicit TLS handshake under the opening deadline completes, the certificate verified. */
    #[Test]
    public function aRealImplicitHandshakeUnderTheDeadlineCompletes(): void
    {
        $cert = $this->certificate();
        $port = $this->server('tls-idle-server.php', $cert, 'silent');

        $client = Client::open($this->endpoint($port, Security::Tls, $cert, 'localhost'));

        self::assertSame(3, $client->select(FolderName::fromWire('INBOX'))->exists, 'signed in over TLS and reading');
    }

    /** The control: under the same deadline, a certificate for another name is refused, as before, not timed out. */
    #[Test]
    public function anImplicitHandshakeAgainstTheWrongNameIsRefusedUnderTheDeadline(): void
    {
        $cert = $this->certificate();
        $port = $this->server('tls-idle-server.php', $cert, 'silent');

        [$failure] = $this->open($this->endpoint($port, Security::Tls, $cert, 'example.test'));

        self::assertInstanceOf(CouldNotOpen::class, $failure, 'the certificate is checked against the peer name');
    }

    /** The control: under the same deadline, a self-signed certificate with no CA file to trust it is refused. */
    #[Test]
    public function anImplicitHandshakeWithNoCaFileForTheCertificateIsRefusedUnderTheDeadline(): void
    {
        $cert = $this->certificate();
        $port = $this->server('tls-idle-server.php', $cert, 'silent');

        [$failure] = $this->open($this->endpoint($port, Security::Tls, null, 'localhost'));

        self::assertInstanceOf(CouldNotOpen::class, $failure, 'a certificate nothing vouches for is refused');
    }

    /** A real STARTTLS handshake under the opening deadline completes, the certificate verified. */
    #[Test]
    public function aRealStartTlsHandshakeUnderTheDeadlineCompletes(): void
    {
        $cert = $this->certificate();
        $port = $this->server('starttls-server.php', $cert);

        $opened = new Opener()->open($this->endpoint($port, Security::StartTls, $cert, 'localhost'));

        self::assertTrue($opened->capabilities->has('IDLE'), 'secured, and the capabilities asked again over TLS');
    }

    /** The control: under the same deadline, STARTTLS against a certificate for another name is refused. */
    #[Test]
    public function aStartTlsHandshakeAgainstTheWrongNameIsRefusedUnderTheDeadline(): void
    {
        $cert = $this->certificate();
        $port = $this->server('starttls-server.php', $cert);

        [$failure] = $this->open($this->endpoint($port, Security::StartTls, $cert, 'example.test'));

        self::assertInstanceOf(CouldNotOpen::class, $failure, 'the certificate is checked against the peer name');
    }

    /** The control: under the same deadline, STARTTLS with no CA file for the self-signed certificate is refused. */
    #[Test]
    public function aStartTlsHandshakeWithNoCaFileForTheCertificateIsRefusedUnderTheDeadline(): void
    {
        $cert = $this->certificate();
        $port = $this->server('starttls-server.php', $cert);

        [$failure] = $this->open($this->endpoint($port, Security::StartTls, null, 'localhost'));

        self::assertInstanceOf(CouldNotOpen::class, $failure, 'a certificate nothing vouches for is refused');
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->processes = [];
        $this->files = [];
    }

    /** @return array{Throwable|null, float} what the opening ended with, and how long it held the caller */
    private function open(Endpoint $endpoint): array
    {
        $started = hrtime(true);
        $failure = null;
        try {
            new Opener()->open($endpoint);
        } catch (Throwable $e) {
            $failure = $e;
        }

        return [$failure, (hrtime(true) - $started) / 1e9];
    }

    private function endpoint(int $port, Security $security, ?string $cert, string $peerName): Endpoint
    {
        return new Endpoint(
            '127.0.0.1',
            $port,
            $security,
            connectTimeout: 2.0,
            readTimeout: 2.0,
            caFile: $cert,
            peerName: $peerName,
            openTimeout: 3.0,
        );
    }

    /** A self-signed certificate for localhost, with its key, in a file of its own. */
    private function certificate(): string
    {
        $cert = $this->temp('imap-cert');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $signed = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($signed);
        openssl_x509_export($signed, $pem);
        openssl_pkey_export($key, $keyPem);
        self::assertIsString($pem);
        self::assertIsString($keyPem);
        file_put_contents($cert, $pem . $keyPem);

        return $cert;
    }

    private function server(string $script, string $cert, string ...$arguments): int
    {
        $portFile = $this->temp('imap-port');
        $this->files[] = $portFile . '.read';
        file_put_contents($portFile, '');
        $this->spawn(array_values([PHP_BINARY, dirname(__DIR__, 2) . '/Support/' . $script, $cert, $portFile, ...$arguments]));

        return $this->port($portFile);
    }

    private function trickler(string $mode, int $holdMs): int
    {
        $portFile = $this->temp('imap-port');
        file_put_contents($portFile, '');
        $this->spawn([PHP_BINARY, dirname(__DIR__, 2) . '/Support/handshake-trickler.php', $portFile, $mode, (string) $holdMs]);

        return $this->port($portFile);
    }

    /** @param list<string> $command */
    private function spawn(array $command): void
    {
        $process = proc_open($command, [], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
    }

    private function port(string $portFile): int
    {
        for ($i = 0; $i < 150 && '' === trim((string) file_get_contents($portFile)); ++$i) {
            usleep(20_000);
        }
        $port = trim((string) file_get_contents($portFile));
        self::assertNotSame('', $port, 'the server never said its port');

        return (int) $port;
    }

    private function temp(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        self::assertTrue(is_string($file));
        $this->files[] = $file;

        return $file;
    }
}
