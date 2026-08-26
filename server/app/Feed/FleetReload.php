<?php

namespace App\Feed;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`fleet.reload`** — "`feed_version` changed under a
 * running client (a deploy)".
 *
 * ⛔ THIS MESSAGE IS THE WHOLE OF § 8.1's SECOND ROW, AND THAT ROW IS WHY THE FEED CARRIES NO
 * SUPPORT WINDOW.
 *
 * § 8.1: the WebSocket surface "carries `feed_version` for detection, but **no support window and
 * no N/N-1 obligation**: there is never a client in the wild older than the server. A client that
 * sees an unknown `feed_version` STOPS APPLYING DELTAS and tells the user to reload — it does not
 * attempt a compatibility dance it cannot win."
 *
 * So this is the server's half of that: the deploy that moves `FEED_VERSION` tells every client
 * still holding the old one, rather than letting it discover the mismatch on the next delta it
 * cannot parse. Without it the asymmetry § 8.1 argues for would cost exactly what a support
 * window costs — a client silently wrong until something else moves.
 *
 * ⚠ NOTHING CALLS THIS YET, AND THAT IS STATED RATHER THAN HIDDEN. Its producer is a DEPLOY step
 * (`bin/deploy.sh`, `docs/PLAN.md § 5`) which does not exist, and its trigger is a comparison
 * against the `feed_version` a running client last saw, which needs the socket server this card
 * does not install. It is built because § 8.3 declares the message and a message a consumer is
 * told to expect with no class to produce it is exactly the defect § 4.10 caught for
 * `seat.retired`; it is NOT claimed as wired.
 */
final class FleetReload implements ShouldBroadcastNow
{
    use Dispatchable;
    use FeedEnvelope;

    public function __construct(
        private readonly string $installId,
        public readonly string $reason,
    ) {}

    public function type(): string
    {
        return 'fleet.reload';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        // `feed_version` appears TWICE by § 8.3's own construction — once in the envelope every
        // message carries, and once as this message's declared payload member. They are the same
        // value read from the same constant, so they cannot disagree; both are kept because § 8.3
        // lists `feed_version` in this row's Payload column and a consumer reading that table
        // would look for it there.
        return ['feed_version' => self::FEED_VERSION, 'reason' => $this->reason];
    }
}
