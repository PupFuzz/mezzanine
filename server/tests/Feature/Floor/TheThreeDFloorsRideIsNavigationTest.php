<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **AT-D3-26 on the 3-D floor — the ride from `/floor/{floor}/3d` is navigation to the destination's 3-D floor**
 * (`docs/design/FLOOR.md` § 4.5; `docs/design/plan-13033-stage2-3d-floor.md` § 3.8 and § 9 slice 6, card#13033). The
 * shape is `TheFloorsRideIsNavigationTest`'s, which holds every phase, guard and arrival of the ride on the 2-D floor;
 * the ride is one state machine for every page (`wire/ride.js`), so what this file adds is what the route kind changes,
 * and the 3-D floor's own control and cab.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ROUTE KIND RIDES WITH THE STOPS. The floor screen started on the 3-D route (`route: '3d'`, as
 * `floor3d/main.js` hands it through `floor/floor-page.js`) offers every floor at its 3-D route (`floorRoute()`), so
 * `opened()` navigates to `/floor/{key}/3d#ride`; the lobby stop is the page's own lobby link either way; and the same run
 * on the 2-D route still navigates to `/floor/{key}#ride`. Each 3-D route the cab offers is served, and declares the
 * arrival's hold.
 *
 * ⛔ THE DOORWAY IS A CONTROL, AND THE CAB IS THE 2-D FLOOR's. `three-d-ride-probe.mjs` drives the shipped
 * `floor3d/floor-labels.js`: the elevator's button is the label layer's first element, `role="button"`, a tab stop with
 * the 2-D floor's name, riding once on a click, Enter and Space, `aria-disabled` while a ride is in flight; and the
 * ride's band leaves on the 3-D floor read `floor/cab-paint.js`'s one table (`rideLeafLevel()` beside `rideLeaves()`). The
 * renderer's `paintCab` is `floor/cab-paint.js`'s own function — imported and handed on, as the 2-D painter hands it —
 * and no file of `floor3d/` draws a cab of its own.
 *
 * ⛔ THE CONTROLS plant each defect this file exists to catch, and watch it red: the doorway not a control (no role; no
 * keyboard route), a 3-D start arriving on the 2-D route, and a second cab drawing path.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a pointer's press on the drawn doorway (it needs WebGL), the cab dialog shown, or the
 * navigation a browser commits — `tools/design/floor3d.browser.mjs`'s `ride` row presses the doorway and reads the cab and
 * the navigation.
 */
class TheThreeDFloorsRideIsNavigationTest extends TestCase
{
    use DrivesTheFloorScreen;
    use RefreshDatabase;

    private const RIDES = 'floor_rides';

    private const LOBBY_HREF = 'http://localhost/dashboard';

    /** The 3-D floor's own modules, read for the cab's drawing path. */
    private const FLOOR3D = __DIR__.'/../../../public/js/floor3d';

    // ── GREEN ────────────────────────────────────────────────────────────────────────────────────

    /** On the 3-D route every floor stop is the floor's 3-D route, then the lobby at the page's link. */
    public function test_green_the_stops_are_the_floors_3d_routes_then_the_lobby(): void
    {
        $frames = $this->rideFrames($this->threeD());

        $this->assertNotSame([], $frames, 'no frame carried a ride');

        foreach ($frames as $render) {
            $this->assertSame(['/floor/aimla/3d', '/floor/kappa/3d', '/floor/the-annex/3d', self::LOBBY_HREF],
                array_column($render['frame']['ride']['stops'], 'href'), "[{$render['at']}] the stops are not the floors' 3-D routes, then the lobby");
        }
    }

    /** `opened()` on a ride begun on the 3-D route navigates to the destination's 3-D route; the lobby stays the lobby. */
    public function test_green_a_ride_begun_in_3d_arrives_in_3d(): void
    {
        $this->assertSame([
            ['phase' => 'leaving', 'route' => '/floor/the-annex/3d#ride'],
            ['phase' => 'leaving', 'route' => '/floor/kappa/3d#ride'],
            ['phase' => 'staying', 'route' => null],
            ['phase' => 'leaving', 'route' => self::LOBBY_HREF.'#ride'],
        ], $this->openedRoutes($this->threeD()));
    }

