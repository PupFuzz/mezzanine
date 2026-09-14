<?php

namespace App\Feed;

/**
 * The stream handler's own clock — the 250 ms tick and § 8.5's stall bound are measured on it.
 *
 * An interface for ONE reason, and it is not convenience: the handler is a loop that only ends on
 * a store answer, a session answer, a `fleet.reload` or a stalled write, and a test that could not
 * step that loop could not drive a single one of AT-D2-15's, AT-D2-19's or AT-D2-25's legs through
 * the real handler. Production binds `App\Feed\MonotonicStreamClock`; the suite binds a scripted one
 * that advances the application clock and performs one scripted server act per tick.
 *
 * ⚠ It is NOT the clock the visibility lag is computed on: that term compares `feed_outbox.created_at`
 * with `now()` (`App\Feed\VisiblePrefix`), the application clock every writer stamps with.
 */
interface StreamClock
{
    /** Milliseconds on a clock that never goes backwards. */
    public function nowMs(): int;

    /** Return no earlier than `$ms` on this clock (immediately, if it has passed). */
    public function sleepUntilMs(int $ms): void;
}
