<?php

namespace Tests\Feature\Lobby;

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use Tests\Feature\Feed\FeedTestCase;

/**
 * `docs/design/FLOOR.md § 4.1`'s ratified building cross-section — one floor plate per FLOOR,
 * stacked, with an elevator as the way between them — driven over REAL `GET /api/fleet/snapshot`
 * bodies, with and without a building layout composing them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ A ROOM IS AN INSTALL; A FLOOR IS AN OPERATOR-COMPOSED SET OF ROOMS (card#9267, § 3.1, § 4.6).
 * This file was `TheBuildingStacksOneFloorPerInstallTest` while a floor WAS an install; the
 * one-floor-per-install building is now the EMPTY layout's case and is still asserted below as
 * exactly that. The composed cases hand the probe a `layout` — the same normalised document the
 * page delivers in `#lobby-layout`, produced by the same `App\Building\BuildingLayout` — so what
 * is driven is the real seam and not a hand-written stand-in for it.
 *
 * ⭐ THE SECOND AND THIRD FLOORS ARE REAL INSTALLS, NOT FIXTURE ROWS. `docs/PLAN.md`'s P4 accept
 * line is "second floor renders from a second install's feed", and the only way to answer it is
 * to provision a second install THROUGH THE REAL INGEST and read the served snapshot back — which
 * is `FeedTestCase`'s own rule ("a second fixture builder here would let the read plane be tested
 * against seat states the fold cannot produce"). So `threeFloors()` below issues ingest tokens for
 * `sola` and `zeta` and the plates are whatever the read surface then serves.
 *
 * ⚠ WHAT NO CHECK HERE COVERS, AND IT IS THE HALF A READER WILL ASSUME IS COVERED: the page.
 * There is no browser on this host, so no plate has been laid out, no cab has been seen standing
 * anywhere, and no button has been clicked. What is asserted is the DECISIONS — which plates, in
 * which order, where a ride goes, and when the ride is refused. `main.js` puts them into
 * elements; `LobbyPageWiringTest` checks those elements exist in both directions; the last test
 * in this file is the one that reds if the DOM half is deleted outright.
 *
 * ⚠ AND NO INSTALL EXISTS IN ANY DEPLOYMENT OF THIS SERVER. There is no host yet (`docs/PLAN.md`
 * P4: "the live-host leg stays unexercised until a host exists"), so every floor below is a floor
 * this suite provisioned. The one-floor and no-floor cases are therefore not hypothetical
 * degradations — they are what the building renders as the moment it is first deployed, which is
 * why they get their own tests and their own planted controls rather than a remark.
 */
class TheBuildingStacksTheComposedFloorsTest extends FeedTestCase
{
    use DrivesTheLobbyClient;

    /**
     * Three installs, so the elevator has a first stop, a middle one and a last one — the last
     * being the only place the wrap is observable at all.
     *
     * @return array<string, mixed> the snapshot body, as served
     */
    private function threeFloors(): array
    {
        $this->issueToken('sola', 'sola-solo');
        $this->issueToken('zeta', 'zeta-pm');

        return $this->oneFloor();
    }

