<?php

namespace Tests\Feature\Board;

use App\Board\LinkPass;
use App\Ingest\SchemaVersions;
use Illuminate\Support\Facades\DB;

/**
 * AT-D4-12 — an undeclared heartbeat clears a declared mapping only from a reporter that sends the members
 * (card#12447, `docs/design/BOARD-TASK.md § 4.4` step 2).
 *
 * The REDs this holds: a clear that does not read `reporter_version` (the older-build seat would be
 * unmapped), and one that compares against `reporterIsOutdated()` (the seat under a higher current build
 * would never clear). The floor is `SchemaVersions::TODO_AND_BOARD_DECLARATION_FROM`, the build that
 * sends the members on every heartbeat.
 */
class AtD4_12AnUndeclaredHeartbeatClearsOnlyFromAReporterThatSendsTest extends BoardTaskTestCase
{
    private function mappedByDeclaration(): void
    {
        $this->liveTier3Seat();
        $this->members = [self::BOARD => ['data' => [$this->member(14)]]];
        $this->board([self::BOARD => [1 => $this->page([$this->card(9234, 14, '2026-09-11T02:10:00+00:00', 'the card')])]]);
        $this->declare(14);
        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();
        $this->assertSame('board_card', $this->renderedTask()['source'], 'the fixture must start board-titled');
    }

    public function test_a_current_reporter_declaring_none_is_cleared_and_falls_through_undegraded(): void
    {
        $this->mappedByDeclaration();
        $this->declare(null);
        $this->assertSame(0, $this->poll()[0]);

        $this->assertSame([null, null], $this->mapping());
        $this->assertNull($this->boardRow());
        $this->assertSame(1, $this->seatCounter(LinkPass::CLEARED));
        $this->sweep();
        $this->assertSame('telemetry', $this->renderedTask()['source']);
        $this->assertFalse($this->renderedTask()['degraded'], 'a seat that stopped declaring renders degraded');
    }

    public function test_an_older_reporter_and_a_silent_seat_are_left_as_they_are(): void
    {
        $this->mappedByDeclaration();
        // A build before the members omits the key, which the ingest stores as the same `null`.
        $this->declare(null, version: '0.4.0');
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping(), 'a reporter that predates the member was unmapped');
        $this->assertSame(0, $this->seatCounter(LinkPass::CLEARED));
    }

    public function test_a_mapped_seat_with_no_batch_since_the_mapping_is_untouched(): void
    {
        $this->mappedByDeclaration();
        DB::table('seat_state')->where('seat_ref', $this->seatRef)->update(['board_user_declared' => null, 'board_fingerprint_declared' => null,
            'reporter_version' => null]);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'declared'], $this->mapping(), 'silence was read as a declaration');
    }

    public function test_the_comparison_is_against_the_floor_and_never_the_current_build(): void
    {
        $this->mappedByDeclaration();
        $this->assertSame(SchemaVersions::TODO_AND_BOARD_DECLARATION_FROM, SchemaVersions::CURRENT_REPORTER_VERSION,
            'precondition: this build is the floor; the case below models the day the current build passes it');
        $this->declare(null, version: '9.0.0');
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([null, null], $this->mapping(), 'a seat ahead of the floor did not clear');
        $this->assertTrue(SchemaVersions::reporterAtLeast('0.5.0', '0.5.0'));
        $this->assertTrue(SchemaVersions::reporterAtLeast('0.10.0', '0.5.0'), 'string order, not numeric precedence');
        $this->assertFalse(SchemaVersions::reporterAtLeast('0.4.9', '0.5.0'));
        $this->assertFalse(SchemaVersions::reporterAtLeast(null, '0.5.0'));
        $this->assertFalse(SchemaVersions::reporterAtLeast('0.5', '0.5.0'));
    }

    /** Control: a seat the OPERATOR mapped that stops declaring keeps its mapping (step 1 skips it). */
    public function test_control_a_pinned_seat_that_stops_declaring_keeps_its_mapping(): void
    {
        $this->liveTier3Seat();
        $this->members = [self::BOARD => ['data' => [$this->member(14)]]];
        $this->board([self::BOARD => [1 => $this->page([])]]);
        $this->map(14);
        $this->declare(null);
        $this->assertSame(0, $this->poll()[0]);
        $this->assertSame([14, 'operator'], $this->mapping());
    }
}
