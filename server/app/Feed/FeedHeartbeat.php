<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`feed.heartbeat`** — "every **15 s**, one row fleet-wide,
 * unconditionally".
 *
 * § 8.3 calls it "the property that makes the whole surface honest": without it a stream that has
 * silently died is indistinguishable from a fleet where nothing is happening.
 *
 * ⛔ UNCONDITIONALLY IS THE WHOLE POINT, so `mezzanine:feed-heartbeat` writes it whether or not
 * anything changed and whether or not any client is connected. A heartbeat suppressed when the
 * fleet is quiet stops exactly when the client most needs it.
 *
 * ONE ROW, FLEET-WIDE (card#9300): the stream is one per browser and carries every install, so the
 * heartbeat that used to go once per install channel is one `feed_outbox` row with no `install_id`.
 *
 * The client half is D3's and is stated here because it is the other end of this contract: "a client
 * that has seen no message of any kind for 45 s (3 intervals) treats the feed as dead".
 */
final class FeedHeartbeat implements FeedMessage
{
    use FeedEnvelope;

    /** § 8.3: every 15 s. § 12 traces it to D1 § 9.1's 60 s/300 s pair, scaled to a LAN channel. */
    public const INTERVAL_S = 15;

    /** § 8.3: "a client that has seen no message of any kind for 45 s (3 intervals)". */
    public const CLIENT_DEAD_AFTER_S = 45;

    /** @param  array<string, mixed>  $fleet  § 8.2.4's object, eight fields, no `counters` */
    public function __construct(public readonly array $fleet) {}

    public function type(): string
    {
        return 'feed.heartbeat';
    }

    public function installId(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        // § 8.2.4: `counters` is `GET /api/fleet/health`'s alone and "the snapshot and the feed
        // NEVER" carry it. `App\Read\FleetHealth::build()` defaults `withCounters` to false, so this
        // is enforced by the builder rather than trimmed here.
        return ['fleet' => $this->fleet];
    }
}
