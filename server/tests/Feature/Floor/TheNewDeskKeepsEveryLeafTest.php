<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The new desk keeps every leaf** — card#11058 B1, `docs/design/FLOOR.md § 5.1`'s *the glance set*
 * (the operator's ruling of 2026-10-02, Q0 (a) + Q1 (B)): the desk draws exactly the ruled set, and
 * every fact it gave up is guaranteed in the drill-down panel AND the desk list.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CHECK IS `desk-leaves-probe.mjs`, OVER THE REAL MODULES — `deskModel()`, `placeDesk()` /
 * `placeBubbles()`, `deskListLines()` and `renderDrillDown()` into a fake root holding the floor page's
 * own slots. Rule (i): every leaf of the committed baseline — the desk as it was drawn before this card
 * (`desk-leaves/fx-desk-leaves-before-11058.json`) — is carried by the panel without `detail` AND by
 * the list. Rule (ii): the desk draws exactly the expected set, a table of predicates each citing its
 * ruling. The population of rule (ii) is every seat of every fixture file plus the planted seats
 * (`desk-leaves/fx-desk-leaves-planted.json`), as their variants; the probe derives it on every run.
 *
 * ⛔ EVERY CRITERION OF THE DESIGN's § 5 B1 IS AN ASSERTION WITH A PLANTED CONTROL seen red here: the
 * module mutations go through `mutatedModules()`, one anchored edit each; the controls on the guard
 * itself (the partition's lock, a ruling, the baseline's keys, the page's slots) mutate a copy of the
 * probe or of its inputs. One `node` process judges every mutated tree of a test.
 */
class TheNewDeskKeepsEveryLeafTest extends TestCase
{
    use DrivesTheScene;

    private const PROBE = __DIR__.'/desk-leaves-probe.mjs';

    private const DATA = __DIR__.'/desk-leaves';

    /** How many `node` processes judge a batch of mutated trees at once. */
    private const PARALLEL = 4;

    /** How many scratch files this test has written — each is removed as the application is torn down. */
    private int $scratch = 0;

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_shipped_desk_keeps_every_leaf_and_draws_exactly_the_ruled_set(): void
    {
        $out = $this->probeRun();
        $result = $out['results'][0];

        $this->assertGreaterThan(0, $out['baseline']['desks'], 'the baseline holds no desk — rule (i) would read nothing');
        $this->assertGreaterThan(0, $result['leaves'], 'no baseline leaf was checked — rule (i) read nothing');
        $this->assertGreaterThan(0, $out['population']['fixture_seats'], 'the fixture walk found no seat — rule (ii) would read nothing');
        $this->assertGreaterThan(0, $out['population']['planted'], 'the planted seats were not read');
        $this->assertGreaterThan(0, $result['desks'], 'rule (ii) judged no desk');
        $this->assertSame([], $result['findings'], $this->say($result['findings']));
    }

    // ── 1. Typed list ──────────────────────────────────────────────────────────────────────────

    /** Criterion 1: a leaf matches only a list line of its own role — the `unrecognised:` line's leaves red without it. */
    public function test_1_the_list_is_read_typed_and_the_unrecognised_line_is_owed_by_every_seat_carrying_one(): void
    {
        $dir = $this->listDropped('unrecognised');
        $findings = $this->probeRun([$dir])['results'][0]['findings'];
        $red = $this->desksWith($findings, fn (array $f): bool => $f['rule'] === 'i-list' && $f['homes'] === ['unrecognised']);

        $owed = $this->baselineDesksWith(fn (array $l): bool => in_array($l[0], ['unrecognised', 'badge.unrecognised'], true));

        $this->assertNotSame([], $owed, 'no baseline desk carries an unrecognised value — the control has nothing to bite on');
        $this->assertSame([], array_values(array_diff($owed, $red)),
            'CONTROL (the unrecognised: line dropped) did not red on every seat carrying one');
    }

    // ── 2. The parts are a partition ───────────────────────────────────────────────────────────

    /**
     * Criterion 2: no rendered text gives both itself and a piece of itself. The property is asserted
     * directly (the green's `partition` findings are none); its control removes the lock — the
     * design-time `parts()` — and the property reds; and with the list read untyped, the dropped
     * `unrecognised:` line reds on the `plant-badges-sparkle…` seats whose one unrecognised value is that
     * badge only while the lock holds.
     */
    public function test_2_the_parts_of_a_text_are_a_partition(): void
    {
        $unlocked = $this->probeCopy('const overlaps = (t, p) => t.taken.some((q) => q.s < p.e && p.s < q.e);',
            'const overlaps = () => false;');

        $this->assertNotSame([], array_filter($this->probeRun([], [], $unlocked)['results'][0]['findings'], fn ($f) => $f['rule'] === 'partition'),
            'CONTROL (the partition\'s lock removed) did not red the property');

        // The seats whose one unrecognised value is the `sparkle` badge: there the `badges:` line can give
        // `badges: sparkle` AND `sparkle` only by giving a text and its own piece.
        $dropped = $this->listDropped('unrecognised');
        $sparkle = fn (array $findings): array => array_values(array_filter(
            $this->desksWith($findings, fn (array $f): bool => $f['rule'] === 'i-list'),
            fn (string $key): bool => str_contains($key, '|plant-badges-sparkle') && ! str_contains($key, 'glitter'),
        ));

        $this->assertNotSame([], $sparkle($this->probeRun([$dropped], ['--untyped-list'])['results'][0]['findings']),
            'CONTROL (untyped, the unrecognised: line dropped) did not red on the plant-badges-sparkle seats');
        $this->assertSame([], $sparkle($this->probeRun([$dropped], ['--untyped-list'], $unlocked)['results'][0]['findings']),
            'without the lock the untyped list still caught the drop — the control no longer shows what the lock adds');
    }

    // ── 3. `hidden` on every node ──────────────────────────────────────────────────────────────

    public function test_3_a_hidden_row_is_never_a_part(): void
    {
        $dir = $this->mutatedModules(['../drilldown/main.js', "        li.textContent = row.text;\n",
            "        li.textContent = row.text;\n        li.hidden = selector === '[data-panel-badges]' || selector === '[data-panel-interns]';\n"]);
        $findings = $this->probeRun([$dir])['results'][0]['findings'];

        foreach (['badges', 'interns'] as $slot) {
            $this->assertNotSame([], array_filter($findings, fn ($f) => $f['rule'] === 'i-panel' && in_array($slot, $f['homes'], true)),
                "CONTROL (every [data-panel-{$slot}] row hidden) did not red");
        }
    }

    // ── 4. `+N more` is a leaf ─────────────────────────────────────────────────────────────────

    public function test_4_the_more_tags_are_leaves(): void
    {
        [$list, $layout] = $this->probeRun([
            $this->listDropped('stool-more'),
            $this->mutatedModules(['../floor/desk-layout.js',
                "            text('stool-more', 'side_table', MORE(more), R['stool-more']);\n", '']),
        ])['results'];

        $this->assertNotSame([], array_filter($list['findings'], fn ($f) => $f['rule'] === 'i-list' && $f['kind'] === 'stool-more'),
            'CONTROL (MORE(table.more) dropped from the list) did not red');
        $this->assertNotSame([], array_filter($layout['findings'], fn ($f) => $f['rule'] === 'ii-missing' && $f['kind'] === 'stool-more'),
            'CONTROL (the stool tag dropped from the desk) did not red');

        $badgeTag = $this->baselineDesksWith(fn (array $l): bool => $l[0] === 'badge-more');
        $this->assertNotSame([], $badgeTag, 'no baseline desk drew the badge row\'s +N more — criterion 4\'s badge half reads nothing');

        $badgesDropped = $this->probeRun([$this->listDropped('badges')])['results'][0]['findings'];
        $this->assertNotSame([], array_filter($badgesDropped, fn ($f) => $f['rule'] === 'i-list' && $f['kind'] === 'badge-more'),
            'CONTROL (the badges line dropped) did not red on a badge the +N more tag counted');
    }

    // ── 5. Baseline keys unique ────────────────────────────────────────────────────────────────

    public function test_5_the_baseline_keys_are_unique_at_load(): void
    {
        $text = (string) file_get_contents(self::DATA.'/fx-desk-leaves-before-11058.json');
        $this->assertSame(1, preg_match('/^( {4}"[^"]*": \{"seat":[^\n]*),\n/m', $text, $m), 'the baseline\'s first desk line did not parse');

        $root = $this->scratchDir();
        file_put_contents($root.'/fx-desk-leaves-before-11058.json', str_replace($m[0], $m[0].$m[1].",\n", $text));
        copy(self::DATA.'/fx-desk-leaves-planted.json', $root.'/fx-desk-leaves-planted.json');

        $this->assertNotSame([], array_filter($this->probeRun([], ['--root', $root])['results'][0]['findings'], fn ($f) => $f['rule'] === 'baseline'),
            'CONTROL (two baseline desks under one key) did not red at load');
    }

    // ── 6. One plant per home ──────────────────────────────────────────────────────────────────

    public function test_6_every_panel_slot_the_guard_types_to_is_a_home_its_plant_reds(): void
    {
        $homes = $this->probeRun()['panel_homes'];
        $this->assertNotSame([], $homes);

        $dirs = array_map(fn (string $slot): string => $this->mutatedModules(['../drilldown/main.js',
            "'[data-panel-{$slot}]'", "'[data-panel-{$slot}-planted]'"]), $homes);

        foreach ($this->probeRun($dirs)['results'] as $i => $result) {
            $this->assertNotSame([], array_filter($result['findings'], fn ($f) => str_starts_with($f['rule'], 'i-panel') && in_array($homes[$i], $f['homes'], true)),
                "CONTROL ([data-panel-{$homes[$i]}] emptied) did not red");
        }
    }

    public function test_6_every_line_role_of_the_list_is_a_home_its_plant_reds(): void
    {
        $roles = $this->probeRun()['list_homes'];
        $this->assertNotSame([], $roles);

        foreach ($this->probeRun(array_map(fn (string $role): string => $this->listDropped($role), $roles))['results'] as $i => $result) {
            $this->assertNotSame([], array_filter($result['findings'], fn ($f) => $f['rule'] === 'i-list' && in_array($roles[$i], $f['homes'], true)),
                "CONTROL (the list's {$roles[$i]} line dropped) did not red");
        }
    }

    // ── 7. Plants as module mutations; the adapter ─────────────────────────────────────────────

    /** Criterion 7: each ruled element's drop, and each thing outside the set, planted in the module it would live in. */
    public function test_7_every_ruled_element_dropped_or_added_reds_rule_ii(): void
    {
        $layout = '../floor/desk-layout.js';
        $plants = [
            'the hatch' => [[$layout, "        rect('lag-overlay', 'lag', R['lag-overlay'], { overlay: desk.lag.overlay });\n", ''], 'ii-missing', 'lag-overlay'],
            'the descriptor' => [[$layout, "        text('monitor-text', 'monitor', desk.action === null ? desk.desk_label : desk.monitor.text, R['monitor-text']);\n", ''], 'ii-missing', 'monitor-text'],
            'the quiet age' => [[$layout, "    text('quiet-age', 'quiet_age', desk.quiet_age, R['quiet-age']);\n", ''], 'ii-missing', 'quiet-age'],
            'the flag off by one' => [[$layout, 'const n = flagCount(desk, row);', 'const n = flagCount(desk, row) + 1;'], 'ii-missing', 'flag'],
            'the flag dropped' => [[$layout, "        elements.push({\n            kind: 'flag',", "        false && elements.push({\n            kind: 'flag',"], 'ii-missing', 'flag'],
            'the chip read from render_state' => [[$layout, 'fit(desk.render_state.recognised ? desk.glyph : UNRECOGNISED,', 'fit(desk.render_state.value,'], 'ii-missing', 'chip'],
            'the 8th intern' => [[$layout, "    const shown = stools.slice(0, STOOL_CAP);\n    const more = (desk.side_table.more ?? 0) + (stools.length - shown.length);",
                "    const shown = stools.slice(0, STOOL_CAP - 1);\n    const more = (desk.side_table.more ?? 0) + Math.max(0, stools.length - STOOL_CAP);"], 'ii-missing', 'stool'],
            'unrecognised badges in the row' => [[$layout, 'const known = desk.badges.filter((id) => !unknown.has(id));', 'const known = desk.badges;'], 'ii-extra', 'badge'],
            'fold_lag out of the row' => [[$layout, '...known.filter((id) => TREATMENT_BADGES.includes(id)),', "...known.filter((id) => TREATMENT_BADGES.includes(id) && id !== 'fold_lag'),"], 'ii-missing', 'badge'],
            'config_invalid out of the row' => [[$layout, '...known.filter((id) => TREATMENT_BADGES.includes(id)),', "...known.filter((id) => TREATMENT_BADGES.includes(id) && id !== 'config_invalid'),"], 'ii-missing', 'badge'],
            'the label line' => [[$layout, "    text('label', 'desk_label', desk.desk_label, R.label);\n", ''], 'ii-missing', 'label'],
            'the currency label' => [[$layout, "    text('currency', 'desk_currency', desk.desk_currency, R.currency);\n", ''], 'ii-missing', 'currency'],
            'the lag line' => [[$layout, "    text('lag', 'lag', desk.lag?.line ?? null, R.lag);\n", ''], 'ii-missing', 'lag'],
            'the nameplate' => [[$layout, "    elements.push({\n        kind: 'nameplate',", "    false && elements.push({\n        kind: 'nameplate',"], 'ii-missing', 'nameplate'],
            'the chip' => [[$layout, "    elements.push({\n        kind: 'chip',", "    false && elements.push({\n        kind: 'chip',"], 'ii-missing', 'chip'],
            'a marker drawn' => [[$layout, "    rect('plate', 'nameplate', R.plate);\n", "    rect('plate', 'nameplate', R.plate);\n    rect('marker', null, R.plate);\n"], 'ii-extra', 'marker'],
            'the monitor dropped with the art' => [[$layout, "    rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n",
                "    if (!ctx.placeholder) rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });\n"], 'ii-missing', 'monitor.lit'],
            'the dimming lost (the placed desk lit full)' => [['../floor/scene.js', "        lighting: model.lighting,\n        bubble_model: bubble,",
                "        lighting: 'full',\n        bubble_model: bubble,"], 'ii-missing', 'group.lighting'],
            'motion forced' => [['../floor/scene.js', 'const moved = elements.map((e) => Object.freeze({ ...e, x: at.x + e.x, y: at.y + e.y }));',
                "const moved = elements.map((e) => Object.freeze({ ...e, ...(e.kind === 'character' ? { animation: { ...e.animation, motion: true } } : {}), x: at.x + e.x, y: at.y + e.y }));"],
                'ii-missing', 'character.motion'],
        ];

        $results = $this->probeRun(array_map(fn (array $p): string => $this->mutatedModules($p[0]), array_values($plants)))['results'];

        foreach (array_keys($plants) as $i => $name) {
            [, $rule, $kind] = $plants[$name];
            $hit = array_filter($results[$i]['findings'], fn ($f) => $f['rule'] === $rule && $f['kind'] === $kind);

            $this->assertNotSame([], $hit, "CONTROL ({$name}) did not red as {$rule} {$kind}: ".$this->say($results[$i]['findings']));
        }
    }

    // ── 8. The gauge's % is read ───────────────────────────────────────────────────────────────

    public function test_8_the_gauge_percentage_is_the_models_own_string(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', "text('gauge-pct', 'gauge', gauge.pct, R['gauge-pct']);",
            "text('gauge-pct', 'gauge', `\${Math.round(gauge.bar)}%`, R['gauge-pct']);"]);

        $this->assertNotSame([], array_filter($this->probeRun([$dir])['results'][0]['findings'], fn ($f) => $f['rule'] === 'ii-missing' && $f['kind'] === 'gauge-pct'),
            'CONTROL (the % re-formatted on the desk) did not red');
    }

    // ── 9. The expected set is data citing its ruling ──────────────────────────────────────────

    public function test_9_every_row_of_the_expected_set_cites_its_ruling_and_a_failure_prints_it(): void
    {
        $out = $this->probeRun();

        $this->assertNotSame([], $out['rows']);

        foreach ($out['rows'] as $row) {
            $this->assertNotSame('', trim((string) $row['ruling']), "the expected-set row {$row['kind']} cites no ruling");
        }

        $unruled = $this->probeCopy("ruling: 'Q5 (b) — the quiet age stays on the desk' },", "ruling: '' },");
        $this->assertNotSame([], array_filter($this->probeRun([], [], $unruled)['results'][0]['findings'], fn ($f) => $f['rule'] === 'ruling'),
            'CONTROL (a row with no ruling) did not fail the build');

        // A rule (ii) failure carries the row's ruling, which `say()` prints beside it.
        $dir = $this->mutatedModules(['../floor/desk-layout.js', "    text('quiet-age', 'quiet_age', desk.quiet_age, R['quiet-age']);\n", '']);
        $missing = array_values(array_filter($this->probeRun([$dir])['results'][0]['findings'], fn ($f) => $f['rule'] === 'ii-missing'));

        $this->assertNotSame([], $missing);
        $this->assertStringContainsString('Q5 (b)', $this->say($missing), 'a rule (ii) failure does not print its ruling');
    }

    // ── 10. No raw unrecognised string on the desk ─────────────────────────────────────────────

    public function test_10_no_raw_unrecognised_string_is_drawn_on_the_desk(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js',
            'const known = desk.badges.filter((id) => !unknown.has(id));', 'const known = desk.badges;']);

        $noRaw = array_filter($this->probeRun([$dir])['results'][0]['findings'], fn ($f) => $f['rule'] === 'no-raw');

        $this->assertNotSame([], $noRaw, 'CONTROL (unrecognised badges re-admitted to the row) did not red Q0');

        // A 64-character single-token id is drawn CUT in a chip: still the raw id on the desk
        // (`raw-on-desk.mjs`, review r1 m1). The planted seat carries one.
        $this->assertNotSame([], array_filter($noRaw, fn ($f) => str_contains($f['where'], 'plant-unrec-badge-single-token')),
            'CONTROL (a cut single-token raw id in the row) did not red Q0');

        // The nameplate is outside the predicate: `plant-dreaming`'s name carries its raw state's word,
        // and the green above holds it — so the seat must be in the population.
        $this->assertNotSame([], $this->baselineDesksWith(fn (array $l): bool => $l[0] === 'nameplate' && $l[2] === 'plant-dreaming'),
            'the plant-dreaming seat is not in the population — the nameplate exclusion is untested');
    }

    /**
     * Rule (ii) accepts a text drawn cut only when its whole value does not fit the width design § 2.2
     * gives it (review r1 m2): a nameplate cut at 30 px fits whole in 148 and reds.
     */
    public function test_ii_a_text_is_drawn_cut_only_when_its_whole_value_does_not_fit(): void
    {
        $dir = $this->mutatedModules(['../floor/desk-layout.js', 'const PLATE_TEXT_W = 148;', 'const PLATE_TEXT_W = 30;']);

        $this->assertNotSame([], array_filter($this->probeRun([$dir])['results'][0]['findings'], fn ($f) => $f['rule'] === 'ii-cut-fits' && $f['kind'] === 'nameplate'),
            'CONTROL (the nameplate cut though it fits) did not red');
    }

    /**
     * The PAINTER puts every node of a desk exactly where its layout element is, and inside that desk's box
     * (`painter-probe.mjs`, review r1 m3 and r2) — the class of a desk-local offset painted as an absolute
     * one, and of a node displaced INSIDE its box, which containment alone reads clean.
     */
    public function test_the_painter_draws_every_node_at_its_layout_element_and_inside_its_desks_box(): void
    {
        $shipped = $this->painterRun();

        $this->assertGreaterThan(0, $shipped['painted'], 'the painter probe read no painted node');
        $this->assertSame([], $shipped['defects']);

        $controls = [
            'the badge text drawn at its desk-local offset' => ['../floor/painter.js', 'x: e.x + e.text_dx, id', 'x: e.text_dx, id'],
            // In-box displacements (review r2): each stays inside the box, so only the equality reds.
            "the chip's rect drawn 40 px right of its element" => ['../floor/painter.js',
                'x: e.x, y: e.y, width: e.w, height: e.h, rx: 7', 'x: e.x + 40, y: e.y, width: e.w, height: e.h, rx: 7'],
            "an intern's glyph drawn 10 px right in its rect" => ['../floor/painter.js',
                'x: e.x + (e.w - STOOL_GLYPH) / 2,', 'x: e.x + (e.w - STOOL_GLYPH) / 2 + 10,'],
            // Q2: the nameplate measured in the fact role and drawn in the name role.
            'the nameplate measured in the wrong type role' => ['../floor/desk-layout.js',
                "fit(desk.nameplate, PLATE_TEXT_W, measure, 'name')", 'fit(desk.nameplate, PLATE_TEXT_W, measure)'],
        ];

        foreach ($controls as $what => $edit) {
            $defects = $this->painterRun(dirname($this->mutatedModules($edit)))['defects'];

            $this->assertNotSame([], $defects, "CONTROL ({$what}) did not red");

            if ($what !== 'the badge text drawn at its desk-local offset') {
                $this->assertSame([], array_values(array_filter($defects, fn (string $d): bool => str_contains($d, "leaves the desk's box"))),
                    "CONTROL ({$what}) left the box — it is planted to stay inside it, where containment alone reads clean");
            }
        }
    }

    /** @return array{desks: int, painted: int, defects: list<string>} */
    private function painterRun(?string $jsRoot = null): array
    {
        $out = shell_exec('node '.escapeshellarg(__DIR__.'/painter-probe.mjs').($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "the painter probe printed something that is not JSON:\n".substr((string) $out, 0, 500));

        return $decoded;
    }

    // ── 11. The panel holds without `detail`; PR-A's slots exist ───────────────────────────────

    public function test_11_the_panel_home_holds_without_detail_and_its_slots_are_the_pages(): void
    {
        $dir = $this->mutatedModules(['../drilldown/main.js', "put(root, '[data-panel-quiet]', transport.quiet_age);",
            "put(root, '[data-panel-quiet]', model.interns.sourced ? transport.quiet_age : null);"]);

        $this->assertNotSame([], array_filter($this->probeRun([$dir])['results'][0]['findings'], fn ($f) => $f['rule'] === 'i-panel-without-detail'),
            'CONTROL (a slot drawn only when detail answered) did not red F11');

        $blade = $this->scratchDir().'/floor.blade.php';
        file_put_contents($blade, str_replace('data-panel-open-calls', 'data-panel-gone',
            (string) file_get_contents(__DIR__.'/../../../resources/views/floor.blade.php')));

        $this->assertNotSame([], array_filter($this->probeRun([], ['--blade', $blade])['results'][0]['findings'], fn ($f) => $f['rule'] === 'slots'),
            'CONTROL (a PR-A slot missing from the page) did not red');
    }

    // ── the rig ────────────────────────────────────────────────────────────────────────────────

    /**
     * Run the probe over `$trees` (each a `mutatedModules()` directory; none = the shipped tree), split
     * across up to `PARALLEL` `node` processes run at once; the results come back in `$trees`' order.
     *
     * @param  list<string>  $trees
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function probeRun(array $trees = [], array $args = [], string $probe = self::PROBE): array
    {
        static $shipped = null;

        if ($trees === [] && $args === [] && $probe === self::PROBE && $shipped !== null) {
            return $shipped;
        }

        $chunks = $trees === [] ? [[]] : array_chunk($trees, (int) ceil(count($trees) / self::PARALLEL));
        $running = [];

        foreach ($chunks as $chunk) {
            $argv = ['node', $probe];

            foreach ($chunk as $tree) {
                array_push($argv, '--js', dirname($tree));
            }

            $process = proc_open([...$argv, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (! is_resource($process)) {
                $this->fail('node could not be started — the guard drives the shipped modules and has nothing to drive them with');
            }

            $running[] = [$process, $pipes, count($chunk)];
        }

        $out = null;

        foreach ($running as [$process, $pipes, $n]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), "the desk leaf probe failed:\n".$stderr);

            $decoded = json_decode($stdout, true);
            $this->assertIsArray($decoded, "the desk leaf probe printed something that is not JSON:\n".substr($stdout, 0, 500));
            $this->assertCount(max(1, $n), $decoded['results'], 'the probe did not judge every tree it was handed');

            $out === null ? $out = $decoded : array_push($out['results'], ...$decoded['results']);
        }

        if ($trees === [] && $args === [] && $probe === self::PROBE) {
            $shipped = $out;
        }

        return $out;
    }

    /**
     * A tree with one of `deskListLines()`'s roles dropped from the row: the line's `text` renamed, so
     * the line has none and the row's own filter drops it.
     */
    private function listDropped(string $role): string
    {
        $source = (string) file_get_contents($this->jsRoot().'/desk/desk-list.js');

        foreach (["role: '{$role}', text: ", "role: '{$role}',\n            text: "] as $anchor) {
            if (substr_count($source, $anchor) === 1) {
                return $this->mutatedModules(['../desk/desk-list.js', $anchor, str_replace('text: ', 'dropped: ', $anchor)]);
            }
        }

        $this->fail("desk-list.js has no line of role `{$role}` to drop");
    }

    /** A copy of the probe beside it (so it reads the same tree and data) with one anchored edit. */
    private function probeCopy(string $anchor, string $replacement): string
    {
        $source = (string) file_get_contents(self::PROBE);
        $this->assertSame(1, substr_count($source, $anchor), 'the probe control\'s anchor is not in the probe exactly once');

        $copy = __DIR__.'/.desk-leaves-probe.control-'.getmypid().'-'.$this->scratch++.'.mjs';
        file_put_contents($copy, str_replace($anchor, $replacement, $source));
        $this->beforeApplicationDestroyed(static fn () => @unlink($copy));

        return $copy;
    }

    private function scratchDir(): string
    {
        $dir = (string) tempnam(sys_get_temp_dir(), 'leaves');
        unlink($dir);
        mkdir($dir);
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            array_map('unlink', (array) glob($dir.'/*'));
            @rmdir($dir);
        });

        return $dir;
    }

    /**
     * The baseline desk keys any of whose leaves `$leaf` accepts.
     *
     * @return list<string>
     */
    private function baselineDesksWith(callable $leaf): array
    {
        static $baseline = null;
        $baseline ??= json_decode((string) file_get_contents(self::DATA.'/fx-desk-leaves-before-11058.json'), true);

        return array_keys(array_filter($baseline['desks'], fn (array $d): bool => array_filter($d['leaves'], $leaf) !== []));
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function desksWith(array $findings, callable $keep): array
    {
        return array_values(array_unique(array_column(array_filter($findings, $keep), 'where')));
    }

    /** The findings, each with the ruling of the expected-set row it failed beside it. */
    private function say(array $findings): string
    {
        return implode("\n", array_map(fn (array $f): string => sprintf('%s %s = «%s» at %s%s',
            $f['rule'], $f['kind'] ?? '', mb_substr((string) ($f['value'] ?? $f['what'] ?? ''), 0, 60), $f['where'] ?? '—',
            isset($f['ruling']) ? "  [ruling: {$f['ruling']}]" : ''), array_slice($findings, 0, 25)))
            .(count($findings) > 25 ? "\n… and ".(count($findings) - 25).' more' : '');
    }
}
