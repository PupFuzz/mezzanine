<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-15 — the lobby never invents a count.** `docs/design/FLOOR.md § 11`, gated at Appendix B
 * **step 9**, the lobby's own row. card#7341 step 9.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE HARNESS DRIVES THE SHIPPED LOBBY OVER THE SHIPPED CLIENT PROTOCOL. Every run below is
 * `fx-snapshot-4` replayed through `fleet-client-probe.mjs` with `lobby: true`, which starts
 * `lobby/lobby-screen.js` over the same `FleetClient` and records every frame it renders — so the
 * words are read as the lobby rendered them, and the fetches as the protocol issued them. The Build
 * bullet's *drop one seat from the client's map without a snapshot* is `lobby_short`'s connect
 * snapshot predating `aimla-review`: the client holds three desks while every snapshot it is served
 * agrees with itself, which is what `EveryFixtureSeatMatchesThePublishedSeatObjectTest` holds every
 * fixture to.
 *
 * ⛔ ONE TRIGGER, AND IT IS THE PROTOCOL'S (Appendix B row 9). The lobby used to fetch a snapshot of
 * its own for § 4.1's disagreement beside the protocol's; the double fetch that made is a RED here.
 *
 * ⚠ `lobby_over`'s pair STANDS after its one fetch at this step, because removing a desk on a full
 * snapshot's absence is Appendix B step 10's backstop (§ 2.3 row 4) and is not built. § 4.1 says
 * what the lobby owes that case — "a disagreement still standing after that fetch is rendered and
 * not re-fetched" — and that is what the N > M GREEN asserts. When step 10 lands, the discovery will
 * resolve that pair, and the re-fetch RED below will stop biting on this run: the control's own
 * assertion is what will say so.
 */
class TheLobbyNeverInventsACountTest extends TestCase
{
    use DrivesTheFleetClientModule;

    private const SHORT = 'showing 3 of 4 desks — one desk could not be read';

    private const OVER = 'showing 4 desks — the building lists 3';

    /** RED — the doc's own: "compute the fleet counts by counting desks". */
    private const RECOUNT = ['../lobby/lobby-model.js',
        'totals: fleetTotals(snapshot?.fleet),',
        'totals: `${held} seats · ${held} live`,'];

    /** RED — the lobby picks a winner: the disagreement is never worded, so the floors' count stands unchallenged. */
    private const WINNER = ['../lobby/lobby-screen.js',
        'discrepancy: feed.applied && state !== null ? discrepancyNotice(state.held, state.total) : null,',
        'discrepancy: null,'];

    /** RED — § 4.1's silence dropped: the words are rendered against a client that holds no population. */
    private const UNSILENCED = ['../lobby/lobby-screen.js',
        'discrepancy: feed.applied && state !== null ? discrepancyNotice(state.held, state.total) : null,',
        'discrepancy: state !== null ? discrepancyNotice(state.held, state.total) : null,'];

    /** RED — the lobby's own trigger back beside the protocol's: one disagreement, two fetches. */
    private const OWN_TRIGGER = ['../lobby/lobby-screen.js',
        "        return this.draw(cab);\n    }",
        "        const frame = this.draw(cab);\n\n        if (frame.discrepancy !== null && this.ownTrigger !== true) {\n            this.ownTrigger = true;\n            await this.#client.refresh();\n        }\n\n        return frame;\n    }"];

    /** RED — the budget as a poll: its memory removed, so a repeated pair is fetched again. */
    private const POLL = ['../wire/discrepancy-budget.js',
        "        if (this.#spent.has(key)) {\n            return false;\n        }",
        '        // control: the memory removed'];

    /** RED — the record rendered as nothing: the lobby draws no event log. */
    private const NO_LOG = ['../lobby/lobby-screen.js',
        'event_log: client.eventLog,',
        'event_log: [],'];

    public function test_green_n_below_m_renders_the_shortfall_fetches_once_and_then_agrees(): void
    {
        $this->assertSame([], $this->shortDefects($this->replay('lobby_short')));
    }

    public function test_green_n_above_m_renders_the_buildings_count_and_the_second_identical_heartbeat_fetches_nothing(): void
    {
        $this->assertSame([], $this->overDefects($this->replay('lobby_over')));
    }

