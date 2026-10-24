<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * ⛔ THE DRIFT GUARD FOR THE COORDINATION CLIENT'S MEMBER SET — `docs/design/FLEET-STATE.md
 * § 8.3.3`'s `coord_round`, in that section's own table order, against
 * `public/js/coord/coord-members.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 8.3.3 is a markdown table and `coord-members.js` is a JavaScript array. That is a restatement,
 * and the rule for a restatement a consumer cannot follow a pointer to is DELETE it or GUARD it. A
 * browser cannot read a markdown table, so this file is the guard and it RE-DERIVES the population
 * from the document on every run — its members, its order and its size. ⛔ NO MEMBER COUNT IS
 * WRITTEN HERE (card#12239's build: a figure here went stale the day § 8.3.3 gained `scope`). The
 * parse is checked instead against a second, independent reading of the same document: the worked
 * `coord.round` examples § 8.3.3 carries, whose keys must be the table's members in the table's order.
 *
 * ⛔ ORDER, NOT ONLY SET. A module whose members agree while two have swapped places is a module no
 * comparison of SETS can see is wrong. CONTROL 5 re-mints exactly that.
 *
 * ⛔ THERE IS NO `coord_thread` SET IN THE CLIENT. Since card#12239 `coord.thread` is specified and not
 * published (D2 § 8.3), so the client keeps none and this guard compares none.
 *
 * ⛔ § 5.7's RENDER MAP IS HELD TO D2 IN ONE DIRECTION. Since the operator's ruling of 2026-10-23 every
 * coordination render is an edge fired on arrival, so § 5.7 renders a SUBSET of `coord_round` by design
 * — its property 4 says the two clocks drive no render, and nothing draws `carrier` or `thread_ref`. What
 * stays a defect is the other direction: a member § 5.7 renders that D2 does not declare, the invented
 * field `verify-floor.py` G2 exists for, caught here a second time from the client's side (card#8075
 * shipped an invented `blocked_since` render on exactly this shape).
 */
class CoordMemberSetMatchesTheDocumentTest extends TestCase
{
    use DrivesTheCoordClient;

    public function test_the_clients_member_set_is_section_833s_coord_round_in_its_order(): void
    {
        $document = $this->documentMembers()['coord_round'];

        // A CONTROL ON THE PARSER FIRST — every assertion below is vacuous if the parse returned
        // nothing, or returned the wrong table. The worked examples are the document's own second
        // statement of the member set, so the table parse must equal every one of them.
        $examples = $this->workedExampleMembers('coord_round');

        $this->assertNotSame([], $document, 'the § 8.3.3 parser found no `coord_round` member — it has stopped reading the document');
        $this->assertNotSame([], $examples, '§ 8.3.3 carries no worked `coord_round` example this parse could read, so the table parse is checked against nothing');

        foreach ($examples as $i => $keys) {
            $this->assertSame($keys, $document,
                "the § 8.3.3 table parse disagrees with worked example {$i}'s members — either the parse read the wrong rows or the document contradicts itself");
        }

        $module = $this->probe([])['round_members'];

        // Direction one: a member D2 publishes that the client does not know. It is a fact on the
        // wire that no render can ever reach.
        $this->assertSame([], array_values(array_diff($document, $module)),
            'D2 § 8.3.3 declares a `coord_round` member the client does not know');

        // Direction two: a member the client knows that D2 does not declare — a field the client
        // invented, which is § 1.3 corollary 1's whole subject.
        $this->assertSame([], array_values(array_diff($module, $document)),
            'the client knows a `coord_round` member D2 § 8.3.3 does not declare');

        // And the ORDER.
        $this->assertSame($document, $module, "the client's `coord_round` member ORDER is not D2 § 8.3.3's");
    }

