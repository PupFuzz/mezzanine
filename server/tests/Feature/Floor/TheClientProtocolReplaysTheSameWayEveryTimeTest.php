<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * The determinism bound every other test in this directory rests on — `docs/design/FLOOR.md`
 * Appendix B row 3's **harness** is only evidence if the same scenario produces the same records
 * every time it is replayed.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ TWO CHECKS, AND THE SECOND IS NOT A DUPLICATE OF THE FIRST. Replaying each scenario a hundred
 * times in ONE process catches an order that varies run to run; scanning the module's own source
 * catches the way a client STOPS being replayable at all — a real timer, a real clock, a random
 * number. A repeat count can only find what happens to vary during the runs it took; the scan
 * finds the mechanism whether or not it varied today.
 *
 * ⛔ THE SCAN READS COMMENT-STRIPPED SOURCE. This module's own header names `setTimeout`,
 * `Date.now` and `Math.random` — in prose, saying it uses none of them — and a scan that read the
 * comments would red on the sentence that promises the property it is checking.
 *
 * ⚠ THE SCAN IS A LIST OF IDENTIFIERS, WHICH IS ITS OWN LIMIT, STATED HERE RATHER THAN IMPLIED: a
 * macrotask reached through something outside the list (a `MessageChannel`, an `await` on a
 * timer-backed library) would make the repeat check FLAKY rather than red. Widen the list here
 * when the module gains a dependency that could schedule one.
 */
class TheClientProtocolReplaysTheSameWayEveryTimeTest extends TestCase
{
    /** The identifiers a replayable client reads none of (§ 4.2's settlement rule). */
    private const FORBIDDEN = ['setTimeout', 'setInterval', 'Date.now', 'performance.now', 'queueMicrotask', 'Math.random'];

    use DrivesTheFleetClientModule;

    /**
     * DET1 — every run the fixture files ship, replayed 100 times in one probe process, yields ONE
     * distinct serialized record list.
     *
     * ⛔ THE RUN LIST IS DERIVED FROM THE FILES, NEVER TYPED HERE. A hand-list silently stops
     * covering the run added after it was written, which is the one most likely to be the
     * un-replayable one.
     */
    public function test_every_shipped_run_replays_identically_a_hundred_times(): void
    {
        $covered = 0;

        foreach ($this->everyShippedRun() as $run) {
            $runs = $this->replayRepeatedly($run, 100);
            $distinct = array_unique(array_map(
                static fn (array $r): string => json_encode($r['records']),
                $runs,
            ));

            $this->assertCount(1, $distinct, "[{$run}] the same scenario produced more than one "
                .'record list over 100 replays — nothing asserted against this run is evidence');
            $covered++;
        }

        $this->assertGreaterThan(20, $covered,
            'the derivation returned fewer runs than the fixture files hold — the coverage this '
            .'check claims is over the population it actually read');
    }

    /** DET2 — the source-level bound: no timer, no real clock, no randomness. */
    public function test_the_module_reads_no_timer_no_real_clock_and_no_randomness(): void
    {
        $source = $this->moduleSource(self::MODULE);

        foreach (self::FORBIDDEN as $identifier) {
            $this->assertStringNotContainsString($identifier, $source,
                "the client protocol reads {$identifier} — the harness cannot replay a scenario "
                .'through a client that schedules or samples anything of its own');
        }
    }

    /** P19 / P19b / P19c — each planted client diverging on the field its row names. */
    public function test_the_planted_clients_each_diverge_on_the_field_their_row_names(): void
    {
        // P19 — the release empties the buffer at random. It can only diverge where the release is
        // reached with something in the buffer, which is why the run is named: `leg_c` holds a
        // delta across a seat fetch and drains it.
        $runs = $this->replayRepeatedly('leg_c', 100, $this->plantedClient(FleetClientPlants::RANDOM[0]));
        $distinct = array_unique(array_map(static fn (array $r): string => json_encode($r['records']), $runs));

        $this->assertGreaterThan(1, count($distinct),
            'P19 did not bite: the drain was made random and 100 replays still produced one record '
            .'list — this run’s buffer is not where the check can see it');

        // P19b / P19c — the source scan, one plant per identifier it exists to find. The plant is
        // run through the SAME scan the bound uses, over a mutated copy of the shipped tree.
        foreach ([[FleetClientPlants::CLOCK[0], 'Date.now'], [FleetClientPlants::RANDOM[0], 'Math.random']] as [$plant, $identifier]) {
            $planted = $this->moduleSource(self::MODULE, $this->plantedClient($plant));

            $this->assertStringContainsString($identifier, $planted,
                "P19b/P19c did not bite: {$identifier} was planted in the module and the scan’s own "
                .'input does not carry it, so the scan is reading something other than the code');
        }
    }

    /**
     * Every run the checked-in fixture files hold, read from the files themselves.
     *
     * ⚠ `log_cap` IS EXCLUDED, AND NOT BECAUSE IT IS UNDETERMINISTIC: it is a 201-delta run whose
     * hundred replays serialize to hundreds of megabytes, and the cap it exists for is asserted by
     * `TheClientsRecordKeepsItsNewestTwoHundredLinesTest`. Excluding it here is a cost decision
     * about this check's input, stated rather than silent.
     *
     * @return list<string>
     */
    private function everyShippedRun(): array
    {
        $runs = [];

        foreach (['fx-snapshot-4', 'fx-gap', 'fx-membership', 'fx-confirm'] as $file) {
            foreach (array_keys($this->fixtureFile($file)['runs']) as $run) {
                if ($run !== 'log_cap') {
                    $runs[] = (string) $run;
                }
            }
        }

        return $runs;
    }
}
