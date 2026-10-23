<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Value\Capabilities;

/** A connection ready for authentication -- or, after PREAUTH, already authenticated -- with what the server can do. */
final readonly class Opened
{
    public function __construct(
        public Connection $connection,
        public Response $greeting,
        public Capabilities $capabilities,
    ) {
    }

    public function preauthenticated(): bool
    {
        return 'PREAUTH' === $this->greeting->status;
    }
}
