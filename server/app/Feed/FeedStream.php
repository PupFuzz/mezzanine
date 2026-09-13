<?php

namespace App\Feed;

use App\Fold\Clock;
use App\Ingest\Counters;
use App\Read\FleetHealth;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s stream handler — `GET /api/fleet/stream`, one FPM worker, one
 * browser, one generator handed to `ResponseFactory::eventStream()`. Nothing here is a daemon and
 * nothing survives the request. card#9300, D2 Appendix B step 9.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * `messages()` IS § 8.3's LOOP AS WRITTEN, line for line, and the placements that are load-bearing
 * are kept where the design put them:
 *
 *   · THE FIRST FRAME IS `fleet.health`, read before the outbox is opened, `db: "down"` when that read
 *     fails (§ 2.2's stream-connect row).
 *   · THE CURSOR STARTS AT THE HEAD BEHIND THE LAG (`Outbox::headBehindLag()`), never a bare
 *     `MAX(id)`, and nothing the client sends can set it lower — no `id:` is written and
 *     `Last-Event-ID` is never read (§ 8.5 refuses a replay buffer).
 *   · THE STALL CHECK MEASURES FROM THE PREVIOUS TICK'S START AND RUNS BEFORE THE READ. From the start,
 *     because the difference must span the writes the consumer blocked (measured from the end it is
 *     the sleep and never fires); before the read, because a stream blocked past § 6.7's 60 s outbox
 *     retention must END rather than advance its cursor over a row the purge took.
 *   · EVERY STORE READ IS INSIDE A `try`, and a failed one ends the stream with
 *     `feed.close{reason:"unavailable"}`: the primitive wraps the generator in `catch (Throwable)`,
 *     so an uncaught exception would end the response silently — the one ending § 8.3 refuses.
 *   · `fleet.reload` IS TERMINAL and is followed by `feed.close{reason:"reload"}`, so a deploy's end is
 *     a stated server decision rather than F3's unexplained silence (operator ruling A4).
 *   · THE SESSION RE-CHECK (§ 9) runs after the pass's writes, every 15 s, and continues the stream on
 *     exactly one outcome: valid.
 *
 * ⛔ EVERY FRAME IS `new StreamedEvent('mezzanine', $json)`, never a bare value: the primitive writes
 * `event: update` for anything else and the client's one `addEventListener("mezzanine", …)` would never
 * fire (§ 8.3). `$json` is the already-encoded string — an outbox row's `message` byte for byte.
 */
final class FeedStream
{
    /** § 8.3: `event:` carries this fixed literal on every message; `t` inside the envelope is what a client dispatches on. */
    public const EVENT = 'mezzanine';

    /** § 8.3's tick. */
    public const TICK_MS = 250;

    /** § 8.5: "the client's own dead-feed figure applied in the other direction". */
    public const STALL_BOUND_S = FeedHeartbeat::CLIENT_DEAD_AFTER_S;

    /** § 9's auth interval: the session is re-checked on the heartbeat's 15 s. */
    public const AUTH_INTERVAL_S = FeedHeartbeat::INTERVAL_S;

    public function __construct(
        private readonly SessionRecheck $session,
        private readonly StreamClock $clock,
    ) {}

    /** @return \Generator<int, StreamedEvent> */
    public function messages(): \Generator
    {
        set_time_limit(0);

        yield $this->frame(new FleetHealthMessage($this->connectHealth()));

        try {
            $cursor = Outbox::headBehindLag();
        } catch (\Throwable $e) {
            // cursor = 0 is REFUSED: it would replay the whole retention window.
            yield $this->closed('unavailable', $e);

            return;
        }

        $tickStarted = $authDone = $this->clock->nowMs();

        while (true) {
            $this->clock->sleepUntilMs($tickStarted + self::TICK_MS);

            if ($this->clock->nowMs() - $tickStarted > self::STALL_BOUND_S * 1000) {
                yield $this->close('stalled');
                $this->countResyncRequired();

                return;
            }

            $tickStarted = $this->clock->nowMs();   // stamped BEFORE the writes it is there to measure

            try {
                $rows = Outbox::after($cursor);
            } catch (\Throwable $e) {
                // No cursor is held, nothing is frozen, and there is no resume: the client re-opens
                // and re-snapshots (§ 2.2's mid-stream row).
                yield $this->closed('unavailable', $e);

                return;
            }

            foreach ($rows as $row) {
                if ($this->admits($row)) {
                    yield new StreamedEvent(self::EVENT, $row->message);
                }

                $cursor = (int) $row->id;

                if ($row->t === 'fleet.reload') {
                    yield $this->close('reload');

                    return;
                }
            }

            if ($this->clock->nowMs() - $authDone >= self::AUTH_INTERVAL_S * 1000) {
                try {
                    $valid = $this->session->stillValid();
                } catch (\Throwable $e) {
                    // The read did not answer ABOUT THE SESSION — an error, a lock, a permission
                    // refusal, a lost connection. `unavailable`, never `session` (§ 9).
                    yield $this->closed('unavailable', $e);

                    return;
                }

                if (! $valid) {
                    yield $this->close('session');

                    return;
                }

                $authDone = $this->clock->nowMs();
            }
        }
    }

    /** @return array<string, mixed> § 8.2.4's object, or its `db: "down"` form when the store cannot be read */
    private function connectHealth(): array
    {
        try {
            return FleetHealth::build(Clock::toMs(Clock::sql(now())));
        } catch (\Throwable $e) {
            Log::error('mezzanine.feed: the store could not be read on stream connect; fleet.health says db=down', [
                'error' => $e->getMessage(),
            ]);

            return FleetHealth::down();
        }
    }

    /**
     * § 9's per-subscriber filter. It admits everything today — fleet read is all-or-nothing, and
     * whether a per-install ACL is needed is § 14 item 7, an operator question. Every row carries its
     * `install_id` so that the rule has a key to attach to when it is ruled.
     */
    private function admits(object $row): bool
    {
        return true;
    }

    private function frame(FleetHealthMessage $message): StreamedEvent
    {
        return new StreamedEvent(self::EVENT, json_encode($message->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function close(string $reason): StreamedEvent
    {
        return new StreamedEvent(self::EVENT, json_encode((new FeedClose($reason))->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function closed(string $reason, \Throwable $cause): StreamedEvent
    {
        Log::warning('mezzanine.feed: a stream read of the store did not answer; ending it', [
            'reason' => $reason,
            'error' => $cause->getMessage(),
        ]);

        return $this->close($reason);
    }

    /** § 7.2: counted on the stall bound's branch and no other. A store that cannot take the count does not change the close. */
    private function countResyncRequired(): void
    {
        try {
            Counters::global('feed_resync_required');
        } catch (\Throwable $e) {
            Log::warning('mezzanine.feed: could not count feed_resync_required', ['error' => $e->getMessage()]);
        }
    }
}
