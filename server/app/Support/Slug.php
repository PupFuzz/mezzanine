<?php

namespace App\Support;

/**
 * `docs/design/EVENT-SCHEMA.md § 3.1`'s two identity slugs, stated ONCE for every surface that
 * checks one.
 *
 * The bodies are UNANCHORED on purpose: a route constraint (`Route::where()`) anchors its pattern
 * itself and takes no delimiters, while `preg_match()` needs both — so `pattern()` builds the second
 * spelling from the first rather than a caller keeping a copy of it.
 *
 * Callers: `mezzanine:ingest-token:issue` (where an install and a seat are minted) and
 * `GET /api/building/rooms/{install_id}/map` (`docs/design/FLEET-STATE.md § 8.7`: "The surface
 * answers for any `install_id` that matches D1 § 3.1's slug; one that does not is `404`").
 */
final class Slug
{
    /** D1 § 3.1: `install_id` — `^[a-z0-9][a-z0-9-]{1,31}$`. */
    public const INSTALL_ID = '[a-z0-9][a-z0-9-]{1,31}';

    /** D1 § 3.1: `seat_id` — `^[a-z0-9][a-z0-9-]{1,47}$`. */
    public const SEAT_ID = '[a-z0-9][a-z0-9-]{1,47}';

    /** `$body` anchored and delimited for `preg_match()`. */
    public static function pattern(string $body): string
    {
        return '/^'.$body.'$/';
    }
}
