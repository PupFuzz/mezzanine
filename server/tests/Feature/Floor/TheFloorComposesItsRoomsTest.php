<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **THE COMPOSITION, AND THE THREE OWED HALVES STEP 7 TAKES ON.** `docs/design/FLOOR.md`
 * Appendix B row 7's own claims, card#7341 step 7 — the ones AT-D3-3 and AT-D3-18 do not reach:
 *
 *   · § 4.6's floor PLAN — each room's grid at its `origin` over the floor's `hallway`, the default
 *     arrangement at § 12's gap where there is none, and the floor's extent as their union.
 *   · § 4.2's ONE back-wall band — the wall clock and the windows, drawn once across that extent —
 *     and § 6.5's rule for when their value is SET and when they have none at all.
 *   · § 3.2's overflow row, and § 4.6's room the fleet reports no seat for.
 *   · § 9 F18 (two footprints sharing a pixel), F16 (a room map request that fails) and F17 (the
 *     layout request that fails, on the FLOOR route).
 *   · Appendix B row 13's halves owed here: T39's *re-renders one room* and its *one event-log line
 *     written*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THESE CLAIMS HAVE NO NUMBERED ACCEPTANCE TEST, and that is § 11's own position rather than a
 * gap: an acceptance test in that section is bound to a fixture and a suite, and row 7's Gate cell
 * names the two that exist. What row 7 states in prose is gated HERE, by this row's own test, the
 * way rows 2, 11, 12 and 13 are gated by theirs.
 *
 * ⛔ EVERY GEOMETRIC EXPECTATION IS RE-DERIVED FROM THE FIXTURE'S OWN MAPS AND § 12's PUBLISHED GAP,
 * never transcribed. A floor's extent is arithmetic over documents the client holds, so a test that
 * wrote the answer down would agree with itself while the maps moved underneath it.
 */
class TheFloorComposesItsRoomsTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const UNPLANNED = 'plan_default';

    private const PLANNED = 'plan_placed';

    private const OVERLAP = 'plan_overlap';

    private const MAP_FAILS = 'plan_map_fails';

    private const LAYOUT_FAILS = 'plan_layout_fails';

    private const ROOM_MAP = 'plan_room_map';

    private const COLD_DEAD = 'plan_cold_dead';

    /**
     * GREEN — a floor with no plan: its rooms side by side, left to right in `install_id` ascending,
     * top edges aligned, each at its own map's size, § 12's gap apart, and no hallway.
     */
    public function test_green_a_floor_with_no_plan_is_arranged_by_the_rule_that_needs_no_author(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::UNPLANNED));
        $gap = $this->publishedGap();

        $this->assertFalse($frame['planned'], 'a floor whose rooms carry no `origin` was drawn as a planned one');
        $this->assertNull($frame['hallway'], 'a floor with no plan drew a hallway');

        $ids = array_column($frame['rooms'], 'install_id');
        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids, 'the rooms are not left to right in `install_id` ascending (§ 2.1 row 6)');

        // Each room's `x` is the sum of the widths of the rooms before it plus one gap per boundary,
        // re-added here from the fixture's own footprints rather than written down.
        $x = 0;

        foreach ($frame['rooms'] as $room) {
            $this->assertSame($x, $room['origin']['x'], "[{$room['install_id']}] is not at the running arrangement offset");
            $this->assertSame(0, $room['origin']['y'], "[{$room['install_id']}] does not have its top edge aligned");

            $x += $room['footprint']['width'] + $gap;
        }

        // The extent is the UNION, computed the same way from the rooms the frame drew.
        $this->assertSame($this->unionOf($frame), $frame['extent'],
            "the floor's extent is not the union of everything drawn on it");
    }

    /**
     * GREEN — a planned floor: every room at its authored `origin`, the hallway drawn, and the
     * extent the union of the rooms AND the hallway. ⭐ `beta` sits at `aimla`'s right EDGE, which is
     * § 4.6's *two rooms may share an edge and may never share a pixel* — the control on F18.
     */
    public function test_green_a_planned_floor_draws_each_room_at_its_origin_over_its_hallway(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::PLANNED));

        $this->assertTrue($frame['planned'], 'a floor whose every room carries an `origin` was not drawn as planned');
        $this->assertNotNull($frame['hallway'], 'the planned floor drew no hallway');
        $this->assertSame($this->unionOf($frame), $frame['extent'],
            "the planned floor's extent is not the union of its rooms and its hallway");

        // The shared edge: `aimla` ends exactly where `beta` begins, and NEITHER is reported as an
        // overlap. A closed-interval comparison would refuse exactly the building § 4.6 describes.
        $aimla = $this->roomOf($frame, 'aimla');
        $beta = $this->roomOf($frame, 'beta');

        $this->assertSame($aimla['footprint']['x'] + $aimla['footprint']['width'], $beta['footprint']['x'],
            'the fixture no longer places two rooms edge to edge, so the shared-edge control asserts nothing');
        $this->assertSame([], $frame['overlaps'], 'two rooms sharing an EDGE were reported as sharing a pixel');
        $this->assertSame([], array_values(array_filter($frame['notices'],
            static fn (string $n): bool => str_contains($n, 'overlap'))),
            'an overlap notice was drawn over a floor whose rooms only share an edge');

        // § 4.6: a hallway declares NO `desks` layer — it seats nobody and the slot function runs
        // per room. Read from the delivered document rather than assumed.
        $this->assertArrayNotHasKey('slots', $frame['hallway'],
            'the hallway was read as declaring slots');
    }

    /**
     * GREEN — § 4.2's ONE back-wall band, spanning the floor's whole extent above the composed slab.
     * A floor of several offices has one clock over all of them.
     */
    public function test_green_one_band_spans_the_whole_floor_however_many_rooms_are_on_it(): void
    {
        foreach ([self::UNPLANNED, self::PLANNED] as $run) {
            $frame = $this->lastFloor($this->floorRun($run));

            $this->assertGreaterThan(1, count($frame['rooms']),
                "[{$run}] draws one room, so *one band over N rooms* is not exercised by it");
            $this->assertSame([
                'x' => $frame['extent']['x'],
                'y' => $frame['extent']['y'],
                'width' => $frame['extent']['width'],
            ], $frame['band'], "[{$run}] the band does not span the floor's whole extent");
        }
    }

    /**
     * GREEN — § 4.2's wall clock and sky: the VIEWER's own clock at minute resolution, with no second
     * hand, and § 6.5's *before the first render that establishes a live feed the room has no value*.
     */
    public function test_green_the_room_is_set_by_a_live_feed_and_is_unset_before_one(): void
    {
        $set = $this->lastFloor($this->floorRun(self::UNPLANNED))['room'];

        $this->assertNotNull($set, 'the connect snapshot established a live feed and the room was never set');
        $this->assertSame(19, $set['hours'], "the room does not read the viewer's own civil hour the scenario states");
        $this->assertSame('dusk', $set['sky'], 'the sky phase is not the one that hour carries');
        $this->assertSame('your local time', $set['label'],
            'the clock is not labelled as the viewer\'s own, so a viewer could read it as wire data');

        // ⛔ NO SECOND HAND. Minute resolution only: the value carries hours and minutes and the two
        // angles a face is drawn from, and nothing at second resolution — a second hand stepping in
        // 15 s jumps is the *looks broken* a later maintainer repairs with a `setInterval`.
        $this->assertSame(['hours', 'minutes', 'hour_angle_deg', 'minute_angle_deg', 'sky', 'label'],
            array_keys($set), 'the room render carries a member beyond § 4.2\'s minute-resolution clock and sky');

        // § 6.5: a cold start onto a feed that never came up sets NOTHING — "a plausible time on a
        // page that has never been live is exactly the zero that rule refuses".
        $this->assertNull($this->lastFloor($this->floorRun(self::COLD_DEAD))['room'],
            'the room was set on a page that never established a live feed');
    }

    /**
     * GREEN — § 3.2's overflow row is the FLOOR's, one band below the composed floor, and § 5.5's
     * notice names the room on a floor of several because *which map is short* is its whole point.
     */
    public function test_green_seats_past_the_maps_slots_are_the_floors_overflow_row(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::UNPLANNED));
        $beta = $this->roomOf($frame, 'beta');

        $this->assertGreaterThan(0, count($beta['overflow']),
            'no room on this floor is short of desks, so the overflow rule is not exercised');
        $this->assertSame($beta['overflow'], $frame['overflow'],
            "the short room's seats did not reach the FLOOR's overflow row");
        $this->assertContains('floor map is short '.count($beta['overflow']).' desks — beta', $frame['notices'],
            'the notice does not name the room, which is the question it exists to answer on a floor of several maps');

        // Every seat is placed SOMEWHERE: a slot or the overflow row, and never dropped.
        $seats = array_keys($this->snapshotSeats(self::UNPLANNED));
        $placed = array_merge(array_column($beta['desks'], 'key'), $beta['overflow']);

        foreach ($seats as $key) {
            if (! str_starts_with($key, 'beta/')) {
                continue;
            }

            $this->assertContains($key, $placed, "[{$key}] a seat the fleet reports is on no desk and in no overflow row");
        }

        // § 4.6: a room the fleet reports no seat for is drawn and LABELLED, never omitted.
        $this->assertFalse($this->roomOf($frame, 'gamma')['reported']);
        $this->assertContains('no seats reported for this room', $frame['notices'],
            'a room the client holds no seat for was omitted or drawn without its label');
    }

    /** GREEN — § 9 F18: both rooms drawn, the later `install_id` on top, under § 5.5's notice. */
    public function test_green_two_overlapping_rooms_are_both_drawn_and_named(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::OVERLAP));

        $this->assertSame([['aimla', 'beta']], $frame['overlaps'],
            'the read-time overlap was not found, or not named with the two `install_id`s in key order');
        $this->assertContains('rooms `aimla` and `beta` overlap on this floor', $frame['notices']);

        // Both rooms are still drawn, and every desk of both stays reachable.
        $this->assertNotNull($this->roomOf($frame, 'aimla')['footprint']);
        $this->assertNotNull($this->roomOf($frame, 'beta')['footprint']);
        $this->assertSame(['aimla', 'beta', 'gamma'], $frame['draw_order'],
            'the draw order is not key order, so the LATER `install_id` is not the one on top');
    }

    /**
     * GREEN — Appendix B row 13's F16 half, owed here: the room renders its desks with NO map, in a
     * plain grid, under *room map could not be loaded — HTTP N* naming the room; it has no extent, so
     * F18's determination leaves it out; and the shipped default is NOT drawn in its place.
     */
    public function test_green_a_room_whose_map_failed_draws_placeholders_under_its_own_notice(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::MAP_FAILS));
        $room = $this->roomOf($frame, 'aimla');

        $this->assertTrue($room['mapless'], 'the room whose map request failed was drawn as though it held one');
        $this->assertNull($room['map'], 'a map was held for a room whose only request failed');
        $this->assertNull($room['slots'], 'the mapless room reports a slot count — it has no map to declare one');
        $this->assertNull($room['footprint'], 'the mapless room has an extent although the client holds no map for it');
        $this->assertContains('room map could not be loaded — HTTP 503 — aimla', $frame['notices']);

        // Every desk is still drawn, in a plain grid, in § 3.2's own order — F16 costs the room and
        // never the identity — and none of them is in the FLOOR's overflow row, which is about a
        // map's slot count and not about a room with no map.
        $this->assertCount(4, $room['desks'], 'the mapless room drew no desks, which is the seat-invisible defect F16 refuses');

        foreach ($room['desks'] as $index => $desk) {
            $this->assertNull($desk['slot'], 'a mapless room placed a desk in a slot no map declares');
            $this->assertSame($index, $desk['grid_index'], 'the placeholder grid is not in a stable order');
            $this->assertNotContains($desk['key'], $frame['overflow'],
                "[{$desk['key']}] a mapless room's desk was put in the floor's overflow row");
        }

        // The extent leaves it out, which is what F18's determination does with it.
        $this->assertSame($this->unionOf($frame), $frame['extent']);
    }

    /**
     * GREEN — Appendix B row 13's F17 half, owed here: the FLOOR route renders the same statement,
     * and on a cold-start deep link it composes NOTHING — it does not turn the segment into a
     * one-room floor.
     */
    public function test_green_a_failed_layout_composes_nothing_on_the_floor_route(): void
    {
        $frame = $this->lastFloor($this->floorRun(self::LAYOUT_FAILS));

        $this->assertFalse($frame['composed'], 'a failed layout request composed a building anyway');
        $this->assertSame('the building layout could not be loaded — HTTP 503', $frame['statement'],
            'the floor route does not render F17\'s statement, or does not name the status');
        $this->assertNull($frame['floor'], 'the route\'s segment was composed into a floor with no layout to say it is one');

        // The installs are listed as rooms with NO FLOOR CLAIMED, so every seat stays reachable.
        $this->assertNotSame([], $frame['rooms']);

        foreach ($frame['rooms'] as $room) {
            $this->assertFalse($room['floor_claimed'],
                "[{$room['install_id']}] is listed with a floor claimed, which needs the document that failed");
        }
    }

    /**
     * GREEN — Appendix B row 13's T39 halves, owed here: a `room.map` is delivered to the apply, the
     * room RE-RENDERS at the new version, and the ONE event-log line that apply returns is written
     * into § 5.5's record.
     */
    public function test_green_a_room_map_re_renders_one_room_and_writes_one_record_line(): void
    {
        $result = $this->floorRun(self::ROOM_MAP);
        $before = $this->firstFloorWithRoom($result, 'aimla');
        $after = $this->lastFloor($result);

        $this->assertSame('default', $this->roomOf($before, 'aimla')['map']['source']);
        $this->assertSame(
            ['source' => 'authored', 'map_version' => 4],
            $this->roomOf($after, 'aimla')['map'],
            'the room did not re-render at the version the `room.map` named',
        );
        $this->assertNotSame(
            $this->roomOf($before, 'aimla')['slots'],
            $this->roomOf($after, 'aimla')['slots'],
            'the re-fetched map declares the same slot count as the one before it, so a room that never '
            .'re-rendered would pass this leg',
        );

        // ONE line, naming the room and the revision, in the record the client protocol owns.
        $lines = array_values(array_filter($result['final']['event_log'],
            static fn (string $line): bool => str_contains($line, 'room aimla')));

        $this->assertCount(1, $lines, 'the applied `room.map` wrote no line into § 5.5\'s record, or wrote more than one');
        $this->assertStringContainsString('map revision 4', $lines[0]);

        // And ONLY that room moved: `beta` and `gamma` are untouched by another room's map.
        foreach (['beta', 'gamma'] as $other) {
            $this->assertSame($this->roomOf($before, $other)['map'], $this->roomOf($after, $other)['map'],
                "[{$other}] another room's `room.map` re-fetched this one");
        }
    }

    /**
     * ⛔ RED — THE GAP CLOSED. § 12's gap is what makes two rooms' own perimeter walls read as two
     * rooms rather than as one double wall; at zero the rooms abut and the floor is one room wide.
     */
    public function test_red_a_default_arrangement_with_no_gap_abuts_two_rooms(): void
    {
        $noGap = $this->mutatedModules([self::FLOOR_LAYOUT,
            'export const ROOM_GAP_PX = 64;',
            'export const ROOM_GAP_PX = 0;',
        ]);

        $frame = $this->lastFloor($this->floorRun(self::UNPLANNED, $noGap));
        $rooms = $frame['rooms'];

        $this->assertSame(
            $rooms[0]['footprint']['x'] + $rooms[0]['footprint']['width'],
            $rooms[1]['origin']['x'],
            'the RED did not bite: the gap was removed and the rooms are still apart',
        );

        // The shipped arrangement puts § 12's published figure between them.
        $shipped = $this->lastFloor($this->floorRun(self::UNPLANNED))['rooms'];

        $this->assertSame(
            $shipped[0]['footprint']['x'] + $shipped[0]['footprint']['width'] + $this->publishedGap(),
            $shipped[1]['origin']['x'],
            'the shipped arrangement does not put § 12\'s gap between two rooms',
        );
    }

    /**
     * ⛔ RED — THE EXTENT THAT IS NOT THE UNION. Take the first room's footprint as the floor's
     * extent: the band then spans one room of several, and the camera pans over a floor narrower
     * than the building the operator authored.
     */
    public function test_red_an_extent_that_is_not_the_union_narrows_the_floor_and_its_band(): void
    {
        $firstOnly = $this->mutatedModules([self::FLOOR_LAYOUT,
            '    const extent = boxes.length === 0 ? null : Object.freeze({',
            "    const extent = boxes.length === 0 ? null : Object.freeze({\n"
            ."        x: boxes[0].x,\n        y: boxes[0].y,\n        width: boxes[0].width,\n"
            ."        height: boxes[0].height,\n    });\n\n"
            .'    const unused = boxes.length === 0 ? null : Object.freeze({',
        ]);

        $planted = $this->lastFloor($this->floorRun(self::UNPLANNED, $firstOnly));
        $shipped = $this->lastFloor($this->floorRun(self::UNPLANNED));

        $this->assertLessThan($shipped['extent']['width'], $planted['extent']['width'],
            'the RED did not bite: the extent was taken from one room and is still as wide as the union');
        $this->assertSame($planted['extent']['width'], $planted['band']['width'],
            'the band no longer follows the extent, so this RED is red for a reason nobody wrote');
        $this->assertSame($this->unionOf($shipped), $shipped['extent'],
            'the shipped extent is not the union of everything drawn on the floor');
    }

    /**
     * ⛔ RED — THE CLOSED-INTERVAL FOOTPRINT. Compare footprints as closed rectangles and two rooms
     * that share an EDGE are reported as sharing a pixel — which refuses exactly the building
     * § 4.6 describes, *a floor subdivided into two rooms with one wall between them*.
     */
    public function test_red_a_closed_interval_comparison_reports_a_shared_edge_as_an_overlap(): void
    {
        // The clause the shared edge sits exactly on: `beta` begins where `aimla` ends, so it is
        // `b.x < a.x + a.width` that separates them and closing THAT interval is the defect.
        $closed = $this->mutatedModules([self::FLOOR_LAYOUT,
            '        && b.x < a.x + a.width',
            '        && b.x <= a.x + a.width',
        ]);

        $frame = $this->lastFloor($this->floorRun(self::PLANNED, $closed));

        $this->assertNotSame([], $frame['overlaps'],
            'the RED did not bite: the comparison was closed on one axis and the shared edge is still not an overlap');
        $this->assertContains('rooms `aimla` and `beta` overlap on this floor', $frame['notices']);

        // And the shipped half-open comparison still FINDS a real overlap, so the fix cannot be
        // *never report one*.
        $this->assertSame([['aimla', 'beta']], $this->lastFloor($this->floorRun(self::OVERLAP))['overlaps'],
            'the shipped comparison no longer finds two rooms that really do share pixels');
    }

    /**
     * ⛔ RED — THE LAST HELD MAP DRAWN AS THE CURRENT ONE. F16's *Never* is *drawing the shipped
     * default in its place*, and its reachable form on a client that caches by version is to keep
     * drawing the map it already held: the room then renders as one the operator authored while the
     * request for it is failing, with no notice to say so.
     */
    public function test_red_a_failed_map_drawn_from_the_last_held_one_hides_the_failure(): void
    {
        $silent = $this->mutatedModules([self::FLOOR_SCREEN,
            '            const mapless = state.state === null || state.state.failure !== null;',
            '            const mapless = state.state === null;',
        ]);

        $frame = $this->lastFloor($this->floorRun(self::ROOM_MAP, $silent));
        $room = $this->roomOf($frame, 'aimla');

        // The `plan_room_map` run holds a map and then re-fetches; under the plant a room whose
        // request failed would draw the held document. The observable is the MAP_FAILS run, where
        // nothing was ever held — so the plant is read there, on the room with a failure standing.
        $failed = $this->lastFloor($this->floorRun(self::MAP_FAILS, $silent));

        $this->assertFalse($this->roomOf($failed, 'aimla')['mapless'],
            'the RED did not bite: the failure was ignored and the room is still drawn as mapless');
        $this->assertSame([], array_values(array_filter($failed['notices'],
            static fn (string $n): bool => str_contains($n, 'room map could not be loaded'))),
            'the planted client still drew F16\'s notice, so the defect it plants is not the silent one');

        // The shipped client says so by name over the same bytes.
        $this->assertContains('room map could not be loaded — HTTP 503 — aimla',
            $this->lastFloor($this->floorRun(self::MAP_FAILS))['notices']);
        $this->assertNotNull($room, 'the control run drew no `aimla` room at all');
    }

    /**
     * ⛔ RED — F17's *Never*: a failed layout composed into a one-room floor. The segment is then
     * read as a floor key on the strength of a document that failed, and the viewer is shown a
     * building nobody authored, drawn with confidence.
     */
    public function test_red_a_failed_layout_composed_into_a_one_room_floor(): void
    {
        $composing = $this->mutatedModules([self::FLOOR_SCREEN,
            '        return floors(this.#snapshotShape(), this.#building.floors);',
            "        return floors(this.#snapshotShape(), this.#building.floors ?? []);",
        ]);

        $frame = $this->lastFloor($this->floorRun(self::LAYOUT_FAILS, $composing));

        $this->assertTrue($frame['composed'],
            'the RED did not bite: the empty layout was read out of a failed fetch and nothing was composed');
        $this->assertNotNull($frame['floor'],
            'the planted client composed no floor for the segment, so this RED is red for a reason nobody wrote');

        // The shipped client composes nothing, and still says why.
        $shipped = $this->lastFloor($this->floorRun(self::LAYOUT_FAILS));

        $this->assertFalse($shipped['composed']);
        $this->assertNotNull($shipped['statement']);
    }

    /**
     * ⛔ RED — THE SEAT SILENTLY DROPPED. § 3.2: a seat past the map's `S` is the overflow row, and
     * a silent drop *is a seat that exists and is invisible, which is the same lie as an empty office
     * and is worse for being local*.
     */
    public function test_red_seats_past_the_maps_slots_dropped_instead_of_overflowed(): void
    {
        $dropping = $this->mutatedModules([self::FLOOR_LAYOUT,
            '            overflow.push(seat.key);',
            '            continue;',
        ]);

        $frame = $this->lastFloor($this->floorRun(self::UNPLANNED, $dropping));

        $this->assertSame([], $frame['overflow'],
            'the RED did not bite: the overflow push was removed and the floor still carries an overflow row');
        $this->assertSame([], array_values(array_filter($frame['notices'],
            static fn (string $n): bool => str_contains($n, 'floor map is short'))),
            'the planted client still drew the short-map notice, so the defect it plants is not the silent drop');

        // The shipped client keeps every seat, which the GREEN above asserts seat by seat.
        $this->assertNotSame([], $this->lastFloor($this->floorRun(self::UNPLANNED))['overflow']);
    }

    /**
     * ⛔ RED — THE ROOM SET ON EVERY RENDER. § 6.5's property is that A17's value is set by a render
     * that establishes or re-establishes a LIVE feed and by nothing else; a client that sets it on
     * every render shows a plausible time on a page that has never been live, and — once step 8's
     * poll exists — re-reads the viewer's clock every 10 s for the whole duration of a dead feed,
     * which is § 6.3's second forbidden form arriving through the recovery path.
     */
    public function test_red_a_room_set_on_every_render_shows_a_time_on_a_page_that_was_never_live(): void
    {
        $always = $this->mutatedModules([self::FLOOR_SCREEN,
            '        if (heartbeat || established) {',
            '        if (true) {',
        ]);

        $this->assertNotNull($this->lastFloor($this->floorRun(self::COLD_DEAD, $always))['room'],
            'the RED did not bite: the room is set unconditionally and a page that never went live still shows none');
        $this->assertNull($this->lastFloor($this->floorRun(self::COLD_DEAD))['room'],
            'the shipped client set the room on a page that never established a live feed');
    }

    /** § 12's *Gap between rooms on a floor with no plan*, read out of that table on every run. */
    private function publishedGap(): int
    {
        $this->assertSame(1, preg_match(
            '/\| Gap between rooms on a floor with no plan \| \*\*(\d+) px\*\* \|/',
            $this->floorMd(),
            $m,
        ), '§ 12 publishes no gap row in the form this test reads, so every arrangement below would be unread');

        return (int) $m[1];
    }

    /**
     * The union of everything the frame draws — every room's footprint and the hallway — computed
     * from the frame rather than from a figure written here.
     *
     * @param  array<string, mixed>  $frame
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function unionOf(array $frame): ?array
    {
        $boxes = array_values(array_filter(array_column($frame['rooms'], 'footprint')));

        if ($frame['hallway'] !== null) {
            $boxes[] = [
                'x' => 0,
                'y' => 0,
                'width' => $frame['hallway']['pixel_width'],
                'height' => $frame['hallway']['pixel_height'],
            ];
        }

        if ($boxes === []) {
            return null;
        }

        $x = min(array_column($boxes, 'x'));
        $y = min(array_column($boxes, 'y'));

        return [
            'x' => $x,
            'y' => $y,
            'width' => max(array_map(static fn (array $b): int => $b['x'] + $b['width'], $boxes)) - $x,
            'height' => max(array_map(static fn (array $b): int => $b['y'] + $b['height'], $boxes)) - $y,
        ];
    }
}
