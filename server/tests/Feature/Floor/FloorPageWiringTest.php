<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The floor page's own gate** — `docs/design/FLOOR.md` Appendix B row 8: "every element the DOM
 * entry addresses exists on the Blade view and every element the view declares is written into, the
 * view serves the entry as a module whose every import resolves, and a control plants each defect
 * the check exists to catch and watches it red". Shaped like `Tests\Feature\Lobby\LobbyPageWiringTest`.
 * card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT READS THE FLOOR PAGE ALONE — the served view and `public/js/floor/main.js` — and names no
 * fixture and not the harness, as the row says. Every fact the page draws is asserted headlessly
 * elsewhere; what this holds is the contract between the page's two halves, which no browser on the
 * build host can witness.
 *
 * ⛔ AND THE TWO THINGS ROW 8 SAYS THE PAGE MUST CARRY THAT NOTHING ELSE CAN CHECK: that the client
 * protocol is constructed WITH its scheduler — a real `EventSource` without the stream recovery
 * inherits the browser's own reconnect (row 8's ⛔) — and that the animation log is constructed with
 * § 12's retention figure (§ 14 item 26), re-derived from § 12 rather than copied here.
 *
 * ⛔ WIDENED TO THE PAINTER's ELEMENTS (Appendix B row 14, card#7341 step 11): the page's drawing is
 * `public/js/floor/painter.js`'s, which addresses the view's drawing element itself, so the ids it
 * addresses join the entry's in the both-directions check, and the entry must construct the painter
 * from that module — a painter nobody constructs addresses nothing and would pass vacuously.
 *
 * ⛔ AND TO THE CAMERA's (Appendix B row 15): the entry hands the screen the viewer's viewport and
 * wires the wheel (the event's `deltaMode` and `ctrlKey` with its `deltaY`), the drag (the primary button only, and
 * ended by a `pointercancel` or by a move with that button no longer held), the keyboard, the zoom buttons, the fit-floor control and a resize (which
 * re-shows the camera at once, stopping a glide) to the screen's camera acts — a camera no event
 * reaches is a floor that never moves and would pass AT-D3-21, which drives the acts directly. And the
 * drawing is reachable: it takes focus, and neither it nor the painter's `<svg>` is an image, whose
 * children — the desks — would be presentational; a desk activates on Enter and Space, and the desk the
 * keyboard was on keeps focus across the painter's rebuild of the `<svg>`.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything renders, lays out or is legible.
 */
