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
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                    $text = strtoupper(trim(substr($token[1], 1, -1)));
                    if (in_array($text, ['CLOSE', 'EXPUNGE', 'UID EXPUNGE'], true)) {
                        $sites[] = sprintf('%s:%d %s', $file->getFilename(), $token[2], $text);
                    }
                }
                $next = $tokens[$i + 1] ?? null;
                if (T_OBJECT_OPERATOR === $token[0] && is_array($next) && 'expunge' === strtolower($next[1])) {
                    $sites[] = sprintf('%s:%d ->expunge()', $file->getFilename(), $token[2]);
                }
            }
        }

        self::assertSame([], $sites, 'the library deletes no mail');
    }
}
