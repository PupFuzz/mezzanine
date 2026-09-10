<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * ⛔ THE DRIFT GUARD FOR THE COORDINATION CLIENT'S TWO MEMBER SETS — `docs/design/FLEET-STATE.md
 * § 8.3.3`'s `coord_thread` and `coord_round`, each in that section's own table order, against
 * `public/js/coord/coord-members.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 8.3.3 is two markdown tables and `coord-members.js` is two JavaScript arrays. That is a
 * restatement, and the rule for a restatement a consumer cannot follow a pointer to is DELETE it
 * or GUARD it. A browser cannot read a markdown table, so this file is the guard and it
 * RE-DERIVES both populations from the document on every run. A hand-written expected list here
 * would be a THIRD copy, and the first thing three copies do is let two of them agree while the
 * document says something else.
 *
 * ⛔ ORDER, NOT ONLY SET. `docs/design/FLOOR.md § 5.7`'s render map walks these in D2's order,
 * so a module whose members agree while two have swapped places is a module no comparison of
 * SETS can see is wrong. CONTROL 4 re-mints exactly that.
 *
 * ⛔ AND § 5.7 IS CLOSED AGAINST THE SAME POPULATION, BOTH WAYS. A member D2 declares that no
 * § 5.7 row renders is a fact the floor drops silently; a member § 5.7 renders that D2 does not
 * declare is the invented-field defect `verify-floor.py` G2 exists for, caught here a second
 * time from the client's side. Neither direction is a paranoia case: card#8075 shipped an
 * invented `blocked_since` render on exactly this shape.
 */
class CoordMemberSetMatchesTheDocumentTest extends TestCase
{
    use DrivesTheCoordClient;

    public function test_the_clients_member_sets_are_section_833s_two_objects_in_its_order(): void
    {
        $document = $this->documentMembers();

        // A CONTROL ON THE PARSER FIRST — every assertion below is vacuous if the parse returned
        // nothing, which is the exact false-clean this file exists to prevent. Eleven each is
        // D2's own shape: two objects, eleven fields apiece.
        $this->assertCount(11, $document['coord_thread'],
            'the § 8.3.3 parser did not find eleven `coord_thread` members — it has stopped reading the document');
        $this->assertCount(11, $document['coord_round'],
            'the § 8.3.3 parser did not find eleven `coord_round` members — it read one of the section’s two tables and not the other');
        $this->assertSame('thread_ref', $document['coord_thread'][0],
            'the § 8.3.3 parser found something, but not the table it was aimed at');

        $probe = $this->probe([]);

        foreach (['coord_thread' => 'thread_members', 'coord_round' => 'round_members'] as $object => $key) {
            $module = $probe[$key];

            // Direction one: a member D2 publishes that the client does not know. It is a fact
            // on the wire that no render can ever reach.
            $this->assertSame([], array_values(array_diff($document[$object], $module)),
                "D2 § 8.3.3 declares a `{$object}` member the client does not know");

            // Direction two: a member the client knows that D2 does not declare — a field the
            // client invented, which is § 1.3 corollary 1's whole subject.
            $this->assertSame([], array_values(array_diff($module, $document[$object])),
                "the client knows a `{$object}` member D2 § 8.3.3 does not declare");

            // And the ORDER, which is what § 5.7's render map walks.
            $this->assertSame($document[$object], $module,
                "the client's `{$object}` member ORDER is not D2 § 8.3.3's");
        }
    }

