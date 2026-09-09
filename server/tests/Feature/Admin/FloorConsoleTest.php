<?php

namespace Tests\Feature\Admin;

use App\Admin\ConsoleModules;
use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;
use App\Models\User;
use App\Sweep\Purge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The console's FLOORS module — card#9085, the (a) half of the operator's 2026-09-08 ruling:
 * *"add/remove floors; author the MAP — how many desk slots, where they sit, the room's shape"*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE LINE THIS FILE EXISTS TO HOLD IS THE (b) LINE, AND IT IS ASSERTED AS AN ABSENCE.
 * `docs/design/FLOOR.md § 3.2` assigns a seat to a desk by a pure function of the rendered seat
 * set, "so two browsers, two reloads and two server restarts agree without a stored position and
 * without a server field". Pinning a named seat to a chosen desk is `card#9071`'s undecided
 * ruling. A module that stored a desk position would retire that invariant by accident, so
 * `test_the_floors_schema_stores_no_seat_to_desk_binding` asserts the SCHEMA has no column that
 * could carry one — the state the next "while I'm here" edit would add.
 *
 * ⚠ THE SEAT COUNT ON THIS PAGE IS `App\Read\Snapshot::seats()`, THE READ THE FLOOR ITSELF USES.
 * A second query would be free to disagree with the floor about which seats exist, and the first
 * thing the two would disagree about is `§ 4.10`'s read filter — which is why
 * `test_the_seat_count_is_the_snapshot_read_and_honours_the_retirement_filter` retires a seat
 * past the window and asserts the console stops counting it.
 */
class FloorConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'aimla';

    private ?User $operator = null;

    /**
     * ONE operator per test, memoised: several arms act as the operator twice, and a second
     * `create()` on the same address is a unique-key violation rather than a finding.
     */
    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create([
            'email' => 'ops@example.com',
        ]);
    }

    /**
     * A provisioned seat, in the shape `App\Console\Commands\IssueIngestToken` writes it —
     * `docs/design/FLOOR.md § 3.4`'s provisioned-but-silent desk: `offline` / `no_data_yet`.
     *
     * ⚠ THE ROWS ARE WRITTEN DIRECTLY RATHER THAN BY RUNNING THE ISSUE COMMAND, and the reason is
     * canon #20 rather than speed: that command mints and PRINTS a real bearer token, and a test
     * that called it would put a credential in CI output for no assertion's benefit. What this
     * borrows from it is the row shape, which is the part the reads under test actually see.
     */
    private function provisionSeat(string $seatId, string $installId = self::INSTALL, ?string $retiredAt = null): int
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $installRef = DB::table('installs')->where('install_id', $installId)->value('id')
            ?? DB::table('installs')->insertGetId(['install_id' => $installId, 'created_at' => $now]);

        $seatRef = DB::table('seats')->insertGetId([
            'install_ref' => $installRef,
            'seat_id' => $seatId,
            'created_at' => $now,
            'retired_at' => $retiredAt,
            'retired_by' => $retiredAt === null ? null : 'ops@example.com',
            'retired_reason' => $retiredAt === null ? null : 'host decommissioned',
        ]);

        DB::table('seat_state')->insert([
            'seat_ref' => $seatRef,
            'render_state' => $retiredAt === null ? 'offline' : 'retired',
            'link_state' => 'offline',
            'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet',
            'state_computed_at' => $now,
            'updated_at' => $now,
        ]);

        return $seatRef;
    }

    private function author(string $installId, string $map, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->operator())
            ->post(route('admin.floors.store'), ['install_id' => $installId, 'map' => $map]);
    }

    // ── the module is an ENTRY in the one module list, not an edit to the shell ──────────────

    public function test_the_floors_module_is_an_entry_in_the_console_module_list(): void
    {
        $keys = array_column(ConsoleModules::all(), 'key');

        $this->assertContains('floors', $keys);

        // And the shell's nav is generated from that list, so being an entry is being linked.
        $this->actingAs($this->operator())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee(route('admin.floors.index'));
    }

    // ── authoring a map ─────────────────────────────────────────────────────────────────────

    public function test_a_map_is_authored_for_an_install_the_snapshot_renders(): void
    {
        $this->provisionSeat('aimla-pm');

        $this->author(self::INSTALL, FloorMapFixture::valid(12))
            ->assertRedirect(route('admin.floors.index'))
            ->assertSessionHasNoErrors();

        $row = DB::table('floors')->where('install_id', self::INSTALL)->first();

        $this->assertNotNull($row);
        $this->assertSame('ops@example.com', $row->updated_by);

        // Stored VERBATIM: the bytes an operator authored are the bytes the store holds, so the
        // desk slots the renderer reads are the ones Tiled exported and not a re-encoding of them.
        $this->assertSame(FloorMapFixture::valid(12), $row->map);
    }

    public function test_authoring_is_refused_for_an_install_the_snapshot_does_not_render(): void
    {
        $this->provisionSeat('aimla-pm');

        $this->author('no-such-install', FloorMapFixture::valid())
            ->assertSessionHasErrors('install_id');

        $this->assertSame(0, DB::table('floors')->count());
    }

    public function test_authoring_twice_replaces_the_map_rather_than_adding_a_second_floor(): void
    {
        $this->provisionSeat('aimla-pm');

        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();
        $this->author(self::INSTALL, FloorMapFixture::valid(20))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('floors')->where('install_id', self::INSTALL)->count());
        $this->assertSame(20, FloorMap::parse(DB::table('floors')->value('map'))->slots);
    }

    public function test_the_slot_count_is_derived_from_the_map_and_never_stored(): void
    {
        // The derivation, over two maps that differ in nothing but their desk objects.
        $this->assertSame(12, FloorMap::parse(FloorMapFixture::valid(12))->slots);
        $this->assertSame(7, FloorMap::parse(FloorMapFixture::valid(7))->slots);

        // And no column carries it, so the number on the page cannot drift from the map it
        // describes: there is one home for `S` and it is the map itself.
        $this->assertNotContains('slot_count', Schema::getColumnListing('floors'));
        $this->assertNotContains('slots', Schema::getColumnListing('floors'));

        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(7))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('7 desk slots');
    }

    // ── the refusals, each one a clause of docs/design/FLOOR.md § 10.1 / § 10.3 ─────────────

    /**
     * @return array<string, array{0: string, 1: string}> name => [document, the phrase the
     *                                                    refusal must name]
     */
    public static function refusedMaps(): array
    {
        return [
            'base64 tile layer' => [FloorMapFixture::base64Layer(), 'base64'],
            'base64 layer inside a group' => [FloorMapFixture::base64LayerInsideAGroup(), 'base64'],
            'compressed tile layer' => [FloorMapFixture::compressedLayer(), 'zlib'],
            'embedded tileset image' => [FloorMapFixture::embeddedTilesetImage(), 'data:'],
            'no desks layer' => [FloorMapFixture::noDesksLayer(), 'desks'],
            'two desks layers' => [FloorMapFixture::twoDesksLayers(), 'desks'],
            'empty desks layer' => [FloorMapFixture::emptyDesksLayer(), 'desks'],
            'infinite map' => [FloorMapFixture::infinite(), 'infinite'],
            'the .tmx spelling' => [FloorMapFixture::tmx(), 'JSON'],
            'not a map at all' => ['{"type":"tileset"}', 'map'],
            'not json' => ['not json at all', 'JSON'],
        ];
    }

    /**
     * ⛔ THE CONTROL IS `test_a_map_is_authored_for_an_install_the_snapshot_renders` ABOVE: every
     * document below is the valid map with ONE property changed, so a refusal here is a refusal of
     * that property and not of a document the validator never liked.
     */
    #[DataProvider('refusedMaps')]
    public function test_a_map_that_breaks_a_clause_is_refused_and_nothing_is_stored(string $document, string $phrase): void
    {
        $this->provisionSeat('aimla-pm');

        $this->author(self::INSTALL, $document)->assertSessionHasErrors('map');

        $this->assertSame(0, DB::table('floors')->count(), 'a refused map was stored anyway');

        // The refusal NAMES what it refused. A generic "invalid map" leaves an operator with a
        // Tiled export and no idea which setting to change.
        try {
            FloorMap::parse($document);
            $this->fail('the parser accepted a document the route refused');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsStringIgnoringCase($phrase, $e->getMessage());
        }
    }

    public function test_a_map_over_the_size_cap_is_refused_by_its_measured_size(): void
    {
        $map = FloorMapFixture::decoded();
        $map['layers'][0]['data'] = array_fill(0, FloorMap::MAX_BYTES, 1);

        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::encode($map))->assertSessionHasErrors('map');

        $this->assertSame(0, DB::table('floors')->count());
    }

    // ── what the module is FOR: the floor list, and the two things it surfaces ───────────────

    public function test_the_seat_count_is_the_snapshot_read_and_honours_the_retirement_filter(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->provisionSeat('aimla-impl-1');
        // Retired longer ago than the render window: § 4.10 stops selecting it, so the floor no
        // longer draws its desk and this page must not count it either.
        $this->provisionSeat(
            'aimla-gone',
            retiredAt: now()->copy()->subDays(Purge::RETENTION_DAYS + 1)->format('Y-m-d H:i:s.v'),
        );

        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('2 seats')
            ->assertDontSee('3 seats');
    }

    public function test_the_console_says_when_a_map_declares_fewer_slots_than_the_floor_has_seats(): void
    {
        foreach (['aimla-pm', 'aimla-impl-1', 'aimla-impl-2'] as $seat) {
            $this->provisionSeat($seat);
        }

        $this->author(self::INSTALL, FloorMapFixture::valid(1))->assertSessionHasNoErrors();

        // § 3.2's overflow rule: the seats with no slot are rendered in an overflow row and the
        // floor shows *floor map is short N desks*. The operator should see that HERE, on the
        // page where the map can be fixed, rather than on the floor where it is only a symptom.
        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('short 2 desks');
    }

    public function test_an_install_with_no_map_is_listed_as_having_none(): void
    {
        $this->provisionSeat('aimla-pm');

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee(self::INSTALL)
            ->assertSee('no map authored');
    }

    public function test_a_map_whose_install_the_snapshot_no_longer_renders_is_surfaced_not_hidden(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid())->assertSessionHasNoErrors();

        // Every seat of the install falls out of the render window. The authored map is now a
        // map for a floor nothing draws — surfaced as a defect, never silently ignored, which is
        // the same posture card#9071 records for a pin naming a seat that is not there.
        DB::table('seats')->update([
            'retired_at' => now()->copy()->subDays(Purge::RETENTION_DAYS + 1)->format('Y-m-d H:i:s.v'),
        ]);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee(self::INSTALL)
            ->assertSee('no seat on this floor renders');
    }

    /**
     * ⚠ A STORED MAP THIS PARSER REFUSES IS A REACHABLE STATE, AND THE PAGE SAYS SO RATHER THAN
     * DYING. The console validates at the write, so the only way in is a writer that is not the
     * console — a later TIGHTENING of `App\Floor\FloorMap`'s clauses over rows already stored, or
     * a hand-edited row. The row is therefore written directly here, which is exactly the shape
     * the state arrives in.
     *
     * What is asserted is that the whole module does not go down with one bad row, and that the
     * row is NAMED rather than rendered as `0 desk slots` — a floor with no desks is a different
     * claim, and a false one.
     */
    public function test_a_stored_map_the_parser_refuses_is_named_and_does_not_take_the_page_down(): void
    {
        $this->provisionSeat('aimla-pm');

        $now = now()->format('Y-m-d H:i:s.v');

        DB::table('floors')->insert([
            'install_id' => self::INSTALL,
            'map' => FloorMapFixture::base64Layer(),
            'updated_by' => 'ops@example.com',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('this map can no longer be read')
            ->assertSee('base64')
            ->assertDontSee('desk slots');
    }

    // ── removing a floor ────────────────────────────────────────────────────────────────────

    public function test_removing_a_floor_removes_the_authored_map_and_touches_nothing_else(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid())->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->post(route('admin.floors.remove', self::INSTALL))
            ->assertRedirect(route('admin.floors.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('floors')->count());

        // The install and its seats are the fleet's, not the console's: removing an authored map
        // removes an authored artifact and no fleet state at all.
        $this->assertSame(1, DB::table('installs')->where('install_id', self::INSTALL)->count());
        $this->assertSame(1, DB::table('seats')->whereNull('retired_at')->count());
    }

    public function test_removing_a_floor_that_has_no_map_is_a_named_refusal_not_a_silent_ok(): void
    {
        $this->provisionSeat('aimla-pm');

        $this->actingAs($this->operator())
            ->post(route('admin.floors.remove', self::INSTALL))
            ->assertRedirect(route('admin.floors.index'))
            ->assertSessionHasErrors('floor');
    }

    // ── ⛔ the (b) line, asserted as an absence ──────────────────────────────────────────────

    public function test_the_floors_schema_stores_no_seat_to_desk_binding(): void
    {
        $columns = Schema::getColumnListing('floors');

        // The control: the table exists and carries the two things it IS for.
        $this->assertContains('install_id', $columns);
        $this->assertContains('map', $columns);

        foreach ($columns as $column) {
            foreach (['seat', 'desk', 'slot', 'pin', 'position'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $column,
                    "`floors.$column` looks like a stored desk position — that is card#9071's "
                    .'undecided (b) ruling, and docs/design/FLOOR.md § 3.2 derives it',
                );
            }
        }
    }

    public function test_the_floors_module_registers_no_route_that_could_bind_a_seat_to_a_desk(): void
    {
        $floors = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/floors'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertNotEmpty($floors, 'the floors module registered no routes at all');

        foreach ($floors as $signature) {
            $this->assertStringNotContainsString('seat', $signature);
            $this->assertStringNotContainsString('desk', $signature);
        }
    }
}
