<?php

namespace Tests\Feature\Floor;

use App\Fold\Badges;
use Tests\TestCase;

/**
 * card#9491 — the drill-down marks a server badge *its counter rose within the last 24 h* only when the
 * server windows it. `drilldown-model.js`'s `WINDOWED_SERVER_BADGES` is a copy of
 * `App\Fold\Badges::COUNTER_BADGES`'s keys that a browser has to carry, so it is held equal here, and
 * the control plants a drift to see the guard red.
 */
class TheDrillDownWindowsTheServersCounterBadgesTest extends TestCase
{
    use DrivesTheDrillDown;

    public function test_the_drill_downs_windowed_server_badges_are_the_servers(): void
    {
        $this->assertSame($this->server(), $this->shipped());
    }

    public function test_the_guard_goes_red_on_a_dropped_badge(): void
    {
        $dropped = $this->shipped($this->mutatedModules(['../drilldown/drilldown-model.js',
            "['seq_gap', 'seq_collision', 'epoch_reset', 'reporter_ahead']", "['seq_gap', 'seq_collision', 'epoch_reset']"]));

        $this->assertNotSame($this->server(), $dropped, 'CONTROL did not bite: a dropped badge matched the server');
    }

    /**
     * The panel says the window beside every counter-derived badge, D1's and the server's, and beside
     * no current condition; and it labels the reporter's counters as running totals.
     */
    public function test_each_counter_derived_badge_carries_the_window_and_no_current_condition_does(): void
    {
        $model = $this->model();

        $this->assertSame([
            'clock_skew' => null,
            'derivation_error' => null,
            'epoch_reset' => 'its counter rose within the last 24 h',
            'lossy' => 'its counter rose within the last 24 h',
            'seq_gap' => 'its counter rose within the last 24 h',
        ], $model['windows']);
        $this->assertSame('running totals, kept across reporter restarts', $model['reporter_since']);

        // CONTROL: the panel as it stood before card#9491 — no window beside any badge — reds.
        $planted = $this->model($this->mutatedModules(['../drilldown/drilldown-model.js',
            'window: fromReporter || WINDOWED_SERVER_BADGES.includes(badge) ? WITHIN_WINDOW : null,', 'window: null,']));
        $this->assertNotSame($model['windows'], $planted['windows'], 'CONTROL did not bite: a panel with no window matched');
    }

    /** @return array{windows: array<string, string|null>, reporter_since: string|null} */
    private function model(?string $wireDir = null): array
    {
        $model = dirname($wireDir ?? $this->moduleDir()).'/drilldown/drilldown-model.js';
        $seat = [
            'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
            'badges' => ['lossy', 'epoch_reset', 'seq_gap', 'clock_skew', 'derivation_error'],
            'delivery' => ['clock_skew_ms' => 180000],
            'detail' => ['counters' => ['seq_gap' => 3, 'seq_epoch_change' => 1],
                'heartbeat_counters' => ['spool_dropped_events' => 2, 'state_reset' => 1], 'heartbeat_predicates' => []],
        ];
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'const r = m.drillDownModel('.json_encode($seat).', [], {});'
            .'console.log(JSON.stringify({ windows: Object.fromEntries(r.badges.rows.map((b) => [b.badge, b.window])),'
            .' reporter_since: r.counters.reporter?.since ?? null }));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not run the drill-down model');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'the drill-down model printed no JSON: '.$out);
        ksort($decoded['windows']);

        return $decoded;
    }

    /** @return list<string> */
    private function server(): array
    {
        $keys = array_keys(Badges::COUNTER_BADGES);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function shipped(?string $wireDir = null): array
    {
        $model = dirname($wireDir ?? $this->moduleDir()).'/drilldown/drilldown-model.js';
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'console.log(JSON.stringify(m.WINDOWED_SERVER_BADGES));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the drill-down model');

        $decoded = json_decode($out, true);
        sort($decoded);

        return $decoded;
    }
}
