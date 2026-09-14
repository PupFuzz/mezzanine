<?php

namespace App\Feed;

use App\Fold\Clock;

/**
 * `docs/design/FLEET-STATE.md § 4.10` / § 8.3's **`seat.retired`** message, at the one moment that
 * produces it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS CLASS EXISTS AT ALL. § 4.10: "nothing in this document emitted `seat.retired`: § 8.3's
 * table said only WHEN the message is sent and named no process to send it, which is a wire message
 * a consumer is told to expect and no path produces." The retirement act is the producer § 4.10
 * names — `App\Fleet\SeatRetirement`, which both operator entry points go through (§ 2.1).
 *
 * ⭐ MOVED FROM `App\Events` ON card#9300. It was a Laravel event carrying `ShouldBroadcastNow` +
 * `ShouldDispatchAfterCommit`, the one feed message that lived outside `App\Feed`. Under § 8.3's SSE
 * transport it is an outbox row like the other eight, and it lives with them.
 *
 * WHAT DID NOT CHANGE, and it is the half AT-D2-23's fourth RED turns on: this class is still the ONLY
 * producer of `seat.retired`. "Set `retired_at` / `retired_by` / `retired_reason` directly and let
 * the ordinary machinery run. NO `seat.retired` EVER REACHES A CONNECTED CLIENT — nothing else in this
 * document publishes it."
 *
 * ⛔ "IN THE TRANSACTION THAT SETS THE COLUMNS", WITHOUT PUBLISHING A FACT THAT CAN BE ROLLED BACK.
 * The act enqueues this inside `App\Feed\Outbox::transaction()`, whose last statement inserts the row:
 * the message commits with the columns or not at all. A rolled-back retirement leaves no row, and a
 * committed one cannot lose its message to a crash between two acts.
 *
 * The `state_version` rides the message because § 8.5 makes it the feed's ordering key: the delta
 * carrying `render_state: "retired"` and this message are two announcements of one transaction.
 */
final class SeatRetired implements FeedMessage
{
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
     * § 8.3's declared payload for this row: `install_id`, `seat_id`, `reason`, `at` — and
     * `state_version`, for the reason stated above. § 8.3's feed rule licenses the addition: the two
     * ends of this surface ship in one deploy (§ 8.1).
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
