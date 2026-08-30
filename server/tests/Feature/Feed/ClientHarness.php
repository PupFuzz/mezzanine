<?php

namespace Tests\Feature\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.4`'s **client**, implemented exactly as § 8.4 and § 8.5 state
 * it — the "client harness" AT-D2-7 and AT-D2-8 both name in their BUILD.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT IS UNDER TEST WHEN A TEST USES THIS, AND WHAT IS NOT. STATED SO NEITHER IS OVERCLAIMED.
 *
 * The real client is D3's, in JavaScript, and does not exist. So a test built on this class is
 * NOT evidence that the shipped floor implements § 8.4. What it IS evidence of is the half that
 * belongs to this repository and that a JavaScript client cannot supply for itself:
 *
 *   • the snapshot carries a PER-SEAT `state_version` — § 8.4's watermark — so step 5 can be
 *     exact rather than approximate ("The watermark is per seat, not per fleet");
 *   • every delta carries a `state_version` EXACTLY ONE ABOVE its predecessor, which is the
 *     premise § 8.5's `== local + 1` rule and AT-D2-8's "jump by 2" both rest on;
 *   • the object a delta patches into is the SAME object the snapshot serves, field for field,
 *     so a client that applies every delta converges on the server rather than drifting.
 *
 * Those are server properties, and this class is the instrument that reads them. The REDs the
 * tests drive are mutations of THIS class's protocol (subscribe-after-snapshot, no watermark, no
 * version check) precisely because § 11 states them that way — they are the client mistakes the
 * server's contract has to make detectable.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 8.4's SIX STEPS, and each method below is one of them:
 *
 *   1. client connects, subscribes to private-fleet.<install>      → subscribe()
 *   2. client BUFFERS every seat.delta it receives from this moment → buffer()
 *   3. client GETs /api/fleet/snapshot                             → the caller's GET
 *   4. client applies the snapshot                                 → applySnapshot()
 *   5. client drains the buffer, DISCARDING any delta whose
 *      state_version <= that seat's snapshot version               → drain()
 *   6. steady state: apply deltas as they arrive                   → apply()
 */
final class ClientHarness
{
    /** @var array<string, array<string, mixed>> seat objects, keyed `install/seat` */
    public array $seats = [];

    /** @var list<array<string, mixed>> step 2's buffer */
    public array $buffer = [];

    /** @var list<string> every seat this client resynced, in order — AT-D2-8's observable */
    public array $resynced = [];

    public bool $subscribed = false;

    // ── the knobs the REDs turn, each one a client mistake § 11 names ────────────────────────

    /**
     * RED (AT-D2-7, second): "apply every buffered delta UNCONDITIONALLY".
     *
     * ⚠ A FINDING FELL OUT OF DRIVING THIS ONE, AND IT IS REPORTED RATHER THAN SMOOTHED OVER:
     * § 8.4 step 5's watermark discard ("DISCARDING any delta whose `state_version` <= that
     * seat's snapshot version") and § 8.5's own discard ("If it is less than or equal, the delta
     * is a duplicate or a straggler and is discarded") ARE THE SAME COMPARISON ON THE SAME TWO
     * NUMBERS — because after step 4 the seat's held version IS the snapshot's watermark. A client
     * that implements § 8.5 gets step 5 for free, so dropping step 5 ALONE is unobservable.
     *
     * That is why this flag makes the drain bypass BOTH: § 11's word is "unconditionally", and
     * the only client that is genuinely unconditional is the one with neither check. Card #7827's
     * PR body carries the observation; the two rules are redundant, not contradictory, so nothing
     * in D2 is wrong.
     */
    public bool $useWatermark = true;

    /** RED (AT-D2-8): ignore `state_version` and apply on arrival. */
    public bool $checkVersion = true;

    public function subscribe(): void
    {
        $this->subscribed = true;
    }

    /**
     * Step 2. A delta that arrives before `subscribe()` is NOT received at all — that is the
     * whole content of the subscribe-first rule, and it is why this method drops rather than
     * buffers when unsubscribed.
     *
     * @param  array<string, mixed>  $delta  a `seat.delta` payload
     */
    public function buffer(array $delta): void
    {
        if ($this->subscribed) {
            $this->buffer[] = $delta;
        }
    }

    /** Step 4. @param  array<string, mixed>  $body  a `GET /api/fleet/snapshot` response */
    public function applySnapshot(array $body): void
    {
        foreach ($body['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $this->seats[$this->key($seat['install_id'], $seat['seat_id'])] = $seat;
            }
        }
    }

    /**
     * Step 5 — "drains the buffer, DISCARDING any delta whose `state_version` <= that seat's
     * snapshot version, and applying the rest IN ORDER".
     *
     * @param  \Closure(string, string, int): array<string, mixed>|null  $resync
     */
    public function drain(?\Closure $resync = null): void
    {
        $buffered = $this->buffer;
        $this->buffer = [];

        foreach ($buffered as $delta) {
            if (! $this->useWatermark) {
                $this->merge($delta);   // § 11's "unconditionally" — see the flag's docblock

                continue;
            }

            $key = $this->key($delta['install_id'], $delta['seat_id']);
            $held = $this->seats[$key]['state_version'] ?? -1;

            if ($delta['state_version'] <= $held) {
                continue;   // already included in the snapshot
            }

            $this->apply($delta, $resync);
        }
    }

    /**
     * Step 6, and § 8.5's rule: "a client applies a delta iff `delta.state_version ==
     * local.state_version + 1`. If it is GREATER, deltas were lost: the client RE-SYNCS THAT ONE
     * SEAT … If it is LESS THAN OR EQUAL, the delta is a duplicate or a straggler and is
     * discarded."
     *
     * @param  array<string, mixed>  $delta
     * @param  \Closure(string, string, int): array<string, mixed>|null  $resync  the drill-down
     *                                                                            GET, with
     *                                                                            `?resync_from=`
     */
    public function apply(array $delta, ?\Closure $resync = null): void
    {
        $key = $this->key($delta['install_id'], $delta['seat_id']);
        $held = $this->seats[$key]['state_version'] ?? null;

        if ($this->checkVersion && $held !== null) {
            if ($delta['state_version'] <= $held) {
                return;
            }

            if ($delta['state_version'] > $held + 1) {
                // § 8.5: re-sync THAT ONE SEAT. The rest of the fleet is untouched, which is what
                // makes a gap cost one request rather than a whole snapshot.
                $this->resynced[] = $key;

                if ($resync !== null) {
                    $this->seats[$key] = ($resync)($delta['install_id'], $delta['seat_id'], $held);
                }

                return;
            }
        }

        $this->merge($delta);
    }

    /**
     * § 8.3.1's merge, and nothing else: "`patch` is a SHALLOW MERGE at the top level: a nested
     * object is replaced WHOLE, never deep-merged, because a deep merge makes 'this field became
     * null' and 'this field was not mentioned' the same wire shape."
     *
     * @param  array<string, mixed>  $delta
     */
    private function merge(array $delta): void
    {
        $key = $this->key($delta['install_id'], $delta['seat_id']);

        $this->seats[$key] = array_merge(
            $this->seats[$key] ?? [],
            (array) $delta['patch'],
            ['state_version' => $delta['state_version']],
        );
    }

    /** @return array<string, mixed>|null */
    public function seat(string $installId, string $seatId): ?array
    {
        return $this->seats[$this->key($installId, $seatId)] ?? null;
    }

    private function key(string $installId, string $seatId): string
    {
        return $installId.'/'.$seatId;
    }
}
