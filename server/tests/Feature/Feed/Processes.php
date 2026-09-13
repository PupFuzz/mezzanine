<?php

namespace Tests\Feature\Feed;

use App\Feed\StreamClock;
use App\Fold\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Several of the feed's processes — streams, writers — run CONCURRENTLY on one virtual clock, each in
 * its own `Fiber` and, where the property needs it, on its own database connection (card#9300).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY. AT-D2-15 is "one connection closes and no others" and AT-D2-25 is "a concurrent writer cannot
 * strand a message behind a stream's cursor". Both are properties of processes that run AT THE SAME
 * TIME: a healthy stream ticking while a slow one is blocked in its write; a stream reading between
 * two writers' commits. One PHP process can run them at the same time only if each can stop where the
 * real one would wait — in its 250 ms sleep, in a blocked write, inside an open transaction — and the
 * next can run. A `Fiber` is exactly that stop.
 *
 * HOW. Every process sleeps through `sleepUntilMs()` (the handler's `StreamClock` IS this object) or
 * `waitMs()`, which suspends its fiber with a wake time. `run()` resumes whichever process wakes first,
 * after moving the application clock forward to that instant (never back), and switching the default
 * database connection to that process's own. So time is shared and monotonic, and "the stream read
 * between writer 2's COMMIT and writer 1's" is an ordering this schedules rather than a race the suite
 * hopes to win.
 *
 * ⚠ WHAT IT IS NOT: parallel. Two processes never execute a statement at the same instant, so a race
 * INSIDE one statement is not reached. Every property here is about the ORDER of whole statements
 * across processes — which rows a read can see given which commits have landed — and that order is
 * what a real MariaDB decides from the real connections this uses.
 */
final class Processes implements StreamClock
{
    private const LIVELOCK = 100_000;

    /** @var array<string, array{fiber: \Fiber, wake: int, connection: string}> */
    private array $processes = [];

    /** @var array<string, mixed> each finished process's return value */
    public array $results = [];

    public function nowMs(): int
    {
        return (int) Clock::toMs(Clock::sql(now()));
    }

    /** `StreamClock`: a stream's 250 ms sleep, which lets every other process run meanwhile. */
    public function sleepUntilMs(int $ms): void
    {
        \Fiber::suspend($ms);
    }

    /** Let `$ms` pass for the calling process — a write that blocks, a transaction held open. */
    public function waitMs(int $ms): void
    {
        \Fiber::suspend($this->nowMs() + $ms);
    }

    /** Start `$body` as a process at `$atMs` (default now) on `$connection`. */
    public function spawn(string $name, \Closure $body, ?int $atMs = null, string $connection = 'mysql'): void
    {
        $this->processes[$name] = [
            'fiber' => new \Fiber(fn () => $this->results[$name] = $body($this)),
            'wake' => $atMs ?? $this->nowMs(),
            'connection' => $connection,
        ];
    }

    public function finished(string $name): bool
    {
        return array_key_exists($name, $this->results);
    }

    /**
     * Run every process until all have finished, or until `$untilMs` — after which the unfinished ones
     * are abandoned where they stand (a stream that would have ticked for ever).
     */
    public function run(int $untilMs): void
    {
        $default = DB::getDefaultConnection();
        $stalledResumes = 0;

        try {
            while ($this->processes !== []) {
                uasort($this->processes, fn ($a, $b) => $a['wake'] <=> $b['wake']);
                $name = array_key_first($this->processes);
                $process = $this->processes[$name];

                if ($process['wake'] > $untilMs) {
                    break;
                }

                if ($process['wake'] > $this->nowMs()) {
                    Carbon::setTestNow(Carbon::now()->addMilliseconds($process['wake'] - $this->nowMs()));
                    $stalledResumes = 0;
                } elseif (++$stalledResumes > self::LIVELOCK) {
                    // A process that keeps asking to wake at an instant already past never lets time
                    // move — a loop that sleeps "until" a stale timestamp. Fail it by name rather than
                    // hang the suite.
                    throw new \RuntimeException("process '$name' resumed ".self::LIVELOCK.' times without the clock moving: a livelock');
                }

                DB::setDefaultConnection($process['connection']);

                $wake = $process['fiber']->isStarted()
                    ? $process['fiber']->resume()
                    : $process['fiber']->start();

                if ($process['fiber']->isTerminated()) {
                    unset($this->processes[$name]);
                } else {
                    $this->processes[$name]['wake'] = (int) $wake;
                }
            }
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
