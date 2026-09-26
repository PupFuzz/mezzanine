<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The list view's own guard** — `docs/design/FLOOR.md` Appendix B row 15's gate column: "every leaf
 * of `deskModel()`'s output is either rendered — the module's own text form of that value is on the
 * row — or excluded by path with a reason; and every rendered leaf is seen at a value other than the
 * model's default — not null, not false, not 0, not empty — on at least one run over § 11's fixtures,
 * `fx-confirm`'s `missing_persistent` run among them". card#7341 row 15, slice A.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ BOTH POPULATIONS ARE DERIVED, NEITHER IS LISTED. The RUNS are every run of every checked-in
 * fixture file (`fixtureFileNames()`, the one population every harness reader shares) that draws a
 * desk: a run that starts the floor screen is replayed as written, a run that starts no renderer is
 * replayed under the desk floor — the page condition `DrivesTheDeskFloor` already adds — and a lobby
 * run is skipped because the lobby draws no desk model. The LEAVES are whatever `deskModel()` returned
 * on those runs, walked by `desk-list-probe.mjs`; nothing here or in the module names a fact the model
 * emits except the exclusions, and a stale exclusion reds too.
 *
 * ⛔ "RENDERED" IS MEASURED ON THE ROW (the probe perturbs each non-default leaf and watches the row),
 * and "SEEN" IS PER LEAF: a printed leaf no run ever drives off its default is a leaf the row could
 * drop without any run noticing, and it reds by name.
 */
class TheListViewRendersEveryDeskMemberTest extends TestCase
{
    use DrivesTheFloorScreen;

    /** The run the gate names — § 2.3 row 5's unconfirmed desk is only ever drawn on it. */
    private const CONFIRM_RUN = 'missing_persistent';

    public function test_every_leaf_of_the_desk_model_is_on_the_row_or_excluded_by_path(): void
    {
        $this->assertSame([], $this->defects($this->verdict()));
    }

    public function test_the_runs_are_every_desk_drawing_fixture_run_and_include_the_confirm_run(): void
    {
        $runs = array_keys($this->collected()['per_run']);

        $this->assertContains(self::CONFIRM_RUN, $runs, 'the guard did not replay the run its gate names');
        $this->assertGreaterThan(0, $this->collected()['per_run'][self::CONFIRM_RUN],
            'missing_persistent drew no desk — the unconfirmed desk has no run to be seen on');
        $this->assertGreaterThan(20, count($runs), 'the guard replayed almost no runs — the fixture walk has stopped reading');
    }

    /**
     * `floor/main.js`'s `paintDesks()` writes text only from `deskListRow()`'s output: the nameplate's
     * line into the link, the rest through `items()`, no other text sink, and no `desk.<member>` read
     * beside the two ids it routes by. `deskLine()` is gone, not kept beside it.
     */
    public function test_the_floor_page_paints_the_list_views_lines(): void
    {
        $this->assertSame([], $this->pageDefects((string) file_get_contents($this->jsRoot().'/floor/main.js')));
    }

    /**
     * ⛔ THE CONTROLS (canon #9) — each check seen red, naming what it caught:
     *   · a boolean member added to the desk model and held `false` throughout — never non-default;
     *   · a member that is an object on one desk and a non-default string on the others — a primitive on
     *     a path the walk treats as interior;
     *   · a printed member dropped from the row — on the model, and on no line;
     *   · `labelLine()` dropping `dark.age` — an exclusion whose carrier no longer carries it;
     *   · a ghost path in `NOT_LISTED` — an exclusion of a leaf no desk has;
     *   · the *unconfirmed* word dropped — a `true` whose flip leaves the row unchanged;
     *   · the open-call line dropped — a number whose sentinel is on no line;
     *   · the page composing a desk line of its own again, or appending a model member to the list view's.
     */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $planted = $this->mutatedModules(['../desk/desk-render.js',
            "        nameplate: seat.seat_id,\n", "        nameplate: seat.seat_id,\n        planted_flag: false,\n"]);
        // Over ONE fixture file's runs, to hold the suite's time: a leaf held false on every run is
        // held false on any subset of them, so the red it must name does not depend on the population.
        $this->assertRedNaming($this->defects($this->verdict($planted, $planted, 'fx-degraded')), '`planted_flag`',
            'never seen at a non-default value', 'a boolean leaf held false on every run');

        $mixed = $this->mutatedModules(['../desk/desk-render.js', "        nameplate: seat.seat_id,\n",
            "        nameplate: seat.seat_id,\n        planted_mixed: seat.seat_id === 'aimla-lagged' ? { inner: 'x' } : seat.seat_id,\n"]);
        $this->assertRedNaming($this->defects($this->verdict($mixed, $mixed, 'fx-degraded')), '`planted_mixed`',
            'is a non-default primitive on a path', 'a string on a path another desk carries an object at');

