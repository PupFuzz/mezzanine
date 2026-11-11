<?php

namespace Tests\Feature\Admin;

use App\Building\Layouts;
use App\Building\RoomRefused;
use App\Building\Rooms;
use App\Coord\HookBinding;
use App\Fleet\SeatTokens;
use App\Models\User;
use App\Read\SeatIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Feed\OutboxWire;
use Tests\TestCase;

/**
 * The console's **Rooms** module and the Agents index's *Room* control — card#13117, plan-13117-seat-identity.md
 * § 4.1–§ 4.2 — and T22 (§ 5.5): `Layouts::amend()` decides against the document it replaces, and the textarea's
 * compare-and-swap refuses a save from a stale base.
 *
 * ⚠ T22's *the second action blocked on the lock* is driven here in sequence, not with two connections: the suite
 * holds one (`php-tests.yml`'s header, card#7523). What sequence does prove is the property the lock serves — the
 * second action, rendered from the same base as the first, reads the first's result and either applies on top of
 * it or is refused naming the floor; a form-time decision would do neither.
 */
class RoomConsoleTest extends TestCase
{
    use RefreshDatabase;

    private ?User $operator = null;

    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);
    }

    /** @param  array<string, mixed>  $form */
    private function create(array $form): TestResponse
    {
        return $this->actingAs($this->operator())->post(route('admin.rooms.store'), $form);
    }

    private function retire(string $room): TestResponse
    {
        return $this->actingAs($this->operator())->post(route('admin.rooms.retire', $room));
    }

    /** @param  list<array<string, mixed>>  $floors */
    private function saveLayout(array $floors): int
    {
        return Layouts::save((string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT), 'suite', Layouts::version());
    }

    /** @return array<string, list<string>> each floor of the current document, by its first room, => its rooms */
    private function placement(): array
    {
        $out = [];

        foreach (Layouts::document()->floors as $floor) {
            $rooms = array_keys(get_object_vars($floor->rooms));
            sort($rooms);
            $out[$rooms[0]] = $rooms;
        }

        ksort($out);

        return $out;
    }

    public function test_the_rooms_module_is_listed_and_its_page_lists_every_room(): void
    {
        Rooms::create('aimla', 'The Aimla room', null, 'suite');
        Rooms::create('gone', 'Gone', null, 'suite');
        Rooms::retire('gone', 'suite');

        $this->actingAs($this->operator())->get(route('admin.rooms.index'))->assertOk()
            ->assertSee('aimla')->assertSee('The Aimla room')->assertSee('gone')->assertSee('retired');
    }

    public function test_a_room_is_created_on_no_floor_with_no_layout_revision(): void
    {
        $this->create(['key' => 'aimla', 'name' => 'Aimla', 'floor' => ''])
            ->assertRedirect(route('admin.rooms.index'))->assertSessionHasNoErrors();

        $this->assertSame('Aimla', DB::table('installs')->where('install_id', 'aimla')->value('display_name'));
        $this->assertSame(0, Layouts::version());
    }

    public function test_a_room_placed_at_creation_is_one_layout_revision_and_one_message(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->saveLayout([['label' => 'Ground', 'rooms' => ['aimla' => ['form' => 'office']]]]);
        $wire = new OutboxWire;
        $wire->forget();

        $this->create(['key' => 'nook', 'name' => 'The Nook', 'floor' => 'aimla'])->assertSessionHasNoErrors();

        $this->assertSame(2, Layouts::version());
        $this->assertCount(1, $wire->ofType('building.layout'));
        $this->assertSame(['aimla' => ['aimla', 'nook']], $this->placement());
        // The rest of the floor's entry is the operator's and stays as authored.
        $this->assertSame('Ground', Layouts::document()->floors[0]->label);
        $this->assertSame('office', Layouts::document()->floors[0]->rooms->aimla->form);
    }

    public function test_a_room_the_reader_refuses_to_place_is_not_created_either(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]]]]);

        // A planned floor is all-or-nothing (FLOOR.md § 4.6), so a room placed with no origin is refused —
        // and the room's row goes with the refusal, since the two are one transaction.
        $this->create(['key' => 'nook', 'name' => 'Nook', 'floor' => 'aimla'])->assertSessionHasErrors('room');

        $this->assertFalse(DB::table('installs')->where('install_id', 'nook')->exists());
        $this->assertSame(1, Layouts::version());
    }

    public function test_a_key_that_cannot_be_a_room_and_a_name_that_cannot_be_one_are_refused(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $rows = DB::table('installs')->count();

        foreach ([
            ['key' => SeatIdentity::UNASSIGNED, 'name' => 'x'],
            ['key' => 'aimla', 'name' => 'again'],
            ['key' => 'Not A Slug', 'name' => 'x'],
            ['key' => 'ok-key', 'name' => str_repeat('n', Rooms::NAME_MAX + 1)],
        ] as $form) {
            $this->create($form)->assertSessionHasErrors('room');
        }

        // A blank name never reaches the act through the form (the request trims it to nothing, and `name` is
        // required); the act refuses one of its own for a caller that is not the form.
        $this->create(['key' => 'ok-key', 'name' => '   '])->assertSessionHasErrors('name');
        $this->expectException(RoomRefused::class);

        try {
            Rooms::create('ok-key', '   ', null, 'suite');
        } finally {
            $this->assertSame($rows, DB::table('installs')->count());
        }

    }

    /** The bound is the column's: a name of exactly NAME_MAX multibyte characters is stored whole. */
    public function test_a_name_at_the_bound_is_stored_whole(): void
    {
        $this->create(['key' => 'wide', 'name' => str_repeat('é', Rooms::NAME_MAX)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('é', Rooms::NAME_MAX), DB::table('installs')->where('install_id', 'wide')->value('display_name'));
    }

    public function test_a_room_is_renamed_and_keeps_its_key(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');

        $this->actingAs($this->operator())->patch(route('admin.rooms.update', 'aimla'), ['name' => 'The Burrow'])
            ->assertSessionHasNoErrors();

        $this->assertSame('The Burrow', DB::table('installs')->where('install_id', 'aimla')->value('display_name'));
    }

    public function test_a_room_is_not_retired_while_a_seat_is_in_it_or_a_binding_is_bound_to_it(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        SeatTokens::issue('seat-a', 'suite');
        $this->actingAs($this->operator())->patch(route('admin.agents.room', 'seat-a'), ['room' => 'aimla'])->assertSessionHasNoErrors();

        $this->retire('aimla')->assertSessionHasErrors(['room' => 'Room aimla still holds seat-a. Move it to another room, or retire it, first; nothing was changed.']);

        $this->actingAs($this->operator())->patch(route('admin.agents.room', 'seat-a'), ['room' => SeatIdentity::UNASSIGNED]);
        [$hook] = HookBinding::issue('acme/team', 'room', 'aimla', false, 'suite');
        $this->retire('aimla')->assertSessionHasErrors('room');
        $this->assertNull(DB::table('installs')->where('install_id', 'aimla')->value('retired_at'));

        HookBinding::revoke($hook, null);
        $this->retire('aimla')->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('installs')->where('install_id', 'aimla')->value('retired_at'));
    }

    public function test_retiring_a_placed_room_takes_it_off_its_floor_in_one_revision_and_an_unplaced_one_writes_none(): void
    {
        foreach (['aimla', 'nook', 'den', 'attic'] as $key) {
            Rooms::create($key, ucfirst($key), null, 'suite');
        }

        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open'], 'nook' => ['form' => 'open']]], ['rooms' => ['den' => ['form' => 'open']]]]);

        $this->retire('nook')->assertSessionHasNoErrors();
        $this->assertSame(2, Layouts::version());
        $this->assertSame(['aimla' => ['aimla'], 'den' => ['den']], $this->placement());

        // The last room of a floor takes the floor's entry with it: a floor of no rooms is a document the reader refuses.
        $this->retire('den')->assertSessionHasNoErrors();
        $this->assertSame(3, Layouts::version());
        $this->assertSame(['aimla' => ['aimla']], $this->placement());

        $this->retire('attic')->assertSessionHasNoErrors();
        $this->assertSame(3, Layouts::version(), 'retiring an unplaced room wrote a layout revision');
    }

    public function test_a_layout_write_that_places_a_retired_room_is_refused_and_a_seat_cannot_move_into_one(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        Rooms::create('nook', 'Nook', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open'], 'nook' => ['form' => 'open']]]]);
        Rooms::retire('nook', 'suite');
        $this->assertSame(2, Layouts::version());

        // The raw textarea, from the current base.
        $this->actingAs($this->operator())->patch(route('admin.layout.update'), [
            'layout' => '{"floors": [{"rooms": {"nook": {"form": "open"}}}]}', 'base_layout_version' => 2,
        ])->assertSessionHasErrors(['layout' => 'This layout places the retired room `nook`. A retired room is drawn nowhere and is listed in the '
            .'console\'s Rooms module only (docs/design/FLEET-STATE.md § 6.11, card#13117): take it out of the document and save again.']);

        // A restore of the revision written before the retirement.
        $this->actingAs($this->operator())->post(route('admin.layout.restore', 1))->assertSessionHasErrors('revision');
        $this->assertSame(2, Layouts::version());

        SeatTokens::issue('seat-a', 'suite');
        $this->actingAs($this->operator())->patch(route('admin.agents.room', 'seat-a'), ['room' => 'nook'])
            ->assertSessionHasErrors(['room' => 'Room nook is retired, and a seat cannot be placed in a retired room. Nothing was changed.']);
        $this->assertSame(SeatIdentity::UNASSIGNED, SeatIdentity::byId('seat-a')->install_id);

        // A binding cannot name it either.
        $this->expectExceptionMessage('room "nook" is retired');
        HookBinding::issue('acme/team', 'room', 'nook', false, 'suite');
    }

    /** T22, first half: two console actions rendered from one base — the second applies on top of the first. */
    public function test_two_console_actions_from_one_base_both_apply_the_second_on_the_firsts_result(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->create(['key' => 'nook', 'name' => 'Nook', 'floor' => 'aimla'])->assertSessionHasNoErrors();
        $this->create(['key' => 'den', 'name' => 'Den', 'floor' => 'aimla'])->assertSessionHasNoErrors();

        $this->assertSame(['aimla' => ['aimla', 'den', 'nook']], $this->placement());
        $this->assertSame(3, Layouts::version());
    }

    /** T22, second half: the first action removed the floor the second was rendered against — refused, naming it. */
    public function test_a_console_action_against_a_floor_an_earlier_action_removed_is_refused_naming_it(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        Rooms::create('lone', 'Lone', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open']]], ['rooms' => ['lone' => ['form' => 'open']]]]);

        $this->retire('lone')->assertSessionHasNoErrors();
        $layout = Layouts::documentText();

        $this->create(['key' => 'nook', 'name' => 'Nook', 'floor' => 'lone'])
            ->assertSessionHasErrors(['room' => 'The floor holding room lone is no longer in the building layout — it changed since this page '
                .'was opened. Reload and choose the floor again; nothing was created.']);

        $this->assertSame($layout, Layouts::documentText(), 'the layout does not hold exactly the first action\'s write');
        $this->assertFalse(DB::table('installs')->where('install_id', 'nook')->exists());
    }

    /** T22, the textarea: a save from a stale base is refused naming both revisions, and the store is unchanged. */
    public function test_a_textarea_save_from_a_stale_base_is_refused_naming_both_revisions(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open']]]]);
        // A console action lands after the form was opened at revision 1.
        $this->create(['key' => 'nook', 'name' => 'Nook', 'floor' => 'aimla'])->assertSessionHasNoErrors();
        $before = Layouts::documentText();

        $this->actingAs($this->operator())->patch(route('admin.layout.update'), [
            'layout' => '{"floors": [{"rooms": {"aimla": {"form": "office"}}}]}', 'base_layout_version' => 1,
        ])->assertSessionHasErrors(['layout' => 'The layout changed since you opened it (revision 2 is current; you edited from 1): '
            .'reload and re-apply your edit. Nothing was saved (docs/design/FLEET-STATE.md § 6.11).']);

        $this->assertSame(2, Layouts::version());
        $this->assertSame($before, Layouts::documentText());

        // The control: the same edit from the current base is saved.
        $this->actingAs($this->operator())->patch(route('admin.layout.update'), [
            'layout' => '{"floors": [{"rooms": {"aimla": {"form": "office"}}}]}', 'base_layout_version' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, Layouts::version());
    }

    public function test_an_edit_that_changes_nothing_is_refused_as_a_no_op(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        $this->saveLayout([['rooms' => ['aimla' => ['form' => 'open']]]]);

        $this->expectExceptionMessage('That change leaves the building layout as it already is');
        Layouts::amend(fn (\stdClass $document): \stdClass => $document, 'suite');
    }

    /** The Agents index's *Room* control, through the route: one move, and its status and warnings shown. */
    public function test_the_room_control_moves_a_seat_and_the_index_shows_its_room(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        SeatTokens::issue('seat-a', 'suite');

        $this->actingAs($this->operator())->patch(route('admin.agents.room', 'seat-a'), ['room' => 'aimla'])
            ->assertRedirect(route('admin.agents.index'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'seat-a moved from unassigned to aimla'));

        $this->actingAs($this->operator())->get(route('admin.agents.index'))->assertOk()
            ->assertSee('<option value="aimla" selected>', false);
    }

    /** The console is operator-only: an enrolled observer is refused the rooms writes and the move, and nothing is written. */
    public function test_an_observer_is_refused_and_nothing_is_written(): void
    {
        Rooms::create('aimla', 'Aimla', null, 'suite');
        SeatTokens::issue('seat-a', 'suite');
        $observer = User::factory()->twoFactorConfirmed()->observer()->create();

        $this->actingAs($observer)->post(route('admin.rooms.store'), ['key' => 'nook', 'name' => 'Nook'])->assertForbidden();
        $this->actingAs($observer)->patch(route('admin.rooms.update', 'aimla'), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($observer)->post(route('admin.rooms.retire', 'aimla'))->assertForbidden();
        $this->actingAs($observer)->patch(route('admin.agents.room', 'seat-a'), ['room' => 'aimla'])->assertForbidden();
        $this->actingAs($observer)->get(route('admin.rooms.index'))->assertForbidden();

        $this->assertFalse(DB::table('installs')->where('install_id', 'nook')->exists());
        $this->assertSame('Aimla', DB::table('installs')->where('install_id', 'aimla')->value('display_name'));
        $this->assertNull(DB::table('installs')->where('install_id', 'aimla')->value('retired_at'));
        $this->assertSame(SeatIdentity::UNASSIGNED, SeatIdentity::byId('seat-a')->install_id);
    }

    public function test_the_primitive_refuses_the_reserved_key_for_every_act(): void
    {
        foreach ([
            fn () => Rooms::rename(SeatIdentity::UNASSIGNED, 'x'),
            fn () => Rooms::retire(SeatIdentity::UNASSIGNED, 'suite'),
        ] as $act) {
            try {
                $act();
                $this->fail('the reserved key was accepted');
            } catch (RoomRefused $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }
}
