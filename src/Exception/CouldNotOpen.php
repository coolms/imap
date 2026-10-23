<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use RuntimeException;

/** No usable connection: the server could not be reached, refused us, or could not give the security asked for. */
final class CouldNotOpen extends RuntimeException
{
}
