<?php

namespace Tests\Feature\Lobby;

use Tests\TestCase;

/**
 * ⛔ THE DRIFT GUARD FOR THE LOBBY'S ONE MEMBER SET — `docs/design/FLOOR.md § 7.1`'s ten
 * `render_state` members, in § 7.1's fixed order, against `public/js/lobby/render-state.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 7.1 is a TABLE of member names and `RENDER_STATES` is a JavaScript array of member names.
 * That is a restatement, and the rule for a restatement a consumer cannot follow a pointer to is
 * DELETE it or GUARD it. A browser cannot read a markdown table, so it cannot be deleted — this
 * file is the guard, and it RE-DERIVES the population from the document on every run.
 *
 * A hand-written expected list here would be a THIRD copy, and the first thing three copies do is
 * let two of them agree while the document says something else. `docs/design/floor-preview`'s
 * README names the shape from the other end: "**six** copies of one member set are what this
 * replaced — four of them covering only four members (card#7943)".
 *
 * ⛔ ORDER, NOT ONLY SET. § 4.1 renders the per-floor summary "in § 7.1's fixed member order", so
 * a module whose ten agree while two have swapped places renders an order nobody ratified — and
 * a set comparison cannot see it. Control 4 below re-mints exactly that and requires the ORDER
 * assertion to be the one that catches it, with set equality still passing.
 *
 * ⚠ WHAT THIS DOES NOT CHECK, named so a green is not read as more than it is: the Label line
 * column, the seven `unknown_reason` sentences and § 7.6's twelve `api_error_type` phrases. Those
 * are the DESK's strings, the desk is not in this slice, and `tools/design/floor-preview.selftest.mjs`
 * already compares all three cell by cell against this same document.
 */
class LobbyMemberSetMatchesTheDocumentTest extends TestCase
{
    use DrivesTheLobbyClient;

    public function test_the_clients_member_set_is_section_71s_ten_in_section_71s_order(): void
    {
        $document = $this->documentMembers();

        // A CONTROL ON THE PARSER FIRST — every assertion below is vacuous if the parse returned
        // nothing, which is the exact false-clean this file exists to prevent. Ten is D3's own
        // claim in its own words: "`render_state` has **ten** members".
        $this->assertCount(10, $document,
            'the § 7.1 parser did not find ten members — it has stopped reading the document');
        $this->assertSame('working', $document[0],
            'the § 7.1 parser found something, but not the table it was aimed at');

        $module = $this->probe([])['render_states'];

        // Direction one: a member D3 publishes that the client does not know. It would render as
        // UNRECOGNISED on a floor where D2 is entitled to send it — § 5.4's marker doing duty for
        // a member that is perfectly well recognised by the document.
        $this->assertSame([], array_values(array_diff($document, $module)),
            '§ 7.1 publishes a render_state the client does not know');

        // Direction two: a member the client knows that D3 does not publish. This is the
        // `thinking` defect the floor-preview README warns about in terms — a DERIVATION (§ 6.2
        // A4 over a `working` seat) mistaken for a wire member.
        $this->assertSame([], array_values(array_diff($module, $document)),
            'the client knows a render_state § 7.1 does not publish');

        // And the ORDER, which is what § 4.1 actually consumes.
        $this->assertSame($document, $module,
            "the client's member ORDER is not § 7.1's, which is the order § 4.1 renders");
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect and names the assertion above that must
     * catch it. A comparison that has only ever been seen agreeing is not evidence.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the parse itself. Renaming § 7.1's closing anchor is the trap the
        // floor-preview selftest documents: an `indexOf` miss silently widens the slice, and a
        // parser that then finds "ten members somewhere in there" reports agreement over the
        // wrong population. Here the slice fails outright, which is the honest answer.
        $moved = str_replace(self::S71_CLOSE, '### 7.2b Badges', $this->floorMd());
        $this->assertNotCount(10, $this->documentMembers($moved),
            "CONTROL 1 did not bite: § 7.1's closing anchor was renamed and the parser still "
            .'reported ten members, so the parse is not reading the bounds it claims to');

        $anchor = "export const RENDER_STATES = Object.freeze([\n    'working',";

        // CONTROL 2 — a member DROPPED from the client. Direction one must red.
        $short = $this->probe([], $this->mutatedModules([
            'render-state.js', $anchor, 'export const RENDER_STATES = Object.freeze([',
        ]))['render_states'];
        $this->assertNotSame([], array_diff($this->documentMembers(), $short),
            'CONTROL 2 did not bite: `working` was deleted from the client and the '
            .'§ 7.1-publishes-a-member-the-client-does-not-know direction stayed clean');

        // CONTROL 3 — `thinking` ADDED to the client. Direction two must red. This is the
        // named defect: § 6.2 A4's derivation over a `working` seat, mistaken for a wire member.
        $extra = $this->probe([], $this->mutatedModules([
            'render-state.js', $anchor, "export const RENDER_STATES = Object.freeze([\n    'thinking',\n    'working',",
        ]))['render_states'];
        $this->assertNotSame([], array_diff($extra, $this->documentMembers()),
            'CONTROL 3 did not bite: `thinking` was added to the client member set and the '
            .'client-knows-a-member-D3-does-not-publish direction stayed clean');

        // CONTROL 4 — two members SWAPPED. The set is untouched, so both directions above stay
        // clean and only the ORDER assertion can see it. Asserting that split is the point: it
        // is what proves the order check is load-bearing rather than incidental.
        $swapped = $this->probe([], $this->mutatedModules([
            'render-state.js', "    'working',\n    'idle',", "    'idle',\n    'working',",
        ]))['render_states'];
        $document = $this->documentMembers();

        $this->assertSame([], array_values(array_diff($document, $swapped)),
            'CONTROL 4 is not testing what it claims: the swap changed the set, so the order '
            .'assertion is not the only thing that could catch it');
        $this->assertSame([], array_values(array_diff($swapped, $document)),
            'CONTROL 4 is not testing what it claims: the swap changed the set, so the order '
            .'assertion is not the only thing that could catch it');
        $this->assertNotSame($document, $swapped,
            'CONTROL 4 did not bite: two members were swapped and the ORDER assertion stayed clean');
    }
}
