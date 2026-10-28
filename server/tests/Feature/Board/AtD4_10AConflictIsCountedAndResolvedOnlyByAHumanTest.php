<?php

namespace Tests\Feature\Board;

use App\Board\LinkPass;
use Illuminate\Support\Facades\Log;

/**
 * AT-D4-10 — a conflict is counted on every refused seat and resolved only by a human act (card#12447,
 * `docs/design/BOARD-TASK.md § 4.4` step 4's fleet leg and its contention set).
 *
 * The REDs this holds: a rule preferring the newer declaration (A's `14` would move to B), a pass that
 * catches the UNIQUE violation and counts nothing (B's counter), and a pass that maps the first of two
 * contenders it reads (the reversed-order case).
 */
class AtD4_10AConflictIsCountedAndResolvedOnlyByAHumanTest extends BoardTaskTestCase
{
    private function seat(string $seatId): array
    {
        return $this->issueToken(self::INSTALL, $seatId);
    }

    public function test_a_held_user_is_refused_on_the_declaring_seat_and_a_retirement_frees_it(): void
    {
        $this->members = [self::BOARD => ['data' => [$this->member(14), $this->member(15)]]];
        $this->board([self::BOARD => [1 => $this->page([])]]);

        // Seat A (the suite's default seat) maps 14 by declaration.
        $this->declare(14);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping());

        [$tokB, $refB] = $this->seat('aimla-impl');
        $this->declare(14, token: $tokB, seat: 'aimla-impl');

        Log::spy();
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping(), 'the holder lost its user to a copied declaration');
        $this->assertSame([null, null], $this->mapping($refB));
        $this->assertSame(1, $this->seatCounter(LinkPass::CONFLICT, $refB));
        $this->assertSame(0, $this->seatCounter(LinkPass::CONFLICT), 'the holder was counted');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => is_string($m)
            && $m === 'board link conflict: user 14 declared by aimla/aimla-impl, held by aimla/aimla-pm')->once();

        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame(2, $this->seatCounter(LinkPass::CONFLICT, $refB), 'the conflict is not counted on every poll');

        // Retire A: the retirement act clears its mapping, row and source; the next poll maps B.
        $this->artisan('mezzanine:retire', ['--seat' => self::INSTALL.'/'.self::SEAT, '--by' => 'suite', '--reason' => 'replaced'])
            ->assertSuccessful();
        $this->assertSame([null, null], $this->mapping());
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping($refB));
    }

    public function test_two_unmapped_contenders_map_neither_in_either_read_order_until_one_is_refused(): void
    {
        $this->members = [self::BOARD => ['data' => [$this->member(16)]]];
        $this->board([self::BOARD => [1 => $this->page([])]]);

        [$tokC, $refC] = $this->seat('aimla-impl-c');
        [$tokD, $refD] = $this->seat('aimla-impl-d');
        // D declares FIRST, so the rows' declaration order is the reverse of their id order.
        $this->declare(16, token: $tokD, seat: 'aimla-impl-d');
        $this->declare(16, token: $tokC, seat: 'aimla-impl-c');

        Log::spy();
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([[null, null], [null, null]], [$this->mapping($refC), $this->mapping($refD)],
            'a contender was mapped: the answer depends on the read order');
        $this->assertSame([1, 1], [$this->seatCounter(LinkPass::CONFLICT, $refC), $this->seatCounter(LinkPass::CONFLICT, $refD)]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => is_string($m) && str_starts_with($m, 'board link conflict: user 16 declared by '))->twice();

        $this->artisan('mezzanine:seat-board-user', ['--seat' => self::INSTALL.'/aimla-impl-d', '--refuse' => true])->assertSuccessful();
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([16, 'declared'], $this->mapping($refC), 'D left the contention set by the operator\'s word, and C did not map');
        $this->assertSame([null, 'refused'], $this->mapping($refD));
    }

    /** Control: B declaring 15 maps B, so the conflict assertion turns on the collision and not on B. */
    public function test_control_a_distinct_declaration_maps(): void
    {
        $this->members = [self::BOARD => ['data' => [$this->member(14), $this->member(15)]]];
        $this->board([self::BOARD => [1 => $this->page([])]]);
        $this->declare(14);
        [$tokB, $refB] = $this->seat('aimla-impl');
        $this->declare(15, token: $tokB, seat: 'aimla-impl');

        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([[14, 'declared'], [15, 'declared']], [$this->mapping(), $this->mapping($refB)]);
        $this->assertSame(0, $this->seatCounter(LinkPass::CONFLICT, $refB));
    }
}
