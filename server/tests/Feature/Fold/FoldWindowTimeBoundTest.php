<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * card#9464 — a fold window stops at `Fold::WINDOW_BUDGET_MS` as well as at `Fold::BATCH`, and a
 * stopped window advances the cursor to the last event it APPLIED.
 *
 * Driven by a stepping `WindowClock`: `window()` reads it once at the lock grant and once after each
 * applied event, so a clock that advances a fixed step per read puts the budget check exactly where a
 * test wants it. `FoldWindowDurationTest` drives the same bound on the real clock and real connections.
 */
class FoldWindowTimeBoundTest extends FoldTestCase
{
    public function test_a_window_that_reaches_its_budget_stops_and_advances_to_the_last_applied_event(): void
    {
        $this->deliver($this->clearKill());
        $ids = $this->eventIds();

        // Elapsed after the k-th applied event is k × 100 ms, so a 300 ms budget stops after the third.
        $fold = new Fold(new Projector, new StateRecompute, windowBudgetMs: 300, clock: new SteppingClock(100));

        $this->assertSame(3, $fold->pass(), 'the window did not stop at its budget');
        $this->assertSame($ids[2], (int) $this->state()->fold_cursor_event_id,
            'the cursor is not on the last applied event');
        $this->assertTrue($this->behind(), 'the seat is not left on the claim with its unapplied tail');

        // The tail folds whole on the next pass, and nothing was skipped.
        $this->assertSame(count($ids) - 3, app(Fold::class)->pass());
        $this->assertSame(end($ids), (int) $this->state()->fold_cursor_event_id);
        $this->assertSame('unknown', $this->state()->activity_state);
        $this->assertSame(2, DB::table('calls')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(0, (int) $this->state()->fold_errors);
    }

    public function test_a_window_whose_budget_is_spent_before_its_first_event_still_applies_that_event(): void
    {
        $this->deliver($this->clearKill());
        $ids = $this->eventIds();

        $fold = new Fold(new Projector, new StateRecompute, windowBudgetMs: 300, clock: new SteppingClock(10_000));

        $this->assertSame(1, $fold->pass(), 'a window whose budget was already spent made no progress, or did not stop');
        $this->assertSame($ids[0], (int) $this->state()->fold_cursor_event_id);
    }

    public function test_a_window_that_never_reaches_its_budget_folds_as_an_unbounded_one_does(): void
    {
        $this->deliver($this->clearKill());
        $ids = $this->eventIds();

        $bounded = new Fold(new Projector, new StateRecompute, windowBudgetMs: 300, clock: new SteppingClock(0));
        $this->assertSame(count($ids), $bounded->pass());
        $this->assertSame(end($ids), (int) $this->state()->fold_cursor_event_id);

        // The control: the same fixture on a second seat, folded by the container's fold, whose budget
        // this window cannot reach.
        [$token, $controlRef] = $this->issueToken(self::INSTALL, 'aimla-control');
        $this->deliver($this->clearKill(), token: $token, seat: 'aimla-control');
        $this->assertSame(count($ids), app(Fold::class)->pass());

        $this->assertSame($this->folded($controlRef), $this->folded($this->seatRef));
    }

    public function test_a_raced_cursor_still_rolls_back_a_window_its_budget_stopped(): void
    {
        $this->deliver($this->clearKill());

        // Moves the cursor out from under the pass on the third event — the event the budget stops at.
        $racing = new class($this->seatRef) extends StateRecompute
        {
            private int $calls = 0;

            public function __construct(private int $seatRef)
            {
                parent::__construct();
            }

            /** @param  array<string, mixed>  $before */
            public function after(FoldEvent $e, array $before, string $cause = 'wire_event'): bool
            {
                $moved = parent::after($e, $before, $cause);

                if (++$this->calls === 3) {
                    DB::table('seat_state')->where('seat_ref', $this->seatRef)->update(['fold_cursor_event_id' => 999_999]);
                }

                return $moved;
            }
        };

        $applied = (new Fold(new Projector, $racing, windowBudgetMs: 300, clock: new SteppingClock(100)))->pass();

        $this->assertSame(0, $applied);
        $this->assertSame(0, (int) $this->state()->fold_cursor_event_id, 'a raced, budget-stopped window advanced the cursor');
        $this->assertSame(0, DB::table('calls')->where('seat_ref', $this->seatRef)->count(),
            'projections of a raced, budget-stopped window survived its rollback');
        $this->assertSame([], $this->transitions());
    }

    /** @return list<int> */
    private function eventIds(): array
    {
        return DB::table('events')->where('seat_ref', $this->seatRef)->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> what a fold leaves on a seat, less its identity and its clocks */
    private function folded(int $seatRef): array
    {
        $state = $this->state($seatRef);

        return [
            'caught_up' => (int) $state->fold_cursor_event_id === (int) $state->head_event_id,
            'render_state' => $state->render_state,
            'link_state' => $state->link_state,
            'activity_state' => $state->activity_state,
            'state_version' => (int) $state->state_version,
            'open_calls' => (int) $state->open_calls,
            'open_turn' => (bool) $state->open_turn,
            'fold_errors' => (int) $state->fold_errors,
            'calls' => DB::table('calls')->where('seat_ref', $seatRef)->count(),
            'transitions' => $this->transitions($seatRef),
        ];
    }
}
