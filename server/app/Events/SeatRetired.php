<?php

namespace App\Events;

use App\Feed\FeedEnvelope;
use App\Fold\Clock;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
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
 * ⚠ THE SEAM CARD #7712 LEFT HERE IS NOW CLOSED — card #7827, and this is what it did.
 *
 * #7712 wrote: "This event is the PUBLICATION POINT, not the publication … Part B is what makes
 * it reach a socket — by implementing `ShouldBroadcast` on it OR BY LISTENING FOR IT, whichever
 * § 8.3's envelope turns out to want." § 8.3's envelope wanted the first: the message's `t` IS
 * its broadcast name and its channel IS `private-fleet.{install_id}`, both of which
 * `App\Feed\FeedEnvelope` now supplies to all five § 8.3 message types from one place. A
 * listener would have put this one message's envelope somewhere the other four's is not.
 *
 * WHAT DID NOT CHANGE, and it is the half AT-D2-23's third RED turns on: this class is still the
 * ONLY producer of `seat.retired`. "Set `retired_at` / `retired_by` / `retired_reason` directly
 * and let the ordinary machinery run. NO `seat.retired` EVER REACHES A CONNECTED CLIENT — nothing
 * else in this document publishes it." Adding `ShouldBroadcastNow` gives the one producer a wire;
 * it does not give the columns-without-the-command path one.
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
final class SeatRetired implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use FeedEnvelope;

    public function __construct(
        public readonly int $seatRef,
        public readonly string $installId,
        public readonly string $seatId,
        public readonly string $retiredAt,
        public readonly string $retiredBy,
        public readonly string $retiredReason,
        public readonly int $stateVersion,
    ) {}

    public function type(): string
    {
        return 'seat.retired';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /**
     * § 8.3's declared payload for this row: `install_id`, `seat_id`, `reason`, `at`.
     *
     * `state_version` rides it too, for the reason stated above — the delta carrying
     * `render_state: "retired"` and this message are two announcements of one transaction, and a
     * consumer that can see they sit at the same version does not have to guess whether it has
     * both. § 8.1's REST rule ("additive changes are free") is not what licenses it; § 8.3's own
     * feed rule is weaker still — the two ends of this channel ship in one deploy.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'install_id' => $this->installId,
            'seat_id' => $this->seatId,
            'reason' => $this->retiredReason,
            'at' => Clock::wire($this->retiredAt),
            'state_version' => $this->stateVersion,
        ];
    }
}
