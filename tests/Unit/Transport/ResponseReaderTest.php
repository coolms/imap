<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit\Transport;

use CoolMS\Imap\Exception\ConnectionClosed;
use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Exception\TimedOut;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Transport\ResponseReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function str_repeat;
use function stream_set_timeout;
use function stream_socket_pair;
use function strlen;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ResponseReaderTest extends TestCase
{
    #[Test]
    public function responsesComeWholeTheirLiteralsIncluded(): void
    {
        $body = "Subject: x\r\n\r\nline one\r\n* 9 EXISTS\r\n";
        $reader = $this->reader("* 23 EXISTS\r\n* 1 FETCH (UID 7 BODY[] {" . strlen($body) . "}\r\n" . $body . " FLAGS (\\Seen))\r\na1 OK done\r\n");

        self::assertSame('* 23 EXISTS', $reader->next());
        $fetch = $reader->next();
        self::assertSame('* 1 FETCH (UID 7 BODY[] {' . strlen($body) . "}\r\n" . $body . ' FLAGS (\Seen))', $fetch);
        $items = Response::parse($fetch)->data[2]->items();
        self::assertSame($body, $items[3]->text(), 'a line inside a literal is the literal\'s, never a response');
        self::assertSame('a1 OK done', $reader->next());
    }

    #[Test]
    public function twoLiteralsInOneResponse(): void
    {
        $reader = $this->reader("* 2 FETCH (BODY[1] {3}\r\nabc BODY[2] ~{2}\r\n\x00\x01)\r\n");

        self::assertSame("* 2 FETCH (BODY[1] {3}\r\nabc BODY[2] ~{2}\r\n\x00\x01)", $reader->next());
    }

    #[Test]
    public function aConnectionThatEndsInsideALiteralIsClosedNotShort(): void
    {
        $reader = $this->reader("* 1 FETCH (BODY[] {10}\r\nshort");

        $this->expectException(ConnectionClosed::class);
        $this->expectExceptionMessage('inside a literal of 10 bytes');
        $reader->next();
    }

    #[Test]
    public function aConnectionThatEndsInsideALineIsClosed(): void
    {
        $this->expectException(ConnectionClosed::class);
        $this->reader('a1 OK no end')->next();
    }

    #[Test]
    public function aLiteralOverTheLimitIsRefusedBeforeItIsRead(): void
    {
        $reader = $this->reader("* 1 FETCH (BODY[] {999999999999}\r\n", literalLimit: 1024);

        $this->expectException(ProtocolError::class);
        $this->expectExceptionMessage('over the limit of 1024');
        $reader->next();
    }

    #[Test]
    public function aLineOverTheLimitIsRefused(): void
    {
        $reader = $this->reader('* OK ' . str_repeat('x', 100) . "\r\n", lineLimit: 32);

        $this->expectException(ProtocolError::class);
        $this->expectExceptionMessage('longer than 32 bytes');
        $reader->next();
    }

    #[Test]
    public function aServerThatSaysNothingTimesOutItIsNotCalledClosed(): void
    {
        foreach (['' => 'before a line', '* OK half' => 'inside a line', "* 1 FETCH (BODY[] {10}\r\nabc" => 'inside a literal'] as $sent => $where) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($pair);
            [$client, $server] = $pair;
            fwrite($server, $sent);
            stream_set_timeout($client, 0, 50000);
            try {
                new ResponseReader($client)->next();
                self::fail('a silent server is never a response: ' . $where);
            } catch (TimedOut) {
                $this->addToAssertionCount(1);
            } finally {
                fclose($server);
            }
        }
    }

    private function reader(string $bytes, int $lineLimit = 65536, int $literalLimit = 1048576): ResponseReader
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return new ResponseReader($stream, $lineLimit, $literalLimit);
    }
}
