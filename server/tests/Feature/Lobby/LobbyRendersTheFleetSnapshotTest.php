<?php

namespace Tests\Feature\Lobby;

use Tests\Feature\Feed\FeedTestCase;

/**
 * The lobby (`docs/design/FLOOR.md § 4.1`) driven over a REAL `GET /api/fleet/snapshot` body.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE FLEET IS BUILT BY THE REAL INGEST AND THE REAL FOLD, NOT BY WRITING `seat_state`. That
 * is `FeedTestCase`'s own rule — "a second fixture builder here would let the read plane be
 * tested against seat states the fold cannot produce" — and it buys this file its whole claim:
 * the strings asserted below were rendered from bytes the server actually served.
 *
 * ⭐ THE FIXTURE DISCRIMINATES THE ORDER, WHICH IS WHY IT IS THIS FIXTURE. The snapshot serves
 * `aimla`'s seats in seat_id order — `aimla-impl` (`offline`) BEFORE `aimla-pm` (`idle`) — while
 * § 7.1's fixed member order puts `idle` before `offline`. So a summary built in the order the
 * seats arrived, or in the order the counts were first seen, renders *1 offline · 1 idle* and
 * fails. A fixture whose two orders agreed would have asserted nothing about the order at all.
 *
 * ⚠ WHAT IS NOT EXERCISED HERE, AND CANNOT BE: the page. There is no browser on this host, so
 * no assertion below has been laid out or painted. What is asserted is the FACTS the client
 * computes from the wire. `main.js` puts them into elements; `LobbyPageWiringTest` checks that
 * the elements it addresses exist; nothing checks how any of it looks.
 */
class LobbyRendersTheFleetSnapshotTest extends FeedTestCase
{
    use DrivesTheLobbyClient;

    /**
     * Two installs, three seats, one of them driven through a real turn.
     *
     * @return array<string, mixed> the snapshot body, as served
     */
    private function fleet(): array
    {
        $this->secondSeat('aimla-impl');
        $this->issueToken('sola', 'sola-solo');
        $this->deliver($this->cleanTurn());
        $this->fold();

        return $this->actingAs($this->enrolled())
            ->get('/api/fleet/snapshot')
            ->assertOk()
            ->json();
    }

