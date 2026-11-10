<?php

namespace Tests\Feature\Building;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\TestCase;

/**
 * **The building view's page wiring** — `docs/design/plan-13033-3d-view.md` § 8 item 4 (card#13033 slice 4; `FLOOR.md`
 * Appendix B row 47): what only the page's own source can show, since there is no browser on this host, held as the
 * source's own lines, each with a control that plants its defect and watches it red.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 *  · THE IDS, BOTH WAYS: every `building-*` id `building/main.js` addresses is declared by `building.blade.php` (else a
 *    `getElementById` answers `null` and a fact is written nowhere), and every id the page declares is written into or
 *    is an `aria-labelledby` target (else a placeholder stands over a fleet that answered). The strip's indicator ids are
 *    built from the model's indicator keys, asked of the model, never listed.
 *  · THE PROTOCOL AND THE LOG are `wire/live-page.js`'s, the log handed to the screen and used nowhere else.
 *  · THE CAMERA'S WIRES: `cameraGestures(` and `cameraKeys(` each called once, on the stage, with the 3-D acts;
 *    `cameraView(` given its `between` and `onArrive`; the page's `show` asking `view.gliding()` before anything else and
 *    the one writer of the stage's `data-gliding="true"`; `offerKeys()` naming only the keys that move something; the
 *    keyboard's focus read by `:focus-visible` in `labels.js`; OrbitControls nowhere under `building/`.
 *  · THE PICTURE is the floor page's painter with the nameplate omitted (`floor-picture.js`), and the floor page's own
 *    painter has no `omit`.
 *  · THE MARKUP offers nothing before the stack is drawn: the stage a group with no tab stop and no keys named, the
 *    buttons, the hint and the camera row hidden, the canvas `aria-hidden`; and the *3D* pill on the lobby and the floor
 *    links to the route by its name.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that anything draws, lays out, glides or reads. That is the browser leg's
 * (`tools/design/building-view.browser.mjs`).
 */
class BuildingPageWiringTest extends TestCase
{
    use DrivesAShippedClientModule;
    use RefreshDatabase;

    protected function moduleDir(): string
    {
        return $this->jsRoot().'/building';
    }

    protected function probeScript(): string
    {
        return __DIR__.'/stack-model-probe.mjs';
    }

    public function test_the_route_serves_the_page_to_a_signed_in_viewer_only(): void
    {
        $this->assertSame(url('/building'), route('building.view'));
        $this->assertNotSame(route('building'), route('building.view'), 'the page took the API\'s route name');
        $this->get('/building')->assertRedirect();
        $this->assertStringContainsString('/js/building/main.js', $this->page());
    }

    public function test_every_element_the_entry_addresses_exists_on_the_page_and_the_reverse(): void
    {
        $this->assertSame([], $this->idDefects($this->page(), $this->mainJs()));
    }

    public function test_the_page_constructs_the_protocol_and_hands_its_log_to_the_screen_alone(): void
    {
        $js = $this->mainJs();

        $this->assertSame([], $this->livePageDefects($js));
        $this->assertSame([], $this->pageLogDefects($js));
        $this->assertSame([], $this->logUseDefects($js));
    }

    public function test_every_import_resolves(): void
    {
        $this->assertGreaterThan(0, $this->assertEveryRelativeImportResolves($this->moduleDir()), 'no relative import was found — the check measured nothing');
    }

    public function test_the_page_wires_the_camera_through_the_house_wires(): void
    {
        $this->assertSame([], $this->cameraDefects($this->sources()));
    }

    public function test_the_markup_offers_nothing_before_the_stack_is_drawn_and_the_entry_links_name_the_route(): void
    {
        $this->assertSame([], $this->markupDefects($this->page(), $this->entryPages()));
    }

    // ── the controls ───────────────────────────────────────────────────────────────────────────

