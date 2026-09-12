<?php

namespace App\Feed;

use App\Fold\Clock;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **envelope** and **channel**, in one place — the two
 * properties every one of the five message types shares.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 8.3, verbatim: "**Envelope** — every message: `{"feed_version":1,"t":…,"server_time":"…",
 * …}`." and "**Channel: `private-fleet.{install_id}`** — one per install, so a floor subscribes
 * to what it renders and a future per-install authorization has a channel to hang on."
 *
 * ⚠ `PrivateChannel('fleet.'.$install)` is what puts `private-` on the wire. Laravel's private
 * channels prefix the name themselves (`Illuminate\Broadcasting\PrivateChannel::__construct`), so
 * spelling `private-fleet.…` here would produce `private-private-fleet.…` — the one place an
 * implementer reading § 8.3's channel name literally gets it wrong. It is written once, here, and
 * `routes/channels.php` authorises the same unprefixed name for the same reason.
 *
 * A TRAIT rather than a base class, because `App\Events\SeatRetired` already exists (card #7712),
 * already carries `ShouldDispatchAfterCommit`, and is constructed by the retirement act
 * (`App\Fleet\SeatRetirement`) — it needs the envelope without changing what it is.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ TRANSPORT — WHAT IS BUILT AND WHAT IS NOT, STATED SO NOTHING READS AS MORE THAN IT IS.
 *
 * ⭐ § 8.3 pins the transport to NATIVE SERVER-SENT EVENTS since card#9287 (2026-09-12): one
 * fleet-wide `GET /api/fleet/stream` served by PHP-FPM, fed by a `feed_outbox` table every writer
 * inserts into in its own transaction. It USED to pin Laravel Reverb, and this trait's shape is
 * that earlier pin's: `laravel/reverb` was never installable on this tree (every version through
 * v1.11.1 requires `guzzlehttp/psr7 ^2.6` against this application's 3.1.0), and the ruling
 * removed the daemon rather than downgrade the framework's HTTP stack to admit it.
 *
 * WHAT SURVIVES OF THIS TRAIT UNDER THAT RULING, AND WHAT DOES NOT. `broadcastWith()` IS the
 * § 8.3 envelope, and under the amendment it is what a writer serializes ONCE into
 * `feed_outbox.message`; `type()` is the row's `t`. `broadcastOn()` and `broadcastAs()` are the
 * retired transport's channel and event name — there is no channel and no `/broadcasting/auth`
 * under SSE (the `event:` field is `t`, written from the same value) — and they, the
 * `ShouldBroadcastNow` markers on the message classes and `routes/channels.php` are retired at
 * D2 Appendix B step 9, which builds the outbox writer and the stream handler. Until that step
 * lands this is the pre-amendment wiring, kept because it compiles and publishes to the `log`
 * broadcaster, and it must not be read as the design. AT-D2-15 now has a surface to run against
 * — the handler's stall bound — and is built at the same step.
 */
trait FeedEnvelope
{
    /** § 8.1: the feed "carries `feed_version` for detection". */
    public const FEED_VERSION = 1;

    /** § 8.3's `t` — the message type. */
    abstract public function type(): string;

    /** @return array<string, mixed>  the members BEYOND the envelope */
    abstract public function body(): array;

    abstract public function installId(): string;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('fleet.'.$this->installId());
    }

    /**
     * The event name a client subscribes to IS `t`, so the two cannot drift: a consumer bound to
     * `seat.delta` and a server broadcasting `App\Feed\SeatDelta` is the failure this collapses.
     */
    public function broadcastAs(): string
    {
        return $this->type();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'feed_version' => self::FEED_VERSION,
            't' => $this->type(),
            'server_time' => Clock::wire(Clock::sql(now())),
        ] + $this->body();
    }
}