class FloorPageWiringTest extends TestCase
{
    use DrivesAShippedClientModule;
    use RefreshDatabase;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/fleet-client-probe.mjs';
    }

    public function test_every_element_the_entry_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $this->assertSame([], $this->wiringDefects($this->floorPage(), $this->mainJs()));
    }

    /** Appendix B row 14: the entry constructs the painter from `floor/painter.js`, whose ids are checked above. */
    public function test_the_entry_paints_the_room_with_the_painter_module(): void
    {
        $this->assertSame([], $this->painterDefects($this->mainJs()));
    }

    /** Appendix B row 15: the viewport reaches the screen and every viewer act reaches the camera. */
    public function test_the_entry_wires_the_viewport_and_the_viewers_acts_to_the_screens_camera(): void
    {
        $this->assertSame([], $this->cameraDefects($this->mainJs()));
    }

    /** Appendix B row 15: the drawing takes the keyboard's focus and exposes the desks inside it. */
    public function test_the_drawing_is_focusable_and_never_an_image_so_its_desks_are_exposed(): void
    {
        $this->assertSame([], $this->exposureDefects($this->floorPage(), $this->painterJs()));
    }

    public function test_the_page_serves_the_entry_as_a_module_and_every_import_resolves(): void
    {
        $html = $this->floorPage();

        $this->assertStringContainsString('type="module"', $html);
        $this->assertStringContainsString('/js/floor/main.js', $html, 'the page does not load the floor entry');
        $this->assertStringContainsString('data-floor="aimla"', $html, 'the route did not hand the page its floor segment');
        $this->assertGreaterThan(0, $this->assertEveryRelativeImportResolves($this->moduleDir()),
            'no relative import was found — the check measured nothing');
    }

    /** § 4.4: the route is inside the `auth` + `mfa` group, as the lobby is. */
    public function test_the_route_is_behind_login_and_the_second_factor(): void
    {
        $this->get('/floor/aimla')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->twoFactorUnenrolled()->create())
            ->get('/floor/aimla')
            ->assertRedirect(route('two-factor.enroll'));
    }

    public function test_the_entry_constructs_the_protocol_with_its_recovery_and_the_log_with_section_12s_bound(): void
    {
        $this->assertSame([], $this->bindingDefects($this->mainJs()));
    }

    /** ⛔ THE CONTROLS — each re-mints one defect this file exists to catch. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $html = $this->floorPage();
        $js = $this->mainJs();

        $renamed = str_replace('id="floor-feed"', 'id="floor-feeed"', $html);
        $this->assertNotSame($renamed, $html);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($renamed, $js),
            'CONTROL (renamed element) did not bite');

        $orphaned = str_replace('<p id="floor-kept" hidden></p>', '<p id="floor-kept" hidden></p><p id="floor-orphan"></p>', $html);
        $this->assertNotSame($orphaned, $html);
        $this->assertArrayHasKey('unwritten', $this->wiringDefects($orphaned, $js),
            'CONTROL (an element nothing writes into) did not bite');

        $widened = $this->mutatedModules(['../lobby/lobby-model.js',
            "    return [\n        {\n            key: 'store',",
            "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',"]);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($html, $js, $widened),
            'CONTROL (a fifth indicator with no element) did not bite — the computed ids are not derived from the model');

        // The construction moved to `wire/live-page.js` at the lobby's step (card#7341 step 9), so the
        // scheduler plant is planted THERE, and a second plant is the page walking around it.
        $livePage = (string) file_get_contents($this->jsRoot().'/wire/live-page.js');
        $unscheduled = str_replace('new FleetClient(pageFetch, PageEventSource, clock, timers)', 'new FleetClient(pageFetch, PageEventSource, clock)', $livePage);
        $this->assertNotSame($unscheduled, $livePage);
        $this->assertArrayHasKey('recovery', $this->livePageDefects($js, $unscheduled),
            'CONTROL (the protocol constructed without its scheduler) did not bite');

        $bypassed = str_replace('livePage(() => screen.render())', 'new FleetClient(fetch, EventSource, { now: Date.now }) && livePage(() => screen.render())', $js);
        $this->assertNotSame($bypassed, $js);
        $this->assertArrayHasKey('recovery', $this->bindingDefects($bypassed),
            'CONTROL (the page constructing a protocol of its own beside wire/live-page.js) did not bite');

        $unbounded = str_replace('createAnimationLog(ANIMATION_LOG_RETENTION)', 'createAnimationLog()', $js);
        $this->assertNotSame($unbounded, $js);
        $this->assertArrayHasKey('retention', $this->bindingDefects($unbounded),
            'CONTROL (the log constructed with no bound) did not bite');

        $misaddressed = str_replace("getElementById('floor-drawing')", "getElementById('floor-drawinq')", $this->painterJs());
        $this->assertNotSame($misaddressed, $this->painterJs());
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($html, $js, null, $misaddressed),
            'CONTROL (the painter addressing an element the page does not declare) did not bite');

        $undrawn = preg_replace('/<div id="floor-drawing"[^>]*><\/div>/', '', $html);
        $this->assertNotSame($undrawn, $html);
        $this->assertArrayHasKey('undeclared', $this->wiringDefects($undrawn, $js),
            'CONTROL (the view without the painter\'s drawing element) did not bite');

        $unpainted = str_replace("import { createPainter, loadArt, measurer } from './painter.js';", '', $js);
        $this->assertNotSame($unpainted, $js);
        $this->assertArrayHasKey('painter', $this->painterDefects($unpainted),
            'CONTROL (an entry that never constructs the painter) did not bite');

        $unwheeled = str_replace('show(screen.wheel(', 'show(screen.camera(', $js);
        $this->assertNotSame($unwheeled, $js);
        $this->assertArrayHasKey('wheel', $this->cameraDefects($unwheeled),
            'CONTROL (a wheel that never reaches the camera) did not bite');

        $modeless = str_replace('{ deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }', '{ deltaY: event.deltaY, ctrlKey: event.ctrlKey }', $js);
        $this->assertNotSame($modeless, $js);
        $this->assertArrayHasKey('wheel', $this->cameraDefects($modeless),
            'CONTROL (a wheel whose deltaMode never reaches the camera) did not bite');

        $pinchless = str_replace('{ deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }', '{ deltaY: event.deltaY, deltaMode: event.deltaMode }', $js);
        $this->assertNotSame($pinchless, $js);
        $this->assertArrayHasKey('wheel', $this->cameraDefects($pinchless),
            'CONTROL (a wheel whose ctrlKey — a pinch — never reaches the camera) did not bite');

        $uncancelled = str_replace("drawing.addEventListener('pointercancel'", "drawing.addEventListener('pointerleave'", $js);
        $this->assertNotSame($uncancelled, $js);
        $this->assertArrayHasKey('drag', $this->cameraDefects($uncancelled),
            'CONTROL (a drag no pointercancel ends) did not bite');

        $unreleased = str_replace('if (drag === null || (event.buttons & 1) === 0) {', 'if (drag === null) {', $js);
        $this->assertNotSame($unreleased, $js);
        $this->assertArrayHasKey('drag', $this->cameraDefects($unreleased),
            'CONTROL (a drag a buttonless move does not end — its pointerup was released outside) did not bite');

        $anyButton = str_replace('if (!event.isPrimary || event.button !== 0) {', 'if (false) {', $js);
        $this->assertNotSame($anyButton, $js);
        $this->assertArrayHasKey('drag', $this->cameraDefects($anyButton),
            'CONTROL (a drag any button starts) did not bite');

        $stale = str_replace("    screen.resize(viewport(), surface());\n    show(screen.camera());\n", "    screen.resize(viewport(), surface());\n", $js);
        $this->assertNotSame($stale, $js);
        $this->assertArrayHasKey('resize', $this->cameraDefects($stale),
            'CONTROL (a resize that leaves a glide running over the old surface) did not bite');

        $keyless = str_replace("drawing.addEventListener('keydown'", "drawing.addEventListener('keyup-never'", $js);
        $this->assertNotSame($keyless, $js);
        $this->assertArrayHasKey('keyboard', $this->cameraDefects($keyless),
            'CONTROL (a keyboard that never reaches the camera) did not bite');

        $buttonless = str_replace("el('floor-zoom-in').addEventListener('click', () => {\n    show(screen.zoomStep(1));", "el('floor-zoom-in').addEventListener('click', () => {\n    show(screen.camera());", $js);
        $this->assertNotSame($buttonless, $js);
        $this->assertArrayHasKey('buttons', $this->cameraDefects($buttonless),
            'CONTROL (a zoom button that never reaches the camera) did not bite');

        $painter = $this->painterJs();
        $imaged = str_replace("class: 'floor-scene', role: 'group'", "class: 'floor-scene', role: 'img'", $painter);
        $this->assertNotSame($imaged, $painter);
        $this->assertArrayHasKey('painter', $this->exposureDefects($html, $imaged),
            'CONTROL (the drawing painted as an image, its desks presentational) did not bite');

        $enterOnly = str_replace("if (event.key === 'Enter' || event.key === ' ') {", "if (event.key === 'Enter') {", $painter);
        $this->assertNotSame($enterOnly, $painter);
        $this->assertArrayHasKey('activate', $this->exposureDefects($html, $enterOnly),
            'CONTROL (a desk Space does not activate) did not bite');

        $unrestored = preg_replace('/\n\s*\(svg\.querySelector\(`\[data-key=[^\n]*\n/', "\n", $painter);
        $this->assertNotSame($unrestored, $painter);
        $this->assertArrayHasKey('refocus', $this->exposureDefects($html, $unrestored),
            'CONTROL (a rebuild that drops the focused desk\'s focus) did not bite');

        $unfocusable = str_replace(' tabindex="0" aria-keyshortcuts', ' aria-keyshortcuts', $html);
        $this->assertNotSame($unfocusable, $html);
        $this->assertArrayHasKey('focus', $this->exposureDefects($unfocusable, $painter),
            'CONTROL (a drawing the keyboard cannot reach) did not bite');

        $blind = str_replace('    viewport: viewport(),', '', $js);
        $this->assertNotSame($blind, $js);
        $this->assertArrayHasKey('viewport', $this->cameraDefects($blind),
            'CONTROL (a screen handed no viewport) did not bite');

        $drifted = str_replace('const ANIMATION_LOG_RETENTION = 2000;', 'const ANIMATION_LOG_RETENTION = 5000;', $js);
        $this->assertNotSame($drifted, $js);
        $this->assertArrayHasKey('retention', $this->bindingDefects($drifted),
            'CONTROL (a retention figure that is not § 12\'s) did not bite');
    }

    /** @return array<string, string> */
    private function wiringDefects(string $html, string $js, ?string $jsRoot = null, ?string $painter = null): array
    {
        $declared = $this->declaredIds($html);
        $addressed = array_values(array_unique(array_merge(
            $this->addressedIds($js, $jsRoot),
            $this->painterIds($painter ?? $this->painterJs()),
        )));
        sort($addressed);

        $this->assertGreaterThan(10, count($declared), 'the page declares almost no floor elements — the parse has stopped reading it');
        $this->assertGreaterThan(10, count($addressed), 'the entry addresses almost no elements — the parse has stopped reading main.js');

        $defects = [];
        $undeclared = array_values(array_diff($addressed, $declared));
        $unwritten = array_values(array_diff($declared, $this->accountedFor($html, $addressed)));

        if ($undeclared !== []) {
            $defects['undeclared'] = 'main.js writes into elements the page does not declare: '.implode(', ', $undeclared);
        }

        if ($unwritten !== []) {
            $defects['unwritten'] = 'the page declares elements nothing writes into: '.implode(', ', $unwritten);
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function bindingDefects(string $js): array
    {
        $defects = $this->livePageDefects($js);

        $this->assertSame(1, preg_match('/^\| The floor page\'s animation-log retention \| \*\*([\d,]+) rows\*\*/m', $this->floorMd(), $m),
            '§ 12\'s retention row did not parse');

        if (preg_match('/const ANIMATION_LOG_RETENTION = (\d+);/', $js, $c) !== 1
            || (int) $c[1] !== (int) str_replace(',', '', $m[1])
            || ! str_contains($js, 'createAnimationLog(ANIMATION_LOG_RETENTION)')) {
            $defects['retention'] = 'the animation log is not constructed with § 12\'s retention figure';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function cameraDefects(string $js): array
    {
        $defects = [];
        $wired = [
            'viewport' => ['viewport: viewport(),', 'screen.resize(viewport(), surface())'],
            'wheel' => ["drawing.addEventListener('wheel'", 'show(screen.wheel(', '{ deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }'],
            'drag' => ["drawing.addEventListener('pointermove'", 'show(screen.drag(', 'if (!event.isPrimary || event.button !== 0) {',
                "drawing.addEventListener('pointercancel'", 'if (drag === null || (event.buttons & 1) === 0) {'],
            'resize' => ["    screen.resize(viewport(), surface());\n    show(screen.camera());\n"],
            'keyboard' => ["drawing.addEventListener('keydown'", 'show(screen.zoomStep(KEY_ZOOM[event.key]))', 'show(screen.drag(...KEY_PAN[event.key]))'],
            'buttons' => ["el('floor-zoom-in').addEventListener('click', () => {\n    show(screen.zoomStep(1));",
                "el('floor-zoom-out').addEventListener('click', () => {\n    show(screen.zoomStep(-1));"],
            'fit' => ["el('floor-fit').addEventListener('click'", 'screen.fitFloor()'],
        ];

        foreach ($wired as $act => $needles) {
            foreach ($needles as $needle) {
                if (! str_contains($js, $needle)) {
                    $defects[$act] = "the entry does not wire the {$act} to the screen's camera (`{$needle}` is missing)";
                }
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function exposureDefects(string $html, string $painter): array
    {
        $defects = [];

        if (preg_match('/<div id="floor-drawing"([^>]*)>/', $html, $m) !== 1) {
            return ['focus' => 'the page declares no #floor-drawing'];
        }

        if (! str_contains($m[1], 'tabindex="0"')) {
            $defects['focus'] = 'the drawing takes no keyboard focus, so its keys reach nothing';
        }

        if (str_contains($m[1], 'role="img"')) {
            $defects['drawing'] = 'the drawing is an image — its desks are presentational';
        }

        if (preg_match("/svg = node\\('svg', \\{[^}]*role: '(\\w+)'/", $painter, $r) !== 1 || $r[1] === 'img') {
            $defects['painter'] = 'the painter\'s <svg> is an image (or states no role) — its desks are presentational';
        }

        if (! str_contains($painter, "if (event.key === 'Enter' || event.key === ' ') {")) {
            $defects['activate'] = 'a desk activates on Enter alone — a role="button" also answers Space';
        }

        // The painter rebuilds the <svg> on every paint: the focused desk's key is noted before the
        // rebuild and focus is put back on the rebuilt desk after it, or a keyboard user on a desk
        // drops to the page's body at the next render.
        $noted = strpos($painter, 'const focusedKey = host.contains(document.activeElement)');
        $rebuilt = strpos($painter, 'host.replaceChildren(svg);');
        $restored = strpos($painter, 'svg.querySelector(`[data-key="${CSS.escape(focusedKey)}"]`)');

        if ($noted === false || $rebuilt === false || $restored === false || ! ($noted < $rebuilt && $rebuilt < $restored)) {
            $defects['refocus'] = 'the painter does not put focus back on the desk it rebuilt — noted before `host.replaceChildren(svg)`, restored after it';
        }

        return $defects;
    }

    private function floorPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/floor/aimla')
            ->assertOk()
            ->getContent() ?: '';
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    private function painterJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/painter.js');
    }

    /**
     * Every `floor-*` id the painter addresses — it finds its own drawing element.
     *
     * @return list<string>
     */
    private function painterIds(string $painter): array
    {
        preg_match_all("/getElementById\(\s*'(floor-[a-z-]+)'/", $painter, $m);

        $this->assertNotSame([], $m[1], 'the painter addresses no element — the parse has stopped reading painter.js');

        return $m[1];
    }

    /** @return array<string, string> */
    private function painterDefects(string $js): array
    {
        return str_contains($js, "import { createPainter, loadArt, measurer } from './painter.js';")
            && preg_match('/=\s*createPainter\(/', $js) === 1
            ? []
            : ['painter' => 'the entry does not construct the room drawing\'s painter from floor/painter.js'];
    }

    /** @return list<string> */
    private function declaredIds(string $html): array
    {
        preg_match_all('/id="(floor-[a-z-]+)"/', $html, $m);

        $ids = array_values(array_unique($m[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Every `floor-*` id main.js addresses: the literals its three helpers name, plus the family it
     * builds from the lobby model's indicator keys — derived from the model, never listed.
     *
     * @return list<string>
     */
    private function addressedIds(string $js, ?string $jsRoot = null): array
    {
        preg_match_all("/(?:el|say|list)\(\s*'(floor-[a-z-]+)'/", $js, $m);
        $ids = $m[1];

        $this->assertSame(1, preg_match_all('/`floor-\$\{[A-Za-z0-9_.]+\}`/', $js),
            'main.js builds element ids from a template in a number of places this parser does not know');

        foreach ($this->indicatorKeys($jsRoot) as $key) {
            $ids[] = 'floor-'.$key;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function indicatorKeys(?string $floorDir = null): array
    {
        $model = dirname($floorDir ?? $this->moduleDir()).'/lobby/lobby-model.js';
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'console.log(JSON.stringify(m.indicators({}).map((i) => i.key)));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the lobby model');

        return json_decode($out, true);
    }

    /** @return list<string> */
    private function accountedFor(string $html, array $addressed): array
    {
        preg_match_all('/aria-labelledby="([^"]+)"/', $html, $m);

        $aria = [];

        foreach ($m[1] as $value) {
            foreach (preg_split('/\s+/', $value) ?: [] as $id) {
                $aria[] = $id;
            }
        }

        return array_values(array_unique(array_merge($addressed, $aria)));
    }
}
