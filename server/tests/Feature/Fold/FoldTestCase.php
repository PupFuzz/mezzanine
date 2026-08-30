<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use App\Fold\Fold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared rig for the fold's acceptance tests.
 *
 * `docs/design/FLEET-STATE.md § 11`: "Every test below drives the fold with EVENT FIXTURES — arrays
 * of wire events in D1's exact shape, REPLAYED THROUGH THE REAL INGEST PATH into the real store."
 * That is what `deliver()` does: it POSTs to `/api/ingest/events` with a real token, so every test
 * here exercises validation, dedup, the batch write, the `head_event_id` advance and the § 2.3
 * cursor seed before the fold sees anything. A fixture inserted straight into `events` would prove
 * the fold reads its own writes.
 *
 * Six named fixtures are § 11's; they are built here and nowhere else.
 */
abstract class FoldTestCase extends TestCase
{
    use RefreshDatabase;

    protected const INSTALL = 'aimla';

    protected const SEAT = 'aimla-pm';

    /**
     * The source address every fixture batch is POSTed from.
     *
     * A constant rather than a literal because a second caller now needs the SAME address rather
     * than an address: `At19ReadAuthTest` asserts that read-plane auth failures from an address do
     * not throttle the INGEST at that address, and a test that used a different one would pass
     * whether the two planes shared a rate-limit bucket or not.
     */
    protected const REPORTER_IP = '203.0.113.10';

    protected string $token;

    protected int $seatRef;

    /** The seat clock the fixtures are written on. */
    protected string $sessionId = 'a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604';

    private int $seq = 1000;

    protected int $clockMs;

    protected function setUp(): void
    {
        parent::setUp();

        // A FIXED SERVER CLOCK, so the 2 s visibility lag and § 4.5's 300/900 s thresholds are
        // driven rather than waited for. Every test that cares about an age moves it explicitly.
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00.000', 'UTC'));
        $this->clockMs = Clock::toMs('2026-08-26 12:00:00.000');

        [$this->token, $this->seatRef] = $this->issueToken(self::INSTALL, self::SEAT);
    }

    protected function tearDown(): void
    {
        Fold::$afterEmptinessProof = null;
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{string, int} */
    protected function issueToken(string $install, string $seat): array
    {
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => $install, 'seat_id' => $seat, '--by' => 'suite',
        ])->assertSuccessful();

