<?php

namespace Tests\Feature\Fold;

use Tests\Feature\Feed\FeedTestCase;

/**
 * card#12239 — § 8.2.1's `roundtable_agent_name` and `roundtable_agent_name_check`, driven through
 * the real ingest, fold and read plane, with the VALUES asserted. The sibling of
 * `ProtocolAgentNameDeclarationTest`, whose header states why a drift guard on the field NAMES cannot
 * see a pair hard-wired to `null`.
 *
 * The two arms of D2 § 8.3.3 rule 1 read two different declarations, so each test here also asserts
 * that moving one pair leaves the other where it was: a fold that wrote the roundtable name into the
 * protocol name's column (or the reverse) would cross the arms the rule says never cross.
 */
class RoundtableAgentNameDeclarationTest extends FeedTestCase
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

    /** One heartbeat carrying the given roundtable pair beside the fixture's own protocol name. */
    private function beat(?string $name, ?string $check, int $uptime = 90_000): array
    {
        $beat = $this->heartbeats(1, $uptime)[0];
        $beat['data']['roundtable_agent_name'] = $name;
        $beat['data']['roundtable_agent_name_check'] = $check;

        return $beat;
    }

    public function test_a_heartbeat_carrying_the_declaration_projects_it_and_rides_the_delta(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $seat = $this->served();
        $this->assertArrayHasKey('roundtable_agent_name', $seat);
        $this->assertNull($seat['roundtable_agent_name'], '§ 8.2.1: `null` before the first heartbeat');
        $this->assertNull($seat['roundtable_agent_name_check'], '§ 8.2.1: `null` before the first heartbeat');

        $mark = $this->wire->mark();

        $this->deliver([$this->beat('pm-solo', 'checked')]);
        $this->fold();

        $seat = $this->served();
        $this->assertSame('pm-solo', $seat['roundtable_agent_name'], '§ 8.2.1: the last heartbeat\'s declaration');
        $this->assertSame('checked', $seat['roundtable_agent_name_check'], '§ 8.2.1: D1\'s check, verbatim');
        // The arms never cross: the protocol name is the fixture's own, untouched by this pair.
        $this->assertSame('pm', $seat['protocol_agent_name'], 'the roundtable declaration moved the protocol name');

        foreach (['roundtable_agent_name' => 'pm-solo', 'roundtable_agent_name_check' => 'checked'] as $member => $value) {
            $up = $this->deltasCarrying($member, $mark);
            $this->assertCount(1, $up, "the declaring heartbeat emitted no delta carrying `{$member}` (§ 6.5)");
            $this->assertSame($value, $up[0]['payload']['patch'][$member]);
        }

        // ── ONE MEMBER MOVES ALONE: the config's identity disagreeing under an unchanged name ──
        $mark = $this->wire->mark();

        $this->deliver([$this->beat('pm-solo', 'disagreed', 91_000)]);
        $this->fold();

        $flip = $this->deltasCarrying('roundtable_agent_name_check', $mark);
        $this->assertCount(1, $flip, 'a check-state edge emitted no delta carrying it');
        $this->assertSame('disagreed', $flip[0]['payload']['patch']['roundtable_agent_name_check']);
        $this->assertSame([], $this->deltasCarrying('roundtable_agent_name', $mark),
            'the delta named an unchanged `roundtable_agent_name` as changed');
    }

    /** A name off the slug pattern reaches the column as `null`, as the protocol name's does. */
    public function test_a_name_off_the_slug_pattern_is_stored_as_null(): void
    {
        $this->deliver([$this->beat('PM Solo', 'checked')]);
        $this->fold();

        $this->assertNull($this->served()['roundtable_agent_name'],
            'a name outside D1 § 3.1\'s slug pattern reached the seat object');
        $this->assertSame('checked', $this->served()['roundtable_agent_name_check']);
    }

    /** A later heartbeat that OMITS both keys leaves both `null` — D1 § 6.0's missing-equals-null. */
    public function test_a_later_heartbeat_that_omits_both_keys_leaves_both_null(): void
    {
        $this->deliver([$this->beat('pm-solo', 'checked')]);
        $this->fold();
        $this->assertSame('pm-solo', $this->state()->roundtable_agent_name, 'the declaration never reached the column');

        $beat = $this->heartbeats(1, 92_000)[0];
        unset($beat['data']['roundtable_agent_name'], $beat['data']['roundtable_agent_name_check']);
        $this->deliver([$beat]);
        $this->fold();

        $this->assertSame(92_000, (int) $this->state()->reporter_uptime_s,
            'the omitting heartbeat never landed, so the assertions below would prove nothing');
        $this->assertNull($this->served()['roundtable_agent_name']);
        $this->assertNull($this->served()['roundtable_agent_name_check']);
    }
}
