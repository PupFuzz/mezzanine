<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-9 — the fold is idempotent across a restart.
 *
 * ⚠ WHAT IS AND IS NOT DRIVEN HERE, STATED BEFORE THE ASSERTIONS RATHER THAN AFTER THEM.
 * § 11's build is "`SIGKILL` the fold daemon mid-pass (inside the transaction); restart it", 20
 * times. The suite runs against SQLite `:memory:` on ONE in-process connection: there is no second
 * process to kill, and no second connection from which a partial state could be observed. A real
 * SIGKILL is not reachable on this store, and the 20-iteration race is not either.
 *
 * What IS driven is the PROPERTY the kill is a way of reaching — "the cursor advance is in the same
 * transaction as the projections, so a crash mid-pass rolls back BOTH" (§ 6.5's mechanism 1). The
 * first test below reaches the identical state by failing the cursor advance after nine events have
 * been projected, and asserts that all nine vanish with it. That is the same rollback a kill
 * produces, observed from inside the process instead of from outside it.
 */
class At9FoldIdempotenceTest extends FoldTestCase
{
    public function test_a_failed_cursor_advance_rolls_back_every_projection_of_the_pass(): void
    {
        $this->deliver($this->clearKill());

        $this->assertSame(0, DB::table('calls')->where('seat_ref', $this->seatRef)->count());

        // A recompute that, on the last event of the window, moves the cursor out from under the
        // pass — which is what a second fold worker does, and what makes `advance()` match no row.
        $racing = new class($this->seatRef) extends StateRecompute
        {
            public function __construct(private int $seatRef)
            {
                // The parent now takes `$publish` (card #7827): a `StateRecompute` publishes
                // § 8.3's `seat.delta` whenever it bumps a version. This stand-in is a FOLD
                // worker, so it takes the fold's own default rather than suppressing the feed —
                // the pass under test is one whose transaction ROLLS BACK, and the property that
                // no delta escapes it is `ShouldDispatchAfterCommit`'s, not this class's.
                parent::__construct();
            }

            /**
             * ⚠ THE `$before` PARAMETER IS CARD #7837's, AND UPDATING THIS OVERRIDE WAS FORCED BY
             * THE COMPILER RATHER THAN FOUND BY READING — which is the whole reason that card made
             * it REQUIRED instead of an optional argument defaulting to a self-sample. An optional
             * one would have left this stand-in silently sampling on the wrong side of
             * `Projector::apply()` while the real fold sampled on the right one, and a fold seam
             * that derives differently from the fold is a seam that proves nothing.
             *
             * @param  array<string, mixed>  $before
             */
            public function after(FoldEvent $e, array $before, string $cause = 'wire_event'): bool
            {
                $moved = parent::after($e, $before, $cause);

                if ($e->kind === 'session.start') {   // E9, the last event of the fixture
                    DB::table('seat_state')->where('seat_ref', $this->seatRef)
                        ->update(['fold_cursor_event_id' => 999_999]);
                }

                return $moved;
            }
        };

        (new Fold(new Projector, $racing))->pass();

        // ⛔ THE ASSERTION IS ON THE STORE, NOT ON THE EXCEPTION. Nine events had been projected
        // inside that transaction — two calls, two sessions, a turn record — and the tenth's
        // cursor write matched no row. If the advance were in its own transaction, those nine
        // projections would still be here and the seat would be permanently half-folded.
        $this->assertSame(0, DB::table('calls')->where('seat_ref', $this->seatRef)->count(),
            'projections survived a rolled-back pass — the cursor advance is not in the transaction');
        $this->assertSame(0, DB::table('sessions')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(0, DB::table('seat_state_transitions')->where('seat_ref', $this->seatRef)->count());

        // A clean re-run then folds the whole window, which is the "restart it" half.
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state);
        $this->assertSame(2, DB::table('calls')->where('seat_ref', $this->seatRef)->count());
    }

    public function test_a_poison_event_costs_one_seat_and_says_so(): void
    {
        // § 6.5's poison-event rule, and § 2.1's reason for per-seat cursors: "a single global
        // cursor makes one unprojectable event freeze the entire fleet's derivation — the 'one bad
        // batch wedges the stream' shape D1 refuses in the spool. Per seat, a poison event costs one
        // desk, AND THAT DESK SAYS SO."
        $this->deliver($this->cleanTurn());

        $poison = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                if ($e->kind === 'tool.end') {
                    throw new \RuntimeException('unprojectable');
                }

                parent::apply($e);
            }
        };

        (new Fold($poison, new StateRecompute))->pass();
        (new Fold($poison, new StateRecompute))->pass();

        // The cursor advanced PAST it — the seat is not frozen.
        $this->assertFalse($this->behind(), 'the poison event wedged the seat forever');

        $this->assertSame(1, $this->counter('fold_error'));
        $this->assertSame(1, (int) $this->state()->fold_errors);

        // § 4.3: a fold error raises the `derivation_error` BADGE and LEAVES THE STATE ALONE.
        // "Overwriting a seat's derived state with `unknown` because one event failed to project
        // would DESTROY the reading its other facts still support" — label, never collapse.
        $this->assertContains('derivation_error', json_decode($this->state()->server_badges, true));
        $this->assertNotSame('unknown', $this->state()->activity_state);

        $this->assertContains('fold_error', array_column($this->transitions(), 'cause'));

        // "The event STAYS IN `events`: the fix plus `mezzanine:rebuild --seat` recovers the seat
        // exactly, which is only true because the log is the source of truth and the projections
        // are derived." So: the row is still there, and a rebuild with a working projector recovers.
        $this->assertSame(1, DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', 'tool.end')->count());

        $this->artisan('mezzanine:rebuild', ['--seat' => 'aimla/aimla-pm'])->assertSuccessful();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame(0, (int) $this->state()->fold_errors, 'the rebuild did not clear the badge');
        $this->assertSame(1, $this->counter('fold_error'), 'the rebuild reset a monotonic counter');
    }

    public function test_re_folding_an_already_folded_window_applies_nothing_twice(): void
    {
        // § 6.5's SECOND idempotency mechanism, on its own: "every projection is an upsert keyed on
        // a natural key, guarded by the LWW comparator, so applying the same event twice is a no-op
        // REGARDLESS. This is what makes § 6.6's replay safe to run against live tables, and it is
        // why mechanism 1 alone is not enough." Mechanism 1 cannot help here, because nothing
        // crashed — the window is simply folded twice.
        $this->deliver($this->clearKill());
        $this->fold();

        $before = $this->projections();

        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_event_id' => 0]);

        $this->fold();

        $this->assertSame($before, $this->projections(), 'a second application changed the projections');
        $this->assertSame(2, DB::table('calls')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(0, (int) $this->state()->open_calls);

        // …and the replay was DETECTED rather than silently absorbed: each `tool.start` arrived for
        // a call that was already closed, which D1 § 8.6 counts as `late_open` and refuses to
        // reopen. A phantom open call is exactly what a double-applied `tool.start` looks like.
        $this->assertSame(2, $this->counter('late_open'));
    }

    /** @return array<string, mixed> */
    private function projections(): array
    {
        $strip = ['id', 'seat_ref', 'session_ref', 'updated_at'];

        $rows = fn (string $table, string $order) => DB::table($table)->where('seat_ref', $this->seatRef)
            ->orderBy($order)->get()
            ->map(fn ($r) => array_diff_key((array) $r, array_flip($strip)))->all();

        return [
            'sessions' => $rows('sessions', 'session_id'),
            'calls' => $rows('calls', 'call_id'),
            'attention' => $rows('attention_requests', 'request_id'),
        ];
    }
}
