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

    /**
     * The **wire** spelling of a stored `DATETIME(3)`: `docs/design/FLEET-STATE.md § 8.2.1`'s
     * `rfc3339_ms` — `2026-08-23T14:23:14.201Z`, always three fractional digits, always `Z`.
     *
     * It lives here, beside the two conversions it is the third of, because a second spelling of
     * "how this project writes a timestamp on the wire" is a second thing free to disagree — and
     * the first thing the two would disagree about is whether a whole second carries `.000`,
     * which is exactly the SQLite second form `toMs()` above already had to absorb.
     */
    public static function wire(?string $sql): ?string
    {
        $ms = self::toMs($sql);

        return $ms === null ? null : self::wireFromMs($ms);
    }

    /**
     * The INVERSE of `wire()`: § 8.2.1's `rfc3339_ms` back to a stored `DATETIME(3)` value.
     *
     * It is here rather than at its caller for the reason the class docblock gives about `wire()`
     * itself — the conversion the other way was already being written inline as a `str_replace()`
     * of `T` and `Z`, which is a second, looser opinion about what an `rfc3339_ms` value is: it
     * accepts `2026-08-23 14:23:14.201`, `…T…` without the `Z`, and a bare date with neither.
     * A parameter a client round-trips through this server (`App\Read\TimelineCursor`) must be
     * read by the exact inverse of what wrote it, or the two drift by whatever the looser one
     * additionally admits.
     *
     * @throws \InvalidArgumentException on anything that is not the spelling `wire()` emits
     */
    public static function fromWire(string $wire): string
    {
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z', $wire, new \DateTimeZone('UTC'),
        );

        if ($dt === false) {
            throw new \InvalidArgumentException('not an rfc3339_ms value: '.$wire);
        }

        return $dt->format(self::FORMAT);
    }

    public static function wireFromMs(int $ms): string
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))
            ->modify('+'.($ms % 1000).' milliseconds')
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
