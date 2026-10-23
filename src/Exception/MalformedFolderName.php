<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use InvalidArgumentException;

use function sprintf;

/** A folder name that is not what it claims to be, named with the reason. */
final class MalformedFolderName extends InvalidArgumentException
{
    public function __construct(public readonly string $name, public readonly string $reason)
    {
        parent::__construct(sprintf('The folder name "%s" is malformed: %s.', $name, $reason));
    }
}
