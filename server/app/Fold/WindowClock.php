<?php

namespace App\Fold;

/**
 * The monotonic clock `Fold::window()` measures its `seat_state` hold against `Fold::WINDOW_BUDGET_MS`
 * with (card#9464).
 *
 * `hrtime()`, never `now()`: the suite freezes and travels the application clock (`Carbon::setTestNow()`,
 * `travel()`), and a window budget read from that clock would never elapse under a frozen one. A
 * collaborator rather than a direct `hrtime()` call so a test can drive the budget without sleeping;
 * not `final`, so that test can subclass it.
 */
class WindowClock
{
    /** Milliseconds on a monotonic clock with an arbitrary origin — only differences mean anything. */
    public function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
