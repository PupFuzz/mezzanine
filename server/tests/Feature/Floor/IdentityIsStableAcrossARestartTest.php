<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-3 — identity is stable across a restart.** `docs/design/FLOOR.md` Appendix B row 7's gate,
 * card#7341 step 7.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CLAIM IS THAT A SLOT IS A FUNCTION OF THE KEY, and the four runs are what make the three
 * WRONG functions observable: arrival order (a run whose messages arrive in another order), delivery
 * order (a snapshot whose seats are serialised in another order), and session (a seat that restarts).
 * Two browsers, two reloads and two server restarts must agree without a stored position and without
 * a server field (§ 3.2).
 *
 * ⛔ EVERY EXPECTED SLOT IS § 3.2's OWN PUBLISHED TABLE, READ FROM THE DOCUMENT ON EVERY RUN
 * (`DrivesTheFloorScreen::documentSlots()`), and so is § 3.3's worked collision. Nothing below
 * transcribes a slot number: this test and `tools/design/verify-floor.py`'s G8 read one table from
 * two sides, so the shipped function, the document's worked assignment and the shipped map's own
 * `desks` count are held to one answer.
 *
 * ⚠ AT-D3-3 NAMES NO DISCRIMINATING CONTROL, and that is stated rather than substituted for: what
 * stands in its place is the two-sidedness of the GREENs — the colliding arrival moves exactly one
 * desk and writes exactly one A16 row, the non-colliding arrival moves none and writes none, so a
 * client that moved everything and a client that logged nothing each fail one of the pair.
 */
class IdentityIsStableAcrossARestartTest extends TestCase
{
    use DrivesTheFloorScreen;

    /** § 3.2's own worked case, and the three re-orderings of it the GREEN replays. */
    private const ORDERS = ['slots', 'slots_reversed', 'slots_shuffled'];

    private const ROOM = 'aimla';

    /**
     * GREEN — the four assignments of § 3.2's worked table, identically, in all four runs: the
     * snapshot as served, the same snapshot applied again by a discarded client (a reload), the same
     * seats in reverse order, and the same seats shuffled.
     */
    public function test_green_every_run_assigns_section_32s_worked_table(): void
    {
        $published = $this->documentSlots();

        // The modulus the table is stated over IS the shipped map's slot count, and the fixture's
        // map declares that many — so `S` is one number in the document, in the shipped file and in
        // the bytes these runs replay, rather than three that agree today.
        $this->assertSame($published['modulus'], $this->shippedDefaultSlots(),
            '§ 3.2 works its assignment over a modulus the shipped default map does not declare as its `desks` count');

        foreach (self::ORDERS as $run) {
            $frame = $this->lastFloor($this->floorRun($run));

            $this->assertSame($published['modulus'], $this->roomOf($frame, self::ROOM)['slots'],
                "[{$run}] the room renders a map whose `S` is not the modulus § 3.2 works its table over");
            $this->assertSame($published['slots'], $this->slotsOf($frame, self::ROOM),
                "[{$run}] the assignment is not § 3.2's worked table — the slot is a function of the "
                .'delivery order rather than of the key');
        }

        // The RELOAD: the whole scenario replayed by a client discarded and rebuilt in one process.
        // Two runs of `repeat` are two clients over identical bytes, which is what a reload is.
        $reloads = $this->replayRepeatedly(self::ORDERS[0], 2);

        $this->assertCount(2, $reloads);

        foreach ($reloads as $n => $run) {
            $frame = $run['floor_renders'][count($run['floor_renders']) - 1]['frame'];

            $this->assertSame($published['slots'], $this->slotsOf($frame, self::ROOM),
                "reload {$n} assigned a different slot set — the desk moved because the client restarted");
        }
    }

