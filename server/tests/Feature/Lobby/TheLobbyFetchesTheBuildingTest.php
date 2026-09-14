<?php

namespace Tests\Feature\Lobby;

use App\Building\Layouts;
use App\Fold\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Feed\FeedTestCase;

/**
 * `docs/design/FLOOR.md` Appendix B row 13's lobby half — the layout the lobby stacks comes from
 * `GET /api/building` (`docs/design/FLEET-STATE.md § 8.7`), fetched after the snapshot (§ 4.4's `/` row,
 * § 2.2 step 3b), and never from the page — and § 9 F17, *a failed layout request composes no building*.
 * card#9208 build slice 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ EVERY RESPONSE HANDED TO THE CLIENT HERE WAS SERVED. The snapshot, the building, the refusals and
 * the `building.layout` message are read off the real routes and the real outbox, then replayed to the
 * shipped modules through `lobby-probe.mjs`'s scripted `fetch` — so what is asserted is the client
 * against the surface as it answers, and not against a body written in this file. The two exceptions
 * are named where they occur: a request that never reaches a status, and a `200` whose body is not a
 * layout, neither of which this server can be made to answer.
 *
 * ⚠ WHAT NO CHECK HERE COVERS: `main.js`, which puts these models into elements. There is no browser on
 * this host. `LobbyPageWiringTest` holds the element ids both ways, and the first test below holds that
 * the page carries no layout for the client to read instead of the fetch.
 *
 * ⚠ AND THE LOBBY OPENS NO STREAM. § 2.2's steps 1–2 are Appendix B step 3's protocol, which is not
 * built, so no `building.layout` reaches this page yet; the message is applied here by calling the
 * client's own apply, which is the half this slice owns.
 */
class TheLobbyFetchesTheBuildingTest extends FeedTestCase
{
    use DrivesTheLobbyClient;

    private const SNAPSHOT = '/api/fleet/snapshot';

    private const BUILDING = '/api/building';

    /** @return array{status: int, body: mixed} */
    private function served(string $path): array
    {
        $response = $this->actingAs($this->enrolled())->getJson($path);

        return ['status' => $response->status(), 'body' => $response->json()];
    }

