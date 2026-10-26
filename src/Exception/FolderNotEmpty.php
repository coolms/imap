<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use CoolMS\Imap\Value\FolderName;
use RuntimeException;

use function sprintf;

/**
 * A folder was not deleted because it holds mail or other folders. It was put back under its own name; when even that
 * failed, `leftAt` names where it is, so nothing it holds is lost from sight.
 */
final class FolderNotEmpty extends RuntimeException
{
    public function __construct(
        public readonly FolderName $folder,
        public readonly int $messages,
        public readonly int $folders,
        public readonly ?FolderName $leftAt = null,
    ) {
        parent::__construct(sprintf(
            '%s holds %d message(s) and %d folder(s), so it is not deleted%s',
            $folder->display,
            $messages,
            $folders,
            null === $leftAt ? '' : sprintf('; it could not be put back and is at %s', $leftAt->display),
        ));
    }
}
