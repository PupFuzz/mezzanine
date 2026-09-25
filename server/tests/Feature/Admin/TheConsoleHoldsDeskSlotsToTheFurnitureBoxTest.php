<?php

namespace Tests\Feature\Admin;

use App\Building\Revisions;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\FurnitureBox;
use App\Floor\InvalidFloorMap;
use App\Fold\Clock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ THE CONSOLE'S OWN GATE FOR `docs/design/FLOOR.md § 14` ITEM 28(1)'s THREE OBLIGATIONS —
 * Appendix B row 14's gate column, in its words: **"a save and a restore of a map with two
 * intersecting objects are each refused naming both, a save and a restore of a map with an object
 * smaller than the box are each refused, a room whose stored map fails the box is listed on the room
 * index after a change of the box and is not refused, and a control plants each defect and watches
 * it red"** — operator-ruled 2026-09-25, card#7341 comment 6488.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ SHAPED LIKE ROW 11's GATE (`Tests\Feature\Building\TheAuthoredStoreKeepsEveryRevisionTest`):
 * every refusal is asserted with its CONTROL — the same write on a map that differs from the
 * refused one by exactly the property under test (`FloorMapFixture` mutates the valid map, never
 * hand-writes a second), and the control is ACCEPTED. Without the control a refusal arm would pass
 * against a console that refused every write.
 *
 * ⚠ A REVISION THE CONSOLE WOULD NOW REFUSE CAN ONLY EXIST ONE WAY: it was stored before the
 * refusal did (or against another box). This file plants such rows the way the store holds them —
 * `furniture_box` NULL, which is what every revision written before the column existed carries —
 * because the writer under test cannot produce them, which is the point of it.
 *
 * ⚠ A CHANGE OF THE BOX is stood in through the container (`App\Providers\AppServiceProvider`
 * binds `FurnitureBox` to its one source), because the only other way to produce one is to edit
 * `resources/floor/furniture-box.js` under a running suite.
 */