    /**
     * GREEN — an arrival that collides: § 3.3's worked case. The arriving seat takes the slot, the
     * incumbent probes off it, NO OTHER DESK MOVES, and the animation log carries exactly one A16
     * row whose cause is the arriving seat.
     */
    public function test_green_a_colliding_arrival_moves_one_desk_and_writes_one_a16_row(): void
    {
        $worked = $this->documentCollision();
        $published = $this->documentSlots()['slots'];
        $result = $this->floorRun('collision');
        $after = $this->slotsOf($this->lastFloor($result), self::ROOM);

        $arriving = self::ROOM.'/'.$worked['arriving'];
        $displaced = self::ROOM.'/'.$worked['displaced'];

        $this->assertSame($worked['slot'], $after[$arriving] ?? null,
            "the arriving seat did not take the slot § 3.3 works out for it");
        $this->assertSame($worked['moves_to'], $after[$displaced] ?? null,
            'the displaced incumbent did not probe to the slot § 3.3 works out for it');

        // "Every other desk is untouched" — asserted over the whole published table rather than by
        // spot-checking one desk, because the defect this clause exists against is a re-hash of the
        // room and that shows up on the desks nobody looked at.
        foreach ($published as $key => $slot) {
            if ($key === $displaced) {
                continue;
            }

            $this->assertSame($slot, $after[$key] ?? null,
                "[{$key}] a desk outside the collision chain moved — the arrival re-hashed the room");
        }

        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(1, $rows, 'the log does not carry exactly one A16 row for one displacement');
        $this->assertSame($arriving, $rows[0]['cause'],
            "A16's cause is not the arriving seat's key, which is § 11's own answer for this row");
        $this->assertSame($worked['displaced'], $rows[0]['seat_id'],
            'the A16 row names a seat other than the one that MOVED');
        $this->assertSame('edge', $rows[0]['class']);
        $this->assertSame('fired', $rows[0]['phase']);
    }

    /**
     * GREEN — an arrival that does not collide: it takes its own free slot, NO DESK MOVES AT ALL,
     * and the log carries no A16 row.
     */
    public function test_green_a_non_colliding_arrival_moves_nothing_and_writes_no_a16_row(): void
    {
        $published = $this->documentSlots();
        $result = $this->floorRun('no_collision');
        $after = $this->slotsOf($this->lastFloor($result), self::ROOM);

        foreach ($published['slots'] as $key => $slot) {
            $this->assertSame($slot, $after[$key] ?? null,
                "[{$key}] a desk moved on an arrival that collided with nothing");
        }

        // The arriving seat is whichever key the run added, and its slot is re-derived here from
        // § 3.2's published function rather than transcribed: `h mod S` with the slot free.
        $arrived = array_diff(array_keys($after), array_keys($published['slots']));

        $this->assertCount(1, $arrived, 'the run did not add exactly one seat to the room');

        $key = (string) reset($arrived);

        $this->assertSame($this->fnv1a32($key) % $published['modulus'], $after[$key],
            'the non-colliding arrival did not take the slot § 3.2\'s function gives it at zero probes');
        $this->assertSame([], $this->rowsFor($result, 'A16'),
            'an A16 row was written although no arrival collided — a desk move was claimed that did not happen');
    }

    /**
     * GREEN — TWO ARRIVALS IN ONE RENDER (§ 14 item 27). `fx-collision`'s `two_arrivals` run inserts
     * § 3.3's colliding `aimla-impl-4` and the free-slotted `aimla-win-1` in one turn, both released
     * by one discovery snapshot. `aimla-pm` is displaced once, so the log carries exactly one A16
     * row, and § 11 now states its cause for this case: the key of the arrival that sorts LOWEST in
     * § 3.2's `order`, ascending by `(h, seat_id)`.
     *
     * ⛔ THE EXPECTED CAUSE IS RE-DERIVED FROM § 3.2's PUBLISHED FUNCTION, never transcribed, and the
     * run is first checked to BE the case the ruling is about: a run whose two seats landed in two
     * renders would hold two single-arrival turns, where any rule answers the same, and this GREEN
     * would pass over the case it exists to pin.
     *
     * ⚠ THE PAIR ALSO SEPARATES § 3.2's ORDER FROM PLAIN KEY ORDER, and that is asserted rather than
     * assumed: `aimla-win-1` hashes lower and `aimla/aimla-impl-4` is the lower string, so a client
     * that sorted keys as text would name the other seat and red here.
     */
    public function test_green_two_arrivals_in_one_render_record_the_lowest_sorting_arrival_as_a16s_cause(): void
    {
        $result = $this->floorRun('two_arrivals');
        [$lowest, $highest] = $this->twoArrivalsInOneRender($result);

        $this->assertNotSame(min($lowest, $highest), $lowest,
            'the fixture no longer separates § 3.2\'s order from plain key order, so this GREEN would '
            .'not tell the published rule from a text sort');

        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(1, $rows, 'the log does not carry exactly one A16 row for one displacement');
        $this->assertSame($this->documentCollision()['displaced'], $rows[0]['seat_id'],
            'the A16 row names a seat other than the one § 3.3 says the colliding arrival displaces');
        $this->assertSame($lowest, $rows[0]['cause'],
            "A16's cause is not the arrival that sorts lowest in § 3.2's order, which is § 11's "
            .'answer for several arrivals in one render (§ 14 item 27)');
    }

