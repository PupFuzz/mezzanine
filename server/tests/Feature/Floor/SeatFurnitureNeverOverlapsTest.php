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
 * ⛔ THE RUNS ARE `fixtures/fx-scene.json`'s, AND THE GREEN CLAUSES RUN ON THE SHIPPED DEFAULT.
 * `fx-snapshot-4` and `fx-interns`' cap leg and bound seat are replayed on `resources/floor/default.tmj`
 * itself (`@json:`, the file and never a copy), which Appendix B row 14's slice B re-authored to the
 * furniture box: each `desks` object at least the box, the box read from `resources/floor/furniture-box.js`.
 * Until slice B the suite replayed them on a stub of that shape and held the shipped map to what § 11's
 * control could say of it then — *(f)*'s undersized line naming every slot — and that control reded by
 * design when slice B landed. What stands in its place is § 11's POSITIVE control: the shipped map passes
 * every clause with no F21 line, and the slot every desk stands in is the file's own object, so a stand-in
 * cannot pass for the shipped map. The runs that PLANT a map defect — two slots, a crowded pair, an
 * undersized slot — still build their stub from `@box.*`, because the defect is theirs to plant.
 *
 * ⛔ EVERY RED IS PLANTED IN THE SHIPPED MODULE THE DEFECT WOULD LIVE IN — a mutated copy of the tree
 * — and watched failing on the clause it names.
 */
class SeatFurnitureNeverOverlapsTest extends TestCase
{
    use DrivesTheScene;

    private const SHIPPED = 'scene_default';

    private const REORDERED = 'scene_default_reordered';

    private const OVERFLOW = 'scene_overflow';

    private const CROWDED = 'scene_crowded';

    private const CROWDED_REORDERED = 'scene_crowded_reordered';

    private const UNDERSIZED = 'scene_undersized';

    private const CAP = 'interns_cap';

    private const BOUND = 'interns_bound';

    /** Every run but the two whose defect is (f)'s subject. */
    private const CLEAN = [self::SHIPPED, self::REORDERED, self::OVERFLOW, self::CAP, self::BOUND];

    /** FLOOR.md § 10.6's art elements and the character — (e) holds an intern off every OTHER element, the facts. */
    private const ART_KINDS = ['chair', 'character', 'desk-sprite', 'monitor-frame', 'desk-props', 'side-table'];

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

