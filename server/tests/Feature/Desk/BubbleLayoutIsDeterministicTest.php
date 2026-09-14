<?php

namespace Tests\Feature\Desk;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md § 5.1` rules 4 and 5 — the MEASURED box, and the deterministic
 * separation of two bubbles that would overlap.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE GEOMETRY IS THE FIXTURE'S, NOT A DRAWING'S. There is no browser on this host and no
 * floor page to lay out, so the probe hands the module a stub measurer (`w = characters ×
 * char_w`). That is not a test of how wide "ingest endpoint" is; it is a test that the module
 * SIZES FROM WHAT IT IS GIVEN and invents nothing — which is the rule: "the box is never sized
 * from a guess at the text's width".
 *
 * ⛔ AND THE SEPARATION CARRIES NO FACT. Rule 5: a displaced bubble "is a **layout** artifact:
 * its offset says nothing about its seat". The assertions below are about placement being a pure
 * function of the base geometry, in § 3.1's identity order, with nothing stored between renders
 * — "two browsers rendering one fleet place them identically".
 */
class BubbleLayoutIsDeterministicTest extends TestCase
{
    use DrivesTheDeskClient;

    /** Three desks whose base rects all overlap, and one far away that overlaps nothing. */
    private function crowd(): array
    {
        return [
            ['install_id' => 'aimla', 'seat_id' => 'aimla-a', 'anchor' => ['x' => 100, 'y' => 100], 'text' => 'aaaa'],
            ['install_id' => 'aimla', 'seat_id' => 'aimla-b', 'anchor' => ['x' => 110, 'y' => 100], 'text' => 'bbbb'],
            ['install_id' => 'aimla', 'seat_id' => 'aimla-c', 'anchor' => ['x' => 120, 'y' => 100], 'text' => 'cccc'],
            ['install_id' => 'aimla', 'seat_id' => 'aimla-z', 'anchor' => ['x' => 900, 'y' => 100], 'text' => 'zzzz'],
        ];
    }

    public function test_the_box_is_the_measured_text_and_the_overlap_is_resolved_over_base_rects(): void
    {
        $layout = $this->probe(['layout' => ['bubbles' => $this->crowd(), 'char_w' => 10, 'line_h' => 20]])['layout'];

        $this->assertCount(4, $layout);

        // The box is the MEASUREMENT, not a constant: four characters at 10 wide is 40, and the
        // anchor is the bubble's bottom edge with the box centred on the character.
        $this->assertSame(40.0, (float) $layout[0]['w']);
        $this->assertSame(80.0, (float) $layout[0]['x'], 'the box is not centred on the character it is anchored to');
        $this->assertSame(80.0, (float) $layout[0]['y'], 'the box does not sit above its anchor');

        // ⭐ THE BASE-RECT PROPERTY, WHICH IS THE WHOLE OF RULE 5, ASSERTED AS ARITHMETIC:
        // a, b and c all overlap EACH OTHER as drawn (80–120, 90–130, 100–140), so in identity
        // order they lift by 0, 1 and 2. A resolver that tested against ALREADY-DISPLACED rects
        // would give c a lift of 1 — b having moved out of its way — which is the cascade that
        // makes one bubble's placement depend on another's title.
        $this->assertSame([0, 1, 2, 0], array_column($layout, 'lifted'));
        $this->assertSame(80.0 - 26.0, (float) $layout[1]['y'], 'the separated bubble was not lifted clear of the one below it');
        $this->assertSame(80.0 - 52.0, (float) $layout[2]['y']);
        $this->assertSame(80.0, (float) $layout[3]['y'], 'a bubble that overlaps nothing was displaced anyway');
    }

    /**
     * "Two browsers rendering one fleet place them identically with nothing stored" — so the
     * output cannot depend on the order the seats arrived in, which is a delta feed's order and
     * is not the same twice.
     */
    public function test_the_placement_does_not_depend_on_the_order_the_seats_arrived_in(): void
    {
        $inOrder = $this->probe(['layout' => ['bubbles' => $this->crowd()]])['layout'];
        $shuffled = $this->probe(['layout' => ['bubbles' => array_reverse($this->crowd())]])['layout'];

        $this->assertSame($inOrder, $shuffled,
            'the same fleet laid out in a different arrival order placed its bubbles differently');
        $this->assertSame(['aimla-a', 'aimla-b', 'aimla-c', 'aimla-z'], array_column($inOrder, 'seat_id'),
            'the pass is not running in § 3.1\'s identity order');
    }

    /**
     * Rule 4, at its sharpest: a caller with nothing to measure with gets a REFUSAL, because the
     * alternative — a plausible default width — is the fixed-width box that "would meet [the
     * bound] by SILENTLY CLIPPING", and a title clipped with no mark is read as the whole title.
     */
    public function test_a_layout_with_no_measurer_is_refused_rather_than_guessed(): void
    {
        $probe = $this->probe(['layout' => ['bubbles' => $this->crowd(), 'no_measurer' => true]]);

        $this->assertNull($probe['layout'], 'a layout was produced with nothing to measure the text with');
        $this->assertStringContainsString('measurer', (string) $probe['measurer_error']);
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect in the SHIPPED module and names the
     * assertion above that must catch it.
     */
    public function test_the_layout_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $crowd = ['layout' => ['bubbles' => $this->crowd()]];

        // CONTROL 1 — the fixed ORDER removed, so the pass runs in arrival order. The set of
        // boxes is unchanged and every one of them is still separated; only the
        // order-independence assertion can see it, which is what makes that assertion
        // load-bearing rather than incidental.
        $arrivalOrder = $this->mutatedModules([
            'task-bubble.js',
            'const ordered = [...bubbles].sort((a, b) => (identity(a) < identity(b) ? -1 : 1));',
            'const ordered = [...bubbles];',
        ]);

        $this->assertNotSame(
            $this->probe($crowd, $arrivalOrder)['layout'],
            $this->probe(['layout' => ['bubbles' => array_reverse($this->crowd())]], $arrivalOrder)['layout'],
            'CONTROL 1 did not bite: the identity sort was deleted and two arrival orders still '
            .'placed the bubbles identically'
        );

        // CONTROL 2 — the CASCADE: the collision test is moved onto the rect as already lifted,
        // which is exactly "a pass over already-displaced ones". `c` then stops seeing `b`.
        $cascading = $this->probe($crowd, $this->mutatedModules([
            'task-bubble.js',
            'if (overlaps(rect, base[j])) {',
            'if (overlaps({ ...rect, y: rect.y - lifted * (rect.h + SEPARATION_GAP_PX) }, base[j])) {',
        ]))['layout'];

        $this->assertNotSame([0, 1, 2, 0], array_column($cascading, 'lifted'),
            'CONTROL 2 did not bite: the resolver was made to test against displaced rects and '
            .'the base-rect arithmetic still held');

        // CONTROL 3 — the REFUSAL removed. What is being checked above is not that a missing
        // measurer breaks something — it would break anyway, further down, in a message about a
        // value that is not a function — but that the module refuses it DELIBERATELY and says
        // so. With the guard gone, the caller is told something else entirely.
        $noRefusal = $this->probe(
            ['layout' => ['bubbles' => $this->crowd(), 'no_measurer' => true]],
            $this->mutatedModules([
                'task-bubble.js',
                'if (typeof measure !== \'function\') {',
                'if (false) {',
            ]),
        );

        $this->assertStringNotContainsString('measurer', (string) $noRefusal['measurer_error'],
            'CONTROL 3 did not bite: the explicit refusal was deleted from the module and the '
            .'caller was still told which argument was missing');
    }
}