        $seatRef = (int) DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $install)->where('seats.seat_id', $seat)
            ->value('seats.id');

        $token = 'mzn_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('ingest_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 12),
            'seat_ref' => $seatRef,
            'created_at' => Clock::sql(now()),
            'created_by' => 'suite',
        ]);

        return [$token, $seatRef];
    }

    // ── driving ──────────────────────────────────────────────────────────────────────────────

    /**
     * POST a batch through the real ingest, then AGE ITS RECEIPT past the visibility lag so the
     * next fold pass can read it. Ageing is done by moving the SERVER clock forward rather than by
     * back-dating `events.received_at`, because back-dating would be the suite writing a column
     * the ingest owns — and § 2.3's whole argument is that the fold and the ingest write different
     * columns.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $envelope
     */
    protected function deliver(
        array $events,
        array $envelope = [],
        ?string $token = null,
        ?string $install = null,
        ?string $seat = null,
        bool $age = true,
    ): void {
        $install ??= self::INSTALL;
        $seat ??= self::SEAT;

        // D1 § 12.1's IDENTITY-BINDING RULE: the batch is attributed to the TOKEN, and a body
        // naming a different seat than the token is bound to is refused `403`. So a fixture
        // replayed onto a second seat must be re-addressed rather than merely re-tokened — the
        // ingest is right to refuse it, and the ingest's own suite asserts that refusal.
        $events = array_map(
            fn (array $event) => ['install_id' => $install, 'seat_id' => $seat] + $event,
            array_map(fn (array $event) => array_diff_key($event, array_flip(['install_id', 'seat_id'])), $events),
        );

        $body = $envelope + [
            'schema_version' => 1,
            'batch_id' => $this->ulid(),
            'install_id' => $install,
            'seat_id' => $seat,
            'reporter_version' => '0.1.0',
            'reporter_platform' => 'linux',
            'runtime_version' => 'v22.11.0',
            'seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH',
            // The batch's SEND time, not its newest event's time — which is what a real flusher
            // stamps and what D1 § 10.1's `clock_skew_ms` gauge is `received_at − sent_at` of. A
            // fixture that stamped the seat's event clock here would badge `clock_skew` on every
            // test that moves the seat clock, which is most of them, and the badge is
            // version-bearing — so the fixture's own bookkeeping would show up as state changes.
            'sent_at' => $this->wireTime(Clock::toMs(Clock::sql(now()))),
            'events' => $events,
        ];

        $response = $this->call(
            'POST', '/api/ingest/events',
            server: [
                'REMOTE_ADDR' => self::REPORTER_IP,
                'CONTENT_TYPE' => 'application/json; charset=utf-8',
                'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token),
            ],
            content: json_encode($body, JSON_UNESCAPED_SLASHES),
        );

        $response->assertStatus(202);

        // AT-D2-22 is the one caller that wants the batch left INSIDE the lag, because that window
        // is its subject. Everything else wants it aged out, so ageing is the default.
        if ($age) {
            $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);
        }
    }

    /** Run fold passes until the fleet is caught up (bounded, so a wedge fails rather than hangs). */
    protected function fold(int $maxPasses = 50): void
    {
        $fold = app(Fold::class);

        for ($i = 0; $i < $maxPasses; $i++) {
            if ($fold->pass() === 0 && ! $this->behind()) {
                return;
            }
        }

        $this->fail('the fold did not converge in '.$maxPasses.' passes');
    }

    protected function behind(): bool
    {
        return DB::table('seat_state')->whereColumn('fold_cursor_event_id', '<', 'head_event_id')->exists();
    }

    protected function advanceServerClock(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    // ── event builders ───────────────────────────────────────────────────────────────────────

    /**
     * One event in D1 § 4.3's common shape. `event_time` advances on the SEAT clock independently
     * of the server clock, which is what lets a test skew one against the other.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function event(string $kind, array $data = [], ?string $sessionId = null, ?int $seatClockMs = null): array
    {
        $this->clockMs += 1000;

        return [
            'event_id' => $this->ulid(),
            'schema_version' => 1,
            'kind' => $kind,
            'event_time' => $this->wireTime($seatClockMs ?? $this->clockMs),
            'seq' => $this->seq++,
            'install_id' => self::INSTALL,
            'seat_id' => self::SEAT,
            'session_id' => $kind === 'reporter.heartbeat' ? null : ($sessionId ?? $this->sessionId),
            'data' => $data,
        ];
    }

    /** § 11's `clean_turn`: turn.start, tool.start/tool.end(completed), turn.end(stop_hook, []). */
    protected function cleanTurn(?string $sessionId = null): array
    {
        $call = $this->ulid();

        return [
            $this->event('turn.start', ['prompt_chars' => 412], $sessionId),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => 'Bash: composer test',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => 'toolu_01A9F3kQ2mZ', 'open_calls_before' => 0,
            ], $sessionId),
            $this->event('tool.end', [
                'call_id' => $call, 'tool_name' => 'Bash', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 251, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'harness_ref',
            ], $sessionId),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 41880,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
            ], $sessionId),
        ];
    }

    /** § 11's `failed_call`: a call that RAN AND ERRORED — a closed call, which does not block idle. */
    protected function failedCall(): array
    {
        $call = $this->ulid();

        return [
            $this->event('turn.start', ['prompt_chars' => 12]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Edit', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('tool.end', [
                'call_id' => $call, 'tool_name' => 'Edit', 'outcome' => 'failed',
                'abort_reason' => null, 'duration_ms' => 18, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use_failure', 'match' => 'harness_ref',
            ]),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 900,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 1,
            ]),
        ];
    }

    /**
     * § 11's `clear_kill` — § 10's TEN events, E0…E9, "including the `turn.start` that opens the
     * trace. Ten, not nine: replaying E1–E9 alone leaves `T` false through the E5–E7 window, which
     * is precisely the window AT-D2-2's first RED probes."
     *
     * @param  bool  $sessionStartFirst  D1's alternate hook order — `SessionStart(clear)` before
     *                                   `SessionEnd(clear)`. Both reap idempotently, so the wire is
     *                                   the same sequence of kinds and the state path is identical.
     */
    protected function clearKill(bool $sessionStartFirst = false, bool $completedInstead = false): array
    {
        $dispatch = $this->ulid();
        $bash = $this->ulid();
        $next = 'b8e3d029-5c11-4f88-9a0d-3e72d5c9b024';

        $abortReason = $completedInstead ? null : 'session_cleared';
        $outcome = $completedInstead ? 'completed' : 'aborted';
        $closeSource = $completedInstead ? 'post_tool_use' : 'reap_session_boundary';
        $match = $completedInstead ? 'harness_ref' : 'reap';

        $e = [
            $this->event('turn.start', ['prompt_chars' => 412]),                          // E0
            $this->event('tool.start', [                                                  // E1
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main',
                'parent_call_id' => null, 'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [                                              // E2
                'call_id' => $dispatch, 'title' => 'draft the D1 event schema',
                'title_truncated' => false, 'subagent_type' => 'coder',
            ]),
            $this->event('tool.start', [                                                  // E3
                'call_id' => $bash, 'tool_name' => 'Bash', 'descriptor' => 'Bash: sleep 120',
                'descriptor_truncated' => false, 'agent_scope' => 'subagent',
                'parent_call_id' => $dispatch, 'harness_call_ref' => null, 'open_calls_before' => 1,
            ]),
            $this->event('tool.end', [                                                    // E4
                'call_id' => $bash, 'tool_name' => 'Bash', 'outcome' => $outcome,
                'abort_reason' => $abortReason, 'duration_ms' => 27411, 'duration_source' => 'index',
                'close_source' => $closeSource, 'match' => $match,
            ]),
            $this->event('tool.end', [                                                    // E5
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'outcome' => $outcome,
                'abort_reason' => $abortReason, 'duration_ms' => 184992, 'duration_source' => 'index',
                'close_source' => $closeSource, 'match' => $match,
            ]),
            $this->event('subagent.stop', [                                               // E6
                'call_id' => $dispatch, 'outcome' => $outcome, 'abort_reason' => $abortReason,
                'duration_ms' => 184992, 'close_source' => $closeSource,
            ]),
            $this->event('turn.end', [                                                    // E7
                'end_reason' => $completedInstead ? 'stop_hook' : 'session_cleared',
                'api_error_type' => null, 'duration_ms' => 41880,
                'open_calls_at_end' => $completedInstead ? 0 : 2,
                'aborted_call_ids' => $completedInstead ? [] : [$bash, $dispatch],
                'stop_hook_active' => false, 'background_tasks_open' => 0,
                'tool_calls' => 2, 'failed_calls' => 0,
            ]),
        ];

        $sessionEnd = $this->event('session.end', [                                       // E8
            'end_reason' => 'clear', 'duration_ms' => 938204, 'turns' => 1, 'aborted_calls' => 2,
        ]);

        $sessionStart = $this->event('session.start', [                                   // E9
            'source' => 'clear', 'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => $this->sessionId,
        ], $next);

        return $sessionStartFirst
            ? [...$e, $sessionStart, $sessionEnd]
            : [...$e, $sessionEnd, $sessionStart];
    }

    /**
     * AT-D2-2's CASE β — what the installed harness actually emits (card #7337): the parent's turn
     * ends CLEAN while carrying `background_tasks_open: 1`, its dispatched subagent then opens a
     * call, and a `/clear` reaps that call and ends the session.
     */
    protected function backgroundTaskTrace(): array
    {
        $dispatch = $this->ulid();
        $childCall = $this->ulid();

        return [
            $this->event('turn.start', ['prompt_chars' => 88]),
            $this->event('tool.start', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $dispatch, 'title' => 'audit the fold', 'title_truncated' => false,
                'subagent_type' => 'coder',
            ]),
            // The dispatch call closes `completed` 4–45 ms after `SubagentStart` (D1 § 6.4,
            // MEASURED) — so the parent's turn ends clean with the subagent still alive.
            $this->event('tool.end', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 41, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'harness_ref',
            ]),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 4100,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 1, 'tool_calls' => 1, 'failed_calls' => 0,
            ]),
            // 239 ms later, the subagent's own first tool call opens.
            $this->event('tool.start', [
                'call_id' => $childCall, 'tool_name' => 'Bash', 'descriptor' => 'Bash: sleep 120',
                'descriptor_truncated' => false, 'agent_scope' => 'subagent',
                'parent_call_id' => $dispatch, 'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('tool.end', [
                'call_id' => $childCall, 'tool_name' => 'Bash', 'outcome' => 'aborted',
                'abort_reason' => 'session_cleared', 'duration_ms' => 120, 'duration_source' => 'index',
                'close_source' => 'reap_session_boundary', 'match' => 'reap',
            ]),
            $this->event('session.end', [
                'end_reason' => 'clear', 'duration_ms' => 9000, 'turns' => 1, 'aborted_calls' => 1,
            ]),
        ];
    }

    /** § 11's `blocked_pair`. */
    protected function blockedPair(bool $requestOnly = false): array
    {
        $request = $this->ulid();
        $call = $this->ulid();

        $events = [
            $this->event('turn.start', ['prompt_chars' => 20]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Write', 'descriptor' => 'Write: notes.md',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('attention.request', [
                'request_id' => $request, 'source' => 'permission_request_hook',
                'notification_kind' => 'permission_required', 'call_id' => $call, 'open_calls' => 1,
            ]),
        ];

        if ($requestOnly) {
            return $events;
        }

        $events[] = $this->event('attention.resolved', [
            'request_id' => $request, 'resolution' => 'granted',
            'resolution_source' => 'call_close', 'waited_ms' => 8200,
        ]);

        return $events;
    }

    /** § 11's `heartbeat_only`: N heartbeats, one per minute, NO activity event of any kind. */
    protected function heartbeats(int $count, int $uptimeStart = 86_213): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $this->clockMs += 59_000;   // event() adds the remaining second
            $events[] = $this->event('reporter.heartbeat', [
                'uptime_s' => $uptimeStart + ($i * 60), 'spool_bytes' => 0, 'spool_files' => 1,
                'spool_lag_events' => 0, 'oldest_unsent_age_s' => null, 'last_hook_at' => null,
                'open_calls' => 0, 'open_sessions' => 0, 'open_attention' => 0, 'enabled' => true,
                'degraded' => [], 'counters' => ['batches_sent' => $i + 1], 'counters_omitted' => 0,
                'predicates' => [], 'selftest' => ['spool_writable' => 'pass'],
                'config_fingerprint' => '9f2c41a7be03d518',
            ]);
        }

        return $events;
    }

    // ── reading ──────────────────────────────────────────────────────────────────────────────

    protected function state(?int $seatRef = null): object
    {
        return DB::table('seat_state')->where('seat_ref', $seatRef ?? $this->seatRef)->first();
    }

    /** @return list<array{from: ?string, to: string, cause: string}> */
    protected function transitions(?int $seatRef = null): array
    {
        return DB::table('seat_state_transitions')
            ->where('seat_ref', $seatRef ?? $this->seatRef)->orderBy('id')
            ->get()
            ->map(fn ($r) => ['from' => $r->from_render_state, 'to' => $r->to_render_state, 'cause' => $r->cause])
            ->all();
    }

    protected function counter(string $name, ?int $seatRef = null): int
    {
        return (int) (DB::table('seat_counters')
            ->where('seat_ref', $seatRef ?? $this->seatRef)->where('name', $name)->value('value') ?? 0);
    }

    protected function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';

        for ($i = 0; $i < 26; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }

        return $out;
    }

    protected function wireTime(int $ms): string
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))
            ->modify('+'.($ms % 1000).' milliseconds')
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