        $this->assertSame([], $this->readingDefects($this->sceneOf(self::SHIPPED), $this->sceneOf(self::REORDERED)),
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

    public function test_green_e_every_bound_string_is_cut_no_intern_is_hidden_and_the_flag_counts_what_the_row_does_not_draw(): void
    {
        $this->assertSame([], $this->boundDefects(self::CAP), '(e) on the cap leg');
        $this->assertSame([], $this->boundDefects(self::BOUND), '(e) on the bound seat');
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
     * ⛔ § 11's POSITIVE CONTROL: the shipped default — re-authored to the furniture box by Appendix B
     * row 14's slice B — with `fx-snapshot-4`'s four seats passes every clause and emits no F21 line, and
     * the cap leg on the same map passes (a) and (e), which on the sprite-sized objects the default
     * shipped before could not. So the gate is known to be able to say *nothing overlaps* and *no line*
     * of the map every unauthored room renders, and not only of a stub the suite built to that shape.
     *
     * ⛔ THE FILE, NEVER A COPY: every drawn desk's slot rect is held equal to the object the shipped
     * file declares at that slot, and the objects are re-read from the file here rather than trusted
     * to the fixture's `@json:`, so a run replaying a stand-in — or a fixture that quietly stopped
     * reading the file — cannot satisfy this control.
     */
    public function test_control_the_shipped_default_re_authored_to_the_box_passes_every_clause_with_no_f21_line(): void
    {
        $objects = $this->shippedDefaultObjects();
        $this->assertCount($this->shippedDefaultSlots(), $objects);

        foreach ([self::SHIPPED, self::CAP] as $run) {
            $result = $this->floorRun($run);
            $frame = $this->lastFloor($result);
            $scene = $this->lastScene($result, $run);

            foreach ($scene['desks'] as $desk) {
                $this->assertNotNull($desk['slot_rect'], "[{$run}] {$desk['key']} has no slot on the shipped default, whose slots outnumber this run's seats");
                $this->assertSame($objects[$desk['slot']], $desk['slot_rect'],
                    "[{$run}] {$desk['key']}'s slot is not the shipped file's object at slot {$desk['slot']} — the run did not read the shipped default");
            }

            // card#11144: the shipped default reserves a desk, and the reserved slot is in (a)'s population —
            // the run's PM sits there by its role, so the slot a seat is placed in BEFORE the probe loop is
            // held to its object and its furniture to its rect like every other.
            if ($run === self::SHIPPED) {
                $atReserved = array_values(array_filter($scene['desks'], fn (array $d): bool => $d['slot'] === ($this->shippedDefaultReservation()['index'] ?? null)));

                $this->assertSame([$this->documentSlots()['holder']], array_column($atReserved, 'key'),
                    "[{$run}] the shipped default's reserved slot is not drawn holding § 3.2's `role` row, so (a) does not read the slot seated by role");
            }

            $this->assertSame([], $this->f21Lines($frame), "[{$run}] the shipped default emitted an F21 line");
            $this->assertSame([], $this->containmentDefects($scene), "[{$run}] (a) on the shipped default");
            $this->assertSame([], $this->bubbleDefects($scene), "[{$run}] (b) on the shipped default");
            $this->assertSame([], $this->measuredDefects($scene, $run), "[{$run}] (d) on the shipped default");
        }

        $this->assertSame([], $this->boundDefects(self::CAP), '(e) on the cap leg, on the shipped default');
    }

    // ── RED — each defect planted in the shipped module it would live in ──────────────────────

    public function test_red_the_tray_outside_the_slot(): void
    {
        // The facts column laid from the box's right edge rather than the art column's.
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const colB = ART_W + GUTTER;', 'const colB = W + GUTTER;']);

        $this->assertNotSame([], $this->containmentDefects($this->sceneOf(self::SHIPPED, $dir)),
            'RED (the facts column outside the slot) did not fail (a) on fx-snapshot-4');
        $this->assertNotSame([], $this->containmentDefects($this->sceneOf(self::CAP, $dir)),
            'RED (the facts column outside the slot) did not fail (a) on the cap leg');
    }

    public function test_red_the_primitive_that_does_not_cut(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'if (whole.w <= width) {', 'if (true) {']);
        $scene = $this->sceneOf(self::CAP, $dir);

        $this->assertNotSame([], $this->containmentDefects($scene), 'RED (the primitive that does not cut) did not fail (a)');

        // (e) in EVERY role of `TYPE_ROLES` (card#11058 Q2, card#11046's screen role), each named so no role's
        // cut goes unread: the cap leg's descriptor, cut in the screen role; the bound seat's 48 B nameplate,
        // cut in the name role; and, in the fact role, a facts-column string the cap leg stretches past the box
        // when the primitive does not cut it — read below, element by element.
        foreach ([
            'screen' => [self::CAP, "'s descriptor at its bound is not drawn cut"],
            'name' => [self::BOUND, "'s nameplate at its bound is not drawn cut"],
        ] as $role => [$run, $says]) {
            $this->assertNotSame([], array_filter($this->boundDefects($run, $dir), fn (string $d): bool => str_contains($d, $says)),
                "RED (the primitive that does not cut) did not fail (e) in the {$role} role on {$run}");
        }

        $past = [];

        foreach ($scene['desks'] as $desk) {
            foreach ($desk['elements'] as $e) {
                if (is_string($e['text'] ?? null) && ($e['role'] ?? 'fact') === 'fact' && $e['x'] + $e['w'] > $desk['box']['x'] + $desk['box']['w']) {
                    $past[] = "{$desk['key']}'s {$e['kind']}";
                }
            }
        }

        $this->assertNotSame([], $past, 'RED (the primitive that does not cut) drew no fact-role string past its box on the cap leg');
    }

    public function test_red_the_hidden_stool(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const shown = stools.slice(0, STOOL_CAP);', 'const shown = stools.slice(0, STOOL_CAP - 1);']);

        $this->assertNotSame([], $this->boundDefects(self::CAP, $dir),
            'RED (the hidden stool) did not fail (e)');
    }

