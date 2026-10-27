<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function is_array;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function substr;
use function token_get_all;

/**
 * This library deletes no mail: it sends no CLOSE, which expunges a folder selected read-write, and no EXPUNGE,
 * which removes every message flagged \Deleted in the folder, another client's too. Read from the sources, by tokens,
 * so a comment that names them is not a send.
 */
final class NoFolderWidePurgeTest extends TestCase
{
    #[Test]
    public function noSourceSendsCloseOrExpunge(): void
    {
        $sites = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            foreach (self::sites((string) file_get_contents($file->getPathname())) as $site) {
                $sites[] = $file->getFilename() . ':' . $site;
            }
        }

        self::assertSame([], $sites, 'the library deletes no mail');
    }

    #[Test]
    public function aCommandIsFoundHoweverItIsWritten(): void
    {
        $source = <<<'PHP'
            <?php
            $this->run(new Command('CLOSE'));
            $this->run(new Command('UID', atom('EXPUNGE')));
            $this->send($tag . " CLOSE\r\n");
            $this->send("$tag EXPUNGE\r\n");
            $this->send("$tag UID EXPUNGE 4\r\n");
            $connection->expunge();
            $this->send($tag . " IDLE\r\n");
            $this->send("DONE\r\n");
            $reply = Response::parse("* 1 EXPUNGE\r\n");
            PHP;

        self::assertSame(['2 CLOSE', '3 EXPUNGE', '4 CLOSE', '5 EXPUNGE', '6 EXPUNGE', '7 ->expunge()'], self::sites($source));
    }

    /**
     * Each line that sends CLOSE or EXPUNGE, and which: a string, quoted or a part of an interpolated one, with a line
     * whose command is CLOSE, EXPUNGE or UID EXPUNGE (at the line's start, or after one tag; never a server's untagged
     * `* 1 EXPUNGE`), and a call of `->expunge()`. Capitals only, as a command is written.
     *
     * @return list<string>
     */
    private static function sites(string $source): array
    {
        $sites = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $token[0] || T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                $text = T_CONSTANT_ENCAPSED_STRING === $token[0] ? substr($token[1], 1, -1) : $token[1];
                foreach (preg_split('/\\\\r\\\\n|\\\\n|\r?\n/', $text) ?: [] as $line) {
                    if (1 === preg_match('/^\s*(?:(?!\*)\S+\s+)?(?:UID\s+)?(CLOSE|EXPUNGE)(?![A-Za-z])/', $line, $match)) {
                        $sites[] = sprintf('%d %s', $token[2], $match[1]);
                    }
                }
            }
            $next = $tokens[$i + 1] ?? null;
            if (T_OBJECT_OPERATOR === $token[0] && is_array($next) && 'expunge' === strtolower($next[1])) {
                $sites[] = sprintf('%d ->expunge()', $token[2]);
            }
        }

        return $sites;
    }
}
