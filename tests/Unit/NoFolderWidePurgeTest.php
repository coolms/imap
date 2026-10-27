<?php

declare(strict_types=1);

namespace CoolMS\Imap\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function in_array;
use function is_array;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function strtoupper;
use function substr;
use function token_get_all;
use function trim;

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
            PHP;

        self::assertSame(['2 CLOSE', '3 EXPUNGE', '4 CLOSE', '5 EXPUNGE', '6 EXPUNGE', '7 ->expunge()'], self::sites($source));
    }

    /** @return list<string> each line that sends CLOSE or EXPUNGE, and which */
    private static function sites(string $source): array
    {
        $sites = [];
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $text = strtoupper(trim(substr($token[1], 1, -1)));
                if (in_array($text, ['CLOSE', 'EXPUNGE', 'UID EXPUNGE'], true)) {
                    $sites[] = sprintf('%d %s', $token[2], 'CLOSE' === $text ? 'CLOSE' : 'EXPUNGE');
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
