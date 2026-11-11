<?php

namespace Tests\Feature\Admin;

use App\Building\Layouts;
use App\Building\Revisions;
use App\Building\Rooms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Building\ResolvesFloorRoutes;
use Tests\Feature\Feed\FeedTestCase;
use Tests\Feature\Feed\OutboxWire;

/**
 * The console's **Floors** module — card#13117, plan-13117-seat-identity.md § 5.5, and T13's console half (§ 5.3): a
 * floor renamed, created and given rooms from the console's forms, each act one `Layouts::amend()` revision decided
 * against the document it replaces. The addresses are asserted where a viewer meets them (`ResolvesFloorRoutes`).
 *
 * ⚠ The races are driven in sequence, as `RoomConsoleTest`'s are: the suite holds one connection (card#7523).
 */
class FloorsConsoleTest extends FeedTestCase
{
    use RefreshDatabase;
    use ResolvesFloorRoutes;

    private ?User $operator = null;

    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);
    }

    /** @param  list<array<string, mixed>>  $floors */
    private function save(array $floors): int
    {
        return Layouts::save((string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'suite', Layouts::version());
    }

    /** @param  array<string, mixed>  $form */
    private function rename(string $floor, array $form): TestResponse
    {
        return $this->actingAs($this->operator())->patch(route('admin.building.floors.update', $floor), $form);
    }

    /** @param  array<string, mixed>  $form */
    private function create(array $form): TestResponse
    {
        return $this->actingAs($this->operator())->post(route('admin.building.floors.store'), $form);
    }

    private function place(string $floor, string $room): TestResponse
    {
        return $this->actingAs($this->operator())->post(route('admin.building.floors.place', $floor), ['room' => $room]);
    }

    /** @return array<string, array<string, mixed>> the current layout's floors, by address */
    private function floors(): array
    {
        return array_column(Layouts::layout()->floors, null, 'floor');
    }

    /** @param  list<string>  $keys  live rooms, named after their keys */
    private function rooms(array $keys): void
    {
        // The fixture fleet already holds some of these rooms; each is named as the ones created here are.
        foreach ($keys as $key) {
            DB::table('installs')->where('install_id', $key)->exists()
                ? Rooms::rename($key, ucfirst($key))
                : Rooms::create($key, ucfirst($key), null, 'suite');
        }
    }

    private function refused(TestResponse $response, string $message): void
    {
        $response->assertRedirect(route('admin.building.index'))->assertSessionHasErrors('floor');
        $this->assertStringContainsString($message, session('errors')->first('floor'));
    }

    public function test_the_floors_module_lists_each_floor_with_its_address_former_addresses_theme_and_rooms(): void
    {
        $this->rooms(['aimla', 'sola', 'attic']);
        $this->save([['name' => 'The Burrow', 'aliases' => ['the-nook'], 'theme' => 'cottage', 'rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->actingAs($this->operator())->get(route('admin.building.index'))->assertOk()
            ->assertSee('The Burrow')->assertSee('/floor/the-burrow')->assertSee('/floor/the-nook')
            ->assertSee('<option value="cottage" selected>', false)
            ->assertSee('Aimla (aimla)')
            ->assertSee('Attic (attic, on no floor)');
    }

    // ── T13, the console half ───────────────────────────────────────────────────────────────────────

    public function test_renames_from_the_console_keep_every_old_address_redirecting_in_one_hop(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'The Nook', 'rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->rename('the-nook', ['name' => 'The Den'])->assertSessionHasNoErrors();
        $this->rename('the-den', ['name' => 'The Burrow'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'now named The Burrow'));

        $this->assertSame(['the-nook', 'the-den'], $this->floors()['the-burrow']['aliases']);

        // A name of the same address changes what the plaque reads and no address.
        $this->rename('the-burrow', ['name' => 'THE burrow'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'its address is unchanged'));
        $this->assertSame(['the-nook', 'the-den'], $this->floors()['the-burrow']['aliases']);

        $this->assertSame(['the-nook' => 'the-burrow', 'the-den' => 'the-burrow'],
            array_map(fn (array $r) => $r['redirect'], $this->resolve(['the-nook', 'the-den'])));
    }

    public function test_a_floor_created_from_the_console_takes_over_a_former_address_in_one_revision(): void
    {
        $this->rooms(['aimla', 'sola']);
        $this->save([['name' => 'The Burrow', 'aliases' => ['the-nook'], 'rooms' => ['aimla' => ['form' => 'open'], 'sola' => ['form' => 'office']]]]);
        $revisions = DB::table('authored_revisions')->where('kind', Revisions::LAYOUT)->count();
        $wire = new OutboxWire;
        $wire->forget();

        $this->create(['name' => 'The Nook', 'theme' => 'cottage', 'room' => 'sola'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'a former address of floor The Burrow'));

        $this->assertSame($revisions + 1, DB::table('authored_revisions')->where('kind', Revisions::LAYOUT)->count(), 'one revision');
        $this->assertCount(1, $wire->ofType('building.layout'), 'one message');
        $floors = $this->floors();
        $this->assertSame([], $floors['the-burrow']['aliases']);
        $this->assertSame(['sola'], array_column($floors['the-nook']['rooms'], 'install'));
        $this->assertSame('office', $floors['the-nook']['rooms'][0]['form'], 'the room keeps its form');
        $this->assertSame('cottage', $floors['the-nook']['theme']);
        $this->assertSame(['floor' => 'the-nook', 'redirect' => null], $this->resolve(['the-nook'])['the-nook']);
    }

    public function test_a_name_another_floor_answers_to_now_is_refused_naming_it_and_nothing_is_written(): void
    {
        $this->rooms(['aimla', 'den']);
        $this->save([
            ['name' => 'The Nook', 'rooms' => ['aimla' => ['form' => 'open']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);
        $before = Layouts::documentText();

        $this->refused($this->rename('the-den', ['name' => 'the nook']), "The address /floor/the-nook is floor The Nook's now");
        $this->assertSame($before, Layouts::documentText());
    }

    // ── § 5.5: create, and the two S4 findings ──────────────────────────────────────────────────────

    public function test_a_floor_is_created_holding_a_room_and_a_form_without_one_is_refused(): void
    {
        $this->rooms(['attic']);

        $this->create(['name' => 'The Attic'])->assertSessionHasErrors('room');
        $this->assertSame(0, Layouts::version());

        $this->create(['name' => 'The Attic', 'room' => 'attic'])->assertSessionHasNoErrors();
        $this->assertSame(['attic'], array_column($this->floors()['the-attic']['rooms'], 'install'));
        $this->assertArrayNotHasKey('theme', $this->floors()['the-attic'], 'no theme chosen: the house theme');
    }

    public function test_a_new_floor_taking_the_only_room_of_another_is_refused_and_says_to_rename_it(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'The Nook', 'aliases' => ['nook'], 'rooms' => ['aimla' => ['form' => 'open']]]]);
        $before = Layouts::documentText();

        $this->refused($this->create(['name' => 'The Den', 'room' => 'aimla']), 'Rename The Nook instead');

        $this->assertSame($before, Layouts::documentText(), 'the floor and every address it answers to are kept');
        $this->assertSame(['floor' => null, 'redirect' => 'the-nook'], $this->resolve(['nook'])['nook']);
    }

    // ── § 5.5: place ────────────────────────────────────────────────────────────────────────────────

    public function test_a_room_placed_on_a_floor_moves_from_its_old_one_which_keeps_its_plan(): void
    {
        $this->rooms(['aimla', 'sola', 'den']);
        $this->save([
            ['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]], 'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 2000]]]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);

        $this->place('the-den', 'sola')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => ! str_contains($s, 'planned') && ! str_contains($s, 'removed'));

        $floors = $this->floors();
        $this->assertSame(['den', 'sola'], array_column($floors['the-den']['rooms'], 'install'));
        $this->assertSame('office', $floors['the-den']['rooms'][1]['form']);
        $this->assertArrayNotHasKey('origin', $floors['the-den']['rooms'][1], 'the room leaves its position behind');
        $this->assertSame([['install' => 'aimla', 'form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]], $floors['ground']['rooms']);
    }

    public function test_a_room_placed_on_a_planned_floor_clears_its_plan_and_says_so(): void
    {
        $this->rooms(['aimla', 'sola']);
        $this->save([
            ['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]]],
        ]);

        $this->place('ground', 'sola')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Floor Ground was planned'));

        foreach ($this->floors()['ground']['rooms'] as $room) {
            $this->assertArrayNotHasKey('origin', $room);
        }
    }

    public function test_placing_a_floors_last_room_elsewhere_removes_the_floor_and_says_its_addresses_open_nothing(): void
    {
        $this->rooms(['aimla', 'den']);
        $this->save([
            ['name' => 'The Nook', 'rooms' => ['aimla' => ['form' => 'open']]],
            ['name' => 'The Den', 'rooms' => ['den' => ['form' => 'open']]],
        ]);

        $this->place('the-den', 'aimla')->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Floor The Nook held no other room and is removed'));

        $this->assertSame(['the-den'], array_keys($this->floors()));
    }

    public function test_placing_a_room_on_the_floor_it_is_on_is_refused_and_leaves_the_plan(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]]]]);
        $before = Layouts::documentText();

        $this->refused($this->place('ground', 'aimla'), 'Room aimla is on floor Ground already');
        $this->assertSame($before, Layouts::documentText());
    }

    public function test_a_room_that_is_not_a_live_room_is_refused_at_the_form(): void
    {
        $this->rooms(['aimla', 'gone']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);
        Rooms::retire('gone', 'suite');

        $this->place('ground', 'gone')->assertSessionHasErrors('room');
        $this->place('ground', 'never-made')->assertSessionHasErrors('room');
        $this->assertSame(1, Layouts::version());
    }

    // ── § 5.5: theme, forget, and a page rendered from an older document ────────────────────────────

    public function test_a_floors_theme_is_set_and_cleared_and_a_theme_the_build_does_not_ship_is_refused(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->rename('ground', ['theme' => 'cottage'])->assertSessionHasNoErrors();
        $this->assertSame('cottage', $this->floors()['ground']['theme']);

        $this->rename('ground', ['theme' => ''])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('theme', $this->floors()['ground']);

        $this->refused($this->rename('ground', ['theme' => 'brutalist']), 'which this build does not ship');
    }

    public function test_a_former_address_is_forgotten_and_one_already_forgotten_is_refused_by_name(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'The Burrow', 'aliases' => ['the-nook', 'the-den'], 'rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->rename('the-burrow', ['forget' => 'the-nook'])->assertSessionHasNoErrors();
        $this->assertSame(['the-den'], $this->floors()['the-burrow']['aliases']);
        $this->assertSame(['floor' => null, 'redirect' => null], $this->resolve(['the-nook'])['the-nook']);

        $this->refused($this->rename('the-burrow', ['forget' => 'the-nook']), 'keeps no former address /floor/the-nook now');
    }

    public function test_a_save_names_one_change(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->rename('ground', ['name' => 'Upstairs', 'forget' => 'x'])->assertSessionHasErrors('floor');
        $this->rename('ground', [])->assertSessionHasErrors('floor');
        $this->assertSame(1, Layouts::version());
    }

    public function test_an_act_on_a_floor_a_newer_document_renamed_is_refused_naming_the_address(): void
    {
        $this->rooms(['aimla', 'sola']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);
        // A second operator renames the floor after this page was rendered at /floor/ground.
        $this->rename('ground', ['name' => 'Upstairs'])->assertSessionHasNoErrors();

        $this->refused($this->place('ground', 'sola'), 'There is no floor at /floor/ground');
        $this->refused($this->rename('ground', ['theme' => 'cottage']), 'There is no floor at /floor/ground');
    }

    public function test_a_stored_layout_the_reader_refuses_is_named_on_the_page_and_every_act_is_refused_by_name(): void
    {
        $this->rooms(['aimla']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);
        // A document a later rule refuses (FLOOR.md § 9 F17) — here the retired member `label`.
        DB::table('building_layout')->where('id', Layouts::ROW_ID)
            ->update(['document' => '{"floors": [{"label": "Ground", "rooms": {"aimla": {"form": "open"}}}]}']);

        $this->actingAs($this->operator())->get(route('admin.building.index'))->assertOk()
            ->assertSee('The stored building layout cannot be read')->assertDontSee('Create a floor');

        $this->refused($this->rename('ground', ['name' => 'Upstairs']), "refused by this build's reader");
        $this->refused($this->create(['name' => 'Upstairs', 'room' => 'aimla']), "refused by this build's reader");
    }

    public function test_an_observer_is_refused_the_page_and_every_act_and_nothing_is_written(): void
    {
        $this->rooms(['aimla', 'sola']);
        $this->save([['name' => 'Ground', 'rooms' => ['aimla' => ['form' => 'open']]]]);
        $observer = User::factory()->twoFactorConfirmed()->observer()->create();

        $this->actingAs($observer)->get(route('admin.building.index'))->assertForbidden();
        $this->actingAs($observer)->post(route('admin.building.floors.store'), ['name' => 'X', 'room' => 'sola'])->assertForbidden();
        $this->actingAs($observer)->patch(route('admin.building.floors.update', 'ground'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($observer)->post(route('admin.building.floors.place', 'ground'), ['room' => 'sola'])->assertForbidden();

        $this->assertSame(1, Layouts::version());
    }

    // ── the page's address preview (`public/js/console/floor-name.js`) ──────────────────────────────

    public function test_the_name_field_shows_the_address_and_the_floor_that_holds_it_before_the_save(): void
    {
        $floors = [
            ['key' => 'the-burrow', 'name' => 'The Burrow', 'aliases' => ['the-nook']],
            ['key' => 'the-den', 'name' => 'The Den', 'aliases' => []],
        ];
        $cases = [
            ['Thé  Nook', null, 'is a former address of floor The Burrow: this floor takes it over'],
            ['the den', null, "is floor The Den's address, and the save will be refused"],
            ['The Den', 'the-den', '/floor/the-den, as now'],
            ['Unassigned', null, "is the Unassigned floor's"],
            ['三階', null, 'no address'],
            ['The Attic', null, '/floor/the-attic'],
        ];
        $js = realpath(__DIR__.'/../../../public/js/console/floor-name.js');
        $script = sprintf(
            'import { addressNote } from %s; console.log(JSON.stringify(%s.map(([n, self]) => addressNote(n, %s, self).note)));',
            json_encode('file://'.$js), json_encode($cases, JSON_UNESCAPED_UNICODE), json_encode($floors),
        );
        $notes = json_decode((string) shell_exec('node --input-type=module -e '.escapeshellarg($script).' 2>&1'), true);

        $this->assertIsArray($notes);

        foreach ($cases as $i => [$name, , $expected]) {
            $this->assertStringContainsString($expected, $notes[$i], $name);
        }
    }
}
