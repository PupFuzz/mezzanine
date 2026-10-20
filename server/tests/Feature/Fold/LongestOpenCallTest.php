<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * card#12113 — § 8.2.1's `longest_open_call`, driven through the real ingest, fold and read plane,
 * with the VALUES asserted.
 *
 * `docs/design/FLEET-STATE.md § 8.2.1` owns the member: the OLDEST open call of the same open set
 * `action` is the newest of, ordered by `opened_received_at` ascending and then `id` ascending;
 * `null` exactly when `open_calls` is 0, and the same call `action` names when exactly one is open.
 * § 10's trace E1 / E3 / E5 is the case it exists for: an intern's call replaces the dispatch as
 * `action` while the dispatch stays open.
 *
 * ⛔ WHAT THE DRIFT GUARD CANNOT SEE. `SeatObjectMatchesTheDocumentTest` asserts every § 8.2.1 NAME is
 * on the object, so a `longest_open_call` that copied `action`, or ordered on the seat clock, passes
 * it. These tests pin which call is named, on which clock, with which tie-break, and the delta.
 */
class LongestOpenCallTest extends FeedTestCase
{
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

    /** @return array<string, mixed> */
    private function toolStart(string $call, string $tool, string $scope = 'main', ?string $parent = null, ?int $seatClockMs = null): array
    {
        return $this->event('tool.start', [
            'call_id' => $call, 'tool_name' => $tool, 'descriptor' => null,
            'descriptor_truncated' => false, 'agent_scope' => $scope,
            'parent_call_id' => $parent, 'harness_call_ref' => null, 'open_calls_before' => 0,
        ], seatClockMs: $seatClockMs);
    }

    /** @return array<string, mixed> */
    private function toolEnd(string $call, string $tool): array
    {
        return $this->event('tool.end', [
            'call_id' => $call, 'tool_name' => $tool, 'outcome' => 'completed',
            'abort_reason' => null, 'duration_ms' => 1000, 'duration_source' => 'harness',
            'close_source' => 'post_tool_use', 'match' => 'harness_ref',
        ]);
    }

    /** The server-clock receipt of call `$call`'s open, on the wire. */
    private function receivedAt(string $call): string
    {
        return Clock::wire(DB::table('calls')->where('seat_ref', $this->seatRef)
            ->where('call_id', $call)->value('opened_received_at'));
    }

