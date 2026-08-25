<?php

namespace App\Fold;

/**
 * `DATETIME(3)` ↔ milliseconds, in one place.
 *
 * Every timestamp in this schema is `DATETIME(3)`, UTC, and never `TIMESTAMP` — because MySQL
 * converts a `TIMESTAMP` by session time zone and does not convert a `DATETIME`
 * (`docs/design/FLEET-STATE.md § 6.3`). The store hands them back as `Y-m-d H:i:s.v` strings on
 * both engines, so every age arithmetic in the fold goes through here rather than through a
 * `strtotime` at the call site, which would silently pick up the process time zone.
 */
final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s.v';

    public static function sql(\DateTimeInterface $at): string
    {
        return $at->format(self::FORMAT);
    }

    /** Milliseconds since the epoch, from a stored `DATETIME(3)` value. */
    public static function toMs(?string $sql): ?int
    {
        if ($sql === null || $sql === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $sql, new \DateTimeZone('UTC'))
            // A `DATETIME(3)` whose fractional part is exactly zero comes back from SQLite as
            // `Y-m-d H:i:s` with no `.000`, which the strict format above will not parse. This is
            // not a tolerance for arbitrary shapes — it is the one documented second form of the
            // same column, and letting it return null would put a hole in a total function.
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $sql, new \DateTimeZone('UTC'));

        if ($dt === false) {
            throw new \InvalidArgumentException('not a DATETIME(3) value: '.$sql);
        }

        return (int) round((float) $dt->format('U.u') * 1000);
    }

    /** A stored `DATETIME(3)` value, from milliseconds since the epoch. */
    public static function fromMs(int $ms): string
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))
            ->modify('+'.($ms % 1000).' milliseconds')
            ->format(self::FORMAT);
    }
}
