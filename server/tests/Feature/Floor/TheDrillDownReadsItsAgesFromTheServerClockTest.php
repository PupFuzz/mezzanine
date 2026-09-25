<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-10 — ages come from the server clock, the PANEL half.** `docs/design/FLOOR.md § 11`, gated at
 * Appendix B **step 10** (card#7342; the floor half is step 4's, `TheAgeReadoutReadsTheServerClockTest`).
 * "The same fixture and the same skewed clock, **with the drill-down opened on `aimla-pm`** … the
 * transport block's *both ages under one* as of *stamp* is the only surface the receipt half is
 * observable on." GREEN: "the transport block's receipt age likewise reads seconds, not three hours, and
 * carries its *as of* stamp." **Reads:** the harness, the drill-down.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE EXPECTED AGE IS DERIVED, NEVER TRANSCRIBED: the stamp is the detail response's own
 * `server_time`, the receipt is the served seat's `delivery.last_receipt_at`, the wording is § 2.4's
 * table and the duration the shipped `formatDuration` — so the assertion is "the stamp minus the
 * receipt", and a browser clock three hours fast can move it only by a client that reads that clock.
 *
 * ⛔ THE CONTROL IS THE SAME RUN WITH THE BROWSER CLOCK RIGHT, AND THE WHOLE PANEL MUST BE IDENTICAL —
 * every age on it, ticking or stamped, not only the receipt — "so the test measures the offset and not
 * the rendering".
 */
class TheDrillDownReadsItsAgesFromTheServerClockTest extends TestCase
{
    use DrivesTheDrillDown;

    private const RUN = 'panel_ages';

    /** § 11's +3 h. */
    private const SKEW_MS = 3 * 3600 * 1000;

    /** The RED: the receipt age measured from `Date.now()` — the viewer's own machine. */
    private const BROWSER_CLOCK = ['../drilldown/drilldown-model.js',
        'receipt_age: receipt === null ? NO_DATA_YET : receiptAgeAt(receipt, at),',
        'receipt_age: receipt === null ? NO_DATA_YET : receiptAgeAt(receipt, Date.now()),'];

    public function test_green_the_receipt_age_reads_seconds_under_its_stamp_on_a_skewed_browser(): void
    {
        $this->assertSame([], $this->defects($this->skewed()));
    }

    public function test_control_a_correct_browser_clock_renders_the_identical_panel(): void
    {
        $skewed = $this->openPanel($this->skewed(), 'aimla-pm');
        $right = $this->openPanel($this->floorRun(self::RUN, null, ['browser_clock_ms' => $this->serverTimeMs(self::RUN)]), 'aimla-pm');

        $this->assertSame($right, $skewed, 'the panel differs between a right browser clock and one three hours fast');
        $this->assertSame([], $this->defects($this->floorRun(self::RUN, null, ['browser_clock_ms' => $this->serverTimeMs(self::RUN)])));
    }

    public function test_red_the_receipt_age_from_the_browser_clock_is_caught(): void
    {
        $defects = $this->defects($this->skewed($this->mutatedModules(self::BROWSER_CLOCK)));

        $this->assertArrayHasKey('receipt', $defects, 'the browser-clock RED did not bite: '.json_encode($defects));
        $this->assertStringContainsString('3h', $defects['receipt'], 'the RED failed, but not with the three hours it plants');
    }

    private function skewed(?string $dir = null): array
    {
        return $this->floorRun(self::RUN, $dir, ['browser_clock_ms' => $this->serverTimeMs(self::RUN) + self::SKEW_MS]);
    }

    /** @return array<string, string> */
    private function defects(array $result): array
    {
        $fixture = $this->fixture(self::RUN);
        $body = $fixture['http']['/api/fleet/seats/aimla/aimla-pm'][0]['body'];
        $panel = $this->openPanel($result, 'aimla-pm');
        $stamp = $this->ms($body['server_time']);
        $receipt = $this->ms($body['delivery']['last_receipt_at']);
        $want = $this->wording('receipt age', $this->formatDurations([intdiv($stamp - $receipt, 1000)])[0]);
        $asOf = 'as of '.gmdate('H:i:s', intdiv($stamp, 1000));
        $defects = [];

        if ($panel['transport']['receipt_age'] !== $want) {
            $defects['receipt'] = "the transport block's receipt age reads `{$panel['transport']['receipt_age']}`, not `{$want}`";
        }

        if ($panel['transport']['as_of'] !== $asOf) {
            $defects['stamp'] = "the transport block is stamped `{$panel['transport']['as_of']}`, not the detail response's `{$asOf}`";
        }

        // The ticking ages beside it are the corrected clock's too — seconds, not hours.
        foreach ([$panel['transport']['quiet_age'], $panel['action']['elapsed']] as $age) {
            if (! is_string($age) || preg_match('/\d+h/', $age) === 1) {
                $defects['ticking'] ??= 'a ticking age on the panel reads '.json_encode($age).' on a fleet reporting seconds ago';
            }
        }

        return $defects;
    }
}
