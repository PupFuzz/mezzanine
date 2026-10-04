<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * card#11144 — § 8.2.1's `protocol_agent_role`, the roster entry's role the reporter relays beside the
 * declared name (D1 § 3.1), driven through the real ingest, fold, read plane and feed, with the VALUES
 * asserted. Shaped like `ProtocolAgentNameDeclarationTest`, and for the same reason: a member hard-wired
 * to `null` passes every drift guard that checks the member's NAME, and would seat nobody at a desk
 * reserved for a role, forever.
 *
 * The readings it separates: no heartbeat yet (`null`), a heartbeat relaying a role (`pm`), a heartbeat
 * relaying `null` (the reporter's answer for every case D1 § 3.1 lists), and a heartbeat that omits the
 * member (a reporter that predates it — `null`, like the name pair).
 */
class ProtocolAgentRoleRelayTest extends FeedTestCase
{
    /** @return array<string, mixed> the seat's § 8.2.1 object as a consumer receives it */
    private function served(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)
            ->assertOk()->json();
    }

    /** @return list<array<string, mixed>> the `seat.delta` messages since `$mark` naming `$member` */
    private function deltasCarrying(string $member, int $mark): array
    {
        return array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array($member, $d['payload']['changed'], true),
        ));
    }

    public function test_a_relayed_role_projects_rides_the_delta_and_clears_on_a_null(): void
    {
        // ── BEFORE THE FIRST HEARTBEAT ──────────────────────────────────────────────────────────
        $this->deliver($this->cleanTurn());
        $this->fold();

        $seat = $this->served();
        $this->assertArrayHasKey('protocol_agent_role', $seat);
        $this->assertNull($seat['protocol_agent_role'], '§ 8.2.1: `null` before the first heartbeat');

        // ── THE FIRST HEARTBEAT RELAYING A ROLE ────────────────────────────────────────────────
        $mark = $this->wire->mark();
        $this->deliver($this->heartbeats(1));
        $this->fold();

        $this->assertSame('pm', $this->state()->protocol_agent_role);
        $this->assertSame('pm', $this->served()['protocol_agent_role'], '§ 8.2.1: the last heartbeat\'s role, verbatim');

        $up = $this->deltasCarrying('protocol_agent_role', $mark);
        $this->assertCount(1, $up, 'the relaying heartbeat emitted no delta carrying `protocol_agent_role` (§ 6.5)');
        $this->assertSame('pm', $up[0]['payload']['patch']['protocol_agent_role']);

        // ── AN UNCHANGED ROLE MINTS NOTHING ────────────────────────────────────────────────────
        $mark = $this->wire->mark();
        $this->deliver($this->heartbeats(1, 90_000));
        $this->fold();
        $this->assertSame(90_000, (int) $this->state()->reporter_uptime_s, 'the second heartbeat never landed');
        $this->assertSame([], $this->deltasCarrying('protocol_agent_role', $mark),
            'a heartbeat relaying the same role named it as changed');

        // ── THE ROLE MOVES ALONE: a roster edit under an unchanged, still-checked name ──────────
        $mark = $this->wire->mark();
        $beat = $this->heartbeats(1, 180_000)[0];
        $beat['data']['protocol_agent_role'] = 'pm-helper';
        $this->deliver([$beat]);
        $this->fold();

        $moved = $this->deltasCarrying('protocol_agent_role', $mark);
        $this->assertCount(1, $moved, 'a role edge emitted no delta carrying it');
        $this->assertSame('pm-helper', $moved[0]['payload']['patch']['protocol_agent_role'],
            'D1 § 3.1: the role is relayed uninterpreted, an open vocabulary');
        $this->assertSame([], $this->deltasCarrying('protocol_agent_name', $mark),
            'the delta named an unchanged `protocol_agent_name` as changed');
        $this->assertSame([], $this->deltasCarrying('protocol_agent_name_check', $mark),
            'the delta named an unchanged `protocol_agent_name_check` as changed');

        // ── THE REPORTER RELAYS NULL: a duplicate roster name, under a `checked` declaration ────
        $mark = $this->wire->mark();
        $beat = $this->heartbeats(1, 270_000)[0];
        $beat['data']['protocol_agent_role'] = null;
        $this->deliver([$beat]);
        $this->fold();

        $seat = $this->served();
        $this->assertNull($seat['protocol_agent_role'], 'a relayed null left the previous role in place');
        $this->assertSame('checked', $seat['protocol_agent_name_check'],
            'the premise: D1 § 3.1\'s `checked` + `null` reading — the check is unchanged under a null role');
        $down = $this->deltasCarrying('protocol_agent_role', $mark);
        $this->assertCount(1, $down, 'clearing `protocol_agent_role` emitted no delta carrying it');
        $this->assertNull($down[0]['payload']['patch']['protocol_agent_role']);
    }

    /**
     * A later heartbeat that OMITS the member leaves it `null` — a reporter that predates card#11144.
     * D1 § 6.0: a missing key and an explicit `null` are the same thing, so a kept role would publish a
     * role the seat has stopped relaying as though it still relayed it.
     */
    public function test_a_later_heartbeat_that_omits_the_role_leaves_it_null(): void
    {
        $this->deliver($this->heartbeats(1));
        $this->fold();
        $this->assertSame('pm', $this->state()->protocol_agent_role, 'the role never reached the column');

        $beat = $this->heartbeats(1, 90_000)[0];
        unset($beat['data']['protocol_agent_role']);
        $this->deliver([$beat]);
        $this->fold();

        $this->assertSame(90_000, (int) $this->state()->reporter_uptime_s,
            'the omitting heartbeat never landed, so the assertion below would prove nothing');
        $this->assertNull($this->served()['protocol_agent_role'], 'an omitted role outlived the heartbeat that dropped it');
        $this->assertSame('pm', $this->served()['protocol_agent_name'],
            'the control: the name the same heartbeat still carried is kept, so the null is the role\'s own');
    }

    /**
     * A role AT § 6.14's 48 B figure is stored verbatim — D2 § 6.4's column is that wide. One byte over is
     * the ingest's refusal, which `Tests\Unit\Ingest\EventFieldByteBoundsTest` drives for every bound
     * `KindRegistry::KINDS` declares, this one included.
     */
    public function test_a_role_at_the_bound_is_stored_verbatim(): void
    {
        $at = $this->heartbeats(1)[0];
        $at['data']['protocol_agent_role'] = str_repeat('a', 48);
        $this->deliver([$at]);
        $this->fold();
        $this->assertSame(str_repeat('a', 48), $this->served()['protocol_agent_role'], 'a role AT the bound was not served verbatim');
    }

    /**
     * `mezzanine:rebuild` RESETS the role with the rest of the heartbeat facts (card#11144). A rebuild
     * whose window holds no heartbeat must land on `null`, as the live fold of that window would: a
     * kept role is a value no replayed event carries, which is AT-D2-10's divergence by construction.
     * The name pair is the control — it is reset by the same list, so a `null` name beside a kept role
     * is the role's own reset missing.
     */
    public function test_a_rebuild_whose_window_holds_no_heartbeat_resets_the_role(): void
    {
        $this->deliver($this->heartbeats(1));
        $this->fold();
        $this->assertSame('pm', $this->state()->protocol_agent_role, 'the premise: a role reached the column');

        $this->deliver($this->cleanTurn());
        $this->fold();
        $newest = DB::table('events')->where('seat_ref', $this->seatRef)->max('received_at');

        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT, '--since' => $newest])
            ->assertSuccessful();

        $this->assertNull($this->state()->protocol_agent_name, 'the control: the rebuild did not reset the name pair');
        $this->assertNull($this->state()->protocol_agent_role, 'the rebuild kept a role no replayed heartbeat carries');
    }
}
