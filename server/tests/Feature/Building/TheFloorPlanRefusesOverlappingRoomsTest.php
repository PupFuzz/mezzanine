<?php

namespace Tests\Feature\Building;

use App\Building\BuildingLayout;
use App\Building\FloorPlan;
use App\Building\InvalidBuildingLayout;
use Tests\TestCase;

/**
 * ⭐ card#9292's one geometric rule — `docs/design/FLOOR.md § 4.6`: **two rooms on one floor whose
 * footprints would intersect are refused by name, naming both** — and its edge case, which is the
 * half a closed-interval implementation gets wrong: **a shared edge is not an intersection.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CONTROL IS THE POINT OF THIS FILE, not a courtesy. § 4.6's reason for the half-open
 * rectangle is that "*a floor subdivided into two rooms* is naturally drawn with one wall between
 * them" — so a check that refused touching rooms would refuse exactly the building the operator
 * asked for, and it would do it while looking like a working overlap check. Every refusal below
 * has a control one pixel away from it.
 *
 * ⚠ THE EXTENTS ARE PASSED IN, which is what makes this suite able to run at all on a host with no
 * database: the resolution — authored map, else the shipped default — is
 * `App\Building\RoomExtents`'s and is exercised by the store's own suite.
 */
class TheFloorPlanRefusesOverlappingRoomsTest extends TestCase
{
    /** @param array<string, array{x: int, y: int}> $origins */
    private function floors(array $origins): array
    {
        return BuildingLayout::parse(['floors' => [['rooms' => array_map(
            fn (array $origin) => ['form' => 'office', 'origin' => $origin],
            $origins,
        )]]])->floors;
    }

    public function test_two_rooms_sharing_an_edge_are_accepted_because_that_is_one_wall_between_them(): void
    {
        // ⛔ THE CONTROL. `sola` covers [0, 256) and `zeta` starts at 256: they touch and share no
        // pixel. § 4.6: "two rooms may share an EDGE and may never share a pixel".
        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 0], 'zeta' => ['x' => 256, 'y' => 0]]),
            ['sola' => ['width' => 256, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );

        // And the vertical case, which a check that compared only one axis would pass for free.
        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 0], 'zeta' => ['x' => 0, 'y' => 192]]),
            ['sola' => ['width' => 256, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );

        // ⛔ AND BOTH MIRRORED, which is not symmetry for its own sake: the comparison has FOUR
        // clauses and the rooms are compared in `install_id` order, so a pair with the lower id on
        // the left exercises two of them and leaves the other two unmeasured. Measured, rather
        // than reasoned: with only the two arms above, planting `<=` in the first clause left this
        // whole file GREEN. Each arm below is the mutation that survived.
        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 256, 'y' => 0], 'zeta' => ['x' => 0, 'y' => 0]]),
            ['sola' => ['width' => 256, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );

        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 192], 'zeta' => ['x' => 0, 'y' => 0]]),
            ['sola' => ['width' => 256, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );

        $this->addToAssertionCount(4);
    }

    public function test_two_rooms_sharing_one_pixel_are_refused_naming_both_and_their_footprints(): void
    {
        // ONE pixel of overlap — the same document as the control with `zeta` moved left by 1.
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('Rooms `sola` and `zeta` would share pixels on floor `sola`');

        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 0], 'zeta' => ['x' => 255, 'y' => 0]]),
            ['sola' => ['width' => 256, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );
    }

    public function test_a_room_whose_map_grew_into_its_neighbour_is_refused_by_the_same_rule(): void
    {
        // § 4.6 rule 1's stated cost: "enlarging a room can collide with a neighbour, and the
        // write that would do it is refused by name rather than clipped". Same origins as the
        // control; `sola`'s EXTENT is what moved, which is the room-map write site.
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('covers 0–288');

        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 0], 'zeta' => ['x' => 256, 'y' => 0]]),
            ['sola' => ['width' => 288, 'height' => 192], 'zeta' => ['width' => 256, 'height' => 192]],
        );
    }

    public function test_rooms_on_DIFFERENT_floors_never_overlap_however_they_are_placed(): void
    {
        // The floor is the coordinate space (§ 4.6: "in the floor's pixel space"), so two rooms at
        // the same origin on two floors are two rooms in two buildings-worth of space. A check
        // that compared every room to every other would refuse this, and it is the ordinary case
        // of a building whose floors were each planned on their own.
        $floors = BuildingLayout::parse(['floors' => [
            ['rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]]],
            ['rooms' => ['zeta' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]]],
        ]])->floors;

        FloorPlan::refuseOverlaps($floors, [
            'sola' => ['width' => 256, 'height' => 192],
            'zeta' => ['width' => 256, 'height' => 192],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_an_unplanned_floor_is_not_checked_because_it_claims_no_position(): void
    {
        // § 4.6's default arrangement is the client's, and it cannot overlap by construction. A
        // check that demanded extents here would refuse every building that has no plan — which
        // is every building today.
        FloorPlan::refuseOverlaps(
            BuildingLayout::parse(['floors' => [['rooms' => [
                'sola' => ['form' => 'office'],
                'zeta' => ['form' => 'office'],
            ]]]])->floors,
            [],
        );

        $this->assertSame([], FloorPlan::placedRooms(
            BuildingLayout::parse(['floors' => [['rooms' => ['sola' => ['form' => 'office']]]]])->floors,
        ));
    }

    public function test_a_placed_room_with_no_resolved_extent_refuses_rather_than_passing_unmeasured(): void
    {
        // ⛔ THE SILENT-PASS GUARD. A room skipped for want of an extent is a check reporting
        // clean over a room nothing measured — the exact shape that makes an overlap check a
        // decoration. `App\Building\RoomExtents` is what refuses first, by name; this is the
        // backstop for a caller that forgot to ask it.
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('no extent was resolved for it');

        FloorPlan::refuseOverlaps(
            $this->floors(['sola' => ['x' => 0, 'y' => 0], 'zeta' => ['x' => 256, 'y' => 0]]),
            ['sola' => ['width' => 256, 'height' => 192]],
        );
    }

    public function test_placed_rooms_are_exactly_the_rooms_an_extent_is_needed_for(): void
    {
        $floors = BuildingLayout::parse(['floors' => [
            ['rooms' => [
                'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
                'zeta' => ['form' => 'office', 'origin' => ['x' => 256, 'y' => 0]],
            ]],
            ['rooms' => ['mira' => ['form' => 'open'], 'nova' => ['form' => 'open']]],
        ]])->floors;

        // The unplanned floor's rooms are deliberately absent: resolving an extent for them would
        // read a map (and, for an unauthored room, demand the shipped default) for a floor that
        // claims no position at all.
        $this->assertSame(['sola', 'zeta'], FloorPlan::placedRooms($floors));
    }
}
