<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Carbon;

/**
 * Pin the clock for a test class that drives a `App\Support\FixedWindow` limit to its ceiling.
 *
 * WHY THIS EXISTS — card#9223. `FixedWindow::hit()` indexes its counter on
 * `intdiv(now()->getTimestamp(), $windowS)`: an ABSOLUTE wall-clock window, not one that begins at
 * the first request. So a test that sends `$limit` requests and then asserts the next one is
 * refused is asserting something that only holds if the whole loop lands in ONE window — and
 * nothing made that so. A loop that happens to straddle a real minute boundary puts its last
 * request in a fresh window, where `202` is CORRECT limiter behaviour and the TEST is the thing
 * that is wrong.
 *
 * MEASURED before this trait: the ingest suite's 120-request loop was red 1 full-suite run in 3
 * (`Expected 429 but received 202`) and green 3 of 3 in isolation. That gap is not noise — it is
 * the loop's DURATION, which is the straddle probability, and it is why the flake only appeared
 * once `php-tests` became a CI lane (card#7344) and started running the suite under contention.
 *
 * ⛔ THIS DOES NOT WEAKEN AN ASSERTION. Every assertion is untouched. What was missing is the
 * PRECONDITION each of these tests already names in its own title — "in a minute", "an hour". The
 * pin establishes it. Widening an assertion to accept `202`-or-`429` would have deleted the test;
 * this makes the test able to mean what it says.
 *
 * The limiter's boundary behaviour is a SEPARATE property and keeps its own test
 * (`test_the_request_limit_releases_after_its_window`), which travels this pinned clock forward
 * deliberately instead of waiting for the real one to drift across a boundary by luck.
 *
 * ⚠ WHO SHOULD USE THIS — the population, and how it was derived. Every test file asserting a
 * `429` (`grep -rln 'assertStatus(429)' tests/`), minus those whose limiter is not `FixedWindow`:
 *
 *   `IngestRateLimitTest`  — IN. Four loops against `FixedWindow`, and nothing under
 *                            `IngestTestCase` pins. This is where the flake was measured.
 *   `At19ReadAuthTest`     — IN, but ALREADY PROTECTED: it inherits `FoldTestCase`'s pin through
 *                            `FeedTestCase -> SweepTestCase`, set there for an unrelated stated
 *                            reason (driving § 4.5's age thresholds). Its 600-iteration loop
 *                            against a 60 s window is ~5x this one's exposure and is safe only for
 *                            that reason, so the trait is applied for its GUARD: if the Fold pin
 *                            is ever moved or removed, this reds at once instead of that class
 *                            beginning to flake at five times the ingest rate. The trait's own
 *                            `setUp` runs BEFORE `FoldTestCase::setUp()` (Laravel calls
 *                            `setUpTraits()` from the base `setUp()`), so Fold's deliberate
 *                            `2026-08-26 12:00:00` instant still wins — asserted, not assumed.
 *   `TwoFactorResetTest`   — OUT. Its limiter is Laravel's own `Illuminate\Cache\RateLimiter`,
 *                            which decays from the FIRST hit rather than from an absolute
 *                            boundary, so it cannot straddle. It also calls `travelBack()`, which
 *                            would tear this pin down mid-test.
 */
trait PinsTheRateLimitWindow
{
    /**
     * Auto-invoked by Laravel's `setUpTraits()` (`setUp`.class_basename($trait)) during `setUp()`,
     * so a using class needs no `setUp()` of its own and an existing one needs no edit.
     */
    protected function setUpPinsTheRateLimitWindow(): void
    {
        $this->travelTo(now());
    }

    /**
     * The pin is invisible at the call site — every limit test reads exactly as it did before —
     * so removing it would break nothing until CI went red one run in three again, at a distance
     * from the change. This is the check that reds immediately instead.
     */
    public function test_this_class_pins_the_rate_limit_window(): void
    {
        $this->assertTrue(
            Carbon::hasTestNow(),
            'This class drives a FixedWindow limit to its ceiling, so its clock must be pinned or '.
            'the loop can straddle a window boundary and the limit will legitimately not fire '.
            '(card#9223). PinsTheRateLimitWindow owns the pin.',
        );
    }
}
