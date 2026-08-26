<?php

namespace Tests\Feature\Feed;

use App\Feed\SeatDelta;
use App\Fold\SeatFacts;
use App\Read\SeatObject;
use App\Read\Snapshot;

/**
 * ⛔ THE DRIFT GUARD FOR CARD #7827's DENOMINATOR.
 *
 * `docs/design/FLEET-STATE.md § 8.2.1` is a TABLE of field names, and `App\Read\SeatObject` is a
 * PHP array of field names. That is a restatement, and engineering canon's rule for a restatement
 * a consumer cannot follow a pointer to is: DELETE it, or GUARD it. It cannot be deleted — the
 * serializer has to name its keys — so this file is the guard, and it RE-DERIVES the population
 * from the document on every run rather than comparing against a list somebody wrote down once.
 *
 * That property is the whole point. A hand-written expected-key list here would be a THIRD copy,
 * and the first thing three copies do is let two of them agree while the document says something
 * else. The parser below reads § 8.2.1's own table, so the day a field is added to D2 and not to
 * the serializer, this reds — and the day one is added to the serializer and not to D2, it reds
 * the other way.
 *
 * ⚠ WHAT IT DOES NOT CHECK, NAMED so a green here is not read as more than it is: types, bounds,
 * nullability and example values. Those are prose in the same table and are checked, where they
 * are checked at all, by the tests that drive real fixtures through the real fold.
 */
class SeatObjectMatchesTheDocumentTest extends FeedTestCase
{
    public function test_the_seat_object_carries_exactly_the_fields_section_821_declares(): void
    {
        $declared = $this->declaredFields();

        // A CONTROL FIRST — the parser must be capable of returning something wrong. An empty or
        // tiny parse would make every assertion below vacuous, which is the exact false-clean
        // this file exists to prevent: a guard that cannot fail is a decoration.
        $this->assertGreaterThan(50, count($declared),
            'the § 8.2.1 parser found almost nothing — it has stopped reading the document');
        $this->assertContains('derivation.fold_lag_ms', $declared,
            'the parser missed a field the document certainly declares');

        $this->deliver($this->cleanTurn());
        $this->fold();

        $object = SeatObject::forSeatRef($this->seatRef, $this->clockMs);
        $this->assertNotNull($object);

        $actual = $this->flatten($object);

        // Every DECLARED field is on the object. A nested object that is legitimately `null` on
        // this fixture (`retired`, `context`) contributes only its own name, so its members are
        // exempted — the presence of `retired.at` is not assertable on a seat nobody retired.
        $missing = array_values(array_filter(
            $declared,
            fn (string $f) => ! in_array($f, $actual, true) && ! $this->parentIsNull($f, $object),
        ));

        $this->assertSame([], $missing, '§ 8.2.1 declares fields the seat object does not carry');

        // And nothing EXTRA. § 8.1 makes an additive REST member free for a CONSUMER to ignore;
        // it does not make it free for this object, which § 8.2.1 declares field by field and
        // which the delta patches by name.
        $extra = array_values(array_diff($actual, $declared));

        $this->assertSame([], $extra, 'the seat object carries fields § 8.2.1 does not declare');
    }

    /**
     * ⛔ THE SECOND HALF OF THE SAME GUARD: `SeatDelta::WIRE_MEMBER` must cover the fingerprint.
     *
     * `SeatFacts::versionBearing()` decides WHETHER a delta is emitted; `WIRE_MEMBER` decides
     * WHAT it carries. A fingerprint member with no entry in the map is the one defect neither
     * side notices: the seat's state changes, `state_version` bumps, a delta is emitted — and it
     * does not carry the thing that changed. Every client is then permanently wrong about that
     * member until something unrelated moves it, which on a quiet desk is never.
     */
    public function test_the_delta_map_covers_every_version_bearing_member(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $fingerprint = array_keys(SeatFacts::versionBearing($this->seatRef));

        $this->assertNotEmpty($fingerprint, 'the fingerprint is empty — the control failed');

        $this->assertSame(
            [],
            array_values(array_diff($fingerprint, array_keys(SeatDelta::WIRE_MEMBER))),
            'a version-bearing member has no wire member: it would bump the version and not ride the delta',
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys(SeatDelta::WIRE_MEMBER), $fingerprint)),
            'the map names a fingerprint member that no longer exists',
        );

        // Every wire member the map points AT must be a real top-level member of the object, or
        // the patch would carry a key no client has.
        $object = SeatObject::forSeatRef($this->seatRef, $this->clockMs);

        foreach (array_unique(array_values(SeatDelta::WIRE_MEMBER)) as $member) {
            $this->assertArrayHasKey($member, $object, 'the delta map points at a non-existent wire member');
        }
    }

    /** § 8.1: "carries `api_version`" — asserted against the document's own worked snapshot. */
    public function test_the_api_version_matches_the_worked_snapshot(): void
    {
        $doc = file_get_contents($this->d2());

        $this->assertMatchesRegularExpression(
            '/"api_version":\s*'.Snapshot::API_VERSION.'\b/',
            $doc,
            '§ 8.2.2\'s worked snapshot does not carry this api_version',
        );
    }

    // ── the parser ───────────────────────────────────────────────────────────────────────────

    /**
     * § 8.2.1's table's first column, in document order.
     *
     * @return list<string>
     */
    private function declaredFields(): array
    {
        $doc = file_get_contents($this->d2());

        $start = strpos($doc, '#### 8.2.1 The seat-state object');
        $this->assertNotFalse($start, '§ 8.2.1 is not where this parser expects it');

        $end = strpos($doc, '#### 8.2.2', $start);
        $this->assertNotFalse($end, '§ 8.2.2 is not where this parser expects it');

        $table = substr($doc, $start, $end - $start);

        preg_match_all('/^\|\s*`([a-z_]+(?:\.[a-z_]+)?(?:\[\])?)`\s*\|/m', $table, $m);

        // `subagents[].call_id` is how the document spells a member of an array-valued field.
        return array_values(array_unique(array_map(
            fn (string $f) => str_replace('[]', '', $f),
            $m[1],
        )));
    }

    /**
     * The object's own field names, dotted the way § 8.2.1 spells them.
     *
     * @param  array<string, mixed>  $object
     * @return list<string>
     */
    private function flatten(array $object): array
    {
        $out = [];

        foreach ($object as $key => $value) {
            $out[] = $key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                foreach (array_keys($value) as $inner) {
                    $out[] = $key.'.'.$inner;
                }
            }

            // An array-valued field (`subagents`, `badges`, `selftest_failed`) contributes its
            // ELEMENTS' keys only when § 8.2.1 declares them — `subagents[].call_id` does,
            // `badges` is an array of strings and declares none.
            if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                foreach (array_keys($value[0]) as $inner) {
                    $out[] = $key.'.'.$inner;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** A member of a nested object that is legitimately null on this fixture is not "missing". */
    private function parentIsNull(string $field, array $object): bool
    {
        if (! str_contains($field, '.')) {
            return false;
        }

        [$parent] = explode('.', $field, 2);

        return ($object[$parent] ?? null) === null || $object[$parent] === [];
    }

    private function d2(): string
    {
        return base_path('../docs/design/FLEET-STATE.md');
    }
}
