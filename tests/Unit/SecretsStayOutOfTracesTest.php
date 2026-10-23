<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use Closure;
use CoolMS\Imap\Client;
use CoolMS\Imap\Protocol\Argument;
use CoolMS\Imap\Sasl\Login;
use CoolMS\Imap\Sasl\Mechanism;
use CoolMS\Imap\Sasl\OAuthBearer;
use CoolMS\Imap\Sasl\Plain;
use CoolMS\Imap\Sasl\XOAuth2;
use CoolMS\Imap\Transport\Endpoint;
use CoolMS\Imap\Transport\Opener;
use CoolMS\Imap\Transport\Security;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function fclose;
use function fwrite;
use function ini_get;
use function ini_set;
use function json_encode;
use function print_r;
use function str_contains;
use function stream_socket_pair;

use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * A password or a token never reaches a stack trace: not as an argument (#[\SensitiveParameter]) and not inside an
 * object passed along (a command, a mechanism), which a logger's print_r or JSON of the trace would show. Each case
 * fails on purpose; the trace is read as getTraceAsString(), print_r and JSON -- with arguments kept, as a development
 * php.ini keeps them -- and the user name, which is no secret, must show there: an instrument that shows no
 * arguments at all would pass every case for nothing.
 */
final class SecretsStayOutOfTracesTest extends TestCase
{
    private const string USER = 'user-name-shown';

    private const string SECRET = 's3cret-never-shown';

    /** @var array<string, string|false> */
    private array $ini = [];

    /** @return iterable<string, array{Closure(): mixed, bool}> a failure, and whether the user name is in its trace */
    public static function failures(): iterable
    {
        yield 'a secret argument with a NUL' => [static fn (): mixed => Argument::secret(self::SECRET . "\0"), false];
        yield 'PLAIN with a NUL' => [static fn (): mixed => new Plain(self::USER, self::SECRET . "\0"), true];
        yield 'XOAUTH2 with a ^A' => [static fn (): mixed => new XOAuth2(self::USER, self::SECRET . "\x01"), true];
        yield 'OAUTHBEARER with a ^A' => [
            static fn (): mixed => new OAuthBearer(self::USER, self::SECRET . "\x01", 'imap.x.test', 993),
            true,
        ];
        yield 'LOGIN on a connection that closed' => [
            static function (): void {
                self::closedClient()->login(self::USER, self::SECRET);
            },
            true,
        ];
        foreach (self::mechanisms() as $name => $mechanism) {
            yield 'AUTHENTICATE ' . $name . ' on a connection that closed' => [
                static function () use ($mechanism): void {
                    self::closedClient()->authenticate($mechanism());
                },
                true,
            ];
        }
    }

    #[Test]
    #[DataProvider('failures')]
    public function theTraceNamesTheUserButNeverTheSecret(Closure $fails, bool $userShown): void
    {
        try {
            $fails();
            self::fail('the case was to fail');
        } catch (Throwable $failure) {
            $trace = self::trace($failure);
        }

        self::assertFalse(str_contains($trace, self::SECRET), 'the secret is in the trace');
        if ($userShown) {
            self::assertTrue(str_contains($trace, self::USER), 'the trace shows no arguments: this case proves nothing');
        }
    }

    protected function setUp(): void
    {
        foreach (['zend.exception_ignore_args' => '0', 'zend.exception_string_param_max_len' => '100'] as $name => $value) {
            $this->ini[$name] = ini_get($name);
            ini_set($name, $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ini as $name => $value) {
            ini_set($name, (string) $value);
        }
    }

    /** @return iterable<string, Closure(): Mechanism> */
    private static function mechanisms(): iterable
    {
        yield 'PLAIN' => static fn (): Mechanism => new Plain(self::USER, self::SECRET);
        yield 'LOGIN' => static fn (): Mechanism => new Login(self::USER, self::SECRET);
        yield 'XOAUTH2' => static fn (): Mechanism => new XOAuth2(self::USER, self::SECRET);
        yield 'OAUTHBEARER' => static fn (): Mechanism => new OAuthBearer(self::USER, self::SECRET, 'imap.x.test', 993);
    }

    /** Greets, offering every mechanism, and then the server is gone: the next command fails mid-flight. */
    private static function closedClient(): Client
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        fwrite($server, "* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN AUTH=LOGIN AUTH=XOAUTH2 AUTH=OAUTHBEARER] ready\r\n");
        $opened = Client::open(
            new Endpoint('imap.x.test', 993, Security::Tls, readTimeout: 1.0),
            new Opener(static fn (): mixed => $client),
        );
        fclose($server);

        return $opened;
    }

    private static function trace(Throwable $failure): string
    {
        $shown = '';
        for ($at = $failure; null !== $at; $at = $at->getPrevious()) {
            $shown .= $at->getMessage() . "\n" . $at->getTraceAsString() . "\n" . print_r($at->getTrace(), true) . "\n"
                . json_encode($at->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }

        return $shown;
    }
}