    /** The rig's install, reporting, and a composed building around it. */
    private function aComposedBuilding(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        Layouts::save((string) json_encode(['floors' => [['label' => 'the solos', 'rooms' => [
            'zeta' => ['form' => 'office'],
            'sola' => ['form' => 'office'],
        ]]]], JSON_PRETTY_PRINT), 'ops@example.com');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $responses
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function scenario(array $responses, array $steps, ?string $moduleDir = null): array
    {
        return $this->probe(['scenario' => ['responses' => $responses, 'steps' => $steps]], $moduleDir)['scenario'];
    }

    /** @return list<string> the floor keys the lobby stacked */
    private function stacked(array $record): array
    {
        return array_column($record['lobby']['floors'], 'floor');
    }

    public function test_the_page_carries_no_layout_and_the_client_reads_none_from_it(): void
    {
        $html = $this->actingAs($this->enrolled())->get('/dashboard')->assertOk()->getContent() ?: '';
        $js = (string) file_get_contents($this->moduleDir().'/main.js');

        // Two delivery paths for one building is the *which of the two am I looking at* question D2 § 13
        // row 41 refuses. The fetch is the one; the page must not still carry a second.
        $this->assertStringNotContainsString('application/json', $html,
            'the lobby page still inlines a JSON document — the layout reaches the client twice');
        $this->assertStringNotContainsString('lobby-layout"', $html,
            'the lobby page still declares the retired #lobby-layout island');
        $this->assertStringNotContainsString("'lobby-layout'", $js,
            'main.js still reads the layout out of the page');
        $this->assertStringContainsString("from './lobby-entry.js'", $js,
            'main.js no longer enters the lobby through lobby-entry.js — nothing on the page fetches the layout');
    }

    public function test_the_lobby_fetches_the_snapshot_then_the_building_and_stacks_the_fetched_layout(): void
    {
        $this->aComposedBuilding();

        $responses = [
            self::SNAPSHOT => [$this->served(self::SNAPSHOT)],
            self::BUILDING => [$this->served(self::BUILDING)],
        ];

        [$entered] = $this->scenario($responses, [['do' => 'enter']]);

        // § 4.4's `/` row: "`GET /api/fleet/snapshot`, then `GET /api/building`".
        $this->assertSame([self::SNAPSHOT, self::BUILDING], $entered['requests']);
        $this->assertSame(['aimla', 'sola'], $this->stacked($entered),
            'the lobby did not stack the floors GET /api/building answered');
        $this->assertSame('the solos', $entered['lobby']['floors'][1]['name']);
        $this->assertSame(['sola', 'zeta'], array_column($entered['lobby']['floors'][1]['rooms'], 'install_id'));
        $this->assertNull($entered['lobby']['layout_statement']);
        $this->assertSame([], $entered['lobby']['unclaimed']);

        // ⛔ CONTROL 20 — the fetch made and its answer dropped: the layout is taken as the EMPTY layout's
        // floors whatever the surface said. Every request is still issued in order; only the composition
        // is lost, which is exactly how a client reading its layout from somewhere else would look.
        $dropped = $this->mutatedModules([
            '../wire/building.js',
            'floors: body.layout.floors };',
            'floors: [] };',
        ]);
        [$ignored] = $this->scenario($responses, [['do' => 'enter']], $dropped);

        $this->assertSame([self::SNAPSHOT, self::BUILDING], $ignored['requests']);
        $this->assertNotSame(['aimla', 'sola'], $this->stacked($ignored),
            'CONTROL 20 did not bite: the fetched layout was discarded and the lobby still stacked it');

        // ⛔ CONTROL 21 — the order reversed: the layout fetched before the snapshot.
        $reversed = $this->mutatedModules([
            'lobby-entry.js',
            "    const snapshot = await fetchSnapshot(fetchImpl);\n\n    if (snapshot.ok) {\n        await building.fetchLayout();\n    }\n",
            "    await building.fetchLayout();\n    const snapshot = await fetchSnapshot(fetchImpl);\n",
        ]);

        $this->assertNotSame([self::SNAPSHOT, self::BUILDING], $this->scenario($responses, [['do' => 'enter']], $reversed)[0]['requests'],
            'CONTROL 21 did not bite: the building was requested before the snapshot and the order check stayed clean');
    }

    public function test_a_layout_request_that_fails_on_a_cold_start_composes_no_building(): void
    {
        $this->aComposedBuilding();

        $snapshot = $this->served(self::SNAPSHOT);
        $user = $this->enrolled();
        Schema::drop('building_layout');
        $refusal = ['status' => 503, 'body' => $this->actingAs($user)->getJson(self::BUILDING)->assertStatus(503)->json()];

        // F17: "on a cold start there is no layout to keep, so under that statement it lists the
        // snapshot's installs as rooms with no floor claimed, each a link to `/floor/{install_id}`".
        // The unreachable request and the 200 that is not a layout cannot be made to happen on this
        // server; they are the two ways a request fails without a refusal body, and the client must read
        // both as failures rather than as the empty layout.
        $arms = [
            'a 503 the surface served' => [$refusal, 'the building layout could not be loaded — HTTP 503'],
            'a request that never reached a status' => [['unreachable' => true], 'the building layout could not be requested — the browser could not reach the server'],
            'a 200 whose body is not a layout' => [['status' => 200, 'text' => '{"floors": []}'], 'the building layout could not be loaded — HTTP 200, and the body is not a layout'],
        ];

        foreach ($arms as $arm => [$answer, $statement]) {
            $responses = [self::SNAPSHOT => [$snapshot], self::BUILDING => [$answer]];

            [$record] = $this->scenario($responses, [['do' => 'enter']]);

            $this->assertSame([], $record['lobby']['floors'], "$arm: a failed layout request composed a building");
            $this->assertSame([], $record['building']['plates'], "$arm: the cross-section stacked plates from a failed request");
            $this->assertSame([['install_id' => 'aimla', 'href' => '/floor/aimla', 'held' => 1]], $record['lobby']['unclaimed'], $arm);
            $this->assertSame($statement, $record['lobby']['layout_statement'], $arm);
            $this->assertNull($record['lobby']['layout_kept'], "$arm: a cold start claimed a last known layout it never had");
            // The elevator's dark-case sentences both claim something about the layout ("the layout
            // composes no floor") that a failed request cannot tell; the ride is refused under F17's own
            // statement instead.
            $this->assertSame([], $record['building']['elevator']['notices'], $arm);
            $this->assertNull($record['building']['elevator']['next'], $arm);
            // Every seat the client holds is still counted, so § 4.1's discrepancy check does not read
            // an uncomposed lobby as one holding no seats and fetch the snapshot for it.
            $this->assertNull($record['lobby']['discrepancy'], $arm);
        }

        // ⛔ CONTROL 22 — F17's "Never", planted: a failed request taken as the empty layout.
        $empty = $this->mutatedModules([
            '../wire/building.js',
            "            this.#layoutFailure = { status };\n",
            "            this.#layoutFailure = { status };\n            this.#layout ??= { layout_version: 0, floors: [] };\n",
        ]);
        [$composed] = $this->scenario([self::SNAPSHOT => [$snapshot], self::BUILDING => [$refusal]], [['do' => 'enter']], $empty);

        $this->assertNotSame([], $composed['lobby']['floors'],
            'CONTROL 22 did not bite: a failed layout request was composed as the empty layout and the check stayed clean');
    }

    public function test_a_layout_request_that_fails_after_a_building_layout_keeps_the_last_known_layout(): void
    {
        $this->aComposedBuilding();

        $snapshot = $this->served(self::SNAPSHOT);
        $first = $this->served(self::BUILDING);

        $this->wire->forget();
        Layouts::save((string) json_encode(['floors' => [['rooms' => ['sola' => ['form' => 'open']]]]]), 'ops@example.com');
        [$message] = $this->wire->ofType('building.layout');
        $second = $this->served(self::BUILDING);

        $records = $this->scenario(
            [
                self::SNAPSHOT => [$snapshot],
                self::BUILDING => [$first, ['status' => 500, 'body' => ['message' => 'Server Error']], $second],
            ],
            [
                ['do' => 'enter'],
                // The version this client already holds: § 2.5 fetches only on one that differs.
                ['do' => 'building.layout', 'message' => ['t' => 'building.layout', 'layout_version' => $first['body']['layout']['layout_version']]],
                ['do' => 'building.layout', 'message' => $message['payload']],
                // F17's recovery: "on the next `building.layout`" — the same message again, after a failure.
                ['do' => 'building.layout', 'message' => $message['payload']],
            ],
        );

        [, $unchanged, $failed, $recovered] = $records;

        $this->assertSame([self::SNAPSHOT, self::BUILDING], $unchanged['requests'], 'a building.layout naming the held version fetched again');
        $this->assertFalse($unchanged['result']);

        $this->assertTrue($failed['result']);
        $this->assertSame(['aimla', 'sola'], $this->stacked($failed), 'a failed re-fetch dropped the layout the client already held');
        $this->assertSame('the building layout could not be loaded — HTTP 500', $failed['lobby']['layout_statement']);
        $this->assertSame('last known layout', $failed['lobby']['layout_kept']);

        $this->assertSame([self::SNAPSHOT, self::BUILDING, self::BUILDING, self::BUILDING], $recovered['requests']);
        $this->assertSame(['aimla', 'sola'], $this->stacked($recovered));
        $this->assertSame([['install' => 'sola', 'form' => 'open']], array_map(
            fn (array $room) => ['install' => $room['install_id'], 'form' => $room['form']],
            $recovered['lobby']['floors'][1]['rooms'],
        ), 'the recovered fetch did not replace the last known layout');
        $this->assertNull($recovered['lobby']['layout_statement']);
        $this->assertNull($recovered['lobby']['layout_kept']);
    }

    /**
     * ⚠ THIS WAS THE PAGE'S REFUSAL. While the page inlined the layout, a stored document the reader
     * refuses threw on `/dashboard`; the page no longer reads the layout, so the refusal is
     * `GET /api/building`'s, and what the lobby owes it is F17 — the statement, and no building.
     *
     * The row is planted rather than saved: the store refuses this at the write since card#9208, and the
     * state is reachable only as a rule tightened after a document was stored.
     */
    public function test_a_stored_layout_the_reader_refuses_is_a_failed_request_and_composes_no_building(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        DB::table('building_layout')->insert([
            'id' => 1,
            'document' => '{"floors":[{"rooms":{"sola":{"form":"cubicle"}}}]}',
            'layout_version' => 1,
            'updated_by' => 'ops@example.com',
            'updated_at' => Clock::sql(now()),
        ]);

        $refused = $this->served(self::BUILDING);
        $this->assertSame(500, $refused['status']);

        [$record] = $this->scenario(
            [self::SNAPSHOT => [$this->served(self::SNAPSHOT)], self::BUILDING => [$refused]],
            [['do' => 'enter']],
        );

        $this->assertSame([], $record['lobby']['floors']);
        $this->assertSame('the building layout could not be loaded — HTTP 500', $record['lobby']['layout_statement']);
        $this->assertSame(['aimla'], array_column($record['lobby']['unclaimed'], 'install_id'));
    }
}
