<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **§ 6.2 A11, `badge-raise`, exercised once** — `docs/design/FLOOR.md § 6.2`'s row: *"a delta whose
 * `changed[]` contains `badges` and whose array gains a member"*. Owed since card#7341 step 6's review
 * (card#7341 comments 6346, 6352, 6367): no fixture patched `badges`, so `DELTA_ROWS`' A11 predicate
 * in `wire/animation-set.js` ran on nothing, and the closed-set vacuity guards could not notice,
 * because six other `edge` rows satisfied them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUN IS THREE DELTAS AND EXACTLY ONE OF THEM IS A GAIN. `fx-snapshot-4`'s `badge_raise`
 * run gains a badge, re-sends the same array, then drops it — all three carry `badges` in
 * `changed[]`. So *exactly one A11 row, caused by the gaining delta* separates a correct predicate
 * from both wrong ones: a row that never fires writes none, and a row that fires on the member alone
 * writes three.
 *
 * ⛔ THE DRIVING MEMBER IS READ FROM § 6.2's OWN CELL (`documentDrivingMembers()`), never typed
 * here, so a table edit that moved A11 onto another member reds this rather than leaving it
 * asserting a driver the document no longer names.
 */
class TheBadgeRaiseFiresOnlyOnAGainedBadgeTest extends TestCase
{
    use DrivesTheDeskFloor;
    use ReadsTheAnimationTable;

    private const RUN = 'badge_raise';

    /** The anchor both plants edit: A11's own predicate in `DELTA_ROWS`. */
    private const A11_PREDICATE = "Object.freeze({ id: 'A11', changed: 'badges', fires: (before, after) => gained(badges(before), badges(after)) }),";

    public function test_a_gained_badge_fires_one_badge_raise_and_a_resent_or_dropped_one_fires_none(): void
    {
        $result = $this->deskRun(self::RUN);

        $this->assertA11FiredOnceOnTheGain($result);
    }

    /**
     * The run's own premise, checked rather than assumed: every delta carries A11's driving member in
     * `changed[]`, and they are the gain, the re-send and the loss in that order. Without this the
     * GREEN's *only once* would be a statement about a fixture that never gave the row a chance.
     */
    public function test_every_delta_in_the_run_carries_the_rows_driving_member(): void
    {
        $members = $this->documentDrivingMembers('A11');

        $this->assertNotSame([], $members, "§ 6.2's A11 Driving fact cell did not parse — nothing below is read from the document");

        $deltas = array_map(static fn (array $m): array => $m['envelope'], $this->fixture(self::RUN)['messages']);

        $this->assertCount(3, $deltas, 'the run is the gain, the re-send and the loss');

        foreach ($deltas as $delta) {
            $this->assertNotSame([], array_intersect($members, $delta['changed']),
                "delta {$delta['state_version']} does not carry A11's driving member — it cannot test the predicate");
        }

        $this->assertSame([[], ['lossy'], ['lossy']], [
            $this->snapshotSeats(self::RUN)['aimla/aimla-impl-2']['badges'],
            $deltas[0]['patch']['badges'],
            $deltas[1]['patch']['badges'],
        ], 'the first delta is not a gain over the snapshot, or the second is not a re-send of it');
        $this->assertSame([], $deltas[2]['patch']['badges'], 'the third delta is not a loss');

        // The client applied all three: an A11 count over deltas the client discarded measures nothing.
        $this->assertEquals($this->fixture(self::RUN)['final']['aimla/aimla-impl-2'],
            $this->finalSeats($this->deskRun(self::RUN))['aimla/aimla-impl-2'],
            'the client did not converge on the run\'s final object — the deltas were not all applied');
    }

    /** Planted RED — the row never fires: the one gain in the run draws no badge-raise. */
    public function test_red_a_badge_raise_that_never_fires_is_caught(): void
    {
        $dir = $this->mutatedModules(['animation-set.js', self::A11_PREDICATE,
            "Object.freeze({ id: 'A11', changed: 'badges', fires: () => false }),"]);

        $this->assertA11Reds($this->deskRun(self::RUN, $dir), 'never fires');
    }

    /** Planted RED — the row fires on the member alone: the re-send and the loss each draw one too. */
    public function test_red_a_badge_raise_that_fires_on_the_member_alone_is_caught(): void
    {
        $dir = $this->mutatedModules(['animation-set.js', self::A11_PREDICATE,
            "Object.freeze({ id: 'A11', changed: 'badges', fires: () => true }),"]);

        $this->assertA11Reds($this->deskRun(self::RUN, $dir), 'fires on `changed[]` alone');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertA11FiredOnceOnTheGain(array $result): void
    {
        $rows = $this->a11Rows($result);

        $this->assertCount(1, $rows, 'A11 did not fire exactly once over one gain, one re-send and one loss');
        $this->assertSame('edge', $rows[0]['class']);
        $this->assertSame('fired', $rows[0]['phase']);
        $this->assertSame(['aimla', 'aimla-impl-2'], [$rows[0]['install_id'], $rows[0]['seat_id']],
            'A11 fired on a desk the gaining delta did not name');
        $this->assertSame($this->fixture(self::RUN)['messages'][0]['envelope']['state_version'], $rows[0]['cause'],
            "A11's cause is not the `state_version` of the delta that gained the badge");
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertA11Reds(array $result, string $plant): void
    {
        try {
            $this->assertA11FiredOnceOnTheGain($result);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("the planted A11 that {$plant} passed the GREEN — the badge-raise gate cannot fail");
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function a11Rows(array $result): array
    {
        return array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['animation_id'] === 'A11'));
    }
}
