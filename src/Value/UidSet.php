<?php

declare(strict_types=1);

namespace CoolMS\Imap\Value;

use InvalidArgumentException;

use function abs;
use function count;
use function explode;
use function implode;
use function max;
use function min;
use function preg_match;
use function sort;
use function usort;

/**
 * A set of UIDs as IMAP writes it (RFC 9051 s.9, sequence-set): "1:5,7,9:12". Built from UIDs it is written in its
 * shortest form, ranges merged; read from a server -- COPYUID's lists, a SEARCH answer -- it is expanded in order. "*"
 * (the largest UID in the folder) has no value of its own here: a set sent with it is built by {@see fromOnward()},
 * and one read with it is refused, since only the server knows what it stands for.
 */
final readonly class UidSet
{
    /** @param list<array{int, ?int}> $ranges first and last; a null last is "*" */
    private function __construct(private array $ranges)
    {
    }

    /** @param list<int> $uids */
    public static function of(array $uids): self
    {
        $ranges = [];
        $sorted = $uids;
        sort($sorted);
        foreach ($sorted as $uid) {
            self::positive($uid);
            $last = count($ranges) - 1;
            if ($last >= 0 && $uid <= $ranges[$last][1] + 1) {
                $ranges[$last] = [$ranges[$last][0], max($ranges[$last][1], $uid)];

                continue;
            }
            $ranges[] = [$uid, $uid];
        }
        if ([] === $ranges) {
            throw new InvalidArgumentException('A UID set holds at least one UID.');
        }

        return new self($ranges);
    }

    /** "n:*": every UID from n on -- what a fetch of new mail asks for. */
    public static function fromOnward(int $first): self
    {
        self::positive($first);

        return new self([[$first, null]]);
    }

    /** A set as a server wrote it; "*" refused. */
    public static function parse(string $set): self
    {
        if (1 !== preg_match('/\A[0-9]+(?::[0-9]+)?(?:,[0-9]+(?::[0-9]+)?)*\z/', $set)) {
            throw new InvalidArgumentException('"' . $set . '" is not a set of UIDs.');
        }
        // Merged as ranges, never expanded: "1:4294967295" from a broken server costs one range, not four billion UIDs.
        $ranges = [];
        foreach (explode(',', $set) as $part) {
            $ends = explode(':', $part);
            $a = (int) $ends[0];
            $b = (int) ($ends[1] ?? $ends[0]);
            self::positive($a);
            self::positive($b);
            $ranges[] = [min($a, $b), max($a, $b)];
        }
        usort($ranges, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $at = count($merged) - 1;
            if ($at >= 0 && $first <= $merged[$at][1] + 1) {
                $merged[$at] = [$merged[$at][0], max($merged[$at][1], $last)];

                continue;
            }
            $merged[] = [$first, $last];
        }

        return new self($merged);
    }

    /**
     * The UIDs in order, as a server listed them -- COPYUID pairs its source and destination sets by position, so
     * the order of a parsed set is the order written, not sorted. At most $max UIDs: a caller knows how many it moved,
     * and a set naming more is refused before it is expanded.
     *
     * @return list<int>
     */
    public static function listed(string $set, int $max): array
    {
        $count = 0;
        foreach (explode(',', $set) as $part) {
            $ends = explode(':', $part);
            $count += abs((int) $ends[0] - (int) ($ends[1] ?? $ends[0])) + 1;
        }
        if ($count > $max) {
            throw new InvalidArgumentException('"' . $set . '" names ' . $count . ' UIDs, more than the ' . $max . ' asked for.');
        }
        if (1 !== preg_match('/\A[0-9]+(?::[0-9]+)?(?:,[0-9]+(?::[0-9]+)?)*\z/', $set)) {
            throw new InvalidArgumentException('"' . $set . '" is not a set of UIDs.');
        }
        $uids = [];
        foreach (explode(',', $set) as $part) {
            $ends = explode(':', $part);
            $a = (int) $ends[0];
            $b = (int) ($ends[1] ?? $ends[0]);
            $step = $a <= $b ? 1 : -1;
            for ($uid = $a;; $uid += $step) {
                self::positive($uid);
                $uids[] = $uid;
                if ($uid === $b) {
                    break;
                }
            }
        }

        return $uids;
    }

    public function toString(): string
    {
        $parts = [];
        foreach ($this->ranges as [$first, $last]) {
            $parts[] = match (true) {
                null === $last => $first . ':*',
                $first === $last => (string) $first,
                default => $first . ':' . $last,
            };
        }

        return implode(',', $parts);
    }

    private static function positive(int $uid): void
    {
        if ($uid < 1 || $uid > 4294967295) {
            throw new InvalidArgumentException('A UID is a number from 1 to 4294967295; ' . $uid . ' is not.');
        }
    }
}
