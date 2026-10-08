<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * card#9418 — § 8.2.1's `idle_since` and `idle_nudge_after_s`, driven through the real ingest, fold
 * and read plane, with the VALUES asserted.
 *
 * The contract was agreed with the consumer (the bridge's idle watchdog) on rt#478 ask 2 and rt#479
 * and is recorded on the card: `idle_since` is the SERVER-clock instant the fold entered `idle` — the
 * `received_at` of the event whose fold minted the edge — minted fresh on every entry and null on
 * every other state; `idle_nudge_after_s` is the install's configured horizon, and its KEY IS ABSENT
 * when nothing is configured, so a consumer can tell an undeclared horizon from a declared one.
 *
 * ⛔ WHAT THE DRIFT GUARD CANNOT SEE. `SeatObjectMatchesTheDocumentTest` asserts every § 8.2.1 NAME is
 * on the object, so an `idle_since` hard-wired to null — or computed off the seat clock — passes it.
 * These tests pin the value, its clock, its re-mint, and the key's absence.
 */
class IdleSinceAndHorizonTest extends FeedTestCase
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

    /** The server-clock receipt of the newest event of `$kind` on this seat, on the wire. */
    private function receivedAtOfLatest(string $kind): string
    {
        return Clock::wire(DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', $kind)->orderByDesc('id')->value('received_at'));
    }

    public function test_idle_since_is_the_server_receipt_of_the_event_that_minted_idle(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state, 'the control: the fixture did not go idle');

        $expected = $this->receivedAtOfLatest('turn.end');
        $turnEnd = DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', 'turn.end')->value('event_time');

        // THE CLOCK, discriminated: the fixture's seat clock is not the server clock, so a value
        // read off `event_time` (the basis the card's description first proposed, which § 3.3
        // forbids for an age) differs from the receipt and reds here.
        $this->assertNotSame(Clock::wire($turnEnd), $expected, 'the fixture cannot tell the two clocks apart');

        $this->assertSame($expected, $this->served()['idle_since'], 'seat REST: idle_since is the receipt instant');
        $this->assertSame($expected, $this->snapshotted()['idle_since'], 'snapshot: idle_since is the receipt instant');
    }

    public function test_idle_since_is_null_while_working_and_while_blocked(): void
    {
        // Idle FIRST, so the nulls below are an EXIT clearing a stored value — a seat that was
        // never idle would read null whether or not leaving `idle` clears it.
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->assertNotNull($this->served()['idle_since'], 'the control: the seat did not go idle first');

        $call = $this->ulid();
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 12]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => 'Bash: make',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
        ]);
        $this->fold();

        $this->assertSame('working', $this->state()->activity_state);
        $working = $this->served();
        $this->assertArrayHasKey('idle_since', $working, 'idle_since is a member of every seat object');
        $this->assertNull($working['idle_since'], 'idle_since is null unless activity_state is idle');

        $this->deliver([$this->event('attention.request', [
            'request_id' => $this->ulid(), 'source' => 'permission_request_hook',
            'notification_kind' => 'permission_required', 'call_id' => $call, 'open_calls' => 1,
        ])]);
        $this->fold();

        $this->assertSame('blocked', $this->state()->activity_state);
        $this->assertNull($this->served()['idle_since'], 'idle_since is null on a blocked seat');
    }

    public function test_idle_since_is_minted_fresh_on_every_entry_and_rides_the_delta(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $first = $this->served()['idle_since'];
        $this->assertNotNull($first);

        // A heartbeat is not an edge: an idle seat that keeps reporting keeps its idle_since.
        $this->advanceServerClock(60);
        $this->deliver($this->heartbeats(1));
        $this->fold();
        $this->assertSame($first, $this->served()['idle_since'], 'a heartbeat re-minted idle_since');

        // idle → working → idle: the consumer's re-arm key is (seat, idle_since), so the second
        // idle period must carry a different value — the receipt of the turn that ended it.
        $this->advanceServerClock(120);
        $mark = $this->wire->mark();
        $this->deliver($this->cleanTurn());
        $this->fold();

        $second = $this->served()['idle_since'];
        $this->assertSame($this->receivedAtOfLatest('turn.end'), $second);
        $this->assertNotSame($first, $second, 'a new idle period carried the old idle_since');

        // Version-bearing (§ 6.5's subtraction): the edge reaches a connected client on the delta.
        $carried = array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array('idle_since', $d['payload']['changed'], true),
        ));
        $this->assertNotEmpty($carried, 'no seat.delta carried the idle_since edge');
        $this->assertSame($second, end($carried)['payload']['patch']['idle_since']);
    }

    /**
     * § 6.6: a fold-minted `idle_since` is IN THE LOG, so a rebuild reproduces it byte for byte.
     *
     * `At10RebuildEqualsFoldTest`'s fixture ends `working`, where both sides hold null and the
     * column comparison cannot discriminate; this seat ends `idle`, and the rebuild runs at a LATER
     * wall clock, so a value stamped from `now()` rather than the event's receipt reds here.
     */
    public function test_a_rebuild_reproduces_a_fold_minted_idle_since(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $folded = $this->state()->idle_since;
        $this->assertNotNull($folded, 'the control: the seat did not go idle');

        $this->advanceServerClock(300);
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame($folded, $this->state()->idle_since, 'the rebuild re-dated the idle period');
    }

    public function test_the_horizon_key_is_absent_when_unconfigured(): void
    {
        config(['mezzanine.idle_nudge_after_s' => null]);

        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertArrayNotHasKey('idle_nudge_after_s', $this->served(),
            'seat REST: an undeclared horizon must be ABSENT, never a default');
        $this->assertArrayNotHasKey('idle_nudge_after_s', $this->snapshotted(),
            'snapshot: an undeclared horizon must be ABSENT, never a default');
    }

    public function test_the_configured_horizon_rides_every_seat_object_beside_idle_since(): void
    {
        config(['mezzanine.idle_nudge_after_s' => 900]);

        $this->deliver($this->cleanTurn());
        $this->fold();

        foreach (['seat REST' => $this->served(), 'snapshot' => $this->snapshotted()] as $surface => $seat) {
            $this->assertArrayHasKey('idle_nudge_after_s', $seat, "{$surface}: a configured horizon is missing");
            $this->assertSame(900, $seat['idle_nudge_after_s'], "{$surface}: the resolved horizon, as an int");

            // Siblings of `blocked_since` on the seat object (rt#479).
            $keys = array_keys($seat);
            $at = array_search('blocked_since', $keys, true);
            $this->assertSame(['idle_since', 'idle_nudge_after_s'], array_slice($keys, $at + 1, 2),
                "{$surface}: the two members are not beside blocked_since");
        }
    }
}
