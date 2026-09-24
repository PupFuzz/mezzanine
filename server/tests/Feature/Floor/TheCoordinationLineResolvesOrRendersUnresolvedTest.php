<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-18 — the coordination thread line resolves or renders unresolved, never a guessed desk.**
 * `docs/design/FLOOR.md` Appendix B row 7's second gate, card#7341 step 7, § 14 item 24 (Q8).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS ONE TEST OVER ONE FIXTURE, AND THAT WAS RULED ON RATHER THAN INHERITED (card#7341
 * step 7). § 5.7's four properties are one claim about one render — every RED below plants a defect
 * in that same render — and § 11's own precedent for a claim that cannot be halved is to re-gate
 * rather than split (AT-D3-13, AT-D3-1). What the ruling is CONDITIONED on is the fan-out leg
 * below: A19's envelope count is asserted as the count of RESOLVING DESTINATIONS, derived per post,
 * and `fx-coord`'s `coord_fanout` run makes that count TWO. An incidental `1` is a figure a fixture
 * edit can silently de-gate; a derived count is not.
 *
 * ⛔ WHY A SECOND RUN WAS NEEDED FOR THAT AT ALL, stated because it is an arithmetic fact about the
 * fixture and not a preference: in the `coord` run only `"pm"` and `"coder"` resolve, and
 * D1 § 18.7 REMOVES the author from `targets` — so a post whose origin resolves leaves at most ONE
 * resolving destination there, whatever its address. Two beside a resolving origin needs THREE
 * resolving names, which is one more than § 11's `fx-coord` row declares, so `coord_fanout` supplies
 * the third exactly as `coord_no_declarations` removes all four for the discriminating control.
 *
 * ⛔ THE THREE COORDINATION ROWS ARE WRITTEN HERE FOR THE FIRST TIME ON THIS FLOOR, so this is the
 * discriminating test § 11's forward contract names: AT-D3-1's causing-message set gains
 * `coord.round` and `coord.thread` in the same change, and what makes that widening a gate rather
 * than a decoration is that the rows below are read against their causes.
 */
