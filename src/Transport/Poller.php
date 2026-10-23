<?php

declare(strict_types=1);

namespace CoolMS\Imap\Transport;

/**
 * Waits until one of several streams can be read. IoPoller is the one a Watch uses; the interface is the seam a test
 * stands a poll() of its own in, to show what a native poll() on the socket does and does not see.
 */
interface Poller
{
    /**
     * @param array<string, resource> $streams by the key the caller knows each one by
     *
     * @return list<string> the keys of the streams that can be read; [] when $seconds passed first
     */
    public function readable(array $streams, float $seconds): array;
}
