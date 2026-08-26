<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * `docs/design/FLEET-STATE.md § 4.10` / § 8.3's **`seat.retired`** message, at the one moment that
 * produces it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS CLASS EXISTS AT ALL. § 4.10: "nothing in this document emitted `seat.retired`: § 8.3's
 * table said only WHEN the message is sent and named no process to send it, which is a wire message
 * a consumer is told to expect and no path produces." `mezzanine:retire` is the producer § 4.10
 * names, and this is the act of producing it.
 *
 * ⚠ SEAM — DELIBERATELY NOT `ShouldBroadcast`, AND THE OMISSION IS THE CONTRACT.
 *
 * § 8.3 (the WebSocket delta feed: its transport, its channel names, its message envelope, its
 * backpressure bounds) is card #7339 PART B's and is not built. This event is the PUBLICATION
 * POINT, not the publication: it says *this seat was retired, by this operator, for this reason, at
 * this `state_version`*, and Part B is what makes it reach a socket — by implementing
 * `ShouldBroadcast` on it or by listening for it, whichever § 8.3's envelope turns out to want.
 *
 * Building the broadcast here would have meant inventing a channel name and a payload shape for a
 * contract another card owns, which is how two documents start disagreeing about which one is the
 * contract (§ 1.3). Building NOTHING would have left § 4.10's producer missing again, and
 * AT-D2-23's third RED — "assert the ABSENCE of the message" when the columns are set directly —
 * with nothing to assert against.
 *
 * The `state_version` rides the event because § 8.5 makes it the feed's ordering key: the delta
 * carrying `render_state: "retired"` and this message are two announcements of one transaction, and
 * a consumer that can see they sit at the same version does not have to guess whether it has both.
 */
final class SeatRetired
{
    use Dispatchable;

    public function __construct(
        public readonly int $seatRef,
        public readonly string $installId,
        public readonly string $seatId,
        public readonly string $retiredAt,
        public readonly string $retiredBy,
        public readonly string $retiredReason,
        public readonly int $stateVersion,
    ) {}
}
