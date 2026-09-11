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
 * ⭐ THE TWO RULES THIS FILE EXISTS FOR ARE BOTH ABOUT A HOLE, and they point in opposite
 * directions, which is why neither one covers the other:
 *
 *   AN INSTALL THE LAYOUT DOES NOT PLACE still gets a floor — so a newly-provisioned install
 *   renders without a deploy, and the layout is a DEPARTURE from a default rather than an
 *   enumeration anything depends on being complete.
 *
 *   A ROOM THE FLEET REPORTS NOTHING FOR is still drawn — so a floor is never silently narrower
 *   than the operator authored it.
 *
 * Between them, no install and no authored room can fall off the building; § 4.6 refuses the
 * *nothing is happening* render at building scale exactly as § 0 item 6 refuses it at a desk's.
 */
class TheBuildingComposesFloorsFromRoomsTest extends TestCase
{
    /** @param array<string, array<string, string>> $floors */
    private function compose(array $floors, array $installs): array
    {
        return Building::compose(BuildingLayout::parse(['floors' => $floors]), $installs);
    }

    public function test_two_rooms_composed_onto_one_floor_are_one_floor(): void
    {
        // The operator's own case, verbatim: "a floor could hold multiple solo agents. In the
        // latter case, the floor would be divided into a hallway with separate offices".
        // Two installs, two channels, ONE screen.
        $building = $this->compose(
            ['sola' => ['zeta' => 'office', 'sola' => 'office']],
            ['sola', 'zeta'],
        );

        $this->assertSame(['sola'], array_column($building, 'floor'));
        // § 2.1 row 6: a floor's rooms by `install_id` ascending — a pure function of the set, so
        // the document's own key order (`zeta` first, above) does not reach the screen.
        $this->assertSame(['sola', 'zeta'], array_column($building[0]['rooms'], 'install'));
        $this->assertSame(['office', 'office'], array_column($building[0]['rooms'], 'form'));
    }

    public function test_a_floor_may_mix_a_composed_floor_with_a_room_that_is_its_own(): void
    {
        // The operator's other case, verbatim: a floor "could be 1 large room (PM+impl agent) and
        // n small offices (each holding a solo agent)". Here the large room is its own floor and
        // the offices share one — two floors of different types, which is what the ruling asked
        // for.
        $building = $this->compose(
            [
                'aimla' => ['aimla' => 'open'],
                'sola' => ['sola' => 'office', 'zeta' => 'office'],
            ],
            ['aimla', 'sola', 'zeta'],
        );

        $this->assertSame(['aimla', 'sola'], array_column($building, 'floor'));
        $this->assertSame(['aimla'], array_column($building[0]['rooms'], 'install'));
        $this->assertSame(['sola', 'zeta'], array_column($building[1]['rooms'], 'install'));
    }

    public function test_an_install_the_layout_does_not_place_gets_a_floor_of_its_own_open(): void
    {
        $building = $this->compose(['sola' => ['sola' => 'office', 'zeta' => 'office']], ['sola', 'zeta', 'aimla']);

        $this->assertSame(['aimla', 'sola'], array_column($building, 'floor'));
        $this->assertSame(
            [['install' => 'aimla', 'form' => 'open', 'placed' => false, 'reported' => true]],
            $building[0]['rooms'],
        );
    }

    public function test_a_room_the_fleet_reports_nothing_for_is_drawn_and_flagged_never_omitted(): void
    {
        // `zeta` is authored onto the floor and reports nothing. The floor still has both rooms,
        // and the one that reports nothing SAYS so — the fact is `reported: false`; the words
        // (§ 4.6's "no seats reported for this room") belong to whatever draws it.
        $building = $this->compose(['sola' => ['sola' => 'office', 'zeta' => 'office']], ['sola']);

        $this->assertSame(['sola', 'zeta'], array_column($building[0]['rooms'], 'install'));
        $this->assertSame([true, false], array_column($building[0]['rooms'], 'reported'));

        // THE CONTROL, and it is what makes the assertion above about the RULE rather than about
        // an array shape: report `zeta` and the same floor's same room flips to reported.
        $withZeta = $this->compose(['sola' => ['sola' => 'office', 'zeta' => 'office']], ['sola', 'zeta']);
        $this->assertSame([true, true], array_column($withZeta[0]['rooms'], 'reported'));
    }

    public function test_a_floor_all_of_whose_rooms_report_nothing_is_still_a_floor(): void
    {
        // The strongest form of the rule: the whole floor is authored and the fleet reports none
        // of it. Dropping it would answer "where did my floor go" with nothing at all.
        $building = $this->compose(['sola' => ['sola' => 'office', 'zeta' => 'office']], []);

        $this->assertSame(['sola'], array_column($building, 'floor'));
        $this->assertSame([false, false], array_column($building[0]['rooms'], 'reported'));
    }

    public function test_the_building_is_stacked_by_floor_id_ascending(): void
    {
        // § 2.1 row 6 / § 4.1: an ascending order over floor ids, and it is a pure function of the
        // composed set — NOT the layout's authoring order, which § 4.1 declines to ratify as a
        // stack direction and which an implicit floor has none of anyway.
        $building = $this->compose(
            ['zeta' => ['zeta' => 'open'], 'aimla' => ['aimla' => 'open']],
            ['zeta', 'aimla', 'mira'],
        );

        $this->assertSame(['aimla', 'mira', 'zeta'], array_column($building, 'floor'));
    }

    public function test_composing_a_floor_never_changes_which_channel_a_room_is(): void
    {
        // ⛔ § 4.6: the layout subscribes to nothing, fetches nothing and names no seat. The
        // room's `install_id` — which IS the channel, the snapshot grouping and the ACL point —
        // is carried through untouched whether the room is placed or not, and the composed floor
        // is the ONLY thing that differs between the two arms below.
        $placed = $this->compose(['sola' => ['sola' => 'office', 'zeta' => 'office']], ['sola', 'zeta']);
        $unplaced = $this->compose([], ['sola', 'zeta']);

        $rooms = fn (array $b) => array_merge(...array_map(
            fn ($f) => array_column($f['rooms'], 'install'),
            $b,
        ));

        $this->assertSame(['sola', 'zeta'], $rooms($placed));
        $this->assertSame(['sola', 'zeta'], $rooms($unplaced));
        $this->assertNotSame(array_column($placed, 'floor'), array_column($unplaced, 'floor'));
    }
}
