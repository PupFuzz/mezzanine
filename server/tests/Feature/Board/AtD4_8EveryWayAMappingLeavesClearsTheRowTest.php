<?php

namespace Tests\Feature\Board;

use Illuminate\Support\Facades\DB;

/**
 * AT-D4-8 — every way a mapping leaves clears the row (`docs/design/BOARD-TASK.md § 11`, § 4.1,
 * § 7.2), and the seat→board-user command's refusals.
 *
 * Three ways a seat's mapping leaves: `--clear`, a re-map with `--board-user`, and retirement. Each
 * must take the `seat_board_task` row with it in the same act — otherwise the merge goes on
 * answering from a card the seat is no longer joined to, and the re-map case is the hardest to
 * see: a desk showing the PREVIOUS user's card with `task.degraded` false and every field
 * internally consistent. Retirement's own half is `Tests\Feature\Fleet\
 * RetirementClearsTheBoardUserMappingTest`; the third build here drives it end to end from a real
 * poll.
 */
class AtD4_8EveryWayAMappingLeavesClearsTheRowTest extends BoardTaskTestCase
{
    private function boardTitledSeat(): void
    {
        $this->liveTier3Seat();
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'user 14\'s card'),
            $this->card(9300, 15, '2026-09-11T02:10:00+00:00', 'user 15\'s card'),
        ])]]);
        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();

        $this->assertSame('board_card', $this->renderedTask()['source'], 'the fixture must start board-titled');
    }

    private function seatBoardUser(array $args): int
    {
        return $this->artisan('mezzanine:seat-board-user', ['--seat' => self::INSTALL.'/'.self::SEAT] + $args)->run();
    }

    public function test_clear_nulls_the_column_and_deletes_the_row_and_the_next_recompute_renders_tier_3(): void
    {
        $this->boardTitledSeat();

        $this->assertSame(0, $this->seatBoardUser(['--clear' => true]));

        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('board_user_id'));
        $this->assertNull($this->boardRow(), 'the row outlived the mapping it was joined through');

        $this->sweep();
        $task = $this->renderedTask();
        $this->assertSame('telemetry', $task['source']);
        $this->assertFalse($task['degraded'], 'a cleared mapping is dark, not degraded');

        // And the poller no longer writes the seat.
        $this->assertSame(0, $this->poll()[0]);
        $this->assertNull($this->boardRow());
    }

    public function test_a_re_map_deletes_the_previous_users_row_so_nothing_answers_until_the_next_poll(): void
    {
        $this->boardTitledSeat();

        $this->assertSame(0, $this->seatBoardUser(['--board-user' => '15']));

        $this->assertSame(15, (int) DB::table('seats')->where('id', $this->seatRef)->value('board_user_id'));
        $this->assertNull($this->boardRow(), 'the previous user\'s card would answer for this seat');

        $this->sweep();
        $this->assertSame('telemetry', $this->renderedTask()['source']);

        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();
        $this->assertSame('card#9300', $this->renderedTask()['ref'], 'the next poll writes the new user\'s answer');
    }

    public function test_retirement_clears_the_mapping_and_frees_the_board_user_for_a_replacement(): void
    {
        $this->boardTitledSeat();

        $this->retire();

        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('board_user_id'));
        $this->assertNull($this->boardRow());

        $this->issueToken(self::INSTALL, 'aimla-pm-2');
        $this->artisan('mezzanine:seat-board-user', ['--seat' => self::INSTALL.'/aimla-pm-2', '--board-user' => (string) self::USER])
            ->assertSuccessful();
    }

    public function test_the_unique_key_refuses_a_second_seat_for_one_board_user_and_changes_nothing(): void
    {
        $this->boardTitledSeat();
        $this->issueToken(self::INSTALL, 'aimla-impl');
        $before = $this->boardRow();

        $this->artisan('mezzanine:seat-board-user', ['--seat' => self::INSTALL.'/aimla-impl', '--board-user' => (string) self::USER])
            ->expectsOutputToContain('already mapped to aimla/aimla-pm')
            ->assertFailed();

        $this->assertSame($before, $this->boardRow(), 'the refused act touched the holder\'s row');
        $this->assertNull(DB::table('seats')->where('seat_id', 'aimla-impl')->value('board_user_id'));
    }

    public function test_an_unknown_seat_a_bad_id_and_a_missing_choice_are_refused_before_anything_is_written(): void
    {
        $this->boardTitledSeat();
        $before = [$this->boardRow(), DB::table('seats')->where('id', $this->seatRef)->value('board_user_id')];

        $this->artisan('mezzanine:seat-board-user', ['--seat' => 'aimla/nobody', '--board-user' => '15'])->assertFailed();

        foreach (['0', '-3', '15x', '1.5', ' 15', '4294967296', ''] as $bad) {
            $this->assertNotSame(0, $this->seatBoardUser(['--board-user' => $bad]), 'accepted --board-user='.var_export($bad, true));
        }

        $this->assertNotSame(0, $this->seatBoardUser([]), 'neither --board-user nor --clear');
        $this->assertNotSame(0, $this->seatBoardUser(['--board-user' => '15', '--clear' => true]), 'both');

        $this->assertSame($before, [$this->boardRow(), DB::table('seats')->where('id', $this->seatRef)->value('board_user_id')]);
    }

    public function test_a_retired_seat_is_not_mapped(): void
    {
        $this->retire();

        $this->assertNotSame(0, $this->seatBoardUser(['--board-user' => (string) self::USER]));
        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('board_user_id'));

        $this->assertSame(0, $this->seatBoardUser(['--clear' => true]), '--clear on a retired seat writes the NULL it already holds');
    }
}