    /**
     * The rig's own install and nothing else — which is exactly what a first deployment looks
     * like, and what this one looks like today.
     *
     * @return array<string, mixed>
     */
    private function oneFloor(): array
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        return $this->actingAs($this->enrolled())
            ->get('/api/fleet/snapshot')
            ->assertOk()
            ->json();
    }

    public function test_the_cross_section_stacks_the_lobbys_own_floors_and_changes_nothing_in_them(): void
    {
        $body = $this->threeFloors();
        $probe = $this->probe(['snapshot' => $body]);
        $plates = $probe['building']['plates'];

        // § 4.1 / § 4.6: the empty layout is one floor per install, floor keys ascending.
        $this->assertSame(['aimla', 'sola', 'zeta'], array_column($plates, 'floor'));

        // ⛔ AND THEY ARE THE LOBBY'S OWN ROWS, MEMBER FOR MEMBER. § 4.1: the cross-section "is a
        // *rendering* of this table, and changes nothing in it … No new field is read, no count
        // is recomputed." A plate list that merely AGREED with the row list would pass an
        // install_id comparison and still be a second derivation; comparing the whole row is what
        // says there is one.
        foreach ($plates as $i => $plate) {
            $row = $probe['model']['floors'][$i];

            $this->assertSame($row, array_diff_key($plate, ['level' => null]),
                'plate '.$i.' is not § 4.1’s own floor row with a stack position added — the '
                .'cross-section has grown a second derivation of the floor list');
        }

        // The stack position itself: dense, ascending, one per plate. A gap or a repeat is a
        // building with two floors numbered the same.
        $this->assertSame([0, 1, 2], array_column($plates, 'level'));

        // § 4.4's published route, still the plate's own link.
        $this->assertSame(['/floor/aimla', '/floor/sola', '/floor/zeta'], array_column($plates, 'href'),
            'the plate is no longer the link to § 4.4’s floor route');
    }

    public function test_the_elevator_rides_between_the_plates_and_wraps_at_the_top_of_the_stack(): void
    {
        $body = $this->threeFloors();

        // No ride yet: the cab stands at the first plate and the first ride goes to the second.
        $fresh = $this->probe(['snapshot' => $body])['building']['elevator'];

        $this->assertSame('aimla', $fresh['at']);
        $this->assertSame(0, $fresh['level']);
        $this->assertSame('sola', $fresh['next']);
        $this->assertSame(3, $fresh['stops']);
        $this->assertSame([], $fresh['notices'],
            'a three-floor building reported a reason the elevator could not be used');

        // Mid-stack.
        $middle = $this->probe(['snapshot' => $body, 'cab' => 'sola'])['building']['elevator'];

        $this->assertSame('sola', $middle['at']);
        $this->assertSame(1, $middle['level']);
        $this->assertSame('zeta', $middle['next']);

        // The last stop wraps to the first — the reference artifact's own behaviour, and the only
        // stop at which the wrap is distinguishable from "the next one along".
        $top = $this->probe(['snapshot' => $body, 'cab' => 'zeta'])['building']['elevator'];

        $this->assertSame('zeta', $top['at']);
        $this->assertSame(2, $top['level']);
        $this->assertSame('aimla', $top['next'],
            'the elevator has no ride from the last stop — a viewer who rides to the top is stranded there');
    }

    public function test_a_one_floor_building_refuses_the_ride_and_says_why(): void
    {
        $body = $this->oneFloor();
        $elevator = $this->probe(['snapshot' => $body])['building']['elevator'];

        // The building is real and has one plate — which is what this deployment renders today.
        $this->assertSame(1, $elevator['stops']);
        $this->assertSame('aimla', $elevator['at']);

        // ⛔ THE RIDE IS REFUSED, AND THE REFUSAL IS RENDERED. A second floor nobody provisioned
        // is a dark surface, and this repository's rule for a dark surface is that it is VISIBLY
        // dark: no ride, and a sentence saying there is nowhere to ride.
        $this->assertNull($elevator['next'],
            'the elevator offered a ride on a building with one floor — there is nowhere for it to go');
        $this->assertSame(
            ['the elevator has one stop — a single-floor building has nowhere to ride'],
            $elevator['notices'],
        );

        // ⛔ THE CONTROL — the exact machine that fakes the ride, planted in the shipped module.
        // `(level + 1) % stack.length` is arithmetic that always "succeeds": at one stop it
        // returns the stop the cab is already on, so the control lights up, the viewer clicks,
        // and the building reports a floor it does not have. This is the defect the refusal
        // above exists to prevent, and it is planted rather than described.
        $faked = $this->mutatedModules([
            'building-model.js',
            '    if (stack.length < 2 || level === null) {',
            '    if (level === null) {',
        ]);
        $fake = $this->probe(['snapshot' => $body], $faked)['building']['elevator'];

        $this->assertSame('aimla', $fake['next'],
            'CONTROL 12 did not bite: the modulo ride was planted in the shipped module and the '
            .'one-stop building still refused — so the refusal is not what the check is measuring');
    }

    public function test_a_building_with_no_floors_has_an_elevator_with_no_stops(): void
    {
        // ⚠ SYNTHETIC, AND THE ONE FIXTURE IN THIS FILE THAT IS. The rig provisions its install
        // in `setUp`, so there is no served body with an empty `installs[]` to read; the shape is
        // D2 § 8.2.2's with the array empty. It is worth a test anyway: an empty fleet is the
        // state this server is in before its first reporter ever posts, and an elevator that
        // resolved `at` to `undefined` there would put a cab on a building that does not exist.
        $elevator = $this->probe([
            'snapshot' => ['server_time' => '2026-09-11T12:00:00.000Z', 'fleet' => [], 'installs' => []],
        ])['building']['elevator'];

        $this->assertSame(0, $elevator['stops']);
        $this->assertNull($elevator['at']);
        $this->assertNull($elevator['level']);
        $this->assertNull($elevator['next']);
        $this->assertSame(
            ['the elevator has no stops — the snapshot carries no installs and the layout composes no floor'],
            $elevator['notices'],
        );
    }

    // ── the BUILDING LAYOUT, card#9267 ───────────────────────────────────────────────────────

    /**
     * ⭐ THE OPERATOR'S OWN CASE over a served snapshot: "a floor could hold multiple solo
     * agents … divided into a hallway with separate offices". `sola` and `zeta` are real installs
     * provisioned through the real ingest, composed onto one floor by a layout parsed by the real
     * reader; `aimla` is left unplaced. Three installs, two floors.
     */
    public function test_two_rooms_composed_onto_one_floor_are_one_plate_and_an_unplaced_install_is_its_own(): void
    {
        $body = $this->threeFloors();
        $layout = BuildingLayout::parse(['floors' => [['zeta' => 'office', 'sola' => 'office']]])->floors;

        $probe = $this->probe(['snapshot' => $body, 'layout' => $layout]);
        $plates = $probe['building']['plates'];

        $this->assertSame(['aimla', 'sola'], array_column($plates, 'floor'));
        $this->assertSame(['/floor/aimla', '/floor/sola'], array_column($plates, 'href'),
            'the plate is no longer the link to § 4.4’s floor route, keyed by the FLOOR');

        // The composed floor names its rooms, § 2.1 row 6's order, each with its form and each
        // reported — the snapshot carries both.
        $this->assertSame(
            [
                ['install_id' => 'sola', 'form' => 'office', 'reported' => true],
                ['install_id' => 'zeta', 'form' => 'office', 'reported' => true],
            ],
            $plates[1]['rooms'],
        );

        // § 2.1 row 5: the plate's summary counts the seats of EVERY install its rooms name —
        // one seat each here, so the composed plate holds two and the lone one holds one. A
        // summary that counted only the key room's seats would read `held: 1` on both.
        $this->assertSame(2, $plates[1]['held']);
        $this->assertSame(1, $plates[0]['held']);
        $this->assertSame([['install_id' => 'aimla', 'form' => 'open', 'reported' => true]], $plates[0]['rooms']);

        // And the elevator rides between FLOORS: two stops, keyed by floor, wrapping at the top.
        $elevator = $probe['building']['elevator'];
        $this->assertSame(2, $elevator['stops']);
        $this->assertSame('aimla', $elevator['at']);
        $this->assertSame('sola', $elevator['next']);
        $this->assertSame('aimla', $this->probe(['snapshot' => $body, 'layout' => $layout, 'cab' => 'sola'])['building']['elevator']['next']);

        // ⛔ CONTROL 15 — § 4.6's default rule removed from the client. The unplaced install
        // must then fall off the building, which is the hole the rule exists to refuse; a check
        // that stayed clean here would be measuring the layout and not the rule.
        $holed = $this->mutatedModules([
            'lobby-model.js',
            '        if (!placed.has(install_id)) {
            rows.push(',
            '        if (false) {
            rows.push(',
        ]);
        $hole = $this->probe(['snapshot' => $body, 'layout' => $layout], $holed)['building']['plates'];

        $this->assertSame(['sola'], array_column($hole, 'floor'),
            'CONTROL 15 did not bite: the default rule was removed from the shipped module and '
            .'the unplaced install still got a floor — so the rule is not what the check measures');
    }

    /**
     * ⭐ THE THIRD ROAD TO THE ONE-STOP REFUSAL, AND THE ONE card#9267's RULING MINTED: ONE FLOOR
     * FROM N INSTALLS. The two this file already covers get there by having nothing else — one
     * install and the empty layout, or no install at all. This one is a building that is FULLY
     * POPULATED and fully placed and still has nowhere to ride: the operator's own "hallway of
     * solo offices" composed over the whole fleet, which is a state only a layout can produce and
     * which arrives the first time an operator composes every room onto one floor. A ride OFFERED
     * here is the modulo fake `elevator()`'s one-stop branch exists to refuse, and a refusal with
     * no notice is that refusal made silent.
     *
     * ⛔ WATCHED RED BEFORE IT WAS TRUSTED: with `elevator()`'s `rows.length === 1` branch pushing
     * nothing, this test fails on the notice, and the one-install test above fails with it — the
     * branch is the shipped code both stand on. CONTROL 12 there is the planted control for the
     * refusal machinery itself, and is not repeated here.
     */
    public function test_a_fleet_composed_onto_one_floor_refuses_the_ride_and_says_why(): void
    {
        // The served body of `threeFloors()` with `aimla` — the install the layout does not
        // place — dropped, so the two composed rooms are the whole building.
        $body = $this->threeFloors();
        $layout = BuildingLayout::parse(['floors' => [['zeta' => 'office', 'sola' => 'office']]])->floors;

        $body['installs'] = array_values(array_filter(
            $body['installs'],
            static fn (array $install): bool => $install['install_id'] !== 'aimla',
        ));

        $this->assertSame(['sola', 'zeta'], array_column($body['installs'], 'install_id'),
            'this is a one-FLOOR building only if aimla is the only install dropped');

        $building = $this->probe(['snapshot' => $body, 'layout' => $layout])['building'];

        // Two rooms, one plate, and the plate is the floor they compose.
        $this->assertSame(['sola'], array_column($building['plates'], 'floor'));
        $this->assertSame(['sola', 'zeta'], array_column($building['plates'][0]['rooms'], 'install_id'));

        $this->assertSame(1, $building['elevator']['stops']);
        $this->assertSame('sola', $building['elevator']['at']);
        $this->assertNull($building['elevator']['next'],
            'the elevator offered a ride on a building whose every room shares its one floor');
        $this->assertSame(
            ['the elevator has one stop — a single-floor building has nowhere to ride'],
            $building['elevator']['notices'],
            'a composed one-floor building did not say why the elevator cannot be used',
        );
    }

    /**
     * § 4.6: "a room the fleet reports no seat for is drawn and labelled, never omitted". The
     * layout places `zeta`; the snapshot has never heard of it. The floor still has both rooms,
     * the second says so, and nothing about it is counted.
     */
    public function test_a_room_the_snapshot_does_not_carry_is_drawn_on_its_floor_and_flagged(): void
    {
        $this->issueToken('sola', 'sola-solo');
        $body = $this->oneFloor();
        $layout = BuildingLayout::parse(['floors' => [['sola' => 'office', 'zeta' => 'office']]])->floors;

        $plates = $this->probe(['snapshot' => $body, 'layout' => $layout])['building']['plates'];

        $this->assertSame(['aimla', 'sola'], array_column($plates, 'floor'));
        $this->assertSame([true, false], array_column($plates[1]['rooms'], 'reported'));
        $this->assertSame(1, $plates[1]['held'], 'an unreported room contributed to the held count');

        // THE CONTROL: provision `zeta` and the same room on the same floor flips to reported.
        $this->issueToken('zeta', 'zeta-solo');
        $flipped = $this->probe(['snapshot' => $this->oneFloor(), 'layout' => $layout])['building']['plates'];

        $this->assertSame([true, true], array_column($flipped[1]['rooms'], 'reported'));
        $this->assertSame(2, $flipped[1]['held']);
    }

    /**
     * ⭐ THE PIN ACROSS THE RUNTIME BOUNDARY. `tests/fixtures/building/compose-cases.json` is the
     * one statement of § 4.6's composition, and `Tests\Feature\Building` holds the PHP copy to
     * it; this walks the browser's copy over the same cases. ⚠ SYNTHETIC SNAPSHOTS, and stated:
     * the fixture names installs, not seats, so each install carries one placeholder seat — the
     * composition reads `installs[].install_id` and nothing on the seat.
     */
    public function test_the_browsers_composition_agrees_with_the_fixture_case_by_case(): void
    {
        $cases = json_decode(
            (string) file_get_contents(__DIR__.'/../../fixtures/building/compose-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['cases'];

        $this->assertGreaterThan(5, count($cases), 'the fixture decoded to almost nothing');

        foreach ($cases as $case) {
            $snapshot = [
                'server_time' => '2026-09-11T12:00:00.000Z',
                'fleet' => [],
                'installs' => array_map(
                    fn (string $id) => ['install_id' => $id, 'seats' => [['install_id' => $id, 'seat_id' => $id.'-seat', 'render_state' => 'idle']]],
                    $case['installs'],
                ),
            ];
            $layout = BuildingLayout::parse(['floors' => $case['layout']])->floors;

            $rows = $this->probe(['snapshot' => $snapshot, 'layout' => $layout])['model']['floors'];

            // Project the lobby row onto the fixture's shape: the fixture states the composition
            // and nothing about summaries or links.
            $composed = array_map(fn (array $row) => [
                'floor' => $row['floor'],
                'rooms' => array_map(fn (array $room) => [
                    'install' => $room['install_id'], 'form' => $room['form'], 'reported' => $room['reported'],
                ], $row['rooms']),
            ], $rows);

            $this->assertSame($case['floors'], $composed, 'fixture case: '.$case['name']);
        }
    }

    /**
     * The page delivers the layout — § 4.6: "with the page, not from an endpoint" — as the
     * normalised floors the reader produced, in the element `main.js` reads. Composed here so
     * the assertion is about a NON-EMPTY document reaching the page and not about `[]`.
     */
    public function test_the_page_delivers_the_composed_floors_the_reader_produced(): void
    {
        config(['building.floors' => [['zeta' => 'office', 'sola' => 'office']]]);

        $html = $this->lobbyPage();

        $this->assertMatchesRegularExpression('/<script type="application\/json" id="lobby-layout">(.+?)<\/script>/s', $html);
        preg_match('/<script type="application\/json" id="lobby-layout">(.+?)<\/script>/s', $html, $m);

        $this->assertSame(
            [['floor' => 'sola', 'rooms' => [['install' => 'sola', 'form' => 'office'], ['install' => 'zeta', 'form' => 'office']]]],
            json_decode($m[1], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_an_invalid_layout_refuses_the_lobby_loudly_rather_than_composing_a_partial_building(): void
    {
        // § 4.6 / the reader's header: a bad document is a refusal on the surfaces that read it,
        // per request — the lobby is one — and never a repair. The seat side is untouched by it.
        config(['building.floors' => [['sola' => 'cubicle']]]);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidBuildingLayout::class);

        $this->actingAs($this->enrolled())->get('/dashboard');
    }

    public function test_a_cab_standing_on_a_floor_the_building_no_longer_has_is_never_moved_quietly(): void
    {
        $body = $this->threeFloors();

        // The viewer rode to a floor that has since left `installs[]` — § 3.5's retirement takes
        // the last seat off a floor and the floor off the snapshot with it.
        $elevator = $this->probe(['snapshot' => $body, 'cab' => 'ghost'])['building']['elevator'];

        $this->assertTrue($elevator['stranded']);
        $this->assertSame('aimla', $elevator['at'], 'the cab was left on a floor the building does not have');
        $this->assertSame(
            ['the floor ghost is no longer in the building — the elevator is at aimla'],
            $elevator['notices'],
            'the cab was moved to another floor without saying so — the same silence § 4.1 refuses '
            .'when it makes the lobby render the discrepancy instead of picking a winner',
        );

        // ⛔ CONTROL 13 — the quiet relocation, planted. The cab still lands on `aimla`; the only
        // thing removed is the client saying so, which is exactly how this defect would ship.
        $quiet = $this->mutatedModules([
            'building-model.js',
            'const stranded = requested !== null && asked === undefined && rows.length > 0;',
            'const stranded = false;',
        ]);
        $moved = $this->probe(['snapshot' => $body, 'cab' => 'ghost'], $quiet)['building']['elevator'];

        $this->assertSame('aimla', $moved['at']);
        $this->assertSame([], $moved['notices'],
            'CONTROL 13 did not bite: the stranded-cab notice was removed from the shipped module '
            .'and the check stayed clean');
    }

    /**
     * ⛔ THE GUARD THAT DOES NOT TRAVEL WITH WHAT IT GUARDS. Every other check in this file drives
     * `building-model.js`, so deleting the module deletes its own witness. This one asserts that
     * the SHIPPED PAGE-FACING CLIENT still reaches the elevator — so removing the cross-section
     * from `main.js` and the control from the page in one tidy commit reds here instead of
     * leaving a lobby that quietly went back to being a list.
     */
    public function test_the_shipped_client_and_the_page_still_carry_the_elevator(): void
    {
        $js = (string) file_get_contents($this->moduleDir().'/main.js');
        $html = $this->lobbyPage();

        $this->assertSame([], $this->elevatorMissingFrom($js, $html));

        // ⛔ CONTROL 14 — the feature removed from the client, which is the defect this test is
        // the only witness for. The SAME predicate is run over a mutated copy, so what is seen
        // failing is the check itself and not a second assertion written to fail.
        $withoutClient = str_replace("import { buildingModel } from './building-model.js';\n", '', $js);
        $withoutPage = str_replace('id="lobby-elevator-notices"', 'id="lobby-notices"', $html);

        $this->assertNotSame($withoutClient, $js, "CONTROL 14's client anchor is gone — it mutated nothing");
        $this->assertNotSame($withoutPage, $html, "CONTROL 14's page anchor is gone — it mutated nothing");

        $this->assertNotSame([], $this->elevatorMissingFrom($withoutClient, $html),
            'CONTROL 14 did not bite: the cross-section import was stripped out of main.js and '
            .'this check stayed clean');
        $this->assertNotSame([], $this->elevatorMissingFrom($js, $withoutPage),
            'CONTROL 14 did not bite: the notice element was renamed off the page and this check '
            .'stayed clean');
    }

    /**
     * Every way the elevator can have left the shipped client, named rather than counted — so a
     * failure says which half went and the control can show the predicate discriminating.
     *
     * @return list<string>
     */
    private function elevatorMissingFrom(string $js, string $html): array
    {
        $missing = [];

        if (! str_contains($js, "from './building-model.js'")) {
            $missing[] = 'main.js no longer renders § 4.1’s cross-section — the lobby is a flat list again';
        }

        foreach (['lobby-elevator', 'lobby-elevator-notices'] as $id) {
            if (! str_contains($js, "'".$id."'")) {
                $missing[] = 'main.js never writes into #'.$id.' — the elevator has no DOM half';
            }

            if (! str_contains($html, 'id="'.$id.'"')) {
                $missing[] = 'the page declares no #'.$id.' — the elevator has nowhere to render';
            }
        }

        return $missing;
    }

    /** The rendered page, as an MFA-satisfied session actually receives it. */
    private function lobbyPage(): string
    {
        return $this->actingAs($this->enrolled())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';
    }
}
