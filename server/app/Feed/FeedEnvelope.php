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
 * already carries `ShouldDispatchAfterCommit`, and is constructed by `mezzanine:retire` — it
 * needs the envelope without changing what it is.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ TRANSPORT — WHAT IS BUILT AND WHAT IS NOT, STATED SO NOTHING READS AS MORE THAN IT IS.
 *
 * § 8.3 pins the transport to **Laravel Reverb**. `laravel/reverb` IS NOT INSTALLED, and it is
 * not installable on this tree today: every published version (through v1.11.1) requires
 * `guzzlehttp/psr7 ^2.6`, and this application has `3.1.0` by way of `guzzlehttp/guzzle 8.1.0`.
 * `composer require laravel/reverb -W --dry-run` resolves only by DOWNGRADING guzzle 8.1.0 →
 * 7.15.5, promises 3.0.2 → 2.5.3 and psr7 3.1.0 → 2.13.1 — three downgrades of the framework's
 * own HTTP stack, which is a dependency decision this card does not take unilaterally.
 *
 * WHAT THAT COSTS AND WHAT IT DOES NOT. The message classes are ordinary Laravel broadcast
 * events: they name a channel, an event name and a payload, and the BROADCASTER is configuration
 * (`BROADCAST_CONNECTION`). Nothing in them is Reverb-specific and nothing changes when Reverb
 * lands. What is genuinely absent is the SOCKET — so § 11's AT-D2-15 (per-connection
 * backpressure, a property of the socket server's outbound queue and not of anything an
 * application publishes) has no surface to run against, and is REPORTED rather than approximated
 * with a mock that would only test itself.
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
