<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * card#9398 — a concurrency error in the fold's transaction is TRANSIENT: the pass yields, and
 * nothing is quarantined.
 *
 * `docs/design/FLEET-STATE.md § 6.5`'s poison-event rule quarantines an event that raises twice.
 * Lock contention raises too — `1020` (snapshot isolation), `1205` (lock wait timeout), `1213`
 * (deadlock) — and quarantining on it skips an INNOCENT event past the cursor and badges
 * `derivation_error` on a healthy seat. So those three yield the pass instead: nothing is applied,
 * nothing is written, the cursor stays put, and the next pass folds the event whole.
 *
 * ⚠ THESE RUN AT TRANSACTION LEVEL 2. `RefreshDatabase` holds the suite's connection inside a
 * transaction, so the fold's own `Outbox::transaction()` is nested, and Laravel rethrows a nested
 * concurrency error as `DeadlockException` — not the `QueryException` a top-level fold (production,
 * `FoldCommand`) sees. `FoldConcurrencyErrorTopLevelTest` drives the top-level shape on committed
 * rows; the two together are what show the check holds at any depth.
 */
class FoldConcurrencyErrorTest extends FoldTestCase
{
    /** @return array<string, array{int}> */
    public static function errors(): array
    {
        return ['1205 lock wait timeout' => [1205], '1020 record changed since last read' => [1020]];
    }

    #[DataProvider('errors')]
    public function test_a_concurrency_error_in_the_window_yields_the_pass_and_quarantines_nothing(int $errno): void
    {
        $this->deliver($this->cleanTurn());

        $contended = new class($errno) extends Projector
        {
            public function __construct(private int $errno) {}

            public function apply(FoldEvent $e): void
            {
                throw ConcurrencyError::raised($this->errno);
            }
        };

        $applied = (new Fold($contended, new StateRecompute))->pass();

        $this->assertNothingFoldedAndNothingQuarantined($applied);
        $this->assertTheNextPassFoldsItWhole();
    }

    public function test_a_concurrency_error_in_the_one_at_a_time_recovery_yields_and_quarantines_nothing(): void
    {
        $this->deliver($this->cleanTurn());

        // The window fails for an ordinary reason, which is what routes the pass into the
        // one-event-at-a-time recovery — and there the store is contended.
        $contended = new class extends Projector
        {
            private int $calls = 0;

            public function apply(FoldEvent $e): void
            {
                if ($this->calls++ === 0) {
                    throw new \RuntimeException('the window fails for a reason of its own');
                }

                throw ConcurrencyError::raised(1205);
            }
        };

        $applied = (new Fold($contended, new StateRecompute))->pass();

        $this->assertNothingFoldedAndNothingQuarantined($applied);
        $this->assertTheNextPassFoldsItWhole();
    }

    private function assertNothingFoldedAndNothingQuarantined(int $applied): void
    {
        $this->assertSame(0, $applied, 'the pass reported applied events');
        $this->assertSame(0, $this->counter('fold_error'), 'lock contention was quarantined as a poison event');
        $this->assertSame(0, (int) $this->state()->fold_errors, 'lock contention was quarantined as a poison event');
        $this->assertSame(0, (int) $this->state()->fold_cursor_event_id, 'the cursor moved past a contended event');
        $this->assertSame([], $this->transitions(), 'the yielded pass wrote a transition row');
        $this->assertSame('offline', $this->state()->render_state, 'the yielded pass changed the render');
    }

    private function assertTheNextPassFoldsItWhole(): void
    {
        $this->fold();

        $this->assertSame((int) $this->state()->head_event_id, (int) $this->state()->fold_cursor_event_id);
        $this->assertSame(0, (int) $this->state()->fold_errors);
        $this->assertSame('idle', $this->state()->activity_state);
    }
}
