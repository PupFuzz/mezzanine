<?php

namespace Tests\Unit\Ingest;

use App\Ingest\IngestPipeline;
use PHPUnit\Framework\TestCase;

/**
 * card#9465 — the ingest's session bounds are derived from the REPORTER's request deadline, so the
 * figure is only right while its inputs are: this holds the derivation's input to the deadline the
 * shipped reporter actually enforces and to the one D1 publishes, and the two derived figures to the
 * copies `docs/design/FLEET-STATE.md § 12` states.
 *
 * `IngestWriteBoundTest` asserts the constants are what the session carries; this asserts the constants
 * still mean what their derivation says. A reporter whose deadline moved would otherwise leave the
 * ingest waiting past it, or giving up long before it, with every test green.
 */
class IngestWriteBoundSourcesTest extends TestCase
{
    private const REPO = __DIR__.'/../../../..';

    public function test_the_derivation_reads_the_deadline_the_shipped_reporter_enforces(): void
    {
        $deadlineMs = (new \ReflectionClassConstant(IngestPipeline::class, 'REPORTER_DEADLINE_MS'))->getValue();

        $this->assertSame([$deadlineMs], $this->figures(
            self::REPO.'/fleet-reporter/fleet-reporter.js',
            '/^\s*REQUEST_MS:\s*(\d+),/m',
        ), "fleet-reporter.js's REQUEST_MS is not the deadline IngestPipeline's bounds are derived from");

        $this->assertSame([intdiv($deadlineMs, 1000)], $this->figures(
            self::REPO.'/docs/design/EVENT-SCHEMA.md',
            '/^\| Total request deadline \| \*\*(\d+) s\*\* \|/m',
        ), "D1 § 3.5's total request deadline is not the one IngestPipeline's bounds are derived from");
    }

    public function test_the_design_states_the_figures_the_ingest_pins(): void
    {
        $doc = self::REPO.'/docs/design/FLEET-STATE.md';

        $this->assertSame([IngestPipeline::LOCK_WAIT_TIMEOUT_S], $this->figures($doc, '/^\| Ingest lock-wait bound \(`innodb_lock_wait_timeout`\) \| (\d+) s \|/m'));
        $this->assertSame([IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S], $this->figures($doc, '/^\| Ingest idle-transaction bound \(`idle_transaction_timeout`\) \| (\d+) s \|/m'));
        $this->assertSame([IngestPipeline::LOCK_WAIT_TIMEOUT_S], $this->figures($doc, '/`innodb_lock_wait_timeout` = \*\*(\d+) s\*\*/'));
        $this->assertSame([IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S], $this->figures($doc, '/`idle_transaction_timeout` = \*\*(\d+) s\*\*/'));
    }

    /**
     * Every figure `$pattern` captures in `$path`, as integers — a list, so a copy that vanished (`[]`) or
     * was duplicated reds as surely as one that changed.
     *
     * @return list<int>
     */
    private function figures(string $path, string $pattern): array
    {
        $text = file_get_contents($path);
        $this->assertIsString($text, "could not read $path");

        preg_match_all($pattern, $text, $m);

        return array_map('intval', $m[1]);
    }
}
