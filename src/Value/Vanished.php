<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use CoolMS\Imap\Exception\ProtocolError;
use CoolMS\Imap\Protocol\Response;
use CoolMS\Imap\Protocol\TokenKind;
use InvalidArgumentException;

use function count;
use function in_array;
use function strtoupper;

/**
 * A VANISHED response (RFC 7162 s.3.2.10): the UIDs expunged. EARLIER marks a reply to a QRESYNC SELECT or a FETCH
 * with VANISHED -- deletions from before now -- and its absence one that happened while the folder was open. Kept as
 * a set, never expanded: a server may name a million UIDs in one range.
 */
final readonly class Vanished
{
    public function __construct(
        public UidSet $uids,
        public bool $earlier,
    ) {
    }

    public static function fromResponse(Response $vanished): self
    {
        $data = $vanished->data;
        if ([] === $data || TokenKind::Atom !== $data[0]->kind || 'VANISHED' !== strtoupper($data[0]->string())) {
            throw new ProtocolError('VANISHED', 0, 'not a VANISHED response');
        }
        $earlier = false;
        $at = 1;
        if (isset($data[1]) && TokenKind::List === $data[1]->kind) {
            $tags = $data[1]->items();
            if (
                1 !== count($tags)
                || TokenKind::Atom !== $tags[0]->kind
                || 'EARLIER' !== strtoupper($tags[0]->string())
            ) {
                throw new ProtocolError('VANISHED', 0, 'the only tag VANISHED takes is (EARLIER)');
            }
            $earlier = true;
            $at = 2;
        }
        $set = $data[$at] ?? null;
        if (
            null === $set
            || count($data) !== $at + 1
            || !in_array($set->kind, [TokenKind::Atom, TokenKind::Number], true)
        ) {
            throw new ProtocolError('VANISHED', 0, 'VANISHED names one set of UIDs');
        }

        try {
            return new self(UidSet::parse($set->string()), $earlier);
        } catch (InvalidArgumentException $notASet) {
            throw new ProtocolError($set->string(), 0, $notASet->getMessage());
        }
    }
}
