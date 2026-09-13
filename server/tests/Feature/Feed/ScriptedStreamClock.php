<?php

namespace Tests\Feature\Feed;

use App\Feed\StreamClock;
use App\Fold\Clock;
use Illuminate\Support\Carbon;

/**
 * The suite's `App\Feed\StreamClock`: the stream handler's loop, stepped one tick at a time on the
 * suite's pinned application clock, with one scripted server act per tick.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY ON THE APPLICATION CLOCK. The handler measures its tick and its stall bound on this clock and
 * its visibility lag on `now()`. In production those are two clocks moving together; here they are
 * one, so a scripted step that advances `now()` by 46 s is a 46 s gap between two ticks, exactly as a
 * write that blocked for 46 s would be.
 *
 * WHAT A STEP IS. `sleepUntilMs()` is called once per tick, after the previous tick's writes. It first
 * moves the clock to the tick's due time (the 250 ms sleep), then runs the next step, if any. A step is
 * any server act — a fold, a retirement, a session expiry, a store failure, a snapshot fetch — so it
 * lands BETWEEN two of the handler's reads, which is where every real one lands.
 *
 * ⛔ A SCRIPT THAT NEVER ENDS ITS STREAM FAILS LOUDLY rather than hanging the suite: once the steps are
 * spent the clock allows `$idleTicks` more ticks and then throws. Every test ends its stream the way
 * production does — a `fleet.reload`, an expired session, a failed read, a stall.
 */
final class ScriptedStreamClock implements StreamClock
{
    public int $ticks = 0;

    private int $idle = 0;

    /** @param  list<\Closure(self): void>  $steps */
    public function __construct(private array $steps = [], private readonly int $idleTicks = 200) {}

    public function nowMs(): int
    {
        return (int) Clock::toMs(Clock::sql(now()));
    }

    public function sleepUntilMs(int $ms): void
    {
        $this->advanceMs(max(0, $ms - $this->nowMs()));
        $this->ticks++;

        if ($this->steps !== []) {
            $this->idle = 0;
            (array_shift($this->steps))($this);

            return;
        }

        if (++$this->idle > $this->idleTicks) {
            throw new \RuntimeException('the scripted stream never ended: its steps were spent '
                .$this->idleTicks.' ticks ago and nothing closed it');
        }
    }

    /** Move the application clock — the handler's and the visibility lag's. */
    public function advanceMs(int $ms): void
    {
        if ($ms > 0) {
            Carbon::setTestNow(Carbon::now()->addMilliseconds($ms));
        }
    }

    /** Append steps (a test can add the next act from inside a step). */
    public function then(\Closure ...$steps): void
    {
        array_push($this->steps, ...$steps);
    }
}
