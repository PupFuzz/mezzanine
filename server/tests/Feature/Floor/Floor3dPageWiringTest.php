<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **The 3-D floor's page wiring** — `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 4 (card#13033 stage 2 slice 4):
 * `/floor/{floor}/3d` serves the floor page's own body (`partials/floor-page.blade.php`) over the 3-D renderer, and the
 * page is the hoisted `floor/floor-page.js`'s, called a second time.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IT HOLDS: the routes — `/floor/x/3d` is the 3-D floor, `/floor/x/3d/s` its drill-down on `s`, `/floor/x/s` still
 * the 2-D drill-down, all behind login and the second factor, and the route table caches; the page — every `floor-*` id
 * the 2-D page declares is declared here (the hoisted page addresses them all, which `FloorPageWiringTest` holds against
 * the 2-D page), and the 3-D stage's own ids are declared exactly where `floor3d/` addresses them, both ways; the wiring
 * — `startFloorPage(` once in each entry with its own route, the keys wired in `floor-page.js` alone, the house gesture
 * wire and the 2-D painter nowhere in `floor3d/`, and the camera controls' own key listener connected nowhere.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a drawing. `tools/design/floor3d.browser.mjs` is the browser leg that draws the floor and
 * reads it.
 */
class Floor3dPageWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_routes_resolve_in_order_and_the_seat_route_still_opens_the_drill_down(): void
    {
        $this->assertSame([], $this->routeDefects());
    }

    public function test_the_routes_are_behind_login_and_the_second_factor(): void
    {
        foreach (['/floor/aimla/3d', '/floor/aimla/3d/aimla-pm'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->twoFactorUnenrolled()->create())->get($path)->assertRedirect(route('two-factor.enroll'));
            auth()->logout();
        }
    }

    public function test_the_route_table_caches(): void
    {
        $cache = sys_get_temp_dir().'/floor3d-routes-'.getmypid().'.php';
        $out = [];
        $code = 0;

        exec('cd '.escapeshellarg(base_path()).' && APP_ROUTES_CACHE='.escapeshellarg($cache).' php artisan route:cache 2>&1', $out, $code);

        try {
            $this->assertSame(0, $code, 'php artisan route:cache failed: '.implode("\n", $out));
            $this->assertFileExists($cache);
            $this->assertStringContainsString('floor3d.view', (string) file_get_contents($cache));
            $this->assertStringContainsString('floor3d.seat', (string) file_get_contents($cache));
        } finally {
            @unlink($cache);
        }
    }

    public function test_the_page_declares_every_id_the_floor_page_addresses_and_the_stage_ids_both_ways(): void
    {
        $this->assertSame([], $this->pageDefects($this->page('/floor/aimla/3d'), $this->page('/floor/aimla'), $this->floor3dJs()));
    }

    public function test_the_page_serves_the_3d_entry_with_its_segments(): void
    {
        $html = $this->page('/floor/aimla/3d/aimla-pm');

        $this->assertStringContainsString('/js/floor3d/main.js', $html);
        $this->assertStringNotContainsString('/js/floor/main.js', $html);
        $this->assertStringContainsString('data-floor="aimla"', $html);
        $this->assertStringContainsString('data-seat="aimla-pm"', $html);
        // No ride arrives on this route before the plan's slice 6, so it declares no arrival hold.
        $this->assertDoesNotMatchRegularExpression('/<html\b[^>]*\bdata-ride-reveals\b/', $html);
        // The two floor pages link each other, and the 2-D page keeps its link to the building view.
        $this->assertMatchesRegularExpression('#<a class="pill floor-route-pill" href="[^"]*/floor/aimla">2D</a>#', $html);
        $this->assertMatchesRegularExpression('#<a class="pill floor-route-pill" href="[^"]*/floor/aimla/3d">Floor in 3D</a>#', $this->page('/floor/aimla'));
        $this->assertMatchesRegularExpression('#<a class="pill building-pill" href="[^"]*/building">3D</a>#', $this->page('/floor/aimla'));
    }

    public function test_the_wiring_is_the_hoisted_pages_and_the_3d_floor_wires_no_2d_camera(): void
    {
        $this->assertSame([], $this->wiringDefects($this->sources()));
    }

    /** ⛔ THE CONTROLS — each plants one defect a check above exists to catch, and watches it red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $page = $this->page('/floor/aimla/3d');
        $flat = $this->page('/floor/aimla');
        $js = $this->floor3dJs();

        $renamed = str_replace('id="floor3d-labels"', 'id="floor3d-labelz"', $page);
        $this->assertNotSame($renamed, $page);
        $this->assertNotSame([], $this->pageDefects($renamed, $flat, $js), 'CONTROL (a stage id renamed on the page) did not bite');

        $dropped = str_replace('id="floor-panel-retry"', 'id="floor-panel-retried"', $page);
        $this->assertNotSame($dropped, $page);
        $this->assertNotSame([], $this->pageDefects($dropped, $flat, $js), 'CONTROL (a floor page id missing on the 3-D page) did not bite');

        $orphan = str_replace('<canvas id="floor3d-canvas"', '<p id="floor3d-orphan"></p><canvas id="floor3d-canvas"', $page);
        $this->assertNotSame([], $this->pageDefects($orphan, $flat, $js), 'CONTROL (a stage id nothing addresses) did not bite');

        $sources = $this->sources();
        $plants = [
            'the 3-D entry on the 2-D route' => ['floor3d/main.js', "route: '3d' });", "route: '2d' });"],
            'the 3-D entry starting the page twice' => ['floor3d/main.js', "startFloorPage(document, window, { renderer: available ? inWorld() : listOnly(), route: '3d' });", "startFloorPage(document, window, { renderer: listOnly(), route: '3d' });\nstartFloorPage(document, window, { renderer: inWorld(), route: '3d' });"],
            'the house gesture wire on the 3-D floor' => ['floor3d/floor-paint.js', "import { cabTheme } from '../floor/cab-paint.js';", "import { cabTheme } from '../floor/cab-paint.js';\nimport { cameraGestures } from '../wire/camera-gestures.js';\ncameraGestures(null);"],
            'the 2-D painter on the 3-D floor' => ['floor3d/floor-paint.js', "import { cabTheme } from '../floor/cab-paint.js';", "import { cabTheme } from '../floor/cab-paint.js';\nimport { createPainter } from '../floor/painter.js';\ncreatePainter({});"],
            'the controls\' own key listener connected' => ['floor3d/floor-paint.js', '    controls.screenSpacePanning = false;', "    controls.screenSpacePanning = false;\n    controls.listenToKeyEvents(window);"],
            'the keys wired a second time, in the renderer' => ['floor3d/floor-paint.js', "import { cabTheme } from '../floor/cab-paint.js';", "import { cabTheme } from '../floor/cab-paint.js';\nimport { cameraKeys } from '../wire/camera-keys.js';\ncameraKeys(null);"],
        ];

        foreach ($plants as $what => [$file, $anchor, $replacement]) {
            $this->assertStringContainsString($anchor, $sources[$file], "the plant '{$what}' no longer applies — its anchor is gone");
            $planted = [...$sources, $file => str_replace($anchor, $replacement, $sources[$file])];
            $this->assertNotSame([], $this->wiringDefects($planted), "CONTROL ({$what}) did not bite");
        }

        // The route order: the seat route registered first would read `/floor/x/3d` as a seat named `3d`.
        $this->assertNotSame([], $this->routeDefects(['floor.seat']), 'CONTROL (the seat route ahead of the 3-D routes) did not bite');
    }

    /**
     * What each path resolves to. `$first` names routes moved to the head of a copy of the route table — the order a
     * registration before the 3-D routes would give — so the control reads the same table, reordered.
     *
     * @param  list<string>  $first
     * @return list<string>
     */
    private function routeDefects(array $first = []): array
    {
        $routes = Route::getRoutes()->getRoutes();
        $ordered = [...array_filter($routes, fn ($r) => in_array($r->getName(), $first, true)), ...array_filter($routes, fn ($r) => ! in_array($r->getName(), $first, true))];
        $match = function (string $path) use ($ordered): ?array {
            $request = \Illuminate\Http\Request::create($path);

            foreach ($ordered as $route) {
                if ($route->matches($request)) {
                    return [$route->getName(), $route->bind($request)->parameters()];
                }
            }

            return null;
        };

        $want = [
            '/floor/aimla/3d' => ['floor3d.view', ['floor' => 'aimla']],
            '/floor/aimla/3d/aimla-pm' => ['floor3d.seat', ['floor' => 'aimla', 'seat' => 'aimla-pm']],
            '/floor/aimla/aimla-pm' => ['floor.seat', ['floor' => 'aimla', 'seat' => 'aimla-pm']],
            '/floor/aimla' => ['floor', ['floor' => 'aimla']],
        ];
        $defects = [];

        foreach ($want as $path => $expected) {
            $got = $match($path);

            if ($got !== $expected) {
                $defects[] = "{$path} resolves to ".json_encode($got).', not '.json_encode($expected);
            }
        }

        return $defects;
    }

    private function page(string $path): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get($path)->assertOk()->getContent() ?: '';
    }

    /** @return list<string> */
    private function ids(string $html, string $prefix): array
    {
        preg_match_all('/id="('.preg_quote($prefix, '/').'[a-z-]+)"/', $html, $m);
        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function pageDefects(string $page, string $flat, string $js): array
    {
        $defects = [];
        $missing = array_diff($this->ids($flat, 'floor-'), $this->ids($page, 'floor-'));

        if ($missing !== []) {
            $defects[] = 'the 3-D page lacks floor page ids the hoisted page addresses: '.implode(', ', $missing);
        }

        preg_match_all("/getElementById\\('(floor3d-[a-z-]+)'\\)/", $js, $m);
        $addressed = array_values(array_unique($m[1]));
        sort($addressed);
        $this->assertGreaterThan(2, count($addressed), 'floor3d/ addresses almost no element — the parse has stopped reading it');
        $declared = $this->ids($page, 'floor3d-');

        foreach (array_diff($addressed, $declared) as $id) {
            $defects[] = "floor3d/ addresses #{$id}, which the page does not declare";
        }

        foreach (array_diff($declared, $addressed) as $id) {
            $defects[] = "the page declares #{$id}, which nothing in floor3d/ addresses";
        }

        return $defects;
    }

    private function floor3dJs(): string
    {
        return implode("\n", array_map('file_get_contents', (array) glob(public_path('js/floor3d').'/*.js')));
    }

    /** @return array<string, string> every module of `floor/` and `floor3d/`, by its path under `public/js` */
    private function sources(): array
    {
        $out = [];

        foreach (['floor', 'floor3d'] as $dir) {
            foreach ((array) glob(public_path("js/{$dir}").'/*.js') as $file) {
                $out[$dir.'/'.basename((string) $file)] = (string) file_get_contents((string) $file);
            }
        }

        return $out;
    }

    /** @return array<string, string> every page module under `public/js` but the vendored ones, by its path there */
    private function pageModules(): array
    {
        $out = [];
        $root = public_path('js');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $rel = substr((string) $file, strlen($root) + 1);

            if (str_ends_with($rel, '.js') && ! str_starts_with($rel, 'vendor/')) {
                $out[$rel] = (string) file_get_contents((string) $file);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function wiringDefects(array $sources): array
    {
        $defects = [];
        $in = fn (string $needle, string $dir) => array_keys(array_filter($sources, fn ($s, $f) => str_starts_with($f, $dir.'/') && str_contains($s, $needle), ARRAY_FILTER_USE_BOTH));

        foreach (['floor/main.js' => "route: '2d'", 'floor3d/main.js' => "route: '3d'"] as $entry => $route) {
            if (substr_count($sources[$entry], 'startFloorPage(') !== 1 || substr_count($sources[$entry], $route) !== 1) {
                $defects[] = "{$entry} does not call startFloorPage( once with {$route}";
            }
        }

        foreach (['cameraKeys(', 'offerKeys('] as $call) {
            $where = [...$in($call, 'floor'), ...$in($call, 'floor3d')];

            if ($where !== ['floor/floor-page.js']) {
                $defects[] = "{$call} is wired in ".implode(', ', $where).' — the hoisted page alone wires the keys';
            }
        }

        foreach (['cameraGestures(', 'createPainter(', 'cameraView('] as $call) {
            if ($in($call, 'floor3d') !== []) {
                $defects[] = "{$call} appears in ".implode(', ', $in($call, 'floor3d')).' — the 3-D floor runs no 2-D camera or painter';
            }
        }

        foreach ([...$this->pageModules(), ...$sources] as $file => $source) {
            if (str_contains($source, 'listenToKeyEvents')) {
                $defects[] = "{$file} connects the camera controls' own key listener — the keys have one reader, the house wire";
            }
        }

        if (! str_contains($sources['floor3d/floor-paint.js'], "from '../vendor/three/examples/jsm/controls/OrbitControls.js'")) {
            $defects[] = 'floor-paint.js does not import OrbitControls from the vendored add-on';
        }

        return $defects;
    }
}
