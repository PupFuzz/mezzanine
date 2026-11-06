<?php

namespace Tests\Feature\Support;

use Tests\TestCase;

/**
 * card#12946 — `DrivesAShippedClientModule::runProbes()`, the one path every probe run takes, reads a
 * large answer in time that grows with its size, not with its square.
 *
 * The defect this exists for: the read loop appended each chunk to a buffer that a leftover
 * `foreach` value copy still shared, so every append copied the whole answer so far. A floor run's
 * answer is many megabytes, and the per-change test job went from about half an hour to about an
 * hour and a half on it (PR #420). The run's answer was still right — only the time showed it — so
 * the check is a time bound, set far from both sides: a linear read of this answer takes well
 * under a second even on a loaded host, and the quadratic one took minutes.
 */
class TheProbeRigReadsALargeAnswerInLinearTimeTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const STDOUT_BYTES = 32 * 1024 * 1024;

    private const STDERR_BYTES = 4 * 1024 * 1024;

    /** Seconds. A linear read of the answer below takes a fraction of one; the quadratic read took minutes. */
    private const BOUND_S = 20.0;

    protected function moduleDir(): string
    {
        return $this->jsRoot();
    }

    protected function probeScript(): string
    {
        return __DIR__.'/bulk-output-probe.mjs';
    }

    public function test_a_large_answer_is_read_whole_and_in_linear_time(): void
    {
        $started = microtime(true);
        [$status, $stdout, $stderr] = $this->runProbe(['stdout_bytes' => self::STDOUT_BYTES, 'stderr_bytes' => self::STDERR_BYTES]);
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $status, "the bulk probe failed:\n".substr($stderr, -2000));
        $this->assertSame(self::STDOUT_BYTES, strlen($stdout), 'the rig did not read the whole stdout');
        $this->assertSame(self::STDERR_BYTES, strlen($stderr), 'the rig did not read the whole stderr');
        $this->assertSame('', trim($stdout, 'o'), 'stdout carried bytes the probe did not write');
        $this->assertSame('', trim($stderr, 'e'), 'stderr carried bytes the probe did not write');
        $this->assertLessThan(self::BOUND_S, $elapsed, sprintf(
            'reading a %d MiB answer took %.1f s — the read loop is copying its buffer on every append',
            self::STDOUT_BYTES >> 20, $elapsed,
        ));
    }
}