    /** The discriminating control: the intact fixture renders no discrepancy notice and issues no fetch. */
    public function test_control_the_intact_fixture_renders_no_notice_and_fetches_nothing(): void
    {
        $result = $this->replay('lobby_intact');

        $this->assertSame(['/api/fleet/snapshot'], $this->snapshotRequests($result),
            'the intact fixture issued a discovery fetch');
        $this->assertNotSame([], $this->framesWithSummary($result), 'the lobby rendered nothing to check');

        foreach ($result['lobby_renders'] as $render) {
            $this->assertNull($render['frame']['discrepancy'], "at {$render['at']} the intact fixture rendered a notice");
        }

        $this->assertSame('4 seats · 4 live', $this->frameAt($result, 16000)['summary']['totals']);
    }

    /** § 4.1: "A fetch that fails … spends nothing, so the next heartbeat retries the same (N, M)". */
    public function test_green_a_failed_discovery_spends_nothing_and_the_next_heartbeat_retries_it(): void
    {
        $this->assertSame([], $this->refundDefects($this->replay('lobby_short_fails')));
    }

    /**
     * § 4.1: "one such fetch is in flight at a time, a `fleet{}` admitted meanwhile being evaluated
     * when it ends". The meanwhile heartbeat carries a pair the budget has never spent, so the
     * in-flight rule is the only thing that stops it.
     */
    public function test_green_one_discovery_is_in_flight_at_a_time(): void
    {
        $this->assertSame([], $this->inFlightDefects($this->replay('lobby_short_inflight')));
    }

    /**
     * § 4.1's silence: "once no check can EVER run for it, because the initial snapshot itself failed
     * and the client has not been re-run … this line does not need a wording of its own for that
     * case, only silence."
     */
    public function test_green_no_words_while_no_check_can_ever_run(): void
    {
        $this->assertSame([], $this->silenceDefects($this->replay('lobby_cold_silent')));
    }

    /**
     * Appendix B row 9: the lobby renders the record's *membership changes* lines for a seat or an
     * install a discovery fetch adds, which the client protocol writes.
     */
    public function test_green_the_lobby_renders_the_membership_lines_a_discovery_writes(): void
    {
        $this->assertSame([], $this->membershipDefects($this->replay('lobby_discovers_room')));
    }

    /** The doc's RED: "compute the fleet counts by counting desks → the lobby confidently reports 3 seats on a 4-seat fleet". */
    public function test_red_the_fleet_counts_computed_by_counting_desks_are_caught(): void
    {
        $defects = $this->shortDefects($this->replay('lobby_short', $this->mutatedModules(self::RECOUNT)));

        $this->assertArrayHasKey('totals', $defects, 'the recount RED did not bite: '.json_encode($defects));
    }

    public function test_red_the_lobby_picking_a_winner_is_caught(): void
    {
        $short = $this->shortDefects($this->replay('lobby_short', $this->mutatedModules(self::WINNER)));
        $over = $this->overDefects($this->replay('lobby_over', $this->mutatedModules(self::WINNER)));

        $this->assertArrayHasKey('notice', $short, 'the winner RED did not bite on N < M: '.json_encode($short));
        $this->assertArrayHasKey('notice', $over, 'the winner RED did not bite on N > M: '.json_encode($over));
    }

    public function test_red_a_refetch_on_the_same_pair_is_caught(): void
    {
        $defects = $this->overDefects($this->replay('lobby_over', $this->mutatedModules(self::POLL), [], true));

        $this->assertArrayHasKey('fetches', $defects,
            'the budget-as-a-poll RED did not bite — if Appendix B step 10 has landed, the discovery now '
            .'resolves this pair and this run no longer holds a standing one: '.json_encode($defects));
    }

    public function test_red_a_failed_fetch_that_spends_the_pair_is_caught(): void
    {
        $defects = $this->refundDefects($this->replay('lobby_short_fails', $this->plantedClient(FleetClientPlants::SPEND[0])));

        $this->assertArrayHasKey('fetches', $defects, 'the spend RED did not bite: '.json_encode($defects));
    }

    public function test_red_a_second_discovery_while_one_is_in_flight_is_caught(): void
    {
        $defects = $this->inFlightDefects($this->replay('lobby_short_inflight', $this->plantedClient(FleetClientPlants::OVERLAP[0]), [], true));

        $this->assertArrayHasKey('fetches', $defects, 'the overlap RED did not bite: '.json_encode($defects));
    }