    public function test_the_id_check_goes_red_both_ways(): void
    {
        $html = $this->page();
        $js = $this->mainJs();

        $renamed = str_replace('id="building-sweep"', 'id="building-sweeep"', $html);
        $this->assertNotSame($renamed, $html);
        $this->assertNotSame([], $this->idDefects($renamed, $js), 'CONTROL (an addressed element renamed) did not bite');

        $orphan = str_replace('<p id="building-kept" hidden></p>', '<p id="building-kept" hidden></p><p id="building-orphan"></p>', $html);
        $this->assertNotSame($orphan, $html);
        $this->assertNotSame([], $this->idDefects($orphan, $js), 'CONTROL (a declared element nothing writes) did not bite');

        $widened = $this->mutatedModules(['../lobby/lobby-model.js', "    return [\n        {\n            key: 'store',", "    return [\n        { key: 'purge', label: 'purge', member: 'fleet.purge', value: 'ok', detail: null },\n        {\n            key: 'store',"]);
        $this->assertNotSame([], $this->idDefects($html, $js, $widened), 'CONTROL (a fifth indicator with no element) did not bite — the computed ids are not derived from the model');
    }

    public function test_the_protocol_and_log_checks_go_red(): void
    {
        $js = $this->mainJs();

        foreach ([
            'a protocol of its own' => ['livePage(() => screen.render())', 'new FleetClient(fetch, EventSource, { now: Date.now }) && livePage(() => screen.render())', 'livePageDefects', 'recovery'],
            'a log of its own' => ['startBuildingScreen(client, pageFetch, clock, log, draw, {', 'startBuildingScreen(client, pageFetch, clock, createAnimationLog(), draw, {', 'pageLogDefects', 'retention'],
            'a row written through the log' => ["client.start();\n", "log.edge({ animation_id: 'A14', cause: 'feed.heartbeat', install_id: null, seat_id: null, motion: true, at: 0 });\nclient.start();\n", 'logUseDefects', 'log'],
        ] as $what => [$from, $to, $check, $key]) {
            $planted = str_replace($from, $to, $js);
            $this->assertNotSame($planted, $js, "the {$what} control's anchor is gone");
            $this->assertArrayHasKey($key, $this->{$check}($planted), "CONTROL ({$what}) did not bite");
        }
    }