    /** The same run on the 2-D route still arrives on the 2-D floor. */
    public function test_green_a_ride_begun_in_2d_arrives_in_2d(): void
    {
        $this->assertSame([
            ['phase' => 'leaving', 'route' => '/floor/the-annex#ride'],
            ['phase' => 'leaving', 'route' => '/floor/kappa#ride'],
            ['phase' => 'staying', 'route' => null],
            ['phase' => 'leaving', 'route' => self::LOBBY_HREF.'#ride'],
        ], $this->openedRoutes($this->floorRun(self::RIDES)));
    }

    /** Each 3-D route the cab offers is that floor's 3-D page, which holds the cab's snapshot over the arrival. */
    public function test_green_each_3d_floor_route_deep_links_and_declares_the_arrival(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        foreach (['the-annex', 'kappa', 'aimla'] as $floor) {
            $html = $this->actingAs($user)->get("/floor/{$floor}/3d")->assertOk()->getContent() ?: '';

            $this->assertStringContainsString('data-floor="'.$floor.'"', $html);
            $this->assertStringContainsString('/js/floor3d/main.js', $html);
            $this->assertMatchesRegularExpression('/<html\b[^>]*\bdata-ride-reveals\b/', $html, "/floor/{$floor}/3d holds no arrival");
        }
    }

    /** The doorway's button, and the 3-D leaves read off the 2-D leaves' table. */
    public function test_green_the_doorway_is_a_control_and_its_leaves_are_the_rides(): void
    {
        $out = $this->rideProbe();

        $this->assertSame(['control' => [], 'leaves' => []], $out['legs']);
        $this->assertSame('elevator', $out['read']['layer'][0]);
        $this->assertCount(14, $out['read']['leaves'], 'the leaves leg read fewer than the seven phases twice');
    }

    /** The renderer's cab is the 2-D floor's `paintCab()`, and no 3-D module draws one of its own. */
    public function test_green_the_cab_is_drawn_by_the_one_cab_path(): void
    {
        $this->assertSame([], $this->cabDefects($this->floor3dSources()));
    }

    // ── RED ──────────────────────────────────────────────────────────────────────────────────────

    /** ⛔ THE CONTROLS — each plants one defect in a copy of the shipped tree, or of the 3-D sources, and the check reds. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        // A 3-D start arriving on the 2-D route: the stops' routes left the plaques' own.
        $dir = $this->mutatedModules([self::FLOOR_SCREEN, '?.map((plate) => ({ ...plate, href: floorRoute(options.route, plate.floor, null) })) ?? null;', ';']);
        $this->assertContains('/floor/the-annex#ride', array_column($this->openedRoutes($this->threeD($dir)), 'route'), 'CONTROL (a 3-D start arriving on 2-D) did not bite');

        // The page not handing the screen its route kind: the 2-D routes again.
        $dir = $this->mutatedModules([self::FLOOR_SCREEN, 'href: floorRoute(options.route, plate.floor, null)', "href: floorRoute('2d', plate.floor, null)"]);
        $this->assertContains('/floor/kappa#ride', array_column($this->openedRoutes($this->threeD($dir)), 'route'), 'CONTROL (the route kind ignored) did not bite');

        // The doorway not a control: no role, no keyboard route, no tab stop.
        foreach ([
            'no role' => ["    elevator.setAttribute('role', 'button');\n", ''],
            'no keyboard route' => ["        if (event.key === 'Enter' || event.key === ' ') {", "        if (event.key === 'Enter') {"],
            'no tab stop' => ['    elevator.tabIndex = 0;', '    elevator.tabIndex = -1;'],
            'not first in reading order' => ['            const first = doorway === null ? 0 : 1;', '            const first = 0;'],
            'no aria-disabled in flight' => ["                elevator.setAttribute('aria-disabled', 'true');", ''],
        ] as $what => [$from, $to]) {
            $dir = $this->mutatedModules(['../floor3d/floor-labels.js', $from, $to]);
            $this->assertNotSame([], $this->rideProbe($dir)['legs']['control'], "CONTROL (the doorway: {$what}) did not bite");
        }

        // The 3-D leaves reading a table of their own: the ride's arrival leaving them open.
        $dir = $this->mutatedModules(['../floor/cab-paint.js', "    return { open: band.leaves === 'opens' ? eased : 1 - eased, moving: t < 1 };", '    return { open: eased, moving: t < 1 };']);
        $this->assertNotSame([], $this->rideProbe($dir)['legs']['leaves'], 'CONTROL (the 3-D leaves off the table) did not bite');

        // A second cab drawing path: the renderer's own `paintCab`, or a 3-D module drawing a cab's room.
        $sources = $this->floor3dSources();
        foreach ([
            'the renderer\'s own paintCab' => ['floor-paint.js', '                paintCab,', "                paintCab(host, ride) {\n                    host.replaceChildren();\n                },"],
            'a cab room drawn in floor3d' => ['room-paint.js', "import { CAB_SHADE, CAB_STYLE, cabTheme, rideLeafLevel } from '../floor/cab-paint.js';",
                "import { CAB_SHADE, CAB_STYLE, cabTheme, rideLeafLevel, drawCabInterior } from '../floor/cab-paint.js';\nconst drawn = (host) => drawCabInterior(host, { x: 0, y: 0, w: 1, h: 1 });"],
        ] as $what => [$file, $from, $to]) {
            $this->assertSame(1, substr_count($sources[$file], $from), "the plant '{$what}' no longer applies — its anchor is gone");
            $this->assertNotSame([], $this->cabDefects([...$sources, $file => str_replace($from, $to, $sources[$file])]), "CONTROL ({$what}) did not bite");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    /** The rides run with the floor screen on the 3-D route, as `floor3d/main.js` starts it. */
    private function threeD(?string $dir = null): array
    {
        return $this->floorRun(self::RIDES, $dir, ['floor' => ['route' => '3d'] + $this->fixture(self::RIDES)['floor']]);
    }

