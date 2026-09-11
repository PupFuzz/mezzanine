<?php

namespace Tests\Feature\DrillDown;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig every drill-down test shares — the PANEL's half of it. The generic half (run the
 * shipped modules under `node`, run a mutated copy, assert the anchor exists exactly once, walk
 * the relative imports) is `Tests\Feature\Support\DrivesAShippedClientModule` and is not
 * restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE FIXTURES ARE D2's WORKED OBJECTS, TRANSCRIBED FIELD FOR FIELD, so that what this suite
 * asserts a panel renders is asserted over the object `docs/design/FLEET-STATE.md § 8.2.2`
 * publishes rather than over one invented here. Where a fixture goes beyond that object it is
 * because § 8.2.2 does not publish the thing — the `detail` member (§ 8.2.3 names it and
 * publishes no field table for it, § 14 item 1) and the timeline response — and each such
 * member is built from the columns THIS SERVER actually serves, which is `FleetController`.
 *
 * ⛔ THE CORRECTED CLOCK IS AN ARGUMENT, NEVER `now()`. Every age this panel renders is
 * `docs/design/FLOOR.md § 2.4`'s corrected clock minus a wire instant, so a fixture that read
 * the wall clock would assert a different string every second. `self::NOW` is the one instant
 * this suite measures from, chosen so that each of the three age shapes the panel draws lands on
 * a value § 2.4's own boundary table names.
 */
trait DrivesTheDrillDownClient
{
    use DrivesAShippedClientModule;

    /** § 2.4's boundary table — its bounds, and the header row that opens it. */
    private const S24_TABLE = '| Seconds in | Renders | The clause it is here for |';

    /**
     * The corrected clock every fixture below is read at: 125 s after the worked object's
     * `context.sampled_received_at`, so the context sample's age is § 2.4's own `2m 05s`.
     */
    private const NOW = '2026-08-23T14:43:09.880Z';

