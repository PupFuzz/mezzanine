<?php

namespace Tests\Feature\Board;

/**
 * AT-D4-5 — two cards, one seat, one deterministic answer (`docs/design/BOARD-TASK.md § 11`,
 * § 4.2).
 *
 * Greatest `updated_at`; on an exact tie, greatest `id`. Both fixtures are served in BOTH row
 * orders, because a winner chosen by "whichever came last" passes any single order half the time
 * and is exactly the flapping desk title § 4.2 forbids.
 */
class AtD4_5OneDeterministicAnswerTest extends BoardTaskTestCase
{
    private function winnerOf(array $rows): array
    {
        $this->board([self::BOARD => [1 => $this->page($rows)]]);
        $this->assertSame(0, $this->poll()[0]);

        $row = $this->boardRow();

        return [(int) $row['card_id'], $row['title']];
    }

    public function test_the_greatest_updated_at_wins_in_either_order(): void
    {
        $this->map();
        $older = $this->card(9300, self::USER, '2026-09-09T11:04:00+00:00', 'older, larger id');
        $newer = $this->card(9208, self::USER, '2026-09-11T02:10:00+00:00', 'newer, smaller id');

        $this->assertSame([9208, 'newer, smaller id'], $this->winnerOf([$older, $newer]));
        $this->assertSame([9208, 'newer, smaller id'], $this->winnerOf([$newer, $older]));
    }

    public function test_on_an_exact_tie_the_greatest_id_wins_in_either_order_and_a_second_poll_does_not_move_it(): void
    {
        $this->map();
        $a = $this->card(9208, self::USER, '2026-09-11T02:10:00+00:00', 'smaller id');
        $b = $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'greater id');

        $this->assertSame([9234, 'greater id'], $this->winnerOf([$a, $b]));
        $this->assertSame([9234, 'greater id'], $this->winnerOf([$b, $a]));
        $this->assertSame([9234, 'greater id'], $this->winnerOf([$b, $a]), 'a second poll over unchanged input');
    }

    public function test_the_same_instant_in_two_offsets_is_a_tie_and_not_an_ordering(): void
    {
        // `updated_at` is compared as an INSTANT. 02:10+00:00 and 04:10+02:00 are one moment, so the
        // id decides; a string comparison would call the second one later.
        $this->map();
        $a = $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'greater id');
        $b = $this->card(9208, self::USER, '2026-09-11T04:10:00+02:00', 'smaller id, later-looking string');

        $this->assertSame([9234, 'greater id'], $this->winnerOf([$b, $a]));
        $this->assertSame('2026-09-11 02:10:00.000', $this->boardRow()['card_updated_at']);
    }

    public function test_a_card_on_a_second_configured_board_competes_on_the_same_rule(): void
    {
        // § 4.3: the join is `assigned_user_id` alone, so a card on any configured board can answer.
        $this->map();
        $this->configure(self::BASE, self::TOKEN, '14, 15');
        $this->board([
            14 => [1 => $this->page([$this->card(9208, self::USER, '2026-09-10T00:00:00+00:00', 'on 14')])],
            15 => [1 => $this->page([$this->card(120, self::USER, '2026-09-11T00:00:00+00:00', 'on 15', 15)])],
        ]);

        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([120, 15, 'on 15'], [(int) $this->boardRow()['card_id'], (int) $this->boardRow()['board_id'], $this->boardRow()['title']]);
        $this->assertCount(2, $this->requested, 'one request per single-page board');
    }

    public function test_an_archived_or_deleted_card_is_not_a_candidate(): void
    {
        $this->map();
        $live = $this->card(9208, self::USER, '2026-09-09T00:00:00+00:00', 'live');
        $archived = ['archived_at' => '2026-09-11T00:00:00+00:00'] + $this->card(9300, self::USER, '2026-09-11T00:00:00+00:00', 'archived');
        $deleted = ['deleted_at' => '2026-09-11T00:00:00+00:00'] + $this->card(9301, self::USER, '2026-09-11T00:00:00+00:00', 'deleted');

        $this->assertSame([9208, 'live'], $this->winnerOf([$archived, $live, $deleted]));
    }
}
