<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md § 5.5` — the client's own narration is "newest first, capped at 200 lines,
 * text only". The cap is the record's own bound, so it ships with the record at Appendix B step 3;
 * what the lines SAY, and who draws them, is the lobby's and the strip's, later.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NO WORDING IS ASSERTED HERE. This test is about the record's SHAPE — how many lines survive,
 * and which ones — so it identifies a line by the seat and the act it names, never by a pinned
 * sentence; the wordings § 5.5 publishes are other tests' to hold.
 *
 * ⛔ THE RUN WRITES 201 LINES TO ASSERT A 200-LINE CAP, and one more than the bound is the whole
 * point: a fixture that wrote 200 would pass against a client with no cap at all.
 *
 * ⚠ WHY A RESYNC IS WHAT WRITES THEM: the resync line is written when the request is ISSUED, so a
 * seat endpoint answering `503` on every attempt turns each gap-delta into exactly one line, with
 * nothing else in the run competing for the record.
 */
class TheClientsRecordKeepsItsNewestTwoHundredLinesTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** The record's own bound: 201 acts, 200 lines, and the ones kept are the NEWEST. */
    public function test_two_hundred_and_one_acts_leave_the_newest_two_hundred_lines(): void
    {
        $result = $this->replay('log_cap');
        $log = $result['final']['event_log'];

        $this->assertCount(201, $this->seatRequests($result),
            'the run did not issue the 201 reads it exists to issue — the cap is being asserted '
            .'against a record that never reached it');
        $this->assertCount(200, $log, 'the record is not capped at its own stated bound');

        // Newest first, and the survivors are the newest: the LAST act of the run is line 0, and
        // the first act — the one at t=1000 — has been dropped.
        $this->assertStringStartsWith('201000 ', $log[0],
            'the newest line is not at the top, so "newest first" is not what this record is');
        $this->assertStringStartsWith('2000 ', $log[199],
            'the oldest SURVIVING line is not the 201st-from-newest — the cap dropped the wrong end');
        $this->assertSame([], array_values(array_filter(
            $log,
            static fn (string $line): bool => str_starts_with($line, '1000 '),
        )), 'the first act of the run is still in the record, so nothing was dropped at all');
    }

    /** P20b — the cap removed: every act survives, and the record grows without bound. */
    public function test_the_planted_client_diverges_on_the_field_its_row_names(): void
    {
        $uncapped = $this->replay('log_cap', $this->plantedClient(FleetClientPlants::UNCAPPED[0]));

        $this->assertCount(201, $uncapped['final']['event_log'],
            'P20b did not bite: the cap was removed and the record still held 200 lines');
        $this->assertStringStartsWith('1000 ', $uncapped['final']['event_log'][200],
            'P20b’s divergence is that the line the cap DROPS is the one still there');
    }
}
