<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use RuntimeException;

/** The connection ended where a response was due: what was read is not handed on. */
final class ConnectionClosed extends RuntimeException
{
}
