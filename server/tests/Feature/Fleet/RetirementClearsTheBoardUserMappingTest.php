<?php

namespace Tests\Feature\Fleet;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sweep\SweepTestCase;

/**
 * Retirement clears the seat's board-user mapping — `docs/design/FLEET-STATE.md § 2.1` / § 4.10 /
 * § 6.7, and `docs/design/BOARD-TASK.md` AT-D4-8, as ratified on card#7582 (2026-09-12).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS GUARDS, AND WHY IT IS NOT BOOKKEEPING. § 6.7 states the invariant in one sentence:
 * a `seat_board_task` row "leaves in exactly two ways" — any write of `seats.board_user_id`, and
 * the seat's retirement. The second way had NO WRITER when `BOARD-TASK.md` was drafted, and the
 * review that measured it named the cost precisely: `board_user_id` is UNIQUE, so a retired seat
 * that keeps it holds that board user against the whole fleet, and **the replacement seat cannot
 * be mapped to the same person** until somebody runs `mezzanine:seat-board-user --clear` on a
 * seat that no longer appears on any read surface. The only symptom is that command's bare
 * non-zero exit. The operator's ruling took that fork's option (b) — give the second way a
 * writer — rather than softening the sentence to match a missing one.
 *
 * ⚠ THE SECOND TEST IS THE ONE THAT MATTERS, and it asserts the CONSEQUENCE rather than the
 * columns: after retiring a mapped seat, the same board user maps onto a replacement seat. Before
 * the fix that INSERT is refused by `uq_seat_board_user`, which is the operator-visible harm; the
 * first test's column assertions would pass on a fix that nulled the column and left the row, and
 * on one that deleted the row and left the column. Three assertions over two failure directions.
 *
 * ⛔ THE ACT IS THE COMMAND, NOT AN UPDATE — AT-D2-23's build rule, inherited: "not by writing the
 * columns directly, because the command *is* the mechanism under test". `retire()` is
 * `SweepTestCase`'s helper and runs `mezzanine:retire` with its three real arguments.
 *
 * ⚠ THE MAPPING IS SEEDED BY AN UPDATE AND THAT IS NOT THE SAME COMPROMISE. `mezzanine:seat-board-user`
 * is DESIGN ONLY — the ruling says building the poller and its mapping command is a separate pull
 * — so there is no producer to drive here. What is under test is the RETIREMENT act; its input is
 * a store state, and seeding a store state is what a fixture is. The moment that command exists,
 * this seeding is what should reach for it.
 */
class RetirementClearsTheBoardUserMappingTest extends SweepTestCase
{
    private const BOARD_USER = 14;

    /**
     * GREEN — the column is nulled and the row is deleted, by the retirement act.
     */
    public function test_retiring_a_mapped_seat_nulls_the_column_and_deletes_the_input_row(): void
    {
        $this->map($this->seatRef, self::BOARD_USER);

        $this->assertSame(self::BOARD_USER, (int) DB::table('seats')->where('id', $this->seatRef)->value('board_user_id'));
        $this->assertSame(1, DB::table('seat_board_task')->where('seat_ref', $this->seatRef)->count(),
            'the fixture must exist before the act, or the assertions below measure nothing');

        $this->retire();

        $seat = DB::table('seats')->where('id', $this->seatRef)->first();

        $this->assertNotNull($seat->retired_at, 'the act itself must have happened');
        $this->assertNull($seat->board_user_id, 'retirement leaves the seat holding a live board join');
        $this->assertSame(0, DB::table('seat_board_task')->where('seat_ref', $this->seatRef)->count(),
            'the input row outlived the seat that was its only subject');
    }

    /**
     * GREEN — the consequence the ruling was made on: the board user is free for a replacement
     * seat. This is the assertion that a half-fix (either column or row, not both) fails.
     */
    public function test_the_board_user_can_be_mapped_to_a_replacement_seat_after_the_retirement(): void
    {
        $this->map($this->seatRef, self::BOARD_USER);
        [, $replacement] = $this->issueToken(self::INSTALL, 'aimla-pm-2');

        // The control: while the retired-to-be seat holds the mapping, the replacement CANNOT
        // have it. Without this the assertion below would pass against a store with no unique
        // key at all, which is a check that cannot fail.
        try {
            $this->map($replacement, self::BOARD_USER);
            $this->fail('uq_seat_board_user did not refuse a second seat for one board user');
        } catch (QueryException) {
            // expected: § 6.4's UNIQUE key, doing its job
        }

        $this->retire();

        $this->map($replacement, self::BOARD_USER);

        $this->assertSame(self::BOARD_USER, (int) DB::table('seats')->where('id', $replacement)->value('board_user_id'));
    }

    /**
     * GREEN — retiring an UNMAPPED seat is the ordinary case and is untouched; and the act is
     * scoped to its own seat, so another seat's mapping and input row survive it.
     */
    public function test_the_clearing_is_scoped_to_the_retired_seat_and_no_mapping_is_not_an_error(): void
    {
        [, $other] = $this->issueToken(self::INSTALL, 'aimla-impl');
        $this->map($other, self::BOARD_USER);

        // The seat under test carries no mapping at all.
        $this->retire();

        $this->assertNotNull(DB::table('seats')->where('id', $this->seatRef)->value('retired_at'));
        $this->assertSame(self::BOARD_USER, (int) DB::table('seats')->where('id', $other)->value('board_user_id'),
            'the retirement reached another seat');
        $this->assertSame(1, DB::table('seat_board_task')->where('seat_ref', $other)->count(),
            'the retirement deleted another seat\'s input row');
    }

    /**
     * Seed what `mezzanine:seat-board-user` and `mezzanine:board-poll` will write when they are
     * built: the mapping, and one input row for it.
     */
    private function map(int $seatRef, int $boardUser): void
    {
        DB::table('seats')->where('id', $seatRef)->update(['board_user_id' => $boardUser]);

        DB::table('seat_board_task')->insert([
            'seat_ref' => $seatRef,
            'card_id' => 7582,
            'board_id' => 14,
            'title' => 'a card title the poller truncated',
            'card_updated_at' => '2026-08-26 11:00:00.000',
            'observed_at' => '2026-08-26 11:59:00.000',
        ]);
    }
}