    /**
     * § 10 E1 → E3 → E4 → E5: a dispatch opened before a newer intern call. `action` is the newer,
     * `longest_open_call` the dispatch, and both are null once everything closes.
     */
    public function test_a_dispatch_under_a_newer_intern_call_is_the_longest_and_the_intern_is_the_action(): void
    {
        $dispatch = $this->ulid();
        $intern = $this->ulid();

        // E1 — one call open: the two members name it alike.
        $mark = $this->wire->mark();
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 412]),
            $this->toolStart($dispatch, 'Agent'),
        ]);
        $this->fold();

        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertSame(1, $seat['open_calls'], "{$surface}: the control — one call is open");
            $this->assertSame($dispatch, $seat['action']['call_id'], "{$surface}: E1's action is the dispatch");
            $this->assertSame([
                'call_id' => $dispatch,
                'tool_name' => 'Agent',
                'started_received_at' => $this->receivedAt($dispatch),
            ], $seat['longest_open_call'], "{$surface}: with one call open, longest_open_call is that call");

            // Beside `action` on the object, as § 8.2.2's worked snapshot places it.
            $keys = array_keys($seat);
            $this->assertSame('longest_open_call', $keys[array_search('action', $keys, true) + 1],
                "{$surface}: longest_open_call is not the member after action");
        }

        $carried = array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array('longest_open_call', $d['payload']['changed'], true),
        ));
        $this->assertNotEmpty($carried, 'no seat.delta carried longest_open_call opening');
        $this->assertSame($dispatch, end($carried)['payload']['patch']['longest_open_call']['call_id']);

        // E3 — the intern's call, RECEIVED LATER (`deliver()` moves the server clock past the
        // first batch), replaces the dispatch as `action`; the dispatch stays the longest.
        $mark = $this->wire->mark();
        $this->deliver([$this->toolStart($intern, 'Bash', 'subagent', $dispatch)]);
        $this->fold();

        $this->assertNotSame($this->receivedAt($dispatch), $this->receivedAt($intern),
            'the control: the two calls were received at one instant, so the ordering cannot discriminate');

        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertSame(2, $seat['open_calls'], "{$surface}: the control — two calls are open");
            $this->assertSame($intern, $seat['action']['call_id'], "{$surface}: E3's action is the newer intern call");
            $this->assertSame([
                'call_id' => $dispatch,
                'tool_name' => 'Agent',
                'started_received_at' => $this->receivedAt($dispatch),
            ], $seat['longest_open_call'], "{$surface}: E3's longest_open_call is the older dispatch");
        }

        $deltas = $this->wire->ofTypeFrom('seat.delta', $mark);
        $this->assertNotEmpty($deltas, 'E3 emitted no seat.delta');
        foreach ($deltas as $d) {
            $this->assertNotContains('longest_open_call', $d['payload']['changed'],
                'E3 did not move the oldest open call, and a delta said it did');
        }

        // E4 — the intern's call closes: the dispatch is the one open call, and both name it.
        $this->deliver([$this->toolEnd($intern, 'Bash')]);
        $this->fold();

        $seat = $this->served();
        $this->assertSame($dispatch, $seat['action']['call_id']);
        $this->assertSame($dispatch, $seat['longest_open_call']['call_id']);

        // E5 — nothing open: both null.
        $mark = $this->wire->mark();
        $this->deliver([$this->toolEnd($dispatch, 'Agent')]);
        $this->fold();

        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertSame(0, $seat['open_calls'], "{$surface}: the control — nothing is open");
            $this->assertArrayHasKey('longest_open_call', $seat, "{$surface}: the member is on every seat object");
            $this->assertNull($seat['action'], "{$surface}: E5's action is null");
            $this->assertNull($seat['longest_open_call'], "{$surface}: E5's longest_open_call is null");
        }

        $closed = array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array('longest_open_call', $d['payload']['changed'], true),
        ));
        $this->assertNotEmpty($closed, 'no seat.delta carried longest_open_call clearing');
        $this->assertNull(end($closed)['payload']['patch']['longest_open_call']);
    }

    /**
     * The key is the SERVER receipt, not the seat clock — the age is computed from the receipt
     * (§ 3.3), so the call named must be the one whose `started_received_at` is earliest. The seat
     * clock here runs BACKWARDS between the two batches, so an ordering on `opened_at` names the
     * other call and reds.
     */
    public function test_the_oldest_is_ordered_on_the_server_receipt_not_the_seat_clock(): void
    {
        $first = $this->ulid();
        $second = $this->ulid();
        $seatClock = $this->clockMs;

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 12]),
            $this->toolStart($first, 'Bash', seatClockMs: $seatClock + 60_000),
        ]);
        $this->deliver([$this->toolStart($second, 'Read', seatClockMs: $seatClock + 5_000)]);
        $this->fold();

        $seat = $this->served();
        $this->assertSame(2, $seat['open_calls'], 'the control: two calls are open');
        $this->assertSame($first, $seat['action']['call_id'],
            'the control: action is the newest on the SEAT clock, which is the first-received call here');
        $this->assertSame($first, $seat['longest_open_call']['call_id'],
            'longest_open_call is not the call RECEIVED first — it was ordered on the seat clock');
        $this->assertSame($this->receivedAt($first), $seat['longest_open_call']['started_received_at']);
    }

    /**
     * One key and one tie-break (§ 8.2.1): two calls in one batch share a receipt instant, and the
     * lower `id` — the call the fold inserted first — is the oldest.
     */
    public function test_two_calls_received_in_one_instant_break_the_tie_on_the_lower_id(): void
    {
        $first = $this->ulid();
        $second = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 12]),
            $this->toolStart($first, 'Agent'),
            $this->toolStart($second, 'Bash'),
        ]);
        $this->fold();

        $this->assertSame($this->receivedAt($first), $this->receivedAt($second),
            'the control: the two calls were not received at one instant, so no tie is broken');

        $seat = $this->served();
        $this->assertSame($second, $seat['action']['call_id'], 'the control: action is the later-opened call');
        $this->assertSame($first, $seat['longest_open_call']['call_id'],
            'the tie did not break on the lower id');
    }

    /** § 6.6: a rebuild re-derives the member exactly, as it does `current_call_ref`. */
    public function test_a_rebuild_reproduces_the_longest_open_call(): void
    {
        $dispatch = $this->ulid();
        $intern = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 412]),
            $this->toolStart($dispatch, 'Agent'),
        ]);
        $this->deliver([$this->toolStart($intern, 'Bash', 'subagent', $dispatch)]);
        $this->fold();

        $folded = $this->served()['longest_open_call'];
        $this->assertSame($dispatch, $folded['call_id'] ?? null, 'the control: the dispatch is not the longest');

        $this->advanceServerClock(300);
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();

        $this->assertSame($folded, $this->served()['longest_open_call'], 'the rebuild named a different call');
    }
}
