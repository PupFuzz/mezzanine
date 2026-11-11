<?php

namespace Tests\Feature\Building;

use App\Building\FloorEdits;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Building\Rooms;
use App\Floor\FloorMap;
use App\Floor\Floors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Feature\Feed\FeedTestCase;

/**
 * A floor is named, its web address is the slug of its name, and a rename keeps the old address redirecting —
 * `docs/design/FLOOR.md § 4.4` and `§ 4.6` as amended by card#13117 (the plan's T12 and T13, S4's share: the console's
 * forms are S5's, so the acts are driven here through the closures they will hand `Layouts::amend()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE REDIRECTS ARE ASSERTED WHERE A VIEWER MEETS THEM: the layout is written to the store, served by
 * `GET /api/building`, composed by the browser's `floors()` and resolved by the floor screen's `resolveRoute()` — the
 * page's own modules under `node`, over the bytes the server answered. A resolver checked against a hand-written list
 * of floors would be checking a building no server serves.
 *
 * ⚠ THE RACES ARE DRIVEN IN SEQUENCE, as `Tests\Feature\Admin\RoomConsoleTest`'s are: the suite holds one connection
 * (card#7523), so what is proven is that the second act, decided against the document the first wrote, is refused
 * naming the first's floor, and the layout holds exactly the first's write.
 */
class AFloorIsAddressedByItsNameTest extends FeedTestCase
{
    use RefreshDatabase;
    use ResolvesFloorRoutes;

    private const BY = 'suite';

    // ── T12: the reader's refusals at load ──────────────────────────────────────────────────────────

