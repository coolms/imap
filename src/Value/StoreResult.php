<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

/**
 * What one UID STORE gave (RFC 7162 s.3.1.3): the messages whose flags the server reported, and with UNCHANGEDSINCE
 * the UIDs it did NOT change because another client changed them first -- the [MODIFIED ...] code. Those the caller
 * reads again before it decides; a store is never repeated over a change it has not seen.
 */
final readonly class StoreResult
{
    /** @param list<FetchedItems> $reported */
    public function __construct(
        public array $reported,
        public ?UidSet $notChanged,
    ) {
    }
}
