<?php

namespace Tests\Feature\Board;

use App\Fold\Clock;
use App\Fold\StateRecompute;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AT-D4-3 — an unreachable board writes nothing (`docs/design/BOARD-TASK.md § 11`, § 7.3, § 6.3).
 *
 * ⛔ THE RULE UNDER TEST IS § 7.3's: a degraded read writes NOTHING — not one row, not
 * `observed_at`, not a null-out. The attractive defect is writing `card_id = NULL` when the read
 * failed: it is indistinguishable from the board saying *no card*, so the title vanishes on the
 * first failure and `task.degraded` never fires. Every degraded path § 9 lists runs here against a
 * seeded row, which must come out byte-identical; the § 6.5 invalid read is two of them.
 *
 * THE CONTROL is the same fixture with the board REACHABLE, which must move `observed_at` — so the
 * byte-identical assertion is known to be able to see a write.
 */
class AtD4_3AnUnreachableBoardWritesNothingTest extends BoardTaskTestCase
{
    /** Map the seat and seed its row with one clean poll; return the seeded row. */
    private function seedRow(): array
    {
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'Drop tier 2 (the GitHub-sourced task title)'),
        ])]]);

        [$code] = $this->poll();
        $this->assertSame(0, $code, 'the seeding poll must succeed, or the row below is not a seeded one');

        $row = $this->boardRow();
        $this->assertSame(9234, (int) $row['card_id']);

        return $row;
    }

    #[DataProvider('degradedReads')]
    public function test_a_degraded_poll_exits_non_zero_counts_a_failure_and_leaves_the_row_byte_identical(string $scenario): void
    {
        $seeded = $this->seedRow();
        $this->assertSame([1, 0], $this->pollCounters());

        $this->advanceServerClock(300);
        $this->arrangeDegraded($scenario);

        [$code, $printed] = $this->poll();

        $this->assertSame($seeded, $this->boardRow(), $scenario.': the row moved — a failed poll wrote');
        $this->assertNotSame(0, $code, $scenario.': a degraded poll exits non-zero');
        $this->assertSame([1, 1], $this->pollCounters(), $scenario.': board_poll_failed + 1, board_poll_ok unmoved');
        $this->assertStringContainsString('nothing written', $printed);
    }

    public function test_the_control_a_reachable_board_moves_observed_at(): void
    {
        $seeded = $this->seedRow();

        $this->advanceServerClock(300);
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'Drop tier 2 (the GitHub-sourced task title)'),
        ])]]);

        [$code] = $this->poll();

        $this->assertSame(0, $code);
        $this->assertNotSame($seeded['observed_at'], $this->boardRow()['observed_at'],
            'a clean poll must move observed_at, or the byte-identical assertion above sees nothing');
        $this->assertSame(array_diff_key($seeded, ['observed_at' => 1]), array_diff_key($this->boardRow(), ['observed_at' => 1]));
    }

    /**
     * The GREEN's last clause: after the failure the desk still renders the board title until the
     * bound, then drops it with `task.degraded`. Driven by heartbeats and sweep passes, the way a
     * live seat ages — never by moving `observed_at`, which this test exists to prove nothing moved.
     */
    public function test_after_a_failure_the_desk_keeps_its_board_title_until_the_bound_then_drops_it_degraded(): void
    {
        $this->liveTier3Seat();
        $seeded = $this->seedRow();

        $this->advanceServerClock(60);
        $this->arrangeDegraded('connection refused');
        [$code] = $this->poll();
        $this->assertNotSame(0, $code);

        // Age to 1 s inside the bound, keeping the transport alive every 10 minutes.
        $target = Clock::toMs($seeded['observed_at']) + StateRecompute::BOARD_TITLE_BOUND_MS - 1000;

        while (Carbon::now()->getTimestampMs() + 600_000 < $target) {
            $this->advanceServerClock(600);
            $this->stayAlive();
        }

        Carbon::setTestNow(Carbon::createFromTimestampMs($target, 'UTC'));
        $this->sweep();

        $task = $this->renderedTask();
        $this->assertSame('board_card', $task['source'], 'inside the bound the board title still stands');
        $this->assertSame('card#9234', $task['ref']);
        $this->assertFalse($task['degraded']);

        $this->advanceServerClock(2);
        $this->sweep();

        $task = $this->renderedTask();
        $this->assertSame('telemetry', $task['source'], 'past the bound the board title is dropped');
        $this->assertSame(self::TIER3_TITLE, $task['title']);
        $this->assertTrue($task['degraded'], 'and the drop is labelled');
        $this->assertSame($seeded, $this->boardRow(), 'nothing in the ageing wrote the input row');
    }
}
