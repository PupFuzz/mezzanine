<?php

namespace Tests\Feature\Desk;

use Tests\TestCase;

/**
 * ⛔ THE DRIFT GUARD FOR THE BUBBLE'S ONE MEMBER SET — the `render_state` members whose desk
 * draws NO CHARACTER, re-derived from `docs/design/FLOOR.md § 7.1`'s **Desk** column, against
 * `public/js/desk/task-bubble.js`'s `NO_CHARACTER_STATES`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE SET EXISTS AT ALL: § 5.1 rule 3 — "A desk that draws no character draws no bubble …
 * [§ 7.1]'s **Desk** column is where which desks have one is read". The rule points at a
 * markdown table; a browser cannot read one; so the module holds a restatement, and the rule for
 * a restatement a consumer cannot follow a pointer to is DELETE it or GUARD it. This file is the
 * guard, and it RE-DERIVES the population from the document on every run rather than comparing
 * against a hand-written list that would be a third copy free to agree with the module while D3
 * says something else.
 *
 * ⛔ AND THE GUARD OUTLIVES NEITHER SIDE. It reads the shipped module, so deleting the bubble
 * reds it; it reads § 7.1, so an amendment that gave `stale` a character reds it too. That is
 * the placement rule for a guard: not on the thing it guards.
 *
 * ⚠ WHAT THIS DOES NOT CHECK, so a green is not read as more than it is: that a desk whose cell
 * DOES draw a character draws the character § 7.1 describes. The pose is the desk's render
 * (§ 7.1's own table, built with the floor) and no desk exists yet to draw one. What is
 * checkable today is the partition the BUBBLE switches on.
 */
class DeskCharacterSetMatchesTheDocumentTest extends TestCase
{
    use DrivesTheDeskClient;

    public function test_the_modules_no_character_set_is_section_71s_desk_column(): void
    {
        $document = $this->documentCharacters();

        // A CONTROL ON THE PARSER FIRST — every assertion below is vacuous if the parse returned
        // nothing, which is the exact false-clean this file exists to prevent. Ten is D3's own
        // claim in its own words: "`render_state` has **ten** members".
        $this->assertCount(10, $document,
            'the § 7.1 parser did not find ten members — it has stopped reading the document');
        $this->assertArrayHasKey('working', $document,
            'the § 7.1 parser found something, but not the table it was aimed at');

        $module = $this->probe([])['no_character_states'];
        $derived = $this->documentNoCharacterStates();

        // The document's own reading of its cells, before either direction is compared: three
        // desks with nobody at them — two empty chairs and the desk that is removed outright.
        $this->assertSame(['stale', 'offline', 'retired'], $derived,
            '§ 7.1\'s Desk column no longer reads as three characterless desks — if that is an '
            .'amendment, the module and this expectation both follow it; if it is a parse, fix the parse');

        // Direction one: a desk D3 draws empty that the module would draw a thinker over —
        // § 7.5's asleep-versus-gone confusion arriving through the bubble.
        $this->assertSame([], array_values(array_diff($derived, $module)),
            '§ 7.1 has a desk with no character that the bubble module still draws over');

        // Direction two: a desk the module refuses a bubble on that D3 populates — a seat whose
        // task silently stops being rendered on a desk that has somebody sitting at it.
        $this->assertSame([], array_values(array_diff($module, $derived)),
            'the bubble module withholds the bubble from a desk § 7.1 draws a character on');
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect and names the assertion above that must
     * catch it. A comparison that has only ever been seen agreeing is not evidence.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the parse itself. Renaming § 7.1's closing anchor is the trap the
        // floor-preview selftest documents: a miss silently widens the slice, and a parser that
        // then finds "ten members somewhere in there" reports agreement over the wrong
        // population.
        $moved = str_replace(self::S71_CLOSE, '### 7.2b Badges', $this->floorMd());
        $this->assertNotCount(10, $this->documentCharacters($moved),
            "CONTROL 1 did not bite: § 7.1's closing anchor was renamed and the parser still "
            .'reported ten members, so the parse is not reading the bounds it claims to');

        // CONTROL 2 — the DESK CELL, which is the column this guard is actually about. `idle`'s
        // desk is amended to an empty chair; a parser keying on the member name rather than on
        // the cell would not notice, and would report the same three characterless desks.
        $amended = str_replace(
            '| `idle` | **character present, slumped asleep on the desk**',
            '| `idle` | **empty chair**',
            $this->floorMd(),
        );
        $this->assertNotSame($this->floorMd(), $amended,
            'CONTROL 2 mutated nothing — § 7.1\'s `idle` cell has been reworded, so this control '
            .'would "prove" the derivation can fail by running it against an unmodified document');
        $this->assertContains('idle', $this->documentNoCharacterStates($amended),
            'CONTROL 2 did not bite: § 7.1\'s `idle` desk was amended to an empty chair and the '
            .'derivation still reported it as a desk with a character, so it is not reading the cell');

        $anchor = "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired']);";

        // CONTROL 3 — a member DROPPED from the module: `offline` desks would draw a bubble over
        // an empty chair. Direction one must red.
        $short = $this->probe([], $this->mutatedModules([
            'task-bubble.js', $anchor,
            "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'retired']);",
        ]))['no_character_states'];

        $this->assertNotSame([], array_diff($this->documentNoCharacterStates(), $short),
            'CONTROL 3 did not bite: `offline` was deleted from the module set and the '
            .'§ 7.1-draws-no-character direction stayed clean');

        // CONTROL 4 — a member ADDED to the module: `idle` desks would lose their bubble, which
        // is the quieter defect of the two because nothing appears that should not. Direction
        // two must red.
        $extra = $this->probe([], $this->mutatedModules([
            'task-bubble.js', $anchor,
            "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired', 'idle']);",
        ]))['no_character_states'];

        $this->assertNotSame([], array_diff($extra, $this->documentNoCharacterStates()),
            'CONTROL 4 did not bite: `idle` was added to the module set and the '
            .'module-withholds-a-bubble direction stayed clean');
    }

    /**
     * § 5.4's membership test reaches the bubble too: an unrecognised `render_state` has no
     * published desk, so it has no known anchor and draws no bubble. The module takes the
     * members from `lobby/render-state.js` — the one published copy — rather than writing a
     * second one.
     */
    public function test_an_unrecognised_render_state_anchors_no_bubble(): void
    {
        // `thinking` is the named defect: § 6.2 A4's DERIVATION over a `working` seat, mistaken
        // for a wire member. It is not a `render_state`, so it is not a desk this document
        // describes.
        $probe = $this->probe(['character_probe' => ['working', 'stale', 'thinking', null]]);

        $this->assertSame([true, false, false, false], $probe['characters'],
            'the desk draws a character for a state § 7.1 does not publish, or refuses one for a state it does');

        $this->assertNull(
            $this->probe(['seat' => $this->seatBody(['render_state' => 'thinking'])])['bubble'],
            'a seat in an unrecognised render_state drew a thought bubble — over a character the '
            .'document does not say is there'
        );
    }
}