    /** The shipped client modules — the ones the browser is served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/drilldown')
            ?: $this->fail('server/public/js/drilldown does not exist — the client this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/drilldown-probe.mjs';
    }

    /** `self::NOW`, in the milliseconds the model takes. */
    protected function nowMs(): int
    {
        return (int) round(((float) \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z', self::NOW, new \DateTimeZone('UTC'),
        )->format('U.u')) * 1000);
    }

    /**
     * § 2.4's boundary table, parsed out of the document: `[[seconds, rendered], …]`.
     *
     * ⛔ `strpos` RESULTS ARE CHECKED BEFORE THEY ARE USED AS BOUNDS. A renamed header answers
     * `false`, and `false` in arithmetic is `0` — which silently hands the parse the whole
     * document from its start. `DurationFormatMatchesTheDocumentTest`'s CONTROL 1 plants that
     * rename and requires this to stop returning rows.
     *
     * @return list<array{string, string}>
     */
    protected function documentDurations(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, self::S24_TABLE);

        if ($open === false) {
            return [];
        }

        $rows = [];
        $lines = explode("\n", substr($md, $open));
        $separator = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                // The table's rows are contiguous; the first line that is not one ends it, which
                // is what keeps the walk from running on into § 2.4's next table.
                if ($separator) {
                    break;
                }

                continue;
            }

            if (preg_match('/^\|\s*-{3,}/', $line) === 1) {
                $separator = true;

                continue;
            }

            // The document writes a negative with U+2212 MINUS SIGN, which is not the ASCII
            // hyphen any numeric parse expects. It is normalised rather than matched around,
            // because the row it appears in is clause 1's — the one row whose whole subject is a
            // NEGATIVE input, and a parser that skipped it would drop the clause it proves.
            $cells = array_map(trim(...), explode('|', str_replace("\u{2212}", '-', $line)));

            if (count($cells) < 4 || preg_match('/^-?\d+(\.\d+)?$/', $cells[1]) !== 1) {
                continue;
            }

            $rows[] = [$cells[1], trim($cells[2], '`')];
        }

        return $rows;
    }

    /**
     * `GET /api/fleet/seats/aimla/aimla-pm`'s body: D2 § 8.2.2's worked seat object transcribed
     * field for field, plus the two members only this endpoint carries — `server_time` (§ 8.2)
     * and `detail` (§ 8.2.3).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function seatBody(array $overrides = [], ?array $detail = null): array
    {
        return array_merge([
            'api_version' => 1,
            'server_time' => self::NOW,
            'install_id' => 'aimla',
            'seat_id' => 'aimla-pm',
            'state_version' => 48219,
            'render_state' => 'working',
            'link_state' => 'live',
            'activity_state' => 'working',
            'unknown_reason' => null,
            'api_error_type' => null,
            'blocked_since' => null,
            'action' => [
                'call_id' => '01K3TA4E5F6G7H8J9K0M1N2P3Q',
                'tool_name' => 'Bash',
                'descriptor' => 'Bash: composer test',
                'started_at' => '2026-08-23T14:23:09.882Z',
                'started_received_at' => '2026-08-23T14:23:14.201Z',
                'agent_scope' => 'main',
                'parent_call_id' => null,
            ],
            'open_calls' => 1,
            'open_turn' => true,
            'subagents' => [[
                'call_id' => '01K3TA6G7H8J9K0M1N2P3Q4R5T',
                'title' => 'draft the D1 event schema',
                'subagent_type' => 'coder',
                'started_at' => '2026-08-23T14:23:31.004Z',
            ]],
            'subagents_open' => 1,
            'task' => [
                'title' => 'ingest endpoint',
                'source' => 'board_card',
                'ref' => 'card#7338',
                'as_of' => '2026-08-23T14:05:00.000Z',
                'degraded' => false,
            ],
            'context' => [
                'used_pct' => 73.2,
                'used_tokens' => 146401,
                'total_tokens' => 200000,
                'source' => 'harness',
                'sampled_at' => '2026-08-23T14:41:00.310Z',
                'sampled_received_at' => '2026-08-23T14:41:04.880Z',
            ],
            'model_label' => 'claude-opus-5',
            'session' => [
                'session_id' => 'a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604',
                'started_at' => '2026-08-23T14:22:40.201Z',
                'source' => 'clear',
                'project_label' => 'mezzanine',
                'harness_label' => 'claude-code/2.1.240',
            ],
            'activity' => [
                'last_event_time' => '2026-08-23T14:23:09.882Z',
                'last_received_at' => '2026-08-23T14:23:14.201Z',
                'last_kind' => 'tool.start',
            ],
            'delivery' => [
                'last_receipt_at' => '2026-08-23T14:23:14.201Z',
                'last_heartbeat_at' => '2026-08-23T14:23:00.412Z',
                'no_data_since' => null,
                'clock_skew_ms' => 412,
                'spool_lag_events' => 0,
                'oldest_unsent_age_s' => null,
                'seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH',
                'last_seq' => 48211,
            ],
            'badges' => ['lossy'],
            'badges_since' => '2026-08-23T09:14:02.118Z',
            'enabled' => true,
            'reporter' => [
                'version' => '0.1.0', 'platform' => 'linux', 'uptime_s' => 401150,
                'selftest_failed' => [],
            ],
            'retired' => null,
            'derivation' => [
                'computed_at' => '2026-08-23T14:23:14.318Z',
                'fold_lag_ms' => 117,
                'cursor_event_id' => 9912837,
            ],
            'detail' => $detail ?? $this->detailBody(),
        ], $overrides);
    }

    /**
     * § 8.2.3's `detail`, built from the columns `App\Http\Controllers\FleetController::detail()`
     * actually serves — D2 publishes no field table for it (§ 14 item 1), so the fixture is read
     * off the producer rather than invented.
     *
     * `$dispatches` open dispatch calls (the interns), the last of them with a **null title** —
     * AT-D3-4's honest orphan — plus the seat's OWN open `Bash` call and one call made by an
     * intern, which are the two rows that must not be listed as interns.
     *
     * @return array<string, mixed>
     */
    protected function detailBody(int $dispatches = 1): array
    {
        $calls = [];

        for ($i = 0; $i < $dispatches; $i++) {
            $last = $i === $dispatches - 1;

            $calls[] = [
                'call_id' => sprintf('01K3TA6G7H8J9K0M1N2P3Q4R%02d', $i),
                'tool_name' => 'Agent',
                'descriptor' => null,
                'agent_scope' => 'main',
                'parent_call_id' => null,
                'is_dispatch' => 1,
                'title' => $last ? null : 'draft the D1 event schema',
                'subagent_type' => 'coder',
                'opened_at' => '2026-08-23T14:23:31.004Z',
                'orphan_due_at' => '2026-08-23T15:23:31.004Z',
            ];
        }

        return [
            'heartbeat_counters' => null,
            'heartbeat_predicates' => null,
            'counters' => [],
            'predicates' => [],
            'open_calls' => [
                ...$calls,
                // The seat's OWN call. § 5.2: "the panel that listed every one would call a
                // seat's own `Bash` call an intern".
                [
                    'call_id' => '01K3TA4E5F6G7H8J9K0M1N2P3Q',
                    'tool_name' => 'Bash',
                    'descriptor' => 'Bash: composer test',
                    'agent_scope' => 'main',
                    'parent_call_id' => null,
                    'is_dispatch' => 0,
                    'title' => null,
                    'subagent_type' => null,
                    'opened_at' => '2026-08-23T14:23:09.882Z',
                    'orphan_due_at' => '2026-08-23T14:38:09.882Z',
                ],
                // A call the INTERN is running. It is what § 5.2's stated predicate selects and
                // what § 8's label rows cannot draw: no title, no type, ever.
                [
                    'call_id' => '01K3TB0000000000000000000',
                    'tool_name' => 'Bash',
                    'descriptor' => 'Bash: sleep 120',
                    'agent_scope' => 'subagent',
                    'parent_call_id' => '01K3TA6G7H8J9K0M1N2P3Q4R00',
                    'is_dispatch' => 0,
                    'title' => null,
                    'subagent_type' => null,
                    'opened_at' => '2026-08-23T14:24:02.500Z',
                    'orphan_due_at' => '2026-08-23T14:39:02.500Z',
                ],
            ],
            'attention' => null,
            'session' => null,
        ];
    }

    /**
     * `GET …/timeline?limit=50`'s body, from the columns that endpoint serves. The receipt
     * instant is 252 s before `self::NOW`, so the row's age is § 2.4's own `4m 12s`.
     *
     * @return array<string, mixed>
     */
    protected function timelineBody(?array $events = null): array
    {
        return [
            'api_version' => 1,
            'server_time' => self::NOW,
            'install_id' => 'aimla',
            'seat_id' => 'aimla-pm',
            'limit' => 50,
            'next_before' => null,
            // ⛔ `null` MEANS "the default row", `[]` MEANS "an empty window". They are different
            // fixtures for different assertions — § 5.2's *no activity in this window* is only
            // reachable from the second — and a default that fired on `[]` would make the empty
            // case unwritable.
            'events' => $events ?? [[
                'event_id' => '01K3TA4E5F6G7H8J9K0M1N2P3Q',
                'kind' => 'tool.start',
                'event_time' => '2026-08-23T14:38:53.882Z',
                'received_at' => '2026-08-23T14:38:57.880Z',
                'session_id' => 'a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604',
            ]],
        ];
    }
}
