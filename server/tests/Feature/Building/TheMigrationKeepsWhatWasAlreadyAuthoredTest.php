<?php

namespace Tests\Feature\Building;

use App\Building\Layouts;
use App\Building\Revisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\TestCase;

/**
 * ⛔ THE TWO THINGS card#9208's MIGRATION MUST NOT SILENTLY DROP, exercised against the migration
 * itself rather than against a description of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 *   1. **Every `floors` row that already existed gets a revision behind it.**
 *      `docs/design/FLEET-STATE.md § 6.11`: "the migration that adds `map_version` seeds one
 *      `room_map` revision 1 per `floors` row from the row's own `map`, `updated_by` and
 *      `updated_at`, so the invariant *no current row without a revision behind it* holds from
 *      the first migration onward." Without it the console's RESTORE has nothing to offer for
 *      exactly the rooms an operator has been drawing longest.
 *
 *   2. **A layout the operator had configured is seeded, not lost.** The document lived in
 *      `config/building.php` and this change retires that file; if it still declares floors when
 *      the migration runs, they become the store's revision 1 — translated from the pre-card#9292
 *      room shape, VALIDATED first, and the migration fails loudly rather than seeding a document
 *      the reader would refuse on every request afterwards.
 *
 * ⚠ WHY IT RUNS THE MIGRATION BY HAND. `RefreshDatabase` has already applied it to an EMPTY
 * database, where both seeds have nothing to do — which is the state every other test sees and is
 * exactly the state in which this migration's interesting half is unreachable. So each arm takes
 * the schema back down, puts the *before* state in place, and brings it up again.
 *
 * ⚠ AND WHY IT CLEANS UP EXPLICITLY. DDL implicitly commits on MySQL/MariaDB, so the transaction
 * `RefreshDatabase` wraps a test in cannot roll these arms back on the store production runs (the
 * `php-tests-mariadb` lane). The rows are therefore removed here rather than left to a rollback
 * that only happens on SQLite.
 */
class TheMigrationKeepsWhatWasAlreadyAuthoredTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_12_000100_create_the_authored_building_store.php');
    }

    protected function tearDown(): void
    {
        DB::table('authored_revisions')->delete();
        DB::table('building_layout')->delete();
        DB::table('floors')->delete();

        parent::tearDown();
    }

    public function test_a_map_authored_before_the_store_existed_gains_revision_one_from_its_own_row(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('floors')->insert([
            'install_id' => 'aimla',
            'map' => FloorMapFixture::valid(12),
            'updated_by' => 'someone-else@example.com',
            'created_at' => '2026-09-01 10:00:00.000',
            'updated_at' => '2026-09-02 11:30:00.000',
        ]);

        $migration->up();

        $revision = Revisions::get(Revisions::ROOM_MAP, 'aimla', 1);

        $this->assertNotNull($revision, 'the pre-existing map has no revision behind it');
        $this->assertSame(FloorMapFixture::valid(12), $revision->document);

        // ⛔ FROM THE ROW'S OWN VALUES, not from the migration's clock or from the operator running
        // it: the revision records what actually happened, and a *blame* that named whoever
        // deployed would be worse than no blame at all.
        $this->assertSame('someone-else@example.com', $revision->authored_by);
        $this->assertStringStartsWith('2026-09-02 11:30:00', (string) $revision->authored_at);

        // And the current row points at it — § 6.4: "`map_version` = authored_revisions.revision
        // of the row this IS".
        $this->assertSame(1, (int) DB::table('floors')->where('install_id', 'aimla')->value('map_version'));
    }

    public function test_a_configured_layout_is_seeded_as_revision_one_in_the_room_shape_the_reader_now_takes(): void
    {
        // The PRE-card#9292 document, bare form strings and all — which is what a deployment's
        // `config/building.php` would have held, and what the reader now refuses by name.
        config(['building.floors' => [
            ['label' => 'the solos', 'rooms' => ['zeta' => 'office', 'sola' => 'office']],
        ]]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertSame(1, Layouts::version());
        $this->assertSame('config/building.php', Layouts::current()->updated_by);

        // Translated to the record, and it PARSES — the seed is validated before it is written, so
        // what lands in the store is a document the reader accepts rather than one it will refuse
        // on every request from then on.
        $layout = Layouts::layout();

        $this->assertSame('sola', $layout->floors[0]['floor']);
        $this->assertSame('the solos', $layout->floors[0]['label']);
        $this->assertSame(
            [['install' => 'sola', 'form' => 'office'], ['install' => 'zeta', 'form' => 'office']],
            $layout->floors[0]['rooms'],
        );

        // The seed is a revision like any other, so the layout it replaced is retrievable and the
        // history starts where the document did.
        $this->assertSame(1, Revisions::history(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT)->count());
    }

    public function test_a_configured_layout_the_reader_refuses_fails_the_migration_loudly(): void
    {
        // ⛔ FAIL LOUD RATHER THAN SEED A DOCUMENT THE READER WILL REFUSE PER REQUEST. The deploy
        // stops with the reason in front of the person running it; the alternative puts the same
        // refusal on every page load of the console and the floor, with nothing saying where it
        // came from.
        config(['building.floors' => [['rooms' => ['sola' => 'broom-cupboard']]]]);

        $migration = $this->migration();
        $migration->down();

        try {
            $migration->up();
            $this->fail('a layout this reader refuses was seeded into the store');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be seeded', $e->getMessage());
            $this->assertStringContainsString('broom-cupboard', $e->getMessage());
        }

        // ⛔ AND THE SCHEMA DID NOT MOVE, which is what makes *edit the document and migrate
        // again* a real instruction. A migration that had created its tables before refusing
        // would be neither applied nor recorded: the next `migrate` re-enters `up()` and dies on
        // *table already exists*, and the operator's only way out of a typo is dropping tables by
        // hand. The document is therefore validated before any DDL.
        $this->assertFalse(Schema::hasTable('authored_revisions'));
        $this->assertFalse(Schema::hasTable('building_layout'));
        $this->assertFalse(Schema::hasColumn('floors', 'map_version'));

        // THE OTHER HALF OF THE SAME PROPERTY: the operator fixes the document and the SAME
        // migration object runs clean — no manual repair in between.
        config(['building.floors' => [['rooms' => ['sola' => 'office']]]]);

        $migration->up();

        $this->assertSame(1, Layouts::version());
    }

    public function test_an_empty_configured_layout_seeds_nothing_because_nobody_ever_pressed_save(): void
    {
        // THE CONTROL for both arms above, and § 8.7's own rule: "`layout_version` is `0` with an
        // empty `floors` when no layout was ever saved". A seeded revision 1 carrying an empty
        // document would claim an operator composed today's building, and *undo* would then offer
        // a revision nobody made.
        config(['building.floors' => []]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertSame(0, Layouts::version());
        $this->assertSame(0, DB::table('authored_revisions')->count());
    }
}