        foreach ([
            'a printed member dropped from the row' => [['../desk/desk-list.js', "        desk.config_note,\n", ''],
                '`config_note`', 'neither on the row nor named'],
            'the unconfirmed word dropped (a true whose flip leaves the row unchanged)' => [['../desk/desk-list.js',
                'desk.unconfirmed ? UNCONFIRMED : null', 'null'], '`unconfirmed` = true', 'neither on the row nor named'],
            'the open-call line dropped (a number whose sentinel is on no line)' => [['../desk/desk-list.js',
                "        desk.open_calls === null ? null : OPEN_CALLS(desk.open_calls),\n", ''],
                '`open_calls` =', 'neither on the row nor named'],
            'a ghost path in NOT_LISTED' => [['../desk/desk-list.js', "export const NOT_LISTED = Object.freeze({\n",
                "export const NOT_LISTED = Object.freeze({\n    'ghost.leaf': { reason: 'planted' },\n"],
                '`ghost.leaf`', 'is not a leaf of any desk model the runs drew'],
        ] as $control => [$edit, $names, $says]) {
            $this->assertRedNaming($this->defects($this->verdict(null, $this->mutatedModules($edit))), $names, $says, $control);
        }

        // The carrier check reads the MODEL's label line, so the mutation is to the model and the
        // collection is re-run over it; `fx-degraded`'s stale and offline desks carry the dark pair.
        $darkAge = $this->mutatedModules(['../desk/desk-render.js',
            'return dark.age === null ? dark.since : dark.since + DASH + dark.age;', 'return dark.since;']);
        $this->assertRedNaming($this->defects($this->verdict($darkAge, $darkAge, 'fx-degraded')), '`dark.age`',
            'is excluded as carried by `label_line`', '`labelLine()` dropping `dark.age`');

        $main = (string) file_get_contents($this->jsRoot().'/floor/main.js');
        $composed = str_replace('const [first, ...rest] = deskListRow(desk);',
            'const [first, ...rest] = [deskLine(desk)];', $main);
        $this->assertNotSame($composed, $main, 'the page control\'s anchor is gone from floor/main.js');
        $this->assertNotSame([], $this->pageDefects($composed.
            "\nfunction deskLine(desk) { return [desk.nameplate, desk.glyph].join(' — '); }\n"),
            'CONTROL (the page composing its own desk line) did not bite');