    public function test_the_lobby_renders_the_snapshots_own_facts_each_from_its_named_d2_member(): void
    {
        $body = $this->fleet();

        // The fixture's discriminating property, asserted rather than assumed — if the wire ever
        // starts serving `aimla-pm` first, the order assertion below stops discriminating and
        // this line is what says so instead of the suite quietly getting weaker.
        $this->assertSame('offline', $body['installs'][0]['seats'][0]['render_state'],
            'the fixture no longer delivers the floor in an order that differs from § 7.1’s');

        $model = $this->probe(['snapshot' => $body])['model'];

        // § 4.1 row 1 — one row per floor, from `installs[].install_id`, ascending.
        $this->assertSame(['aimla', 'sola'], array_column($model['floors'], 'install_id'));
        $this->assertSame(['/floor/aimla', '/floor/sola'], array_column($model['floors'], 'href'),
            'the row is the link to § 4.4’s published floor route');

        // § 4.1 row 2 — a count per `render_state` member present, in § 7.1's fixed order.
        $this->assertSame('1 idle · 1 offline', $model['floors'][0]['summary']);
        $this->assertSame('1 offline', $model['floors'][1]['summary']);

        // …and the order is § 7.1's, re-derived from the document rather than read off the line
        // above. The two assertions are different claims: one pins the string, this one pins
        // WHERE the string's order came from.
        $this->assertOrderedByTheDocument($model['floors'][0]['summary']);

        // § 4.1 row 3 — `fleet.seats_total` / `fleet.seats_live`, read from the wire.
        $this->assertSame(
            $body['fleet']['seats_total'].' seats · '.$body['fleet']['seats_live'].' live',
            $model['totals'],
        );
        $this->assertSame('3 seats · 1 live', $model['totals']);

        // …and they are NOT the held count, which on this fixture is a different number in one
        // of the two positions. A recount would read "3 seats · 3 live".
        $this->assertSame(3, $model['held']);
        $this->assertStringNotContainsString('3 live', $model['totals'],
            'the live count was recounted from the desks — `seats_live` is 1 on this fixture');

        // § 4.1 row 4 — no discrepancy on an intact snapshot. AT-D3-15's discriminating control.
        $this->assertNull($model['discrepancy']);

        // § 4.1 row 5 / § 2.3 — the membership stamp, from this response's own `server_time`.
        $this->assertSame('membership as of '.substr((string) $body['server_time'], 11, 8), $model['stamp']);
        $this->assertSame('membership as of 12:00:03', $model['stamp']);

        // § 4.1 row 6 / § 5.3 — three indicators plus ingest recency, each naming one member and
        // NEVER ONE AGGREGATE (D2 § 8.2.4: "the wire keeps them apart").
        $this->assertSame(['store', 'derivation', 'sweep', 'ingest'], array_column($model['indicators'], 'key'));
        $this->assertSame(
            ['fleet.db', 'fleet.fold', 'fleet.sweep', 'fleet.ingest_last_receipt_at'],
            array_column($model['indicators'], 'member'),
            'an indicator stopped naming exactly one D2 member, which is where an aggregate starts',
        );

        $byKey = array_column($model['indicators'], null, 'key');
        $this->assertSame('ok', $byKey['store']['value']);
        $this->assertSame('ok', $byKey['derivation']['value']);
        $this->assertSame($body['fleet']['sweep'], $byKey['sweep']['value']);
        $this->assertSame('stalled', $byKey['sweep']['value']);
        $this->assertSame('last receipt 12:00:00', $byKey['ingest']['value']);

        // ⛔ `fleet.max_fold_lag_ms` IS DELIBERATELY NOT RENDERED — its published form is the
        // fleet banner's (§ 2.4, § 7.4), which belongs to the FLOOR's status strip. A second
        // rendering of one fact on a second surface is what § 2.4's one-form-per-fact rule
        // forbids, so the lobby must carry no trace of it.
        $rendered = (string) json_encode($model);
        $this->assertStringNotContainsString('max_fold_lag', $rendered);
        $this->assertStringNotContainsString('behind', $rendered,
            '§ 2.4’s derivation-lag string reached the lobby');

        // ⛔ AND NO DURATION OF ANY KIND — card#9209: D3 publishes no duration format, so there
        // is no honest way to render one. Every shape § 7.1's own disagreeing exemplars take.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*m\s+\d+\s*s/', $rendered);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*h\s+\d+\s*m/', $rendered);
        $this->assertStringNotContainsString(' ago', $rendered);
    }

    /**
     * AT-D3-15 — the lobby never invents a count, in BOTH directions, with § 4.1's
     * one-fetch-per-distinct-`(N, M)` budget.
     */
    public function test_the_lobby_renders_the_disagreement_and_never_picks_a_winner(): void
    {
        $body = $this->fleet();

        // N < M — a seat dropped from the client's map with no snapshot, which is AT-D3-15's own
        // BUILD ("simulating a missed insert").
        $short = $body;
        array_shift($short['installs'][0]['seats']);
        $model = $this->probe(['snapshot' => $short])['model'];

        $this->assertSame(2, $model['held']);
        $this->assertSame('the client holds 2 of 3 seats — refreshing', $model['discrepancy']);
        $this->assertSame('3 seats · 1 live', $model['totals'],
            'the totals moved with the held count — they are the wire’s and must not');

        // N > M — reachable whenever a client missed a `seat.retired` announcement. A test built
        // only on N < M leaves the wording *N of M*, which reads as a subset, unexercised on the
        // direction where it is false.
        $lowered = $body;
        $lowered['fleet']['seats_total'] = 2;
        $model = $this->probe(['snapshot' => $lowered])['model'];

        $this->assertSame('the client holds 3 seats; the fleet reports 2 — refreshing', $model['discrepancy']);
        $this->assertSame('2 seats · 1 live', $model['totals'],
            'the lobby stopped rendering the wire’s number once it disagreed — which is picking a winner');

        // § 4.1's budget: one fetch per DISTINCT (N, M), never a poll. The repeated pair is
        // AT-D3-15's "second, identical heartbeat".
        $budget = $this->probe(['observations' => [[2, 3], [2, 3], [3, 2], [3, 3]]])['budget'];

        $this->assertSame([true, false, true, false], $budget['admitted']);
        $this->assertSame(2, $budget['spent']);
    }

