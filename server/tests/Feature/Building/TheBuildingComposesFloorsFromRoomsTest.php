<?php

namespace Tests\Feature\Building;

use App\Building\Building;
use App\Building\BuildingLayout;
use Tests\TestCase;

/**
 * Composing the building — `docs/design/FLOOR.md § 4.6`. One authored layout plus the installs the
 * fleet reports, in; the floors this deployment draws, out.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ THE CASES ARE A FIXTURE, NOT THIS FILE'S, because the rule has two homes. The browser
 * composes the same floors (`public/js/lobby/lobby-model.js`, `floors()`) — § 4.1's discrepancy
 * check discovers an install after the page was served, and that install still owes a floor — so
 * `tests/fixtures/building/compose-cases.json` is the one statement of the rule and both this
 * suite and `Tests\Feature\Lobby\TheBuildingStacksTheComposedFloorsTest` walk every case in it.
 * A case added there reaches both; a rule one runtime has and the other lacks reds one of them.
 *
 * ⭐ THE TWO RULES THE FIXTURE EXISTS FOR ARE BOTH ABOUT A HOLE, and they point in opposite
 * directions, which is why neither one covers the other: an install the layout does not place
 * still gets a floor, and a room the fleet reports nothing for is still drawn. Between them no
 * install and no authored room can fall off the building.
 */
class TheBuildingComposesFloorsFromRoomsTest extends TestCase
{
    /** @return list<array{name: string, layout: list<array<string, mixed>>, installs: list<string>, floors: list<array<string, mixed>>}> */
    public static function cases(): array
    {
        $path = __DIR__.'/../../fixtures/building/compose-cases.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['cases'];
    }

    public function test_the_fixture_has_cases_and_every_one_names_a_distinct_property(): void
    {
        // The parse control: a fixture that decoded to nothing would make the walk below
        // vacuously green. And the fixture must exercise each hole in at least one direction,
        // stated as the two cases that would be the first to be deleted.
        $cases = self::cases();
        $names = array_column($cases, 'name');

        // A floors entry is a RECORD since card#9273, so the rooms of a case are its entries'
        // `rooms` members and no longer the entries themselves.
        $roomsOf = fn (array $c) => array_merge(...array_map(
            fn (array $floor) => array_keys($floor['rooms']),
            $c['layout'] ?: [['rooms' => []]],
        ));

        $this->assertGreaterThan(5, count($cases));
        $this->assertSame($names, array_unique($names), 'two fixture cases share a name');
        $this->assertContains(true, array_map(
            fn (array $c) => $c['layout'] !== [] && count($c['installs']) > count($roomsOf($c)),
            $cases,
        ), 'no case has an install the layout does not place');
        $this->assertContains(true, array_map(
            fn (array $c) => count($roomsOf($c)) > count($c['installs']),
            $cases,
        ), 'no case has a room the fleet does not report');

        // card#9273: the fixture is the cross-runtime statement of the LABEL too, so it must
        // carry a labelled case and an unlabelled one — without both, a runtime that dropped
        // labels entirely and one that invented them everywhere would each walk it clean.
        $labels = array_merge(...array_map(
            fn (array $c) => array_column($c['floors'], 'label'),
            $cases,
        ));

        $this->assertContains(null, $labels, 'no fixture floor reads as its key');
        $this->assertNotEmpty(array_filter($labels, 'is_string'), 'no fixture floor carries a label');

        // ⭐ card#9292: and of the PLAN. Three properties, because a runtime that dropped `origin`,
        // one that invented it everywhere, and one that paged a `hallway` onto every composed floor
        // would each walk a fixture missing one of these clean.
        $composedRooms = array_merge(...array_map(
            fn (array $c) => array_merge(...array_map(fn (array $f) => $f['rooms'], $c['floors'])),
            $cases,
        ));

        $this->assertNotEmpty(
            array_filter($composedRooms, fn (array $room) => isset($room['origin'])),
            'no fixture room is PLACED, so a runtime that dropped `origin` would walk this clean',
        );
        $this->assertNotEmpty(
            array_filter($composedRooms, fn (array $room) => ! isset($room['origin'])),
            'every fixture room is placed, so a runtime that invented an origin would walk this clean',
        );
        $this->assertContains(
            true,
            array_map(fn (array $c) => array_filter($c['layout'], fn (array $f) => isset($f['hallway'])) !== [], $cases),
            'no fixture layout carries a hallway, so *the composer never carries one* is untested',
        );
    }

    public function test_every_fixture_case_composes_to_exactly_the_floors_it_states(): void
    {
        foreach (self::cases() as $case) {
            $this->assertSame(
                $case['floors'],
                Building::compose(BuildingLayout::parse(['floors' => $case['layout']]), $case['installs']),
                'fixture case: '.$case['name'],
            );
        }
    }

    public function test_composing_a_floor_never_changes_which_channel_a_room_is(): void
    {
        // ⛔ § 4.6: the layout subscribes to nothing, fetches nothing and names no seat. The
        // room's `install_id` — which IS the channel, the snapshot grouping and the ACL point —
        // is carried through untouched whether the room is placed or not, and the composed floor
        // is the ONLY thing that differs between the two arms below.
        $placed = Building::compose(BuildingLayout::parse(['floors' => [['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]]), ['sola', 'zeta']);
        $unplaced = Building::compose(BuildingLayout::parse(['floors' => []]), ['sola', 'zeta']);

        $rooms = fn (array $b) => array_merge(...array_map(
            fn ($f) => array_column($f['rooms'], 'install'),
            $b,
        ));

        $this->assertSame(['sola', 'zeta'], $rooms($placed));
        $this->assertSame(['sola', 'zeta'], $rooms($unplaced));
        $this->assertNotSame(array_column($placed, 'floor'), array_column($unplaced, 'floor'));

        // And an implicit floor has no entry to carry a label, so it reads as its key (card#9273).
        $this->assertSame([null, null], array_column($unplaced, 'label'));
    }

    public function test_a_label_changes_what_a_floor_reads_as_and_nothing_else_about_the_building(): void
    {
        // ⭐ THE GUARANTEE AT THE COMPOSER, card#9273: the same installs against two layouts that
        // differ ONLY in labels compose to the same floors, the same keys and the same rooms. The
        // reader's own copy of this property (`BuildingLayoutTest`) is about the document; this is
        // about the BUILDING, which is what the console and the page actually read.
        $rooms = ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']];
        $installs = ['sola', 'zeta', 'aimla'];

        $plain = Building::compose(BuildingLayout::parse(['floors' => [['rooms' => $rooms]]]), $installs);
        $named = Building::compose(
            BuildingLayout::parse(['floors' => [['label' => 'the solos', 'rooms' => $rooms]]]),
            $installs,
        );

        $this->assertSame(array_column($plain, 'floor'), array_column($named, 'floor'));
        $this->assertSame(array_column($plain, 'rooms'), array_column($named, 'rooms'));

        // The one member that moved — and the implicit `aimla` floor's stayed null, because there
        // is no layout entry to name it.
        $this->assertSame([null, null], array_column($plain, 'label'));
        $this->assertSame([null, 'the solos'], array_column($named, 'label'));
    }
}
