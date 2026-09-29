<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-9 (render half) — the client half of snapshot-then-deltas.** Gated at Appendix B **step 6**:
 * *no `edge` row* and *the held `entered` rows the delivered states require* are claims about the
 * **animation set**, and a floor with no animations in it satisfies *no edge row* for free.
 * card#7341 step 6. The protocol half is step 3's and is `TheClientProtocolOpensBeforeItReadsTest`'s.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ § 6.5: A SNAPSHOT, A RESYNC, A PER-SEAT FETCH AND A RECONNECT RENDER THE WORLD AS DELIVERED, WITH
 * NO `edge`-CLASS ANIMATION. Their arrival is not a claim that anything happened to any seat — it is a
 * claim about what the client knows. Animating them would put an arrival at every desk on every
 * reconnect, and a fleet that appeared to walk back in every time the network hiccupped would have made
 * the floor's motion meaningless in exactly one afternoon.
 *
 * ⛔ AND THE OTHER HALF OF THE SAME RULE IS ASSERTED BESIDE IT: a snapshot DOES render the states it
 * delivers, held renders included, so the `entered` rows must be there. A test asserting only the
 * absence would be satisfied by a renderer that drew nothing at all, which is the reading § 6.5 itself
 * calls out — *the rule is no edge animation on a snapshot, never no motion after a snapshot*.
 *
 * ⛔ HOW *ACROSS THE SNAPSHOT APPLY* IS MADE CHECKABLE. These runs deliver deltas too, and those deltas
 * legitimately fire `edge` rows — `leg_c`'s `win-1` delta patches `context`, which is A12's own driver.
 * So the population is the versions a REST surface delivered and a delta did NOT: no `edge` row may name
 * one. That is the rule stated over what the client applied rather than over a wall-clock window.
 */
class TheSnapshotRenderAnimatesNothingTest extends TestCase
{
    use DrivesTheDeskFloor;
    use ReadsTheAnimationTable;

    /**
     * Both runs the protocol half builds: the forced 500 ms connect window, and `fx-membership` leg (c)
     * with the same forced delay on the discrepancy fetch.
     */
    private const RUNS = ['watermark', 'leg_c'];

    /** ⛔ THE GREEN — no `edge` row across either run's REST applies, and the held entries present. */
    public function test_no_edge_row_fires_on_a_snapshot_a_resync_or_a_fetch(): void
    {
        foreach (self::RUNS as $run) {
            $result = $this->deskRun($run);
            $restOnly = $this->versionsOnlyRestDelivered($run);

            $this->assertNotSame([], $restOnly,
                "[{$run}] no version was delivered by a REST surface alone, so the clause below is vacuous");

            foreach ($result['animation_log'] as $row) {
                if ($row['class'] !== 'edge') {
                    continue;
                }

                $this->assertNotContains($row['cause'], $restOnly,
                    "[{$run}] {$row['animation_id']} fired on `state_version` {$row['cause']}, which only a snapshot "
                    .'or a per-seat fetch delivered — § 6.5, and an arrival at every desk on every reconnect');
            }

            // THE OTHER HALF: the states those applies delivered ARE entered, each opening a fresh
            // episode and carrying the delivering object's own `state_version`.
            $entered = array_values(array_filter(
                $result['animation_log'],
                static fn (array $r): bool => $r['phase'] === 'entered' && in_array($r['cause'], $restOnly, true),
            ));

            $this->assertNotSame([], $entered,
                "[{$run}] a REST apply delivered states and entered no held render — a floor that went still");

            $episodes = array_column($entered, 'episode_id');

            $this->assertSame($episodes, array_unique($episodes), "[{$run}] two REST-apply entries share an episode");

            foreach ($entered as $row) {
                $key = "{$row['install_id']}/{$row['seat_id']}";
                $object = $this->objectAtVersion($run, $key, $row['cause']);

                $this->assertNotNull($object, "[{$run}] {$key}'s entry names a version no REST body carried");
                $this->assertSame($this->heldRowFor($object), $row['animation_id'],
                    "[{$run}] {$key} entered a row § 6.2 does not predict for the object that was delivered");
            }
        }
    }

