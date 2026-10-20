<?php

namespace Tests\Feature\Lobby;

use Tests\Feature\Building\LayoutFixture;
use Tests\Feature\Feed\FeedTestCase;
use Tests\Feature\Support\ModuleSpecifiers;

/**
 * `docs/design/FLOOR.md § 4.1`'s floor list as the lobby's directory and both cabs read it — one plaque per FLOOR,
 * `building-model.js`'s `plates()` over `lobby-model.js`'s `floors()` — driven over REAL `GET /api/fleet/snapshot`
 * bodies, with and without a building layout composing them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ A ROOM IS AN INSTALL; A FLOOR IS AN OPERATOR-COMPOSED SET OF ROOMS (card#9267, § 3.1, § 4.6). The one-floor-per-
 * install building is the EMPTY layout's case and is asserted below as exactly that. The composed cases hand the probe
 * a `layout` — the same normalised floors `GET /api/building` answers, produced by the same `App\Building\BuildingLayout`
 * — so what is driven is the real seam and not a hand-written stand-in for it. That the lobby takes its layout from
 * that request, and what it renders when the request fails, is `TheLobbyFetchesTheBuildingTest`'s.
 *
 * ⭐ THE SECOND AND THIRD FLOORS ARE REAL INSTALLS, NOT FIXTURE ROWS. `docs/PLAN.md`'s P4 accept line is "second floor
 * renders from a second install's feed", so `threeFloors()` below issues ingest tokens for `sola` and `zeta` and the
 * plaques are whatever the read surface then serves.
 *
 * ⚠ Until card#12113 this file was `TheBuildingStacksTheComposedFloorsTest` and held the cross-section's elevator too —
 * its next stop, its wrap, its one-stop and no-stop refusals and its stranded cab. The lobby's elevator is now § 4.5's
 * ride (`Tests\Feature\Lobby\TheLobbysElevatorIsTheFloorsRideTest`), which has no next stop and no cab position, and
 * those tests went with the code they held. What is asserted here is the DECISIONS — which plaques, in which order,
 * named and linked how — and the last test is the one that reds if the page-facing client stops drawing them.
 */
class TheDirectoryListsTheComposedFloorsTest extends FeedTestCase
{
    use DrivesTheLobbyClient;

