<?php

namespace Tests\Feature\Fold;

use App\Fold\Derivation;

/**
 * AT-D2-3 — stale, offline and disabled are RENDERED, never idle.
 *
 * ⚠ SCOPE. § 11's build for this test is "run the SWEEPER across the thresholds", and the sweeper
 * (§ 2.1's third process) is built by neither half of card #7339 — see the PR body. What IS in
 * scope, and what this file drives, is the DERIVATION the sweeper would run: § 4.5's cascade and
 * § 4.2's collapse, exercised through the fold, which recomputes both on every applied event.
 *
 * The fold reaches every threshold honestly rather than by back-dating a column: a batch delivered
 * at T and folded at T+400 s is a fold pass whose `server_now − last_receipt_at` is 400 s, which is
 * exactly the input § 4.5 rule 3 reads. What is NOT covered here is the sweeper's own job — making
 * that transition ARRIVE on a seat that has stopped sending anything at all, which no fold pass
 * can do because a seat with no unfolded events is never claimed.
 */
class At3LinkStateTest extends FoldTestCase
{
    public function test_a_silent_seat_renders_stale_with_its_activity_state_preserved_underneath(): void
    {
        $this->deliver($this->cleanTurn());

        // 400 s of silence — PAST 300 s, and the direction matters. § 11: "a seat is stale when it
        // has been silent for MORE than 300 s, and asserting the ceiling instead would pass on a
        // seat that never went stale at all."
        $this->advanceServerClock(400);
        $this->fold();

        $state = $this->state();
        $this->assertSame('stale', $state->render_state);

        // `D2-MUST` #2, discharged STRUCTURALLY: the activity axis is MASKED, not cleared. § 4.4
        // says leaving `live` masks `idle` rather than clearing it, because idle is a claim about
        // something that already happened and staleness does not falsify it.
        $this->assertSame('idle', $state->activity_state);
        $this->assertNotSame('idle', $state->render_state);

        // § 4.5: `stale` carries `no_data_since` = `last_receipt_at`, so the rendered string is
        // "no data since 14:18" rather than a glyph that means nothing on its own.
        $this->assertNotNull($state->last_receipt_at);
    }

    public function test_a_seat_silent_past_900_s_renders_offline(): void
    {
        $this->deliver($this->cleanTurn());
        $this->advanceServerClock(1000);
        $this->fold();

        $this->assertSame('offline', $this->state()->render_state);
        $this->assertSame('idle', $this->state()->activity_state);
    }

    public function test_the_discriminating_control_a_seat_receiving_normally_does_not_go_stale(): void
    {
        // "A seat receiving normally must not enter `stale` in the same run, or the sweep is simply
        // marking everything."
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('idle', $this->state()->render_state);
        $this->assertSame('live', $this->state()->link_state);
    }

    public function test_a_heartbeat_carrying_enabled_false_renders_disabled_not_offline(): void
    {
        // D1 § 6.14 / § 4.5 rule 4: a seat that is OFF and a seat that is GONE must not look alike.
        // A disabled seat keeps heartbeating — it is off, not gone.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $heartbeat = $this->heartbeats(1);
        $heartbeat[0]['data']['enabled'] = false;

        $this->deliver($heartbeat);
        $this->fold();

        $this->assertSame('disabled', $this->state()->render_state);
        $this->assertSame('idle', $this->state()->activity_state);
    }

    /**
     * § 4.5's cascade, every rule and both directions, as the unit it is.
     *
     * The two ORDERINGS inside it are decisions and each gets a case: silence above the flag (a
     * seat that has stopped heartbeating is telling us nothing current about whether it is off),
     * and off above draining (a disabled seat's spool backlog is a fact about a seat that is not
     * working, and "off" is the more actionable reading).
     */
    public function test_the_link_cascade_is_total_and_each_rule_can_be_selected(): void
    {
        $now = 1_000_000_000_000;
        $fresh = $now - 1_000;

        $this->assertSame('offline', Derivation::link(null, true, null, $now), 'rule 1: never reported');
        $this->assertSame('offline', Derivation::link($now - 901_000, true, null, $now), 'rule 2');
        $this->assertSame('stale', Derivation::link($now - 301_000, true, null, $now), 'rule 3');
        $this->assertSame('disabled', Derivation::link($fresh, false, null, $now), 'rule 4');
        $this->assertSame('catching_up', Derivation::link($fresh, true, 301, $now), 'rule 5');
        $this->assertSame('live', Derivation::link($fresh, true, 0, $now), 'rule 6');

        // Silence above the flag: a DISABLED seat that has also stopped heartbeating takes
        // `offline`, because the cascade tests silence BEFORE it tests a flag that is only ever
        // learned from a heartbeat that is no longer arriving.
        $this->assertSame('offline', Derivation::link($now - 901_000, false, null, $now));

        // Off above draining.
        $this->assertSame('disabled', Derivation::link($fresh, false, 9_999, $now));

        // The thresholds are exclusive, which is the direction AT-D2-3 turns on.
        $this->assertSame('live', Derivation::link($now - 300_000, true, null, $now));
        $this->assertSame('stale', Derivation::link($now - 900_000, true, null, $now));
        $this->assertSame('live', Derivation::link($fresh, true, 300, $now));
    }

    public function test_render_precedence_collapses_in_the_stated_order(): void
    {
        // § 4.2, read top-down, first match wins. `retired` is true regardless of what the seat is
        // still doing — a retired seat that keeps reporting is a misconfiguration, and rendering it
        // as `working` would hide that.
        $this->assertSame('retired', Derivation::render(true, 'live', 'working'));
        $this->assertSame('retired', Derivation::render(true, 'offline', 'idle'));
        $this->assertSame('stale', Derivation::render(false, 'stale', 'idle'));
        $this->assertSame('catching_up', Derivation::render(false, 'catching_up', 'working'));
        $this->assertSame('working', Derivation::render(false, 'live', 'working'));
        $this->assertSame('idle', Derivation::render(false, 'live', 'idle'));
    }
}
