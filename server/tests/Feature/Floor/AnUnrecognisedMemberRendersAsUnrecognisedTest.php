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