    /**
     * ⛔ THE MID-SESSION LEG's RENDER: `aimla-win/win-1`'s RENDERED desk equals the fixture's post-delta
     * object and not the discovery snapshot's. The two differ on `context.used_pct`, which the desk draws
     * as its gauge — so this is asserted on a RENDERED value, not on the held object the protocol half
     * already covers.
     */
    public function test_the_discovered_desk_renders_the_post_delta_object(): void
    {
        $result = $this->deskRun('leg_c');
        $desk = $this->lastFrame($result)['desks']['aimla-win/win-1'] ?? null;

        $this->assertNotNull($desk, 'the discovered install\'s desk was never drawn');

        $scenario = $this->fixture('leg_c');
        $discovery = $this->servedSeat($scenario['http']['/api/fleet/snapshot'][1]['body'], 'win-1');
        $final = $scenario['final']['aimla-win/win-1'];

        $this->assertNotSame($discovery['context']['used_pct'], $final['context']['used_pct'],
            'the discovery snapshot and the post-delta object agree on the gauge, so this test cannot tell them apart');
        $this->assertSame($final['context']['used_pct'], $desk['gauge']['bar'],
            'the discovered desk draws the discovery snapshot\'s gauge, not the object the drain applied over it');
        $this->assertStringContainsString((string) $final['context']['used_pct'], $desk['gauge']['pct'],
            'the gauge\'s rendered percentage is not the post-delta sample\'s');
    }

    /**
     * ⛔ THIRD RED — THE ANIMATING SNAPSHOT. The client routes every row a REST surface delivers through
     * the delta journal, and reads *this seat was absent* as *this seat was `offline`*; every desk on the
     * floor then plays on every reconnect. The SET IS UNMUTATED, which is what makes this a statement
     * about its § 6.5 guard rather than about a plant.
     */
    public function test_third_red_an_animating_snapshot_plays_at_every_desk(): void
    {
        $animating = $this->plantedClient(FleetClientPlants::SNAPSHOT_AS_DELTA[0]);

        foreach (self::RUNS as $run) {
            $result = $this->deskRun($run, $animating);
            $restOnly = $this->versionsOnlyRestDelivered($run);
            $fired = array_values(array_filter(
                $result['animation_log'],
                static fn (array $r): bool => $r['class'] === 'edge' && in_array($r['cause'], $restOnly, true),
            ));

            $this->assertNotSame([], $fired,
                "[{$run}] the RED did not bite: the snapshot's rows went through the delta journal and the set "
                .'fired nothing on them');
        }
    }

    /**
     * The `state_version`s one run's REST bodies delivered and its deltas did not — the population § 6.5
     * forbids an `edge` row to name.
     *
     * @return list<int>
     */
    private function versionsOnlyRestDelivered(string $run): array
    {
        $scenario = $this->fixture($run);
        $rest = [];
        $deltas = [];

        foreach ($scenario['http'] as $responses) {
            foreach ($responses as $response) {
                foreach ($this->seatsIn($response['body'] ?? null) as $seat) {
                    $rest[] = $seat['state_version'];
                }
            }
        }

        foreach ($scenario['messages'] ?? [] as $message) {
            if (($message['envelope']['t'] ?? null) === 'seat.delta') {
                $deltas[] = $message['envelope']['state_version'];
            }
        }

        return array_values(array_unique(array_diff($rest, $deltas)));
    }

    /**
     * The object one run's REST bodies delivered for a key at a version.
     *
     * @return array<string, mixed>|null
     */
    private function objectAtVersion(string $run, string $key, int $version): ?array
    {
        foreach ($this->fixture($run)['http'] as $responses) {
            foreach ($responses as $response) {
                foreach ($this->seatsIn($response['body'] ?? null) as $seat) {
                    if ("{$seat['install_id']}/{$seat['seat_id']}" === $key && $seat['state_version'] === $version) {
                        return $seat;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Every seat object one response body carries — a snapshot's `installs[].seats[]`, or a served seat
     * body with the REST envelope stripped.
     *
     * @return list<array<string, mixed>>
     */
    private function seatsIn(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        if (isset($body['installs'])) {
            $seats = [];

            foreach ($body['installs'] as $install) {
                foreach ($install['seats'] as $seat) {
                    $seats[] = $seat;
                }
            }

            return $seats;
        }

        if (isset($body['seat_id'])) {
            return [array_diff_key($body, ['api_version' => null, 'server_time' => null, 'detail' => null])];
        }

        return [];
    }

    /**
     * One seat out of a snapshot body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function servedSeat(array $body, string $seatId): array
    {
        foreach ($this->seatsIn($body) as $seat) {
            if ($seat['seat_id'] === $seatId) {
                return $seat;
            }
        }

        $this->fail("the body carries no seat {$seatId}");
    }
}
