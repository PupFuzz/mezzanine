<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\SeatFacts;
use App\Fold\StateRecompute;
use Illuminate\Support\Facades\DB;

/**
 * EVERY TRANSITION ROW IS ANNOUNCED BY THE `state_version` IT CITES.
 *
 * § 8.5 makes `state_version` the feed's ordering key and has the client apply a delta iff
 * `delta.state_version == local.state_version + 1`, and every transition row carries the version it
 * happened at (§ 6.4, § 8.5's table). So a transition row written at a version that was never
 * incremented is a state change no consumer can ever learn about: the drill-down has a row, the feed
 * has nothing, and a client polling the version sees the same number it already holds.
 *
 * § 6.5 states the two writes as two separate conditions — "if any VERSION-BEARING field changed:
 * state_version += 1" and "if render_state changed: INSERT seat_state_transitions" — plus a third
 * requirement inside the poison-event rule: a fold error writes a transition row "regardless",
 * because that row is the only record that an event was skipped. Two of those three can disagree,
 * and this test drives both places they meet:
 *
 *  1. THE RENDER-CHANGE ROW. Sound only because `render_state` is a MEMBER of § 6.5's
 *     version-bearing subtraction — a render change is therefore a version change by construction
 *     and cannot be missed. That is load-bearing and invisible: removing `render_state` from
 *     `SeatFacts::versionBearing()` (it reads like bookkeeping beside `link_state` and
 *     `activity_state`) makes every transition row cite a stale version at once. The second test
 *     below pins it.
 *
 *  2. THE FOLD-ERROR ROW, which is the reachable one. A repeat poison event on a seat that is
 *     already badged `derivation_error` moves NOTHING version-bearing — the badge is already up,
 *     `reporter.heartbeat` is not in § 3.2's activity set so no activity column moves, and the
 *     projection itself was rolled back — so the row was written at the previous version, twice
 *     over, and the desk's third and fourth skipped events were invisible to the feed.
 */
class StateVersionAnnouncesEveryTransitionTest extends FoldTestCase
{
    /** @return list<array{version: int, cause: string, to: ?string}> */
    private function transitionRows(): array
    {
        return DB::table('seat_state_transitions')->where('seat_ref', $this->seatRef)->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'version' => (int) $r->state_version,
                'cause' => $r->cause,
                'to' => $r->to_render_state,
            ])->all();
    }

    /** Assert the invariant this file exists for, over whatever rows the seat has. */
    private function assertEveryRowAnnounced(): void
    {
        $rows = $this->transitionRows();

        $this->assertNotEmpty($rows, 'no transition rows, so the assertion below would pass vacuously');

        $versions = array_column($rows, 'version');

        $this->assertSame(
            $versions,
            array_values(array_unique($versions)),
            'two transition rows cite the same state_version, so at least one of them was written at a '
                .'version that was never bumped and no consumer of the feed can learn it happened: '
                .json_encode($rows),
        );

        $sorted = $versions;
        sort($sorted);

        $this->assertSame($sorted, $versions, 'transition rows are not in version order');

        $this->assertLessThanOrEqual(
            (int) $this->state()->state_version,
            end($versions),
            'a transition row cites a version ahead of the seat',
        );
    }

    public function test_a_repeat_fold_error_is_not_written_at_a_version_nobody_will_ever_see(): void
    {
        $this->deliver($this->cleanTurn());

        // A heartbeat is the poison kind ON PURPOSE: it is NOT in § 3.2's activity set, so its
        // recompute moves no activity column, and after the `derivation_error` badge is up a second,
        // third and fourth quarantine move nothing version-bearing at all. That is the case where
        // the version bump and the transition row disagree; a poisoned `tool.end` would hide the
        // defect behind the activity columns it drags along.
        $poison = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                if ($e->kind === 'reporter.heartbeat') {
                    throw new \RuntimeException('unprojectable');
                }

                parent::apply($e);
            }
        };

        for ($i = 0; $i < 4; $i++) {
            $this->deliver($this->heartbeats(1));

            // Two passes per delivery: § 6.5 retries a raising event ALONE ONCE before quarantining.
            (new Fold($poison, new StateRecompute))->pass();
            (new Fold($poison, new StateRecompute))->pass();
        }

        $this->assertSame(4, (int) $this->state()->fold_errors, 'the four poison events did not all quarantine');
        $this->assertSame(4, count(array_filter($this->transitionRows(), fn ($r) => $r['cause'] === 'fold_error')),
            '§ 6.5 requires a transition row per fold error regardless of whether the render moved');

        $this->assertEveryRowAnnounced();
    }

    public function test_a_render_change_is_a_version_change_by_construction(): void
    {
        // THE MECHANISM, ASSERTED DIRECTLY. `render_state` is a member of § 6.5's version-bearing set
        // — the subtraction removes ten bookkeeping members and this is not one of them — which is
        // the whole reason the render-change branch cannot write a row at an unbumped version.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertArrayHasKey('render_state', SeatFacts::versionBearing($this->seatRef),
            'render_state left the version-bearing set: every transition row now cites a stale version');

        // And behaviourally, over § 11's `clear_kill` fixture — § 10's two transition rows, each at
        // its own version, each version one the feed will carry.
        $this->deliver($this->clearKill());
        $this->fold();

        $this->assertEveryRowAnnounced();

        $renderRows = array_values(array_filter($this->transitionRows(), fn ($r) => $r['cause'] === 'wire_event'));

        $this->assertGreaterThanOrEqual(3, count($renderRows), 'the fixture minted fewer render changes than it should');
    }

    public function test_a_rebuild_writes_its_marker_row_at_a_version_of_its_own(): void
    {
        // THE THIRD WRITER OF A TRANSITION ROW, found auditing the shape of the two above.
        // `mezzanine:rebuild` replays the log through the same `StateRecompute` — so the replay's
        // own rows are minted correctly — and then writes ONE marker row with `cause: rebuild`
        // directly, reading `state_version` back without bumping it. When the last replayed event
        // moved the render (a `turn.end` leaving `working`, which is how most windows end) that
        // marker lands on the version the replay's last row already announced: two rows, one
        // version, and the feed carries no delta saying the seat was rebuilt.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->artisan('mezzanine:rebuild', ['--seat' => 'aimla/aimla-pm'])->assertSuccessful();

        $rows = $this->transitionRows();

        $this->assertSame('rebuild', end($rows)['cause'] ?? null);

        $this->assertEveryRowAnnounced();
    }
}
