/**
 * A floor's web address — the slug of its name (`docs/design/FLOOR.md § 4.6`, card#13117): NFKD with every combining
 * mark dropped, A–Z lowered, every maximal run outside `[a-z0-9]` one `-`, a leading and a trailing `-` stripped.
 *
 * ⚠ SELF-CONTAINED COPY, KEPT IN LOCKSTEP BY A TEST. `App\Building\FloorSlug` is the server's copy and the one the
 * reader keys floors by; this one only shows a name's address before it is saved — the client never resolves a
 * slug it did not receive from `GET /api/building`, so the two cannot disagree about a route.
 * `Tests\Feature\Building\TheFloorSlugIsOneFunctionTest` drives both over
 * `tests/fixtures/building/floor-slug-cases.json`. Change one and that test reds until the other follows.
 *
 * ⚠ ONLY ASCII IS LOWERED, as in the PHP copy: a Unicode case mapping is the step two runtimes' tables could
 * answer differently on, and after NFKD nothing outside A–Z lowers into `[a-z0-9]`.
 *
 * @param {string} name
 * @returns {string} the slug, or `''` when the name has no letter or digit a–z 0–9 once accents are folded
 */
export function floorSlug(name) {
    return String(name)
        .normalize('NFKD')
        .replace(/\p{Mn}/gu, '')
        .replace(/[A-Z]/g, (c) => c.toLowerCase())
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
