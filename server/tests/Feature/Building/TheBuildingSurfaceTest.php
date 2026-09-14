<?php

namespace Tests\Feature\Building;

use App\Building\BuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\ShippedDefaultMap;
use App\Fold\Clock;
use App\Ingest\Counters;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Feature\Feed\FeedTestCase;

/**
 * ⭐ THE ACCEPTANCE TEST `docs/design/FLOOR.md` Appendix B row 12 OWES, in its own words: **"a `503`
 * on a store that cannot be read and never a default served in its place; a token refused as the
 * timeline refuses one; the shipped default answered for an unauthored room with
 * `source: "default"`"** — `docs/design/FLEET-STATE.md § 8.7`'s building surface, card#9208 build
 * slice 2.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ D2 § 11 NUMBERS NO ACCEPTANCE TEST FOR § 8.7, so the gate is row 12's own, and the three clauses
 * above are the whole of what either document states for it; this file asserts those three, plus
 * § 8.7's two worked responses member for member. The feed half — `room.map` and `building.layout`
 * committed with the revision they announce — is the store's property and is asserted beside the
 * store, in `TheAuthoredStoreKeepsEveryRevisionTest`.
 *
 * ⛔ THE THREE CLAUSES ARE ONE HAZARD SEEN FROM THREE SIDES: a client that cannot tell *the operator
 * authored nothing* from *the store is down* draws the wrong building with confidence (§ 8.7). So the
 * `503` is asserted to carry no document, and the default is asserted to say `default`.
 */
class TheBuildingSurfaceTest extends FeedTestCase
{
    private const OPERATOR = 'ops@example.com';

