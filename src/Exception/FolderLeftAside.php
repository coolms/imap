<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use CoolMS\Imap\Value\FolderName;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * A folder renamed aside to be looked at could not be put back under its own name: it is at `leftAt`, with everything
 * it holds. The failure that stopped the look is the previous exception.
 */
final class FolderLeftAside extends RuntimeException
{
    public function __construct(
        public readonly FolderName $folder,
        public readonly FolderName $leftAt,
        Throwable $previous,
    ) {
        parent::__construct(sprintf('%s could not be put back and is at %s', $folder->display, $leftAt->display), 0, $previous);
    }
}
