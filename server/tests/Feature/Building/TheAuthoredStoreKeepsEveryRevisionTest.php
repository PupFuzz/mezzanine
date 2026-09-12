<?php

namespace Tests\Feature\Building;

use App\Building\BuildingChanged;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\InvalidFloorMap;
use App\Sweep\Purge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\TestCase;

/**
 * ⭐ THE ACCEPTANCE TEST `docs/design/FLOOR.md` Appendix B row 11 OWES, in its own words: **"a save
 * is one revision, a restore is a forward revision, a removal is retrievable, and a byte-identical
 * save is refused"** — `docs/design/FLEET-STATE.md § 6.11`'s store, card#9208's reversal.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS FILE IS FOR, AND WHY IT IS NOT THE CONSOLE'S SUITE. § 6.11 gives back three of the
 * four things version control lost — revert, blame and diff — and every one of them is a property
 * of the STORE rather than of a page: history is append-only, a restore writes forward, a removal
 * is a revision with a `document NULL`, and the current row is a projection of the log. A test
 * that drove the forms would prove the buttons exist; this proves the properties they rest on.
 *
 * ⛔ AND THE OVERLAP IS ASSERTED AT BOTH WRITE SITES, because § 6.11 says in terms which one an
 * implementer misses: "a room map's save, restore or removal changes the room's extent, so on a
 * planned floor it is checked against the current layout in the same transaction". A suite that
 * only checked the layout's save would be green against exactly that defect.
 */