    private function browse(string $path, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->enrolled())->getJson($path);
    }

    private function author(string $installId, string $document): int
    {
        return Floors::save($installId, FloorMap::parse($document), self::OPERATOR);
    }

    /** The room's current revision's `authored_at`, on the wire — what § 8.7's `updated_at` is. */
    private function authoredAt(string $installId, int $revision): string
    {
        return (string) Clock::wire(Revisions::get(Revisions::ROOM_MAP, $installId, $revision)->authored_at);
    }

    private function assertJsonEnvelope(TestResponse $response): void
    {
        // § 8.7: "both are `application/json; charset=utf-8` and carry `api_version` and `server_time`".
        $this->assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame(1, $response->json('api_version'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', (string) $response->json('server_time'));
    }

    // ── GET /api/building ────────────────────────────────────────────────────────────────────

    public function test_a_building_nobody_authored_is_layout_version_0_with_no_floors_and_no_rooms(): void
    {
        // § 8.7: "`layout_version` is `0` with an empty `floors` when no layout was ever saved: today's
        // building, one floor per install." And `rooms[]` "lists the rooms with an authored map and
        // nothing else" — the seat this rig's fleet reports (`aimla`) has none.
        $response = $this->browse('/api/building')->assertOk();

        $this->assertJsonEnvelope($response);
        $this->assertSame(['api_version', 'server_time', 'layout', 'rooms'], array_keys($response->json()));
        $this->assertSame(['layout_version' => 0, 'floors' => []], $response->json('layout'));
        $this->assertSame([], $response->json('rooms'));

        // Empty LISTS on the wire, never `{}` — a client iterates both.
        $this->assertStringContainsString('"floors":[]', $response->getContent());
        $this->assertStringContainsString('"rooms":[]', $response->getContent());
    }

    public function test_the_building_answers_section_87s_worked_response(): void
    {
        // The worked example's building: `aimla` alone and open; `sola` and `zeta` planned side by
        // side, 256 px wide each and so 288 px apart, over a hallway; all three authored.
        $this->author('aimla', FloorMapFixture::valid(12));
        $this->author('sola', FloorMapFixture::sized(8, 5));
        $this->author('sola', FloorMapFixture::sized(8, 6));
        $this->author('zeta', FloorMapFixture::sized(8, 5));

        $hallway = FloorMapFixture::hallway();

        Layouts::save((string) json_encode(['floors' => [
            ['rooms' => ['aimla' => ['form' => 'open']]],
            ['label' => 'the solos', 'hallway' => $hallway, 'rooms' => [
                'zeta' => ['form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
                'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
            ]],
        ]]), self::OPERATOR);
        Layouts::save((string) json_encode(['floors' => [
            ['rooms' => ['aimla' => ['form' => 'open']]],
            ['label' => 'the solos', 'hallway' => $hallway, 'rooms' => [
                'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
                'zeta' => ['form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
            ]],
        ]]), self::OPERATOR);

        $response = $this->browse('/api/building')->assertOk();

        $this->assertJsonEnvelope($response);
        $this->assertSame(['api_version', 'server_time', 'layout', 'rooms'], array_keys($response->json()));
        $this->assertSame(2, $response->json('layout.layout_version'));

        // "the floors, already keyed, labelled and sorted, exactly as the page delivered them before
        // this surface existed" — the page's value is `Layouts::layout()->floors`, so the two are
        // asserted equal rather than the surface re-deriving its own normalisation.
        $this->assertSame(json_decode((string) json_encode(Layouts::layout()->floors), true), $response->json('layout.floors'));

        $this->assertSame([
            ['floor' => 'aimla', 'label' => null, 'rooms' => [['install' => 'aimla', 'form' => 'open']]],
            ['floor' => 'sola', 'label' => 'the solos', 'rooms' => [
                ['install' => 'sola', 'form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
                ['install' => 'zeta', 'form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
            ], 'hallway' => $hallway],
        ], $response->json('layout.floors'));

        // `rooms[]`: every authored room, its current `map_version` and the `authored_at` of that
        // revision — and nothing about seats, slots or the document (§ 8.7's closing boundary).
        $this->assertSame([
            ['install_id' => 'aimla', 'map_version' => 1, 'updated_at' => $this->authoredAt('aimla', 1)],
            ['install_id' => 'sola', 'map_version' => 2, 'updated_at' => $this->authoredAt('sola', 2)],
            ['install_id' => 'zeta', 'map_version' => 1, 'updated_at' => $this->authoredAt('zeta', 1)],
        ], $response->json('rooms'));
    }

    /**
     * ⛔ `layout_version` AND `floors` ARE ONE REVISION'S. A client holds the version it was answered
     * and treats the `building.layout` message carrying that version as already applied (§ 8.7), so a
     * body pairing a new version with old floors keeps the old building on screen with nothing left to
     * replace it.
     *
     * The race is planted rather than hoped for: a save commits the instant the surface's first read
     * of `building_layout` has run. Read once, the body is the revision before that save, whole. Read
     * twice, the save lands between the two reads and the body pairs one revision's version with the
     * other's floors, in whichever direction the reads happen to be ordered — so the assertion is the
     * pairing itself, never one direction of it.
     */
    public function test_a_save_committing_during_the_read_never_tears_the_version_from_its_floors(): void
    {
        Layouts::save((string) json_encode(['floors' => [['rooms' => ['aimla' => ['form' => 'open']]]]]), self::OPERATOR);

        $user = $this->enrolled();
        $planted = false;

        DB::listen(function ($query) use (&$planted) {
            if (! $planted && str_starts_with($query->sql, 'select * from '.$this->wrapTable('building_layout'))) {
                $planted = true;
                Layouts::save((string) json_encode(['floors' => [['rooms' => ['zeta' => ['form' => 'office']]]]]), self::OPERATOR);
            }
        });

        $response = $this->browse('/api/building', $user)->assertOk();

        $this->assertTrue($planted, 'the planted save never fired, so this proves nothing');
        $this->assertSame(2, Layouts::version(), 'the planted save did not commit');

        $version = $response->json('layout.layout_version');
        $floors = BuildingLayout::fromJson((string) Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $version)->document)->floors;

        $this->assertSame(
            json_decode((string) json_encode($floors), true),
            $response->json('layout.floors'),
            "layout_version $version was answered beside another revision's floors",
        );
    }

    public function test_a_removed_room_leaves_rooms_and_a_room_the_layout_places_is_not_listed_unless_authored(): void
    {
        $this->author('aimla', FloorMapFixture::valid(12));
        $this->author('sola', FloorMapFixture::valid(4));
        Floors::remove('aimla', self::OPERATOR);

        Layouts::save((string) json_encode(['floors' => [
            ['rooms' => ['zeta' => ['form' => 'office']]],
        ]]), self::OPERATOR);

        // § 8.7: `rooms[]` "is not a population statement" — `zeta` is placed and unauthored, `aimla`
        // was removed back to the default; neither is listed.
        $this->assertSame(['sola'], array_column($this->browse('/api/building')->assertOk()->json('rooms'), 'install_id'));
    }

    // ── GET /api/building/rooms/{install_id}/map ─────────────────────────────────────────────

    public function test_an_authored_room_answers_its_document_whole_with_the_version_it_is(): void
    {
        // A member Tiled writes as an EMPTY OBJECT, because that is the spelling an associative
        // decode loses: `{}` would come back as `[]` and the document would no longer be "byte for
        // byte what was authored, re-serialised through this envelope" (§ 8.7).
        $map = FloorMapFixture::decoded(12);
        $map['editorsettings'] = new \stdClass;
        $document = FloorMapFixture::encode($map);

        $this->author('aimla', FloorMapFixture::valid(8));
        $this->author('aimla', $document);

        $response = $this->browse('/api/building/rooms/aimla/map')->assertOk();

        $this->assertJsonEnvelope($response);
        $this->assertSame(
            ['api_version', 'server_time', 'install_id', 'source', 'map_version', 'updated_at', 'map'],
            array_keys($response->json()),
        );
        $this->assertSame('aimla', $response->json('install_id'));
        $this->assertSame('authored', $response->json('source'));
        $this->assertSame(2, $response->json('map_version'));
        $this->assertSame($this->authoredAt('aimla', 2), $response->json('updated_at'));
        $this->assertEquals(json_decode($document), json_decode($response->getContent())->map);
        $this->assertStringContainsString('"editorsettings":{}', $response->getContent());
    }

    public function test_an_unauthored_room_answers_the_shipped_default_labelled_default(): void
    {
        // § 2.2's second building row: "`200` with the shipped default, `source: "default"`,
        // `map_version: null` … the row an implementer gets wrong by answering `404`". Three rooms
        // that reach it three ways: one that reports and was never authored, one that has never
        // reported at all, and one that was authored and REMOVED back to the default.
        $this->author('sola', FloorMapFixture::valid(4));
        Floors::remove('sola', self::OPERATOR);

        $default = json_decode((string) file_get_contents(ShippedDefaultMap::path()));

        foreach ([self::INSTALL, 'never-reported', 'sola'] as $room) {
            $response = $this->browse("/api/building/rooms/$room/map")->assertOk();

            $this->assertJsonEnvelope($response);
            $this->assertSame(
                ['api_version', 'server_time', 'install_id', 'source', 'map_version', 'updated_at', 'map'],
                array_keys($response->json()),
                "$room: the default is the same shape as an authored map",
            );
            $this->assertSame($room, $response->json('install_id'));
            $this->assertSame('default', $response->json('source'), "$room was not labelled as the default");
            $this->assertNull($response->json('map_version'));
            $this->assertNull($response->json('updated_at'));
            $this->assertEquals($default, json_decode($response->getContent())->map);
        }
    }

    public function test_an_install_id_that_is_not_a_slug_is_404(): void
    {
        // § 8.7: "The surface answers for any `install_id` that matches D1 § 3.1's slug; one that
        // does not is `404`" — `^[a-z0-9][a-z0-9-]{1,31}$`.
        foreach (['a', 'Aimla', '-aimla', 'aimla_pm', str_repeat('a', 33)] as $notASlug) {
            $this->browse("/api/building/rooms/$notASlug/map")->assertNotFound();
        }

        // …and the boundary on the other side answers.
        $this->browse('/api/building/rooms/'.str_repeat('a', 32).'/map')->assertOk();
        $this->browse('/api/building/rooms/0a/map')->assertOk();
    }

    // ── § 9: browser-only ────────────────────────────────────────────────────────────────────

    public function test_a_read_token_is_refused_exactly_as_the_timeline_refuses_one(): void
    {
        $token = $this->readToken();

        // The timeline's refusal, measured rather than restated, so the comparison is against what
        // that route actually answers.
        $timeline = $this->asMachine($token, '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline');
        $timeline->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

        foreach (['/api/building', '/api/building/rooms/aimla/map'] as $path) {
            $refused = $this->asMachine($token, $path);

            $this->assertSame($timeline->getStatusCode(), $refused->getStatusCode(), "$path: a token was not refused as the timeline refuses one");
            $this->assertSame(
                array_diff_key($timeline->json(), ['server_time' => true]),
                array_diff_key($refused->json(), ['server_time' => true]),
                "$path: the refusal differs from the timeline's",
            );
            $this->assertNull($refused->json('map'));
            $this->assertNull($refused->json('layout'));
        }

        // § 9: "it is **not** `token_wrong_surface` — the token is on the read side".
        $this->assertSame(0, $this->globalCounter(Counters::TOKEN_WRONG_SURFACE));

        // The same paths, the credential § 9 says they take.
        $this->browse('/api/building')->assertOk();
        $this->browse('/api/building/rooms/aimla/map')->assertOk();
    }

    public function test_a_guest_and_a_session_without_mfa_are_refused(): void
    {
        $paths = ['/api/building', '/api/building/rooms/aimla/map'];

        // Guests first: `actingAs()` below holds its user for every later request of this test.
        foreach ($paths as $path) {
            $this->asMachine(null, $path)->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        }

        $unenrolled = User::factory()->create();

        foreach ($paths as $path) {
            $this->actingAs($unenrolled)->getJson($path)
                ->assertForbidden()->assertJsonPath('error', 'two_factor_required');
        }
    }

    // ── § 2.2: fail CLOSED ───────────────────────────────────────────────────────────────────

    /**
     * "a store that cannot be read is `503 fleet_unavailable`, never a default served as though it
     * were the answer". Driven the way `At19ReadAuthTest` drives the snapshot's: the table the read
     * starts from is dropped, so the real driver raises on the real path — the shape of a store
     * failure, not a whole-host outage (that test's docblock owns the distinction).
     */
    public function test_a_room_map_the_store_cannot_read_is_503_and_never_the_default(): void
    {
        $user = $this->enrolled();

        Schema::drop('floors');

        foreach ([self::INSTALL, 'never-reported'] as $room) {
            $response = $this->browse("/api/building/rooms/$room/map", $user);

            $response->assertStatus(503)->assertJsonPath('error', 'fleet_unavailable');

            foreach (['map', 'source', 'map_version'] as $member) {
                $this->assertArrayNotHasKey($member, $response->json(), "a refusal carried `$member`");
            }
        }
    }

    public function test_a_layout_the_store_cannot_read_is_503_and_never_the_empty_building(): void
    {
        $this->assertTheBuildingRefusesWithout('building_layout');
    }

    public function test_room_versions_the_store_cannot_read_are_503_and_never_an_unauthored_building(): void
    {
        $this->assertTheBuildingRefusesWithout('floors');
    }

    private function assertTheBuildingRefusesWithout(string $table): void
    {
        $user = $this->enrolled();

        Schema::drop($table);

        $response = $this->browse('/api/building', $user);

        // An empty `floors` is "today's building" (§ 8.7), and an empty `rooms[]` is every room on
        // the default — served on a failed read either would be a building composed from nothing,
        // which is D3 F17's defect arriving from the server instead of from the client.
        $response->assertStatus(503)->assertJsonPath('error', 'fleet_unavailable');
        $this->assertArrayNotHasKey('layout', $response->json(), "$table: a refusal carried a layout");
        $this->assertArrayNotHasKey('rooms', $response->json(), "$table: a refusal carried rooms");
    }
}
