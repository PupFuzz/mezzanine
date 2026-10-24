<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-18, as card#12239's build re-scoped it — the coordination JOIN resolves or reports
 * unresolved, never a guessed desk.** `docs/design/FLOOR.md` Appendix B row 7's second gate (card#7341
 * step 7, § 14 item 24), with the line assertions removed together with A18 (the operator's ruling of
 * 2026-10-23: nothing coordination-related is held). What survives is the join this test exists for —
 * which names resolve to which desk, which do not, and with which reason — asserted on A19's origin and
 * destinations, A20's origin, and the event-log line an unresolved end writes (§ 5.7 property 1).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ENVELOPE COUNT IS DERIVED PER POST, never written: A19's rows for a post EQUAL its destinations
 * that resolve to a desk, and `fx-coord`'s `coord_fanout` run makes that count TWO so a fan-out bug is
 * visible to the equality at all.
 *
 * ⚠ WHAT LEFT WITH THE LINE, named rather than left as a silence: `"reviewer"` (a name real only on
 * another install) and `"aimla-impl-2"` (a seat-id coincidence) were `T1`'s PARTICIPANTS, and no
 * `coord.round` of `fx-coord` names either, so the seat-id-equality RED is held at the model by
 * `Tests\Feature\Coordination\CoordDrawsNoBirdWithoutAJoinTest`'s identity-fallback control and on the
 * fleet arm by AT-D3-29's fourth RED, rather than on this floor.
 */
