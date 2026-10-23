<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Token;
use CoolMS\Imap\Protocol\TokenKind;
use InvalidArgumentException;

use function array_combine;
use function count;
use function strtoupper;

/**
 * A COPYUID code (RFC 4315 s.3): the destination folder's UIDVALIDITY, and the UIDs the moved messages had and now
 * have, paired by position. The sets are kept as the server wrote them, and paired only up to the number moved.
 */
final readonly class CopyUid
{
    private function __construct(
        public int $uidValidity,
        public string $source,
        public string $destination,
    ) {
    }

    /** From a status response's code, when it is a COPYUID; null otherwise. */
    public static function fromCode(?Token $code): ?self
    {
        $items = $code?->items() ?? [];
        if (
            [] === $items
            || TokenKind::Atom !== $items[0]->kind
            || 'COPYUID' !== strtoupper($items[0]->string())
        ) {
            return null;
        }
        if (4 !== count($items) || TokenKind::Number !== $items[1]->kind) {
            throw new ProtocolError('COPYUID', 0, 'COPYUID is "COPYUID uidvalidity source destination"');
        }

        return new self((int) $items[1]->value, $items[2]->string(), $items[3]->string());
    }

    /**
     * Old UID to new, for at most $moved messages -- the number the caller asked to move.
     *
     * @return array<int, int>
     */
    public function pairs(int $moved): array
    {
        try {
            $from = UidSet::listed($this->source, $moved);
            $to = UidSet::listed($this->destination, $moved);
        } catch (InvalidArgumentException $broken) {
            throw new ProtocolError($this->source . ' ' . $this->destination, 0, $broken->getMessage());
        }
        if (count($from) !== count($to)) {
            throw new ProtocolError($this->source . ' ' . $this->destination, 0, 'COPYUID pairs two sets of different sizes');
        }

        return array_combine($from, $to);
    }
}
