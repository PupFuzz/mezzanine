<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md § 2.3 row 5` — the confirmation signal. A desk is staffed only while the
 * client holds a CURRENT, confirmed read of that seat; a held seat whose own read keeps failing
 * stops being drawn as a character and takes § 7.1's empty chair instead.
 *
 * ⭐ *"If an agent is missing, then the office analogy is that it did not show up at work. Hence
 * that agent's desk will be unstaff (no person at the desk)."* — operator ruling, card#7341,
 * 2026-09-14, with the count ratified at **2** on 2026-09-15.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ STEP 3 SHIPS THE SIGNAL AND DRAWS NOTHING. `readStatus()` and `discrepancyState()` are data;
 * the empty chair, the *no data since …* line and the lobby's notice are the renderer's, at
 * Appendix B step 10. Every assertion here is on what the protocol REPORTS.
 *
 * ⛔ A FAILED READ UNDER AN IN-FLIGHT DISCOVERY COUNTS LIKE ANY OTHER (operator, 2026-09-15). The
 * floor may say a seat is missing while a discovery that could still repair it is running, and a
 * good discovery ends that by repairing the seat. The exemption this plan carried until then was
 * measured to hold the desk on a stale character indefinitely for the QUIET seat — one read per
 * discovery generation is never a second failure inside one, so the grace excused every read.
 * `missing_discovery_sparse` is that shape, and `boundedgrace` is that client, restored verbatim.
 *
 * ⛔ `missing` IS NEVER TRUE FOR A SEAT THE CLIENT HAS NEVER HELD. An unheld seat's failing insert
 * fetch is the LOBBY's disagreement — `discrepancyState()` — because the client knows only the
 * count, not which desk. `lobby_persistent_unheld` asserts both halves of that at once.
 */
class TheClientProtocolKnowsWhichDesksItCanConfirmTest extends TestCase
{
    use DrivesTheFleetClientModule;

    private const WIN1 = 'aimla-win/win-1';

    /** H14 — the second consecutive failed read is where the desk stops being claimable. */
    public function test_a_held_seat_whose_reads_keep_failing_is_unconfirmed_after_the_second(): void
    {
        $result = $this->replay('missing_persistent');
        $series = $this->readStatusSeries($result, self::WIN1);

        // ONE failure is not enough — that is the whole no-flicker constraint, and it is why the
        // threshold sits above 1.
        $this->assertSame(['at' => 1300, 'missing' => false, 'failStreak' => 1, 'confirmedAt' => 0],
            $this->sampleAt($series, 1300), 'one failed read already flipped the desk');

        $this->assertSame(['at' => 6300, 'missing' => true, 'failStreak' => 2, 'confirmedAt' => 0],
            $this->sampleAt($series, 6300), 'the second consecutive failed read did not flip the desk');

        // And it stays unconfirmed for the rest of the run: 12 failed reads, no success.
        $this->assertSame(['missing' => true, 'failStreak' => 12, 'confirmedAt' => 0],
            $result['final']['read_status'][self::WIN1]);

        // The held object is untouched by any of it. The client does not overwrite what it last
        // read; what is missing is its own confirmation, which is why this is not an 11th state.
        $this->assertSame(100, $this->finalSeats($result)[self::WIN1]['state_version']);
        $this->assertSame('idle', $this->finalSeats($result)[self::WIN1]['render_state']);
    }

    /** H14 — a single failure the very next read repairs must never flicker the character out. */
    public function test_a_single_failure_the_next_read_repairs_never_flickers(): void
    {
        $result = $this->replay('missing_repair');

        $this->assertSame([], array_values(array_filter(
            $this->readStatusSeries($result, self::WIN1),
            static fn (array $s): bool => $s['missing'] === true,
        )), 'the desk flickered out on a failure the next read repaired');

        $this->assertSame(106, $this->finalSeats($result)[self::WIN1]['state_version'],
            'the repairing read did not reach the map');
        $this->assertSame(['missing' => false, 'failStreak' => 0, 'confirmedAt' => 6300],
            $result['final']['read_status'][self::WIN1],
            'a successful read did not clear the streak and stamp the confirmation');
    }

    /** H14 — the retry is the existing per-delta primitive, and a repair ends the missing render. */
    public function test_the_retry_is_the_existing_per_delta_primitive_and_a_repair_ends_it(): void
    {
        $result = $this->replay('missing_retry');
        $series = $this->readStatusSeries($result, self::WIN1);

        $this->assertSame([6300, 11000], $this->missingWindow($series),
            'the two consecutive failures did not leave the desk unconfirmed until the repair');

        $this->assertCount(3, $this->seatRequests($result),
            'the third delta did not re-issue the read — nothing else ever would');
        $this->assertSame(109, $this->finalSeats($result)[self::WIN1]['state_version']);
        $this->assertFalse($result['final']['read_status'][self::WIN1]['missing'],
            'the repaired read left the desk unconfirmed');
    }

    /**
     * H14 — the operator's 2026-09-15 ruling: a failure that lands while a discovery is in flight
     * advances the streak like any other, and the discovery decides only the BUFFER. The accepted
     * flicker is exactly two samples wide here, and the discovery's own release ends it.
     */
    public function test_a_failed_read_under_an_in_flight_discovery_counts_like_any_other(): void
    {
        $result = $this->replay('missing_discovery_exempt');

        $this->assertSame([5400, 7000], $this->missingWindow($this->readStatusSeries($result, self::WIN1)),
            'the failure that landed under the discovery was excused — the desk kept a character '
            .'the client could not confirm');

        // The discovery's release re-issues the read (§ 2.3 row 5's second retry event), it
        // succeeds, and THAT is what ends the missing render — no rule of the discovery's own.
        $this->assertSame(['missing' => false, 'failStreak' => 0, 'confirmedAt' => 7300],
            $result['final']['read_status'][self::WIN1]);
        $this->assertSame(106, $this->finalSeats($result)[self::WIN1]['state_version']);
        $this->assertArrayHasKey('aimla-win/win-3', $this->finalSeats($result),
            'the discovery did not insert the seat it was sent to find');
        $this->assertCount(3, $this->seatRequests($result));
    }

    /** H14 — DENSE failures under chained discoveries (pass-5 BLOCKER 1's own shape). */
    public function test_dense_failures_under_chained_discoveries_reach_the_empty_chair(): void
    {
        $result = $this->replay('missing_discovery_repeating');
        $series = $this->readStatusSeries($result, self::WIN1);

        $this->assertSame(6300, $this->missingWindow($series)[0],
            'the second consecutive failed read did not fire under a continuously in-flight discovery');
        $this->assertSame(['missing' => true, 'failStreak' => 29, 'confirmedAt' => 0],
            $result['final']['read_status'][self::WIN1]);
    }

    /**
     * H14 — failures RARER than the discoveries: one read per discovery generation, never two
     * inside one. This is the distribution a per-discovery grace excuses in FULL, so it is the
     * shape that stayed a stale character forever until the exemption was dropped (pass-6
     * BLOCKER 1).
     */
    public function test_failures_rarer_than_the_discoveries_reach_the_empty_chair_too(): void
    {
        $result = $this->replay('missing_discovery_sparse');
        $series = $this->readStatusSeries($result, self::WIN1);

        $this->assertSame(20800, $this->missingWindow($series)[0],
            'the quiet seat never reached the empty chair — its one read per discovery was excused');

        // Fewer reads than discoveries is the premise: if that stopped being true this fixture
        // would have become `missing_discovery_repeating` and would prove nothing new.
        $this->assertLessThan(count($this->snapshotRequests($result)), count($this->seatRequests($result)),
            'the seat was read at least once per discovery — this run is no longer the SPARSE shape');
        $this->assertSame(['missing' => true, 'failStreak' => 6, 'confirmedAt' => 0],
            $result['final']['read_status'][self::WIN1],
            'the confirmation did not stay frozen at the connect snapshot');
    }

    /**
     * H15 — the UNHELD shape. The client knows only the count, so no desk is nameable: the
     * disagreement is the lobby's, and the notice stops claiming a refresh once the one check this
     * `(N, M)` pair gets has run without resolving it.
     */
    public function test_the_lobby_notice_stops_claiming_a_refresh_once_the_one_check_has_run(): void
    {
        $result = $this->replay('lobby_persistent_unheld');
        $series = $this->discrepancySeries($result);

        $this->assertSame(['at' => 5000, 'held' => 1, 'total' => 2, 'refreshing' => true],
            $this->sampleAt($series, 5000), 'the notice was not claiming the check that was in flight');
        $this->assertSame(['at' => 5300, 'held' => 1, 'total' => 2, 'refreshing' => false],
            $this->sampleAt($series, 5300), 'the notice kept claiming a refresh after the one check '
            .'this pair gets had run without resolving the disagreement');
        $this->assertSame(['held' => 1, 'total' => 2, 'refreshing' => false],
            $result['final']['discrepancy_state']);

        // And the seat nobody can name never reports `missing`: it was never held.
        $this->assertSame(['missing' => false, 'failStreak' => 0, 'confirmedAt' => null],
            $result['final']['read_status'][self::WIN1],
            'a seat the client has never held was reported as a missing DESK, which the client '
            .'cannot know — that disagreement is the lobby\'s');
    }

    /** Every planted client for this overlay, each diverging on the field its row names. */
    public function test_the_planted_clients_each_diverge_on_the_field_their_row_names(): void
    {
        // P31 — `readStatus()` never reports `missing`: the desk keeps drawing a character out of
        // a state 12 consecutive failed reads ago.
        $nomissing = $this->replay('missing_persistent', $this->plantedClient(FleetClientPlants::NOMISSING[0]));

        $this->assertFalse($nomissing['final']['read_status'][self::WIN1]['missing'],
            'P31 did not bite: the report was removed and the desk was still called unconfirmed');
        $this->assertSame(12, $nomissing['final']['read_status'][self::WIN1]['failStreak'],
            'P31’s premise is that the STREAK is unchanged and only the report is gone');

        // P32 — the threshold at ONE: the single failure `missing_repair` repairs now flickers.
        $oneflicker = $this->replay('missing_repair', $this->plantedClient(FleetClientPlants::ONEFLICKER[0]));

        $this->assertSame([1300, 6000], $this->missingWindow($this->readStatusSeries($oneflicker, self::WIN1)),
            'P32 did not bite: the threshold was lowered to 1 and the single repaired failure still '
            .'did not flicker the desk out');

        // P33 — a key that has failed once is never re-fetched: the repair never comes.
        $noretry = $this->replay('missing_retry', $this->plantedClient(FleetClientPlants::NORETRY[0]));

        $this->assertSame(100, $this->finalSeats($noretry)[self::WIN1]['state_version'],
            'P33 did not bite: the retry was removed and the seat still recovered');

        // ⚠ AND IT IS NOT EVEN REPORTED UNCONFIRMED, which is the sharper half of this defect and
        // is measured here rather than assumed: the streak counts FAILED READS, so a client that
        // stops re-reading stops accumulating them — it holds at 1, below the threshold, and the
        // desk keeps drawing a character out of the state it held at t=1000 with nothing saying
        // so. (The plant register's parenthetical for this row reads "stays missing, stuck at
        // 100"; the "stuck at 100" half is what reproduces against the shipped client.)
        $this->assertSame(['missing' => false, 'failStreak' => 1, 'confirmedAt' => 0],
            $noretry['final']['read_status'][self::WIN1],
            'P33’s measured shape is a desk left stale AND under the threshold, because the reads '
            .'that would have counted are the ones the plant removed');

        // P34 — the notice always claims a refresh, including after the pair's one check has run.
        $always = $this->replay('lobby_persistent_unheld', $this->plantedClient(FleetClientPlants::ALWAYSREFRESHING[0]));

        $this->assertTrue($always['final']['discrepancy_state']['refreshing'],
            'P34 did not bite: the predicate was replaced with `true` and the notice still fell silent');

        // P35 / P36 — the discovery-in-flight exemption restored. On the two-sample flicker it
        // erases the window; on the dense shape it holds the streak at 0 through 29 failed reads.
        $exempt = $this->replay('missing_discovery_exempt', $this->plantedClient(FleetClientPlants::EXEMPTDISCOVERY[0]));

        $this->assertSame([], $this->missingWindow($this->readStatusSeries($exempt, self::WIN1)),
            'P35 did not bite: the exemption was restored and the in-flight failure still counted');

        $repeating = $this->replay('missing_discovery_repeating', $this->plantedClient(FleetClientPlants::EXEMPTDISCOVERY[0]));

        $this->assertSame([], $this->missingWindow($this->readStatusSeries($repeating, self::WIN1)),
            'P36 did not bite: the exemption was restored and the dense failures still reached the '
            .'empty chair');
        $this->assertSame(0, $repeating['final']['read_status'][self::WIN1]['failStreak'],
            'P36’s measured shape is a streak that never leaves 0 under a continuously in-flight '
            .'discovery — that is what made the desk permanently, invisibly wrong');

        // P37 — Amendment 5's per-discovery grace, restored VERBATIM (the two fields, the
        // generation bump, the one-grace-per-generation check). The same six failed reads, and the
        // desk never goes unconfirmed at all: this RED is against what the plan shipped, not a
        // strawman.
        $bounded = $this->replay('missing_discovery_sparse', $this->plantedClientWith(FleetClientPlants::BOUNDEDGRACE));

        $this->assertSame([], $this->missingWindow($this->readStatusSeries($bounded, self::WIN1)),
            'P37 did not bite: the bounded grace was restored and the quiet seat still reached the '
            .'empty chair');
        $this->assertSame(0, $bounded['final']['read_status'][self::WIN1]['failStreak']);
        $this->assertCount(count($this->seatRequests($this->replay('missing_discovery_sparse'))),
            $this->seatRequests($bounded),
            'P37’s premise is that the bounded client reads the seat exactly as often — what '
            .'differs is only what those failures are allowed to count for');
    }

    /**
     * The instants at which a run reports the desk unconfirmed — the window, as a list, so a row
     * that claims one asserts the whole shape rather than a single sample.
     *
     * @param  list<array<string, mixed>>  $series
     * @return list<int>
     */
    private function missingWindow(array $series): array
    {
        return array_values(array_map(
            static fn (array $s): int => $s['at'],
            array_filter($series, static fn (array $s): bool => $s['missing'] === true),
        ));
    }

    /**
     * The LAST sample written at an instant — the probe writes one record per settled event, and
     * two events can share a scenario-clock instant.
     *
     * @param  list<array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    private function sampleAt(array $series, int $at): array
    {
        $matching = array_values(array_filter($series, static fn (array $s): bool => $s['at'] === $at));

        $this->assertNotSame([], $matching, "no sample was written at t={$at}");

        return $matching[count($matching) - 1];
    }
}
