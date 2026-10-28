<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * AT-D2-32 — an intern's activity is the reporter's word, and an open dispatch is working until it
 * says otherwise (card#12451, `docs/design/FLEET-STATE.md` § 6.5's projection of
 * `subagent.activity`, § 8.2.1's `subagents[].activity_state`, § 4.8's row).
 *
 * Driven through the real ingest, fold, outbox and read plane, one delivered batch and one fold at a
 * time, so each "pass" below is one batch the fold took whole. The seven seats of the AT are the
 * seven tests here — each runs on a fresh store and the suite's default seat — because what each
 * asserts is a property of one seat's own history.
 *
 * ⚠ WHAT IT CANNOT SEE: that the REPORTER says `idle` at the right moment. That is D1's AT-29, in
 * `fleet-reporter/fleet-reporter.selftest.py`; this test is the server's half and takes the reporter's
 * word as given.
 */
class AnInternsActivityIsTheReportersWordTest extends FeedTestCase
{
    /** @return array<string, mixed> */
    private function dispatch(string $call, ?int $seatClockMs = null): array
    {
        return $this->event('tool.start', [
            'call_id' => $call, 'tool_name' => 'Agent', 'descriptor' => null,
            'descriptor_truncated' => false, 'agent_scope' => 'main',
            'parent_call_id' => null, 'harness_call_ref' => 'toolu_d', 'open_calls_before' => 0,
        ], seatClockMs: $seatClockMs);
    }

    /** @return array<string, mixed> */
    private function spawn(string $call, ?string $title = 'draft the schema', ?int $seatClockMs = null): array
    {
        return $this->event('subagent.spawn', [
            'call_id' => $call, 'title' => $title, 'title_truncated' => false, 'subagent_type' => 'coder',
        ], seatClockMs: $seatClockMs);
    }

