<?php

namespace Tests\Feature\Floor;

use App\Floor\FurnitureBox;
use Tests\TestCase;

/**
 * **AT-D3-30 — an out-of-date reporter is marked on the desk, named in the panel, and never read as
 * not-current.** `docs/design/FLOOR.md § 7.2`'s `update_required` row and § 13 row 75 (card#11818
 * part 2), gated at Appendix B row 25. The floor compares no versions: the badge is D2's judgement
 * (AT-D2-30), and this holds the render of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE BUILD IS `fx-reporter`, THROUGH THE SHIPPED CODE. The run is replayed through the shipped
 * client and desk floor; each frame's desk models are laid out by the shipped `floor/desk-layout.js`,
 * listed by the shipped `desk/desk-list.js`, and the held seats' panels are modelled by the shipped
 * `drilldown/drilldown-model.js`. The panel's line is compared with § 7.2's table, read from the
 * document on every run, never with a copy written here.
 *
 * ⛔ THE GREEN IS ONE FUNCTION, AND EACH RED IS READ THROUGH IT. `defects()` returns the GREEN's
 * failures keyed by the clause each breaks; a RED plants its defect in the shipped module and requires
 * the clause it names to red — not merely "something".
 */
class AnOutOfDateReporterIsMarkedOnTheDeskTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'reporter';

    private const BADGE = 'update_required';

    private const IMPL = 'aimla/aimla-impl-1';

    private const PM = 'aimla/aimla-pm';

    /**
     * The renderer this test reads is the desk floor, so the run's `floor` — the floor SCREEN, which
     * `fx-reporter` names so `tools/design/floor-fixture.browser.mjs` can draw the run on the real page —
     * is switched off: a page condition, which screen draws, and not a byte of the scenario.
     */
    private const DESK_FLOOR_ONLY = ['floor' => null];

    /** RED — the wire's order: row 44's cluster, with nothing pinned. */
    private const PLANT_WIRE_ORDER = ['../floor/desk-layout.js',
        "export const PINNED_BADGES = Object.freeze(['update_required']);",
        'export const PINNED_BADGES = Object.freeze([]);'];

    /** Second RED — the old reporter read as not-current: the badge joins the treatment that stops the loop. */
    private const PLANT_TREATED = ['../desk/desk-render.js',
        "const configInvalid = badges.includes('config_invalid');",
        "const configInvalid = badges.includes('config_invalid') || badges.includes('update_required');"];

    /** Third RED — the invented target: the panel's line names a version to update to. */
    private const PLANT_TARGET = ['../drilldown/drilldown-model.js',
        "line: 'reporter out of date — update required' }",
        "line: 'reporter out of date — update required: 0.3.0' }"];

    public function test_green_the_badge_is_marked_named_and_never_read_as_not_current(): void
    {
        $this->assertSame([], $this->defects());
    }

    /** The run's own premise: § 11's row, checked rather than assumed. */
    public function test_the_fixture_carries_the_two_seats_and_three_deltas_the_row_states(): void
    {
        $seats = $this->snapshotSeats(self::RUN);

        $this->assertSame([self::PM, self::IMPL], array_keys($seats));
        $this->assertSame([[], '0.3.0'], [$seats[self::PM]['badges'], $seats[self::PM]['reporter']['version']]);
        $this->assertSame([[self::BADGE], '0.1.0'], [$seats[self::IMPL]['badges'], $seats[self::IMPL]['reporter']['version']]);

        foreach ($seats as $key => $seat) {
            $this->assertSame(['live', 'working'], [$seat['link_state'], $seat['render_state']], "{$key} is not live and working");
            $this->assertNotNull($seat['task'], "{$key} carries no task");
        }

        $patches = array_map(static fn (array $m): array => [$m['envelope']['seat_id'], $m['envelope']['patch']['badges']],
            $this->fixture(self::RUN)['messages']);
        $this->assertSame([
            ['aimla-impl-1', ['lossy', 'config_invalid', self::BADGE]],
            ['aimla-impl-1', []],
            ['aimla-pm', [self::BADGE]],
        ], $patches);
        $this->assertArrayNotHasKey('reporter', $this->fixture(self::RUN)['messages'][2]['envelope']['patch'],
            'the server-moved delta carries a reporter change, so it is not the case the row names');
    }

    public function test_red_the_wires_order_folds_the_update_mark_into_the_flag(): void
    {
        $this->assertArrayHasKey('order', $this->defects($this->mutatedModules(self::PLANT_WIRE_ORDER)),
            'the RED did not bite: the wire order passed the order clause');
    }

    public function test_red_the_badge_read_as_a_treatment_stops_a_working_desks_loop(): void
    {
        $this->assertArrayHasKey('current', $this->defects($this->mutatedModules(self::PLANT_TREATED)),
            'the RED did not bite: the old reporter was treated as not-current and the current clause passed');
    }

    public function test_red_a_panel_line_naming_a_target_version_is_caught_by_the_verbatim_clause(): void
    {
        $this->assertArrayHasKey('panel', $this->defects($this->mutatedModules(self::PLANT_TARGET)),
            'the RED did not bite: a line naming a version passed the verbatim clause');
    }

    /** Discriminating control: `aimla-pm` at the snapshot, the same render state and task, draws no chip and no flag. */
    public function test_control_the_same_desk_without_the_badge_draws_no_chip(): void
    {
        $frame = $this->framesAfterEachMessage($this->deskRun(self::RUN, null, self::DESK_FLOOR_ONLY))[0];
        [$pm, $impl] = $this->laidOut([$frame['desks'][self::PM], $frame['desks'][self::IMPL]], $this->jsRoot());

        $this->assertSame([[], []], [$this->chips($pm['elements']), $this->flag($pm['elements'])]);
        $this->assertSame([self::BADGE], $this->chips($impl['elements']), 'the control discriminates nothing: the badged desk draws no chip either');
        $this->assertSame($frame['desks'][self::PM]['pose'], $frame['desks'][self::IMPL]['pose'], 'the two desks are not in the same render state');
    }

    /**
     * AT-D3-30's GREEN, clause by clause, over one replay of `fx-reporter` against the shipped tree or a
     * planted copy of it.
     *
     * @return array<string, string>
     */
    private function defects(?string $dir = null): array
    {
        $root = $dir === null ? $this->jsRoot() : dirname($dir);
        $result = $this->deskRun(self::RUN, $dir, self::DESK_FLOOR_ONLY);
        $frames = $this->framesAfterEachMessage($result);
        $deltas = array_map(static fn (array $m): array => $m['envelope'], $this->fixture(self::RUN)['messages']);
        $line = $this->documentLine();
        $defects = [];

        // ── at the snapshot ───────────────────────────────────────────────────────────────────
        $snap = $frames[0]['desks'];
        [$pm, $impl] = $this->laidOut([$snap[self::PM], $snap[self::IMPL]], $root);

        if ($this->chips($impl['elements']) !== [self::BADGE] || $this->flag($impl['elements']) !== []) {
            $defects['snapshot'] = 'aimla-impl-1 draws chips '.json_encode($this->chips($impl['elements'])).' and flag '.json_encode($this->flag($impl['elements']));
        }

        if ($this->chips($pm['elements']) !== [] || $this->flag($pm['elements']) !== []) {
            $defects['snapshot'] = 'aimla-pm draws chips '.json_encode($this->chips($pm['elements'])).' and flag '.json_encode($this->flag($pm['elements']));
        }

        if (! in_array('badges: '.self::BADGE, $impl['list'], true)) {
            $defects['list'] = 'the desk list does not print `badges: update_required`: '.json_encode($impl['list']);
        }

        // Neither desk is treated as not-current: full light, the working pose, and the loop entered moving.
        foreach ([self::PM, self::IMPL] as $key) {
            $entered = array_values(array_filter($this->heldRows($result, $key), static fn (array $r): bool => $r['phase'] === 'entered'));

            if ($snap[$key]['lighting'] !== 'full' || $entered === [] || $entered[0]['motion'] !== true) {
                $defects['current'] = "{$key} is treated as not-current: lighting {$snap[$key]['lighting']}, entered ".json_encode($entered);
            }
        }

        // The panel: one row, the id and § 7.2's line verbatim, no window and no counter; the version beside it.
        $panel = $this->panels([$this->snapshotSeats(self::RUN)[self::IMPL]], $root)[0];
        $want = [['badge' => self::BADGE, 'line' => $line, 'window' => null, 'counters' => null]];
        $got = array_map(static fn (array $r): array => ['badge' => $r['badge'], 'line' => $r['line'], 'window' => $r['window'], 'counters' => $r['counters']],
            $panel['badges']['rows']);

        if ($got !== $want || $panel['reporter']['version'] !== '0.1.0') {
            $defects['panel'] = 'the panel reads '.json_encode($got).' beside version '.json_encode($panel['reporter']['version']).", not one row with *{$line}*";
        }

        // ── after the first delta: the treatment badge, then the update mark, and ⚠ +1 for the wire-ordered badge ──
        [$impl1] = $this->laidOut([$frames[1]['desks'][self::IMPL]], $root);

        if ($this->chips($impl1['elements']) !== ['config_invalid', self::BADGE] || $this->flag($impl1['elements']) !== ['⚠ +1']) {
            $defects['order'] = 'after the first delta the chips read '.json_encode($this->chips($impl1['elements']))
                .' and the flag '.json_encode($this->flag($impl1['elements']));
        }

        // ── after the clearing delta: the row and the flag are gone ──
        [$impl2] = $this->laidOut([$frames[2]['desks'][self::IMPL]], $root);

        if ($this->chips($impl2['elements']) !== [] || $this->flag($impl2['elements']) !== []) {
            $defects['clear'] = 'after the clearing delta the desk still draws '.json_encode([$this->chips($impl2['elements']), $this->flag($impl2['elements'])]);
        }

        // ── after aimla-pm's delta: the chip arrives with A11, the reporter block unchanged ──
        [$pm3] = $this->laidOut([$frames[3]['desks'][self::PM]], $root);
        $a11 = array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['animation_id'] === 'A11'
            && "{$r['install_id']}/{$r['seat_id']}" === self::PM));
        $pmPanel = $this->panels([$this->finalSeats($result)[self::PM]], $root)[0];

        if ($this->chips($pm3['elements']) !== [self::BADGE]
            || array_column($a11, 'cause') !== [$deltas[2]['state_version']]
            || $pmPanel['reporter']['version'] !== $this->snapshotSeats(self::RUN)[self::PM]['reporter']['version']) {
            $defects['server_moved'] = 'aimla-pm after its delta: chips '.json_encode($this->chips($pm3['elements']))
                .', A11 causes '.json_encode(array_column($a11, 'cause')).', reporter version '.json_encode($pmPanel['reporter']['version']);
        }

        return $defects;
    }

    /**
     * The frame each message left — the snapshot's, then one per message — keyed by position: the last
     * frame drawn before the next message arrived (or before the run ended).
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function framesAfterEachMessage(array $result): array
    {
        $arrivals = array_column($this->fixture(self::RUN)['messages'], 'at_ms');
        $bounds = [...$arrivals, PHP_INT_MAX];
        $frames = [];
        $from = PHP_INT_MIN;

        foreach ($bounds as $until) {
            $in = array_values(array_filter($result['desk_renders'], static fn (array $r): bool => $r['at'] >= $from && $r['at'] < $until
                && count($r['frame']['desks']) === 2));
            $this->assertNotSame([], $in, "no frame was drawn between {$from} and {$until} ms");
            $frames[] = $in[count($in) - 1]['frame'];
            $from = $until;
        }

        return $frames;
    }

    /** § 7.2's Drill-down line for the badge: the first italic span of its row, as the document writes it. */
    private function documentLine(): string
    {
        $this->assertSame(1, preg_match('/^\| `'.self::BADGE.'` \| D2 \| [^|]+ \| \*([^*]+)\*/m', $this->floorMd(), $m),
            "§ 7.2's update_required row did not parse");

        return str_replace('`', '', $m[1]);
    }

    /**
     * Each desk model laid out by `floor/desk-layout.js` and listed by `desk/desk-list.js`.
     *
     * @param  list<array<string, mixed>>  $models
     * @return list<array{elements: list<array<string, mixed>>, list: list<string>}>
     */
    private function laidOut(array $models, string $root): array
    {
        return $this->node($root, 'const { deskLayout } = await import(url("floor/desk-layout.js"));'
            .'const { deskListRow } = await import(url("desk/desk-list.js"));'
            .'const measure = (t) => ({ w: [...String(t)].length * 6, h: 12 });'
            .'out(input.map((model) => ({ elements: deskLayout(model, { box: { width: '.FurnitureBox::current()->width.', height: '.FurnitureBox::current()->height.' }, measure,'
            .' character: { w: 18, h: 32 }, sprite: null, placeholder: false, failed: new Set() }).elements, list: deskListRow(model) })));',
            $models);
    }

    /**
     * Each seat's panel model, by `drilldown/drilldown-model.js`.
     *
     * @param  list<array<string, mixed>>  $seats
     * @return list<array<string, mixed>>
     */
    private function panels(array $seats, string $root): array
    {
        return $this->node($root, 'const { drillDownModel } = await import(url("drilldown/drilldown-model.js"));'
            .'out(input.map((seat) => drillDownModel({ ...seat, server_time: "2026-08-23T14:23:14.900Z" }, null,'
            .' { now_ms: Date.parse("2026-08-23T14:23:15Z") })));', $seats);
    }

    /**
     * @param  list<mixed>  $input
     * @return list<mixed>
     */
    private function node(string $root, string $body, array $input): array
    {
        $script = 'import { readFileSync } from "node:fs";'
            .'const url = (p) => '.json_encode('file://'.$root.'/').' + p;'
            .'const input = JSON.parse(readFileSync(0, "utf8"));'
            .'const out = (v) => console.log(JSON.stringify(v));'
            .$body;

        $process = proc_open(['node', '--input-type=module', '-e', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'node could not be started');
        fwrite($pipes[0], (string) json_encode($input));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "the node run failed:\n".$stderr);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "the node run printed something that is not JSON:\n".$stdout);
        $this->assertCount(count($input), $decoded);

        return $decoded;
    }

    /**
     * The badge chips the desk draws, in order, by the TEXT each draws — a chip whose id was cut to fit
     * reads differently from the id, and AT-D3-30 names the text.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<string>
     */
    private function chips(array $elements): array
    {
        return array_column(array_values(array_filter($elements, static fn (array $e): bool => $e['kind'] === 'badge')), 'text');
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<string>
     */
    private function flag(array $elements): array
    {
        return array_column(array_values(array_filter($elements, static fn (array $e): bool => $e['kind'] === 'flag')), 'text');
    }
}
