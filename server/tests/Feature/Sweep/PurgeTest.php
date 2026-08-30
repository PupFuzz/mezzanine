<?php

namespace Tests\Feature\Sweep;

use App\Sweep\PlaneClock;
use App\Sweep\Purge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.7` — retention, the purge, and THE CHAIN BETWEEN THEM.
 *
 * The chain is one inequality because all three numbers move together:
 *
 *     spool residency 8 days < dedup window 10 days < event retention 14 days
 *
 * and the test that matters most here is the one that watches the purge REFUSE rather than the ones
 * that watch it delete. AT-D2-17's RED is "set event retention to 7 days, purge, then re-deliver an
 * 8-day-old event → it inserts as new, the timeline double-counts it, and the ledger gains a second
 * open for a call that closed a week ago" — and § 2.2's posture for this path is why the refusal is
 * the correct direction: "Retaining too much costs disk; deleting on a broken assumption costs the
 * dedup guarantee. THE SAFE DIRECTION IS TO KEEP."
 */
class PurgeTest extends SweepTestCase
{
    public function test_events_and_batches_past_fourteen_days_are_deleted_and_recent_ones_are_not(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $old = DB::table('events')->count();
        $this->assertGreaterThan(0, $old);

        // 15 days of server clock, then a fresh batch. Ageing by moving the CLOCK rather than by
        // back-dating `received_at` is the rig's standing rule: `received_at` is the ingest's
        // column, and a suite that writes it is a suite proving the purge reads its own writes.
        $this->advanceServerClock(15 * 86400);
        $this->deliver($this->heartbeats(1));
        $this->fold();

        $fresh = DB::table('events')->count() - $old;

        app(Purge::class)->pass();

        $this->assertSame($fresh, DB::table('events')->count());
        $this->assertSame(1, DB::table('batches')->count());
    }

    public function test_the_purge_refuses_a_retention_below_the_dedup_window(): void
    {
        // `D2-MUST` #3's floor, checked rather than assumed. Without this refusal the same call
        // deletes an 8-day-old event, a re-send re-inserts it as new, and NOTHING ANYWHERE SAYS SO
        // — "the single most confusing possible corruption of a timeline".
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->advanceServerClock(9 * 86400);

        $before = DB::table('events')->count();

        try {
            app(Purge::class)->pass(retentionDays: 7);
            $this->fail('the purge accepted a retention inside the dedup window');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('dedup window', $e->getMessage());
        }

        $this->assertSame($before, DB::table('events')->count(), 'nothing was deleted');

        // The floor itself is exactly 10 days, not "about 10": a retention EQUAL to the window is
        // ACCEPTED, one day under it is not. Asserting only the refusal would pass on a guard that
        // refused everything, which is the shape of a check that cannot answer the other way.
        $this->advanceServerClock(2 * 86400);
        app(Purge::class)->pass(retentionDays: Purge::DEDUP_WINDOW_DAYS);
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_the_command_reports_the_refusal_rather_than_a_stack_trace(): void
    {
        $this->artisan('mezzanine:purge', ['--retention-days' => 3])
            ->expectsOutputToContain('dedup window')
            ->assertFailed();
    }

    public function test_open_rows_are_never_purged_and_closed_ones_are(): void
    {
        // § 6.7: `sessions`, `calls`, `attention_requests` are retained "14 days AFTER THE ROW
        // CLOSED; OPEN ROWS ARE NEVER PURGED", because "a closed fact older than the log it was
        // derived from cannot be re-derived, so purging it early would make a rebuild produce a
        // DIFFERENT answer than the live fold — breaking AT-D2-10's equality for a reason that is
        // not a defect".
        $this->deliver($this->cleanTurn());       // one call, closed
        $this->deliver($this->openCall());        // one call, still open
        $this->fold();

        $this->assertSame(2, DB::table('calls')->count());

        $this->advanceServerClock(15 * 86400);
        $this->deliver($this->heartbeats(1));
        $this->fold();

        app(Purge::class)->pass();

        $remaining = DB::table('calls')->get();
        $this->assertCount(1, $remaining);
        $this->assertNull($remaining->first()->closed_at, 'the OPEN call is the survivor');
    }

    public function test_current_state_and_counters_are_never_purged(): void
    {
        // § 6.7's "never" row, and § 4.10's reason for the most important member of it: "`seats` is
        // retained forever; the 14 days is a READ FILTER, not a deletion, so an operator query can
        // still find the row and its reason." A seat row also outlives its events deliberately —
        // "a provisioned seat that has never reported must render, not vanish."
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->sweep();

        $this->advanceServerClock(60 * 86400);

        app(Purge::class)->pass();

        $this->assertSame(1, DB::table('seats')->count());
        $this->assertSame(1, DB::table('installs')->count());
        $this->assertSame(1, DB::table('seat_state')->count());
        $this->assertGreaterThan(0, DB::table('seat_counters')->count());
        $this->assertGreaterThan(0, DB::table('seat_predicates')->count());
    }

    public function test_the_pass_stamps_purge_last_run_at(): void
    {
        // § 6.7: "a four-day outage of an hourly job is visible in `purge_last_run_at` ~96 times
        // over" — which is only true if a completed pass stamps it and a refused one does not.
        $this->assertNull(PlaneClock::lastRunAt(PlaneClock::PURGE));

        try {
            app(Purge::class)->pass(retentionDays: 3);
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertNull(PlaneClock::lastRunAt(PlaneClock::PURGE), 'a refused pass is not a pass');

        app(Purge::class)->pass();

        $this->assertNotNull(PlaneClock::lastRunAt(PlaneClock::PURGE));
    }

    public function test_a_pass_that_runs_out_of_budget_counts_the_backlog_rather_than_holding_a_transaction(): void
    {
        // § 6.7: "the budget means a purge that cannot keep up FALLS BEHIND VISIBLY
        // (`purge_backlog_rows` is counted) rather than holding a long transaction."
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->advanceServerClock(15 * 86400);

        $expired = DB::table('events')->count() + DB::table('batches')->count()
            + DB::table('calls')->whereNotNull('closed_at')->count()
            + DB::table('seat_state_transitions')->count();

        app(Purge::class)->pass(budgetSeconds: 0);

        $this->assertGreaterThan(0, DB::table('events')->count(), 'the budget stopped it');
        $this->assertSame(
            $expired,
            (int) DB::table('global_counters')->where('name', 'purge_backlog_rows')->value('value'),
        );

        // The discriminating control: with a budget, the same fixture drains and the counter does
        // not move again — so the counter is known to be capable of reporting "kept up".
        app(Purge::class)->pass();
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(
            $expired,
            (int) DB::table('global_counters')->where('name', 'purge_backlog_rows')->value('value'),
        );
    }

    public function test_the_delete_is_bounded_and_loops_until_the_table_is_drained(): void
    {
        // § 6.7's mechanics: `LIMIT 5000`, "looped until it deletes fewer than the limit". A fixture
        // one row over the bound is what separates "the loop runs" from "one statement happened to
        // be big enough" — at exactly `BATCH` rows a single statement would look identical.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $seed = DB::table('events')->first();
        $rows = [];

        for ($i = 0; $i < Purge::BATCH + 1; $i++) {
            $rows[] = (array) $seed + [];
            $rows[$i]['id'] = 1000 + $i;
            $rows[$i]['event_id'] = str_pad((string) $i, 26, 'A', STR_PAD_LEFT);
            $rows[$i]['seq'] = 500_000 + $i;
        }

        DB::table('events')->insert($rows);
        $this->advanceServerClock(15 * 86400);

        $deleted = app(Purge::class)->pass();

        $this->assertSame(0, DB::table('events')->count());
        $this->assertGreaterThan(Purge::BATCH, $deleted['events']);
    }

    public function test_the_scheduler_carries_the_hourly_purge_and_neither_daemon(): void
    {
        // § 2.1 gives `mezzanine:purge` a SCHEDULE and gives the fold and the sweep a SUPERVISOR.
        // A scheduler entry for either daemon would start a second copy every minute of a process
        // that is already running, so the absence is asserted rather than assumed.
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->implode("\n");

        $this->assertStringContainsString('mezzanine:purge', $commands);
        $this->assertStringNotContainsString('mezzanine:fold', $commands);
        $this->assertStringNotContainsString('mezzanine:sweep', $commands);
    }
}