    public function test_the_camera_check_goes_red_against_each_defect(): void
    {
        $sources = $this->sources();

        foreach ([
            'the show never gated on the glide' => ['main', "    if (view.gliding() || live.stack === null) {\n        return;\n    }\n\n    const from = resting();", "    if (live.stack === null) {\n        return;\n    }\n\n    const from = resting();"],
            'a glide with no interpolation of its own' => ['main', 'cameraView(apply, { between: (a, b, t) => betweenPoses(a, b, t, axis), onArrive: arrive })', 'cameraView(apply, { onArrive: arrive })'],
            'a glide whose arrival tells nobody' => ['main', 'cameraView(apply, { between: (a, b, t) => betweenPoses(a, b, t, axis), onArrive: arrive })', 'cameraView(apply, { between: (a, b, t) => betweenPoses(a, b, t, axis) })'],
            'the gestures on the canvas' => ['main', 'cameraGestures(stage, ', 'cameraGestures(canvas, '],
            'the keys on the canvas' => ['main', 'cameraKeys(stage, ', 'cameraKeys(canvas, '],
            'a second gesture wiring' => ['main', "// *Fit the building*", "cameraGestures(stage, { pan: acts.pan, zoom: acts.zoom, pinch: acts.pinch, drag: acts.drag, camera: acts.camera }, show);\n// *Fit the building*"],
            'the vertical arrows advertised' => ['main', "const SHORTCUTS = '+ - ArrowLeft ArrowRight';", "const SHORTCUTS = '+ - ArrowUp ArrowDown ArrowLeft ArrowRight';"],
            'the keys never offered' => ['main', "    offerKeys(stage, zoomButtons, acts.camera(), SHORTCUTS);\n}\n\n/** One building frame", "}\n\n/** One building frame"],
            'a mouse press read as the keyboard' => ['labels', "        if (event.target.matches(':focus-visible')) {", '        if (true) {'],
            'OrbitControls back' => ['paint', "    WebGLRenderer,\n} from '../vendor/three/three.module.js';", "    WebGLRenderer,\n} from '../vendor/three/three.module.js';\nimport { OrbitControls } from '../vendor/three/OrbitControls.js';"],
            'the nameplate painted twice' => ['picture', "                    omit: new Set(['nameplate']),\n", ''],
            'a second writer of data-gliding' => ['main', "    destination = null;\n    acts.arrived(to.view);", "    destination = null;\n    stage.dataset.gliding = 'true';\n    acts.arrived(to.view);"],
        ] as $what => [$file, $from, $to]) {
            $planted = $sources;
            $planted[$file] = str_replace($from, $to, $sources[$file]);
            $this->assertNotSame($planted[$file], $sources[$file], "the {$what} control's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->cameraDefects($planted), "CONTROL ({$what}) did not bite");
        }

        $floor = $sources;
        $floor['floor'] = str_replace('    painter = createPainter({', "    painter = createPainter({\n        omit: new Set(['nameplate']),", $sources['floor']);
        $this->assertNotSame($floor['floor'], $sources['floor']);
        $this->assertNotSame([], $this->cameraDefects($floor), 'CONTROL (the floor page\'s painter omitting a member) did not bite');
    }

    public function test_the_markup_check_goes_red(): void
    {
        $html = $this->page();
        $entries = $this->entryPages();

        foreach ([
            'a tab stop in the markup' => ['aria-label="the building drawing">', 'aria-label="the building drawing" tabindex="0">'],
            'keys named in the markup' => ['aria-label="the building drawing">', 'aria-label="the building drawing" aria-keyshortcuts="+ -">'],
            'the stage an image' => ['id="building-stage" role="group"', 'id="building-stage" role="img"'],
            'a zoom button shown' => ['id="building-zoom-in" hidden>', 'id="building-zoom-in">'],
            'the hint shown' => ['id="building-hint" class="camera-hint" hidden>', 'id="building-hint" class="camera-hint">'],
            'the camera row shown' => ['id="building-camera-row" class="floor-camera-row" hidden>', 'id="building-camera-row" class="floor-camera-row">'],
            'the canvas in the tree' => ['<canvas id="building-canvas" aria-hidden="true" hidden>', '<canvas id="building-canvas" hidden>'],
            'the read link shown' => ['id="building-read-floor" class="pill" hidden>', 'id="building-read-floor" class="pill">'],
        ] as $what => [$from, $to]) {
            $planted = str_replace($from, $to, $html);
            $this->assertNotSame($planted, $html, "the {$what} control's anchor is gone");
            $this->assertNotSame([], $this->markupDefects($planted, $entries), "CONTROL ({$what}) did not bite");
        }

        foreach (['the lobby', 'the floor'] as $which) {
            $planted = $entries;
            $planted[$which] = str_replace('class="pill building-pill" href="'.route('building.view').'"', 'class="pill building-pill" href="/"', $entries[$which]);
            $this->assertNotSame($planted[$which], $entries[$which], "the {$which} pill control's anchor is gone");
            $this->assertNotSame([], $this->markupDefects($html, $planted), "CONTROL ({$which}'s 3D pill linking elsewhere) did not bite");
        }
    }

    // ── the checks ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function idDefects(string $html, string $js, ?string $moduleDir = null): array
    {
        preg_match_all('/id="(building-[a-z-]+)"/', $html, $d);
        $declared = array_values(array_unique($d[1]));

        preg_match_all("/(?:el|say|list)\\(\\s*'(building-[a-z-]+)'/", $js, $a);
        $addressed = $a[1];
        $this->assertSame(1, preg_match_all('/`building-\$\{[A-Za-z0-9_.]+\}`/', $js), 'main.js builds ids from a template in a number of places this parser does not know');

        foreach ($this->indicatorKeys($moduleDir) as $key) {
            $addressed[] = 'building-'.$key;
        }

        $this->assertGreaterThan(8, count($declared), 'the page declares almost no building elements — the parse has stopped reading it');
        $this->assertGreaterThan(8, count($addressed), 'the entry addresses almost no elements — the parse has stopped reading it');

        preg_match_all('/aria-labelledby="([^"]+)"/', $html, $l);
        $labelled = array_merge(...array_map(static fn (string $v): array => preg_split('/\s+/', $v) ?: [], $l[1]));
        $defects = [];

        foreach (array_diff($addressed, $declared) as $id) {
            $defects[] = "main.js writes #{$id}, which the page does not declare";
        }

        foreach (array_diff($declared, $addressed, $labelled) as $id) {
            $defects[] = "the page declares #{$id}, which nothing writes";
        }

        return array_values($defects);
    }

    /** @return list<string> */
    private function indicatorKeys(?string $moduleDir): array
    {
        $model = dirname($moduleDir ?? $this->moduleDir()).'/lobby/lobby-model.js';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg('const m = await import('.json_encode('file://'.$model).'); console.log(JSON.stringify(m.indicators({}).map((i) => i.key)));'));

        $this->assertIsString($out, 'node could not read the lobby model');

        return json_decode($out, true);
    }