        $appended = str_replace('link.textContent = first;', "link.textContent = first + ' — ' + desk.glyph;", $main);
        $this->assertNotSame($appended, $main, 'the page control\'s anchor is gone from floor/main.js');
        $this->assertRedNaming($this->pageDefects($appended), 'writes text that is not the list view\'s own line',
            '`first + \' — \' + desk.glyph`', 'the page appending a model member to the nameplate\'s line');
        $this->assertRedNaming($this->pageDefects($appended), 'reads `desk.glyph` itself', '',
            'the page reading a desk-model member beside the list view');
    }

    /**
     * @param  list<string>  $defects
     */
    private function assertRedNaming(array $defects, string $names, string $says, string $control): void
    {
        $this->assertNotEmpty(array_filter($defects, fn (string $d): bool => str_contains($d, $names) && str_contains($d, $says)),
            "CONTROL ({$control}) did not red naming it: ".json_encode($defects));
    }

    /**
     * Every desk model the fixtures' runs drew, deduplicated, and how many distinct desks each run
     * contributed — collected once per tree, because it is ~one `node` process per run.
     *
     * @return array{desks: list<array{run: string, key: string, model: array<string, mixed>}>, per_run: array<string, int>}
     */
    private function collected(?string $dir = null, ?string $onlyFile = null): array
    {
        static $cache = [];

        $slot = ($dir ?? '').'|'.($onlyFile ?? '');

        if (isset($cache[$slot])) {
            return $cache[$slot];
        }

        $desks = [];
        $perRun = [];

        foreach ($this->fixtureFileNames() as $file) {
            if ($onlyFile !== null && $file !== $onlyFile) {
                continue;
            }

            foreach ($this->fixtureFile($file)['runs'] ?? [] as $run => $scenario) {
                if (($scenario['lobby'] ?? false) === true) {
                    continue;
                }

                $floor = array_key_exists('floor', $scenario);
                $result = $this->replay($run, $dir, $floor || ($scenario['desk_floor'] ?? false) === true ? [] : ['desk_floor' => true]);
                $perRun[$run] = 0;

                $frames = $floor
                    ? array_map(fn (array $r): array => $r['frame']['desks']['desks'], $result['floor_renders'])
                    : array_map(fn (array $r): array => $r['frame']['desks'], $result['desk_renders']);

                foreach ($frames as $frame) {
                    foreach ($frame as $key => $model) {
                        $hash = md5((string) json_encode($model));

                        if (! isset($desks[$hash])) {
                            $desks[$hash] = ['run' => $run, 'key' => (string) $key, 'model' => $model];
                            $perRun[$run]++;
                        }
                    }
                }
            }
        }

        $this->assertNotSame([], $desks, 'no fixture run drew a desk — the guard would read nothing');

        return $cache[$slot] = ['desks' => array_values($desks), 'per_run' => $perRun];
    }

    /**
     * The probe's reading of the collected desks through a list view — the shipped one, or a copy.
     *
     * @return array<string, mixed>
     */
    private function verdict(?string $modelTree = null, ?string $listTree = null, ?string $onlyFile = null): array
    {
        $collected = $this->collected($modelTree, $onlyFile);

        [$status, $stdout, $stderr] = $this->runListProbe(['desks' => $collected['desks']], $listTree ?? $this->moduleDir());

        $this->assertSame(0, $status, "the list-view probe failed:\n".$stderr);

        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded, "the list-view probe printed something that is not JSON:\n".$stdout);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $verdict
     * @return list<string>
     */
    private function defects(array $verdict): array
    {
        $defects = $verdict['defects'];

        $this->assertGreaterThan(20, count($verdict['leaves']), 'the probe found almost no printed leaves — the walk has stopped reading the model');

        foreach ($verdict['leaves'] as $path => $leaf) {
            if ($leaf['nondefault_runs'] === []) {
                $defects[] = "`{$path}` is never seen at a non-default value on any fixture run — the row could drop it and no run would notice";
            }
        }

        foreach (array_diff($verdict['not_listed'], $verdict['excluded']) as $ghost) {
            $defects[] = "`{$ghost}` is excluded in NOT_LISTED and is not a leaf of any desk model the runs drew";
        }

        return $defects;
    }

    /**
     * The page half, read off `floor/main.js`'s source: it imports the list view, and its `paintDesks()`
     * writes text only from `deskListRow()`'s output — every text-property assignment is the nameplate's
     * line (`first`), every `items()` call is the other lines (`rest`), no other text sink appears, no
     * DOM insertion is handed a string built in place, and no `desk.<member>` is read beside the two ids
     * the link routes by. A source read, not a DOM run: the page is a DOM entry no harness loads.
     *
     * @return list<string>
     */
    private function pageDefects(string $main): array
    {
        $defects = [];

        if (! str_contains($main, "import { deskListRow } from '../desk/desk-list.js';")) {
            $defects[] = 'floor/main.js does not import the list view';
        }

        if (preg_match('/function\s+deskLine\b/', $main) === 1) {
            $defects[] = 'floor/main.js composes a desk line of its own (`deskLine()`), beside the list view';
        }

        if (preg_match('/^function paintDesks\(.*?\n\}\n/ms', $main, $match) !== 1) {
            return [...$defects, 'floor/main.js has no `paintDesks()` — the page check reads nothing'];
        }

        $body = $match[0];

        if (! str_contains($body, 'const [first, ...rest] = deskListRow(desk);')) {
            $defects[] = 'paintDesks() does not take its lines from `deskListRow(desk)`';
        }

        preg_match_all('/\.(?:textContent|innerText|innerHTML|outerHTML|nodeValue)\s*=\s*([^;]+);/', $body, $sinks);

        foreach ($sinks[1] as $value) {
            if (trim($value) !== 'first') {
                $defects[] = "paintDesks() writes text that is not the list view's own line: `".trim($value).'`';
            }
        }

        preg_match_all('/\bitems\(([^)]*)\)/', $body, $calls);

        foreach ($calls[1] as $argument) {
            if (trim($argument) !== 'rest') {
                $defects[] = "paintDesks() paints lines that are not the list view's own: `items({$argument})`";
            }
        }

        if (preg_match('/insertAdjacent(?:Text|HTML)|createTextNode|document\.write/', $body, $raw) === 1) {
            $defects[] = "paintDesks() writes text through `{$raw[0]}`, outside the list view's lines";
        }

        preg_match_all('/\.(?:append|prepend|before|after|replaceWith)\(([^()]*)\)/', $body, $inserts);

        foreach ($inserts[1] as $argument) {
            if (preg_match('/[\'"`+]/', $argument) === 1) {
                $defects[] = "paintDesks() inserts a string built in place: `{$argument}`";
            }
        }

        preg_match_all('/\bdesk\.(\w+)/', $body, $reads);

        foreach (array_diff(array_unique($reads[1]), ['seat_id', 'install_id']) as $member) {
            $defects[] = "paintDesks() reads `desk.{$member}` itself, beside the list view's lines";
        }

        return $defects;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{int, string, string}
     */
    private function runListProbe(array $payload, string $wireDir): array
    {
        $process = proc_open(['node', __DIR__.'/desk-list-probe.mjs', $wireDir],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            $this->fail('node could not be started — the list view is a shipped module and there is nothing to drive it with');
        }

        fwrite($pipes[0], (string) json_encode($payload));
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
