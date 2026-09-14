<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * card#9398 — a concurrency error is transient at the fold's TOP-LEVEL transaction, the depth
 * production runs it at (`FoldCommand` is the one caller of `Fold::pass()`).
 *
 * At the top level Laravel rolls the transaction back and rethrows the engine's own
 * `QueryException`; `FoldConcurrencyErrorTest` covers the nested shape (`DeadlockException`). The
 * quarantine path is driven only here, because `quarantine()` writes before it can raise, and at a
 * nested level Laravel does not roll those writes back — so the rig, not the fold, would leave them.
 */
class FoldConcurrencyErrorTopLevelTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'fold-concurrency-top';
    }

    public function test_a_deadlock_in_the_window_yields_the_pass_and_quarantines_nothing(): void
    {
        $this->write(self::WRITER_1, $this->batch([$this->turnStart(), $this->toolStart($this->ulid())]));
        $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);

        $contended = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                throw ConcurrencyError::raised(1213);
            }
        };

        $applied = $this->foldPass(self::FOLD, new Fold($contended, new StateRecompute));

        $this->assertNothingFoldedAndNothingQuarantined($applied);
        $this->assertTheNextPassFoldsItWhole();
    }

    public function test_a_concurrency_error_while_quarantining_yields_instead_of_escaping_the_pass(): void
    {
        $this->write(self::WRITER_1, $this->batch([$this->turnStart()]));
        $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);

        // A genuinely unprojectable event, so both attempts fail and the pass reaches the quarantine —
        // and the store is contended by the time the quarantine writes.
        $poison = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                throw new \RuntimeException('unprojectable');
            }
        };

        $contendedQuarantine = new class extends StateRecompute
        {
            public function after(FoldEvent $e, array $before, string $cause = 'wire_event'): bool
            {
                if ($cause === 'fold_error') {
                    throw ConcurrencyError::raised(1205);
                }

                return parent::after($e, $before, $cause);
            }
        };

        $applied = $this->foldPass(self::FOLD, new Fold($poison, $contendedQuarantine));

        $this->assertNothingFoldedAndNothingQuarantined($applied);
        $this->assertTheNextPassFoldsItWhole();
    }

    private function assertNothingFoldedAndNothingQuarantined(int $applied): void
    {
        $state = $this->state();

        $this->assertSame(0, $applied, 'the pass reported applied events');
        $this->assertSame(0, $this->counter('fold_error'), 'lock contention was quarantined as a poison event');
        $this->assertSame(0, (int) $state->fold_errors, 'lock contention was quarantined as a poison event');
        $this->assertSame(0, (int) $state->fold_cursor_event_id, 'the cursor moved past a contended event');
        $this->assertSame(0, DB::connection(self::FIXTURE)->table('seat_state_transitions')->where('seat_ref', $this->seatRef)->count(),
            'the yielded pass wrote a transition row');
        $this->assertSame('offline', $state->render_state, 'the yielded pass changed the render');
    }

    private function assertTheNextPassFoldsItWhole(): void
    {
        $this->foldUntilCaughtUp(self::FOLD);

        $state = $this->state();
        $this->assertSame((int) $state->head_event_id, (int) $state->fold_cursor_event_id);
        $this->assertSame(0, (int) $state->fold_errors);
    }
}
