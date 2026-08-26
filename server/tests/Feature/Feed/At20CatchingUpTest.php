<?php

namespace Tests\Feature\Feed;

use App\Fold\Derivation;

/**
 * **AT-D2-20 — catching up is not current, and not stale** (`docs/design/FLEET-STATE.md § 11`).
 *
 * § 11's BUILD: "a seat whose heartbeat carries `oldest_unsent_age_s > 300` while batches keep
 * arriving (D1's post-outage drain)."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS TEST IS ON THE WIRE SURFACE AND NOT ONLY ON `seat_state`.
 *
 * § 11's RED is "ignore `oldest_unsent_age_s` → THE FLOOR ANIMATES HOURS-OLD WORK AS IF IT WERE
 * HAPPENING NOW, with no indication anywhere that the desk is replaying history." The floor reads
 * the SNAPSHOT, so the thing that has to be true is a property of the published object: that it
 * says `catching_up`, that the underlying activity state still rides it, and that
 * `activity.last_event_time` is visibly hours behind `server_time`. A store-side assertion alone
 * would leave the one field that carries the warning — `delivery.oldest_unsent_age_s` — unread by
 * anything.
 *
 * § 4.2 is why the render collapses this way: "`catching_up` outranks the activity state because a
 * draining seat's activity facts are hours old … The activity state STILL RIDES THE OBJECT as
 * `activity_state`, with `activity.last_event_time` saying how old it is."
 */
class At20CatchingUpTest extends FeedTestCase
{
    /** D1's post-outage drain: hours of spooled work arriving now. */
    private const DRAIN_AGE_S = 4 * 3600;

