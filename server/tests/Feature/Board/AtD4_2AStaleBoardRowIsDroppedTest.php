<?php

namespace Tests\Feature\Board;

use App\Fold\Clock;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * AT-D4-2 — a stale board row is dropped, never rendered (`docs/design/BOARD-TASK.md § 11`, § 8,
 * D2 § 4.9).
 *
 * The row is PRODUCED by a real poll and then its `observed_at` is placed at the boundary — the one
 * input this test is about, set to the two values either side of the bound. Ageing the whole seat
 * by half an hour instead is AT-D4-3's last test; here the assertion is on the boundary itself,
 * bound − 1 s and bound + 1 s, so it is not on an arbitrary distance from it (§ 11's control).
 */
class AtD4_2AStaleBoardRowIsDroppedTest extends BoardTaskTestCase
{
    private const CARD_TITLE = 'Drop tier 2 (the GitHub-sourced task title)';

    private function boardTitledSeatObservedAgo(int $agoMs): void
    {
        $this->liveTier3Seat();
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', self::CARD_TITLE),
        ])]]);
        $this->assertSame(0, $this->poll()[0]);

        DB::table('seat_board_task')->where('seat_ref', $this->seatRef)->update([
            'observed_at' => Clock::fromMs(Clock::toMs(Clock::sql(now())) - $agoMs),
        ]);

        $this->sweep();
    }

    public function test_past_the_bound_the_board_title_is_dropped_and_the_drop_is_labelled(): void
    {
        $this->boardTitledSeatObservedAgo(StateRecompute::BOARD_TITLE_BOUND_MS + 1000);

        $state = $this->state();
        $this->assertSame('telemetry', $state->task_source);
        $this->assertSame(self::TIER3_TITLE, $state->task_title);
        $this->assertNull($state->task_ref);
        $this->assertTrue((bool) $state->task_degraded);

        $task = $this->renderedTask();
        $this->assertTrue($task['degraded']);
        $this->assertStringNotContainsString(self::CARD_TITLE, json_encode($task), 'the board title appears nowhere in the rendered object');
        $this->assertStringNotContainsString('card#9234', json_encode($task));
    }

    public function test_inside_the_bound_the_board_title_answers(): void
    {
        $this->boardTitledSeatObservedAgo(StateRecompute::BOARD_TITLE_BOUND_MS - 1000);

        $task = $this->renderedTask();
        $this->assertSame('board_card', $task['source']);
        $this->assertSame(self::CARD_TITLE, $task['title']);
        $this->assertSame('card#9234', $task['ref']);
        $this->assertFalse($task['degraded']);
        $this->assertSame(
            Clock::wire(DB::table('seat_board_task')->where('seat_ref', $this->seatRef)->value('observed_at')),
            $task['as_of'],
            '§ 8.3: task.as_of is the stored observed_at, never now()',
        );
    }

    public function test_a_dropped_title_with_no_tier_3_beneath_it_is_absent_not_degraded(): void
    {
        // § 8.2: the whole `task` group goes to null together — a flag whose subject is absent is a
        // value no consumer can read.
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', self::CARD_TITLE),
        ])]]);
        $this->assertSame(0, $this->poll()[0]);

        DB::table('seat_board_task')->where('seat_ref', $this->seatRef)->update([
            'observed_at' => Clock::fromMs(Clock::toMs(Clock::sql(now())) - StateRecompute::BOARD_TITLE_BOUND_MS - 1000),
        ]);
        $this->sweep();

        $this->assertNull($this->renderedTask());
        $this->assertFalse((bool) $this->state()->task_degraded);
    }
}
