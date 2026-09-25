<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-4 — the subagent cap boundary.** `docs/design/FLOOR.md § 11`, gated at Appendix B **step 10**
 * (card#7342): "replay `fx-interns`, and open the drill-down against a stubbed detail response carrying
 * nine open dispatch calls. **Reads:** the harness, the side table, the drill-down, the uncapped intern
 * list."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ BOTH SURFACES, ONE RUN. The side table is the DESK's (§ 8, Appendix B step 5) and draws the seat
 * object's capped `subagents[]`; the uncapped list is the PANEL's and draws the detail response's open
 * dispatch calls. `panel_interns` replays `fx-interns` on the floor — 0 → 8 → 8-with-`subagents_open`-9
 * — and opens the drill-down after the third state, so every clause is read off the same floor frames a
 * page draws.
 *
 * ⛔ EACH RED IS PLANTED ON BOTH SURFACES, because each is a defect either surface can commit on its own:
 * an invented title on a stool or on a panel row, a count read off an array on the desk or in the panel.
 */
class TheInternListIsUncappedWhereTheSideTableIsCappedTest extends TestCase
{
    use DrivesTheDrillDown;

    private const RUN = 'panel_interns';

    private const KEY = 'aimla/aimla-interns';

    /** AT-D3-4's first RED — the invented title, on the desk and in the panel. */
    private const INVENTED_TITLE = [
        ['../desk/desk-render.js', 'label: s.title ?? UNTITLED,', 'label: s.title ?? s.subagent_type ?? UNTITLED,'],
        ['../drilldown/drilldown-model.js', 'label: call.title ?? UNTITLED,', 'label: call.title ?? call.subagent_type ?? UNTITLED,'],
    ];

    /** Second RED — the counted array, on the desk and in the panel. */
    private const COUNTED_DESK = ['../desk/desk-render.js',
        'const open = Number.isInteger(seat.subagents_open) ? seat.subagents_open : null;',
        'const open = subagents.length;'];

    private const COUNTED_PANEL = ['../drilldown/drilldown-model.js',
        'const open = seat?.subagents_open ?? null;',
        'const open = (seat?.subagents ?? []).length;'];

    public function test_green_eight_stools_then_plus_one_more_and_the_drill_down_lists_nine(): void
    {
        $this->assertSame([], $this->defects());
    }

    /** The discriminating control: `subagents: []`, `subagents_open: 0` renders no stools and no tag. */
    public function test_control_an_empty_side_table_draws_no_stools_and_no_tag(): void
    {
        $result = $this->floorRun(self::RUN);
        $firstDelta = min(array_column($this->fixture(self::RUN)['messages'], 'at_ms'));
        $checked = 0;

        foreach ($result['floor_renders'] as $render) {
            $desk = $render['frame']['desks']['desks'][self::KEY] ?? null;

            if ($desk === null || $render['at'] >= $firstDelta) {
                continue;
            }

            $this->assertSame([], $desk['side_table']['stools'], 'an empty `subagents[]` drew a stool');
            $this->assertNull($desk['side_table']['more'], 'a seat running no subagent carries a *+N more* tag');
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no frame drew the seat before its first delta — the control read nothing');
    }

    public function test_red_the_invented_title_is_caught_on_the_desk_and_in_the_panel(): void
    {
        foreach (self::INVENTED_TITLE as $edit) {
            $defects = $this->defects($this->mutatedModules($edit));

            $this->assertArrayHasKey('untitled', $defects, "the invented-title RED planted in {$edit[0]} did not bite: ".json_encode($defects));
        }
    }

    public function test_red_the_counted_array_is_caught_on_the_desk_and_in_the_panel(): void
    {
        $desk = $this->defects($this->mutatedModules(self::COUNTED_DESK));
        $this->assertArrayHasKey('more', $desk, 'the counted-array RED on the desk did not bite: '.json_encode($desk));

        $panel = $this->defects($this->mutatedModules(self::COUNTED_PANEL));
        $this->assertArrayHasKey('count', $panel, 'the counted-array RED in the panel did not bite: '.json_encode($panel));
    }

    /**
     * Every clause of AT-D3-4's GREEN, keyed by what it is about, each an empty-or-message.
     *
     * @return array<string, string>
     */
    private function defects(?string $dir = null): array
    {
        $result = $this->floorRun(self::RUN, $dir);
        $fixture = $this->fixture(self::RUN);
        $times = array_column($fixture['messages'], 'at_ms');
        sort($times);
        [$eight, $nine] = $times;
        $defects = [];
        $seen = ['eight' => 0, 'nine' => 0];

        // ── The side table, the DESK's: 8 stools and no tag at 8 elements; 8 and *+1 more* at 9. ──
        foreach ($result['floor_renders'] as $render) {
            $desk = $render['frame']['desks']['desks'][self::KEY] ?? null;

            if ($desk === null || $render['at'] < $eight) {
                continue;
            }

            $phase = $render['at'] < $nine ? 'eight' : 'nine';
            $seen[$phase]++;
            $stools = $desk['side_table']['stools'];

            if (count($stools) !== 8) {
                $defects['stools'] ??= "at {$render['at']} the side table draws ".count($stools).' stools, not 8';
            }

            $want = $phase === 'eight' ? null : 1;

            if ($desk['side_table']['more'] !== $want) {
                $defects['more'] ??= "at {$render['at']} the *+N more* tag reads ".json_encode($desk['side_table']['more'])
                    .', not '.json_encode($want).' — the count is `subagents_open − 8`, the wire\'s';
            }

            foreach ($stools as $stool) {
                if ($stool['untitled'] && $stool['label'] !== 'untitled') {
                    $defects['untitled'] ??= "a stool whose spawn was never received is labelled `{$stool['label']}`";
                }
            }

            if (! in_array(true, array_column($stools, 'untitled'), true)) {
                $defects['untitled'] ??= 'no stool is drawn **untitled** although one element carries `title: null`';
            }
        }

        if ($seen['eight'] === 0 || $seen['nine'] === 0) {
            $defects['frames'] = 'the run drew no frame in one of the two states: '.json_encode($seen);
        }

        // ── The drill-down: the detail response's nine open dispatch calls, uncapped. ──
        $panel = $this->openPanel($result, 'aimla-interns');
        $interns = $panel['interns'];
        $served = array_values(array_filter(
            $fixture['http']['/api/fleet/seats/aimla/aimla-interns'][0]['body']['detail']['open_calls'],
            static fn (array $c): bool => $c['is_dispatch'] === 1,
        ));

        if (count($interns['rows']) !== count($served) || count($served) !== 9) {
            $defects['listed'] = 'the drill-down lists '.count($interns['rows']).' interns against '.count($served).' open dispatch calls served';
        }

        if ($interns['open'] !== 9) {
            $defects['count'] = 'the drill-down counts '.json_encode($interns['open']).' interns on a seat whose `subagents_open` is 9';
        }

        $orphan = array_values(array_filter($served, static fn (array $c): bool => $c['title'] === null))[0] ?? null;
        $row = $orphan === null ? null : (array_values(array_filter($interns['rows'], static fn (array $r): bool => $r['call_id'] === $orphan['call_id']))[0] ?? null);

        if ($row === null || $row['untitled'] !== true || $row['label'] !== 'untitled') {
            $defects['untitled'] ??= 'the drill-down does not render the null-title intern **untitled** with its `call_id`: '.json_encode($row);
        }

        foreach ($interns['rows'] as $listed) {
            if ($listed['untitled'] === false && ! in_array($listed['label'], array_column($served, 'title'), true)) {
                $defects['untitled'] ??= "the drill-down lists an intern labelled `{$listed['label']}`, which no served call is titled";
            }
        }

        return $defects;
    }
}