    public function test_the_reader_refuses_each_document_naming_the_member_and_the_floors(): void
    {
        $refused = [
            'label' => [[['label' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]]], 'since card#13117 the member is `name`'],
            'no name' => [[['rooms' => ['nook' => ['form' => 'open']]]], 'declares no `name`'],
            'non-string name' => [[['name' => 7, 'rooms' => ['nook' => ['form' => 'open']]]], 'not a string (int)'],
            'blank name' => [[['name' => " \u{00A0}", 'rooms' => ['nook' => ['form' => 'open']]]], 'blank `name`'],
            'slug-empty name' => [[['name' => '三階', 'rooms' => ['nook' => ['form' => 'open']]]], 'must contain a letter or digit a–z 0–9'],
            'two floors slugging alike' => [[
                ['name' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]],
                ['name' => 'thé  nook', 'rooms' => ['den' => ['form' => 'open']]],
            ], 'Floors `The Nook` and `thé  nook` (#1) would both be at the address `/floor/the-nook`'],
            'alias equal to another floor\'s slug' => [[
                ['name' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]],
                ['name' => 'The Den', 'aliases' => ['the-nook'], 'rooms' => ['den' => ['form' => 'open']]],
            ], 'Floor `The Den` keeps the former address `the-nook`, which is the address of floor `The Nook` now'],
            'alias in two floors' => [[
                ['name' => 'The Nook', 'aliases' => ['old'], 'rooms' => ['nook' => ['form' => 'open']]],
                ['name' => 'The Den', 'aliases' => ['old'], 'rooms' => ['den' => ['form' => 'open']]],
            ], 'Floors `The Den` and `The Nook` both keep the former address `old`'],
            'alias that is not an address' => [[['name' => 'The Nook', 'aliases' => ['The-Den'], 'rooms' => ['nook' => ['form' => 'open']]]], 'the alias `The-Den`, which is not an address'],
            'alias equal to its own slug' => [[['name' => 'The Nook', 'aliases' => ['the-nook'], 'rooms' => ['nook' => ['form' => 'open']]]], 'beside the same address as its current one'],
            'name unassigned' => [[['name' => 'Unassigned', 'rooms' => ['nook' => ['form' => 'open']]]], 'reserved for the Unassigned floor'],
            'alias unassigned' => [[['name' => 'The Nook', 'aliases' => ['unassigned'], 'rooms' => ['nook' => ['form' => 'open']]]], 'the alias `unassigned`, which is reserved'],
            'room unassigned' => [[['name' => 'The Nook', 'rooms' => ['unassigned' => ['form' => 'open']]]], 'places a room keyed `unassigned`'],
        ];

        foreach ($refused as $what => [$floors, $message]) {
            try {
                LayoutFixture::read(['floors' => $floors]);
                $this->fail("the reader accepted a document with {$what}");
            } catch (InvalidBuildingLayout $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $what);
            }
        }
    }

    public function test_the_reader_accepts_the_empty_building_and_a_named_floor_with_its_former_addresses(): void
    {
        $this->assertSame([], LayoutFixture::read(['floors' => []])->floors);

        $floor = LayoutFixture::read(['floors' => [
            ['name' => 'Thé  Nook', 'aliases' => ['nook', 'the-den'], 'rooms' => ['nook' => ['form' => 'open']]],
        ]])->floors[0];

        $this->assertSame(['the-nook', 'Thé  Nook', ['nook', 'the-den']], [$floor['floor'], $floor['name'], $floor['aliases']]);
    }

    // ── T12: the write's refusals ───────────────────────────────────────────────────────────────────

    public function test_a_save_and_a_restore_placing_a_retired_room_are_refused_and_a_stored_one_still_loads(): void
    {
        Rooms::create('nook', 'Nook', null, self::BY);
        Rooms::create('den', 'Den', null, self::BY);
        $placing = $this->save([['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]], ['name' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]]]);
        Rooms::retire('nook', self::BY);

        $this->assertRefused(fn () => $this->save([['name' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open'], 'den' => ['form' => 'open']]]]), 'the retired room `nook`');
        $this->assertRefused(fn () => Layouts::restore($placing, self::BY), 'the retired room `nook`');

        // No LOAD refusal exists for it — no load can meet one — so a document planted into the store directly loads.
        DB::table('building_layout')->where('id', Layouts::ROW_ID)->update(['document' => json_encode(['floors' => [
            ['name' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]],
        ]])]);
        $this->assertSame(['the-nook'], array_column(Layouts::layout()->floors, 'floor'));
    }

    public function test_a_raw_save_naming_a_floor_into_an_address_another_floor_still_keeps_is_refused(): void
    {
        $this->save([
            ['name' => 'The Burrow', 'aliases' => ['the-nook'], 'rooms' => ['burrow' => ['form' => 'open']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);

        $this->assertRefused(fn () => $this->save([
            ['name' => 'The Burrow', 'aliases' => ['the-nook'], 'rooms' => ['burrow' => ['form' => 'open']]],
            ['name' => 'The Nook', 'rooms' => ['den' => ['form' => 'open']]],
        ]), 'Floor `The Burrow` keeps the former address `the-nook`, which is the address of floor `The Nook` now');

        // The consistent raw document — the alias gone from A and the name on B in one save — is the operator's take-over.
        $this->save([
            ['name' => 'The Burrow', 'rooms' => ['burrow' => ['form' => 'open']]],
            ['name' => 'The Nook', 'rooms' => ['den' => ['form' => 'open']]],
        ]);
        $this->assertSame(['the-burrow', 'the-nook'], array_column(Layouts::layout()->floors, 'floor'));
    }

    // ── T13: rename, redirect, take-over, precedence, races ─────────────────────────────────────────

    public function test_renames_redirect_in_one_hop_and_keep_a_drill_down_links_seat(): void
    {
        $this->save([['name' => 'The Nook', 'rooms' => ['aimla' => ['form' => 'open']]]]);

        Layouts::amend(FloorEdits::rename('the-nook', 'The Den'), self::BY);
        Layouts::amend(FloorEdits::rename('the-den', 'The Burrow'), self::BY);

        $floor = Layouts::layout()->floors[0];
        $this->assertSame(['the-burrow', ['the-nook', 'the-den']], [$floor['floor'], $floor['aliases']]);

        $this->assertSame(
            ['the-nook' => 'the-burrow', 'the-den' => 'the-burrow', 'the-burrow' => null, 'THE-BURROW' => null],
            array_map(fn (array $r) => $r['redirect'], $this->resolve(['the-nook', 'the-den', 'the-burrow', 'THE-BURROW'])),
        );
        $this->assertSame('the-burrow', $this->resolve(['THE-BURROW'])['THE-BURROW']['floor'],
            'an address typed in another case reaches its floor, whose key the page then shows');

        // A rename back to a former name takes that alias out of the list as it becomes current again.
        Layouts::amend(FloorEdits::rename('the-burrow', 'The Nook'), self::BY);
        $floor = Layouts::layout()->floors[0];
        $this->assertSame(['the-nook', ['the-den', 'the-burrow']], [$floor['floor'], $floor['aliases']]);
    }

    public function test_a_new_floor_takes_over_a_former_address_in_one_revision(): void
    {
        $this->save([['name' => 'The Nook', 'rooms' => ['aimla' => ['form' => 'open'], 'sola' => ['form' => 'office']]]]);
        Layouts::amend(FloorEdits::rename('the-nook', 'The Burrow'), self::BY);

        $revisions = DB::table('authored_revisions')->where('kind', Revisions::LAYOUT)->count();
        $messages = DB::table('feed_outbox')->where('t', 'building.layout')->count();

        Layouts::amend(FloorEdits::create('The Nook', null, 'sola'), self::BY);

        $this->assertSame($revisions + 1, DB::table('authored_revisions')->where('kind', Revisions::LAYOUT)->count(), 'one revision');
        $this->assertSame($messages + 1, DB::table('feed_outbox')->where('t', 'building.layout')->count(), 'one message');

        $floors = array_column(Layouts::layout()->floors, null, 'floor');
        $this->assertSame([], $floors['the-burrow']['aliases'], 'the address left The Burrow\'s aliases');
        $this->assertSame(['sola'], array_column($floors['the-nook']['rooms'], 'install'));
        $this->assertSame(['floor' => 'the-nook', 'redirect' => null], $this->resolve(['the-nook'])['the-nook']);
    }

    public function test_a_floor_named_like_a_room_takes_the_address_from_the_rooms_redirect(): void
    {
        $this->save([
            ['name' => 'aimla', 'rooms' => ['aimla' => ['form' => 'open'], 'sola' => ['form' => 'office']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);

        // Before: `sola` is a room on floor `aimla`, so its address redirects there.
        $this->assertSame(['floor' => null, 'redirect' => 'aimla'], $this->resolve(['sola'])['sola']);

        Layouts::amend(FloorEdits::rename('the-den', 'sola'), self::BY);

        // After: a name the operator chose outranks a key nobody chose (§ 4.4's precedence).
        $this->assertSame(['floor' => 'sola', 'redirect' => null], $this->resolve(['sola'])['sola']);
    }

    public function test_two_renames_racing_for_one_address_refuse_the_second_naming_the_first(): void
    {
        // (i) B's take-over of `the-nook` commits first; A's rename back to *The Nook* then finds it current on B.
        $this->save([
            ['name' => 'The Burrow', 'aliases' => ['the-nook'], 'rooms' => ['aimla' => ['form' => 'open']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);
        Layouts::amend(FloorEdits::rename('the-den', 'The Nook'), self::BY);
        $after = Layouts::documentText();

        $this->assertRefused(fn () => Layouts::amend(FloorEdits::rename('the-burrow', 'The Nook'), self::BY),
            'The address /floor/the-nook is floor The Nook\'s now');
        $this->assertSame($after, Layouts::documentText(), 'the layout holds exactly the first write');

        // (ii) A's rename back commits first; B's take-over then finds no alias and the address current on A.
        $this->save([
            ['name' => 'The Burrow', 'aliases' => ['the-nook'], 'rooms' => ['aimla' => ['form' => 'open']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);
        Layouts::amend(FloorEdits::rename('the-burrow', 'The Nook'), self::BY);
        $after = Layouts::documentText();

        $this->assertRefused(fn () => Layouts::amend(FloorEdits::rename('the-den', 'The Nook'), self::BY),
            'The address /floor/the-nook is floor The Nook\'s now');
        $this->assertSame($after, Layouts::documentText(), 'the layout holds exactly the first write');
    }

    public function test_a_room_is_placed_on_a_floor_found_by_its_address(): void
    {
        Rooms::create('parlour', 'Parlour', null, self::BY);
        $this->save([['name' => 'The Nook', 'rooms' => ['parlour' => ['form' => 'open']]]]);

        Rooms::create('den', 'Den', 'the-nook', self::BY);

        $this->assertSame(['den', 'parlour'], array_column(Layouts::layout()->floors[0]['rooms'], 'install'));
        // A room's key is not a floor's address: the floor is found by its own.
        $this->assertRefused(fn () => Rooms::create('cave', 'Cave', 'parlour', self::BY), 'There is no floor at /floor/parlour');
    }

    public function test_a_retired_rooms_kept_map_puts_it_on_no_floor(): void
    {
        // An authored room no floor places is drawn on the Unassigned floor — and a RETIRED room keeps its map as a
        // record, so the population the browser composes from (`rooms[]`) must leave it out or it is drawn there.
        Rooms::create('attic', 'Attic', null, self::BY);
        Floors::save('attic', FloorMap::parse(FloorMapFixture::valid()), self::BY);
        $this->assertSame(['floor' => 'unassigned', 'redirect' => null], $this->resolve(['unassigned'])['unassigned'],
            'the live authored room is not on the Unassigned floor, so the check below would measure nothing');

        Rooms::retire('attic', self::BY);

        $served = $this->actingAs($this->enrolled())->getJson('/api/building')->assertOk();
        $this->assertNotContains('attic', array_column($served->json('rooms'), 'install_id'));
        $this->assertSame(['floor' => null, 'redirect' => null], $this->resolve(['unassigned'])['unassigned']);
    }

    /** @param  list<array<string, mixed>>  $floors */
    private function save(array $floors): int
    {
        return Layouts::save((string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), self::BY, Layouts::version());
    }

    private function assertRefused(callable $act, string $message): void
    {
        try {
            $act();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail('the act was not refused: '.$message);
    }
}
