<?php

namespace Tests\Feature\Board;

use App\Board\LinkPass;
use App\Fleet\SeatTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AT-D4-13 — the migration pins every mapping that exists (card#12447, `docs/design/BOARD-TASK.md § 13.1`'s
 * migration row, D2 § 6.4's backfill): before card#12447 the seat→board-user command was the only writer of
 * `seats.board_user_id`, so every mapping the migration finds is an operator's PIN.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, as `TheCharacterMigrationKeepsEveryUniqueDrawTest` does and for its reason:
 * `RefreshDatabase` applied it to an EMPTY table, where the backfill has nothing to do. So each leg takes it
 * down, maps a seat the way the schema before this card did, and brings it up again. DDL implicitly commits on
 * MariaDB, so `tearDown()` removes this class's rows explicitly.
 *
 * THE RED is the migration with no backfill, and it is DRIVEN rather than argued: with the source left NULL the
 * same seat IS a link-pass candidate, and a poll on which it declares another user replaces the hand mapping.
 */
class AtD4_13TheMigrationPinsEveryMappingThatExistsTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'mig-12447';

    private const BASE = 'https://board.example.test/api/v3';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_28_000100_add_the_board_link_and_the_todo_tier.php');
    }

    protected function tearDown(): void
    {
        $installs = DB::table('installs')->where('install_id', self::INSTALL)->pluck('id')->all();
        $seats = DB::table('seats')->whereIn('install_ref', $installs)->pluck('id')->all();

        DB::table('seat_board_task')->whereIn('seat_ref', $seats)->delete();
        DB::table('seat_counters')->whereIn('seat_ref', $seats)->delete();
        DB::table('ingest_tokens')->whereIn('seat_ref', $seats)->delete();
        DB::table('seat_state')->whereIn('seat_ref', $seats)->delete();
        DB::table('seats')->whereIn('id', $seats)->delete();
        DB::table('installs')->whereIn('id', $installs)->delete();

        parent::tearDown();
    }

    private function seatRef(string $seatId): int
    {
        return (int) DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', self::INSTALL)->where('seats.seat_id', $seatId)->value('seats.id');
    }

    /** Down, the seats as the schema before card#12447 wrote them (`$mapped` ⇒ that board user), up. */
    private function migrateOver(array $mapped): void
    {
        foreach (array_keys($mapped) as $seatId) {
            SeatTokens::issue(self::INSTALL, $seatId, 'suite');
        }

        $migration = $this->migration();
        $migration->down();

        foreach ($mapped as $seatId => $user) {
            DB::table('seats')->where('id', $this->seatRef($seatId))->update(['board_user_id' => $user]);
        }

        $migration->up();
    }

    /** The seat now declares 15 from a current build, on this site's own base. */
    private function declaresFifteen(int $seatRef): void
    {
        DB::table('seat_state')->where('seat_ref', $seatRef)->update([
            'board_user_declared' => 15, 'board_fingerprint_declared' => LinkPass::fingerprint(self::BASE),
            'reporter_version' => '0.5.0',
        ]);
        Http::fake(['board.example.test/*' => Http::response(json_encode(['data' => [
            ['user_id' => 15, 'account_type' => 'human', 'disabled' => false],
        ]]), 200)]);
    }

    /** One link pass, both phases, in a transaction of its own as the poll's would be. */
    private function linkPass(): void
    {
        $plan = LinkPass::prepare([14], self::BASE, 'kbr_suite_token');
        DB::transaction(fn () => LinkPass::apply($plan));
    }

    public function test_every_existing_mapping_is_pinned_and_survives_a_different_declaration(): void
    {
        $this->migrateOver(['mapped' => 14, 'unmapped' => null]);

        $mapped = $this->seatRef('mapped');
        $this->assertSame('operator', DB::table('seats')->where('id', $mapped)->value('board_user_source'));
        $this->assertNull(DB::table('seats')->where('id', $this->seatRef('unmapped'))->value('board_user_source'));
        $this->assertSame(0, DB::table('seats')->whereNotNull('board_user_id')->whereNull('board_user_source')->count());

        $this->declaresFifteen($mapped);
        $this->linkPass();
        $this->assertSame(14, (int) DB::table('seats')->where('id', $mapped)->value('board_user_id'),
            'the link pass replaced an operator\'s hand mapping');
    }

    public function test_red_with_no_backfill_the_hand_mapping_is_the_passs_to_replace(): void
    {
        $this->migrateOver(['mapped' => 14]);
        $mapped = $this->seatRef('mapped');
        // The migration without its backfill: the source left NULL over a mapping that exists.
        DB::table('seats')->where('id', $mapped)->update(['board_user_source' => null]);

        $this->declaresFifteen($mapped);
        $this->linkPass();
        $this->assertSame(15, (int) DB::table('seats')->where('id', $mapped)->value('board_user_id'),
            'the RED did not fire: an unpinned mapping was left alone, so the assertion above discriminates nothing');
    }

    /** Control: on a store with no mapping at all nothing is backfilled, and the query is 0 for the trivial reason. */
    public function test_control_a_store_with_no_mapping_backfills_nothing(): void
    {
        $this->migrateOver(['unmapped' => null]);

        $this->assertSame(0, DB::table('seats')->whereNotNull('board_user_source')
            ->whereIn('id', [$this->seatRef('unmapped')])->count());
        $this->assertSame(0, DB::table('seats')->whereNotNull('board_user_id')->whereNull('board_user_source')->count());
    }
}
