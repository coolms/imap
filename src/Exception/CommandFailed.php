<?php

declare(strict_types=1);

namespace CoolMS\Imap\Exception;

use CoolMS\Imap\Protocol\Response;
use RuntimeException;

use function sprintf;
use function strtoupper;

/**
 * A command the server answered NO or BAD. The message names the command in its log form -- no secret, no literal's
 * bytes -- and the server's own words; the code, e.g. [AUTHENTICATIONFAILED] or [NONEXISTENT], is on the completion.
 */
final class CommandFailed extends RuntimeException
{
    public function __construct(public readonly string $command, public readonly Response $completion)
    {
        parent::__construct(sprintf('%s: %s %s', $command, (string) $completion->status, $completion->text));
    }

    /** The response code's name, upper-cased; null when the server gave none. */
    public function code(): ?string
    {
        $items = $this->completion->code?->items() ?? [];

        return [] === $items ? null : strtoupper($items[0]->string());
    }
}