    /** @return array<string, mixed> */
    private function childStart(string $call, string $parent): array
    {
        return $this->event('tool.start', [
            'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => null,
            'descriptor_truncated' => false, 'agent_scope' => 'subagent',
            'parent_call_id' => $parent, 'harness_call_ref' => null, 'open_calls_before' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function end(string $call, string $tool, string $source = 'post_tool_use'): array
    {
        return $this->event('tool.end', [
            'call_id' => $call, 'tool_name' => $tool, 'outcome' => 'completed',
            'abort_reason' => null, 'duration_ms' => 1000, 'duration_source' => 'harness',
            'close_source' => $source, 'match' => 'harness_ref',
        ]);
    }

    /** @return array<string, mixed> */
    private function activity(string $call, string $state, ?int $seatClockMs = null): array
    {
        return $this->event('subagent.activity', ['call_id' => $call, 'activity_state' => $state], seatClockMs: $seatClockMs);
    }

    /** @return array<string, mixed> the seat's § 8.2.1 object from the seat REST */
    private function served(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)
            ->assertOk()->json();
    }

    /** @return array<string, mixed> the same seat's object from the snapshot */
    private function snapshotted(): array
    {
        $snapshot = $this->asMachine($this->readToken('snap'), '/api/fleet/snapshot')->assertOk()->json();

        foreach ($snapshot['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                if ($seat['seat_id'] === self::SEAT) {
                    return $seat;
                }
            }
        }

        $this->fail('the snapshot carries no '.self::SEAT);
    }

    /** @return list<array<string, mixed>> every seat.delta after `$mark` whose `changed[]` names `subagents` */
    private function subagentDeltas(int $mark): array
    {
        return array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array('subagents', $d['payload']['changed'], true),
        ));
    }

    /** The seat-state columns an intern's activity must never move (§ 4.8). */
    private function seatFacts(): array
    {
        $row = (array) DB::table('seat_state')->where('seat_ref', $this->seatRef)->first([
            'activity_state', 'render_state', 'open_turn', 'last_activity_event_time',
            'last_activity_received_at', 'last_activity_kind', 'server_badges',
        ]);

        return $row;
    }

    /** Deliver one batch and fold it whole. */
    private function pass(array $events): void
    {
        $this->deliver($events);
        $this->fold();
    }

    /**
     * Seat 1 — idle then working, each in its own pass: one delta per flip, the whole array patched,
     * and nothing about the SEAT moves.
     */
    public function test_each_flip_is_one_delta_on_the_array_and_moves_nothing_of_the_seat(): void
    {
        $dispatch = $this->ulid();
        $child = $this->ulid();

        $this->pass([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->dispatch($dispatch), $this->spawn($dispatch),
            $this->childStart($child, $dispatch), $this->end($child, 'Bash'),
        ]);
        $this->assertSame('working', $this->served()['subagents'][0]['activity_state'],
            'the control: an open dispatch reads working before any activity event');

        $facts = $this->seatFacts();
        $transitions = count($this->transitions());

        $mark = $this->wire->mark();
        $this->pass([$this->activity($dispatch, 'idle')]);

        $deltas = $this->subagentDeltas($mark);
        $this->assertCount(1, $deltas, 'the idle pass did not produce exactly one delta naming subagents');
        $this->assertSame([['call_id' => $dispatch, 'activity_state' => 'idle']],
            array_map(fn ($s) => ['call_id' => $s['call_id'], 'activity_state' => $s['activity_state']],
                $deltas[0]['payload']['patch']['subagents']),
            'the delta does not patch the whole array with the element at idle');
        $this->assertCount(1, $this->wire->ofTypeFrom('seat.delta', $mark), 'the idle pass sent a second delta');

        $this->assertSame($facts, $this->seatFacts(), 'an intern going idle moved a fact of the SEAT (§ 4.8)');
        $this->assertSame('working', $this->state()->activity_state,
            'the seat left working while a dispatch call is open');
        $this->assertCount($transitions, $this->transitions(), 'an intern flip wrote a transition row');
        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertSame('idle', $seat['subagents'][0]['activity_state'], "{$surface}: the element is not idle");
        }

        $mark = $this->wire->mark();
        $this->pass([$this->activity($dispatch, 'working')]);

        $deltas = $this->subagentDeltas($mark);
        $this->assertCount(1, $deltas, 'the working pass did not produce exactly one delta naming subagents');
        $this->assertSame('working', $deltas[0]['payload']['patch']['subagents'][0]['activity_state']);
        $this->assertSame($facts, $this->seatFacts(), 'an intern resuming moved a fact of the SEAT (§ 4.8)');
        $this->assertCount($transitions, $this->transitions(), 'an intern flip wrote a transition row');
        $this->assertSame('working', $this->served()['subagents'][0]['activity_state']);

        // The uncapped list agrees with the capped array element by element (§ 8.2.3), and an
        // ordinary call carries no intern state.
        $this->pass([$this->childStart($this->ulid(), $dispatch)]);
        $seat = $this->served();
        $byCall = [];
        foreach ($seat['detail']['open_calls'] as $c) {
            $byCall[$c['call_id']] = $c;
        }
        foreach ($seat['subagents'] as $s) {
            $this->assertSame($s['activity_state'], $byCall[$s['call_id']]['activity_state'],
                'the seat-detail open-call list disagrees with subagents[] on the intern\'s state');
        }
        foreach ($byCall as $c) {
            if (! $c['is_dispatch']) {
                $this->assertNull($c['activity_state'], 'an ordinary call carries an intern state');
            }
        }
    }

    /** Seat 2 — no activity event ever: `working`, never null, never derived from the child's silence. */
    public function test_a_dispatch_no_activity_event_named_reads_working(): void
    {
        $dispatch = $this->ulid();
        $child = $this->ulid();

        $this->pass([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->dispatch($dispatch), $this->spawn($dispatch),
            $this->childStart($child, $dispatch), $this->end($child, 'Bash'),
        ]);
        // Hours of server time pass with no hook under the subagent; the server mints nothing.
        $this->advanceServerClock(3 * 3600);
        $this->fold();

        $this->assertSame(0, DB::table('events')->where('kind', 'subagent.activity')->count(),
            'the control: no activity event was stored');
        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertArrayHasKey('activity_state', $seat['subagents'][0], "{$surface}: the member is absent");
            $this->assertSame('working', $seat['subagents'][0]['activity_state'],
                "{$surface}: an intern with no reporter word is not working");
        }
    }

    /** Seat 3 — the two events in reverse arrival order converge on the later `event_time`. */
    public function test_the_later_event_time_wins_whatever_the_arrival_order(): void
    {
        $dispatch = $this->ulid();
        $this->pass([$this->event('turn.start', ['prompt_chars' => 40]), $this->dispatch($dispatch), $this->spawn($dispatch)]);

        $earlier = $this->clockMs + 10_000;
        $later = $this->clockMs + 20_000;

        $this->pass([$this->activity($dispatch, 'working', $later)]);
        $this->pass([$this->activity($dispatch, 'idle', $earlier)]);

        $this->assertSame('working', $this->served()['subagents'][0]['activity_state'],
            'the earlier idle, arriving second, overwrote the later working (no LWW guard)');
    }

    /** Seat 4 — an activity for a `call_id` no row holds is stored and projects nothing. */
    public function test_an_activity_for_an_unknown_call_creates_nothing(): void
    {
        $this->pass([$this->event('turn.start', ['prompt_chars' => 40])]);
        $before = $this->served();
        $calls = DB::table('calls')->count();
        // Every counter but `accepted`, which counts the batch itself and is meant to rise.
        $counters = fn () => DB::table('seat_counters')->where('seat_ref', $this->seatRef)
            ->where('name', '!=', 'accepted')->pluck('value', 'name')->all();
        $countersBefore = $counters();

        $orphan = $this->ulid();
        $this->pass([$this->activity($orphan, 'idle')]);

        $this->assertSame(1, DB::table('events')->where('kind', 'subagent.activity')->count(), 'the event was not stored');
        $this->assertSame($calls, DB::table('calls')->count(), 'an activity event for no call created a calls row');
        $after = $this->served();
        $this->assertSame($before['subagents'], $after['subagents'], 'an intern appeared that nobody opened');
        $this->assertSame($before['subagents_open'], $after['subagents_open']);
        $this->assertSame($before['state_version'], $after['state_version'], 'a seat-object member moved');
        $this->assertSame($countersBefore, $counters(), 'a counter rose for an orphan activity event');
    }

    /** Seat 5 — the dispatch, the idle and the working in ONE pass: two activity deltas, in order. */
    public function test_an_idle_and_a_working_in_one_pass_are_two_deltas_in_order(): void
    {
        $dispatch = $this->ulid();
        $mark = $this->wire->mark();
        $this->pass([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->dispatch($dispatch), $this->spawn($dispatch),
            $this->activity($dispatch, 'idle'), $this->activity($dispatch, 'working'),
        ]);

        $states = array_map(
            fn ($d) => $d['payload']['patch']['subagents'][0]['activity_state'] ?? null,
            $this->subagentDeltas($mark),
        );
        // The open and the spawn's title each version the array too; the last two are the flips.
        $this->assertGreaterThanOrEqual(3, count($states), 'the pass did not version each projected event');
        $this->assertSame(['idle', 'working'], array_slice($states, -2),
            'the one pass did not yield an idle delta and then a working delta');
    }

    /** Seat 6 — a dispatch opened and closed in one pass is carried by one version and not the next. */
    public function test_a_dispatch_opened_and_closed_in_one_pass_is_a_gain_and_a_loss(): void
    {
        $dispatch = $this->ulid();
        $mark = $this->wire->mark();
        $open = $this->dispatch($dispatch);
        $close = $this->end($dispatch, 'Agent');
        $this->pass([$this->event('turn.start', ['prompt_chars' => 40]), $open, $close]);

        $arrays = array_map(
            fn ($d) => array_column($d['payload']['patch']['subagents'], 'call_id'),
            $this->subagentDeltas($mark),
        );
        $this->assertSame([[$dispatch], []], $arrays,
            'the dispatch was not carried by one version and dropped by the next');

        $listed = array_column($this->actingAs($this->enrolled())
            ->getJson('/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT.'/timeline')->assertOk()->json('events'), 'event_id');
        $this->assertContains($open['event_id'], $listed, 'the drill-down timeline does not list the dispatch\'s open');
        $this->assertContains($close['event_id'], $listed, 'the drill-down timeline does not list the dispatch\'s close');
    }

    /**
     * Seat 7 — a late spawn re-fill with a LATER event_time, then a working stamped BETWEEN the idle
     * and the spawn: neither comparator refuses the other's fact.
     */
    public function test_the_activity_and_the_spawn_are_guarded_on_their_own_comparators(): void
    {
        $dispatch = $this->ulid();
        $base = $this->clockMs;

        $this->pass([
            $this->event('turn.start', ['prompt_chars' => 40], seatClockMs: $base + 1_000),
            $this->dispatch($dispatch, $base + 2_000),
        ]);
        $this->pass([$this->activity($dispatch, 'idle', $base + 10_000)]);
        $this->pass([$this->spawn($dispatch, 'a title that arrived late', $base + 30_000)]);
        $this->pass([$this->activity($dispatch, 'working', $base + 20_000)]);

        $element = $this->served()['subagents'][0];
        $this->assertSame('a title that arrived late', $element['title'], 'the spawn was refused');
        $this->assertSame('working', $element['activity_state'],
            'the working, older than the spawn but newer than the idle, was refused by the row\'s applied triple');
        $this->assertNull(DB::table('calls')->where('call_id', $dispatch)->value('closed_at'), 'the row is not open');
    }
}