    public function test_section_57s_render_map_names_exactly_the_members_d2_declares(): void
    {
        $document = $this->documentMembers();
        $expected = array_merge(
            array_map(fn (string $m): string => "coord_thread.{$m}", $document['coord_thread']),
            array_map(fn (string $m): string => "coord_round.{$m}", $document['coord_round']),
        );

        $rendered = $this->renderedMembers();

        $this->assertCount(22, $expected,
            'the § 8.3.3 parse is not twenty-two members, so the closure below would run over the wrong population');
        $this->assertNotSame([], $rendered,
            '§ 5.7’s render map did not parse — its D2-field column would then be compared against nothing');

        sort($expected);
        $actual = $rendered;
        sort($actual);

        $this->assertSame([], array_values(array_diff($expected, $actual)),
            'D2 § 8.3.3 declares a coordination member that no § 5.7 row renders — the floor drops a wire fact silently');
        $this->assertSame([], array_values(array_diff($actual, $expected)),
            '§ 5.7 renders a coordination member D2 § 8.3.3 does not declare — a rendered fact with no field is a fact the client invented');
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect and names the assertion above that must
     * catch it. A comparison that has only ever been seen agreeing is not evidence.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the parse itself. Renaming § 8.3.3's closing heading makes `strpos`
        // answer false; the slice must fail outright rather than silently widening.
        $moved = str_replace(self::S833_CLOSE, '### 8.4b Snapshot-then-deltas', $this->fleetStateMd());
        $this->assertNotCount(11, $this->documentMembers($moved)['coord_thread'],
            'CONTROL 1 did not bite: § 8.3.3’s closing heading was renamed and the parser still '
            .'reported eleven members, so the parse is not reading the bounds it claims to');

        // CONTROL 2 — the SECOND table under one heading. D2 declares two objects there; a
        // reader that stops at the first reports clean over half the surface.
        $halved = preg_replace(
            '/^\| `coord_round\.[a-z_]+`.*$/m',
            '',
            $this->fleetStateMd(),
        );
        $this->assertNotCount(11, $this->documentMembers((string) $halved)['coord_round'],
            'CONTROL 2 did not bite: every `coord_round` row was removed from § 8.3.3 and the '
            .'parser still reported eleven, so it is not reading the second table at all');

        $anchor = "export const COORD_THREAD_MEMBERS = Object.freeze([\n    'thread_ref',";

        // CONTROL 3 — a member DROPPED from the client. Direction one must red.
        $short = $this->probe([], $this->mutatedModules([
            'coord-members.js', $anchor, 'export const COORD_THREAD_MEMBERS = Object.freeze([',
        ]))['thread_members'];
        $this->assertNotSame([], array_diff($this->documentMembers()['coord_thread'], $short),
            'CONTROL 3 did not bite: `thread_ref` was deleted from the client and the '
            .'D2-declares-a-member-the-client-does-not-know direction stayed clean');

        // CONTROL 4 — an INVENTED member added to the client. Direction two must red. The name
        // is the shape card#8075 actually shipped: a fact a renderer wanted and no wire carries.
        $extra = $this->probe([], $this->mutatedModules([
            'coord-members.js', $anchor, "export const COORD_THREAD_MEMBERS = Object.freeze([\n    'converged',\n    'thread_ref',",
        ]))['thread_members'];
        $this->assertNotSame([], array_diff($extra, $this->documentMembers()['coord_thread']),
            'CONTROL 4 did not bite: `converged` — a flag D2 explicitly refuses to publish — was '
            .'added to the client member set and the client-knows-a-member-D2-does-not-declare '
            .'direction stayed clean');

        // CONTROL 5 — two members SWAPPED. The set is untouched, so both directions above stay
        // clean and only the ORDER assertion can see it.
        $swapped = $this->probe([], $this->mutatedModules([
            // The anchor carries the `Object.freeze([` above it deliberately: `thread_ref`
            // followed by `install_id` occurs in BOTH member arrays, and a two-line anchor would
            // fail the exactly-once assertion rather than mutate the one this control means.
            'coord-members.js',
            "Object.freeze([\n    'thread_ref',\n    'install_id',",
            "Object.freeze([\n    'install_id',\n    'thread_ref',",
        ]))['thread_members'];
        $document = $this->documentMembers()['coord_thread'];

        $this->assertSame([], array_values(array_diff($document, $swapped)),
            'CONTROL 5 is not testing what it claims: the swap changed the set, so the order '
            .'assertion is not the only thing that could catch it');
        $this->assertSame([], array_values(array_diff($swapped, $document)),
            'CONTROL 5 is not testing what it claims: the swap changed the set, so the order '
            .'assertion is not the only thing that could catch it');
        $this->assertNotSame($document, $swapped,
            'CONTROL 5 did not bite: two members were swapped and the ORDER assertion stayed clean');

        // CONTROL 6 — the § 5.7 closure, from the DOCUMENT side. Delete a member from § 5.7's
        // render map and the D2-declares-a-member-§5.7-does-not-render direction must red.
        $stripped = str_replace('`coord_round.declares_close`', '`coord_round.carrier`', $this->floorMd());
        $this->assertNotContains('coord_round.declares_close', $this->renderedMembers($stripped),
            'CONTROL 6 did not bite: `coord_round.declares_close` was removed from § 5.7’s D2-field '
            .'column and the render-map parser still reported it');
    }
}