    /** (e)'s stool rects: an intern's sprite drawn over its neighbour, and interns keyed by their place in the row. */
    public function test_red_the_stool_rects(): void
    {
        foreach ([
            'the pitch narrower than the sprite' => ['const STOOL_PITCH = 24;', 'const STOOL_PITCH = 16;', 'meets the desk\'s stool'],
            'the intern keyed by its place' => ['key: internKey(desk.seat_id, stool.call_id),', 'key: internKey(desk.seat_id, `intern${i}`),', 'is keyed'],
            'the sprite drawn at the glyph\'s size' => ['const STOOL_W = 20;', 'const STOOL_W = 8;', 'not the art contract\'s 20 × 32'],
        ] as $what => [$from, $to, $says]) {
            $defects = $this->boundDefects(self::CAP, $this->mutatedModules(['../floor/desk-layout.js', $from, $to]));

            $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_contains($d, $says)), "RED ({$what}) did not fail (e)'s stool rects");
        }
    }

    /** The flag that miscounts — one of the badges past the row dropped from N. */
    public function test_red_the_flag_that_miscounts(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const n = flagCount(desk, row);', 'const n = flagCount(desk, row) - 1;']);

        $this->assertNotSame([], $this->boundDefects(self::CAP, $dir), 'RED (the flag that miscounts) did not fail (e)');
    }

    /** An unrecognised badge's raw id drawn in the row (operator ruling 2026-10-02, Q0). */
    public function test_red_the_raw_badge_in_the_row(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            "    return [\n        ...known.filter((id) => TREATMENT_BADGES.includes(id)),",
            "    return [\n        ...desk.badges.filter((id) => unknown.has(id)),\n        ...known.filter((id) => TREATMENT_BADGES.includes(id)),"]);

        $this->assertNotSame([], array_filter($this->boundDefects(self::CAP, $dir), fn (string $d): bool => str_contains($d, 'raw unrecognised string')),
            'RED (the raw badge in the row) did not fail (e) on its raw-string clause');
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

        $this->assertNotSame([], $this->measuredDefects($this->sceneOf(self::SHIPPED, $dir), self::SHIPPED),
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
        [$glyph] = $this->measurerOf($run, 'fact');
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

    /**
     * (e), over one run's last scene and the desk models that drew it: every string the box gives a
     * width is cut with the mark (and only a cut one carries it); every intern up to § 8's cap is drawn
     * and the *+N more* tag beside it; the descriptor and the nameplate at their bounds are cut; the
     * badge row draws its two — the treatment badges, then recognised badges in the wire's order — and
     * the flag `⚠ +N` counts every unusual item it does not (N as § 5.1's *the glance set* defines it);
     * and no raw unrecognised string is drawn on the desk (the operator's ruling of 2026-10-02, Q0).
     */
    private function boundDefects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $scene = $this->lastScene($result, $run);
        $models = $this->lastFloor($result)['desks']['desks'];
        $defects = [];
        $seats = [];

        foreach ($this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $seats["{$seat['install_id']}/{$seat['seat_id']}"] = $seat;
            }
        }

        $flagged = 0;
        $asks = [];

        foreach ($scene['desks'] as $desk) {
            $seat = $seats[$desk['key']];
            $model = $models[$desk['key']];

            foreach ($desk['elements'] as $e) {
                if (isset($e['text']) && ($e['truncated'] !== str_ends_with($e['text'], $this->mark()))) {
                    $defects[] = "{$desk['key']}'s {$e['kind']} is cut without its mark, or marked uncut";
                }
            }

            if (count($seat['subagents']) > 0) {
                $stools = $this->elementsOf($desk, 'stool');
                $more = $seat['subagents_open'] - count($seat['subagents']);

                if (count($stools) !== count($seat['subagents'])) {
                    $defects[] = "{$desk['key']} draws ".count($stools).' of its '.count($seat['subagents']).' interns';
                }

                if ($more > 0 && array_column($this->elementsOf($desk, 'stool-more'), 'text') !== ["+{$more} more"]) {
                    $defects[] = "{$desk['key']} does not draw its +{$more} more";
                }

                $defects = [...$defects, ...$this->stoolRectDefects($desk, $seat)];
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

            // The row and the flag, from the model's own lists.
            $raw = array_map(static fn (string $u): string => substr($u, strpos($u, ': ') + 2), $model['unrecognised']);
            $unknownBadges = array_map(static fn (string $u): string => substr($u, strlen('badges: ')),
                array_values(array_filter($model['unrecognised'], static fn (string $u): bool => str_starts_with($u, 'badges: '))));
            $known = array_values(array_filter($model['badges'], static fn (string $b): bool => ! in_array($b, $unknownBadges, true)));
            $treatment = ['config_invalid', 'fold_lag'];
            $order = [...array_values(array_filter($known, fn ($b) => in_array($b, $treatment, true))),
                ...array_values(array_filter($known, fn ($b) => ! in_array($b, $treatment, true)))];
            $row = array_column($this->elementsOf($desk, 'badge'), 'badge');
            $n = count($model['unrecognised']) + count($order) - min(2, count($order));

            if ($row !== array_slice($order, 0, 2)) {
                $defects[] = "{$desk['key']}'s badge row draws [".implode(', ', $row).'], not the first two of ['.implode(', ', $order).']';
            }

            if (array_column($this->elementsOf($desk, 'flag'), 'text') !== ($n > 0 ? ["⚠ +{$n}"] : [])) {
                $defects[] = "{$desk['key']}'s flag does not read ⚠ +{$n}";
            }

            $flagged += $n > 0 ? 1 : 0;

            foreach ($desk['elements'] as $e) {
                if (! in_array($e['kind'], ['chip', 'label', 'currency', 'monitor-text', 'badge', 'flag'], true) || ! isset($e['text'])) {
                    continue;
                }

                foreach ($raw as $value) {
                    $asks[] = [$e['text'], $e['truncated'], $value, "{$desk['key']}'s {$e['kind']} draws the raw unrecognised string «{$value}»"];
                }
            }
        }

        foreach ($this->drawsRaw($asks) as $i => $hit) {
            if ($hit) {
                $defects[] = $asks[$i][3];
            }
        }

        if ($run === self::CAP) {
            $this->assertGreaterThan(0, $flagged, 'no desk of the cap leg draws a flag — (e)\'s flag half reads nothing');
        }

        return $defects;
    }

    /**
     * (e)'s stool rects (card#11058 PR-C): each intern is drawn as a sprite in its own 20 × 32 rect — the
     * size `docs/design/FLOOR.md` § 10.4's art-contract bullet (*The desk's art contract*) and § 8's
     * first row (*one intern per open subagent*) state — keyed
     * `seat~<call_id>` by the intern the wire put at that place (Q3), on one row in the wire's order, no two
     * meeting and none meeting another FACT element of its desk — the art it stands in front of (the side table)
     * is painted first, which is FLOOR.md § 10.6's rule 2 and AT-D3-25's to hold. These runs fail no art, so every intern is the
     * sprite rather than § 9 F14's per-stool glyph.
     *
     * @param  array<string, mixed>  $desk
     * @param  array<string, mixed>  $seat
     * @return list<string>
     */
    private function stoolRectDefects(array $desk, array $seat): array
    {
        $defects = [];
        $stools = $this->elementsOf($desk, 'stool');

        foreach ($stools as $i => $e) {
            $call = $seat['subagents'][$i]['call_id'] ?? null;
            $at = "{$desk['key']}'s intern {$i}";

            if ([$e['w'], $e['h']] !== [20, 32]) {
                $defects[] = "{$at} is drawn {$e['w']} × {$e['h']}, not the art contract's 20 × 32";
            }

            if ($e['call_id'] !== $call || $e['key'] !== "{$seat['seat_id']}~{$call}") {
                $defects[] = "{$at} is keyed «{$e['key']}», not the intern key «{$seat['seat_id']}~{$call}» of the call the wire put there";
            }

            if ($e['art'] !== true) {
                $defects[] = "{$at} is drawn as the fallback glyph on a run that failed no art";
            }

            if ($i > 0 && ($e['y'] !== $stools[0]['y'] || $e['x'] <= $stools[$i - 1]['x'])) {
                $defects[] = "{$at} is off the row, or out of the wire's order";
            }

            foreach ($desk['elements'] as $other) {
                if ($other !== $e && ! in_array($other['kind'], self::ART_KINDS, true) && $this->intersect($e, $other)) {
                    $defects[] = "{$at} meets the desk's {$other['kind']}";
                }
            }
        }

        return $defects;
    }

    /**
     * Whether each drawn text carries its raw unrecognised value — `raw-on-desk.mjs`'s predicate, the ONE
     * copy (the desk leaf guard imports it too), run once under `node` over every ask: a raw id cut to fit
     * a chip is still the raw id on the desk.
     *
     * @param  list<array{0: string, 1: bool, 2: string}>  $asks  [text, truncated, value, …]
     * @return list<bool>
     */
    private function drawsRaw(array $asks): array
    {
        if ($asks === []) {
            return [];
        }

        $process = proc_open(['node', __DIR__.'/raw-on-desk.mjs', '--stdin'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'node could not be started');
        fwrite($pipes[0], (string) json_encode(array_map(static fn (array $a): array => [$a[0], $a[1], $a[2]], $asks)));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "raw-on-desk.mjs failed:\n".$stderr);

        $hits = json_decode($stdout, true);
        $this->assertIsArray($hits);
        $this->assertCount(count($asks), $hits);

        return $hits;
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