class TheConsoleHoldsDeskSlotsToTheFurnitureBoxTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'aimla';

    private const OPERATOR = 'ops@example.com';

    private ?User $operator = null;

    private function operator(): User
    {
        return $this->operator ??= User::factory()->twoFactorConfirmed()->create(['email' => self::OPERATOR]);
    }

    /** A seat in the room, so the console's form admits it (`FloorInventory::renderedInstalls()`). */
    private function provisionTheRoom(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $installRef = DB::table('installs')->insertGetId(['install_id' => self::INSTALL, 'created_at' => $now]);
        $seatRef = DB::table('seats')->insertGetId([
            'install_ref' => $installRef,
            'seat_id' => 'aimla-pm',
            'created_at' => $now,
        ]);

        DB::table('seat_state')->insert([
            'seat_ref' => $seatRef,
            'render_state' => 'offline',
            'link_state' => 'offline',
            'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet',
            'state_computed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function save(string $document): int
    {
        return Floors::save(self::INSTALL, FloorMap::parse($document), self::OPERATOR);
    }

    /**
     * A revision as a store written before item 28(1) holds it: `furniture_box` NULL — validated
     * against no recorded box. Appended to the log only; the current row is not moved.
     */
    private function plantALegacyRevision(string $document): int
    {
        $revision = (int) DB::table('authored_revisions')
            ->where('kind', Revisions::ROOM_MAP)->where('subject', self::INSTALL)->max('revision') + 1;

        DB::table('authored_revisions')->insert([
            'kind' => Revisions::ROOM_MAP,
            'subject' => self::INSTALL,
            'revision' => $revision,
            'document' => $document,
            'restored_from' => null,
            'authored_by' => 'an operator before 2026-09-25',
            'authored_at' => Clock::sql(now()),
            'furniture_box' => null,
        ]);

        return $revision;
    }

    /** A legacy revision that is also the room's CURRENT map — what a fielded store can hold. */
    private function plantALegacyCurrentMap(string $document, ?string $validatedAgainst = null): void
    {
        $revision = $this->plantALegacyRevision($document);

        DB::table('authored_revisions')
            ->where('kind', Revisions::ROOM_MAP)->where('subject', self::INSTALL)->where('revision', $revision)
            ->update(['furniture_box' => $validatedAgainst]);

        $at = Clock::sql(now());

        DB::table('floors')->insert([
            'install_id' => self::INSTALL,
            'map' => $document,
            'map_version' => $revision,
            'updated_by' => 'an operator before 2026-09-25',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Stand a different furniture box in — item 28(1)(iii)'s event. */
    private function changeTheBoxTo(int $width, int $height): FurnitureBox
    {
        $box = FurnitureBox::parse(sprintf('export const FURNITURE_BOX = Object.freeze({ width: %d, height: %d });', $width, $height));

        $this->app->instance(FurnitureBox::class, $box);

        return $box;
    }

    /** `$slots` desks side by side at `$box`, in a grid that holds them — a map that passes it. */
    private function mapAt(FurnitureBox $box, int $slots): string
    {
        $map = FloorMapFixture::decoded($slots);
        $tiles = (int) ceil($slots * $box->width / 32);
        $high = (int) ceil($box->height / 32);

        $map['width'] = $tiles;
        $map['height'] = $high;
        $map['layers'][0]['width'] = $tiles;
        $map['layers'][0]['height'] = $high;
        $map['layers'][0]['data'] = array_fill(0, $tiles * $high, 1);

        foreach ($map['layers'][1]['objects'] as $i => &$object) {
            $object['x'] = $box->width * $i;
            $object['width'] = $box->width;
            $object['height'] = $box->height;
        }

        return FloorMapFixture::encode($map);
    }

    private function recordedBox(int $revision): ?string
    {
        return Revisions::get(Revisions::ROOM_MAP, self::INSTALL, $revision)->furniture_box;
    }

    // ── (i) two intersecting objects, refused naming both — at a SAVE and at a RESTORE ─────────

    public function test_a_save_of_two_intersecting_desk_slots_is_refused_on_the_form_naming_both(): void
    {
        $this->provisionTheRoom();

        $this->actingAs($this->operator())
            ->post(route('admin.floors.store'), ['install_id' => self::INSTALL, 'map' => FloorMapFixture::intersectingDesks()])
            ->assertRedirect()
            ->assertSessionHasErrors('map');

        $refusal = (string) session('errors')->first('map');
        $this->assertStringContainsString('Desk slots id 1 and id 2 intersect', $refusal);
        $this->assertStringNotContainsString('id 3', $refusal, 'a slot that shares only an edge was named as intersecting');

        // § 6.11: a refused save writes nothing.
        $this->assertNull(Floors::forInstall(self::INSTALL));
        $this->assertSame(0, Revisions::history(Revisions::ROOM_MAP, self::INSTALL)->count());

        // THE CONTROL: the map one pixel away — `id 2` sharing `id 1`'s edge rather than a pixel
        // column — is accepted, and its revision records the box it was validated against.
        $this->actingAs($this->operator())
            ->post(route('admin.floors.store'), ['install_id' => self::INSTALL, 'map' => FloorMapFixture::valid()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) Floors::forInstall(self::INSTALL)->map_version);
        $this->assertSame(FurnitureBox::current()->signature(), $this->recordedBox(1));

        // AND MIRRORED: the same slots laid right to left, so each shared edge is met from the
        // other side of the half-open test — a closed interval on either axis direction reds here.
        $mirrored = FloorMapFixture::decoded(3);
        $objects = $mirrored['layers'][1]['objects'];
        foreach ($objects as $i => $object) {
            $mirrored['layers'][1]['objects'][$i]['x'] = $objects[count($objects) - 1 - $i]['x'];
        }
        $this->assertSame(2, $this->save(FloorMapFixture::encode($mirrored)));

        // AND STACKED, both ways up: two slots sharing a horizontal edge, for the y half of the test.
        $box = FurnitureBox::current();
        $high = (int) ceil(2 * $box->height / 32);
        foreach ([[0, $box->height], [$box->height, 0]] as $revision => [$first, $second]) {
            $stacked = FloorMapFixture::decoded(2);
            $stacked['height'] = $high;
            $stacked['layers'][0]['height'] = $high;
            $stacked['layers'][0]['data'] = array_fill(0, $stacked['width'] * $high, 1);
            $stacked['layers'][1]['objects'][0]['y'] = $first;
            $stacked['layers'][1]['objects'][1]['x'] = 0;
            $stacked['layers'][1]['objects'][1]['y'] = $second;
            $this->assertSame($revision + 3, $this->save(FloorMapFixture::encode($stacked)));
        }
    }

    public function test_a_restore_of_two_intersecting_desk_slots_is_refused_naming_both(): void
    {
        // The legacy revision is OLDER than what is current — a map authored before the refusal
        // existed and edited since, which is what a restore reaches back for.
        $this->provisionTheRoom();
        $legacy = $this->plantALegacyRevision(FloorMapFixture::intersectingDesks());
        $this->save(FloorMapFixture::valid(12));
        $this->save(FloorMapFixture::valid(8));

        $this->actingAs($this->operator())
            ->post(route('admin.floors.restore', [self::INSTALL, $legacy]))
            ->assertRedirect(route('admin.floors.revisions', self::INSTALL))
            ->assertSessionHasErrors('revision');

        $this->assertStringContainsString('Desk slots id 1 and id 2 intersect', (string) session('errors')->first('revision'));

        // Nothing moved: revision 3 is still current and no revision was written.
        $this->assertSame(3, (int) Floors::forInstall(self::INSTALL)->map_version);
        $this->assertSame(3, Revisions::history(Revisions::ROOM_MAP, self::INSTALL)->count());

        // THE CONTROL: restoring a passing revision on the same room is accepted.
        $this->assertSame(4, Floors::restore(self::INSTALL, 2, self::OPERATOR));
        $this->assertSame(FurnitureBox::current()->signature(), $this->recordedBox(4));
    }

    // ── (ii) an object smaller than the box, refused — at a SAVE and at a RESTORE ─────────────

    public function test_a_save_of_a_desk_slot_smaller_than_the_box_is_refused_naming_it(): void
    {
        $this->provisionTheRoom();
        $box = FurnitureBox::current();

        $this->actingAs($this->operator())
            ->post(route('admin.floors.store'), ['install_id' => self::INSTALL, 'map' => FloorMapFixture::undersizedDesk()])
            ->assertRedirect()
            ->assertSessionHasErrors('map');

        $this->assertStringContainsString(
            sprintf('Desk slot id 1 is %d × %d pixels, smaller than the furniture box of %d × %d', $box->width - 1, $box->height, $box->width, $box->height),
            (string) session('errors')->first('map'),
        );
        $this->assertNull(Floors::forInstall(self::INSTALL));
        $this->assertSame(0, Revisions::history(Revisions::ROOM_MAP, self::INSTALL)->count());

        // The other dimension: `id 1` one pixel SHORT of the box, and as wide as it.
        $short = FloorMapFixture::decoded();
        $short['layers'][1]['objects'][0]['height'] -= 1;

        try {
            $this->save(FloorMapFixture::encode($short));
            $this->fail('a slot one pixel shorter than the box was saved');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString(sprintf('Desk slot id 1 is %d × %d pixels', $box->width, $box->height - 1), $e->getMessage());
        }

        // THE CONTROL: the same map with `id 1` exactly the box is accepted.
        $this->assertSame(1, $this->save(FloorMapFixture::valid()));
    }

    public function test_a_restore_of_a_desk_slot_smaller_than_the_box_is_refused_naming_it(): void
    {
        $this->provisionTheRoom();
        $legacy = $this->plantALegacyRevision(FloorMapFixture::undersizedDesk());
        $passing = $this->plantALegacyRevision(FloorMapFixture::valid(8));
        $this->save(FloorMapFixture::valid(12));

        try {
            Floors::restore(self::INSTALL, $legacy, self::OPERATOR);
            $this->fail('a revision holding a slot smaller than the box was restored');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString('Desk slot id 1 is', $e->getMessage());
            $this->assertStringContainsString('smaller than the furniture box', $e->getMessage());
        }

        $this->assertSame(3, (int) Floors::forInstall(self::INSTALL)->map_version);
        $this->assertSame(3, Revisions::history(Revisions::ROOM_MAP, self::INSTALL)->count());

        // THE CONTROL: a passing legacy revision — stored with no recorded box, like the refused
        // one — is restored, and the restore records the box it was validated against today.
        $this->assertSame(4, Floors::restore(self::INSTALL, $passing, self::OPERATOR));
        $this->assertSame(FurnitureBox::current()->signature(), $this->recordedBox(4));
    }

    // ── (iii) a change of the box: LISTED on the room index, never refused ───────────────────

    public function test_a_room_whose_stored_map_fails_a_changed_box_is_listed_on_the_index_and_not_refused(): void
    {
        $this->provisionTheRoom();
        $before = FurnitureBox::current();
        $document = FloorMapFixture::valid(2);
        $this->assertSame(1, $this->save($document));

        // THE CONTROL first: under the box it was validated against, the room is not listed.
        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertDontSee('rooms-failing-the-box')
            ->assertDontSee('fails the furniture box');

        // The box grows — art that moves the box, § 14 item 28(1)(iii)'s event.
        $after = $this->changeTheBoxTo($before->width + 40, $before->height);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('rooms-failing-the-box')
            ->assertSee('fails the furniture box')
            ->assertSee(sprintf('smaller than the furniture box of %d × %d', $after->width, $after->height));

        // ⛔ NOT REFUSED: the map is still current, still the same bytes, and still served to the
        // floor — the page draws it under F21's notice until its author saves a passing one.
        $this->assertSame(1, (int) Floors::forInstall(self::INSTALL)->map_version);
        $this->actingAs($this->operator())
            ->getJson('/api/building/rooms/'.self::INSTALL.'/map')
            ->assertOk()
            ->assertJsonPath('map_version', 1);

        // A WRITE is held to the new box, though: saving the old map again is refused…
        try {
            $this->save(FloorMapFixture::valid(3));
            $this->fail('a map smaller than the new box was saved');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString('smaller than the furniture box of '.$after->width, $e->getMessage());
        }

        // …and the author's passing save takes the room off the list, recording the new box.
        $this->assertSame(2, $this->save($this->mapAt($after, 2)));
        $this->assertSame($after->signature(), $this->recordedBox(2));

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertDontSee('rooms-failing-the-box');
    }

    public function test_a_map_stored_before_the_console_recorded_a_box_is_re_validated_and_never_assumed_passing(): void
    {
        // The pre-existing-revision decision: `furniture_box` NULL is *validated against no recorded
        // box*, which differs from every signature, so the map is judged on the index today.
        $this->provisionTheRoom();
        $this->plantALegacyCurrentMap(FloorMapFixture::undersizedDesk());

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('rooms-failing-the-box')
            ->assertSee('Desk slot id 1 is');

        // Listed, not refused: the room's current map is untouched and still served.
        $this->actingAs($this->operator())
            ->getJson('/api/building/rooms/'.self::INSTALL.'/map')
            ->assertOk();
    }

    public function test_a_map_stored_before_the_console_recorded_a_box_that_passes_it_is_not_listed(): void
    {
        // THE CONTROL for the arm above: a NULL signature is a reason to JUDGE the map, never a
        // finding — a legacy map that passes today's box is not listed.
        $this->provisionTheRoom();
        $this->plantALegacyCurrentMap(FloorMapFixture::valid(4));

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertDontSee('rooms-failing-the-box');
    }

    public function test_a_map_validated_against_the_current_box_is_not_judged_a_second_time(): void
    {
        // The detection rule, pinned: a box change IS the current signature differing from the one
        // the room's current revision records. A revision that records THIS box passed it at its
        // write, so the index trusts that and does not re-parse it — the planted row below is one
        // no writer can produce (a failing map recorded as validated), used only to show that the
        // signature, and nothing else, is what sends a map to be re-judged.
        $this->provisionTheRoom();
        $this->plantALegacyCurrentMap(FloorMapFixture::undersizedDesk(), FurnitureBox::current()->signature());

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertDontSee('rooms-failing-the-box');

        // …and the same row with the signature of another box IS re-judged and listed.
        DB::table('authored_revisions')->where('subject', self::INSTALL)->update(['furniture_box' => '1x1']);

        $this->actingAs($this->operator())
            ->get(route('admin.floors.index'))
            ->assertOk()
            ->assertSee('rooms-failing-the-box');
    }
}
