<?php

namespace Tests\Feature\Read;

use App\Building\Building;
use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Floor\FloorInventory;
use App\Models\User;
use App\Read\SeatIdentity;
use App\Read\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Support\Rooms;
use Tests\TestCase;

/**
 * T7 — `docs/design/plan-13117-seat-identity.md § 4.4`, card#13117: a seat in no room rides the snapshot
 * as a member of the virtual install `unassigned`, under `api_version` 1, and both composers draw it on the
 * Unassigned floor (S4; until S4 by the implicit floor every unplaced install got, which keyed it `unassigned` too).
 * The browser composer is held to the same answer by `tests/fixtures/building/compose-cases.json`'s
 * *virtual room* cases, which `TheDirectoryListsTheComposedFloorsTest` walks; this class holds the
 * server's and the surfaces around it: the room map the reserved key answers, and that no console
 * surface offers it as a room.
 */
class AnUnassignedSeatRidesTheSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const SEAT = 'seat-w';

    private function issue(): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue(self::SEAT, 'suite'));
    }

    /** @return list<string> the floor keys the server's composer draws over the snapshot's installs */
    private function composedFloors(array $snapshot): array
    {
        return array_column(Building::compose(Layouts::layout(), array_column($snapshot['installs'], 'install_id')), 'floor');
    }

    public function test_a_roomless_seat_is_the_unassigned_group_on_the_unassigned_floor(): void
    {
        $this->issue();

        $snapshot = Snapshot::build(0);

        $this->assertSame(1, $snapshot['api_version']);
        $this->assertSame([SeatIdentity::UNASSIGNED], array_column($snapshot['installs'], 'install_id'));
        $this->assertSame([self::SEAT], array_column($snapshot['installs'][0]['seats'], 'seat_id'));
        $this->assertSame(SeatIdentity::UNASSIGNED, $snapshot['installs'][0]['seats'][0]['install_id']);

        $this->assertSame([SeatIdentity::UNASSIGNED], $this->composedFloors($snapshot));
    }

    public function test_once_placed_neither_the_group_nor_its_floor_is_drawn(): void
    {
        $this->issue();
        Rooms::place(self::SEAT, 'aimla');
        Layouts::save('{"floors": [{"name": "aimla", "rooms": {"aimla": {"form": "open"}}}]}', 'suite', Layouts::version());

        $snapshot = Snapshot::build(0);

        $this->assertSame(['aimla'], array_column($snapshot['installs'], 'install_id'));
        $this->assertSame(['aimla'], $this->composedFloors($snapshot));
    }

    public function test_the_reserved_key_answers_the_shipped_default_map(): void
    {
        $this->issue();

        $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->getJson('/api/building/rooms/'.SeatIdentity::UNASSIGNED.'/map')
            ->assertOk()
            ->assertJsonPath('source', 'default');
    }

    /**
     * The reserved key is refused at the layout's write and at every load: a layout placing `unassigned`
     * places a room the console can never create, and would draw the roomless seats on an authored floor.
     */
    public function test_a_layout_placing_the_reserved_key_is_refused_at_the_write_and_at_load(): void
    {
        $document = '{"floors": [{"name": "aimla","rooms": {"aimla": {"form": "open"}, "unassigned": {"form": "open"}}}]}';

        try {
            Layouts::save($document, 'suite', Layouts::version());
            $this->fail('the write stored a layout placing the reserved key');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('keyed `unassigned`, which is reserved', $e->getMessage());
        }

        $this->expectException(InvalidBuildingLayout::class);
        BuildingLayout::fromJson($document);
    }

    /**
     * No console surface offers the reserved key as a room: the floors module's rows and the write-side
     * guard of its create form both leave it out, so a map cannot be authored for the virtual install —
     * the plant this was seen red on is a `renderedInstalls()` that carries the snapshot's groups whole.
     */
    public function test_the_room_map_form_refuses_the_reserved_key(): void
    {
        $this->issue();

        $this->assertNotContains(SeatIdentity::UNASSIGNED, FloorInventory::renderedInstalls());
        $this->assertNotContains(SeatIdentity::UNASSIGNED, array_column(FloorInventory::rows(), 'install_id'));

        $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->post(route('admin.floors.store'), ['install_id' => SeatIdentity::UNASSIGNED, 'map' => FloorMapFixture::valid()])
            ->assertSessionHasErrors('install_id');

        $this->assertDatabaseMissing('floors', ['install_id' => SeatIdentity::UNASSIGNED]);
    }
}