    /**
     * § 5.4 / AT-D3-11 / AT-D3-15 — a member this client does not know is counted on its own
     * floor, named as unrecognised, and carries the raw string.
     *
     * ⚠ THE VALUE IS INJECTED INTO THE BODY, AND IT HAS TO BE. `seat_state.render_state` is a
     * DB `ENUM`, so this store cannot mint an unrecognised member; the reachable path is a
     * rolling upgrade in which the server is ahead of the browser's cached client. Everything
     * else in this file drives a real body; this test drives a real body with one field moved.
     */
    public function test_an_unrecognised_member_is_counted_and_named_rather_than_dropped(): void
    {
        $body = $this->fleet();
        $body['installs'][0]['seats'][0]['render_state'] = 'pondering';

        $probe = $this->probe(['snapshot' => $body, 'membership_probe' => ['pondering', 'idle']]);

        $this->assertFalse($probe['membership']['pondering'], 'the client claims to know `pondering`');
        $this->assertTrue($probe['membership']['idle']);

        $summary = $probe['model']['floors'][0]['summary'];

        $this->assertSame('1 idle · 1 unrecognised (pondering)', $summary);
        $this->assertSame(2, $this->summaryTotal($summary),
            'the floor holds two seats and the summary counted fewer — iterating § 7.1’s order is a FILTER');
        $this->assertStringContainsString('pondering', $summary, '§ 5.4: the raw string is on the line');
    }

