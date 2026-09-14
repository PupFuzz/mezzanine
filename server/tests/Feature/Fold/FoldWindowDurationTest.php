<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * card#9464 — a fold window's hold on its seat's `seat_state` lock is bounded by its budget, on the real
 * clock and a real second connection.
 *
 * A window of slow events (every event after the first sleeps `SLEEP_MS`) under a budget far below
 * what the whole window would take stops early; its pass takes no longer than the budget, the one
 * event that may be in flight when the budget is crossed, and `SLOP_MS`; and while it runs, another
 * connection cannot take the seat's row — so what the budget bounds is a held lock, not just time.
 */
class FoldWindowDurationTest extends CommittedSeatTestCase
{
    private const EVENTS = 24;

    private const BUDGET_MS = 1000;

    private const SLEEP_MS = 300;

    /*
     * `SLOP_MS` — what a pass spends outside the budget's clock, or inside it on the event in flight
     * beyond its sleep. The budget clock starts at the lock grant and is read after each applied event,
     * so a pass that stops is bounded by:
     *
     *   claim() + BEGIN                                         before the clock starts
     *   the lock statement next to them                         before the clock starts
     *   budget + one event (its sleep + its apply and recompute) the event that crosses the budget
     *   the commit tail: advance() + the outbox insert + COMMIT after the clock's last read
     *
     * Each term below is the largest over repeated runs of this test's own event mix (`turn.start`, then
     * `tool.start`s) on the suite's MariaDB 11.8.6, measured 2026-09-14 inside `pass()`'s timing
     * boundary with a timing scaffold that was not shipped, rounded up. `JITTER_MS` is a stated
     * allowance, not a measurement: for a shared CI runner, a loaded store, and `usleep()` overrun.
     * The test stays falsifiable with it: an unbounded window of these events takes
     * EVENTS × SLEEP_MS and more.
     */

    /** Measured 2026-09-14: ≤ 2.9 ms. */
    private const CLAIM_AND_BEGIN_MS = 5;

    /** Measured 2026-09-14, with `readable()` included: ≤ 1.6 ms. */
    private const LOCK_MS = 5;

    /** Measured 2026-09-14: one event's apply and recompute, ≤ 48.3 ms. */
    private const EVENT_MS = 50;

    /** Measured 2026-09-14: ≤ 8.3 ms. */
    private const COMMIT_TAIL_MS = 10;

    private const JITTER_MS = 250;

    private const SLOP_MS = self::CLAIM_AND_BEGIN_MS + self::LOCK_MS + self::EVENT_MS + self::COMMIT_TAIL_MS + self::JITTER_MS;

    protected function install(): string
    {
        return 'aimla-windowduration';
    }

    public function test_a_window_of_slow_events_releases_the_seat_at_its_budget(): void
    {
        $events = [$this->turnStart()];

        for ($i = 1; $i < self::EVENTS; $i++) {
            $events[] = $this->toolStart($this->ulid());
        }

        $this->write(self::WRITER_1, $this->batch($events));

        $ids = DB::connection(self::FIXTURE)->table('events')->where('seat_ref', $this->seatRef)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(self::EVENTS, $ids);

        $probes = [];
        $record = function (bool $refused) use (&$probes) {
            $probes[] = $refused;
        };

        $slow = new class($this->seatRef, self::PROBE, self::SLEEP_MS, $record) extends Projector
        {
            private bool $first = true;

            /** @param  callable(bool): void  $record */
            public function __construct(private int $seatRef, private string $probe, private int $sleepMs, private $record) {}

            public function apply(FoldEvent $e): void
            {
                if ($this->first) {
                    $this->first = false;
                } else {
                    // The query builder has no NOWAIT. The assertion is that it throws, not which error
                    // the engine reports for a row another transaction holds.
                    try {
                        DB::connection($this->probe)->select(
                            'SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE NOWAIT', [$this->seatRef],
                        );
                        ($this->record)(false);
                    } catch (QueryException) {
                        ($this->record)(true);
                    }

                    usleep($this->sleepMs * 1000);
                }

                parent::apply($e);
            }
        };

        $startedAt = hrtime(true);
        $applied = $this->foldPass(self::FOLD, new Fold($slow, new StateRecompute, windowBudgetMs: self::BUDGET_MS));
        $elapsedMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        $this->assertGreaterThanOrEqual(1, $applied);
        $this->assertLessThan(self::EVENTS, $applied, 'the window applied every event; its budget never stopped it');
        $this->assertSame($ids[$applied - 1], (int) $this->state()->fold_cursor_event_id,
            'the cursor is not on the last event the window applied');

        $this->assertLessThanOrEqual(self::BUDGET_MS + self::SLEEP_MS + self::SLOP_MS, $elapsedMs,
            "the pass held the seat for {$elapsedMs} ms, past its budget, one event and SLOP_MS");

        $this->assertNotEmpty($probes, 'no slow event ran, so nothing probed the lock');
        $this->assertSame(array_fill(0, count($probes), true), $probes,
            "another connection took the seat's seat_state row while the window held it");
    }
}
