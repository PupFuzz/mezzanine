<?php

namespace Tests\Feature\Board;

use App\Board\BoardPoll;
use App\Board\LinkPass;

/**
 * AT-D4-11 — the site and the board are checked before the fleet, a member the board types `human` links like
 * any other, and a failed members read maps nothing (card#12447, `docs/design/BOARD-TASK.md § 4.4` step 4, § 6.6).
 *
 * The REDs this holds: a raw-string base comparison (the case-and-slash seat would be refused), no
 * fingerprint leg (another kanban's id would map), an `account_type` gate (the `human`-typed member — the type
 * this project's own agent login carries on the measured board — would be refused; seat ruling, card#12447), a
 * mapping on a failed read, and an old mapping kept through a refused change.
 */
class AtD4_11TheSiteAndTheBoardAreCheckedFirstTest extends BoardTaskTestCase
{
    /** @return array{string, int} */
    private function seat(string $seatId): array
    {
        return $this->issueToken(self::INSTALL, $seatId);
    }

    /** @return array<string, int> seat label ⇒ seat ref, each declaring as its label says */
    private function fixture(): array
    {
        $this->board([self::BOARD => [1 => $this->page([])]]);
        $seats = [];
        $plan = [
            'site' => [21, 'https://Other.example/api/v3/'],
            'unknown' => [22, self::BASE],
            'disabled' => [23, self::BASE],
            'human' => [24, self::BASE],
            'caseslash' => [25, 'https://BOARD.example.test/api/v3/'],
        ];
        foreach ($plan as $label => [$user, $base]) {
            [$tok, $ref] = $this->seat('impl-'.$label);
            $this->declare($user, $base, $tok, 'impl-'.$label);
            $seats[$label] = $ref;
        }

        return $seats;
    }

    private function members(bool $clean): array
    {
        return ['data' => [
            $this->member(21), $this->member(23, disabled: ! $clean), $this->member(25),
            $this->member(24, 'human'),
            ...($clean ? [$this->member(22)] : []),
        ]];
    }

    public function test_each_leg_refuses_by_its_own_counter_a_human_typed_member_links_and_the_normalized_base_matches(): void
    {
        $seats = $this->fixture();
        $this->members = [self::BOARD => $this->members(false)];
        $this->assertSame(0, $this->poll()[0]);

        $want = ['site' => LinkPass::SITE_MISMATCH, 'unknown' => LinkPass::UNKNOWN_USER,
            'disabled' => LinkPass::UNKNOWN_USER];
        foreach ($want as $label => $counter) {
            $this->assertSame(1, $this->seatCounter($counter, $seats[$label]), $label.' was not refused by '.$counter);
            $this->assertSame([null, null], $this->mapping($seats[$label]), $label.' was written');
        }
        $this->assertSame([24, 'declared'], $this->mapping($seats['human']),
            'a member the board types `human` was refused: the pass gates on account_type');
        $this->assertSame([25, 'declared'], $this->mapping($seats['caseslash']),
            'a base differing only by case and a trailing slash was refused: the raw strings were compared');
    }

    public function test_a_degraded_members_read_maps_nothing_and_the_card_read_still_runs(): void
    {
        $this->fixture();

        foreach (['500' => [500, '{"error":"boom"}'], 'meta' => ['data' => [$this->member(25)], 'meta' => ['last_page' => 2]]] as $case => $answer) {
            $this->members = [self::BOARD => $answer];
            [$ok, $failed] = $this->pollCounters();
            $linkFailed = $this->globalCounter(LinkPass::FAILED);
            $this->assertSame(0, $this->poll()[0], $case);
            $this->assertSame($linkFailed + 1, $this->globalCounter(LinkPass::FAILED), $case.': board_link_failed did not count');
            $this->assertSame([$ok + 1, $failed], $this->pollCounters(), $case.': the card read did not run, or the poll failed');
            $this->assertSame(0, (int) \DB::table('seats')->whereNotNull('board_user_id')->count(), $case.': a seat was mapped on a failed read');
        }
    }

    public function test_a_declared_mapping_whose_new_declaration_is_refused_is_cleared(): void
    {
        $this->members = [self::BOARD => ['data' => [$this->member(14), $this->member(23, disabled: true)]]];
        $this->board([self::BOARD => [1 => $this->page([$this->card(9234, 14, '2026-09-11T02:10:00+00:00')])]]);
        $this->declare(14);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping());
        $this->assertNotNull($this->boardRow());

        $this->declare(23);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame(1, $this->seatCounter(LinkPass::UNKNOWN_USER));
        $this->assertSame(1, $this->seatCounter(LinkPass::CLEARED));
        $this->assertSame([null, null], $this->mapping(), 'the desk keeps 14\'s mapping for a seat that says it is someone else');
        $this->assertNull($this->boardRow(), 'the cleared mapping left its row');
    }

    /** Control: every refused seat with a clean read listing its user as an enabled agent of this base maps. */
    public function test_control_with_a_clean_read_every_seat_on_this_base_maps(): void
    {
        $seats = $this->fixture();
        $this->members = [self::BOARD => $this->members(true)];
        $this->assertSame(0, $this->poll()[0]);

        foreach (['unknown' => 22, 'disabled' => 23, 'human' => 24, 'caseslash' => 25] as $label => $user) {
            $this->assertSame([$user, 'declared'], $this->mapping($seats[$label]), $label.' did not map on a clean read');
        }
        // The site leg is the one refusal a members read cannot answer, and it still refuses.
        $this->assertSame([null, null], $this->mapping($seats['site']));
        $this->assertSame(0, $this->globalCounter(BoardPoll::FAILED));
    }
}
