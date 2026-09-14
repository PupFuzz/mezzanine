<?php

namespace Tests\Feature\Fold;

use App\Fold\WindowClock;

/**
 * A `WindowClock` that advances `$stepMs` on every read — card#9464's tests put `Fold::window()`'s budget
 * check where they want it without sleeping.
 */
final class SteppingClock extends WindowClock
{
    private int $now = 0;

    public function __construct(private readonly int $stepMs) {}

    public function nowMs(): int
    {
        $now = $this->now;
        $this->now += $this->stepMs;

        return $now;
    }
}
