<?php

namespace Tests\Feature\Fold;

use Tests\Feature\Feed\FeedTestCase;

/**
 * card#9296 — § 8.2.1's `protocol_agent_name` and `protocol_agent_name_check`, driven through the
 * real ingest, fold and read plane, with the VALUES asserted.
 *
 * ⛔ WHAT THE DRIFT GUARD CANNOT SEE. `SeatObjectMatchesTheDocumentTest` re-derives § 8.2.1's field
 * list and asserts the object carries every NAME in it, so a pair hard-wired to `null` passes it —
 * and would resolve no coordination participant to any desk, forever. What § 8.2.1 declares is a
 * VALUE with three readings that null alone cannot separate: no heartbeat yet (both `null`), a seat
 * that declares none (`null` and `undeclared`), and a declaration (`pm` and a check state).
 *
 * ⚠ AND THE DELTA. § 6.5 makes both members version-bearing, so the edge rides the heartbeat that
 * carries it. `FeedSurfaceTest::test_an_ordinary_heartbeat_emits_no_delta` is the other half — its
 * heartbeats now carry the declaration too, so it is what reds if the unchanged pair ever mints one.
 *
 * ⚠ `FeedTestCase` rather than `FoldTestCase`, for the read-plane credential and the captured wire,
 * as `HeartbeatObjectsKeepTheirWireShapeTest` in this directory does.
 */
class ProtocolAgentNameDeclarationTest extends FeedTestCase
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

    public function test_a_heartbeat_carrying_the_declaration_projects_it_and_rides_the_delta(): void
    {
        // ── BEFORE THE FIRST HEARTBEAT: an activity-only seat has said nothing ─────────────────
        $this->deliver($this->cleanTurn());
        $this->fold();

        $seat = $this->served();
        $this->assertArrayHasKey('protocol_agent_name', $seat);
        $this->assertArrayHasKey('protocol_agent_name_check', $seat);
        $this->assertNull($seat['protocol_agent_name'], '§ 8.2.1: `null` before the first heartbeat');
        $this->assertNull($seat['protocol_agent_name_check'], '§ 8.2.1: `null` before the first heartbeat');

        // ── THE FIRST DECLARING HEARTBEAT ──────────────────────────────────────────────────────
        $mark = $this->wire->mark();

        $this->deliver($this->heartbeats(1));
        $this->fold();

        $this->assertSame('pm', $this->state()->protocol_agent_name);
        $this->assertSame('checked', $this->state()->protocol_agent_name_check);

        $seat = $this->served();
        $this->assertSame('pm', $seat['protocol_agent_name'], '§ 8.2.1: the last heartbeat\'s declaration');
        $this->assertSame('checked', $seat['protocol_agent_name_check'], '§ 8.2.1: D1\'s check, verbatim');

        foreach (['protocol_agent_name' => 'pm', 'protocol_agent_name_check' => 'checked'] as $member => $value) {
            $up = $this->deltasCarrying($member, $mark);
            $this->assertCount(1, $up, "the declaring heartbeat emitted no delta carrying `{$member}` (§ 6.5)");
            $this->assertSame($value, $up[0]['payload']['patch'][$member]);
        }

        // ── ONE MEMBER MOVES ALONE: a roster disagreeing under an unchanged name ──────────────
        $mark = $this->wire->mark();

        $beat = $this->heartbeats(1, 90_000)[0];
        $beat['data']['protocol_agent_name_check'] = 'disagreed';
        $this->deliver([$beat]);
        $this->fold();

        $flip = $this->deltasCarrying('protocol_agent_name_check', $mark);
        $this->assertCount(1, $flip, 'a check-state edge emitted no delta carrying it');
        $this->assertSame('disagreed', $flip[0]['payload']['patch']['protocol_agent_name_check']);
        $this->assertSame([], $this->deltasCarrying('protocol_agent_name', $mark),
            'the delta named an unchanged `protocol_agent_name` as changed');
        $this->assertSame('pm', $this->served()['protocol_agent_name'],
            'D1 § 3.1: a `disagreed` name is emitted exactly as declared, and this plane carries it so');
    }

    /** `undeclared` is a seat that REPORTED and declared none — not the null of a silent one. */
    public function test_a_seat_that_declares_none_is_undeclared_rather_than_null(): void
    {
        $beat = $this->heartbeats(1)[0];
        $beat['data']['protocol_agent_name'] = null;
        $beat['data']['protocol_agent_name_check'] = 'undeclared';

        $this->deliver([$beat]);
        $this->fold();

        $seat = $this->served();
        $this->assertNull($seat['protocol_agent_name'], '§ 8.2.1: `null` on a seat that declares none');
        $this->assertSame('undeclared', $seat['protocol_agent_name_check'],
            '§ 8.2.1: the check is what tells a non-declaring seat from one that never heartbeated');
    }

    /**
     * A later heartbeat that OMITS both keys leaves both `null`.
     *
     * § 8.2.1 declares each "last heartbeat's value", and D1 § 6.0 says "a missing key and an
     * explicit `null` are the same thing" — so the last heartbeat's value IS `null`, exactly as
     * `enabled` (`HeartbeatObjectsKeepTheirWireShapeTest`). Keeping the previous declaration instead
     * would publish a name the seat has stopped sending as though it still sent it.
     */
    public function test_a_later_heartbeat_that_omits_both_keys_leaves_both_null(): void
    {
        $this->deliver($this->heartbeats(1));
        $this->fold();

        // The premise: a heartbeat that DOES declare moved the columns, so the nulls below are about
        // the omission and not about columns nothing ever wrote.
        $this->assertSame('pm', $this->state()->protocol_agent_name, 'the declaration never reached the column');

        $mark = $this->wire->mark();

        $beat = $this->heartbeats(1, 90_000)[0];
        unset($beat['data']['protocol_agent_name'], $beat['data']['protocol_agent_name_check']);
        $this->deliver([$beat]);
        $this->fold();

        $this->assertSame(90_000, (int) $this->state()->reporter_uptime_s,
            'the omitting heartbeat never landed, so the assertions below would prove nothing');

        $seat = $this->served();
        $this->assertNull($seat['protocol_agent_name'], 'an omitted declaration outlived the heartbeat that dropped it');
        $this->assertNull($seat['protocol_agent_name_check'], 'an omitted check outlived the heartbeat that dropped it');

        foreach (['protocol_agent_name', 'protocol_agent_name_check'] as $member) {
            $down = $this->deltasCarrying($member, $mark);
            $this->assertCount(1, $down, "clearing `{$member}` emitted no delta carrying it");
            $this->assertNull($down[0]['payload']['patch'][$member]);
        }
    }
}
