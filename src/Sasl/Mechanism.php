<?php

declare(strict_types=1);

namespace CoolMS\Imap\Sasl;

/**
 * A SASL mechanism, with no IMAP in it: its name, the response it sends first (null when it waits for a challenge),
 * and its answer to each challenge. Raw bytes both ways -- base64 is the protocol's, not the mechanism's.
 */
interface Mechanism
{
    public function name(): string;

    public function initialResponse(): ?string;

    public function respond(string $challenge): string;
}
