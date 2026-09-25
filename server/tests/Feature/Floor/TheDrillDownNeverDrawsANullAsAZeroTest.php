<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-14 — a null is never drawn as a zero, the PANEL half.** `docs/design/FLOOR.md § 11`, gated at
 * Appendix B **step 10** (card#7342; the desk half is step 5's, `TheDeskRenderNeverDrawsANullAsAZeroTest`).
 * "The same fixture with the drill-down opened on each of the two seats, plus the operator health view
 * for the `counters` assertion. **Reads:** the harness, the drill-down, the uncapped intern list."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ROW BY ROW AGAINST § 5.6, READ OUT OF THE DOCUMENT. `nulls-b`'s null members are DERIVED from the
 * fixture's own object, each is mapped to the place the PANEL draws it, and the words each must read are
 * the ones its § 5.6 cell sets in italics — *not reported*, *no data yet*, *nothing done yet*,
 * *untitled* — parsed from the cell on every run. A member whose cell says the element is not drawn
 * must render nothing at all. A member only the desk or the coordination line draws is named as such
 * rather than silently skipped.
 *
 * ⚠ `delivery.clock_skew_ms` IS HELD TO § 5.6's CELL, WHICH DOES NOT SAY *not reported*. The Build list
 * of AT-D3-14 names it among four that read *not reported*; § 5.6 — which the GREEN says the walk is
 * asserted against — says "not rendered, and no seat-clock timestamp gains a skew note". The cell wins
 * here and the test bullet is amended to agree with it (card#7342 step 10).
 *
 * ⚠ THE OPERATOR HEALTH VIEW IS NOT A PAGE YET. § 5.3 renders `counters` "on an operator view of the
 * health endpoint only" and no Appendix B row builds that view; the render is `lobby/lobby-model.js`'s
 * `healthCounters`, and the assertion is on it.
 */
class TheDrillDownNeverDrawsANullAsAZeroTest extends TestCase
{
    use DrivesTheDrillDown;

    private const RUN = 'panel_nulls';

    /**
     * Where the PANEL draws each nullable member `nulls-b` carries null: the model path, and whether the
     * § 5.6 cell's words or an absence is what it must read. `null` is a member the panel does not draw.
     */
    private const PANEL = [
        'action.descriptor' => ['action.descriptor', 'absent'],
        'action.agent_scope' => ['action.agent_scope', 'absent'],
        'action.parent_call_id' => ['action.parent_call_id', 'absent'],
        'subagents[].title' => ['interns.rows.0.label', 'words'],
        'subagents[].subagent_type' => ['interns.rows.0.type', 'absent'],
        'task.ref' => ['task.ref', 'absent'],
        'context.used_tokens' => ['context.numerals', 'words'],
        // § 5.6's cell reads "as above" — the `used_tokens` row's words, for the one element both draw.
        'context.total_tokens' => ['context.numerals', 'words', 'context.used_tokens'],
        'session.started_at' => ['session.started_at', 'absent'],
        'session.source' => ['session.source', 'absent'],
        'session.project_label' => ['session.project_label', 'absent'],
        'session.harness_label' => ['session.harness_label', 'absent'],
        'activity.last_event_time' => ['quiet_age.last_event_time', 'absent'],
        'activity.last_received_at' => ['transport.quiet_age', 'words'],
        'activity.last_kind' => ['quiet_age.last_kind', 'absent'],
        'delivery.last_receipt_at' => ['transport.receipt_age', 'words'],
        'delivery.last_heartbeat_at' => ['transport.heartbeat', 'words'],
        'delivery.no_data_since' => ['transport.no_data_since', 'absent'],
        'delivery.clock_skew_ms' => ['transport.clock_skew', 'absent'],
        'delivery.spool_lag_events' => ['transport.spool_lag_events', 'words'],
        'delivery.oldest_unsent_age_s' => ['transport.oldest_unsent', 'words'],
        'delivery.seq_epoch' => ['transport.seq_epoch', 'absent'],
        'delivery.last_seq' => ['transport.last_seq', 'words'],
        'reporter.version' => ['reporter.version', 'words'],
        'reporter.platform' => ['reporter.platform', 'words'],
        'reporter.uptime_s' => ['reporter.uptime', 'words'],
        'badges_since' => ['badges.since', 'absent'],
        'enabled' => ['reporter.enabled', 'absent'],
        'protocol_agent_name' => null,
        'protocol_agent_name_check' => null,
        'unknown_reason' => null,
        'api_error_type' => null,
        'blocked_since' => null,
        'model_label' => ['session.model_label', 'absent'],
        'retired' => null,
    ];

    /** The RED: a null coalesced to zero — the spool line's, in the panel. */
    private const ZEROED_SPOOL = ['../drilldown/drilldown-model.js',
        'spool_lag_events: Number.isInteger(d.spool_lag_events) ? String(d.spool_lag_events) : NOT_REPORTED,',
        'spool_lag_events: String(d.spool_lag_events ?? 0),'];

    /** The RED the test names: "a seat that has never reported a context sample renders a full, empty gauge reading 0 %". */
    private const ZEROED_GAUGE = ['context-gauge.js',
        'return { reported: false, statement: NOT_REPORTED, bar: null, pct: null };',
        "return { reported: true, statement: null, bar: 0, pct: '0.0 %', numerals: '0 / 0', source: null, sampled_at: null, age: null };"];

    /** A red of its own: the empty intern list drawn as a list with nothing in it. */
    private const EMPTY_LIST = ['../drilldown/drilldown-model.js',
        'return { open, sourced: true, capped: false, statement: null, rows, listed: rows.length > 0, failed: false };',
        'return { open, sourced: true, capped: false, statement: null, rows, listed: true, failed: false };'];

    /** A red of its own: the health view's unreadable counters drawn as a column of zeros. */
    private const ZEROED_COUNTERS = ['../lobby/lobby-model.js',
        "        return { readable: false, statement: UNREADABLE, rows: [] };",
        "        return { readable: true, statement: null, rows: [{ name: 'snapshot_served', value: '0' }] };"];

    public function test_green_the_containers_and_every_member_render_their_section_5_6_cells(): void
    {
        $this->assertSame([], $this->defects());
    }

    /** The discriminating control: a MEASURED 0.0 % draws a bar at 0 % in the panel too. */
    public function test_control_a_real_zero_draws_a_bar_at_zero(): void
    {
        $panel = $this->openPanel($this->floorRun('panel_real_zero'), 'real-zero');

        $this->assertTrue($panel['context']['reported']);
        $this->assertSame(0, $panel['context']['bar'], 'a measured 0.0 % drew no bar — the test could not tell a zero from an absence');
        $this->assertSame('0.0 %', $panel['context']['pct']);

        $counters = $this->probe(['repeat' => 0, 'health_counters' => [['snapshot_served' => 0]]])['health_counters'][0];
        $this->assertSame([['name' => 'snapshot_served', 'value' => '0']], $counters['rows'],
            'a counter the health endpoint COUNTED at zero was not drawn as zero — the unreadable check could not tell the two apart');
    }

    public function test_red_a_null_coalesced_to_zero_is_caught(): void
    {
        $spool = $this->defects($this->mutatedModules(self::ZEROED_SPOOL));
        $this->assertArrayHasKey('delivery.spool_lag_events', $spool, 'the zeroed-spool RED did not bite: '.json_encode($spool));

        $gauge = $this->defects($this->mutatedModules(self::ZEROED_GAUGE));
        $this->assertArrayHasKey('context', $gauge, 'the zeroed-gauge RED did not bite: '.json_encode($gauge));
    }

    public function test_red_the_empty_intern_list_and_the_zeroed_counters_are_caught(): void
    {
        $list = $this->defects($this->mutatedModules(self::EMPTY_LIST));
        $this->assertArrayHasKey('interns', $list, 'the empty-list RED did not bite: '.json_encode($list));

        $counters = $this->defects($this->mutatedModules(self::ZEROED_COUNTERS));
        $this->assertArrayHasKey('counters', $counters, 'the zeroed-counters RED did not bite: '.json_encode($counters));
    }

    /** @return array<string, string> */
    private function defects(?string $dir = null): array
    {
        $result = $this->floorRun(self::RUN, $dir);
        $a = $this->openPanel($result, 'nulls-a');
        $b = $this->openPanel($result, 'nulls-b');
        $defects = [];

        // ── `nulls-a`, the containers, panel half. ──
        $noSession = $this->cellWords('session');

        if ($a['session']['present'] !== false || $a['session']['statement'] !== $noSession) {
            $defects['session'] = "`session` null reads ".json_encode($a['session']['statement']).", not *{$noSession}*";
        }

        if ($a['interns']['listed'] !== false || $a['interns']['rows'] !== []) {
            $defects['interns'] = "`nulls-a`'s intern list is drawn — it must be ABSENT, not an empty list";
        }

        if ($a['context']['reported'] !== false || $a['context']['bar'] !== null || $a['context']['statement'] !== $this->cellWords('context')) {
            $defects['context'] = '`context` null did not read *not reported* with no bar: '.json_encode($a['context']);
        }

        $unreadable = $this->documentUnreadable();
        $health = $this->probe(['repeat' => 0, 'health_counters' => [null]], $dir)['health_counters'][0];

        if ($health['readable'] !== false || $health['statement'] !== $unreadable || $health['rows'] !== []) {
            $defects['counters'] = "a `null` `counters` on the health view did not read *{$unreadable}*: ".json_encode($health);
        }

        // ── `nulls-b`, member by member. ──
        $walked = 0;

        foreach ($this->nullMembers($this->nullsB()) as $member) {
            $this->assertArrayHasKey($member, self::PANEL,
                "`nulls-b` carries `{$member}` null and this test does not say where the panel draws it — map it or name it as a desk member");

            if (self::PANEL[$member] === null) {
                continue;
            }

            [$path, $kind] = self::PANEL[$member];
            $words = $kind === 'words' ? $this->cellWords(self::PANEL[$member][2] ?? $member) : null;
            $value = $this->at($b, $path);
            $walked++;

            if ($value === 0 || $value === '0' || (is_string($value) && preg_match('/(^|\D)0( |$)/', $value) === 1 && $kind === 'words')) {
                $defects[$member] = "`{$member}` null is drawn as a zero: ".json_encode($value);

                continue;
            }

            if ($kind === 'absent' && $value !== null) {
                $defects[$member] = "`{$member}` null draws ".json_encode($value).' — § 5.6 draws nothing there';
            }

            if ($kind === 'words' && $value !== $words) {
                $defects[$member] = "`{$member}` null reads ".json_encode($value).", not § 5.6's *{$words}*";
            }
        }

        $this->assertGreaterThan(20, $walked, 'the member walk read almost nothing — it is not the population § 5.6 names');

        // § 5.6's skew half: with the skew null, NO seat-clock timestamp on the panel gains a note.
        if ($b['skew'] !== null || str_contains(json_encode($b), 'from the server')) {
            $defects['delivery.clock_skew_ms'] ??= 'a seat-clock timestamp gained a skew note on a seat whose skew is null';
        }

        return $defects;
    }

    /** `nulls-b`, as the run serves it. */
    private function nullsB(): array
    {
        foreach ($this->fixture(self::RUN)['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                if ($seat['seat_id'] === 'nulls-b') {
                    return $seat;
                }
            }
        }

        $this->fail('the run serves no `nulls-b`');
    }

    /**
     * Every member path the object carries as null, in § 5.6's spelling — `subagents[].title` for an
     * array element's member.
     *
     * @return list<string>
     */
    private function nullMembers(array $object, string $prefix = ''): array
    {
        $out = [];

        foreach ($object as $name => $value) {
            $path = $prefix === '' ? (string) $name : "{$prefix}.{$name}";

            if ($value === null) {
                $out[] = $path;
            } elseif (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                array_push($out, ...$this->nullMembers($value[0], "{$path}[]"));
            } elseif (is_array($value) && ! array_is_list($value)) {
                array_push($out, ...$this->nullMembers($value, $path));
            }
        }

        return array_values(array_unique($out));
    }

    /** A dotted model path, `interns.rows.0.label`. */
    private function at(array $model, string $path): mixed
    {
        $value = $model;

        foreach (explode('.', $path) as $step) {
            if (! is_array($value) || ! array_key_exists($step, $value)) {
                return null;
            }

            $value = $value[$step];
        }

        return $value;
    }

    /**
     * The words a § 5.6 cell sets for its member — the first italic phrase in the cell, bold or not.
     */
    private function cellWords(string $member): string
    {
        $doc = $this->floorMd();
        $start = strpos($doc, '| D2 member | What renders when it is null |');

        $this->assertNotFalse($start, '§ 5.6\'s table was not found');

        $this->assertSame(1, preg_match('/^\| `'.preg_quote($member, '/').'` \| (.+) \|$/m', substr($doc, $start), $row),
            "§ 5.6 has no row for `{$member}`");
        $this->assertSame(1, preg_match('/\*{1,3}([a-z][a-z ]*[a-z])\*{1,3}/', $row[1], $m),
            "§ 5.6's `{$member}` cell sets no italic words for this test to read");

        return $m[1];
    }

    /** § 5.3's fleet-counters cell: "a `null` `counters` renders as *unreadable*". */
    private function documentUnreadable(): string
    {
        $this->assertSame(1, preg_match('/a `null` `counters` renders as \*([a-z]+)\*/', $this->floorMd(), $m),
            '§ 5.3\'s fleet-counters cell did not parse');

        return $m[1];
    }
}
