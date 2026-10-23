<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use RuntimeException;

/** The server said nothing for longer than the connection waits: the connection is not closed, but is not usable. */
final class TimedOut extends RuntimeException
{
}