    /** § 5.6 / decision 13 — a null renders as *not reported*, and never as a zero. */
    public function test_a_null_fleet_timestamp_reads_not_reported_and_never_a_zero(): void
    {
        $body = $this->fleet();

        // Not injected: the sweeper has never run on this fixture, so D2 § 8.2.4's "null before
        // the sweeper's first pass" is what the server actually served.
        $this->assertNull($body['fleet']['sweep_last_run_at']);

        $model = $this->probe(['snapshot' => $body])['model'];
        $sweep = array_column($model['indicators'], null, 'key')['sweep'];

        $this->assertSame('last run not reported', $sweep['detail']);
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $sweep['detail'],
            'a null timestamp was coalesced to a clock reading — a measurement nobody made');
        $this->assertSame(
            ['store: ok', 'derivation: ok', 'sweep: stalled · last run not reported', 'ingest: last receipt 12:00:00'],
            array_map(
                fn (array $i) => $i['label'].': '.$i['value'].($i['detail'] === null ? '' : ' · '.$i['detail']),
                $model['indicators'],
            ),
            'a rendered indicator carries a placeholder, a zero, or the word "null"',
        );
    }

    /** § 5.3 / § 9 F4 — `db: "down"` is a full statement, not a red dot. */
    public function test_a_store_that_is_down_renders_a_statement_and_never_a_calm_lobby(): void
    {
        $body = $this->fleet();
        // D2 § 8.2.4 declares `db: "down"` reachable ON THIS OBJECT, and `App\Read\FleetHealth`
        // serves it with the five store-derived members ABSENT rather than defaulted.
        $body['fleet'] = ['db' => 'down'];

        $model = $this->probe(['snapshot' => $body])['model'];

        $this->assertSame(
            'fleet state is unavailable — the store could not be read at 12:00:03',
            $model['store_unavailable'],
        );
        $this->assertSame('down', array_column($model['indicators'], null, 'key')['store']['value']);
        $this->assertSame('not reported seats · not reported live', $model['totals'],
            'an absent count was rendered as a zero, which says "nothing has happened"');
        $this->assertNull($model['discrepancy'],
            'a disagreement was rendered against a total the wire did not send');
    }

    /**
     * ⛔ THE CONTROLS. Each re-mints a real defect in a copy of the SHIPPED module and names the
     * assertion above that must catch it.
     */
    public function test_the_lobby_checks_go_red_against_each_defect_they_exist_to_catch(): void
    {
        $body = $this->fleet();

        // CONTROL 5 — AT-D3-15's own RED: "compute the fleet counts by counting desks". It has
        // to be planted in `lobbyModel`, because `fleetTotals` is not given the installs at all
        // — the defect is unwriteable at the site that renders the number, which is the point.
        $recounting = $this->mutatedModules([
            'lobby-model.js',
            'totals: fleetTotals(snapshot?.fleet),',
            'totals: `${held} seats · ${held} live`,',
        ]);
        $short = $body;
        array_shift($short['installs'][0]['seats']);

        $this->assertSame('2 seats · 2 live', $this->probe(['snapshot' => $short], $recounting)['model']['totals'],
            'CONTROL 5 did not bite: the recount was planted and the totals did not move, so the '
            .'never-recounted assertion is not measuring where the number comes from');

        // CONTROL 6 — the budget as a poll. The second identical observation must stop being
        // refused, which is the "one fetch per distinct (N, M), and not a poll" property.
        $polling = $this->mutatedModules([
            'lobby-model.js',
            "        if (this.#spent.has(key)) {\n            return false;\n        }",
            '        // control: the memory removed',
        ]);

        $this->assertSame([true, true], $this->probe(['observations' => [[2, 3], [2, 3]]], $polling)['budget']['admitted'],
            'CONTROL 6 did not bite: the budget’s memory was removed and it still refused the '
            .'repeat, so the distinct-pair assertion is not measuring the memory');

        // CONTROL 7 — the lobby summary's SILENT filter, which is the defect that has no throw
        // and no glyph: a seat in no member set simply falls out of its own floor's count.
        $filtered = $this->mutatedModules([
            'lobby-model.js',
            '        .concat(unheard.map((v) => `${seen[String(v)]} unrecognised (${String(v)})`))',
            '',
        ]);
        $unrecognised = $body;
        $unrecognised['installs'][0]['seats'][0]['render_state'] = 'pondering';
        $summary = $this->probe(['snapshot' => $unrecognised], $filtered)['model']['floors'][0]['summary'];

        $this->assertSame(1, $this->summaryTotal($summary),
            'CONTROL 7 did not bite: the remainder was deleted and the floor still counted both '
            .'seats, so the counts-every-seat assertion is not measuring the filter');
        $this->assertStringNotContainsString('pondering', $summary);

        // CONTROL 8 — the clean zero: a null timestamp coalesced to a zero clock. This is
        // `docs/KANBAN.md § G-1`'s shape and AT-D3-14's RED.
        $zeroing = $this->mutatedModules([
            'lobby-model.js',
            "    if (typeof wireTime !== 'string') {\n        return null;\n    }",
            "    if (typeof wireTime !== 'string') {\n        return '00:00:00';\n    }",
        ]);
        $model = $this->probe(['snapshot' => $body], $zeroing)['model'];

        $this->assertSame('last run 00:00:00', array_column($model['indicators'], null, 'key')['sweep']['detail'],
            'CONTROL 8 did not bite: the null coalesce was planted and the sweep detail still '
            .'read *not reported*, so the null assertion is not measuring the coalesce');
    }

    /**
     * Every count on a summary line, summed — the "did the floor lose a seat" measurement, which
     * is the one thing a member-by-member comparison cannot see.
     */
    private function summaryTotal(string $summary): int
    {
        preg_match_all('/(?:^|· )(\d+) /', $summary, $m);

        return array_sum(array_map('intval', $m[1]));
    }

    /**
     * The members named on a summary line appear in § 7.1's order — re-derived from `FLOOR.md`,
     * never from a list written here.
     */
    private function assertOrderedByTheDocument(string $summary): void
    {
        $order = $this->documentMembers();

        $this->assertCount(10, $order, 'the § 7.1 parser stopped reading the document');

        $positions = [];

        foreach (explode(' · ', $summary) as $part) {
            $member = preg_replace('/^\d+ /', '', $part);
            $index = array_search($member, $order, true);

            if ($index !== false) {
                $positions[] = $index;
            }
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertNotEmpty($positions, 'no § 7.1 member was found on the summary line');
        $this->assertSame($sorted, $positions,
            'the summary’s members are not in § 7.1’s fixed order (§ 4.1)');
    }
}
