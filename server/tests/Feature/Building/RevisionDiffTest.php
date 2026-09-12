<?php

namespace Tests\Feature\Building;

use App\Building\RevisionDiff;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\TestCase;

/**
 * `docs/design/FLEET-STATE.md § 6.11`'s DIFF — one of the three things the authored store gives
 * back for what version control lost: "the console shows two revisions side by side and names what
 * moved: the tile layers whose data differ, the desk count `S` before and after, and a line diff
 * of the two documents pretty-printed."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE STRUCTURAL HALF IS WHAT THIS FILE IS ABOUT. A CSV tile layer pretty-prints to one line per
 * tile, so a line diff of two maps is thousands of lines whichever tile moved — *which layers
 * changed* and *what `S` became* are the two questions an operator actually has, and a diff that
 * answered neither would be a wall of text calling itself a review.
 */
class RevisionDiffTest extends TestCase
{
    public function test_a_repainted_tile_layer_is_named_and_the_untouched_ones_are_not(): void
    {
        $before = FloorMapFixture::decoded();
        $after = $before;
        $after['layers'][0]['data'][0] = 7;

        $diff = RevisionDiff::between(FloorMapFixture::encode($before), FloorMapFixture::encode($after));

        $this->assertSame([['name' => 'room', 'change' => 'changed']], $diff['layers']);
        $this->assertTrue($diff['structural']);

        // THE CONTROL: the same document against itself names the layer UNCHANGED. Without it, a
        // diff that reported everything as changed would pass the arm above.
        $this->assertSame(
            [['name' => 'room', 'change' => 'unchanged']],
            RevisionDiff::between(FloorMapFixture::encode($before), FloorMapFixture::encode($before))['layers'],
        );
    }

    public function test_a_layer_that_arrived_or_went_is_named_as_that_rather_than_omitted(): void
    {
        $before = FloorMapFixture::decoded();
        $after = $before;
        $after['layers'][] = [
            'id' => 3, 'type' => 'tilelayer', 'name' => 'furniture',
            'width' => 20, 'height' => 8, 'data' => array_fill(0, 160, 2),
        ];

        $diff = RevisionDiff::between(FloorMapFixture::encode($before), FloorMapFixture::encode($after));

        $this->assertSame(
            [['name' => 'room', 'change' => 'unchanged'], ['name' => 'furniture', 'change' => 'added']],
            $diff['layers'],
        );

        // And the other direction — a layer an author DELETED is the change they are least likely
        // to have meant, so it is named rather than dropped from the list.
        $this->assertSame(
            [['name' => 'room', 'change' => 'unchanged'], ['name' => 'furniture', 'change' => 'removed']],
            RevisionDiff::between(FloorMapFixture::encode($after), FloorMapFixture::encode($before))['layers'],
        );
    }

    public function test_a_layer_repainted_inside_a_group_is_still_named(): void
    {
        // Tiled nests layers, and a diff that walked the top level once would report a repainted
        // scenery group as *unchanged* — the same defect `FloorMap`'s encoding check recurses to
        // avoid, in the surface an operator uses to review the save.
        $before = FloorMapFixture::decoded();
        $before['layers'][0] = [
            'id' => 3, 'type' => 'group', 'name' => 'scenery',
            'layers' => [$before['layers'][0]],
        ];

        $after = $before;
        $after['layers'][0]['layers'][0]['data'][0] = 7;

        $this->assertSame(
            [['name' => 'scenery/room', 'change' => 'changed']],
            RevisionDiff::between(FloorMapFixture::encode($before), FloorMapFixture::encode($after))['layers'],
        );
    }

    public function test_the_desk_count_is_reported_before_and_after_because_a_change_re_slots_every_desk(): void
    {
        $diff = RevisionDiff::between(FloorMapFixture::valid(12), FloorMapFixture::valid(8));

        $this->assertSame(['before' => 12, 'after' => 8], $diff['slots']);
    }

    public function test_a_removal_diffs_as_every_line_going_and_carries_no_structural_half(): void
    {
        // § 6.11: a removal is a revision with a `document NULL`. It has no layers and no `S` —
        // the room renders the shipped default — and a diff that printed zeroes there would be
        // making a claim about a room that is not being drawn.
        $diff = RevisionDiff::between(FloorMapFixture::valid(), null);

        $this->assertFalse($diff['structural']);
        $this->assertSame([], $diff['layers']);
        $this->assertSame(['before' => 12, 'after' => null], $diff['slots']);
        $this->assertSame(['-'], array_values(array_unique(array_column($diff['lines'], 'op'))));
    }

    public function test_a_layout_revision_diffs_by_its_lines_and_claims_no_layers_or_slots(): void
    {
        // The layout is diffed by this same class (§ 6.11 keys revisions by `(kind, subject)` and
        // the layout is one of the kinds), and it has neither tile layers nor desks.
        $before = '{"floors":[{"rooms":{"sola":{"form":"office"}}}]}';
        $after = '{"floors":[{"rooms":{"sola":{"form":"open"}}}]}';

        $diff = RevisionDiff::between($before, $after);

        $this->assertSame([], $diff['layers']);
        $this->assertSame(['before' => null, 'after' => null], $diff['slots']);
        $this->assertContains('-', array_column($diff['lines'], 'op'));
        $this->assertContains('+', array_column($diff['lines'], 'op'));

        // The unchanged lines are still there — a diff that printed only the changes would make
        // *what does this document say now* unanswerable from the page.
        $this->assertContains(' ', array_column($diff['lines'], 'op'));
    }

    public function test_two_documents_differing_only_in_their_formatting_diff_as_nothing(): void
    {
        // § 6.11 pretty-prints both sides, so a document saved from an editor that indents
        // differently is not a wall of red — and the no-op refusal at the write is what keeps that
        // from being a revision at all.
        $map = FloorMapFixture::decoded();

        $diff = RevisionDiff::between(
            (string) json_encode($map),
            (string) json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->assertSame([' '], array_values(array_unique(array_column($diff['lines'], 'op'))));
        $this->assertFalse($diff['truncated']);
    }

    public function test_a_diff_too_large_to_pair_up_says_so_rather_than_pairing_it_up_slowly(): void
    {
        // The bound is named when it bites (`truncated`), because a diff that hung on a large map
        // would be the same defect as one that lied about it, arriving as a timeout.
        $before = implode("\n", array_map(fn (int $i) => 'a'.$i, range(1, RevisionDiff::LCS_CAP + 10)));
        $after = implode("\n", array_map(fn (int $i) => 'b'.$i, range(1, RevisionDiff::LCS_CAP + 10)));

        $diff = RevisionDiff::between('"'.$before.'"', '"'.$after.'"');

        $this->assertTrue($diff['truncated']);
        $this->assertSame(['-', '+'], array_values(array_unique(array_column($diff['lines'], 'op'))));
    }
}
