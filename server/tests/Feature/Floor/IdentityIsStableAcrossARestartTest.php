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
     * row, and § 11 names its cause: the arrival that now holds `aimla-pm`'s former slot.
     *
     * ⛔ THE RUN IS FIRST CHECKED TO BE THE CASE THE RULING IS ABOUT. A run whose two seats landed
     * in two renders would hold two single-arrival turns, where any rule gives the same answer.
     *
     * ⚠ THE PAIR ALSO SEPARATES THE RULE FROM THE ONE IT REPLACED, and that is asserted rather than
     * assumed. The slot's taker, `aimla-impl-4`, is NOT the arrival that sorts lowest in § 3.2's
     * `order` (`aimla-win-1` hashes lower). So the old rule, which always named the lowest-order
     * arrival, names a seat that displaced nobody, and fails here.
     */
    public function test_green_two_arrivals_in_one_render_record_the_arrival_that_took_the_slot(): void
    {
        $result = $this->floorRun('two_arrivals');
        $render = $this->displacingRender($result);

        $this->assertCount(2, $render['arrivals'],
            'the displacing render did not carry both arrivals, so the case § 14 item 27 rules on never occurred');

        $displaced = self::ROOM.'/'.$this->documentCollision()['displaced'];
        $taker = $this->takerOf($render, $displaced);

        $this->assertContains($taker, $render['arrivals'],
            'the displaced seat\'s former slot is not held by an arrival, so this run exercises the cascade clause instead');
        $this->assertNotSame($render['arrivals'][0], $taker,
            'the slot\'s taker is also the lowest-order arrival, so this GREEN would not tell the rule from the one it replaced');

        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(1, $rows, 'the log does not carry exactly one A16 row for one displacement');
        $this->assertSame($this->documentCollision()['displaced'], $rows[0]['seat_id'],
            'the A16 row names a seat other than the one § 3.3 says the colliding arrival displaces');
        $this->assertSame($taker, $rows[0]['cause'],
            "A16's cause is not the arrival that took the displaced seat's former slot (§ 11, § 14 item 27)");
    }

    /**
     * GREEN — A CASCADE (§ 14 item 27's second clause). `fx-collision`'s `cascade` run places
     * `aimla-impl-5` in a free slot in one render, then delivers `aimla-win-3`, which hashes to the
     * same slot and sorts lower. `aimla-win-3` takes it, `aimla-impl-5` probes on into `aimla-pm`'s
     * slot, and `aimla-pm` moves again. So two desks move, and `aimla-pm`'s former slot is held by
     * `aimla-impl-5`, which is NOT one of this render's arrivals.
     *
     * The first displacement names its taker, as in the GREEN above. The cascaded one takes the
     * stated approximation: the arrival that sorts lowest in § 3.2's `order` among this render's
     * arrivals. It must never name the non-arrival that took the slot.
     */
    public function test_green_a_cascade_names_the_lowest_order_arrival_and_never_a_seat_that_did_not_arrive(): void
    {
        $result = $this->floorRun('cascade');
        $render = $this->displacingRender($result);
        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(2, $rows, 'the cascade did not move exactly two desks');

        $cascaded = 0;

        foreach ($rows as $row) {
            $key = self::ROOM.'/'.$row['seat_id'];
            $taker = $this->takerOf($render, $key);

            if (in_array($taker, $render['arrivals'], true)) {
                $this->assertSame($taker, $row['cause'],
                    "[{$key}] A16's cause is not the arrival that took the seat's former slot");

                continue;
            }

            $cascaded++;

            $this->assertSame($render['arrivals'][0], $row['cause'],
                "[{$key}] a cascaded displacement's cause is not the lowest-order arrival of its render");
            $this->assertNotSame($taker, $row['cause'],
                "[{$key}] a cascaded displacement names the seat that took the slot, which did not arrive");
        }

        $this->assertSame(1, $cascaded,
            'no displaced seat\'s former slot is held by a non-arrival, so the cascade clause never ran');
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
     * ⛔ THIRD RED — THE OLD RULE: ALWAYS THE LOWEST-ORDER ARRIVAL. In the two-arrival run that names
     * `aimla-win-1`, which displaced nobody.
     */
    public function test_red_the_lowest_order_arrival_as_every_cause_names_a_seat_that_displaced_nobody(): void
    {
        $oldRule = $this->mutatedModules([self::FLOOR_SCREEN,
            'displacementCause(before.get(key), holder, arrivals)',
            'arrivals[0]',
        ]);

        $result = $this->floorRun('two_arrivals', $oldRule);
        $render = $this->displacingRender($result);
        $taker = $this->takerOf($render, self::ROOM.'/'.$this->documentCollision()['displaced']);
        $rows = $this->rowsFor($result, 'A16');

        $this->assertCount(1, $rows);
        $this->assertSame($render['arrivals'][0], $rows[0]['cause'],
            'the plant did not take: the lowest-order arrival was planted and is not the recorded cause');
        $this->assertNotSame($taker, $rows[0]['cause'],
            'the RED did not bite: the old rule names the same seat as the one that took the slot');
    }

    /**
     * ⛔ FOURTH RED — THE CASCADE ANSWERED WITH THE SLOT'S TAKER, WHATEVER IT IS. In the cascade run
     * that names `aimla-impl-5` as `aimla-pm`'s cause, a seat that arrived in an earlier render.
     */
    public function test_red_a_cascade_answered_by_the_slots_taker_names_a_seat_that_did_not_arrive(): void
    {
        $takerAlways = $this->mutatedModules([self::FLOOR_SCREEN,
            'return arrivals.includes(taker) ? taker : arrivals[0];',
            'return taker;',
        ]);

        $result = $this->floorRun('cascade', $takerAlways);
        $render = $this->displacingRender($result);

        $named = array_map(static fn (array $row): string => $row['cause'], $this->rowsFor($result, 'A16'));

        $this->assertCount(2, $named);
        $this->assertNotSame([], array_diff($named, $render['arrivals']),
            'the RED did not bite: with the taker planted as every cause, every cause is still an arrival');
    }

    /**
     * The render that moved a desk already on the floor: the room's slots before it and after it,
     * and its arrivals in § 3.2's `order`.
     *
     * @param  array<string, mixed>  $result
     * @return array{before: array<string, int|null>, after: array<string, int|null>, arrivals: list<string>}
     */
    private function displacingRender(array $result): array
    {
        $previous = null;

        foreach ($result['floor_renders'] as $render) {
            $composed = array_filter(
                $render['frame']['rooms'] ?? [],
                static fn (array $room): bool => ($room['install_id'] ?? null) === self::ROOM && array_key_exists('desks', $room),
            );

            if ($composed === []) {
                continue;
            }

            $slots = $this->slotsOf($render['frame'], self::ROOM);

            if ($previous !== null) {
                foreach ($previous as $key => $slot) {
                    if (array_key_exists($key, $slots) && $slots[$key] !== $slot) {
                        $arrivals = array_values(array_diff(array_keys($slots), array_keys($previous)));

                        // § 3.2: `order` = the room's seats ascending by (h, seat_id).
                        usort($arrivals, fn (string $a, string $b): int => [$this->fnv1a32($a), explode('/', $a)[1]]
                            <=> [$this->fnv1a32($b), explode('/', $b)[1]]);

                        return ['before' => $previous, 'after' => $slots, 'arrivals' => $arrivals];
                    }
                }
            }

            $previous = $slots;
        }

        $this->fail('no frame of this run moved a desk already on the floor');
    }

    /**
     * The key that holds a displaced seat's former slot after the displacing render.
     *
     * @param  array{before: array<string, int|null>, after: array<string, int|null>, arrivals: list<string>}  $render
     */
    private function takerOf(array $render, string $displaced): string
    {
        $this->assertArrayHasKey($displaced, $render['before'], "{$displaced} was not on the floor before the render");

        $taker = array_search($render['before'][$displaced], $render['after'], true);

        $this->assertIsString($taker, "nobody holds {$displaced}'s former slot after the render");

        return $taker;
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
