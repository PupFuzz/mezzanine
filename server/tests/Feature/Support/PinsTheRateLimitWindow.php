<?php

namespace Tests\Feature\Support;

/**
 * Pin the clock for a test class that drives an `App\Support\FixedWindow` limit to its ceiling.
 *
 * WHY THIS EXISTS — card#9223. `FixedWindow::hit()` indexes its counter on
 * `intdiv(now()->getTimestamp(), $windowS)`: an ABSOLUTE wall-clock window, not one that begins at
 * the first request. So a test that sends `$limit` requests and then asserts the next one is
 * refused is asserting something that only holds if the whole loop lands in ONE window — and
 * nothing made that so. A loop that happens to straddle a real minute boundary puts its last
 * request in a fresh window, where `202` is CORRECT limiter behaviour and the TEST is the thing
 * that is wrong.
 *
 * ⛔ THIS DOES NOT WEAKEN AN ASSERTION. Every assertion is untouched. What was missing is the
 * PRECONDITION each of these tests already names in its own title — "in a minute", "an hour". The
 * pin establishes it. Widening an assertion to accept `202`-or-`429` would have deleted the test;
 * this makes the test able to mean what it says.
 *
 * ── HOW OFTEN IT ACTUALLY FIRED, and a correction ──
 * ⚠ The first version of this docblock said the flake "reddened roughly one PR in three". THAT WAS
 * FALSE and it is recorded here rather than quietly dropped. It came from card#9223's local sample
 * of *three* full-suite runs, one of which was red — a 3-run sample restated as a CI rate. The
 * authoritative record refutes it: at the time of writing `gh run list --workflow php-tests.yml`
 * showed 33 runs, 32 success, and the single failure was a composer/PHP-version error on the lane's
 * own setup branch `ci/card-7344-php-tests`, not this test. **No CI run had ever failed on it.**
 *
 * The honest severity is a rate you can DERIVE rather than a number to trust: the exposure is the
 * loop's duration over the window length, so re-measure it with
 * `php vendor/bin/phpunit --filter IngestRateLimitTest --log-junit` and read the case time against
 * the 60 s window. It lands near 1 %, not 33 %. What justifies the fix is not the rate — it is that
 * the lane is a candidate to become a REQUIRED check, where any nonzero flake rate blocks merges
 * and the standing pressure becomes to disable the lane rather than fix the test.
 *
 * The limiter's boundary behaviour is a SEPARATE property and keeps its own test
 * (`test_the_request_limit_releases_after_its_window`), which travels this pinned clock forward
 * deliberately instead of waiting for the real one to drift across a boundary by luck.
 *
 * ── WHO USES THIS ──
 * The population is not listed here — a written list goes stale the first time someone adds a limit
 * test, and nothing would say so. `FixedWindowPinCoverageTest` RE-DERIVES it every run and reds on
 * a class that asserts a `429` without a pinned clock. Read that class for the derivation and for
 * the reasoned exclusions.
 *
 * ⚠ `At19ReadAuthTest` takes this trait even though it ALREADY has a pin, inherited from
 * `FoldTestCase` via `FeedTestCase -> SweepTestCase` and set there for an unrelated reason (driving
 * § 4.5's age thresholds). The reason is NOT that this trait guards that pin — it cannot, and an
 * earlier version of this file wrongly claimed it could. The reason is that its loop against a 60 s
 * window is the longest in the suite, and depending on a pin some other class sets for its own
 * purposes is a coupling nobody declared: with the trait, that class pins its own window and stops
 * caring what `FoldTestCase` does. `FoldTestCase`'s deliberate `2026-08-26 12:00:00` instant still
 * wins, because this trait's `setUp` runs BEFORE it — asserted with a probe, not assumed.
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
}