    /** @return array<string, string> the page's log, named exactly where it is taken and handed to the screen */
    private function logUseDefects(string $js): array
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\\\\])//[^\n]*#'], ['', '$1'], $js);
        $wanted = ['requestRender, log } = livePage(', 'startBuildingScreen(client, pageFetch, clock, log, draw, {'];

        foreach ($wanted as $needle) {
            if (! str_contains($code, $needle)) {
                return ['log' => "the page does not name its log where it should (`{$needle}` is missing)"];
            }
        }

        $uses = preg_match_all('/(?<![\w$.\-])log\b(?!-)/', $code);

        return $uses === count($wanted) ? [] : ['log' => "the page names its log {$uses} times, not the ".count($wanted).' it needs'];
    }

    /**
     * @param  array<string, string>  $s  `main`, `labels`, `paint`, `picture`, `floor` (the floor page's entry) and `all` (every building module)
     * @return list<string>
     */
    private function cameraDefects(array $s): array
    {
        $defects = [];
        $main = ModuleSpecifiers::withoutComments($s['main']);

        foreach (['cameraGestures(' => 'cameraGestures(stage, { pan: acts.pan, zoom: acts.zoom, pinch: acts.pinch, drag: acts.drag, camera: acts.camera }, show);',
            'cameraKeys(' => 'cameraKeys(stage, zoomButtons, { zoomStep: acts.zoomStep, drag: acts.drag, camera: acts.camera }, show);'] as $call => $line) {
            if (substr_count($main, $call) !== 1 || ! str_contains($main, $line)) {
                $defects[] = "{$call} is not called once, on the stage, with the 3-D acts and the page's show";
            }
        }

        if (preg_match('/cameraView\(apply, \{ between: \(a, b, t\) => betweenPoses\(a, b, t, axis\), onArrive: arrive \}\)/', $main) !== 1) {
            $defects[] = 'cameraView() is not given the building\'s interpolation and its arrival';
        }

        if (preg_match('/function show\(next\) \{\n    if \(view\.gliding\(\)/', $main) !== 1) {
            $defects[] = 'the page\'s show does not ask view.gliding() before anything else';
        }

        if (substr_count($main, "stage.dataset.gliding = 'true';") !== 1 || preg_match("/function show\\(next\\) \\{.*?stage\\.dataset\\.gliding = 'true';.*?\\n\\}\\n/s", $main) !== 1) {
            $defects[] = 'the page\'s show is not the one writer of data-gliding="true"';
        }

        if (! str_contains($main, "const SHORTCUTS = '+ - ArrowLeft ArrowRight';") || substr_count($main, 'offerKeys(stage, zoomButtons, acts.camera(), SHORTCUTS);') < 1
            || preg_match('/function stand\(stack\) \{.*offerKeys\(stage, zoomButtons, acts\.camera\(\), SHORTCUTS\);\n\}\n/s', $main) !== 1) {
            $defects[] = 'the keys are not offered on the stage, from the acts\' camera, naming only the keys that move something';
        }

        if (preg_match("/addEventListener\\('(wheel|pointerdown|pointermove|keydown)'/", $main, $m) === 1) {
            $defects[] = "the page wires a {$m[1]} of its own beside the house wires";
        }

        if (! str_contains(ModuleSpecifiers::withoutComments($s['labels']), "list.addEventListener('focusin', (event) => {\n        if (event.target.matches(':focus-visible')) {")) {
            $defects[] = 'labels.js reads a focusin that is not the keyboard\'s';
        }

        foreach (['main', 'labels', 'paint', 'picture', 'all'] as $file) {
            if (str_contains(ModuleSpecifiers::withoutComments($s[$file]), 'OrbitControls')) {
                $defects[] = "OrbitControls is in the code under building/ ({$file})";
            }
        }

        if (preg_match("/createPainter\\(\\{\\n\\s*host: painted,\\n\\s*omit: new Set\\(\\['nameplate'\\]\\),/", $s['picture']) !== 1) {
            $defects[] = 'floor-picture.js paints with no nameplate omitted — the name would be drawn twice';
        }

        if (preg_match('/createPainter\(\{[^}]*\bomit\b/s', $s['floor']) === 1) {
            $defects[] = 'the floor page\'s painter omits a member';
        }

        return $defects;
    }

    /**
     * @param  array<string, string>  $entries  the lobby and the floor, rendered
     * @return list<string>
     */
    private function markupDefects(string $html, array $entries): array
    {
        $defects = [];

        if (preg_match('/<div id="building-stage"([^>]*)>/', $html, $stage) !== 1) {
            return ['the page declares no #building-stage'];
        }

        if (! str_contains($stage[1], 'role="group"')) {
            $defects[] = 'the stage is not a group — its labels would be presentational';
        }

        foreach (['tabindex', 'aria-keyshortcuts'] as $attribute) {
            if (str_contains($stage[1], $attribute)) {
                $defects[] = "the stage carries {$attribute} in the markup — offerKeys() offers it once the stack is drawn";
            }
        }

        foreach (['<button type="button" id="building-zoom-in"', '<button type="button" id="building-zoom-out"', '<button type="button" id="building-fit"',
            '<p id="building-hint"', '<div id="building-camera-row"', '<a id="building-read-floor"', '<canvas id="building-canvas"'] as $open) {
            if (preg_match('/'.preg_quote($open, '/').'([^>]*)>/', $html, $m) !== 1 || preg_match('/(^|\s)hidden(\s|$)/', $m[1]) !== 1) {
                $defects[] = "{$open}> is shown in the markup, before the stack is drawn";
            }
        }

        if (preg_match('/<canvas id="building-canvas"([^>]*)>/', $html, $c) !== 1 || ! str_contains($c[1], 'aria-hidden="true"')) {
            $defects[] = 'the canvas is in the accessibility tree — the list describes it';
        }

        foreach ($entries as $which => $page) {
            if (substr_count($page, 'class="pill building-pill" href="'.route('building.view').'"') !== 1) {
                $defects[] = "{$which} carries no one 3D pill to the building view's route";
            }
        }

        return $defects;
    }

    // ── the sources and pages ──────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function sources(): array
    {
        $dir = $this->moduleDir();
        $all = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), (array) glob($dir.'/*.js')));

        return [
            'main' => $this->mainJs(),
            'labels' => (string) file_get_contents($dir.'/labels.js'),
            'paint' => (string) file_get_contents($dir.'/stack-paint.js'),
            'picture' => (string) file_get_contents($dir.'/floor-picture.js'),
            'floor' => (string) file_get_contents($this->jsRoot().'/floor/main.js'),
            'all' => $all,
        ];
    }

    private function mainJs(): string
    {
        return (string) file_get_contents($this->moduleDir().'/main.js');
    }

    private function page(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get('/building')->assertOk()->getContent() ?: '';
    }

    /** @return array<string, string> */
    private function entryPages(): array
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        return [
            'the lobby' => $this->actingAs($user)->get('/dashboard')->assertOk()->getContent() ?: '',
            'the floor' => $this->actingAs($user)->get('/floor/aimla')->assertOk()->getContent() ?: '',
        ];
    }
}
