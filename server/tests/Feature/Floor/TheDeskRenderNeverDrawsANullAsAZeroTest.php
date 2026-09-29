<?php

namespace Tests\Feature\Floor;

use Closure;
use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` AT-D3-14 — a null is never drawn as a zero — its **desk half**, gating
 * Appendix B row 5: the **desk render** and its **side table**, observed through **the harness**
 * over `fx-nulls`. The panel half (the drill-down, the uncapped intern list, the health view's
 * `counters`) is Appendix B step 10's, asserted by `TheDrillDownNeverDrawsANullAsAZeroTest`; each
 * member this file hands to it is named.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE `nulls-b` WALK IS DRIVEN BY THE FIXTURE'S NULL POPULATION, NOT BY A LIST OF MEMBERS. The
 * population is every path the served `nulls-b` object sets `null`, read out of the JSON on every
 * run. Each member of it must be classified below — a desk cell asserted on the render, or a
 * named reason it is not the desk's — and the classification is set-differenced against the
 * population in BOTH directions, so a null added to the fixture with no assertion reds, and an
 * assertion for a member the fixture no longer nulls reds too. Each member must also have its
 * own § 5.6 row, re-read from the document, so the walk cannot assert a render D3 never stated.
 *
 * ⛔ EVERY GREEN BELOW IS LABELLED *desk half*, because AT-D3-14 splits every assertion between
 * the two surfaces and a list that read as though the desk could show them all is what its own
 * preamble warns against.
 */
class TheDeskRenderNeverDrawsANullAsAZeroTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'nulls';

    /** RED — coalesce the gauge's null to zero: a full, empty gauge reading 0 %. */
    private const PLANT_ZERO_GAUGE = [
        'context-gauge.js',
        'return { reported: false, statement: NOT_REPORTED, bar: null, pct: null };',
        "return { reported: true, statement: null, bar: 0, pct: '0.0 %' };",
    ];

    /** RED — coalesce the quiet age's null basis to zero: *nothing done for 0s*. */
    private const PLANT_ZERO_QUIET = ['age-readout.js', 'return NOTHING_DONE_YET;', "return 'nothing done for 0s';"];

    /** GREEN, desk half — `nulls-a`, the containers. */
    public function test_desk_half_nulls_a_every_null_container_renders_its_absence(): void
    {
        $this->assertSame([], $this->containerDefects($this->deskRun(self::RUN)));
    }

    /** GREEN, desk half — `nulls-b`, row by row over the fixture's own null population. */
    public function test_desk_half_nulls_b_every_null_member_renders_its_5_6_cell(): void
    {
        $population = $this->nullPopulation();
        $walk = $this->walk();

        // Both directions: the classification is exactly the fixture's null population.
        $this->assertSame([], array_values(array_diff($population, array_keys($walk))),
            'nulls-b sets a member null that this walk does not classify — a null nothing asserts on');
        $this->assertSame([], array_values(array_diff(array_keys($walk), $population)),
            'this walk classifies a member nulls-b does not set null — an assertion over nothing');

        $rows = $this->section56Members();

        foreach ($population as $member) {
            $this->assertContains($member, $rows, "§ 5.6 has no row for {$member} — the walk would assert a render D3 never stated");
        }

        $this->assertGreaterThan(10, count(array_filter($walk, static fn ($c): bool => $c instanceof Closure)),
            'almost none of nulls-b\'s null members is asserted on the desk — the desk half is reading nothing');
        $this->assertSame([], $this->walkDefects($this->deskRun(self::RUN)));
    }

    /** RED — the zeroed gauge: `nulls-a`'s absent sample draws a bar at 0 %. */
    public function test_red_a_null_gauge_coalesced_to_zero_is_caught(): void
    {
        $result = $this->deskRun(self::RUN, $this->mutatedModules(self::PLANT_ZERO_GAUGE));
        $gauge = $this->lastFrame($result)['desks'][$this->key('nulls-a')]['gauge'];

        $this->assertNotSame([], $this->containerDefects($result), 'RED did not bite: the gauge was coalesced to zero and the desk half passed');
        $this->assertSame(0, $gauge['bar'], 'RED observed no bar at zero — the plant is not the defect AT-D3-14 names');
        $this->assertSame('0.0 %', $gauge['pct']);
    }

    /** RED — the clean zero on the only readout left: `nulls-b` reads *nothing done for 0s*. */
    public function test_red_a_null_quiet_age_coalesced_to_zero_is_caught_by_the_walk(): void
    {
        $result = $this->deskRun(self::RUN, $this->mutatedModules(self::PLANT_ZERO_QUIET));
        $defects = $this->walkDefects($result);

        $this->assertNotSame([], $defects, 'RED did not bite: the quiet age drew a zero and the walk passed');
        $this->assertSame('nothing done for 0s', $this->lastFrame($result)['desks'][$this->key('nulls-b')]['quiet_age']);
        $this->assertStringContainsString('activity.last_received_at', implode("\n", $defects));
    }

    /** Discriminating control — a MEASURED 0.0 % renders a bar at 0 %, so the test tells a zero from an absence. */
    public function test_control_a_real_zero_renders_a_bar_at_zero(): void
    {
        $desks = $this->lastFrame($this->deskRun('real_zero'))['desks'];

        $this->assertCount(1, $desks);

        $gauge = array_values($desks)[0]['gauge'];

        $this->assertTrue($gauge['reported'], 'a real 0.0 % sample was read as unreported');
        $this->assertEquals(0, $gauge['bar'], 'a real 0.0 % sample drew no bar');
        $this->assertSame('0.0 %', $gauge['pct']);
    }

    /**
     * `nulls-a`'s GREEN, desk half, as a list of what failed.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function containerDefects(array $result): array
    {
        $desk = $this->lastFrame($result)['desks'][$this->key('nulls-a')];
        $d = [];

        // The premise the bubble assertion stands on (§ 11's fixture row): this desk draws a character.
        if (! $desk['character']) {
            $d[] = 'nulls-a draws no character, so *no bubble* would pass without being able to fail';
        }

        if ($desk['gauge']['reported'] !== false || $desk['gauge']['statement'] !== 'not reported' || $desk['gauge']['bar'] !== null) {
            $d[] = 'the context gauge is not *not reported* with no bar: '.json_encode($desk['gauge']);
        }

        if ($desk['bubble'] !== null) {
            $d[] = 'a null task drew a thought bubble: '.json_encode($desk['bubble']);
        }

        if ($desk['monitor']['text'] !== $desk['label_line'] || in_array($desk['monitor']['text'], [null, ''], true)) {
            $d[] = 'the monitor does not show the state line: '.json_encode($desk['monitor']);
        }

        if ($desk['model_label'] !== null) {
            $d[] = 'a null model label rendered as '.json_encode($desk['model_label']);
        }

        if ($desk['side_table'] !== ['stools' => [], 'more' => null]) {
            $d[] = 'an empty subagent array rendered as something other than no stools: '.json_encode($desk['side_table']);
        }

        foreach (array_keys($desk) as $element) {
            // The nameplate is `seat_id`'s (§ 5.1), not the retirement plate § 3.5 removed.
            if (str_contains($element, 'retire') || $element === 'plate') {
                $d[] = "a null `retired` drew a desk element `{$element}`";
            }
        }

        return $d;
    }

    /**
     * The walk over `nulls-b`, as a list of the members whose desk render is not their § 5.6 cell.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function walkDefects(array $result): array
    {
        $desk = $this->lastFrame($result)['desks'][$this->key('nulls-b')];
        $seat = $this->snapshotSeats(self::RUN)[$this->key('nulls-b')];
        $defects = [];

        foreach ($this->walk() as $member => $check) {
            if ($check instanceof Closure && $check($desk, $seat) !== true) {
                $defects[] = "{$member}: the desk does not render its § 5.6 cell — ".json_encode($desk);
            }
        }

        return $defects;
    }

    /**
     * Every member `nulls-b` sets null → the desk assertion of its § 5.6 cell, or the reason it is
     * not the desk's to assert. The keys are checked against the fixture, both directions, above.
     *
     * @return array<string, Closure|string>
     */
    private function walk(): array
    {
        $panel = 'panel half (TheDrillDownNeverDrawsANullAsAZeroTest): § 5.6 renders it in the drill-down\'s block, never on the desk';

        return [
            // desk half — § 5.6's cells that render on the desk.
            'action.descriptor' => static fn (array $d, array $s): bool => $d['monitor']['text'] === $s['action']['tool_name'],
            'action.agent_scope' => static fn (array $d): bool => $d['monitor']['subagent_call'] === false,
            'action.parent_call_id' => static fn (array $d): bool => $d['monitor']['subagent_call'] === false,
            'subagents[].title' => static fn (array $d): bool => $d['side_table']['stools'][0]['label'] === 'untitled'
                && $d['side_table']['stools'][0]['untitled'] === true,
            'subagents[].subagent_type' => static fn (array $d): bool => $d['side_table']['stools'][0]['type'] === null
                && $d['side_table']['stools'][0]['label'] === 'untitled',
            'context.used_tokens' => static fn (array $d, array $s): bool => $d['gauge']['numerals'] === 'not reported'
                && $d['gauge']['bar'] == $s['context']['used_pct'],
            'context.total_tokens' => static fn (array $d, array $s): bool => $d['gauge']['numerals'] === 'not reported'
                && $d['gauge']['pct'] === sprintf('%.1f %%', $s['context']['used_pct']),
            'activity.last_event_time' => static fn (array $d): bool => $d['last_event_time'] === null,
            'activity.last_received_at' => static fn (array $d): bool => $d['quiet_age'] === 'nothing done yet',
            'activity.last_kind' => static fn (array $d): bool => $d['last_kind'] === null,
            'delivery.last_receipt_at' => static fn (array $d): bool => $d['dark']['age'] === null
                && ! str_contains((string) $d['label_line'], 'no data for'),
            'delivery.no_data_since' => static fn (array $d): bool => $d['label_line'] === 'no data yet'
                && $d['dark']['since'] === 'no data yet',
            'api_error_type' => static fn (array $d): bool => ! str_contains((string) $d['label_line'], 'API error'),
            'blocked_since' => static fn (array $d): bool => ! str_contains((string) $d['label_line'], 'waiting on a human'),
            'badges_since' => static fn (array $d): bool => $d['oldest_badge_since'] === null,
            'enabled' => static fn (array $d): bool => $d['glyph'] !== 'monitor-off' && $d['monitor']['lit'] !== 'off',
            'retired' => static fn (array $d): bool => $d['nameplate'] === 'nulls-b',
            // Not the desk's.
            'task.ref' => 'no desk surface on this seat: § 11 — nulls-b draws no character, so it draws no bubble and asserts nothing about one',
            'session.started_at' => $panel,
            'session.source' => $panel,
            'session.project_label' => $panel,
            'session.harness_label' => $panel,
            'delivery.last_heartbeat_at' => $panel,
            'delivery.clock_skew_ms' => $panel,
            'delivery.spool_lag_events' => $panel,
            'delivery.oldest_unsent_age_s' => $panel,
            'delivery.seq_epoch' => $panel,
            'delivery.last_seq' => $panel,
            'reporter.version' => $panel,
            'reporter.platform' => $panel,
            'reporter.uptime_s' => $panel,
            'protocol_agent_name' => 'the floor\'s coordination line (§ 5.7, Appendix B step 7): the desk renders exactly as it did before',
            'protocol_agent_name_check' => 'the floor\'s coordination line (§ 5.7, Appendix B step 7): nothing is drawn',
        ];
    }

    /**
     * Every path the served `nulls-b` object sets null, in § 5.6's spelling (`subagents[].title`).
     *
     * @return list<string>
     */
    private function nullPopulation(): array
    {
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
            foreach ($node as $k => $v) {
                $path = is_int($k) ? rtrim($prefix, '.').'[].' : $prefix.$k;

                if ($v === null) {
                    $out[] = $path;
                } elseif (is_array($v) && $v !== []) {
                    $walk($v, is_int($k) ? $path : $path.'.');
                }
            }
        };

        $walk($this->snapshotSeats(self::RUN)[$this->key('nulls-b')], '');

        $out = array_values(array_unique($out));
        sort($out);

        $this->assertGreaterThan(20, count($out), 'nulls-b sets almost nothing null — the fixture is not the one § 11 states');

        return $out;
    }

    /** @return list<string> the members § 5.6's table has a row for, read from the document */
    private function section56Members(): array
    {
        $doc = $this->floorMd();
        $from = strpos($doc, '| D2 member | What renders when it is null |');
        $to = strpos($doc, '### 5.7 ');

        $this->assertIsInt($from, '§ 5.6\'s table was not found');
        $this->assertIsInt($to);

        preg_match_all('/^\| `([a-z_\[\].]+)` \|/m', substr($doc, $from, $to - $from), $m);

        $this->assertGreaterThan(30, count($m[1]), '§ 5.6\'s table parsed to almost nothing');

        return $m[1];
    }

    private function key(string $seatId): string
    {
        foreach (array_keys($this->snapshotSeats(self::RUN)) as $key) {
            if (str_ends_with($key, '/'.$seatId)) {
                return $key;
            }
        }

        $this->fail("fx-nulls carries no seat {$seatId}");
    }
}
