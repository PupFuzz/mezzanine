<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-17 (render half) — a seat the client does not hold is fetched, never patched.** Gated at
 * Appendix B **step 6**: *the inserted desk renders without an arrival animation* is an assertion about
 * the **animation set**, and before step 6 there is no arrival animation to withhold. card#7341 step 6.
 * The protocol half is step 3's and is `TheClientProtocolInsertsASeatByFetchTest`'s.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ § 3.4's LAST ROW IS THE WHOLE CLAIM, AND IT IS THE ONE EDGE WHERE AN IMPLEMENTER WOULD REACH FOR
 * THE NICER EFFECT. *A desk appearing because the client had not fetched it yet* and *a seat coming
 * online* are different facts, and only the second one happened to the seat. A1 is reserved for a seat
 * LEAVING `offline`, which is a claim the wire actually made.
 *
 * ⛔ THE GREEN IS PAIRED WITH A PRESENCE, BECAUSE *NO A1 ROW* IS FREE ON A FLOOR THAT DREW NOTHING. The
 * inserted desk must be there, and it must have entered the held render its delivered state requires —
 * so the absence of the arrival is measured on a desk that is demonstrably being rendered.
 */
class AFetchedSeatArrivesWithoutAnArrivalAnimationTest extends TestCase
{
    use DrivesTheDeskFloor;
    use ReadsTheAnimationTable;

    /** `fx-membership` leg (a): deltas for a seat absent from `fx-snapshot-4`, each patching only `context`. */
    private const RUN = 'leg_a';

    private const INSERTED = 'aimla/aimla-impl-3';

    public function test_the_inserted_desk_renders_without_an_arrival_animation(): void
    {
        $result = $this->deskRun(self::RUN);
        $rows = $this->rowsFor($result, self::INSERTED);

        // THE PRESENCE HALF FIRST: the desk is drawn, and it entered the render its state requires.
        $desk = $this->lastFrame($result)['desks'][self::INSERTED] ?? null;

        $this->assertNotNull($desk, 'the fetched seat was never drawn, so *no arrival animation* is free');

        $seat = $this->finalSeats($result)[self::INSERTED];
        $entered = array_values(array_filter($rows, static fn (array $r): bool => $r['phase'] === 'entered'));

        $this->assertNotSame([], $entered, 'the inserted desk entered no held render at all');
        $this->assertSame($this->heldRowFor($seat), $entered[0]['animation_id'],
            'the inserted desk entered a render § 6.2 does not predict for its own object');

        // AND THE ABSENCE: no A1 row for that seat. § 11's ordering rule puts this here because A1 is
        // the animation set's, and step 3's protocol half asserts the fetch, the buffering and the line.
        $this->assertNotContains('A1', array_column($rows, 'animation_id'),
            'the inserted desk played an arrival — a seat that came online hours ago walking in the moment '
            .'the client noticed it (§ 3.4)');
    }

    /**
     * ⛔ SECOND RED — THE ANIMATED INSERT. The client routes every row a REST surface delivers through the
     * delta journal and reads *this seat was absent* as *this seat was `offline`*, so the fetch that
     * inserts the desk satisfies A1's *leaves `offline`* and the character walks in. The SET IS
     * UNMUTATED: A1's own condition is what the plant feeds, which is what makes this RED a statement
     * about the set rather than about the plant.
     */
    public function test_second_red_an_animated_insert_walks_a_seat_in_that_came_online_hours_ago(): void
    {
        $animating = $this->plantedClient(FleetClientPlants::SNAPSHOT_AS_DELTA[0]);
        $rows = $this->rowsFor($this->deskRun(self::RUN, $animating), self::INSERTED);

        $this->assertContains('A1', array_column($rows, 'animation_id'),
            'the RED did not bite: the insert went through the delta journal as a seat leaving `offline` '
            .'and no arrival fired');
    }

    /**
     * Every log row one seat wrote, whatever its class — the A1 clause is about an `edge` row, which
     * `DrivesTheDeskFloor::heldRows()` filters out.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $result, string $key): array
    {
        [$install, $seat] = explode('/', $key, 2);

        return array_values(array_filter(
            $result['animation_log'],
            static fn (array $row): bool => $row['install_id'] === $install && $row['seat_id'] === $seat,
        ));
    }
}
