<?php

namespace Tests\Feature\Building;

use App\Floor\FloorMap;
use App\Floor\Floors;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Feature\Feed\FeedTestCase;
use Tests\Feature\Lobby\DrivesTheLobbyClient;

/**
 * `docs/design/FLOOR.md` Appendix B row 13's map half — the client's room map cache by `map_version`
 * (`public/js/wire/building.js`): which rooms § 4.4's floor entry fetches, when a held map is reused,
 * what a `room.map` re-fetches (§ 2.5, `docs/design/FLEET-STATE.md § 8.7`), and § 9 F16, *a failed map
 * request holds no default in its place*. card#9208 build slice 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ EVERY BODY AND EVERY MESSAGE WAS SERVED. The maps are `GET /api/building/rooms/{install_id}/map`'s
 * answers, the versions are `GET /api/building`'s, and each `room.map` is the row the store committed
 * to `feed_outbox` with the revision it announces — replayed to the shipped module through
 * `tests/Feature/Lobby/lobby-probe.mjs`'s scripted `fetch`, which is why this file uses the lobby's rig:
 * that rig copies and drives the whole shipped `public/js` tree, `wire/` included.
 *
 * ⚠ WHAT T39's CLIENT HALF STILL OWES, AND NOTHING HERE CLAIMS IT. The cache has no caller on any page
 * yet: its two callers are the stream that delivers `room.map` — opened by the client protocol,
 * built at Appendix B step 3 and constructed by no page before step 8 — and step 7's floor route,
 * which enters rooms and draws their maps. So this file asserts the fetch, the reuse, the
 * invalidation, the one line the apply returns for the client's event record, and the failure the
 * cache holds. It does NOT assert *re-renders one room* (step 7 draws rooms), *one
 * event-log line WRITTEN* (step 3 builds the record; FLOOR gates the WRITING at step 7, which is
 * what delivers the `room.map` this apply answers), or *no § 6.2 row fired*
 * (step 2's animation log and step 6's animation set) — a check for an animation on a client with no
 * animations cannot fail, so it is not written.
 */
class TheClientHoldsRoomMapsByVersionTest extends FeedTestCase
{
    use DrivesTheLobbyClient;

    private const SNAPSHOT = '/api/fleet/snapshot';

    private const BUILDING = '/api/building';

    private const AIMLA = '/api/building/rooms/aimla/map';

    private const SOLA = '/api/building/rooms/sola/map';

    private const OPERATOR = 'ops@example.com';

    /** @return array{status: int, body: mixed} */
    private function served(string $path): array
    {
        $response = $this->actingAs($this->enrolled())->getJson($path);

        return ['status' => $response->status(), 'body' => $response->json()];
    }

