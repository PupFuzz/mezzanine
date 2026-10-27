<?php

namespace App\Characters;

/**
 * The character generator's draw, ported once — `resources/characters/seed.js`'s `fnv1a32`, `mix32` and
 * `draw()` (card#12443, `docs/design/FLEET-STATE.md § 6.13`).
 *
 * ⛔ A PORT, HELD TO ITS SOURCE. The server needs one value the browser computes: the species a key DRAWS,
 * which is the allocation's preference order. `Tests\Feature\Fleet\TheSpeciesListHasOneSourceTest` holds this
 * class equal to what `node` computes over every key of `tools/characters/roster.json` and to the four `h`
 * values `docs/design/FLOOR.md § 3.2` publishes — the binding `tools/design/verify-floor.py`'s Python copy
 * already has.
 *
 * ⚠ 32-BIT ARITHMETIC ON A 64-BIT INT. JavaScript's `Math.imul(a, b) >>> 0` is the low 32 bits of the product.
 * FNV's prime is under 2^25, so `h * prime` stays under 2^57 and is exact here; `mix32`'s two constants are
 * over 2^31, so a direct `h * c` reaches 2^63 and PHP would turn it into a float and lose the low bits. `imul()`
 * below multiplies by the constant's two 16-bit halves instead, each product under 2^48.
 */
final class Draw
{
    private const OFFSET = 2166136261;

    private const PRIME = 16777619;

    private const MASK = 0xFFFFFFFF;

    /** FNV-1a, 32-bit, over the UTF-8 bytes — `seed.js`'s `fnv1a32`, FLOOR.md § 3.2's hash. */
    public static function fnv1a32(string $s): int
    {
        $h = self::OFFSET;

        foreach (unpack('C*', $s) ?: [] as $byte) {
            $h ^= $byte;
            $h = ($h * self::PRIME) & self::MASK;
        }

        return $h;
    }

    /** Murmur3's fmix32 avalanche — `seed.js`'s `mix32`. */
    public static function mix32(int $h): int
    {
        $h = ($h ^ ($h >> 16)) & self::MASK;
        $h = self::imul($h, 0x85EBCA6B);
        $h = ($h ^ ($h >> 13)) & self::MASK;
        $h = self::imul($h, 0xC2B2AE35);

        return ($h ^ ($h >> 16)) & self::MASK;
    }

    /** One independent draw for one named field — `seed.js`'s `draw(key, field)`. */
    public static function draw(string $key, string $field): int
    {
        return self::mix32(self::fnv1a32($key.'#'.$field));
    }

    /**
     * `seed.js`'s `pick(key, field, choices)`.
     *
     * @template T
     *
     * @param  list<T>  $choices
     * @return T
     */
    public static function pick(string $key, string $field, array $choices): mixed
    {
        return $choices[self::draw($key, $field) % count($choices)];
    }

    /** The low 32 bits of `$a * $b`, for two uint32 values, without leaving the integer range. */
    private static function imul(int $a, int $b): int
    {
        $lo = $a * ($b & 0xFFFF);
        $hi = (($a * ($b >> 16)) & 0xFFFF) << 16;

        return ($lo + $hi) & self::MASK;
    }
}
