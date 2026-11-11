<?php

namespace App\Building;

use App\Read\SeatIdentity;

/**
 * A floor's web address — the slug of its name (`docs/design/FLOOR.md § 4.6`, card#13117 — the operator's ruling of
 * 2026-11-10: *a floor's web address is its name*). One function, and its fixed points are the addresses:
 *
 *     NFKD, every combining mark (\p{Mn}) dropped  →  A–Z lowered to a–z  →  every maximal run outside [a-z0-9]
 *     becomes one "-"  →  a leading and a trailing "-" stripped
 *
 * so `Thé  Nook!` is `the-nook`, `三階` and `***` are `''` (a name the reader refuses), and a slug is a non-empty
 * string of `[a-z0-9]` runs joined by single hyphens.
 *
 * ⚠ SELF-CONTAINED COPY, KEPT IN LOCKSTEP BY A TEST. `public/js/floor/floor-slug.js` is the browser's copy — the
 * browser cannot import PHP — and `Tests\Feature\Building\TheFloorSlugIsOneFunctionTest` drives both over
 * `tests/fixtures/building/floor-slug-cases.json`. Change one and that test reds until the other follows.
 *
 * ⚠ ONLY ASCII IS LOWERED, deliberately: after NFKD and the marks, nothing outside A–Z lowers into `[a-z0-9]`, and a
 * Unicode case mapping is the one step PHP's and a browser's tables could answer differently on.
 *
 * `Normalizer` is `ext-intl`'s, or `symfony/polyfill-intl-normalizer`'s where the extension is absent — a package
 * the framework already requires, so a host without `intl` still runs this.
 */
final class FloorSlug
{
    /** The slug of `$name`; `''` when the name has no letter or digit a–z 0–9 once accents are folded. */
    public static function of(string $name): string
    {
        $folded = \Normalizer::normalize($name, \Normalizer::FORM_KD);

        // `normalize()` answers false on text that is not valid UTF-8: such a name has no address.
        if ($folded === false) {
            return '';
        }

        $bare = strtr((string) preg_replace('/\p{Mn}/u', '', $folded), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $bare), '-');
    }

    /** Whether `$text` is an address: a non-empty fixed point of `of()`. */
    public static function isSlug(string $text): bool
    {
        return $text !== '' && self::of($text) === $text;
    }

    /** The one address no floor may be named into or keep as an alias: the Unassigned floor's (§ 4.4 of the plan). */
    public const RESERVED = SeatIdentity::UNASSIGNED;
}
