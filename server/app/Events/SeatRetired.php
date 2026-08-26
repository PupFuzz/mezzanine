<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
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
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `ShouldDispatchAfterCommit` IS WHAT SATISFIES § 4.10's "IN THE TRANSACTION THAT SETS THE
 * COLUMNS" WITHOUT PUBLISHING A FACT THAT CAN BE ROLLED BACK.
 *
 * The command dispatches this INSIDE the retirement transaction, so the publish is ordered by the
 * same act that sets `retired_at` and there is no window in which one landed and the other did not.
 * The contract then defers the actual delivery until that transaction COMMITS. Both failure modes
 * the two readings worry about are closed by the one mechanism:
 *
 *   publish inside a transaction that rolls back → a client told a seat retired when it did not,
 *   and no way to recall it. The deferral means the listeners are never invoked.
 *
 *   publish after the commit, from outside → the publish is a separate act that a crash between
 *   the two can lose. Here it is registered by the transaction itself.
 *
 * A send that fails AFTER the commit is still possible and is still correct: that client learns the
 * same fact from its next snapshot, which § 8.4's snapshot-then-deltas protocol already makes
 * right. What § 4.10 is protecting — a row never vanishing between two refreshes — is bought by the
 * COMMIT, and the delta rides `state_version`, which the same transaction bumped.
 */
final class SeatRetired implements ShouldDispatchAfterCommit
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