class TheAuthoredStoreKeepsEveryRevisionTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'ops@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // The publish seam records what a committed write announced (card#9287 owns the transport
        // that will consume it). It is process-wide, so each test starts with nothing announced.
        BuildingChanged::forget();
    }

    /** @param list<array<string, mixed>> $floors */
    private function compose(array $floors): int
    {
        return Layouts::save((string) json_encode(['floors' => $floors], JSON_PRETTY_PRINT), self::OPERATOR);
    }

    private function save(string $installId, string $document): int
    {
        return Floors::save($installId, FloorMap::parse($document), self::OPERATOR);
    }

    // ── a SAVE is one revision, and the current row is a projection of it ────────────────────

    public function test_a_save_writes_one_revision_and_points_the_current_row_at_it(): void
    {
        $first = $this->save('aimla', FloorMapFixture::valid(12));
        $second = $this->save('aimla', FloorMapFixture::valid(8));

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame(2, (int) Floors::forInstall('aimla')->map_version);

        // § 6.11: revisions are numbered per `(kind, subject)` and never reused.
        $this->assertSame([2, 1], Revisions::history(Revisions::ROOM_MAP, 'aimla')->pluck('revision')->map(intval(...))->all());

        // The current row's bytes ARE the current revision's — the row is a projection of the log.
        $this->assertSame(
            Revisions::get(Revisions::ROOM_MAP, 'aimla', 2)->document,
            Floors::forInstall('aimla')->map,
        );

        // And the blame: the console session's user, on every revision (§ 6.11's blame row).
        $this->assertSame(
            [self::OPERATOR, self::OPERATOR],
            Revisions::history(Revisions::ROOM_MAP, 'aimla')->pluck('authored_by')->all(),
        );
    }

    public function test_a_byte_identical_save_is_refused_as_a_no_op_rather_than_minting_an_empty_revision(): void
    {
        $this->save('aimla', FloorMapFixture::valid(12));

        try {
            $this->save('aimla', FloorMapFixture::valid(12));
            $this->fail('a byte-identical save was recorded as a revision');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString('byte for byte revision 1', $e->getMessage());
        }

        $this->assertSame(1, Revisions::history(Revisions::ROOM_MAP, 'aimla')->count());

        // THE CONTROL: one byte different and it IS a revision — without this the arm above would
        // pass against a store that refused every save.
        $this->assertSame(2, $this->save('aimla', FloorMapFixture::valid(11)));
    }

    // ── a RESTORE is a forward revision, and a REMOVAL is retrievable ────────────────────────

    public function test_a_restore_is_a_forward_revision_that_rewrites_no_history(): void
    {
        $this->save('aimla', FloorMapFixture::valid(12));
        $this->save('aimla', FloorMapFixture::valid(8));

        $restored = Floors::restore('aimla', 1, self::OPERATOR);

        $this->assertSame(3, $restored);
        $this->assertSame(3, (int) Floors::forInstall('aimla')->map_version);

        $three = Revisions::get(Revisions::ROOM_MAP, 'aimla', 3);

        $this->assertSame(1, (int) $three->restored_from);
        $this->assertSame(FloorMapFixture::valid(12), $three->document);

        // Nothing was rewritten: revision 2 is still exactly what it was, so *undo the restore*
        // is itself a restore (§ 6.11).
        $this->assertSame(FloorMapFixture::valid(8), Revisions::get(Revisions::ROOM_MAP, 'aimla', 2)->document);
        $this->assertSame(4, Floors::restore('aimla', 2, self::OPERATOR));
    }

    public function test_a_removal_deletes_the_row_and_records_a_revision_the_map_can_be_restored_from(): void
    {
        $this->save('aimla', FloorMapFixture::valid(12));

        $this->assertTrue(Floors::remove('aimla', self::OPERATOR));

        // § 6.11: "`floors` holds only rooms with an authored map current, and the revision log is
        // the history" — so the row is gone and the removal is a revision with a NULL document.
        $this->assertNull(Floors::forInstall('aimla'));
        $this->assertNull(Revisions::get(Revisions::ROOM_MAP, 'aimla', 2)->document);

        // ⭐ RETRIEVABLE, which is the claim the whole design rests on: the map that was removed
        // comes back as a forward revision, and the room has a current row again.
        $this->assertSame(3, Floors::restore('aimla', 1, self::OPERATOR));
        $this->assertSame(FloorMapFixture::valid(12), Floors::forInstall('aimla')->map);
    }

    public function test_restoring_a_removal_removes_the_map_again_and_says_which_revision_did_it(): void
    {
        $this->save('aimla', FloorMapFixture::valid(12));
        Floors::remove('aimla', self::OPERATOR);
        Floors::restore('aimla', 1, self::OPERATOR);

        // Revision 2 IS a removal, so restoring it is a removal — "undo the restore is itself a
        // restore" with no special case for the document that happens to be NULL.
        $this->assertNull(Floors::restore('aimla', 2, self::OPERATOR));
        $this->assertNull(Floors::forInstall('aimla'));
        $this->assertSame(2, (int) Revisions::get(Revisions::ROOM_MAP, 'aimla', 4)->restored_from);
    }

    public function test_removing_a_room_that_has_no_map_writes_nothing_at_all(): void
    {
        $this->assertFalse(Floors::remove('aimla', self::OPERATOR));
        $this->assertSame(0, Revisions::history(Revisions::ROOM_MAP, 'aimla')->count());
        $this->assertSame([], BuildingChanged::announced());
    }

    // ── the LAYOUT: the same rules, the other kind ───────────────────────────────────────────

    public function test_no_layout_row_is_todays_building_rather_than_a_missing_one(): void
    {
        // § 8.7: "`layout_version` is `0` with an empty `floors` when no layout was ever saved:
        // today's building, one floor per install." Nothing seeds a row to say that — a seeded
        // revision 1 would claim an operator pressed save.
        $this->assertSame(0, Layouts::version());
        $this->assertSame(['floors' => []], Layouts::document());
        $this->assertSame([], Layouts::layout()->floors);
        $this->assertNull(Layouts::current());
    }

    public function test_a_layout_save_is_a_revision_and_a_byte_identical_one_is_refused(): void
    {
        $document = (string) json_encode(['floors' => [['rooms' => ['sola' => ['form' => 'office']]]]], JSON_PRETTY_PRINT);

        $this->assertSame(1, Layouts::save($document, self::OPERATOR));
        $this->assertSame(1, Layouts::version());
        $this->assertSame($document, Layouts::documentText());

        try {
            Layouts::save($document, self::OPERATOR);
            $this->fail('a byte-identical layout save was recorded as a revision');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('byte for byte the one that is already current', $e->getMessage());
        }

        $this->assertSame(1, Revisions::history(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT)->count());
    }

    public function test_a_layout_revision_is_subjected_by_the_empty_string_so_it_can_never_name_a_room(): void
    {
        // § 6.4's DDL comment carries the argument: D1 § 3.1's slug is at least two characters
        // long, so no `install_id` can be `''`. Asserted rather than trusted, because the two
        // kinds share one table and one unique key.
        $this->compose([['rooms' => ['sola' => ['form' => 'office']]]]);
        $this->save('sola', FloorMapFixture::valid(4));

        $subjects = DB::table('authored_revisions')->orderBy('id')->pluck('subject', 'kind');

        $this->assertSame('', $subjects[Revisions::LAYOUT]);
        $this->assertSame('sola', $subjects[Revisions::ROOM_MAP]);
    }

    public function test_a_layout_restore_is_a_forward_revision_too(): void
    {
        $this->compose([['rooms' => ['sola' => ['form' => 'office']]]]);
        $this->compose([['rooms' => ['sola' => ['form' => 'open']]]]);

        $this->assertSame(3, Layouts::restore(1, self::OPERATOR));
        $this->assertSame(1, (int) Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, 3)->restored_from);
        $this->assertSame('office', Layouts::layout()->floors[0]['rooms'][0]['form']);
    }

    public function test_restoring_a_layout_revision_that_is_already_current_is_refused_as_a_no_op(): void
    {
        $this->compose([['rooms' => ['sola' => ['form' => 'office']]]]);

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('restoring it would change nothing');

        Layouts::restore(1, self::OPERATOR);
    }

    // ── ⭐ card#9292's OVERLAP, at BOTH of § 6.11's write sites ──────────────────────────────

    /** Two rooms side by side on a planned floor, each 10 × 8 tiles of 32 px — 320 × 256. */
    private function twoRoomsSideBySide(): void
    {
        $this->save('sola', FloorMapFixture::sized(10, 8));
        $this->save('zeta', FloorMapFixture::sized(10, 8));

        $this->compose([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 320, 'y' => 0]],
        ]]]);
    }

    public function test_the_layouts_own_save_refuses_a_plan_whose_rooms_would_share_pixels(): void
    {
        // THE CONTROL first: the two rooms share an EDGE at x = 320 and the save is accepted —
        // § 4.6's "one wall between them", which a closed-interval check would refuse.
        $this->twoRoomsSideBySide();

        $this->assertSame(1, Layouts::version());

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('Rooms `sola` and `zeta` would share pixels');

        $this->compose([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 319, 'y' => 0]],
        ]]]);
    }

    public function test_a_room_maps_SAVE_is_refused_when_the_bigger_room_would_overlap_its_neighbour(): void
    {
        // ⛔ THE SECOND WRITE SITE — § 6.11 names it as the one an implementer misses. Nothing
        // about the LAYOUT changes here: the operator makes a room's map wider, and the room's
        // extent is its map's grid (§ 4.6 rule 1), so the plan that fitted no longer does.
        $this->twoRoomsSideBySide();

        try {
            $this->save('sola', FloorMapFixture::sized(11, 8));
            $this->fail('a map that grew into its neighbour was stored');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('would share pixels', $e->getMessage());
        }

        // § 6.11: "a save that fails validation writes nothing and publishes nothing".
        $this->assertSame(1, (int) Floors::forInstall('sola')->map_version);
        $this->assertSame(1, Revisions::history(Revisions::ROOM_MAP, 'sola')->count());

        // THE CONTROL: the same save on the same room, one tile SMALLER, is accepted — so the
        // refusal above is about the geometry and not about the write path being broken.
        $this->assertSame(2, $this->save('sola', FloorMapFixture::sized(9, 8)));
    }

    public function test_a_room_maps_RESTORE_is_refused_when_the_restored_extent_would_overlap(): void
    {
        $this->twoRoomsSideBySide();

        // Shrink `sola`, then move `zeta` into the space that freed up. Restoring `sola`'s
        // revision 1 would now put it back on top of `zeta` — a document that was valid when it
        // was written and is not valid now, which is exactly what a restore has to re-check.
        $this->save('sola', FloorMapFixture::sized(5, 8));
        $this->compose([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 160, 'y' => 0]],
        ]]]);

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('would share pixels');

        Floors::restore('sola', 1, self::OPERATOR);
    }

    public function test_a_room_maps_REMOVAL_is_refused_when_the_default_it_falls_back_to_cannot_be_read(): void
    {
        // ⛔ THE THIRD WRITE SITE, and the one whose extent change is least obvious: a removal puts
        // the room back on the SHIPPED DEFAULT (§ 8.7), whose grid may be larger than the map it
        // replaces. On this repository the default is not vendored yet (Appendix B step 7), so the
        // refusal names the file rather than guessing a size — which is the honest answer and the
        // one that changes to a real overlap check the day the default lands.
        $this->twoRoomsSideBySide();

        try {
            Floors::remove('sola', self::OPERATOR);
            $this->fail('a removal changed a placed room\'s extent without checking it');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('resources/floor/default.tmj', $e->getMessage());
        }

        $this->assertNotNull(Floors::forInstall('sola'), 'the refused removal still deleted the row');
    }

    public function test_a_room_on_an_UNPLANNED_floor_is_removed_without_any_of_that(): void
    {
        // THE CONTROL for the arm above: the same removal, on a floor with no plan, is accepted —
        // § 4.6's default arrangement claims no position, so there is nothing to collide with and
        // no extent to resolve. Without this arm the refusal above would pass against a store that
        // refused every removal.
        $this->save('sola', FloorMapFixture::sized(10, 8));
        $this->compose([['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]);

        $this->assertTrue(Floors::remove('sola', self::OPERATOR));
        $this->assertNull(Floors::forInstall('sola'));
    }

    public function test_a_plan_that_places_a_room_with_no_map_is_refused_by_name_while_no_default_is_shipped(): void
    {
        // The build-order fact § 4.6 assumed away: step 11 landed before step 7's shipped default,
        // so an unauthored room has no grid to measure. Refusing by name is the alternative to
        // inventing one, and the message carries both ways out.
        $this->save('sola', FloorMapFixture::sized(10, 8));

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('has no authored map');

        $this->compose([['rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 320, 'y' => 0]],
        ]]]);
    }

    public function test_the_sweeper_purges_none_of_the_three_tables_however_old_a_revision_gets(): void
    {
        // ⛔ § 6.7: these tables are "purged by NOTHING" — "a row is written by an operator's save
        // in the admin console and by nothing else, so the population is the number of times a
        // person pressed save — and a revision purged is exactly the prior layout the recovery
        // rule exists to keep retrievable". The sweeper's plan is a private constant, so this is
        // asserted by RUNNING a pass rather than by reading it: a later card adding one of these
        // tables to that plan is the edit this arm exists to red, and it would not touch this file.
        $this->save('aimla', FloorMapFixture::valid(12));
        $this->compose([['rooms' => ['aimla' => ['form' => 'open']]]]);
        Floors::remove('aimla', self::OPERATOR);

        // Age every authored row far past the retention boundary — the state in which a purge
        // that DID cover them would take them.
        $ancient = '2020-01-01 00:00:00.000';

        DB::table('authored_revisions')->update(['authored_at' => $ancient]);
        DB::table('building_layout')->update(['updated_at' => $ancient]);

        app(Purge::class)->pass(retentionDays: Purge::DEDUP_WINDOW_DAYS);

        $this->assertSame(3, DB::table('authored_revisions')->count());
        $this->assertSame(1, DB::table('building_layout')->count());

        // And the recovery rule still works over the aged rows, which is the property § 6.7 is
        // protecting rather than the row count itself. The room's own history is save (1) and
        // removal (2), so the restore is 3 — the layout's revision is a different subject and
        // numbers separately (§ 6.4's `(kind, subject, revision)` key).
        $this->assertSame(3, Floors::restore('aimla', 1, self::OPERATOR));
    }

    // ── the publish seam (card#9287 fills it; § 6.11 says WHEN it fires) ─────────────────────

    public function test_a_committed_write_announces_once_and_a_refused_one_announces_nothing(): void
    {
        // § 6.11: "on commit … publish", and "a save that fails validation writes nothing and
        // publishes nothing". The transport is card#9287's open ruling, so what is asserted is the
        // seam's timing — the property slice 2 will hang a real message on.
        $this->save('aimla', FloorMapFixture::valid(12));

        $announced = BuildingChanged::announced();

        $this->assertCount(1, $announced);
        $this->assertSame(BuildingChanged::ROOM_MAP, $announced[0]['message']);
        $this->assertSame(['install_id' => 'aimla', 'map_version' => 1], [
            'install_id' => $announced[0]['payload']['install_id'],
            'map_version' => $announced[0]['payload']['map_version'],
        ]);

        // § 8.3's `at` is the wire spelling of the instant the REVISION records, not of the moment
        // the message was built — a notification that timestamped itself would disagree with the
        // row it announces.
        $this->assertSame(
            \App\Fold\Clock::wire(Revisions::get(Revisions::ROOM_MAP, 'aimla', 1)->authored_at),
            $announced[0]['payload']['at'],
        );

        BuildingChanged::forget();

        try {
            $this->save('aimla', FloorMapFixture::valid(12));
        } catch (InvalidFloorMap) {
            // the no-op refusal
        }

        $this->assertSame([], BuildingChanged::announced(), 'a refused save announced a change');

        // A removal announces `map_version: null` — § 8.7: the room is back on the shipped default.
        BuildingChanged::forget();
        Floors::remove('aimla', self::OPERATOR);

        $this->assertNull(BuildingChanged::announced()[0]['payload']['map_version']);
    }

    public function test_a_layout_save_announces_its_new_version(): void
    {
        $this->compose([['rooms' => ['sola' => ['form' => 'office']]]]);

        $this->assertSame(
            [['message' => BuildingChanged::LAYOUT, 'payload' => ['layout_version' => 1]]],
            BuildingChanged::announced(),
        );
    }
}
