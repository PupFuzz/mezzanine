<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-20 — seat furniture never overlaps, and the overflow row stays below the floor.**
 * `docs/design/FLOOR.md § 11`, gated at Appendix B row 14 (card#7341 step 11). The design rule
 * card#7341 states — seat furniture (desk, character, bubble, plate) must not overlap, and static
 * placement is collision-free by construction at the slot function — kept as the test the card asks
 * for, read from the scene's emitted rects.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-scene.json`'s. `fx-snapshot-4` and `fx-interns`' cap leg and bound
 * seat are replayed on `scene_boxed`'s map — twelve slots each exactly the furniture box, the box
 * read from `resources/floor/furniture-box.js` by the probe — which stands where § 11 names "the
 * shipped default map … re-authored to the furniture box". ⚠ THAT RE-AUTHORING IS APPENDIX B ROW 14's
 * SLICE B AND HAS NOT LANDED: `resources/floor/default.tmj` still ships sprite-sized objects, so the
 * GREEN clauses run on the stand-in and the shipped map is held to what § 11's control says of it
 * TODAY — *(f)*'s undersized line names every slot of the room — which is "the measurement that says
 * the re-authoring is owed rather than optional". When slice B lands, that test reds by design and
 * is replaced by the control § 11 states: the shipped map passing every clause.
 *
 * ⛔ EVERY RED IS PLANTED IN THE SHIPPED MODULE THE DEFECT WOULD LIVE IN — a mutated copy of the tree
 * — and watched failing on the clause it names.
 */
class SeatFurnitureNeverOverlapsTest extends TestCase
{
    use DrivesTheScene;

    private const BOXED = 'scene_boxed';

    private const REORDERED = 'scene_boxed_reordered';

    private const OVERFLOW = 'scene_overflow';

    private const CROWDED = 'scene_crowded';

    private const CROWDED_REORDERED = 'scene_crowded_reordered';

    private const UNDERSIZED = 'scene_undersized';

    private const CAP = 'interns_cap';

    private const BOUND = 'interns_bound';

    private const SHIPPED = 'scene_default';

    /** Every run but the two whose defect is (f)'s subject. */
    private const CLEAN = [self::BOXED, self::REORDERED, self::OVERFLOW, self::CAP, self::BOUND];

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_a_every_desk_lies_inside_its_slot_and_no_two_desks_meet(): void
    {
        foreach (self::CLEAN as $run) {
            $this->assertSame([], $this->containmentDefects($this->sceneOf($run)), "[{$run}] (a)");
        }
    }

    public function test_green_b_no_bubble_meets_another_desk_a_bubble_the_clock_or_the_strip_header_and_placement_is_stable(): void
    {
        foreach (self::CLEAN as $run) {
            $this->assertSame([], $this->bubbleDefects($this->sceneOf($run)), "[{$run}] (b)");
        }

        $this->assertSame([], $this->readingDefects($this->sceneOf(self::BOXED), $this->sceneOf(self::REORDERED)),
            '(b): the second reading, the seats delivered in another order, placed a bubble elsewhere');
        $this->assertSame([], $this->readingDefects($this->sceneOf(self::CROWDED), $this->sceneOf(self::CROWDED_REORDERED)),
            '(b): on the crowded map, where two bubbles are parted, the second reading placed one elsewhere');
    }

    public function test_green_c_the_overflow_row_is_drawn_below_the_floor_inside_its_strip(): void
    {
        $result = $this->floorRun(self::OVERFLOW);

        $this->assertSame([], $this->stripDefects($this->lastScene($result, self::OVERFLOW), $this->lastFloor($result)));
    }

    public function test_green_d_every_bubble_is_sized_from_the_measurer_and_the_bound_title_is_cut_with_a_mark(): void
    {
        foreach (self::CLEAN as $run) {
            $this->assertSame([], $this->measuredDefects($this->sceneOf($run), $run), "[{$run}] (d)");
        }
    }

    public function test_green_e_every_bound_string_is_cut_inside_the_box_and_no_stool_or_badge_is_hidden(): void
    {
        $this->assertSame([], $this->boundDefects($this->sceneOf(self::CAP), self::CAP), '(e) on the cap leg');
        $this->assertSame([], $this->boundDefects($this->sceneOf(self::BOUND), self::BOUND), '(e) on the bound seat');
    }

    public function test_green_f_a_crowded_or_undersized_map_is_drawn_whole_and_named_and_no_other_run_says_so(): void
    {
        $crowded = $this->floorRun(self::CROWDED);
        $this->assertSame([], $this->crowdedDefects($this->lastScene($crowded, self::CROWDED), $this->lastFloor($crowded)));

        $under = $this->floorRun(self::UNDERSIZED);
        $this->assertSame([], $this->undersizedDefects($this->lastScene($under, self::UNDERSIZED), $this->lastFloor($under)));

        foreach (self::CLEAN as $run) {
            $this->assertSame([], $this->f21Lines($this->lastFloor($this->floorRun($run))),
                "[{$run}] a run with neither defect emitted an F21 line");
        }
    }

    /**
     * ⛔ THE CONTROL § 11 CAN STATE OF THE SHIPPED MAP TODAY: "on the sprite-sized objects it ships
     * today … *(f)*'s undersized line names every slot of the room". Slice B's re-authoring turns this
     * red, and this method becomes § 11's positive control — the shipped map passing every clause.
     */
    public function test_control_the_shipped_default_is_still_sprite_sized_so_every_slot_is_named_undersized(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::SHIPPED));
        $lines = $this->f21Lines($frame);
        $undersized = array_values(array_filter($lines, fn ($l) => str_contains($l, 'is smaller than the furniture box')));

        $this->assertCount($this->shippedDefaultSlots(), $undersized,
            'the shipped default no longer has every slot smaller than the box — slice B has landed, and this '
            .'control is owed its replacement: the shipped map passing every clause of AT-D3-20');
        $this->assertCount(count($undersized), $lines,
            'the shipped default emitted an F21 line other than the undersized one — G8 holds its objects disjoint');
    }

    // ── RED — each defect planted in the shipped module it would live in ──────────────────────

    public function test_red_the_tray_outside_the_slot(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const colC = colB + widthB + GUTTER;', 'const colC = W + GUTTER;']);

        $this->assertNotSame([], $this->containmentDefects($this->sceneOf(self::BOXED, $dir)),
            'RED (the tray outside the slot) did not fail (a) on fx-snapshot-4');
        $this->assertNotSame([], $this->containmentDefects($this->sceneOf(self::CAP, $dir)),
            'RED (the tray outside the slot) did not fail (a) on the cap leg');
    }

    public function test_red_the_primitive_that_does_not_cut(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'if (whole.w <= width) {', 'if (true) {']);
        $scene = $this->sceneOf(self::CAP, $dir);

        $this->assertNotSame([], $this->containmentDefects($scene), 'RED (the primitive that does not cut) did not fail (a)');
        $this->assertNotSame([], $this->boundDefects($scene, self::CAP), 'RED (the primitive that does not cut) did not fail (e)');
    }

    public function test_red_the_hidden_stool(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const shown = stools.slice(0, STOOL_CAP);', 'const shown = stools.slice(0, STOOL_CAP - 1);']);

        $this->assertNotSame([], $this->boundDefects($this->sceneOf(self::CAP, $dir), self::CAP),
            'RED (the hidden stool) did not fail (e)');
    }

    public function test_red_the_silent_crowded_map(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'if (footprintsIntersect(rect(objects[i]), rect(objects[j]))) {', 'if (false) {']);
        $result = $this->floorRun(self::CROWDED, $dir);

        $this->assertNotSame([], $this->crowdedDefects($this->lastScene($result, self::CROWDED), $this->lastFloor($result)),
            'RED (the silent crowded map) did not fail (f)');
    }

    public function test_red_the_refused_map(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "            if (model === undefined) {\n                continue;\n            }",
            "            if (model === undefined || notices.length > 0) {\n                continue;\n            }"]);
        $result = $this->floorRun(self::CROWDED, $dir);
        $frame = $this->lastFloor($result);

        $this->assertNotSame([], $this->crowdedDefects($frame['scene'] ?? ['desks' => []], $frame),
            'RED (the refused map — the crowded room drawn with no desks) did not fail (f)');
    }

    public function test_red_the_order_dependent_bubble(): void
    {
        $dir = $this->mutatedModules(['../desk/task-bubble.js',
            'const ordered = [...bubbles].sort((a, b) => (identity(a) < identity(b) ? -1 : 1));',
            'const ordered = [...bubbles];']);

        $this->assertNotSame([], $this->readingDefects($this->sceneOf(self::CROWDED, $dir), $this->sceneOf(self::CROWDED_REORDERED, $dir)),
            'RED (the order-dependent bubble) did not move a bubble on the second reading');
    }

    public function test_red_the_strip_on_the_floor(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'const top = extent.y + extent.height + STRIP_GAP;', 'const top = extent.y + extent.height - H;']);
        $result = $this->floorRun(self::OVERFLOW, $dir);

        $this->assertNotSame([], $this->stripDefects($this->lastScene($result, self::OVERFLOW), $this->lastFloor($result)),
            'RED (the strip on the floor) did not fail (c)');
    }

    public function test_red_the_fixed_width_bubble(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            'const w = Math.max(line1.w, line2?.w ?? 0) + 2 * BUBBLE_PAD;', 'const w = 120;']);

        $this->assertNotSame([], $this->measuredDefects($this->sceneOf(self::BOXED, $dir), self::BOXED),
            'RED (the fixed-width bubble) did not fail (d)');
    }

    // ── The clauses, each a list of defects so a GREEN and its RED read one check ─────────────

    /** (a): every desk's furniture inside its slot; every two desks' furniture disjoint. */
    private function containmentDefects(array $scene): array
    {
        $defects = [];
        $desks = $scene['desks'];
        $furniture = array_map(fn ($d) => $this->furniture($d), $desks);

        foreach ($desks as $i => $desk) {
            $outer = $desk['slot_rect'] ?? $desk['box'];

            if (! $this->inside($furniture[$i], $outer)) {
                $defects[] = "{$desk['key']}'s furniture leaves its ".($desk['slot_rect'] === null ? 'box' : 'slot');
            }

            for ($j = $i + 1; $j < count($desks); $j++) {
                if ($this->intersect($furniture[$i], $furniture[$j])) {
                    $defects[] = "{$desk['key']} and {$desks[$j]['key']} overlap";
                }
            }
        }

        return $defects;
    }

    /** (b): no bubble meets another desk's furniture, another bubble, the clock face, the strip header. */
    private function bubbleDefects(array $scene): array
    {
        $defects = [];
        $clock = $scene['band']['clock'];
        $header = $scene['strip']['header'] ?? null;
        $bubbles = array_values(array_filter($scene['desks'], fn ($d) => $d['bubble'] !== null));

        $this->assertNotSame([], $bubbles, 'no desk drew a bubble — (b) would measure nothing');

        foreach ($bubbles as $i => $desk) {
            $b = $desk['bubble'];

            foreach ($scene['desks'] as $other) {
                if ($other['key'] !== $desk['key'] && $this->intersect($b, $this->furniture($other))) {
                    $defects[] = "{$desk['key']}'s bubble meets {$other['key']}'s furniture";
                }
            }

            foreach (array_slice($bubbles, $i + 1) as $other) {
                if ($this->intersect($b, $other['bubble'])) {
                    $defects[] = "{$desk['key']}'s bubble meets {$other['key']}'s";
                }
            }

            if ($this->intersect($b, $clock)) {
                $defects[] = "{$desk['key']}'s bubble meets the wall clock";
            }

            if ($header !== null && $this->intersect($b, $header)) {
                $defects[] = "{$desk['key']}'s bubble meets the overflow strip's header";
            }
        }

        return $defects;
    }

    /** (b)'s second reading: every bubble exactly where the first reading put it. */
    private function readingDefects(array $first, array $second): array
    {
        $at = fn (array $scene) => array_column(array_map(fn ($d) => [
            'key' => $d['key'],
            'rect' => $d['bubble'] === null ? null : [$d['bubble']['x'], $d['bubble']['y'], $d['bubble']['w'], $d['bubble']['h']],
        ], $scene['desks']), 'rect', 'key');

        $a = $at($first);
        $b = $at($second);
        ksort($a);
        ksort($b);

        return $a === $b ? [] : ['a bubble moved between the two readings'];
    }

    /** (c): the overflow desks below the floor and inside the strip; no floor furniture below. */
    private function stripDefects(array $scene, array $frame): array
    {
        $defects = [];
        $bottom = $frame['extent']['y'] + $frame['extent']['height'];
        $strip = $scene['strip'];
        $overflow = array_values(array_filter($scene['desks'], fn ($d) => $d['overflow']));

        $this->assertCount(count($frame['overflow']), $overflow, 'the scene did not draw every overflow seat');
        $this->assertNotSame([], $overflow, 'the overflowing run drew no overflow desk — (c) would measure nothing');

        foreach ($scene['desks'] as $desk) {
            $f = $this->furniture($desk);

            if ($desk['overflow']) {
                foreach (array_filter([$f, $desk['bubble']]) as $rect) {
                    if ($rect['y'] < $bottom || ! $this->inside($rect, $strip)) {
                        $defects[] = "{$desk['key']} is not below the floor inside the strip";
                    }
                }
            } elseif ($f['y'] + $f['h'] > $bottom) {
                $defects[] = "{$desk['key']}'s furniture reaches below the floor";
            }
        }

        return $defects;
    }

    /** (d): each bubble's box holds its measured text, and the bound title is cut with a mark. */
    private function measuredDefects(array $scene, string $run): array
    {
        [$glyph] = $this->measurerOf($run);
        $defects = [];

        foreach ($scene['desks'] as $desk) {
            $b = $desk['bubble'];

            if ($b === null) {
                continue;
            }

            if ($b['text_w'] !== $glyph * mb_strlen($b['text'])) {
                $defects[] = "{$desk['key']}'s bubble text was not measured by the page's measurer";
            }

            if ($b['w'] < $b['text_w']) {
                $defects[] = "{$desk['key']}'s bubble is narrower than the text it holds";
            }

            if (! $b['truncated'] || ! str_ends_with($b['text'], $this->mark())) {
                $defects[] = "{$desk['key']}'s bubble draws the title at its bound without the mark";
            }
        }

        return $defects;
    }

    /** (e): the fixture's bounded strings cut with a mark, every stool and the tags drawn. */
    private function boundDefects(array $scene, string $run): array
    {
        $defects = [];
        $seats = [];

        foreach ($this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $seats["{$seat['install_id']}/{$seat['seat_id']}"] = $seat;
            }
        }

        foreach ($scene['desks'] as $desk) {
            $seat = $seats[$desk['key']];

            foreach ($desk['elements'] as $e) {
                if (isset($e['text']) && ($e['truncated'] !== str_ends_with($e['text'], $this->mark()))) {
                    $defects[] = "{$desk['key']}'s {$e['kind']} is cut without its mark, or marked uncut";
                }
            }

            if (count($seat['subagents']) > 0) {
                $stools = $this->elementsOf($desk, 'stool');
                $more = $seat['subagents_open'] - count($seat['subagents']);

                if (count($stools) !== count($seat['subagents'])) {
                    $defects[] = "{$desk['key']} draws ".count($stools).' of its '.count($seat['subagents']).' stools';
                }

                if ($more > 0 && array_column($this->elementsOf($desk, 'stool-more'), 'text') !== ["+{$more} more"]) {
                    $defects[] = "{$desk['key']} does not draw its +{$more} more";
                }

                foreach ($this->elementsOf($desk, 'stool-label') as $label) {
                    if (! $label['untitled'] && ! $label['truncated']) {
                        $defects[] = "{$desk['key']}'s intern title at its bound is drawn uncut";
                    }
                }
            }

            if (($seat['action']['descriptor'] ?? null) !== null && strlen($seat['action']['descriptor']) >= 200) {
                $monitor = $this->elementsOf($desk, 'monitor-text');

                if (count($monitor) !== 1 || ! $monitor[0]['truncated']) {
                    $defects[] = "{$desk['key']}'s descriptor at its bound is not drawn cut with a mark";
                }
            }

            if (strlen($seat['seat_id']) >= 48) {
                $plate = $this->elementsOf($desk, 'nameplate');

                if (count($plate) !== 1 || ! $plate[0]['truncated']) {
                    $defects[] = "{$desk['key']}'s nameplate at its bound is not drawn cut with a mark";
                }
            }

            if (count($seat['badges']) > 18) {
                $chips = $this->elementsOf($desk, 'badge');
                $hidden = count($seat['badges']) - count($chips);

                if (count($chips) !== 18 || array_column($this->elementsOf($desk, 'badge-more'), 'text') !== ["+{$hidden} more"]) {
                    $defects[] = "{$desk['key']}'s cluster past D2's bound does not draw the bound and its mark";
                }

                if (($chips[0]['unrecognised'] ?? false) !== true) {
                    $defects[] = "{$desk['key']}'s unrecognised badge is not drawn first — F9's marker could fall under the mark";
                }
            }
        }

        return $defects;
    }

    /** (f) on the crowded map: the line, both desks drawn, the later `id` on top, each in its slot. */
    private function crowdedDefects(array $scene, array $frame): array
    {
        $defects = [];
        $room = $this->roomOf($frame, 'aimla');
        $seatAt = [];

        foreach ($room['desks'] as $d) {
            $seatAt[$d['slot']] = explode('/', $d['key'])[1];
        }

        $expected = $this->intersectLine(3, 4, 'aimla', [$seatAt[2] ?? null, $seatAt[3] ?? null]);

        if (! in_array($expected, $this->f21Lines($frame), true)) {
            $defects[] = "no F21 line: expected «{$expected}»";
        }

        $order = array_column($scene['desks'], 'object_id');
        $three = array_search(3, $order, true);
        $four = array_search(4, $order, true);

        if ($three === false || $four === false) {
            $defects[] = 'a desk of the crowded pair is not drawn';
        } elseif ($four < $three) {
            $defects[] = 'the earlier `id` is drawn on top';
        }

        foreach ($scene['desks'] as $desk) {
            if ($desk['slot_rect'] !== null && ! $this->inside($this->furniture($desk), $desk['slot_rect'])) {
                $defects[] = "{$desk['key']} is not drawn inside its own slot";
            }
        }

        return $defects;
    }

    /** (f) on the undersized map: the line, and the desk drawn at native size, past its edge. */
    private function undersizedDefects(array $scene, array $frame): array
    {
        $defects = [];
        $room = $this->roomOf($frame, 'aimla');
        $key = null;

        foreach ($room['desks'] as $d) {
            if ($d['slot'] === 0) {
                $key = $d['key'];
            }
        }

        $this->assertNotNull($key, 'slot 0 is not occupied on the undersized run — the fixture no longer plants its defect');

        $expected = $this->undersizedLine(1, 'aimla', [explode('/', $key)[1]]);

        if ($this->f21Lines($frame) !== [$expected]) {
            $defects[] = "expected exactly «{$expected}», got «".implode('», «', $this->f21Lines($frame)).'»';
        }

        $desk = $this->deskOf($scene, $key);
        $box = $this->box();

        if ($desk['box']['w'] !== $box['width'] || $desk['box']['h'] !== $box['height']) {
            $defects[] = 'the undersized desk was scaled';
        }

        if ($this->inside($this->furniture($desk), $desk['slot_rect'])) {
            $defects[] = 'the undersized desk was clipped to its slot';
        }

        return $defects;
    }

    /** The frame's F21 lines, whichever the scene emitted. */
    private function f21Lines(array $frame): array
    {
        return array_values(array_filter($frame['notices'], fn ($n) => str_starts_with($n, 'desk object')));
    }

    /** § 5.5's ***desk objects `i` and `j` intersect — `install_id`: `seat`, `seat`***, filled in. */
    private function intersectLine(int $i, int $j, string $room, array $seats): string
    {
        return $this->filled('desk objects `i` and `j` intersect', ['`i`' => "`{$i}`", '`j`' => "`{$j}`"], $room, $seats);
    }

    /** § 5.5's ***desk object `i` is smaller than the furniture box — `install_id`: `seat`***. */
    private function undersizedLine(int $i, string $room, array $seats): string
    {
        return $this->filled('desk object `i` is smaller than the furniture box', ['`i`' => "`{$i}`"], $room, $seats);
    }

    /**
     * One of § 5.5's F21 lines, read out of the document and filled in: the objects' ids in their
     * backticks, the room, and after the colon the seats — an empty object's omitted.
     */
    private function filled(string $head, array $ids, string $room, array $seats): string
    {
        $this->assertSame(1, preg_match('/\*\*\*('.preg_quote($head, '/').' — `install_id`: `seat`(?:, `seat`)?)\*\*\*/', $this->floorMd(), $m),
            "§ 5.5 does not publish «{$head}» in the form this test reads");

        $line = strtr(explode(' — ', $m[1])[0], $ids);
        $named = array_values(array_filter($seats, fn ($s) => $s !== null));

        return $line.' — '.($named === [] ? $room : $room.': '.implode(', ', $named));
    }
}
