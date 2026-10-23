<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\Connection;
use CoolMS\Imap\Transport\ResponseReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function hrtime;
use function proc_close;
use function proc_open;
use function stream_set_timeout;
use function stream_socket_pair;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class IdleTest extends TestCase
{
    private string $record;

    #[Test]
    public function aSilentServerIsLeftWithDoneWhenTheTimeIsUp(): void
    {
        [$connection, $process] = $this->server('', 0);

        $start = hrtime(true);
        $reply = $connection->idle(static fn (): bool => self::fail('nothing came'), 0.3);
        $waited = (hrtime(true) - $start) / 1e9;
        proc_close($process);

        self::assertTrue($reply->ok());
        self::assertSame([], $reply->untagged);
        self::assertGreaterThanOrEqual(0.29, $waited, 'it waited the time given');
        self::assertLessThan(2.0, $waited, 'and not much longer');
        self::assertSame("a1 IDLE\nDONE", file_get_contents($this->record));
    }

    #[Test]
    public function whatComesGoesToTheCallerWhichSaysWhenToStop(): void
    {
        [$connection, $process] = $this->server('* 4 EXISTS\n* 2 FETCH (FLAGS (\Seen))\n', 100);
        $seen = [];

        $start = hrtime(true);
        $reply = $connection->idle(static function (Response $response) use (&$seen): bool {
            $seen[] = $response->data[1]->value;

            return 'FETCH' !== $response->data[1]->value;
        }, 3.0);
        $waited = (hrtime(true) - $start) / 1e9;
        proc_close($process);

        self::assertSame(['EXISTS', 'FETCH'], $seen, 'each in turn, and the second one stopped it');
        self::assertLessThan(2.0, $waited, 'stopped when told, not when the time was up');
        self::assertCount(1, $reply->data('EXISTS'));
        self::assertSame("a1 IDLE\nDONE", file_get_contents($this->record), 'DONE, then the completion');
    }

    protected function tearDown(): void
    {
        @unlink($this->record);
    }

    /** @return array{Connection, resource} */
    private function server(string $send, int $afterMs): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        $this->record = (string) tempnam(sys_get_temp_dir(), 'idle');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Support/idle-server.php', $this->record, $send, (string) $afterMs],
            [0 => $server, 1 => $server, 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertNotFalse($process);
        stream_set_timeout($client, 5);

        return [new Connection($client, new ResponseReader($client)), $process];
    }
}