class TheCoordinationJoinResolvesOrReportsUnresolvedTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const RUN = 'coord';

    private const CONTROL = 'coord_no_declarations';

    private const FANOUT = 'coord_fanout';

    private const ROOM = 'aimla';

    /** GREEN — R3's two ends: the origin and the one destination that resolve, and the one that does not. */
    public function test_green_each_end_resolves_to_its_one_declaring_desk_or_to_nothing(): void
    {
        $rounds = $this->rounds($this->floorRun(self::RUN));
        $r3 = $rounds['R3'];

        $this->assertSame('aimla-impl-1', $r3['origin']['agent']['seat_id'], '`"coder"` did not resolve to the one seat that declares it');
        $members = $this->byName($r3['targets']['members']);

        $this->assertSame('aimla-pm', $members['pm']['seat_id'], '`"pm"` did not resolve to the one seat that declares it');
        $this->assertFalse($members['helper']['resolved'], '`"helper"`, declared by two seats, resolved to a desk');
        $this->assertSame('duplicate_declaration', $members['helper']['reason'],
            'a name two seats of this install declare does not carry the install misconfiguration it is');
        $this->assertSame('declared by more than one seat', $members['helper']['words'], 'the duplicate arm carries no words');
        $this->assertSame(['aimla-pm'], array_column($r3['targets']['desks'], 'seat_id'),
            'an unresolved destination cancelled the resolved one, or a desk no declaration named was drawn to');
    }

    /**
     * GREEN — the *unchecked* marker, ⛔ BOTH HALVES: on the `"coder"` origin, whose declaration nobody
     * checked, and ABSENT on the `"pm"` destination beside it. The marker is words, never a suppressed
     * animation: R3's traveller still flies.
     */
    public function test_green_the_unchecked_end_is_marked_and_the_checked_one_is_not(): void
    {
        $r3 = $this->rounds($this->floorRun(self::RUN))['R3'];
        $pm = $this->byName($r3['targets']['members'])['pm'];

        $this->assertSame('unchecked', $r3['origin']['agent']['check']);
        $this->assertSame('unchecked', $r3['origin']['agent']['marker'], 'a resolved end resting on an UNCHECKED declaration is not rendered as one');
        $this->assertSame('checked', $pm['check']);
        $this->assertNull($pm['marker'], 'the `checked` end carries a marker too — *mark every end* is the same false render');
        $this->assertSame(['A19'], $r3['animations'], 'the unchecked declaration suppressed the traveller rather than carrying a word');
    }

    /** GREEN — each post's A19 rows equal its destinations that resolve to a desk; zero without an origin. */
    public function test_green_each_posts_envelopes_equal_its_resolving_destinations(): void
    {
        foreach ([self::RUN, self::FANOUT] as $run) {
            $result = $this->floorRun($run);
            $rows = $this->rowsFor($result, 'A19');
            $exercised = 0;

            foreach ($this->rounds($result) as $ref => $round) {
                $origin = $round['origin']['agent'];
                $resolving = $origin !== null && $origin['resolved'] === true && ! $round['broadcast']
                    ? count($round['targets']['desks'])
                    : 0;
                $written = count(array_filter($rows, static fn (array $r): bool => $r['cause'] === $ref));

                $this->assertSame($resolving, $written,
                    "[{$run}] {$ref} wrote {$written} travellers over {$resolving} destinations that resolve to a desk");

                $exercised = max($exercised, $resolving);
            }

            if ($run === self::FANOUT) {
                $this->assertGreaterThan(1, $exercised,
                    'no post in the fan-out run resolved more than one destination, so the traveller-count assertion is gated by nothing');
            }
        }
    }

    /** GREEN — the three `targets` states, and the two broadcasts told apart by ORIGIN. */
    public function test_green_the_three_fanout_states_and_the_two_broadcast_posts(): void
    {
        $result = $this->floorRun(self::RUN);
        $rounds = $this->rounds($result);

        // R1 — `targets: null` on a `to:all`: the ring, and no traveller.
        $this->assertFalse($rounds['R1']['targets']['known']);
        $this->assertSame('the fan-out is not resolvable here', $rounds['R1']['targets']['statement']);
        $this->assertSame(['A20'], $rounds['R1']['animations'], 'R1 is not the ring alone');

        // R2 — the same address from an origin that resolves to no desk: nothing at all, and the rest of
        // the object still renders.
        $this->assertSame([], $rounds['R2']['animations'], 'R2 fired an edge from an origin with no desk');
        $this->assertTrue($rounds['R2']['broadcast'], 'R2\'s address no longer carries `all`, so it says nothing about A20');

        // R4 — `targets: []`: *this post reached nobody*, which is not R1's answer.
        $this->assertTrue($rounds['R4']['targets']['known']);
        $this->assertSame('this post reached nobody', $rounds['R4']['targets']['statement']);
        $this->assertTrue($rounds['R4']['declares_close']);

        $this->assertSame(['R1'], array_column($this->rowsFor($result, 'A20'), 'cause'),
            'the rings drawn are not the one post whose address says `all` AND whose origin resolves');
    }

    /** GREEN — § 5.7 property 1: an end that resolves to no desk is a line of the event log, with its reason. */
    public function test_green_an_unresolved_end_is_a_line_of_the_event_log(): void
    {
        $log = implode("\n", $this->floorRun(self::RUN)['final']['event_log']);

        $this->assertStringContainsString('coordination post R2: helper declared by more than one seat', $log,
            'R2\'s unresolved origin wrote no event-log line, or one without its reason');
        $this->assertStringContainsString('coordination post R3: coder unchecked; helper declared by more than one seat', $log,
            'R3\'s line does not name its unchecked origin and its unresolved destination');
        $this->assertStringNotContainsString('coordination post R1:', $log, 'R1, every end of which resolved, wrote a line');
    }

    /**
     * ⛔ DISCRIMINATING CONTROL — the same messages with NO seat declaring anything: no A19/A20 row is
     * written — A20 included, the row this control would lose first — and no desk's facts move.
     */
    public function test_control_with_no_declarations_nothing_is_drawn_and_no_desk_changes(): void
    {
        $result = $this->floorRun(self::CONTROL);
        $frame = $this->lastFloor($result);

        foreach (['A18', 'A19', 'A20', 'A22'] as $id) {
            $this->assertSame([], $this->rowsFor($result, $id), "a {$id} row was written although no name resolves");
        }

        $this->assertSame(2, count(array_filter($this->rounds($result), static fn (array $r): bool => $r['broadcast'] === true)),
            'the control no longer carries its two broadcast addresses, so its A20 arm asserts nothing');

        $declared = $this->lastFloor($this->floorRun(self::RUN))['desks']['desks'];

        foreach ($frame['desks']['desks'] as $key => $desk) {
            $this->assertSame($declared[$key]['render_state'], $desk['render_state'], "[{$key}] a desk's `render_state` moved with the coordination layer");
            $this->assertSame($declared[$key]['badges'], $desk['badges'], "[{$key}] a badge moved with the coordination layer");
            $this->assertSame($declared[$key]['currency_label'], $desk['currency_label'], "[{$key}] a currency label moved with the coordination layer");
        }
    }

    /** ⛔ RED — `duplicate_declaration` FOLDED INTO `no_declaring_seat`: a misconfiguration reads as an absence. */
    public function test_red_folding_the_duplicate_arm_shows_a_misconfiguration_as_an_absence(): void
    {
        $folded = $this->mutatedModules([self::COORD_JOIN,
            '            reasons[name] = DUPLICATE_DECLARATION;',
            '            reasons[name] = NO_DECLARING_SEAT;',
        ]);

        $helper = $this->byName($this->rounds($this->floorRun(self::RUN, $folded))['R3']['targets']['members'])['helper'];

        $this->assertSame('no_declaring_seat', $helper['reason'], 'the RED did not bite: the duplicate arm was folded and the name kept its own reason');
        $this->assertNull($helper['words'], 'the folded arm still carried the duplicate sentence');
    }

    /** ⛔ SECOND RED — THE RING WITH NO DESK TO LEAVE: fired on the address alone, R2 rings. R1 is the control. */
    public function test_red_a_ring_fired_on_the_address_alone_leaves_a_desk_that_resolved_nothing(): void
    {
        $addressOnly = $this->mutatedModules([self::COORD_MODEL, '    if (origin && round.broadcast) {', '    if (round.broadcast) {']);

        $this->assertContains('R2', array_column($this->rowsFor($this->floorRun(self::RUN, $addressOnly), 'A20'), 'cause'),
            'the RED did not bite: the ring was fired on the address alone and R2 still drew none');
        $this->assertContains('R1', array_column($this->rowsFor($this->floorRun(self::RUN), 'A20'), 'cause'),
            'the shipped set draws no ring for a broadcast whose origin resolves');
    }

    /**
     * ⛔ THIRD RED — THE UNVERIFIED NAME DRAWN AS A VERIFIED ONE, and its twin: dropping the marker makes
     * `"coder"` read as `"pm"` does; requiring `checked` to resolve sends no traveller at all.
     */
    public function test_red_dropping_the_unchecked_marker_draws_an_unverified_name_as_a_verified_one(): void
    {
        $unmarked = $this->mutatedModules([self::COORD_JOIN, '        checks[name] = claim.check;', "        checks[name] = 'checked';"]);
        $r3 = $this->rounds($this->floorRun(self::RUN, $unmarked))['R3'];

        $this->assertNull($r3['origin']['agent']['marker'], 'the RED did not bite: the marker survived a join that reports every declaration as checked');

        $strict = $this->mutatedModules([self::COORD_JOIN,
            "const RESOLVING_CHECKS = Object.freeze(['checked', 'unchecked']);",
            "const RESOLVING_CHECKS = Object.freeze(['checked']);",
        ]);
        $this->assertSame([], $this->rounds($this->floorRun(self::RUN, $strict))['R3']['animations'],
            'requiring `checked` to resolve still sent R3\'s traveller, so the fixture no longer flies from an `unchecked` end and the GREEN cannot catch that defect');
    }

    /**
     * The run's install-scope posts in this room, keyed by `post_ref`, from the render that applied
     * each — a post is an edge, drawn by the one render whose journal carried it, and no later frame
     * holds it (§ 5.7).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, array<string, mixed>>
     */
    private function rounds(array $result): array
    {
        $out = [];

        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['coord'][self::ROOM]['rounds'] ?? [] as $round) {
                $out[$round['post_ref']] ??= $round;
            }
        }

        $this->assertNotSame([], $out, 'no frame of the run carries a coordination post for the room — every assertion would be vacuous');

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $ends
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $ends): array
    {
        return array_column($ends, null, 'name');
    }
}
