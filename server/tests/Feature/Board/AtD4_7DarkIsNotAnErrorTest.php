<?php

namespace Tests\Feature\Board;

use App\Fold\Clock;

/**
 * AT-D4-7 — dark is not an error (`docs/design/BOARD-TASK.md § 11`, § 10).
 *
 * A reachable board on which NO card carries an assignee is today's real state, and it is a CLEAN
 * poll: `board_poll_ok` climbs, every mapped seat gets a `card_id = NULL` row with a fresh
 * `observed_at`, and every desk renders tier 3 with `task.degraded: false`. Treating "no
 * candidates" as a failure would make `board_poll_failed` climb forever against a healthy board —
 * the alarm that trains an operator to ignore alarms.
 */
class AtD4_7DarkIsNotAnErrorTest extends BoardTaskTestCase
{
    public function test_a_board_with_no_assigned_card_is_a_clean_poll_and_every_desk_stays_on_tier_3(): void
    {
        $this->liveTier3Seat();
        $this->map();

        $this->board([self::BOARD => [1 => $this->page([
            $this->card(7582, null, '2026-09-10T18:26:00+00:00'),
            $this->card(9208, null, '2026-09-09T11:04:00+00:00'),
        ])]]);

        $startedAt = Clock::sql(now());
        [$code] = $this->poll();

        $this->assertSame(0, $code);
        $this->assertSame([1, 0], $this->pollCounters(), 'board_poll_ok + 1 and board_poll_failed unmoved');

        $row = $this->boardRow();
        $this->assertNotNull($row, 'a mapped seat gets a row on a clean poll — the NULL row is the point (§ 7.2)');
        $this->assertNull($row['card_id']);
        $this->assertNull($row['board_id']);
        $this->assertNull($row['title']);
        $this->assertNull($row['card_updated_at']);
        $this->assertSame($startedAt, $row['observed_at'], 'observed_at is the poll\'s start stamp');

        $this->sweep();

        $task = $this->renderedTask();
        $this->assertSame('telemetry', $task['source']);
        $this->assertFalse($task['degraded'], 'dark is not degraded');
        $this->assertNull($task['ref']);
    }

    public function test_an_unconfigured_poller_is_a_no_op_at_exit_0_and_moves_no_counter(): void
    {
        $this->map();
        $this->configure(null, null, '');
        $this->board([]);

        [$code] = $this->poll();

        $this->assertSame(0, $code);
        $this->assertSame([0, 0], $this->pollCounters(), '§ 9: unconfigured is not failure, and not success either');
        $this->assertSame([], $this->requested, 'an unconfigured poller asks the board nothing');
        $this->assertNull($this->boardRow());
    }
}
