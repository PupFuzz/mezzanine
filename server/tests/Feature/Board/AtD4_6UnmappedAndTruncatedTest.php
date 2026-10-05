<?php

namespace Tests\Feature\Board;

use App\Fold\StateRecompute;
use App\Support\ByteTruncation;
use Illuminate\Support\Facades\DB;

/**
 * AT-D4-6 — an unmapped seat is untouched, and a long title is truncated
 * (`docs/design/BOARD-TASK.md § 11`, § 7.2, § 8.4).
 *
 * The long name is MULTIBYTE and shorter than the bound in CHARACTERS — the case card#9282 found
 * `mb_substr` lets through. It is cut once, at the poller, by D1 § 7.4's byte procedure, and the
 * input row and `seat_state.task_title` must then be the SAME string.
 */
class AtD4_6UnmappedAndTruncatedTest extends BoardTaskTestCase
{
    private const OTHER = 'aimla-impl';

    public function test_the_unmapped_seat_gets_no_row_and_keeps_tier_3_and_the_mapped_seat_title_is_truncated_once(): void
    {
        // 70 box-drawing characters: 210 bytes, 70 characters — under 120 CHARACTERS, over 120 BYTES.
        $long = str_repeat("\u{2500}", 70);
        $this->assertGreaterThan(StateRecompute::TASK_TITLE_MAX_BYTES, strlen($long));
        $this->assertLessThan(StateRecompute::TASK_TITLE_MAX_BYTES, mb_strlen($long));

        $this->liveTier3Seat();
        [$otherToken] = $this->issueToken(self::INSTALL, self::OTHER);
        $otherRef = (int) DB::table('seats')->where('seat_id', self::OTHER)->value('id');
        $this->deliver($this->openCall(), token: $otherToken, seat: self::OTHER);
        $this->fold();
        $otherBefore = $this->taskColumns($otherRef);
        $this->assertSame('telemetry', $otherBefore['task_source']);

        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', $long),
            $this->card(9235, 99, '2026-09-11T02:10:00+00:00', 'assigned to a user no seat maps'),
        ])]]);

        $this->assertNull($this->boardRow($otherRef));
        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();

        $this->assertNull($this->boardRow($otherRef), 'the unmapped seat got a row');
        $this->assertSame($otherBefore, $this->taskColumns($otherRef), 'the unmapped seat\'s task columns moved');

        $stored = $this->boardRow()['title'];
        $this->assertSame(ByteTruncation::toBytes($long, StateRecompute::TASK_TITLE_MAX_BYTES), $stored);
        $this->assertLessThanOrEqual(StateRecompute::TASK_TITLE_MAX_BYTES, strlen($stored));
        $this->assertStringEndsWith(ByteTruncation::MARK, $stored);
        $this->assertSame($stored, $this->state()->task_title, 'the input row and the projection disagree about the title');
        $this->assertSame('board_card', $this->state()->task_source);
    }

    /** @return array<string, mixed> */
    private function taskColumns(int $seatRef): array
    {
        $s = (array) $this->state($seatRef);

        return array_intersect_key($s, array_flip(['task_title', 'task_source', 'task_ref', 'task_as_of', 'task_degraded']));
    }
}