    /**
     * Three installs — three floors on the empty layout, two on the composed one.
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

    public function test_the_directory_lists_the_lobbys_own_floors_and_changes_nothing_in_them(): void
    {
        $body = $this->threeFloors();
        $probe = $this->probe(['snapshot' => $body]);
        $plates = $probe['plates'];

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
        $layout = LayoutFixture::read(['floors' => [['rooms' => ['zeta' => ['form' => 'office'], 'sola' => ['form' => 'office']]]]])->floors;

        $probe = $this->probe(['snapshot' => $body, 'layout' => $layout]);
        $plates = $probe['plates'];

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

        // ⛔ CONTROL 15 — § 4.6's default rule removed from the client. The unplaced install
        // must then fall off the building, which is the hole the rule exists to refuse; a check
        // that stayed clean here would be measuring the layout and not the rule.
        $holed = $this->mutatedModules([
            'lobby-model.js',
            '        if (!placed.has(install_id)) {',
            '        if (false) {',
        ]);
        $hole = $this->probe(['snapshot' => $body, 'layout' => $layout], $holed)['plates'];

        $this->assertSame(['sola'], array_column($hole, 'floor'),
            'CONTROL 15 did not bite: the default rule was removed from the shipped module and '
            .'the unplaced install still got a floor — so the rule is not what the check measures');
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
        $layout = LayoutFixture::read(['floors' => [['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]])->floors;

        $plates = $this->probe(['snapshot' => $body, 'layout' => $layout])['plates'];

        $this->assertSame(['aimla', 'sola'], array_column($plates, 'floor'));
        $this->assertSame([true, false], array_column($plates[1]['rooms'], 'reported'));
        $this->assertSame(1, $plates[1]['held'], 'an unreported room contributed to the held count');

        // THE CONTROL: provision `zeta` and the same room on the same floor flips to reported.
        $this->issueToken('zeta', 'zeta-solo');
        $flipped = $this->probe(['snapshot' => $this->oneFloor(), 'layout' => $layout])['plates'];

        $this->assertSame([true, true], array_column($flipped[1]['rooms'], 'reported'));
        $this->assertSame(2, $flipped[1]['held']);
    }

    // ── the FLOOR'S LABEL, card#9273 ─────────────────────────────────────────────────────────

    /**
     * ⭐ THE OPERATOR'S RULING, DRIVEN END TO END: *"yes, I want to be able to name a floor"*
     * (§ 4.6). The composed floor reads *the solos* on its plate and on the ride control, and
     * EVERY key in sight is still the derived one — the link, the cab's stop, the sort. That
     * pairing is the whole card: a name a viewer reads, and nothing that reads it back.
     */
    public function test_a_labelled_floor_reads_as_its_label_and_links_by_its_key(): void
    {
        $body = $this->threeFloors();
        $layout = LayoutFixture::read(
            ['floors' => [['label' => 'the solos', 'rooms' => ['zeta' => ['form' => 'office'], 'sola' => ['form' => 'office']]]]],
        )->floors;

        $probe = $this->probe(['snapshot' => $body, 'layout' => $layout]);
        $plates = $probe['plates'];

        // The unplaced install has no entry to name it, so it reads as its key; the composed one
        // reads its label. Both are NAMES — neither is a placeholder.
        $this->assertSame(['aimla', 'sola'], array_column($plates, 'floor'));
        $this->assertSame([null, 'the solos'], array_column($plates, 'label'));
        $this->assertSame(['aimla', 'the solos'], array_column($plates, 'name'));

        // ⛔ AND THE LINK IS THE KEY, WHICH IS THE PROPERTY THE LABEL IS SEPARATE FOR. `/floor/the
        // solos` would be a route to a floor the design does not have, and would move the moment
        // the operator renamed the floor.
        $this->assertSame(['/floor/aimla', '/floor/sola'], array_column($plates, 'href'));

        // ⛔ CONTROL 16 — the label dropped in the shipped client, which is how this feature
        // half-ships: the surface delivers the label, the browser ignores it, and the plate quietly
        // reads its key. `test_the_browsers_composition_agrees_with_the_fixture_case_by_case`
        // reds on the same mutation; what is asserted here is what the VIEWER would see.
        $dropped = $this->mutatedModules([
            'lobby-model.js',
            "const label = typeof floor?.label === 'string' ? floor.label : null;",
            'const label = null;',
        ]);
        $unnamed = $this->probe(['snapshot' => $body, 'layout' => $layout], $dropped)['plates'];

        $this->assertSame([null, null], array_column($unnamed, 'label'),
            'CONTROL 16 did not bite: the label read was removed from the shipped module and the '
            .'plate carried a label anyway');
        $this->assertSame(['aimla', 'sola'], array_column($unnamed, 'name'),
            'CONTROL 16 did not bite: with no label read, the plate still read as one');

        // ⛔ CONTROL 17 — the FALLBACK removed instead: `name` becomes the key and the label is
        // carried but never read. The plate then reads `sola` on a floor the operator named,
        // which is the defect that would survive CONTROL 16's mutation being fixed wrongly.
        $keyed = $this->mutatedModules([
            'lobby-model.js',
            'name: label ?? floor,',
            'name: floor,',
        ]);
        $keyedPlates = $this->probe(['snapshot' => $body, 'layout' => $layout], $keyed)['plates'];

        $this->assertSame([null, 'the solos'], array_column($keyedPlates, 'label'));
        $this->assertSame(['aimla', 'sola'], array_column($keyedPlates, 'name'),
            'CONTROL 17 did not bite: the label-else-key fallback was removed from the shipped '
            .'module and the plate still read its label');
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
            $layout = LayoutFixture::read(['floors' => $case['layout']])->floors;

            $rows = $this->probe(['snapshot' => $snapshot, 'layout' => $layout])['model']['floors'];

            // Project the lobby row onto the fixture's shape: the fixture states the composition
            // and nothing about summaries or links.
            $composed = array_map(fn (array $row) => [
                'floor' => $row['floor'],
                // ⛔ THE PIN FOR card#9273's LABEL, and it is this projection: a label PHP carries
                // and the browser drops reds HERE, on the fixture's own cases, rather than being
                // caught by whichever screen noticed first.
                'label' => $row['label'],
                // card#11046 row 21: the floor's `theme`, the same pin — a theme PHP carries and the
                // browser drops reds here.
                'theme' => $row['theme'],
                'rooms' => array_map(
                    // ⛔ AND FOR card#9292's `origin`, for the same reason and in the same place.
                    // Appendix B row 11 calls this projection a CROSS-RUNTIME PIN in terms: the
                    // fixture's planned cases are what make *the plan reaches the browser* a
                    // property of both runtimes rather than of whichever one was read last. The
                    // member is carried only where the floor is planned — an `origin => null`
                    // would be a third state neither runtime has.
                    fn (array $room) => ['install' => $room['install_id'], 'form' => $room['form']]
                        + (isset($room['origin']) ? ['origin' => $room['origin']] : [])
                        + ['reported' => $room['reported']],
                    $row['rooms'],
                ),
            ], $rows);

            $this->assertSame($case['floors'], $composed, 'fixture case: '.$case['name']);
        }
    }

    /**
     * ⛔ THE GUARD THAT DOES NOT TRAVEL WITH WHAT IT GUARDS (card#9273, re-cut for the directory by card#12113). Every
     * other check here drives the modules, so the page-facing client could stop drawing a plaque's NAME, link a plaque
     * by its label, or compose the cab's stops without the layout — every model check green, the viewer reading keys,
     * or riding to a building the directory did not draw. Asserted as writes and as one composition, because there is
     * no browser on this host.
     */
    public function test_the_shipped_client_still_draws_the_floors_name_and_composes_the_floors_once(): void
    {
        $js = $this->pageFacingClient();

        $this->assertSame([], $this->labelMissingFrom($js));

        // ⛔ CONTROL 18 — the plaque reverted to its key, and its link to its label, in the shipped file.
        $keyedPlaque = str_replace('plaque.name', 'plaque.floor', $js);
        $labelLink = str_replace('link.href = plaque.href;', 'link.href = `/floor/${plaque.name}`;', $js);

        $this->assertNotSame($keyedPlaque, $js, "CONTROL 18's plaque anchor is gone — it mutated nothing");
        $this->assertNotSame($labelLink, $js, "CONTROL 18's link anchor is gone — it mutated nothing");
        $this->assertNotSame([], $this->labelMissingFrom($keyedPlaque), 'CONTROL 18 did not bite: the plaque went back to drawing its key');
        $this->assertNotSame([], $this->labelMissingFrom($labelLink), 'CONTROL 18 did not bite: the plaque linked by a label');

        // ⛔ CONTROL 19 — card#9273's shipped defect, in its card#12113 shape: the cab's stops composed without the layout,
        // so the ride and the directory beside it name two buildings.
        $recomposed = str_replace('plates(this.#body(), this.#building.floors)', 'plates(this.#body(), [])', $js);

        $this->assertNotSame($recomposed, $js, "CONTROL 19's anchor is gone — it mutated nothing");
        $this->assertNotSame([], $this->labelMissingFrom($recomposed), 'CONTROL 19 did not bite: the cab composed the building without the layout');
    }

    /**
     * Every way the page-facing client can have stopped drawing a floor as the model decided it: the plaque's name
     * and its key's link written by `directory.js`, and every composition of the floors in the lobby screen — the
     * directory's (`lobbyModel()`) and the cab's stops (`plates()`) — handed the layout the building holds.
     *
     * @return list<string>
     */
    private function labelMissingFrom(string $js): array
    {
        $missing = [];

        if (! str_contains($js, 'plaque.name')) {
            $missing[] = 'the directory no longer writes plaque.name — a named floor reads as its key again (FLOOR.md § 4.6, card#9273)';
        }

        if (! str_contains($js, 'link.href = plaque.href;')) {
            $missing[] = 'the directory no longer links a plaque by its own href — the key\'s route (FLOOR.md § 4.4)';
        }

        // Read in the code alone: the modules' comments name `plates()` and `lobbyModel()` in prose.
        preg_match_all('/\b(?:plates|lobbyModel)\(([^;]*)\)/', ModuleSpecifiers::withoutComments($js), $m);

        if ($m[0] === []) {
            $missing[] = 'the lobby screen composes no floor list at all';
        }

        foreach ($m[1] as $args) {
            if (! str_contains($args, 'this.#building.floors')) {
                $missing[] = "a composition of the floors is not handed the building's layout: ({$args})";
            }
        }

        return $missing;
    }

    /**
     * The lobby's page-facing client: `lobby-screen.js`, the one place that composes the frame and the cab's stops, and
     * `directory.js`, which builds each plaque's elements — read as one text, because the guards are about the whole.
     */
    private function pageFacingClient(): string
    {
        return (string) file_get_contents($this->moduleDir().'/lobby-screen.js')
            ."\n".(string) file_get_contents($this->moduleDir().'/directory.js');
    }
}
