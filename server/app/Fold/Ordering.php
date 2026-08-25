<?php

namespace App\Fold;

/**
 * `D2-MUST` #4's ordering key, in one place.
 *
 * `docs/design/FLEET-STATE.md § 6.5`: "Arrival order for visiting, `(event_time, seq_epoch, seq)`
 * for applying." The fold READS in `events.id` order because `seq` can have permanent holes and a
 * cursor over it could wait forever for an event that will never arrive — but it APPLIES with
 * last-write-wins guarded by this triple, so arrival order decides *when* work happens and never
 * *which value wins*.
 *
 * `seq_epoch` is in the key because `seq` restarts at an epoch reset (D1 § 10.2), so
 * `(event_time, seq)` is not a total order across one: two events a millisecond apart in different
 * epochs can compare backwards. A `seq_epoch` is a ULID and therefore sorts by mint time, so the
 * three-part key is total and reduces to `D2-MUST` #4's two-part key whenever the epoch is
 * constant — which is every comparison except across a reset.
 *
 * Both string operands are compared with `strcmp` rather than parsed: `Y-m-d H:i:s.v` sorts
 * lexicographically iff it sorts chronologically (fixed width, zero-padded, one time zone), and a
 * ULID's Crockford alphabet is defined to sort the same way. Parsing them would buy nothing and
 * would make the comparator depend on a time zone.
 */
final class Ordering
{
    /**
     * @param  array{0: string, 1: string, 2: int}  $a
     * @param  array{0: string, 1: string, 2: int}  $b
     * @return int <0, 0 or >0, `strcmp`-style
     */
    public static function compare(array $a, array $b): int
    {
        return strcmp($a[0], $b[0])
            ?: (strcmp($a[1], $b[1])
                ?: $a[2] <=> $b[2]);
    }

    /**
     * Is the incoming triple strictly newer than the one already applied to a row?
     *
     * STRICTLY, and the strictness is the idempotency: re-applying the SAME event compares equal
     * and is refused, which is § 6.5's mechanism 2 ("applying the same event twice is a no-op
     * regardless") and what makes § 6.6's rebuild safe to run against live tables.
     *
     * @param  array{0: string, 1: string, 2: int}  $incoming
     * @param  array{0: string, 1: string, 2: int}|null  $applied  null = nothing applied yet
     */
    public static function newer(array $incoming, ?array $applied): bool
    {
        return $applied === null || self::compare($incoming, $applied) > 0;
    }
}
