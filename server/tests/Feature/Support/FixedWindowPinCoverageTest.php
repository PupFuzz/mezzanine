<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every test class that drives an `App\Support\FixedWindow` limit to its ceiling runs on a pinned
 * clock — card#9223.
 *
 * ⛔ WHY THIS LIVES OUTSIDE `PinsTheRateLimitWindow` AND NOT INSIDE IT. The first version of this
 * guard was a test method ON that trait, and it could not fail for either hazard it claimed to
 * catch: deleting `FoldTestCase`'s pin left it green (the trait's own pin supplied one), and
 * removing `use PinsTheRateLimitWindow` from a class deleted the guard along with the pin. A check
 * that travels with the thing it checks is a decoration. This one is a separate class, so removing
 * the trait from a covered class REDS IT.
 *
 * ⇒ THE POPULATION IS RE-DERIVED ON EVERY RUN, never written down as a list of class names. A
 * written list is an artifact the loop cites instead of a population the loop measures, and it goes
 * stale silently the first time someone adds a limit test. The derivation is the same one the fix
 * was scoped by: every test file asserting a `429`.
 */
class FixedWindowPinCoverageTest extends TestCase
{
    /**
     * Classes excluded from the requirement, each with the reason it is not in the population.
     * An entry here is a CLAIM that the class does not drive a `FixedWindow` limit — not a waiver.
     *
     * @var array<string, string>
     */
    private const NOT_FIXED_WINDOW = [
        'Tests\Feature\TwoFactorResetTest' => "limiter is Laravel's own Illuminate\\Cache\\RateLimiter, whose TTL starts at the FIRST ".
            'hit rather than at an absolute boundary, so its loop cannot straddle. It also calls '.
            'travelBack(), which would tear a pin down mid-test.',
        'Tests\Feature\TwoFactorResetSurfaceTest' => 'asserts throttle WIRING, not a limit driven to its ceiling — it runs no request loop.',
    ];

    public function test_every_class_asserting_a_429_runs_on_a_pinned_clock(): void
    {
        $population = $this->classesAssertingA429();

        $this->assertNotEmpty(
            $population,
            'The derivation found NO test asserting a 429. An empty population is a measurement '.
            'that never happened (canon #9) — the scan below is broken, not the suite.',
        );

        $unpinned = [];

        foreach ($population as $class => $file) {
            if (array_key_exists($class, self::NOT_FIXED_WINDOW)) {
                continue;
            }

            if (! $this->classPinsItsClock($class)) {
                $unpinned[$class] = $file;
            }
        }

        $this->assertSame([], $unpinned, sprintf(
            "These classes assert a 429 but do not pin their clock:\n  %s\n\n".
            'FixedWindow::hit() indexes on intdiv(now()->getTimestamp(), $windowS) — an ABSOLUTE '.
            'window — so a request loop that straddles a real boundary lands its last request in a '.
            'fresh window, where a 202 is CORRECT limiter behaviour and the TEST is what is wrong '.
            "(card#9223).\n\nEither `use Tests\\Feature\\Support\\PinsTheRateLimitWindow;` on the ".
            'class, or — if its limiter is not FixedWindow-backed — add it to self::NOT_FIXED_WINDOW '.
            'with the reason.',
            implode("\n  ", array_keys($unpinned)),
        ));
    }

    /**
     * The population, re-derived: every test file whose source asserts a 429, mapped to its class.
     *
     * @return array<class-string, string>
     */
    private function classesAssertingA429(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! str_contains($source, 'assertStatus(429)')) {
                continue;
            }

            // This file names the literal in its own failure message, so the scan finds itself.
            // Not a defensive flourish — it fired on the first run.
            if ($file->getPathname() === __FILE__) {
                continue;
            }

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cls)) {
                continue;
            }

            $found[trim($ns[1]).'\\'.$cls[1]] = $file->getPathname();
        }

        return $found;
    }

    /**
     * Whether `$class` has a pinned clock by the time its tests run — however it gets one. The
     * trait supplies it; `FoldTestCase` supplies its own for an unrelated reason. This asks the
     * PROPERTY, so it does not care which.
     */
    private function classPinsItsClock(string $class): bool
    {
        $case = new $class('test_dummy');

        $reflection = new \ReflectionMethod($case, 'setUp');

        try {
            $reflection->invoke($case);
            $pinned = Carbon::hasTestNow();
        } finally {
            $tearDown = new \ReflectionMethod($case, 'tearDown');
            try {
                $tearDown->invoke($case);
            } catch (\Throwable) {
                // the probe only needs setUp's effect; a partial teardown is not a result
            }
            Carbon::setTestNow();
        }

        return $pinned;
    }
}