    public function test_red_the_lobbys_own_trigger_beside_the_protocols_is_caught(): void
    {
        $defects = $this->shortDefects($this->replay('lobby_short', $this->mutatedModules(self::OWN_TRIGGER), [], true));

        $this->assertArrayHasKey('fetches', $defects, 'the double-trigger RED did not bite: '.json_encode($defects));
    }

    public function test_red_words_where_no_check_can_run_are_caught(): void
    {
        $defects = $this->silenceDefects($this->replay('lobby_cold_silent', $this->mutatedModules(self::UNSILENCED)));

        $this->assertArrayHasKey('silence', $defects, 'the unsilenced RED did not bite: '.json_encode($defects));
    }

    public function test_red_a_membership_line_not_rendered_is_caught(): void
    {
        $unwritten = $this->membershipDefects($this->replay('lobby_discovers_room', $this->plantedClient(FleetClientPlants::NOMEMBERSHIPLINE[0])));
        $undrawn = $this->membershipDefects($this->replay('lobby_discovers_room', $this->mutatedModules(self::NO_LOG)));

        $this->assertArrayHasKey('lines', $unwritten, 'the unwritten-line RED did not bite: '.json_encode($unwritten));
        $this->assertArrayHasKey('lines', $undrawn, 'the undrawn-log RED did not bite: '.json_encode($undrawn));
    }

