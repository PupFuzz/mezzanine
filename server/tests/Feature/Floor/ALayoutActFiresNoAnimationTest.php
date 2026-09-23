<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **APPENDIX B ROW 13's HALF THAT IS OWED TO STEP 6, AND TO STEP 6 ALONE**: *no
 * `docs/design/FLOOR.md § 6.2` row fired* on a `room.map` apply. card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY IT COULD NOT BE ASSERTED AT ROW 13's OWN STEP, IN THAT ROW'S OWN WORDS: *a client with no
 * animation log and no animations satisfies it for free*. Row 2's gate is the animation-log module's own
 * contract and asserts nothing about a `room.map` apply, and row 13 shipped the map cache. This is the
 * first step at which the claim can fail, so it is the step that owes it.
 *
 * ⛔ § 2.5's ROOM-MAP ROW IS THE RULE: *a map is a layout act and not a fleet event, so no § 6.2 row
 * fires and a desk that moved appears at its new slot.* § 2.5 says the same of `building.layout` — *no
 * animation, for the same reason* — so both are replayed here. Auditing one of two messages that share
 * one rule is how the second one keeps the defect.
 *
 * ⛔ THE DISCRIMINATING HALF IS THAT THE MESSAGES ARRIVED. A run whose messages were dropped satisfies
 * *no row fired* without the renderer ever being asked, so the probe's own delivery outcome is read
 * first: the fake `EventSource` reports `delivered` or `dropped` per message, and this requires
 * `delivered`.
 */
class ALayoutActFiresNoAnimationTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'layout_acts';

    /** The two messages § 2.5 gives one rule, in the order the fixture delivers them. */
    private const ACTS = ['room.map', 'building.layout'];

    public function test_a_room_map_and_a_building_layout_fire_no_section_62_row(): void
    {
        $result = $this->deskRun(self::RUN);

        // (a) THE MESSAGES ARRIVED. Without this the clause below is a measurement that never happened.
        $delivered = [];

        foreach ($result['records'] as $record) {
            if (str_starts_with($record['label'], 'message ')) {
                $delivered[explode(' ', $record['label'])[1]] = $record['outcome'];
            }
        }

        foreach (self::ACTS as $act) {
            $this->assertSame('delivered', $delivered[$act] ?? null,
                "the fixture's `{$act}` never reached the client, so nothing was asked of the renderer");
        }

        // (b) NO ROW FIRED FOR EITHER. The only rows in the log are the snapshot's own held entries, so
        // the population is asserted exactly rather than by a filter that could quietly match nothing.
        $rows = $result['animation_log'];
        $snapshotSeats = array_keys($this->snapshotSeats(self::RUN));

        $this->assertCount(count($snapshotSeats), $rows,
            'the log carries rows beyond the held renders the snapshot itself delivered — a layout act fired one');

        foreach ($rows as $row) {
            $this->assertSame('held', $row['class'], 'a layout act fired an `edge` row');
            $this->assertSame('entered', $row['phase'], 'a layout act ended a held render');
            $this->assertContains("{$row['install_id']}/{$row['seat_id']}", $snapshotSeats);
        }

        // (c) AND THE DESK IS UNTOUCHED: § 2.5 says every seat keeps its state across a map apply.
        $before = $this->firstFrame($result, self::RUN)['desks'];
        $after = $this->lastFrame($result)['desks'];

        $this->assertSame(array_keys($before), array_keys($after), 'a layout act added or removed a desk');

        foreach ($after as $key => $desk) {
            $this->assertSame($before[$key]['render_state'], $desk['render_state'], "[{$key}] a layout act moved a desk's state");
            $this->assertSame($before[$key]['held'], $desk['held'], "[{$key}] a layout act changed which § 6.2 row a desk holds");
        }
    }

    /**
     * ⛔ RED — THE ROOM RE-SET ON A LAYOUT ACT. The most natural version of this defect, and the one
     * § 6.5 argues against at length: the room changed, so the renderer re-sets the room — A17's wall
     * clock and sky — on the message that announced the change. A17 fires only on a `feed.heartbeat`,
     * and nothing else may move the value it sets.
     */
    public function test_red_a_layout_act_that_re_sets_the_room_fires_a17(): void
    {
        $resetting = $this->mutatedModules(['animation-set.js',
            "            if (entry.t !== 'seat.delta' || entry.outcome !== 'applied') {",
            "            if (entry.t === 'room.map' || entry.t === 'building.layout') {\n"
            ."                this.#edge('A17', entry.t, null, null, at);\n            }\n\n"
            ."            if (entry.t !== 'seat.delta' || entry.outcome !== 'applied') {",
        ]);

        $rows = $this->deskRun(self::RUN, $resetting)['animation_log'];
        $fired = array_values(array_filter($rows, static fn (array $r): bool => $r['class'] === 'edge'));

        $this->assertCount(count(self::ACTS), $fired,
            'the RED did not bite: the renderer re-set the room on a layout act and no `edge` row reached the log');
        $this->assertSame(['A17', 'A17'], array_column($fired, 'animation_id'));
        $this->assertSame(self::ACTS, array_column($fired, 'cause'),
            'the planted rows are not caused by the two layout acts, so this RED is red for a reason nobody wrote');
    }
}
