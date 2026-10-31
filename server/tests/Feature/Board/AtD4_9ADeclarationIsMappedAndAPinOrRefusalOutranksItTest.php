<?php

namespace Tests\Feature\Board;

use App\Board\LinkPass;

/**
 * AT-D4-9 — a confirmed declaration is mapped, and a pin or a refusal outranks it (card#12447,
 * `docs/design/BOARD-TASK.md § 4.4`, § 4.1).
 *
 * Two of the AT's REDs are held by an assertion below, each seen to fail when planted: a pass that
 * overwrites a pinned seat (the third poll's `15`) and one that fills a refused seat (the fifth poll).
 * ⚠ The third — a pass that skips the row deletion on its own write — is NOT observable here, and this
 * test claims nothing about it: the card read in the same transaction upserts the newly mapped seat's
 * row, overwriting whatever the deletion would have removed, so the plant changes no committed state.
 * The deletion is kept because § 4.1's rule is stated over every write of the column.
 */
class AtD4_9ADeclarationIsMappedAndAPinOrRefusalOutranksItTest extends BoardTaskTestCase
{
    private function boardWithBothUsersCards(): void
    {
        $this->members = [self::BOARD => ['data' => [$this->member(14), $this->member(15)]]];
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, 14, '2026-09-11T02:10:00+00:00', 'user 14\'s card'),
            $this->card(9300, 15, '2026-09-11T02:10:00+00:00', 'user 15\'s card'),
        ])]]);
    }

    private function seatBoardUser(array $args): int
    {
        return $this->artisan('mezzanine:seat-board-user', ['--seat' => self::INSTALL.'/'.self::SEAT] + $args)->run();
    }

    public function test_a_confirmed_declaration_maps_and_the_operator_outranks_it_both_ways(): void
    {
        $this->liveTier3Seat();
        $this->boardWithBothUsersCards();
        $this->declare(14);

        // Poll 1: mapped from the declaration, and the card read in the same poll writes its row.
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping());
        $this->assertSame(1, $this->seatCounter(LinkPass::MAPPED));
        $this->assertSame(9234, (int) ($this->boardRow()['card_id'] ?? 0), 'the same poll did not write the new mapping\'s row');

        // Poll 2: the same declaration is already done — nothing written, nothing counted.
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame(1, $this->seatCounter(LinkPass::MAPPED), 'an unchanged declaration was mapped again');

        // The operator pins 15: the row goes in that transaction, and poll 3 leaves 15 standing.
        $this->assertSame(0, $this->seatBoardUser(['--board-user' => '15']));
        $this->assertSame([15, 'operator'], $this->mapping());
        $this->assertNull($this->boardRow(), 'the pin left the previous user\'s row');
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([15, 'operator'], $this->mapping(), 'the pass overrode the operator\'s pin');

        // `--clear` returns the seat to automatic linking: both columns null, and poll 4 maps 14 again.
        $this->assertSame(0, $this->seatBoardUser(['--clear' => true]));
        $this->assertSame([null, null], $this->mapping());
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping());

        // `--refuse`: null with `refused`, the row gone, and poll 5 writes and counts nothing.
        $this->assertSame(0, $this->seatBoardUser(['--refuse' => true]));
        $this->assertSame([null, 'refused'], $this->mapping());
        $this->assertNull($this->boardRow());
        $mapped = $this->seatCounter(LinkPass::MAPPED);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([null, 'refused'], $this->mapping(), 'the pass filled a refused seat');
        $this->assertSame($mapped, $this->seatCounter(LinkPass::MAPPED));
        $this->assertNull($this->boardRow());
    }

    public function test_exactly_one_verb_is_accepted(): void
    {
        $this->assertNotSame(0, $this->seatBoardUser(['--refuse' => true, '--clear' => true]));
        $this->assertNotSame(0, $this->seatBoardUser(['--refuse' => true, '--board-user' => '14']));
        $this->assertNotSame(0, $this->seatBoardUser([]));
        $this->assertSame([null, null], $this->mapping());
    }

    /** Control: a seat declaring nothing, never mapped, is untouched by every poll and counts nothing. */
    public function test_control_a_seat_declaring_nothing_is_left_alone(): void
    {
        $this->liveTier3Seat();
        $this->boardWithBothUsersCards();
        $this->declare(null);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(0, $this->poll()[0]);
        }

        $this->assertSame([null, null], $this->mapping());
        foreach ([LinkPass::MAPPED, LinkPass::CLEARED, LinkPass::CONFLICT, LinkPass::UNKNOWN_USER] as $c) {
            $this->assertSame(0, $this->seatCounter($c), $c.' moved for a seat that declares nothing');
        }
        $this->assertNull($this->boardRow());
    }
}
