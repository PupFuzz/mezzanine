<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * card#11573 — a poison event is always either applied or quarantined, and the fold moves on.
 *
 * § 6.5's poison-event rule quarantines an event that raised twice. The quarantine used to re-run
 * `StateRecompute::after()` WITH THE POISON EVENT, so a raise that came from the recompute's own
 * write raised a third time inside the quarantine. That error left `fold()` and `pass()`: the event
 * was neither applied nor quarantined, the cursor stayed below it, and the seat — which stays the
 * furthest behind and so stays first in the claim — failed the same way on every pass, taking the
 * rest of that pass's claim with it.
 *
 * The first two tests plant a recompute-side raise: the first through the event (a real write the
 * store refuses, the route card#9346's non-ASCII `harness_label` took), the second through the
 * seat-scoped recompute the quarantine itself runs. The third is the per-attempt `FoldEvent`
 * obligation carried on the card from PR #324's review.
 */
class AQuarantineDoesNotDependOnTheStepThatFailedTest extends FoldTestCase
{
    /** `é` is two bytes of UTF-8 and no byte of ASCII, so an ASCII column refuses it under strict mode. */
    private const NON_ASCII = 'é';

    public function test_a_raise_from_the_recomputes_own_write_quarantines_the_event_and_the_pass_goes_on(): void
    {
        // A recompute whose OWN write fails for one event of this seat, after the projector applied
        // it: a real statement the store refuses, into the ASCII `seat_state.harness_label`.
        $recompute = new class($this->seatRef) extends StateRecompute
        {
            public function __construct(private readonly int $poisonedSeat)
            {
                parent::__construct();
            }

            public function after(FoldEvent $e, array $before): bool
            {
                $bumped = parent::after($e, $before);

                if ($e->kind === 'tool.end' && $e->seatRef === $this->poisonedSeat) {
                    DB::table('seat_state')->where('seat_ref', $e->seatRef)
                        ->update(['harness_label' => 'claude-code/2.1.240é']);
                }

                return $bumped;
            }
        };

        $this->deliver($this->cleanTurn());

        // A second seat behind the poisoned one in the claim, so a pass that dies on the first seat
        // is seen to cost the second one too.
        [$otherToken, $otherSeat] = $this->issueToken(self::INSTALL, 'aimla-impl');
        $this->deliver($this->cleanTurn(), token: $otherToken, seat: 'aimla-impl');

        $this->foldPasses($recompute, passes: 3);

        $this->assertFalse($this->behind(), 'a seat is still behind its head: the poison event wedged the fold');
        $this->assertSame(1, $this->counter('fold_error'));
        $this->assertSame(1, (int) $this->state()->fold_errors);
        $this->assertSame(0, $this->counter('fold_quarantine_recompute_error'),
            'the quarantine fell back to its degraded form for an event-side raise it should have recorded in full');

        // The full quarantine: § 6.5's transition row naming the skipped event, and the badge.
        $row = DB::table('seat_state_transitions')->where('seat_ref', $this->seatRef)
            ->where('cause', 'fold_error')->first();
        $this->assertNotNull($row, '§ 6.5 requires a transition row recording the fold error');
        $skipped = DB::table('events')->where('seat_ref', $this->seatRef)->where('kind', 'tool.end')->value('event_id');
        $this->assertSame(['kind' => 'tool.end', 'skipped_event_id' => $skipped], json_decode($row->detail, true));
        $this->assertNull($row->cause_event_ref);
        $this->assertContains('derivation_error', json_decode($this->state()->server_badges, true));

        // The refused write left nothing behind, and the events around the poison one applied.
        $this->assertNull($this->state()->harness_label);
        $this->assertSame('turn.end', $this->state()->last_activity_kind);

        // The other seat, behind the poisoned one in the claim, folded whole.
        $this->assertSame(0, $this->counter('fold_error', $otherSeat));
        $this->assertSame('turn.end', $this->state($otherSeat)->last_activity_kind);
    }

    public function test_a_second_raise_inside_the_quarantine_is_caught_counted_and_still_quarantines(): void
    {
        // The event's recompute raises, AND the seat-scoped recompute the quarantine runs raises too
        // — the shape of a seat whose derived columns cannot be computed at all (a raise in
        // `writeDerivedColumns()`, such as `SeatFacts::foldLagMs()` refusing an unseeded cursor).
        $recompute = new class extends StateRecompute
        {
            public function after(FoldEvent $e, array $before): bool
            {
                if ($e->kind === 'tool.end') {
                    throw new \RuntimeException('planted: the recompute raised for this event');
                }

                return parent::after($e, $before);
            }

            public function forSeat(int $seatRef, array $before, string $cause, array $detail = [], bool $owesRow = false): bool
            {
                throw new \RuntimeException('planted: the quarantine\'s own recompute raised');
            }
        };

        $this->deliver($this->cleanTurn());

        $this->foldPasses($recompute, passes: 3);

        $this->assertFalse($this->behind(), 'the poison event wedged the seat');
        $this->assertSame(1, $this->counter('fold_error'));
        $this->assertSame(1, (int) $this->state()->fold_errors);
        $this->assertSame(1, $this->counter('fold_quarantine_recompute_error'),
            'the quarantine\'s second raise was not counted once');

        // The recompute that raised wrote nothing — no transition row, no version for it — and the
        // event after the quarantined one applied, its recompute raising the badge off
        // `fold_errors`.
        $this->assertNotContains('fold_error', array_column($this->transitions(), 'cause'));
        $this->assertSame('turn.end', $this->state()->last_activity_kind);
        $this->assertContains('derivation_error', json_decode($this->state()->server_badges, true));
    }

    public function test_a_quarantined_event_writes_no_column_projected_from_it(): void
    {
        // The poison event is the window's LAST, so nothing applied after it can overwrite what a
        // quarantine wrote from it. Its projection raises; its recompute would not.
        $poison = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                if ($e->kind === 'turn.end') {
                    throw new \RuntimeException('unprojectable');
                }

                parent::apply($e);
            }
        };

        $turn = $this->cleanTurn();
        $this->deliver($turn);

        (new Fold($poison, new StateRecompute))->pass();

        $this->assertFalse($this->behind());
        $this->assertSame(1, $this->counter('fold_error'));
        $this->assertContains('fold_error', array_column($this->transitions(), 'cause'));

        // The activity trio and the `seq` high-water mark are the last APPLIED event's — the
        // `tool.end` — and not the quarantined `turn.end`'s.
        $state = $this->state();
        $this->assertSame('tool.end', $state->last_activity_kind, 'the quarantine projected the skipped event\'s activity columns');
        $this->assertSame($turn[2]['seq'], (int) $state->last_event_seq, 'the quarantine advanced the applied seq mark past an event it did not apply');
    }

    public function test_a_format_refusal_is_counted_once_when_an_earlier_attempt_rolled_back(): void
    {
        // `conforming()` counts once per INSTANCE, and the count is a row written inside the
        // attempt's transaction. A raise after the projection rolls the count back; if the next
        // attempt reused the instance, its "already counted" flag would survive the rollback and the
        // event would land counted zero times. The planted raise fails the whole-window attempt and
        // recovery attempt 1, and lets attempt 2 land.
        $projector = new class extends Projector
        {
            public int $raises = 0;

            public function apply(FoldEvent $e): void
            {
                parent::apply($e);

                if ($e->kind === 'session.start' && $this->raises < 2) {
                    $this->raises++;

                    throw new \RuntimeException('planted: transient, after the projection wrote');
                }
            }
        };

        $this->deliver([$this->event('session.start', [
            'source' => 'startup', 'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240'.self::NON_ASCII, 'previous_session_id' => null,
        ])]);

        (new Fold($projector, new StateRecompute))->pass();

        $this->assertSame(2, $projector->raises, 'the planted raise did not fail the two attempts it was meant to');
        $this->assertFalse($this->behind());
        $this->assertSame(0, $this->counter('fold_error'), 'the event was quarantined; it should have landed on attempt 2');
        $this->assertSame(1, $this->counter('format_refused.harness_label'),
            'format_refused.harness_label is not exactly one for one event: a rolled-back attempt\'s count leaked into, or suppressed, the attempt that landed');
        $this->assertNull($this->state()->harness_label);
    }

    /**
     * Run `$passes` fold passes with the real projector and `$recompute`, and fail naming EVERY pass
     * that raised out of `pass()` — the escape this card is about, which recurs on each pass because
     * the cursor never moves.
     */
    private function foldPasses(StateRecompute $recompute, int $passes): void
    {
        $raised = [];

        for ($i = 1; $i <= $passes; $i++) {
            try {
                (new Fold(new Projector, $recompute))->pass();
            } catch (\Throwable $e) {
                $raised[] = "pass {$i}: ".$e::class.': '.$e->getMessage();
            }
        }

        $this->assertSame([], $raised, 'fold passes raised out of pass(), so the poison event was neither applied nor quarantined');
    }
}
