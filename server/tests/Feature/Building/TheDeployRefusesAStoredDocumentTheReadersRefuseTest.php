<?php

namespace Tests\Feature\Building;

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Building\Revisions;
use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\TestCase;

/**
 * ⛔ card#9322's DEPLOY-TIME CHECK, exercised against the migration itself: a CURRENT authored
 * document the object-mode readers refuse stops `php artisan migrate`, which `bin/deploy.sh` runs
 * before `php artisan up`, so the refusal reaches the operator before the new readers serve it.
 *
 * ⚠ WHY THE ROWS ARE INSERTED BY HAND. The console refuses both documents below at its save since
 * card#9322, so no write path of this release can put them in the store. They stand for a store
 * written by the previous release, whose associative decode accepted them.
 *
 * ⚠ AND WHY IT RUNS `up()` BY HAND. `RefreshDatabase` has already applied the migration to an empty
 * store, where it has nothing to read. It writes nothing and adds no schema, so the transaction
 * `RefreshDatabase` wraps each test in rolls every row here back.
 */
class TheDeployRefusesAStoredDocumentTheReadersRefuseTest extends TestCase
{
    use RefreshDatabase;

    private const BY = 'someone@example.com';

    private const AT = '2026-09-13 10:00:00.000';

    private function migration(): object
    {
        return require database_path('migrations/2026_09_14_000000_refuse_a_current_authored_document_the_readers_refuse.php');
    }

    public function test_a_stored_floors_object_and_a_stored_layers_object_are_refused_each_by_name(): void
    {
        $layout = '{"floors": {}}';
        $this->storeLayout($layout, 3);

        $map = FloorMapFixture::decoded();
        $map['layers'] = new \stdClass;
        $map = FloorMapFixture::encode($map);
        $this->storeRoomMap('aimla', $map, 2);
        $this->storeRoomMap('sola', FloorMapFixture::valid(), 1);

        $before = $this->theStore();

        $refusal = null;

        try {
            $this->migration()->up();
        } catch (RuntimeException $e) {
            $refusal = $e;
        }

        $this->assertNotNull($refusal, 'the migration passed a store holding two documents the readers refuse');
        $message = $refusal->getMessage();

        // Each document by kind, subject and revision, with the reader's own sentence beside it.
        $this->assertStringContainsString(
            'kind `building_layout`, subject `` (the building layout), revision 3: '.$this->layoutRefusal($layout),
            $message,
        );
        $this->assertStringContainsString(
            'kind `room_map`, subject `aimla`, revision 2: '.$this->mapRefusal($map),
            $message,
        );
        // The readable room is not named.
        $this->assertStringNotContainsString('`sola`', $message);

        // ⛔ AND IT CHANGED NOTHING: the refusal is the whole of what it does, so the operator's fix is
        // made in the console and the migration runs again.
        $this->assertSame($before, $this->theStore());
    }

    public function test_a_store_the_readers_accept_passes(): void
    {
        $this->storeLayout('{"floors": [{"rooms": {"aimla": {"form": "open"}}}]}', 1);
        $this->storeRoomMap('aimla', FloorMapFixture::valid(), 1);

        $this->assertPasses();
    }

    public function test_the_empty_building_spelled_as_a_list_passes(): void
    {
        // The control for the refusal above: `"floors": []` is § 4.6's empty building.
        $this->storeLayout('{"floors": []}', 1);

        $this->assertPasses();
    }

    public function test_a_superseded_revision_the_readers_refuse_does_not_stop_the_deploy(): void
    {
        // ⛔ THE LOG IS APPEND-ONLY (§ 6.11) AND A RESTORE RE-VALIDATES. A superseded revision cannot
        // be repaired, so refusing it would refuse every later deploy; and it cannot become current
        // either, because `Layouts::restore()` and `Floors::restore()` read it through the same
        // reader and the console answers that refusal on the revisions page.
        $this->storeRevision(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, 1, '{"floors": {}}');
        $this->storeLayout('{"floors": []}', 2);

        $map = FloorMapFixture::decoded();
        $map['layers'] = new \stdClass;
        $this->storeRevision(Revisions::ROOM_MAP, 'aimla', 1, FloorMapFixture::encode($map));
        $this->storeRoomMap('aimla', FloorMapFixture::valid(), 2);

        $this->assertPasses();
    }

    /** `up()` returns, and the store is as it was: the check writes nothing. */
    private function assertPasses(): void
    {
        $before = $this->theStore();

        $this->migration()->up();

        $this->assertSame($before, $this->theStore());
    }

    private function storeLayout(string $document, int $revision): void
    {
        $this->storeRevision(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $revision, $document);

        DB::table('building_layout')->insert([
            'id' => 1,
            'document' => $document,
            'layout_version' => $revision,
            'updated_by' => self::BY,
            'updated_at' => self::AT,
        ]);
    }

    private function storeRoomMap(string $installId, string $document, int $revision): void
    {
        $this->storeRevision(Revisions::ROOM_MAP, $installId, $revision, $document);

        DB::table('floors')->insert([
            'install_id' => $installId,
            'map' => $document,
            'map_version' => $revision,
            'updated_by' => self::BY,
            'created_at' => self::AT,
            'updated_at' => self::AT,
        ]);
    }

    private function storeRevision(string $kind, string $subject, int $revision, ?string $document): void
    {
        DB::table('authored_revisions')->insert([
            'kind' => $kind,
            'subject' => $subject,
            'revision' => $revision,
            'document' => $document,
            'restored_from' => null,
            'authored_by' => self::BY,
            'authored_at' => self::AT,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function theStore(): array
    {
        $rows = fn (string $table, string $order) => DB::table($table)->orderBy($order)->get()->map(fn (object $row) => (array) $row)->all();

        return [
            'authored_revisions' => $rows('authored_revisions', 'id'),
            'building_layout' => $rows('building_layout', 'id'),
            'floors' => $rows('floors', 'install_id'),
        ];
    }

    private function layoutRefusal(string $document): string
    {
        try {
            BuildingLayout::fromJson($document);
        } catch (InvalidBuildingLayout $e) {
            return $e->getMessage();
        }

        $this->fail('the reader accepts the layout this test stores as refused');
    }

    private function mapRefusal(string $document): string
    {
        try {
            FloorMap::parse($document);
        } catch (InvalidFloorMap $e) {
            return $e->getMessage();
        }

        $this->fail('the reader accepts the room map this test stores as refused');
    }
}