    /** @return array<string, mixed> the `room.map` envelope the store committed for this write */
    private function announced(\Closure $write): array
    {
        $this->wire->forget();
        $write();
        $messages = $this->wire->ofType('room.map');

        $this->assertCount(1, $messages, 'the write did not announce exactly one room.map');

        return $messages[0]['payload'];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $responses
     * @param  list<string>  $rendered
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function scenario(array $responses, array $rendered, array $steps, ?string $moduleDir = null): array
    {
        return $this->probe(['scenario' => [
            'responses' => $responses,
            'rendered' => $rendered,
            'steps' => $steps,
        ]], $moduleDir)['scenario'];
    }

    /** @return array{source: string, map_version: ?int}|null */
    private function heldIn(array $record, string $room): ?array
    {
        $held = $record['rooms'][$room]['held'] ?? null;

        return $held === null ? null : ['source' => $held['source'], 'map_version' => $held['map_version']];
    }

    /** The rig's install reporting, `aimla` authored once, and every response the entry reads. */
    private function authoredOnce(): array
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid(8)), self::OPERATOR);

        return [
            'snapshot' => $this->served(self::SNAPSHOT),
            'building' => $this->served(self::BUILDING),
            'aimla' => $this->served(self::AIMLA),
            'sola' => $this->served(self::SOLA),
        ];
    }

    public function test_a_room_held_at_the_version_the_building_reported_is_not_fetched_again(): void
    {
        $served = $this->authoredOnce();

        $this->assertSame([['install_id' => 'aimla', 'map_version' => 1]], array_map(
            fn (array $room) => ['install_id' => $room['install_id'], 'map_version' => $room['map_version']],
            $served['building']['body']['rooms'],
        ));

        // A spare answer per room, so a re-fetch shows as a REQUEST rather than as the probe refusing an
        // unscripted one — the control below needs to see it counted.
        $responses = [
            self::SNAPSHOT => [$served['snapshot']],
            self::BUILDING => [$served['building']],
            self::AIMLA => [$served['aimla'], $served['aimla']],
            self::SOLA => [$served['sola'], $served['sola']],
        ];
        $steps = [
            ['do' => 'enter'],
            ['do' => 'rooms', 'rooms' => ['aimla', 'sola']],
            ['do' => 'rooms', 'rooms' => ['aimla', 'sola']],
        ];

        [, $first, $again] = $this->scenario($responses, ['aimla', 'sola'], $steps);

        $fetched = [self::SNAPSHOT, self::BUILDING, self::AIMLA, self::SOLA];

        // § 4.4: a room is fetched when the client "does not already hold" its map "at the `map_version`
        // `/api/building` reported" — and a room absent from `rooms[]` is the default, `null`.
        $this->assertSame($fetched, $first['requests']);
        $this->assertSame(['source' => 'authored', 'map_version' => 1], $this->heldIn($first, 'aimla'));
        $this->assertSame(['source' => 'default', 'map_version' => null], $this->heldIn($first, 'sola'));
        $this->assertSame($fetched, $again['requests'], 'a room held at the reported version was fetched again');

        // ⛔ CONTROL 23 — the reuse check removed: every entry fetches every room.
        $always = $this->mutatedModules([
            '../wire/building.js',
            'if (this.#holds(installId, this.reportedVersion(installId))) {',
            'if (false) {',
        ]);

        $this->assertNotSame($fetched, $this->scenario($responses, ['aimla', 'sola'], $steps, $always)[2]['requests'],
            'CONTROL 23 did not bite: the held map was re-fetched on every entry and the reuse check stayed clean');
    }

    public function test_a_room_map_naming_a_new_version_refetches_that_room_alone_and_returns_one_line(): void
    {
        $served = $this->authoredOnce();

        $saved = $this->announced(fn () => Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid(12)), self::OPERATOR));
        $second = $this->served(self::AIMLA);
        $removed = $this->announced(fn () => Floors::remove('aimla', self::OPERATOR));
        $default = $this->served(self::AIMLA);

        $this->assertSame(2, $saved['map_version']);
        $this->assertNull($removed['map_version']);

        $responses = [
            self::SNAPSHOT => [$served['snapshot']],
            self::BUILDING => [$served['building']],
            self::AIMLA => [$served['aimla'], $second, $default, $second],
            self::SOLA => [$served['sola'], $served['sola']],
        ];
        $steps = [
            ['do' => 'enter'],
            ['do' => 'rooms', 'rooms' => ['aimla', 'sola']],
            ['do' => 'room.map', 'message' => $saved],
            // D2 § 8.7: a client "receives each message once" — and applies one naming the version it
            // holds as nothing.
            ['do' => 'room.map', 'message' => $saved],
            // "A client applies a `room.map` only for a room it renders and ignores the rest."
            ['do' => 'room.map', 'message' => ['t' => 'room.map', 'install_id' => 'zeta', 'map_version' => 3]],
            ['do' => 'room.map', 'message' => $removed],
        ];

        [, $entered, $applied, $duplicate, $elsewhere, $reverted] = $this->scenario($responses, ['aimla', 'sola'], $steps);

        $before = $entered['requests'];

        $this->assertSame([...$before, self::AIMLA], $applied['requests'], 'the room.map did not re-fetch exactly that room');
        $this->assertTrue($applied['result']['applied']);
        $this->assertSame(['source' => 'authored', 'map_version' => 2], $this->heldIn($applied, 'aimla'));
        $this->assertSame(['source' => 'default', 'map_version' => null], $this->heldIn($applied, 'sola'), 'another room was touched');
        // § 2.5: "one line goes into the client's event log naming the room and the revision".
        $this->assertIsString($applied['result']['line']);
        $this->assertStringContainsString('aimla', $applied['result']['line']);
        $this->assertStringContainsString('revision 2', $applied['result']['line']);

        $this->assertSame($applied['requests'], $duplicate['requests'], 'a room.map naming the held version fetched again');
        $this->assertSame(['applied' => false, 'line' => null], $duplicate['result']);

        $this->assertSame($applied['requests'], $elsewhere['requests'], 'a room.map for a room the client does not render was fetched');
        $this->assertSame(['applied' => false, 'line' => null], $elsewhere['result']);

        // A removal is `map_version: null`, which differs from the held 2: the room goes back to the
        // default, fetched rather than assumed.
        $this->assertSame([...$applied['requests'], self::AIMLA], $reverted['requests']);
        $this->assertSame(['source' => 'default', 'map_version' => null], $this->heldIn($reverted, 'aimla'));
        $this->assertStringContainsString('aimla', (string) $reverted['result']['line']);

        // ⛔ CONTROL 24 — the version comparison removed from the apply: every `room.map` for a rendered
        // room re-fetches, so the duplicate costs a request and writes a second line.
        $blind = $this->mutatedModules([
            '../wire/building.js',
            'if (this.#holds(id, version)) {',
            'if (false) {',
        ]);
        [, , , $refetched] = $this->scenario($responses, ['aimla', 'sola'], $steps, $blind);

        $this->assertNotSame($applied['requests'], $refetched['requests'],
            'CONTROL 24 did not bite: a duplicate room.map re-fetched and the check stayed clean');
    }

    /**
     * A map fetch's own `map_version` is newer than the `rooms[]` read before it, so once a `room.map`
     * has re-fetched a room, entering it again compares against the map's answer and not the building's
     * older report.
     */
    public function test_a_room_a_room_map_refetched_is_not_fetched_again_on_the_next_entry(): void
    {
        $served = $this->authoredOnce();

        $saved = $this->announced(fn () => Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid(12)), self::OPERATOR));
        $second = $this->served(self::AIMLA);

        // `building` was served before the save, so it reports `aimla` at 1. A spare answer, so a
        // re-fetch shows as a REQUEST.
        $responses = [
            self::SNAPSHOT => [$served['snapshot']],
            self::BUILDING => [$served['building']],
            self::AIMLA => [$served['aimla'], $second, $second],
        ];
        $steps = [
            ['do' => 'enter'],
            ['do' => 'rooms', 'rooms' => ['aimla']],
            ['do' => 'room.map', 'message' => $saved],
            ['do' => 'rooms', 'rooms' => ['aimla']],
        ];

        [, , $applied, $again] = $this->scenario($responses, ['aimla'], $steps);

        $this->assertSame([self::SNAPSHOT, self::BUILDING, self::AIMLA, self::AIMLA], $applied['requests']);
        $this->assertSame(['source' => 'authored', 'map_version' => 2], $this->heldIn($applied, 'aimla'));
        $this->assertSame($applied['requests'], $again['requests'],
            'entering a room a room.map had already re-fetched fetched it again');
        $this->assertSame(['source' => 'authored', 'map_version' => 2], $this->heldIn($again, 'aimla'));

        // ⛔ CONTROL 27 — the map's own version not recorded: entry compares the held 2 with the building's 1.
        $forgetful = $this->mutatedModules([
            '../wire/building.js',
            "        this.#reported.set(id, body.map_version);\n",
            '',
        ]);

        $this->assertNotSame($applied['requests'], $this->scenario($responses, ['aimla'], $steps, $forgetful)[3]['requests'],
            'CONTROL 27 did not bite: the room was re-fetched on entry and the check stayed clean');
    }

    public function test_a_room_map_request_that_fails_holds_no_default_in_its_place(): void
    {
        $served = $this->authoredOnce();

        $saved = $this->announced(fn () => Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid(12)), self::OPERATOR));
        $second = $this->served(self::AIMLA);

        $user = $this->enrolled();
        Schema::drop('floors');
        $unavailable = ['status' => 503, 'body' => $this->actingAs($user)->getJson(self::AIMLA)->assertStatus(503)->json()];

        $base = [self::SNAPSHOT => [$served['snapshot']], self::BUILDING => [$served['building']]];

        // Cold: the room was never held. F16: "with none held it has no extent" — and no map at all.
        $coldSteps = [['do' => 'enter'], ['do' => 'rooms', 'rooms' => ['aimla']]];
        $coldResponses = $base + [self::AIMLA => [$unavailable]];

        [, $cold] = $this->scenario($coldResponses, ['aimla'], $coldSteps);

        $this->assertSame(['held' => null, 'failure' => ['status' => 503]], $cold['rooms']['aimla'],
            'a failed map request left the room holding something other than nothing');

        // Warm: a map was held. F16: "the mapless room keeps the extent of the last map the client held
        // for it" — kept as the held map, under the failure, never replaced by the default — and the
        // failure is retried "on the user's action": entering the room again re-fetches it even though
        // the map it holds is still at the version `/api/building` reported, which is the one case where
        // only the failure, and not a version, says to fetch.
        $warm = $this->scenario(
            $base + [self::AIMLA => [$served['aimla'], ['status' => 500, 'body' => ['message' => 'Server Error']], $second]],
            ['aimla'],
            [
                ['do' => 'enter'],
                ['do' => 'rooms', 'rooms' => ['aimla']],
                ['do' => 'room.map', 'message' => $saved],
                ['do' => 'rooms', 'rooms' => ['aimla']],
                ['do' => 'room.map', 'message' => $saved],
            ],
        );

        [, $held, $failed, $retried, $settled] = $warm;

        $this->assertSame([self::SNAPSHOT, self::BUILDING, self::AIMLA], $held['requests']);

        $this->assertSame(['source' => 'authored', 'map_version' => 1], $this->heldIn($failed, 'aimla'),
            'a failed re-fetch replaced the map the client held');
        $this->assertSame(['status' => 500], $failed['rooms']['aimla']['failure']);
        $this->assertTrue($failed['result']['applied']);

        $this->assertSame([...$failed['requests'], self::AIMLA], $retried['requests'], 'entering a room whose fetch failed did not retry it');
        $this->assertSame(['source' => 'authored', 'map_version' => 2], $this->heldIn($retried, 'aimla'));
        $this->assertNull($retried['rooms']['aimla']['failure']);

        // Recovered, the same message is the version now held and costs nothing.
        $this->assertSame($retried['requests'], $settled['requests']);
        $this->assertSame(['applied' => false, 'line' => null], $settled['result']);

        // ⛔ CONTROL 25 — F16's "Never", planted: a failed request answered with the shipped default.
        $defaulted = $this->mutatedModules([
            '../wire/building.js',
            "            this.#rooms.set(id, { held, failure: { status } });\n",
            "            this.#rooms.set(id, { held: { source: 'default', map_version: null, map: {} }, failure: { status } });\n",
        ]);
        [, $substituted] = $this->scenario($coldResponses, ['aimla'], $coldSteps, $defaulted);

        $this->assertNotNull($substituted['rooms']['aimla']['held'],
            'CONTROL 25 did not bite: the default was put in place of a failed request and the check stayed clean');
    }
}
