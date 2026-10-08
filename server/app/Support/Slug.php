<?php

namespace App\Support;

/**
 * `docs/design/EVENT-SCHEMA.md § 3.1`'s identity slugs, stated ONCE for every server surface
 * that checks one. § 3.1 owns the patterns. The fleet reporter carries its own copy
 * (`fleet-reporter/fleet-reporter.js`, `SLUG_INSTALL`, `SLUG_SEAT` and `AGENT_NAME_RE`), which
 * nothing here keeps in step.
 *
 * The bodies are UNANCHORED on purpose: a route constraint (`Route::where()`) anchors its pattern
 * itself and takes no delimiters, while `preg_match()` needs both — so `Anchored::pattern()`
 * builds the second spelling from the first rather than a caller keeping a copy of it.
 *
 * Callers: `mezzanine:ingest-token:issue` (where an install and a seat are minted),
 * `GET /api/building/rooms/{install_id}/map` (`docs/design/FLEET-STATE.md § 8.7`: "The surface
 * answers for any `install_id` that matches D1 § 3.1's slug; one that does not is `404`"), and
 * `App\Floor\FloorMap`'s `reserved_for` (`AGENT_NAME`).
 */
final class Slug
{
    /** D1 § 3.1: `install_id` — `^[a-z0-9][a-z0-9-]{1,31}$`. */
    public const INSTALL_ID = '[a-z0-9][a-z0-9-]{1,31}';

    /** D1 § 3.1: `seat_id` — `^[a-z0-9][a-z0-9-]{1,47}$`. */
    public const SEAT_ID = '[a-z0-9][a-z0-9-]{1,47}';

    /**
     * D1 § 3.1's `protocol_agent_name`: the `slug` vocabulary of D1 § 6.0 (lowercase `[a-z0-9-]`,
     * so a character is a byte) under the 48 B bound D1 § 6.14's row states. A ROLE a room map
     * reserves a desk for (`docs/design/FLOOR.md § 10.3`, card#11144) is a roster name of the same
     * shape, so it is checked against this one pattern. The fleet reporter's copy is
     * `AGENT_NAME_RE`.
     */
    public const AGENT_NAME = '[a-z0-9-]{1,48}';
}
