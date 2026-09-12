<?php

namespace Tests\Feature\Admin;

use App\Admin\ConsoleModules;
use App\Building\Layouts;
use App\Floor\FloorInventory;
use App\Floor\FloorMap;
use App\Floor\Floors;
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

    /**
     * ⭐ THE LAYOUT NOW COMES FROM THE STORE, NOT FROM A CONFIG FILE (card#9208's reversal). These
     * arms used to set `config(['building.floors' => …])`; `config/building.php` is gone, and the
     * document reaches the reader through the write path an operator actually uses — which is the
     * seam worth exercising anyway.
     *
     * @param  list<array<string, mixed>>  $floors
     */
    private function composeTheBuilding(array $floors): void
    {
        Layouts::save((string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT), 'ops@example.com');
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

            // ⭐ § 10.3's TABLE, AS IT STANDS SINCE card#9208's REVERSAL AND card#9292 — the
            // section names THIS suite as where "each refusal goes red against the same map with
            // one property changed", so a row of that table with no case here is a rule the
            // console claims and nobody has watched refuse.
            'a grid with no tile width' => [FloorMapFixture::noTileWidth(), 'tilewidth'],
            'a projection the floor does not draw' => [FloorMapFixture::isometric(), 'isometric'],
            'a desk slot past the grid' => [FloorMapFixture::deskOutsideTheGrid(), 'wholly inside'],
            'a desk slot carrying a property' => [FloorMapFixture::deskWithProperties(), 'properties'],
            'a tileset the repository does not ship' => [FloorMapFixture::unshippedTileset(), 'does not ship'],
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
            ->assertSee('no seat in this room renders');
    }

    // ── the BUILDING LAYOUT, card#9267 — which floor each room is on ─────────────────────────

    /**
     * ⭐ THE OPERATOR'S OWN CASE, ON THE SURFACE THEY MANAGE THE BUILDING FROM. Two solo installs
     * composed onto one floor: `docs/design/FLOOR.md § 4.6`'s layout is a deploy-time document
     * and this page is where its effect is legible — a console that still said "a floor is an
     * install" would be answering the operator's first question with the sentence the ruling
     * made false.
     */
    public function test_two_rooms_composed_onto_one_floor_are_shown_on_that_floor(): void
    {
        $this->provisionSeat('sola-solo', 'sola');
        $this->provisionSeat('zeta-solo', 'zeta');

        $this->composeTheBuilding([['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]);

        $rows = collect(FloorInventory::rows())->keyBy('install_id');

        $this->assertSame('sola', $rows['sola']['floor']);
        $this->assertSame('sola', $rows['zeta']['floor']);
        $this->assertSame('office', $rows['zeta']['form']);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('operator-composed set of rooms', false)
            // The floors page points at the module that owns the composition, which since the
            // reversal is a page in this console rather than a file in the deploy.
            ->assertSee(route('admin.layout.edit'), false);
    }

    /**
     * § 4.6: an install the layout does not place "is drawn on a floor of its own, alone, in the
     * `open` form" — so provisioning one needs no deploy to be visible, and the console says
     * where it sits rather than leaving the column blank.
     */
    public function test_an_install_the_layout_does_not_place_is_shown_on_a_floor_of_its_own(): void
    {
        $this->provisionSeat('aimla-pm');

        // Nothing composed at all — the store holds no layout, which § 8.7 calls today's
        // building: one floor per install. It is the state a deployment starts in, so it is
        // asserted by NOT writing one rather than by writing an empty document.

        $rows = collect(FloorInventory::rows())->keyBy('install_id');

        $this->assertSame(self::INSTALL, $rows[self::INSTALL]['floor']);
        $this->assertSame('open', $rows[self::INSTALL]['form']);
    }

    /**
     * § 4.6: "a room the fleet reports no seat for is drawn and labelled, never omitted". On this
     * page that means the room has a ROW at all — the population is the union of the installs the
     * snapshot renders, the installs a map was authored for, and the rooms the layout places.
     * Without the third, an operator who composed a floor and then asked why a room was missing
     * would be told nothing.
     */
    public function test_a_room_the_layout_places_but_the_fleet_does_not_report_still_has_a_row(): void
    {
        $this->provisionSeat('sola-solo', 'sola');

        $this->composeTheBuilding([['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]);

        $rows = collect(FloorInventory::rows())->keyBy('install_id');

        $this->assertTrue($rows->has('zeta'), 'the authored room fell off the page');
        $this->assertSame('sola', $rows['zeta']['floor']);
        $this->assertSame(0, $rows['zeta']['seats']);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('no seats reported for this room');

        // THE CONTROL: report the room and the page stops saying it reports nothing, which is
        // what makes the assertion above about the RULE and not about a sentence that is always
        // on the page.
        $this->provisionSeat('zeta-solo', 'zeta');

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertDontSee('no seats reported for this room');
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

    // ── ⭐ card#9208's REVISIONS, DIFF, RESTORE and EXPORT, on the surface an operator uses ──

    /**
     * ⛔ THE MODULE THAT STANDS IN FOR VERSION CONTROL, driven through the pages rather than
     * through the store (`Tests\Feature\Building\TheAuthoredStoreKeepsEveryRevisionTest` holds the
     * store's own properties). What this arm proves is that the three things
     * `docs/design/FLEET-STATE.md § 6.11` says an operator gets back — blame, diff, revert — are
     * actually REACHABLE: a store with perfect history behind a page nobody can open gives back
     * none of them.
     */
    public function test_the_revisions_page_lists_every_save_with_its_author_and_its_slot_count(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();
        $this->author(self::INSTALL, FloorMapFixture::valid(8))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.floors.revisions', self::INSTALL))
            ->assertOk()
            ->assertSee('ops@example.com')
            ->assertSee('current')
            // `S` before and after, which is what re-slots every desk in the room (§ 10.3).
            ->assertSee('12')
            ->assertSee('8');
    }

    public function test_the_diff_page_names_the_layer_that_moved_and_the_slot_count_on_both_sides(): void
    {
        $this->provisionSeat('aimla-pm');

        $repainted = FloorMapFixture::decoded(12);
        $repainted['layers'][0]['data'][0] = 7;

        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();
        $this->author(self::INSTALL, FloorMapFixture::encode($repainted))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->get(route('admin.floors.diff', self::INSTALL).'?from=1&to=2')
            ->assertOk()
            ->assertSee('room')
            ->assertSee('changed');
    }

    public function test_a_revision_is_exported_as_the_document_that_was_authored(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();

        $response = $this->actingAs($this->operator())
            ->get(route('admin.floors.export', [self::INSTALL, 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="aimla-r1.tmj"');

        // § 6.11: the export is "the operator's own copy against a lost store", so it is the
        // document BYTE FOR BYTE and not a re-encoding of what this application parsed out of it.
        $this->assertSame(FloorMapFixture::valid(12), $response->getContent());
    }

    public function test_restoring_from_the_page_writes_a_forward_revision_and_says_so(): void
    {
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();
        $this->author(self::INSTALL, FloorMapFixture::valid(8))->assertSessionHasNoErrors();

        $this->actingAs($this->operator())
            ->post(route('admin.floors.restore', [self::INSTALL, 1]))
            ->assertRedirect(route('admin.floors.revisions', self::INSTALL))
            ->assertSessionHas('status');

        $this->assertSame(3, (int) Floors::forInstall(self::INSTALL)->map_version);
        $this->assertSame(12, FloorMap::parse(Floors::forInstall(self::INSTALL)->map)->slots);
    }

    public function test_a_save_that_changes_the_slot_count_says_every_desk_in_the_room_has_moved(): void
    {
        // § 10.3: "a save that changes `S` re-slots EVERY desk in that room — the slot function is
        // `h mod S` — and the console shows `S` before and after a save for exactly that reason; a
        // save that keeps `S` moves no desk." Both halves, because the second is what makes the
        // first a measurement rather than a sentence that is always on the page.
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();

        $this->author(self::INSTALL, FloorMapFixture::valid(8));

        $this->assertStringContainsString('declared 12 before', (string) session('status'));
        $this->assertStringContainsString('EVERY desk', (string) session('status'));

        // A save that keeps `S`: same count, different tiles.
        $repainted = FloorMapFixture::decoded(8);
        $repainted['layers'][0]['data'][0] = 7;

        $this->author(self::INSTALL, FloorMapFixture::encode($repainted));

        $this->assertStringContainsString('no desk moved', (string) session('status'));
    }

    public function test_a_no_op_save_comes_back_on_the_form_rather_than_as_a_five_hundred(): void
    {
        // § 6.11's no-op refusal is thrown by the STORE, not by the form's validation rule — it is
        // a fact about what is already current and not about the document. It still has to reach
        // the operator the way every other refusal does.
        $this->provisionSeat('aimla-pm');
        $this->author(self::INSTALL, FloorMapFixture::valid(12))->assertSessionHasNoErrors();

        $this->author(self::INSTALL, FloorMapFixture::valid(12))
            ->assertRedirect()
            ->assertSessionHasErrors('map');

        $this->assertSame(1, (int) Floors::forInstall(self::INSTALL)->map_version);
    }

    public function test_an_overlap_refused_at_a_maps_write_comes_back_on_the_form_naming_both_rooms(): void
    {
        // ⛔ § 6.11's SECOND WRITE SITE, at the surface: the operator is editing a ROOM and the
        // refusal is about the FLOOR it sits on, so the message has to name the other room or
        // there is nothing to act on.
        $this->provisionSeat('sola-solo', 'sola');
        $this->provisionSeat('zeta-solo', 'zeta');

        $this->author('sola', FloorMapFixture::sized(10, 8))->assertSessionHasNoErrors();
        $this->author('zeta', FloorMapFixture::sized(10, 8))->assertSessionHasNoErrors();

        $this->composeTheBuilding([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 320, 'y' => 0]],
        ]]]);

        $this->actingAs($this->operator())
            ->patch(route('admin.floors.update', 'sola'), ['map' => FloorMapFixture::sized(11, 8)])
            ->assertRedirect()
            ->assertSessionHasErrors('map');

        $this->assertStringContainsString(
            'zeta',
            (string) session('errors')->first('map'),
            'the refusal did not name the room the save would have overlapped',
        );
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