    /**
     * N < M — the GREEN's four claims, as defects: the words, the totals verbatim, the summary a
     * count of HELD seats, and one fetch after which they agree.
     *
     * @return array<string, string>
     */
    private function shortDefects(array $result): array
    {
        $defects = [];
        $at = $this->frameAt($result, 1000);

        if ($at['discrepancy'] !== self::SHORT) {
            $defects['notice'] = 'at the heartbeat the lobby rendered '.json_encode($at['discrepancy']);
        }

        // § 4.1 row 3: `fleet.seats_total` / `fleet.seats_live` VERBATIM — the heartbeat's 4 and 4.
        if ($at['summary']['totals'] !== '4 seats · 4 live') {
            $defects['totals'] = "the totals read `{$at['summary']['totals']}` against a heartbeat reporting 4 seats · 4 live";
        }

        // § 2.1 row 5: the summary counts the seats the client HOLDS, which is three here.
        if ($this->summaryCount($at) !== 3) {
            $defects['summary'] = 'the per-floor summary counts '.$this->summaryCount($at).' seats while the client holds 3';
        }

        $fetches = count($this->snapshotRequests($result));

        if ($fetches !== 2) {
            $defects['fetches'] = "{$fetches} snapshot requests — the connect read and ONE discovery were expected";
        }

        $final = $this->lastFrame($result);

        if ($final['discrepancy'] !== null || $this->summaryCount($final) !== 4) {
            $defects['agree'] = 'after the fetch the counts do not agree: '.json_encode($final['discrepancy']);
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function overDefects(array $result): array
    {
        $defects = [];
        $at = $this->frameAt($result, 1000);

        if ($at['discrepancy'] !== self::OVER) {
            $defects['notice'] = 'at the heartbeat the lobby rendered '.json_encode($at['discrepancy']);
        }

        if ($at['summary']['totals'] !== '3 seats · 3 live') {
            $defects['totals'] = "the totals read `{$at['summary']['totals']}` against a heartbeat reporting 3 seats · 3 live";
        }

        $fetches = count($this->snapshotRequests($result));

        if ($fetches !== 2) {
            $defects['fetches'] = "{$fetches} snapshot requests — the second identical heartbeat must issue none";
        }

        // "a disagreement still standing after that fetch is rendered and NOT re-fetched" (§ 4.1).
        if ($this->frameAt($result, 16000)['discrepancy'] !== self::OVER) {
            $defects['standing'] = 'the standing disagreement stopped being rendered at the second heartbeat';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function refundDefects(array $result): array
    {
        $defects = [];
        $fetches = count($this->snapshotRequests($result));

        if ($fetches !== 3) {
            $defects['fetches'] = "{$fetches} snapshot requests — the connect read, the failed discovery and its retry were expected";
        }

        if ($this->lastFrame($result)['discrepancy'] !== null) {
            $defects['agree'] = 'the retried discovery did not resolve the disagreement';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function inFlightDefects(array $result): array
    {
        $defects = [];
        $fetches = count($this->snapshotRequests($result));

        if ($fetches !== 2) {
            $defects['fetches'] = "{$fetches} snapshot requests — a heartbeat during the discovery must issue none";
        }

        // A pair the budget never spent, rendered with § 4.1's count agreement — and not fetched.
        if ($this->frameAt($result, 16000)['discrepancy'] !== 'showing 3 of 5 desks — two desks could not be read') {
            $defects['notice'] = 'while the discovery was in flight the new pair was not rendered: '
                .json_encode($this->frameAt($result, 16000)['discrepancy']);
        }

        if ($this->lastFrame($result)['discrepancy'] !== null) {
            $defects['agree'] = 'the discovery that landed did not resolve the disagreement';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function silenceDefects(array $result): array
    {
        $defects = [];

        // The premise, asserted rather than assumed: the PROTOCOL still reports a disagreement — a
        // client holding nothing against a heartbeat's four — so a silent line is the lobby's choice.
        $this->assertSame(['held' => 0, 'total' => 4, 'refreshing' => false], $result['final']['discrepancy_state'],
            'the run no longer holds the pair the silence is about');
        $this->assertSame('snapshot-failed', $result['final']['phase']);

        foreach ($result['lobby_renders'] as $render) {
            if ($render['frame']['discrepancy'] !== null) {
                $defects['silence'] ??= "at {$render['at']} the lobby rendered `{$render['frame']['discrepancy']}` for a client holding no population";
            }
        }

        // No population is drawn either: the page keeps its *waiting* placeholders rather than a
        // building — § 9 F4's "never an empty office" on the one screen that counts desks.
        if (array_filter($result['lobby_renders'], static fn (array $r): bool => $r['frame']['summary'] !== null) !== []) {
            $defects['summary'] = 'the lobby drew a building for a client that holds no population';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function membershipDefects(array $result): array
    {
        $defects = [];
        $log = $this->lastFrame($result)['event_log'];
        $wanted = ['room added to the building: aimla-win', 'seat added to the floor: aimla-win/win-1', 'seat added to the floor: aimla-win/win-2'];

        foreach ($wanted as $line) {
            if (array_filter($log, static fn (string $l): bool => str_ends_with($l, ' '.$line)) === []) {
                $defects['lines'] ??= "the lobby's event log carries no `{$line}`";
            }
        }

        // The discovery's own rows for the seats the client already held are not arrivals.
        if (array_filter($log, static fn (string $l): bool => str_contains($l, 'aimla/')) !== []) {
            $defects['spurious'] = 'a seat the client already held was narrated as an arrival';
        }

        // ONE discovery, and the lobby fetched no snapshot of its own for it.
        if (count($this->snapshotRequests($result)) !== 2) {
            $defects['fetches'] = count($this->snapshotRequests($result)).' snapshot requests — one discovery was expected';
        }

        return $defects;
    }

    /**
     * The LAST lobby frame rendered at an instant.
     *
     * @return array<string, mixed>
     */
    private function frameAt(array $result, int $at): array
    {
        $frames = array_values(array_filter($result['lobby_renders'], static fn (array $r): bool => $r['at'] === $at));

        $this->assertNotSame([], $frames, "the lobby rendered nothing at t={$at}");

        return $frames[count($frames) - 1]['frame'];
    }

    /** @return array<string, mixed> */
    private function lastFrame(array $result): array
    {
        $this->assertNotSame([], $result['lobby_renders'], 'the lobby rendered nothing');

        return $result['lobby_renders'][count($result['lobby_renders']) - 1]['frame'];
    }

    /** @return list<array<string, mixed>> */
    private function framesWithSummary(array $result): array
    {
        return array_values(array_filter($result['lobby_renders'], static fn (array $r): bool => $r['frame']['summary'] !== null));
    }

    /** Every count on every floor's summary line, summed — the held seats the lobby claims. */
    private function summaryCount(array $frame): int
    {
        $total = 0;

        foreach ($frame['summary']['floors'] as $floor) {
            preg_match_all('/(?:^|· )(\d+) /', $floor['summary'], $m);
            $total += array_sum(array_map('intval', $m[1]));
        }

        return $total;
    }
}