class TheCoordinationLineResolvesOrRendersUnresolvedTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const RUN = 'coord';

    private const CONTROL = 'coord_no_declarations';

    private const FANOUT = 'coord_fanout';

    private const ROOM = 'aimla';

    /** GREEN — the line, its two endpoints, and the desks it is NOT drawn to. */
    public function test_green_the_line_is_drawn_once_between_the_two_resolving_desks(): void
    {
        $result = $this->floorRun(self::RUN);
        $entered = $this->floorWithLine($result, self::ROOM)['coord'][self::ROOM];

        $this->assertCount(1, $entered['threads'], 'the room draws more than one thread for one `thread_ref`');

        $thread = $entered['threads'][0];

        $this->assertSame(['aimla-pm', 'aimla-impl-1'], $thread['endpoints'],
            'the line is not drawn between the two desks this install resolves — and to no other');
        $this->assertSame(['A18'], $thread['animations'], 'the open thread with two resolved endpoints drew no held line');

        // § 14 item 24's whole reason this render is step 7's: the endpoints are DESK POSITIONS, and
        // they are the positions § 3.2's slot function put those desks at.
        $slots = $this->slotsOf($this->floorWithLine($result, self::ROOM), self::ROOM);

        foreach ($thread['ends'] as $end) {
            $this->assertNotNull($end['x'], "[{$end['seat_id']}] the line's endpoint carries no floor position");
            $this->assertArrayHasKey(self::ROOM.'/'.$end['seat_id'], $slots);
        }

        // One A18 `entered` row, and § 11's forward contract for what it carries.
        $rows = $this->rowsFor($result, 'A18');
        $enteredRows = array_values(array_filter($rows, static fn (array $r): bool => $r['phase'] === 'entered'));

        $this->assertCount(1, $enteredRows, 'the log does not carry exactly one A18 entry for one open thread');
        $this->assertSame('T1', $enteredRows[0]['cause'], "A18's cause is not the `coord_thread.thread_ref` § 11 names");
        $this->assertSame(self::ROOM, $enteredRows[0]['install_id'], "A18's row does not carry the message's own install");
        $this->assertNull($enteredRows[0]['seat_id'], 'A18 claims a seat — it is drawn BETWEEN desks and claims none');
        $this->assertTrue($enteredRows[0]['motion'],
            'the line was entered drawn static, which claims one of § 11\'s three reasons for no motion where none applies');
    }

    /**
     * GREEN — the two unresolved REASONS are different words, and neither is the same word as the
     * other. `"helper"` is declared by two seats; `"reviewer"` is declared only on another install
     * and `"aimla-impl-2"` merely equals a `seat_id`.
     */
    public function test_green_a_duplicate_declaration_and_a_missing_one_render_different_reasons(): void
    {
        $model = $this->lastFloor($this->floorRun(self::RUN))['coord'][self::ROOM];
        $reasons = $model['unresolved_reasons'];

        $this->assertSame('duplicate_declaration', $reasons['helper'] ?? null,
            'a name two seats of this install declare does not render as the install misconfiguration it is');
        $this->assertSame('no_declaring_seat', $reasons['reviewer'] ?? null,
            'a name real only on another install does not render as *no seat may resolve it*');
        $this->assertSame('no_declaring_seat', $reasons['aimla-impl-2'] ?? null,
            'a name that merely equals a `seat_id` does not render as *no seat may resolve it*');

        $this->assertNotSame($reasons['helper'], $reasons['reviewer'],
            'the two arms render the same word, so an install misconfiguration reads as *no seat may resolve it*');

        // § 5.7's words for the duplicate arm, beside the name rather than as a line treatment.
        $participants = $this->participants($model);

        $this->assertSame('declared by more than one seat', $participants['helper']['words'],
            'the duplicate arm carries no words beside the name');
        $this->assertNull($participants['reviewer']['words'],
            'the other arm carries the duplicate arm\'s sentence, which is the opposite diagnosis');

        // `all` is a literal member and is expanded on NO endpoint of its own.
        $this->assertFalse($participants['all']['resolved'], '`all` was resolved to a desk');
        $this->assertNotContains('all', $model['threads'][0]['endpoints']);
    }

    /**
     * GREEN — the *unchecked* marker, ⛔ BOTH HALVES: present on the one participant whose
     * declaration nobody checked, and ABSENT on the one beside it. *Mark every participant*
     * satisfies the first alone and is the same false render one step further on.
     */
    public function test_green_the_unchecked_endpoint_is_marked_and_the_checked_one_is_not(): void
    {
        $model = $this->lastFloor($this->floorRun(self::RUN))['coord'][self::ROOM];
        $participants = $this->participants($model);

        $this->assertSame('unchecked', $participants['coder']['check']);
        $this->assertSame('unchecked', $participants['coder']['marker'],
            'a resolved endpoint resting on an UNCHECKED declaration is not rendered as one');

        $this->assertSame('checked', $participants['pm']['check']);
        $this->assertNull($participants['pm']['marker'],
            'the `checked` endpoint carries a marker too — *mark every participant* is the same false render');

        // The line is otherwise unchanged by it: the same single A18 row between the same two desks.
        $drawn = $this->floorWithLine($this->floorRun(self::RUN), self::ROOM)['coord'][self::ROOM];

        $this->assertSame(['aimla-pm', 'aimla-impl-1'], $drawn['threads'][0]['endpoints'],
            'the unchecked declaration changed the line rather than carrying a word beside a name');
    }

    /**
     * GREEN — each post's own answer, and ⭐ THE DERIVED ENVELOPE COUNT: the number of A19 rows a
     * post writes EQUALS the number of its destinations that resolve to a desk, and is zero for a
     * post whose origin does not. Nothing below writes the figure `1`.
     */
    public function test_green_each_posts_envelopes_equal_its_resolving_destinations(): void
    {
        foreach ([self::RUN, self::FANOUT] as $run) {
            $result = $this->floorRun($run);
            $model = $this->lastFloor($result)['coord'][self::ROOM];
            $rows = $this->rowsFor($result, 'A19');
            $exercised = 0;

            foreach ($model['rounds'] as $round) {
                $origin = $round['origin']['agent'];
                $resolving = $origin !== null && $origin['resolved'] === true
                    ? count($round['targets']['desks'])
                    : 0;

                $written = count(array_filter($rows, static fn (array $r): bool => $r['cause'] === $round['post_ref']));

                $this->assertSame($resolving, $written,
                    "[{$run}] {$round['post_ref']} wrote {$written} envelopes over {$resolving} destinations that "
                    .'resolve to a desk — A19 travels from the origin desk to EACH destination desk, and a '
                    .'destination that does not resolve gets none');

                $exercised = max($exercised, $resolving);
            }

            // ⛔ THE COUNT MUST HAVE BEEN ABLE TO EXCEED ONE SOMEWHERE, or the equality above is a
            // tautology over a population of `0`s and `1`s and a fan-out bug is invisible to it.
            if ($run === self::FANOUT) {
                $this->assertGreaterThan(1, $exercised,
                    'no post in the fan-out run resolved more than one destination, so the envelope-count '
                    .'assertion is gated by nothing');
            }
        }
    }

    /**
     * GREEN — the three `targets` states are three answers, and the two broadcast rows are told
     * apart by ORIGIN as well as by address.
     */
    public function test_green_the_three_fanout_states_and_the_two_broadcast_rows(): void
    {
        $result = $this->floorRun(self::RUN);
        $model = $this->lastFloor($result)['coord'][self::ROOM];
        $rounds = [];

        foreach ($model['rounds'] as $round) {
            $rounds[$round['post_ref']] = $round;
        }

        // R1 — `targets: null`: *the fan-out is not resolvable here*, never an empty fan-out and
        // never `to`'s address standing in for it. It draws A20's ring and NO A19.
        $this->assertFalse($rounds['R1']['targets']['known']);
        $this->assertSame('the fan-out is not resolvable here', $rounds['R1']['targets']['statement']);
        $this->assertSame(['A20'], $rounds['R1']['animations'],
            'R1 drew an envelope over a fan-out nothing could resolve, or drew no ring over an address that says `all`');

        // R2 — an unresolved origin does not suppress the rest of the object, and fires NEITHER row:
        // both leave the origin desk and this post has none, though two destinations resolve.
        $this->assertSame([], $rounds['R2']['animations'],
            'R2 fired a row although its origin resolves to no desk — a ring or an envelope with nowhere to leave from');
        $this->assertCount(2, $rounds['R2']['targets']['desks'],
            'R2\'s fan-out does not resolve the two desks that make its silence about the ORIGIN');
        $this->assertTrue($rounds['R2']['broadcast'], 'R2\'s address no longer carries `all`, so it says nothing about A20');
        $this->assertTrue($rounds['R2']['targets']['known'], 'R2\'s rest-of-object was suppressed by its unresolved origin');

        // R3 — the same address from an origin that DOES resolve: one ring and one envelope.
        $this->assertSame(['A19', 'A20'], $rounds['R3']['animations']);
        $this->assertSame($rounds['R2']['to'], $rounds['R3']['to'],
            'R2 and R3 no longer carry one address, so the pair no longer holds A19/A20\'s origin precondition apart from it');

        // R4 — `targets: []`: *this post reached nobody*, which is not R1's answer, and
        // `declares_close` renders the close ACT and nothing that reads as convergence.
        $this->assertTrue($rounds['R4']['targets']['known']);
        $this->assertSame('this post reached nobody', $rounds['R4']['targets']['statement']);
        $this->assertNotSame($rounds['R1']['targets']['statement'], $rounds['R4']['targets']['statement'],
            'the two `targets` answers were collapsed into one, which turns an unknown into a measurement');
        $this->assertTrue($rounds['R4']['declares_close']);

        // The rings that were drawn, and the desks they leave from.
        $ringed = array_column($this->rowsFor($result, 'A20'), 'cause');

        $this->assertSame(['R1', 'R3'], $ringed,
            'the broadcast pulses drawn are not the two posts whose address says `all` AND whose origin resolves');
    }

    /** GREEN — the close ends the line: one A18 `left` row, `motion: false`. */
    public function test_green_the_closing_thread_ends_the_line(): void
    {
        $rows = $this->rowsFor($this->floorRun(self::RUN), 'A18');
        $left = array_values(array_filter($rows, static fn (array $r): bool => $r['phase'] === 'left'));

        $this->assertCount(1, $left, 'the closing `coord.thread` did not end the held line');
        $this->assertSame('T1', $left[0]['cause'],
            "the exit row does not carry the `thread_ref` of the `coord.thread` that ENDED the hold");
        $this->assertFalse($left[0]['motion'], 'an exit row claims motion — nothing is drawn by a render that has been left');
        $this->assertSame($rows[0]['episode_id'], $left[0]['episode_id'],
            'the exit row does not pair with the entry by `episode_id`, so *for how long* is unrecoverable');
    }

    /**
     * ⛔ DISCRIMINATING CONTROL — the same messages with NO seat declaring anything: no line is ever
     * drawn, no A18/A19/A20 row is written — **A20 included, and it is the row this control would
     * lose first** — and every desk's `render_state` is exactly § 11's `fx-snapshot-4` row.
     */
    public function test_control_with_no_declarations_nothing_is_drawn_and_no_desk_changes(): void
    {
        $result = $this->floorRun(self::CONTROL);
        $frame = $this->lastFloor($result);
        $model = $frame['coord'][self::ROOM];

        $this->assertSame(0, $model['drawn_lines'], 'a line was drawn with nothing resolved — every endpoint is a guessed desk');

        foreach (['A18', 'A19', 'A20'] as $id) {
            $this->assertSame([], $this->rowsFor($result, $id),
                "a {$id} row was written although no name resolves — the row has no desk to be drawn at");
        }

        // The rings are what this control loses first: all three of R1/R2/R3 still address `all`.
        $this->assertSame(3, count(array_filter($model['rounds'], static fn (array $r): bool => $r['broadcast'] === true)),
            'the control no longer carries three broadcast addresses, so its A20 arm asserts nothing');

        // § 5.7 property 2: no coordination fact touches a desk. The comparison is against the run
        // WITH declarations, so what is held constant is the coordination layer and nothing else.
        $declared = $this->lastFloor($this->floorRun(self::RUN))['desks']['desks'];

        foreach ($frame['desks']['desks'] as $key => $desk) {
            $this->assertSame($declared[$key]['render_state'], $desk['render_state'],
                "[{$key}] a desk's `render_state` moved with the coordination layer");
            $this->assertSame($declared[$key]['badges'], $desk['badges'], "[{$key}] a badge moved with the coordination layer");
            $this->assertSame($declared[$key]['currency_label'], $desk['currency_label'],
                "[{$key}] a currency label moved with the coordination layer");
        }
    }

    /**
     * ⛔ RED — THE GUESSED DESK. Resolve a name to the desk of the same `seat_id` when no seat
     * declares it: the line is drawn to a desk D2 § 8.3.3 rule 3 forbids resolving to, because
     * *equality between an agent name and a `seat_id` remains a coincidence this plane cannot check*.
     */
    public function test_red_a_name_resolved_by_seat_id_equality_draws_a_guessed_desk(): void
    {
        $guessing = $this->mutatedModules([self::COORD_JOIN,
            "    return Object.freeze({ join: Object.freeze(join), reasons: Object.freeze(reasons), checks: Object.freeze(checks) });",
            "    for (const seat of seats) {\n"
            ."        if (join[seat.seat_id] === undefined) {\n"
            ."            join[seat.seat_id] = seat.seat_id;\n"
            ."        }\n    }\n\n"
            ."    return Object.freeze({ join: Object.freeze(join), reasons: Object.freeze(reasons), checks: Object.freeze(checks) });",
        ]);

        $result = $this->floorRun(self::RUN, $guessing);
        $model = $this->lastFloor($result)['coord'][self::ROOM];
        $participants = $this->participants($model);

        $this->assertTrue($participants['aimla-impl-2']['resolved'],
            'the RED did not bite: the `seat_id` fallback was planted and the name still resolved to nothing');
        $this->assertContains('aimla-impl-2',
            $this->floorWithLine($result, self::ROOM)['coord'][self::ROOM]['threads'][0]['endpoints'],
            'the planted fallback did not reach the line, so this RED is red for a reason nobody wrote');

        // And the shipped join resolves it to nothing over the same bytes.
        $shipped = $this->participants($this->lastFloor($this->floorRun(self::RUN))['coord'][self::ROOM]);

        $this->assertFalse($shipped['aimla-impl-2']['resolved'], 'the shipped join resolved a name no seat declared');
    }

    /**
     * ⛔ SECOND RED — `duplicate_declaration` FOLDED INTO `no_declaring_seat`. An install
     * misconfiguration then reads as *no seat may resolve it*, the opposite diagnosis, on a floor an
     * operator would otherwise act on correctly.
     */
    public function test_red_folding_the_duplicate_arm_shows_a_misconfiguration_as_an_absence(): void
    {
        $folded = $this->mutatedModules([self::COORD_JOIN,
            "            reasons[name] = DUPLICATE_DECLARATION;",
            "            reasons[name] = NO_DECLARING_SEAT;",
        ]);

        $model = $this->lastFloor($this->floorRun(self::RUN, $folded))['coord'][self::ROOM];

        $this->assertSame('no_declaring_seat', $model['unresolved_reasons']['helper'] ?? null,
            'the RED did not bite: the duplicate arm was folded and the name still carried its own reason');
        $this->assertSame(
            $model['unresolved_reasons']['reviewer'],
            $model['unresolved_reasons']['helper'],
            'the planted fold did not make the two arms indistinguishable, which is the defect it exists to show',
        );
        $this->assertNull($this->participants($model)['helper']['words'],
            'the folded arm still carried the duplicate sentence, so the render is not the one the fold produces');
    }

    /**
     * ⛔ THIRD RED — THE RING WITH NO DESK TO LEAVE. Fire the broadcast pulse on the strength of the
     * address alone: R2's ring then expands from a desk the post never resolved to, which is § 5.7
     * clause 1's guessed desk arriving through the one animation whose trigger reads an address.
     * ⭐ R3 IS THE CONTROL ON IT — the same address, an origin that resolves, and the ring IS drawn.
     *
     * ⚠ THIS DEFECT WAS LIVE ON `dev` RATHER THAN HYPOTHETICAL: § 6.2 A20's trigger cell gained its
     * *and whose origin resolves to a desk* clause in PR #217 and `roundAnimations()` kept firing on
     * the address alone. The plant below re-mints exactly the line that shipped.
     */
    public function test_red_a_ring_fired_on_the_address_alone_leaves_a_desk_that_resolved_nothing(): void
    {
        $addressOnly = $this->mutatedModules([self::COORD_MODEL,
            "    if (origin && round.broadcast) {",
            "    if (round.broadcast) {",
        ]);

        $result = $this->floorRun(self::RUN, $addressOnly);
        $ringed = array_column($this->rowsFor($result, 'A20'), 'cause');

        $this->assertContains('R2', $ringed,
            'the RED did not bite: the ring was fired on the address alone and R2 still drew none');

        // The control: the shipped set draws R3's ring — same address, resolving origin — so the
        // fix cannot be *never draw a ring*.
        $shipped = array_column($this->rowsFor($this->floorRun(self::RUN), 'A20'), 'cause');

        $this->assertContains('R3', $shipped, 'the shipped set draws no ring for a broadcast whose origin resolves');
        $this->assertNotContains('R2', $shipped, 'the shipped set draws a ring for a broadcast whose origin resolves to nothing');
    }

    /**
     * ⛔ FOURTH RED — THE UNVERIFIED NAME DRAWN AS A VERIFIED ONE. Drop the *unchecked* marker from
     * the `"coder"` endpoint and it renders exactly as the `checked` `"pm"` endpoint: a name nobody
     * checked against any roster is drawn as one that was, on the render an operator would use to
     * decide whether a desk is who it says it is.
     *
     * ⚠ ITS TWIN DEFECT FAILS THE GREEN RATHER THAN THIS RED, which is why the `unchecked`
     * declaration sits on a RESOLVING name: an implementation that required `checked` to resolve
     * would draw no line here at all.
     */
    public function test_red_dropping_the_unchecked_marker_draws_an_unverified_name_as_a_verified_one(): void
    {
        $unmarked = $this->mutatedModules([self::COORD_JOIN,
            "        checks[name] = claim.check;",
            "        checks[name] = 'checked';",
        ]);

        $participants = $this->participants($this->lastFloor($this->floorRun(self::RUN, $unmarked))['coord'][self::ROOM]);

        $this->assertNull($participants['coder']['marker'],
            'the RED did not bite: the marker survived a join that reports every declaration as checked');
        $this->assertSame($participants['pm']['marker'], $participants['coder']['marker'],
            'the two endpoints are still distinguishable, so the planted defect is not the one this RED names');

        // And the twin: requiring `checked` to resolve draws no line at all, which fails the GREEN.
        $strict = $this->mutatedModules([self::COORD_JOIN,
            "const RESOLVING_CHECKS = Object.freeze(['checked', 'unchecked']);",
            "const RESOLVING_CHECKS = Object.freeze(['checked']);",
        ]);

        $strictModel = $this->lastFloor($this->floorRun(self::RUN, $strict))['coord'][self::ROOM];

        $this->assertSame(0, $strictModel['drawn_lines'],
            'requiring `checked` to resolve still drew a line, so the fixture no longer draws between a '
            .'`checked` and an `unchecked` endpoint and the GREEN can no longer catch that defect');
    }

    /**
     * One room's thread participants, keyed by name.
     *
     * @param  array<string, mixed>  $model
     * @return array<string, array<string, mixed>>
     */
    private function participants(array $model): array
    {
        $byName = [];

        foreach ($model['threads'][0]['participants'] as $participant) {
            $byName[$participant['name']] = $participant;
        }

        return $byName;
    }
}
