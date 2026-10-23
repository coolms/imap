<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

enum Security
{
    /** TLS from the first byte -- port 993 (RFC 8314, the one to prefer). */
    case Tls;

    /** Clear text until STARTTLS -- port 143; refused if the server does not offer it, never carried on in clear. */
    case StartTls;

    /** No TLS at all: a test server on a private network, never a real mailbox. */
    case PlainForTestsOnly;
}
