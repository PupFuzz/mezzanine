<?php

namespace App\Feed;

/**
 * Production's `StreamClock`: `hrtime()`, which does not move when the wall clock is stepped — a
 * stall bound measured on a wall clock would fire, or fail to, on an NTP correction (§ 2.2's clock
 * row names that skew as a thing that happens).
 */
final class MonotonicStreamClock implements StreamClock
{
    public function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    public function sleepUntilMs(int $ms): void
    {
        $wait = $ms - $this->nowMs();

        if ($wait > 0) {
            usleep($wait * 1000);
        }
    }
}