    /** @return list<array<string, mixed>> what each `opened()` answered */
    private function openedRoutes(array $result): array
    {
        return array_column(array_values(array_filter($result['ride_acts'], static fn ($a) => $a['act']['act'] === 'opened')), 'answer');
    }

    /** @return list<array<string, mixed>> the frames whose `ride` is not null */
    private function rideFrames(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['ride'] ?? null) !== null));
    }

    /** `three-d-ride-probe.mjs` over the shipped tree, or a mutated copy of it. */
    private function rideProbe(?string $dir = null): array
    {
        return $this->probe([], $dir, __DIR__.'/three-d-ride-probe.mjs');
    }

    /** @return array<string, string> every `floor3d/` module's source, by file name */
    private function floor3dSources(): array
    {
        $sources = [];

        foreach (glob(self::FLOOR3D.'/*.js') ?: [] as $path) {
            $sources[basename($path)] = (string) file_get_contents($path);
        }

        $this->assertArrayHasKey('floor-paint.js', $sources, 'the 3-D floor\'s modules were not found');

        return $sources;
    }

    /** @return list<string> every way the 3-D floor's cab is not the one cab path */
    private function cabDefects(array $sources): array
    {
        $defects = [];
        $paint = $sources['floor-paint.js'];

        if (preg_match("/^import \\{[^}]*\\bpaintCab\\b[^}]*\\} from '\\.\\.\\/floor\\/cab-paint\\.js';$/m", $paint) !== 1) {
            $defects[] = 'floor-paint.js does not import paintCab from floor/cab-paint.js';
        }

        if (preg_match('/^ {16}paintCab,$/m', $paint) !== 1) {
            $defects[] = 'the renderer\'s drawing does not hand on cab-paint.js\'s paintCab as its own member';
        }

        // The cab's drawing primitives are cab-paint.js's: a 3-D module calling one draws a cab of its own.
        foreach ($sources as $file => $source) {
            foreach (['drawCabInterior(', 'drawFloorView(', 'drawLobbyView(', 'drawOfficeView(', 'cabLeaves('] as $call) {
                if (str_contains($source, $call)) {
                    $defects[] = "{$file} calls {$call} — the cab is drawn by cab-paint.js's paintCab() alone";
                }
            }
        }

        return $defects;
    }
}