    /**
     * ⛔ RED — THE DESK KEYED ON `session.session_id`. § 3.2's key is `(install_id, seat_id)`; a
     * client that hashes the session instead moves a desk, character and all, every time a seat
     * restarts its session. Watch it once: it is the identity defect D1 § 3.4's 30-day incident is
     * the general form of.
     *
     * ⚠ THE `/clear` IS REPLAYED FROM `fx-collision`'s OWN `session_restart` RUN AND NOT FROM
     * `fx-clear-trace`, WHICH AT-D3-3's RED NAMES AS THE EXAMPLE. Two reasons, both mechanical:
     * that file holds exactly ONE run and `TheClearTraceShowsNoIdleAnywhereTest` asserts so, and its
     * run is driven by the desk-only rig, which the probe refuses to start beside the floor screen.
     * What the RED needs is *a `/clear` on any seat*, which is the delta that mints a new
     * `session.session_id` — D2 § 10's E9, authored on one seat here with nothing else moving, so a
     * slot that changes changed because the SESSION did.
     */
    public function test_red_a_desk_keyed_on_the_session_moves_when_a_seat_restarts(): void
    {
        $keyed = $this->mutatedModules([self::FLOOR_LAYOUT,
            "            h: hashSeat(seat.install_id, seat.seat_id),",
            "            h: hashSeat(seat.install_id, seat.session?.session_id ?? seat.seat_id),",
        ]);

        $result = $this->floorRun('session_restart', $keyed);

        $before = $this->slotsOf($this->firstFloorWithRoom($result, self::ROOM), self::ROOM);
        $after = $this->slotsOf($this->lastFloor($result), self::ROOM);

        $moved = array_keys(array_filter($before, static fn (?int $slot, string $key): bool => ($after[$key] ?? null) !== $slot, ARRAY_FILTER_USE_BOTH));

        $this->assertNotSame([], $moved,
            'the RED did not bite: the desk was keyed on the session and no desk moved when a seat restarted one');

        // The shipped code over the same bytes moves nothing — which is what makes the plant above
        // evidence about the KEY rather than about the fixture.
        $shipped = $this->floorRun('session_restart');

        $this->assertSame(
            $this->slotsOf($this->firstFloorWithRoom($shipped, self::ROOM), self::ROOM),
            $this->slotsOf($this->lastFloor($shipped), self::ROOM),
            'the shipped assignment moved a desk on a session restart',
        );
    }

