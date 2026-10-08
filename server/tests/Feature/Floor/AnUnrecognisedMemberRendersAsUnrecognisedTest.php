<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-11 — an unrecognised member renders as unrecognised.** `docs/design/FLOOR.md § 11`,
 * gated at Appendix B **step 8**. card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE BUILD IS THE FIXTURE's `unrecognised` RUN, THROUGH THE SHIPPED DESK FLOOR: a `render_state`
 * of "pondering" (delivered twice), a badge "quantum_flux", an `unknown_reason` of "reasons", and
 * one desk left alone. Every assertion reads the desk frames the shipped renderer drew and the
 * client's own event record — § 5.5's record, not the lobby's rendering of it.
 *
 * ⛔ THE SCENE LEG (card#11058, the operator's ruling of 2026-10-02, Q0): on the DESK no raw
 * unrecognised string is drawn — the chip and the label line read the fixed word, and every
 * unrecognised value is counted into the flag `⚠ +N`; the raw `field: value` lines are the drill-down's
 * rows (and the desk list's). It lays out the run's last desk models through the shipped
 * `floor/desk-layout.js` and renders the panel through the shipped `drilldown/main.js`.
 *
 * ⛔ THE MEMBER SETS THE TEST IS AGAINST ARE THE DOCUMENT's. `wire/member-sets.js` carries the three
 * sets no other module did (§ 7.2's badges, § 7.6's `link_state` and `activity_state`); this file
 * re-derives each from its table and set-differences both directions, so a member the document gains
 * or drops reds here rather than being rendered as *unrecognised* — or silently accepted.
 */
class AnUnrecognisedMemberRendersAsUnrecognisedTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'unrecognised';

    /** RED — the nearest match: an unknown `render_state` read as the closest known member. */
    private const NEAREST = ['../desk/desk-render.js',
        "    const state = seat.render_state;\n\n    if (state === 'retired') {",
        "    const state = isRenderState(seat.render_state) ? seat.render_state : 'working';\n\n    if (state === 'retired') {"];

    /** Second RED — the healthy default: every value is taken as a known one. */
    private const HEALTHY_DEFAULT = ['../desk/desk-render.js',
        '        if (value !== null && value !== undefined && !known(value)) {',
        '        if (false) {'];

    public function test_green_each_unknown_value_renders_raw_and_unrecognised_is_recorded_once_and_touches_no_other_desk(): void
    {
        $this->assertSame([], $this->defects($this->deskRun(self::RUN)));
    }

    public function test_red_the_nearest_match_is_caught(): void
    {
        $defects = $this->defects($this->deskRun(self::RUN, $this->mutatedModules(self::NEAREST)));

        $this->assertArrayHasKey('render_state', $defects, 'the RED did not bite: '.json_encode($defects));
    }

    public function test_red_the_healthy_default_is_caught(): void
    {
        $defects = $this->defects($this->deskRun(self::RUN, $this->mutatedModules(self::HEALTHY_DEFAULT)));

        // The badge — whose only membership test is this one — loses its marker and its desk its
        // not-current treatment, and nothing is recorded; the reason's SENTENCE is § 7.1's own lookup
        // and still marks it, which is why the clauses named here are these three.
        foreach (['badges', 'current', 'record'] as $clause) {
            $this->assertArrayHasKey($clause, $defects, "the RED did not bite on {$clause}: ".json_encode($defects));
        }
    }

    /**
     * The scene leg: every touched desk's flag counts its unrecognised values, no element derived from
     * the state or the badges carries a raw string, and the panel lists each `field: value` line.
     */
    public function test_green_the_desk_flags_each_unrecognised_value_draws_none_raw_and_the_panel_lists_each(): void
    {
        $this->assertSame([], $this->sceneDefects());
    }

    /** ⛔ THE CONTROLS — each clause of the scene leg planted in the module it would live in. */
    public function test_red_each_clause_of_the_scene_leg_is_caught(): void
    {
        foreach ([
            'flag' => ['../floor/desk-layout.js', 'const n = flagCount(desk, row);', 'const n = flagCount(desk, row) + 1;'],
            'raw' => ['../floor/desk-layout.js', 'fit(desk.render_state.recognised ? desk.glyph : UNRECOGNISED,', 'fit(desk.glyph,'],
            'panel' => ['../drilldown/main.js', "putRows(root, '[data-panel-unrecognised]', desk.unrecognised.map((line) => ({ text: line })));",
                "putRows(root, '[data-panel-unrecognised]', []);"],
        ] as $clause => $edit) {
            $this->assertArrayHasKey($clause, $this->sceneDefects($this->mutatedModules($edit)),
                "the scene leg's RED did not bite on {$clause}");
        }
    }

    /**
     * § 7.2's 18 badges and § 7.6's two five-member sets, from the document, against the module.
     */
    public function test_the_three_member_sets_are_the_documents_in_both_directions(): void
    {
        foreach ($this->documentSets() as $name => $members) {
            $this->assertGreaterThan(4, count($members), "§ 7's {$name} table did not parse");
            $this->assertSame($members, $this->moduleSets()[$name], "wire/member-sets.js's {$name} is not the document's");
        }

        // CONTROL: a member dropped from the module reds the comparison above.
        $planted = $this->moduleSets($this->mutatedModules(['member-sets.js', "    'seq_collision',\n", '']));

        $this->assertNotSame($this->documentSets()['BADGES'], $planted['BADGES'],
            'CONTROL did not bite: a badge removed from the module still matched the document');
    }

    /**
     * The scene leg's defects over the run's touched seats, keyed by clause: `flag`, `raw`, `panel`.
     *
     * @return array<string, string>
     */
    private function sceneDefects(?string $dir = null): array
    {
        $result = $this->deskRun(self::RUN, $dir);
        $last = $this->lastFrame($result)['desks'];
        $seats = [];

        foreach ($this->fixture(self::RUN)['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $seats["{$seat['install_id']}/{$seat['seat_id']}"] = $seat;
            }
        }

        $touched = [];

        foreach ($this->fixture(self::RUN)['messages'] as $message) {
            $key = "{$message['envelope']['install_id']}/{$message['envelope']['seat_id']}";
            $seats[$key] = array_replace($seats[$key], $message['envelope']['patch']);
            $touched[$key] = true;
        }

        $drawn = $this->sceneOfDesks(array_map(fn (string $key): array => ['model' => $last[$key], 'seat' => $seats[$key]], array_keys($touched)),
            $dir === null ? $this->jsRoot() : dirname($dir));
        $defects = [];

        foreach (array_keys($touched) as $i => $key) {
            $model = $last[$key];
            $raw = array_map(static fn (string $u): string => substr($u, strpos($u, ': ') + 2), $model['unrecognised']);
            $elements = $drawn[$i]['elements'];
            $flag = array_values(array_filter($elements, static fn (array $e): bool => $e['kind'] === 'flag'));

            // This run's badges are all unrecognised, so N is the unrecognised list's length (§ 5.1's definition).
            if (array_column($flag, 'text') !== ['⚠ +'.count($model['unrecognised'])]) {
                $defects['flag'] = "[{$key}] the flag reads ".json_encode(array_column($flag, 'text')).' for '.json_encode($model['unrecognised']);
            }

            foreach ($elements as $e) {
                if (! in_array($e['kind'], ['chip', 'label', 'currency', 'monitor-text', 'badge', 'flag'], true) || ! isset($e['text'])) {
                    continue;
                }

                foreach ($raw as $value) {
                    if (preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($value, '/').'(?![\p{L}\p{N}_])/u', $e['text']) === 1) {
                        $defects['raw'] = "[{$key}] the desk's {$e['kind']} draws the raw string «{$value}»: «{$e['text']}»";
                    }
                }
            }

            if (array_diff($model['unrecognised'], $drawn[$i]['panel_rows']) !== []) {
                $defects['panel'] = "[{$key}] the panel's unrecognised rows ".json_encode($drawn[$i]['panel_rows'])
                    .' do not list '.json_encode($model['unrecognised']);
            }
        }

        return $defects;
    }

    /**
     * Each desk model laid out by `floor/desk-layout.js`, and its seat's panel rendered by
     * `drilldown/main.js` into a stub holding the `[data-panel-unrecognised]` list — the shipped tree or a copy.
     *
     * @param  list<array{model: array<string, mixed>, seat: array<string, mixed>}>  $desks
     * @return list<array{elements: list<array<string, mixed>>, panel_rows: list<string>}>
     */
    private function sceneOfDesks(array $desks, string $jsRoot): array
    {
        $url = fn (string $p): string => json_encode('file://'.$jsRoot.'/'.$p);
        $script = 'const { deskLayout } = await import('.$url('floor/desk-layout.js').');'
            .'const { drillDownModel } = await import('.$url('drilldown/drilldown-model.js').');'
            .'const { renderDrillDown } = await import('.$url('drilldown/main.js').');'
            .'const desks = JSON.parse(require("fs").readFileSync(0, "utf8"));'
            .'const measure = (t) => ({ w: [...String(t)].length * 6, h: 12 });'
            .'console.log(JSON.stringify(desks.map(({ model, seat }) => {'
            .'  const list = { children: [], hidden: false, replaceChildren(...c) { this.children = c; } };'
            .'  const root = { querySelector: (s) => (s === "[data-panel-unrecognised]" ? list : null),'
            .'    ownerDocument: { createElement: () => ({ dataset: {}, textContent: "" }) } };'
            .'  renderDrillDown(root, drillDownModel({ ...seat, server_time: "2026-08-23T14:23:14.900Z" }, null, { now_ms: Date.parse("2026-08-23T14:23:15Z") }));'
            .'  return { elements: deskLayout(model, { box: { width: 440, height: 228 }, measure, character: { w: 18, h: 32 }, sprite: null, placeholder: false, failed: new Set() }).elements,'
            .'    panel_rows: list.hidden ? [] : list.children.map((li) => li.textContent) };'
            .'})));';

        $process = proc_open(['node', '--input-type=module', '-e', 'import { createRequire } from "node:module"; const require = createRequire(import.meta.url);'.$script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'node could not be started');
        fwrite($pipes[0], (string) json_encode($desks));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "the scene leg's node run failed:\n".$stderr);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "the scene leg's node run printed something that is not JSON:\n".$stdout);
        $this->assertCount(count($desks), $decoded);

        return $decoded;
    }

    /** @return array<string, string> */
    private function defects(array $result): array
    {
        $deltas = array_map(static fn (array $m): array => $m['envelope'], $this->fixture(self::RUN)['messages']);
        $first = $this->firstFrame($result, self::RUN)['desks'];
        $last = $this->lastFrame($result)['desks'];
        $defects = [];

        foreach ($deltas as $delta) {
            $key = "{$delta['install_id']}/{$delta['seat_id']}";
            $desk = $last[$key];
            $patch = $delta['patch'];

            if (isset($patch['render_state']) && $patch['render_state'] === 'pondering') {
                if (! str_contains($desk['glyph'], 'pondering') || ! str_contains($desk['glyph'], 'unrecognised')
                    || $desk['pose'] !== 'empty-chair') {
                    $defects['render_state'] = "[{$key}] the desk drew `{$desk['glyph']}` / `{$desk['pose']}` — not the unrecognised glyph carrying the raw string";
                }
            }

            if (isset($patch['badges'])) {
                if (! in_array('badges: quantum_flux', $desk['unrecognised'], true)) {
                    $defects['badges'] = "[{$key}] the badge is not rendered as unrecognised: ".json_encode($desk['unrecognised']);
                }
            }

            if (isset($patch['unknown_reason'])) {
                if (! str_contains((string) $desk['label_line'], 'reasons (unrecognised)')) {
                    $defects['unknown_reason'] = "[{$key}] the reason renders `{$desk['label_line']}`";
                }
            }

            // Treated as not-current: no loop is drawn for a desk carrying a value nobody knows.
            if (($desk['held']['motion'] ?? false) === true) {
                $defects['current'] = "[{$key}] a desk carrying an unrecognised value still draws its loop";
            }
        }

        // The record: each DISTINCT value once — "pondering" was delivered twice.
        $log = $result['final']['event_log'];

        foreach (['render_state' => 'pondering', 'badges' => 'quantum_flux', 'unknown_reason' => 'reasons'] as $field => $value) {
            $lines = array_filter($log, static fn (string $l): bool => str_contains($l, $field) && str_contains($l, "\"{$value}\""));

            if (count($lines) !== 1) {
                $defects['record'] = count($lines)." record lines name {$field} \"{$value}\", not one";
            }
        }

        // No other desk is affected.
        $touched = array_map(static fn (array $d): string => "{$d['install_id']}/{$d['seat_id']}", $deltas);

        foreach ($last as $key => $desk) {
            if (in_array($key, $touched, true)) {
                continue;
            }

            foreach (['glyph', 'pose', 'label_line', 'unrecognised', 'held'] as $member) {
                if ($desk[$member] !== $first[$key][$member]) {
                    $defects['other'] = "[{$key}] an untouched desk's {$member} changed";
                }
            }
        }

        return $defects;
    }

    /** @return array<string, list<string>> */
    private function documentSets(): array
    {
        $md = $this->floorMd();
        $section = static fn (string $from, string $to): string => substr($md, strpos($md, $from), strpos($md, $to, strpos($md, $from)) - strpos($md, $from));
        $firstColumn = static function (string $text, string $header): array {
            $start = strpos($text, $header);
            $out = [];

            foreach (array_slice(explode("\n", substr($text, $start)), 2) as $line) {
                if (! str_starts_with($line, '| `')) {
                    break;
                }

                preg_match('/^\| `([a-z_]+)`/', $line, $m);
                $out[] = $m[1];
            }

            sort($out);

            return $out;
        };

        $badges = $section('### 7.2 Badges', '### 7.3');
        $sets = $section('### 7.6 The three', '## 8.');

        return [
            'BADGES' => $firstColumn($badges, '| Badge | Origin |'),
            'LINK_STATES' => $firstColumn($sets, '| `link_state` |'),
            'ACTIVITY_STATES' => $firstColumn($sets, '| `activity_state` |'),
        ];
    }

    /** @return array<string, list<string>> */
    private function moduleSets(?string $dir = null): array
    {
        $module = ($dir ?? $this->moduleDir()).'/member-sets.js';
        $script = 'const m = await import('.json_encode('file://'.$module).');'
            .'console.log(JSON.stringify(Object.fromEntries(["BADGES","LINK_STATES","ACTIVITY_STATES"].map((k) => [k, [...m[k]].sort()]))));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read wire/member-sets.js');

        return json_decode($out, true);
    }
}
