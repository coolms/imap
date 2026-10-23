<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

/**
 * What one UID FETCH gave: the messages, and -- asked with VANISHED (RFC 7162 s.3.2.6) -- the UIDs expunged since
 * the mod-sequence given, each VANISHED response's set in the order it came.
 */
final readonly class FetchResult
{
    /**
     * @param list<FetchedItems> $messages
     * @param list<Vanished>     $vanished
     */
    public function __construct(
        public array $messages,
        public array $vanished,
    ) {
    }
}