    /**
     * ⛔ SECOND RED — SLOTS BY SORTED `seat_id` POSITION. § 3.2 rejects it by name: provisioning one
     * seat shifts EVERY later desk by one, so an operator's spatial memory of the office is undone
     * by an arrival. `aimla-alpha` sorts below every seat the snapshot carries, so under the plant
     * every desk on the floor moves.
     */
    public function test_red_slots_by_sorted_seat_id_shift_every_desk_on_an_arrival(): void
    {
        $sorted = $this->mutatedModules([self::FLOOR_LAYOUT,
            "        .sort((a, b) => a.h - b.h || (a.seat_id < b.seat_id ? -1 : a.seat_id > b.seat_id ? 1 : 0));",
            "        .sort((a, b) => (a.seat_id < b.seat_id ? -1 : a.seat_id > b.seat_id ? 1 : 0))\n"
            ."        .map((seat, index) => ({ ...seat, h: index }));",
        ]);

        $result = $this->floorRun('alpha_arrival', $sorted);
        $before = $this->slotsOf($this->firstFloorWithRoom($result, self::ROOM), self::ROOM);
        $after = $this->slotsOf($this->lastFloor($result), self::ROOM);

        $shifted = array_keys(array_filter(
            $before,
            static fn (?int $slot, string $key): bool => ($after[$key] ?? null) !== $slot,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertCount(count($before), $shifted,
            'the RED did not bite: slots were assigned by sorted position and some desk kept its slot');

        // And the shipped function moves none of them on the same arrival.
        $shipped = $this->floorRun('alpha_arrival');

        $this->assertSame(
            $this->documentSlots()['slots'],
            array_intersect_key($this->slotsOf($this->lastFloor($shipped), self::ROOM), $this->documentSlots()['slots']),
            'the shipped assignment shifted a desk when one seat was provisioned',
        );
    }

    /**
     * ⛔ THIRD RED — THE HIGHEST-SORTING ARRIVAL RECORDED AS A16's CAUSE. The two-arrival run's A16
     * row then names the other seat, which is what makes the GREEN above evidence about the rule
     * rather than about a fixture with one possible answer.
     */
    public function test_red_recording_the_highest_sorting_arrival_names_the_other_seat(): void
    {
        $highestFirst = $this->mutatedModules([self::FLOOR_SCREEN,
            'this.#set.displaced(install_id, seat_id, arrivals[0], at);',
            'this.#set.displaced(install_id, seat_id, arrivals[arrivals.length - 1], at);',
        ]);

        $result = $this->floorRun('two_arrivals', $highestFirst);
        [$lowest, $highest] = $this->twoArrivalsInOneRender($result);
        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(1, $rows);
        $this->assertSame($highest, $rows[0]['cause'],
            'the plant did not take: the highest-sorting arrival was planted and is not the recorded cause');
        $this->assertNotSame($lowest, $rows[0]['cause'],
            'the RED did not bite: the plant records the same cause as the published rule');
    }

    /**
     * The two keys the `two_arrivals` run adds to the room, in § 3.2's `order` — after asserting
     * that no frame drew one of them without the other, which is what makes the run two arrivals
     * in ONE render.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: string}
     */
    private function twoArrivalsInOneRender(array $result): array
    {
        $published = $this->documentSlots()['slots'];
        $arrived = array_values(array_diff(
            array_keys($this->slotsOf($this->lastFloor($result), self::ROOM)),
            array_keys($published),
        ));

        $this->assertCount(2, $arrived, 'the run did not add exactly two seats to the room');

        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['rooms'] ?? [] as $room) {
                if (($room['install_id'] ?? null) !== self::ROOM) {
                    continue;
                }

                $drawn = array_intersect($arrived, array_column($room['desks'] ?? [], 'key'));

                $this->assertContains(count($drawn), [0, 2],
                    'a frame drew one of the two arrivals without the other — they landed in two renders, '
                    .'and the case § 14 item 27 rules on never occurred');
            }
        }

        // § 3.2: `order` = the room's seats ascending by (h, seat_id).
        usort($arrived, fn (string $a, string $b): int => [$this->fnv1a32($a), explode('/', $a)[1]]
            <=> [$this->fnv1a32($b), explode('/', $b)[1]]);

        return [$arrived[0], $arrived[1]];
    }

    /** § 3.2's published hash, for the one expectation this file computes rather than reads. */
    private function fnv1a32(string $key): int
    {
        $h = 2166136261;

        foreach (str_split($key) as $char) {
            $h ^= ord($char);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }

        return $h;
    }
}