    public function test_section_57s_render_map_names_only_members_d2_declares(): void
    {
        $declared = array_map(fn (string $m): string => "coord_round.{$m}", $this->documentMembers()['coord_round']);
        $rendered = $this->renderedMembers();

        $this->assertNotSame([], $declared, 'the § 8.3.3 parse is empty, so the closure below would run over nothing');
        $this->assertNotSame([], $rendered,
            '§ 5.7’s render map did not parse — its D2-field column would then be compared against nothing');

        $this->assertSame([], array_values(array_diff($rendered, $declared)),
            '§ 5.7 renders a coordination member D2 § 8.3.3 does not declare — a rendered fact with no field is a fact the client invented');
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect and names the assertion above that must
     * catch it. A comparison that has only ever been seen agreeing is not evidence.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $document = $this->documentMembers()['coord_round'];

        // CONTROL 1 — the parse's bounds. Renaming § 8.3.3's closing heading makes `strpos` answer
        // false; the slice must fail outright rather than silently widening.
        $moved = str_replace(self::S833_CLOSE, '### 8.4b Snapshot-then-deltas', $this->fleetStateMd());
        $this->assertSame([], $this->documentMembers($moved)['coord_round'],
            'CONTROL 1 did not bite: § 8.3.3’s closing heading was renamed and the parser still read members, so it is not reading the bounds it claims to');

        // CONTROL 2 — a row DROPPED from the table. The worked examples still carry the member, so
        // the parser-against-example check must red: the guard sees the document contradict itself
        // rather than shrinking its population quietly.
        $dropped = preg_replace('/^\| `coord_round\.carrier`.*$\n/m', '', $this->fleetStateMd(), 1, $n);
        $this->assertSame(1, $n, 'CONTROL 2’s anchor (the `coord_round.carrier` row) is gone — it mutated nothing');
        $this->assertNotSame($this->workedExampleMembers('coord_round', (string) $dropped)[0], $this->documentMembers((string) $dropped)['coord_round'],
            'CONTROL 2 did not bite: a `coord_round` row was removed from § 8.3.3 and the table parse still agreed with the worked example');

        $anchor = "export const COORD_ROUND_MEMBERS = Object.freeze([\n    'post_ref',";

        // CONTROL 3 — a member MISSING from the client (the seat's ruling: plant a missing member once
        // and see it fail). Direction one must red.
        $short = $this->probe([], $this->mutatedModules([
            'coord-members.js', $anchor, 'export const COORD_ROUND_MEMBERS = Object.freeze([',
        ]))['round_members'];
        $this->assertNotSame([], array_diff($document, $short),
            'CONTROL 3 did not bite: `post_ref` was deleted from the client and the D2-declares-a-member-the-client-does-not-know direction stayed clean');

        // CONTROL 4 — an INVENTED member added to the client. Direction two must red. The name is the
        // shape card#8075 actually shipped: a fact a renderer wanted and no wire carries.
        $extra = $this->probe([], $this->mutatedModules([
            'coord-members.js', $anchor, "export const COORD_ROUND_MEMBERS = Object.freeze([\n    'converged',\n    'post_ref',",
        ]))['round_members'];
        $this->assertNotSame([], array_diff($extra, $document),
            'CONTROL 4 did not bite: `converged` — a flag D2 explicitly refuses to publish — was added to the client member set and the client-knows-a-member-D2-does-not-declare direction stayed clean');

        // CONTROL 5 — two members SWAPPED. The set is untouched, so both directions above stay clean
        // and only the ORDER assertion can see it.
        $swapped = $this->probe([], $this->mutatedModules([
            'coord-members.js',
            "Object.freeze([\n    'post_ref',\n    'thread_ref',",
            "Object.freeze([\n    'thread_ref',\n    'post_ref',",
        ]))['round_members'];

        $this->assertSame([], array_values(array_diff($document, $swapped)),
            'CONTROL 5 is not testing what it claims: the swap changed the set, so the order assertion is not the only thing that could catch it');
        $this->assertSame([], array_values(array_diff($swapped, $document)),
            'CONTROL 5 is not testing what it claims: the swap changed the set, so the order assertion is not the only thing that could catch it');
        $this->assertNotSame($document, $swapped, 'CONTROL 5 did not bite: two members were swapped and the ORDER assertion stayed clean');

        // CONTROL 6 — the § 5.7 closure. Render an invented member in § 5.7's D2-field column and the
        // rendered-but-not-declared direction must red.
        $invented = str_replace('| `coord_round.to` |', '| `coord_round.converged` |', $this->floorMd(), $n);
        $this->assertSame(1, $n, 'CONTROL 6’s anchor (§ 5.7’s `coord_round.to` row) is gone — it mutated nothing');
        $this->assertNotSame([], array_values(array_diff($this->renderedMembers($invented), array_map(fn (string $m): string => "coord_round.{$m}", $document))),
            'CONTROL 6 did not bite: § 5.7 was made to render `coord_round.converged` and the rendered-but-not-declared direction stayed clean');
    }
}
