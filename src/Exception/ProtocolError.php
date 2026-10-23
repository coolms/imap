<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use RuntimeException;

use function sprintf;
use function substr;

/** A server response that does not parse, named with where and why; the bytes near the place are quoted. */
final class ProtocolError extends RuntimeException
{
    public function __construct(
        public readonly string $response,
        public readonly int $offset,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'The server response does not parse at byte %d: %s (near "%s").',
            $offset,
            $reason,
            substr($response, $offset, 40),
        ));
    }
}
