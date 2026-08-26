<?php

namespace App\Feed;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`feed.heartbeat`** — "every **15 s**, per channel,
 * **unconditionally**".
 *
 * § 8.3 calls it "the property that makes the whole surface honest": "Without it, a socket that
 * has silently died is indistinguishable from a fleet where nothing is happening — which is
 * exactly the failure this product exists to remove, one layer further out than D1 solved it."
 *
 * ⛔ UNCONDITIONALLY IS THE WHOLE POINT, so `mezzanine:feed-heartbeat` sends it whether or not
 * anything changed and whether or not any client is connected. A heartbeat suppressed when the
 * fleet is quiet is a heartbeat that stops exactly when the client most needs it — a quiet fleet
 * and a dead socket would once again look the same, which is the state this message exists to
 * separate.
 *
 * The client half is D3's and is stated here because it is the other end of this contract:
 * "a client that has seen no message of any kind for 45 s (3 intervals) treats the feed as dead",
 * renders the `feed_down` indicator § 2.2 requires, and reconnects.
 */
final class FeedHeartbeat implements ShouldBroadcastNow
{
    use Dispatchable;
    use FeedEnvelope;

    /** § 8.3: every 15 s. § 12 traces it to D1 § 9.1's 60 s/300 s pair, scaled to a LAN channel. */
    public const INTERVAL_S = 15;

    /** § 8.3: "a client that has seen no message of any kind for 45 s (3 intervals)". */
    public const CLIENT_DEAD_AFTER_S = 45;

    /** @param  array<string, mixed>  $fleet  § 8.2.4's object, eight fields, no `counters` */
    public function __construct(
        private readonly string $installId,
        public readonly array $fleet,
    ) {}

    public function type(): string
    {
        return 'feed.heartbeat';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        // § 8.2.4: `counters` is `GET /api/fleet/health`'s alone and "the snapshot and the feed
        // NEVER" carry it — nine monotonic integers on a 15 s path "would be permanent bytes
        // carrying, almost always, no news". `App\Read\FleetHealth::build()` defaults
        // `withCounters` to false, so this is enforced by the builder rather than trimmed here.
        return ['fleet' => $this->fleet];
    }
}