    public function test_a_draining_seat_renders_catching_up_and_says_how_old_its_work_is(): void
    {
        // Hours-old ACTIVITY on the seat clock, delivered NOW on the server clock — which is
        // exactly what a drain is: fresh receipts carrying stale content (D1 § 9.1, "`received_at`
        // is fresh while the *content* is hours old").
        $staleSeatClock = $this->clockMs - self::DRAIN_AGE_S * 1000;

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 12], null, $staleSeatClock),
            $this->event('tool.start', [
                'call_id' => $this->ulid(), 'tool_name' => 'Bash', 'descriptor' => 'Bash: make',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ], null, $staleSeatClock + 1000),
            $this->drainingHeartbeat(self::DRAIN_AGE_S),
        ]);
        $this->fold();

        $object = $this->snapshotSeat();

        // GREEN, the three state members.
        $this->assertSame('catching_up', $object['link_state']);
        $this->assertSame('catching_up', $object['render_state']);

        // "the underlying `activity_state` still rides the object" — § 4.2's collapse hides it
        // from the RENDER, not from the object.
        $this->assertSame('working', $object['activity_state'],
            'the activity axis stopped riding the object under a catching_up render');

        // "and `activity.last_event_time` is visibly HOURS BEHIND `server_time`." Both operands
        // come off the RESPONSE, so this is the arithmetic a floor would do, not the suite's.
        $behindMs = $this->wireToMs($object['server_time'])
            - $this->wireToMs($object['activity']['last_event_time']);

        $this->assertGreaterThanOrEqual(
            3 * 3600 * 1000,
            $behindMs,
            'the object does not show that the desk is replaying history',
        );

        // …while the RECEIPT is fresh. That pair — stale content, fresh receipt — is the whole of
        // § 3's "delivery is not activity" arriving on this surface, and it is what makes the
        // `catching_up` render necessary rather than merely tidy.
        $this->assertLessThan(
            60_000,
            $this->wireToMs($object['server_time']) - $this->wireToMs($object['delivery']['last_receipt_at']),
            'the fixture did not actually produce a fresh receipt over stale content',
        );

        // The instrument that produced the state is on the object, so a reader can check the
        // claim rather than take it (§ 8.2.1's `delivery.oldest_unsent_age_s`, "> 300 ⇒
        // `catching_up`").
        $this->assertSame(self::DRAIN_AGE_S, $object['delivery']['oldest_unsent_age_s']);
        $this->assertGreaterThan(Derivation::CATCHING_UP_UNSENT_AGE_S, $object['delivery']['oldest_unsent_age_s']);
    }

    /**
     * GREEN — "the seat is **never** `stale` (because `received_at` keeps moving)".
     *
     * The whole point of the state: a draining seat is NOT a silent one, and the two must not
     * collapse. Driven over four minutes of continuous drain — past § 4.5's 300 s `stale`
     * threshold, which is where a receipt-blind implementation would flip.
     */
    public function test_a_draining_seat_is_never_stale_however_long_the_drain_runs(): void
    {
        $seen = [];

        for ($minute = 0; $minute < 8; $minute++) {
            $this->deliver([$this->drainingHeartbeat(self::DRAIN_AGE_S + $minute * 60)]);
            $this->fold();
            $this->sweep();

            $seen[] = $this->state()->link_state;
            $this->advanceServerClock(60);
        }

        $this->assertNotContains('stale', $seen, 'a seat that kept receiving was called silent');
        $this->assertNotContains('offline', $seen);
        $this->assertSame(['catching_up'], array_values(array_unique($seen)));
    }

    /**
     * DISCRIMINATING CONTROL — "the same seat AFTER the drain completes (`oldest_unsent_age_s`
     * null) → `live`, SO THE STATE IS KNOWN TO BE LEAVEABLE."
     *
     * Without it a seat pinned to `catching_up` forever would pass the GREEN above.
     */
    public function test_the_state_is_leaveable_when_the_drain_completes(): void
    {
        $this->deliver([$this->drainingHeartbeat(self::DRAIN_AGE_S)]);
        $this->fold();
        $this->assertSame('catching_up', $this->snapshotSeat()['render_state']);

        $this->deliver([$this->drainingHeartbeat(null)]);
        $this->fold();

        $object = $this->snapshotSeat();
        $this->assertSame('live', $object['link_state']);
        $this->assertNull($object['delivery']['oldest_unsent_age_s']);
    }

    /**
     * The BOUNDARY, both sides, because § 8.2.1 states it as a strict inequality: "> 300 ⇒
     * `catching_up`". A test that only drove 4 hours would pass against `>= 0`.
     */
    public function test_the_threshold_is_strictly_above_300_seconds(): void
    {
        $this->deliver([$this->drainingHeartbeat(Derivation::CATCHING_UP_UNSENT_AGE_S)]);
        $this->fold();
        $this->assertSame('live', $this->snapshotSeat()['link_state'], '300 s exactly is not yet catching_up');

        $this->deliver([$this->drainingHeartbeat(Derivation::CATCHING_UP_UNSENT_AGE_S + 1)]);
        $this->fold();
        $this->assertSame('catching_up', $this->snapshotSeat()['link_state']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** D1 § 6.14's heartbeat, carrying the one member this test is about. */
    private function drainingHeartbeat(?int $oldestUnsentAgeS): array
    {
        return $this->event('reporter.heartbeat', [
            'uptime_s' => 90_000, 'spool_bytes' => 4096, 'spool_files' => 3,
            'spool_lag_events' => $oldestUnsentAgeS === null ? 0 : 812,
            'oldest_unsent_age_s' => $oldestUnsentAgeS,
            'last_hook_at' => null, 'open_calls' => 0, 'open_sessions' => 1,
            'open_attention' => 0, 'enabled' => true, 'degraded' => [],
            'counters' => ['batches_sent' => 3], 'counters_omitted' => 0, 'predicates' => [],
            'selftest' => ['spool_writable' => 'pass'], 'config_fingerprint' => '9f2c41a7be03d518',
        ]);
    }

    /** @return array<string, mixed> the seat object as the FLOOR receives it, through the snapshot */
    private function snapshotSeat(): array
    {
        $body = $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();

        return $body['installs'][0]['seats'][0] + ['server_time' => $body['server_time']];
    }

    private function wireToMs(string $wire): int
    {
        return (int) round((float) \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z', $wire, new \DateTimeZone('UTC')
        )->format('U.u') * 1000);
    }
}
