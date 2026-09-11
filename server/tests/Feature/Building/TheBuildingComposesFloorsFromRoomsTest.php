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
    /** @return list<array{name: string, layout: list<array<string, string>>, installs: list<string>, floors: list<array<string, mixed>>}> */
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

        $this->assertGreaterThan(5, count($cases));
        $this->assertSame($names, array_unique($names), 'two fixture cases share a name');
        $this->assertContains(true, array_map(
            fn (array $c) => $c['layout'] !== [] && count($c['installs']) > count(array_merge(...array_map('array_keys', $c['layout']))),
            $cases,
        ), 'no case has an install the layout does not place');
        $this->assertContains(true, array_map(
            fn (array $c) => count(array_merge(...array_map('array_keys', $c['layout'] ?: [[]]))) > count($c['installs']),
            $cases,
        ), 'no case has a room the fleet does not report');
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
        $placed = Building::compose(BuildingLayout::parse(['floors' => [['sola' => 'office', 'zeta' => 'office']]]), ['sola', 'zeta']);
        $unplaced = Building::compose(BuildingLayout::parse(['floors' => []]), ['sola', 'zeta']);

        $rooms = fn (array $b) => array_merge(...array_map(
            fn ($f) => array_column($f['rooms'], 'install'),
            $b,
        ));

        $this->assertSame(['sola', 'zeta'], $rooms($placed));
        $this->assertSame(['sola', 'zeta'], $rooms($unplaced));
        $this->assertNotSame(array_column($placed, 'floor'), array_column($unplaced, 'floor'));
    }
}
