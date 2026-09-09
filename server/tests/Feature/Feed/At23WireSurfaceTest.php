<?php

namespace Tests\Feature\Feed;

use App\Events\SeatRetired;
use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * **AT-D2-23 — a retired seat's desk goes IMMEDIATELY, and only ever because it was ANNOUNCED.**
 * (`docs/design/FLEET-STATE.md § 11`, § 4.10, § 8.3.)
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS TEST WAS REWRITTEN, NOT DELETED, AND THE BEHAVIOUR IT USED TO PIN IS RECORDED HERE.
 * card#9078, on an **operator ruling** that reverses § 4.10's fourteen-day render window and
 * FLOOR.md § 3.5's lingering nameplate. Verbatim: *"An absence and removal are very obviously
 * different because if an agent is not reporting, it is assumed to be an absence. A removal is a
 * deliberate action by the operator. When an agent is removed, its seat and desk should go away
 * immediately."*
 *
 * Until it, this file's headline arm was named *"the next snapshot STILL carries the seat"* and
 * its RED was the vanishing desk. Both halves inverted, and an acceptance test that vanished with
 * the behaviour it pinned would leave no record that the rule was ever considered — so the old
 * assertions are stated here in terms rather than dropped: the seat used to stay in the snapshot
 * for `Purge::RETENTION_DAYS` with `render_state: "retired"` and a populated `retired` object,
 * and `fleet.seats_total` used to keep counting it.
 *
 * ⚠ THE ARGUMENT THAT FAILED IS WORTH AS MUCH AS THE RULING, because it is the one a maintainer
 * will re-derive. It was that the desk must LINGER so *"we removed it"* stays distinguishable from
 * *"it went quiet"*. That is false on the design's own render table: a seat that goes quiet is
 * **visibly present and degraded** — `stale` at 300 s, `offline` at 900 s — so a removed seat
 * being GONE is maximally different from it. `test_a_seat_that_merely_went_quiet_keeps_its_desk`
 * below is that render table, asserted, in the same file as the removal it is contrasted with.
 *
 * ⛔ THE ONE PROPERTY THAT SURVIVED THE REVERSAL, AND THE ARM THAT PROVES IT. Removal is driven by
 * the EXPLICIT retirement, **never by absence** — not from a delta, not from a poll, not from a
 * scoped read, and not from silence. § 4.10's first sentence is untouched: "nothing else — no
 * timeout, no purge, no silence — ever removes a row from the fleet."
 *
 * ⚠ WHAT THIS FILE STRUCTURALLY CANNOT COVER: the CLIENT half. The removal-on-absence the design
 * refuses is a client behaviour (`FLOOR.md § 2.3`'s last row — remove only on a full snapshot
 * apply, never on a delta), and there is no client in this repository to drive. What is assertable
 * here is the SERVER half of the same rule: no amount of silence, staleness or sweeping makes a
 * seat leave a read surface, and the only thing that does is the announced act.
 *
 * `Tests\Feature\Fold\At23RetiredSeatTest` owns the store half — the act, its transaction, the
 * `cause: operator` row, the columns-without-the-command RED, the axes deriving underneath, the
 * row surviving the purge. Both halves together are AT-D2-23.
 */
class At23WireSurfaceTest extends FeedTestCase
{
    /**
     * GREEN — "connected clients receive `seat.retired`", on § 8.3's channel with § 8.3's payload.
     *
     * UNCHANGED BY card#9078, and that is the point rather than an accident: the ruling made the
     * announcement the ONLY removal path, so the announcement itself had to stay exactly as it
     * was — same transaction, same version, same payload.
     */
    public function test_retiring_publishes_seat_retired_and_the_delta_at_the_same_version(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->wire->forget();

        $this->retire();

        $retired = $this->wire->ofType('seat.retired');
        $this->assertCount(1, $retired, 'no seat.retired reached the wire');

        // § 8.3's channel: `private-fleet.{install_id}`, one per install.
        $this->assertSame(['private-fleet.'.self::INSTALL], $retired[0]['channels']);

        // § 8.3's envelope, on every message.
        $this->assertSame(1, $retired[0]['payload']['feed_version']);
        $this->assertSame('seat.retired', $retired[0]['payload']['t']);
        $this->assertArrayHasKey('server_time', $retired[0]['payload']);

        // § 8.3's declared payload for this row: install_id, seat_id, reason, at.
        $this->assertSame(self::INSTALL, $retired[0]['payload']['install_id']);
        $this->assertSame(self::SEAT, $retired[0]['payload']['seat_id']);
        $this->assertSame('decommissioned', $retired[0]['payload']['reason']);
        $this->assertNotNull($retired[0]['payload']['at']);

        // § 4.10: "the `seat.retired` feed message AND THE DELTA carrying `render_state:
        // "retired"`, both published by the retirement act in the transaction that sets the
        // columns". Both, at one version — which is what lets a consumer see it has both.
        //
        // ⛔ THE DELTA STILL CARRIES THE SEAT card#9078 JUST REMOVED FROM THE READ SURFACES, and
        // that is deliberate: this pair IS the announcement, and a client is told what left and
        // why. The read filter answers "which desks are on the floor"; it does not censor the
        // message that says one went.
        $deltas = $this->wire->deltasFor(self::INSTALL, self::SEAT);
        $this->assertCount(1, $deltas, 'retirement published no delta');
        $this->assertSame('retired', $deltas[0]['payload']['patch']->render_state);
        $this->assertContains('render_state', $deltas[0]['payload']['changed']);
        $this->assertSame(
            $retired[0]['payload']['state_version'],
            $deltas[0]['payload']['state_version'],
            '§ 8.5: the two announcements of one transaction sit at different versions',
        );
    }

    /**
     * ⛔ THE PRIMARY GREEN, INVERTED BY THE RULING: **the desk is gone on the next render**, and
     * the record it used to carry is still answerable.
     *
     * The mutation that drives it is `App\Read\RetirementFilter::renderable()` widened back to a
     * window (or to nothing) — one line, one home, five read sites.
     */
    public function test_the_desk_goes_at_the_announcement_and_the_record_survives_it(): void
    {
        $this->secondSeat();

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $before = $this->snapshotSeats();
        $this->assertArrayHasKey(self::SEAT, $before, 'the seat was not on the floor to begin with');

        $this->retire();

        $body = $this->snapshot();
        $seats = $this->index($body);

        // 1 — GONE. Not "cleared", not "stamped retired": absent from the rendered population, in
        // the same transaction that announced it.
        $this->assertArrayNotHasKey(self::SEAT, $seats,
            'the retired desk is still on the floor — card#9078: a removal goes immediately');

        // 2 — AND `seats_total` WENT WITH IT, in the same read. § 8.2.4's population and the seat
        // list are one population by construction (`RetirementFilter`, one home); two numbers
        // moving on different days is the disagreement that class exists to prevent.
        $this->assertSame(1, $body['fleet']['seats_total']);

        // 3 — …AND IT WAS A READ FILTER, NOT A DELETION. § 4.10: "`seats` is retained forever; an
        // operator query can still find the row and its reason." Both halves, because a DELETE
        // would satisfy assertion 1 on its own — which is precisely why the ruling could have been
        // implemented wrongly and looked right.
        $row = DB::table('seats')->where('id', $this->seatRef)->first();
        $this->assertNotNull($row, 'the disappearance was a DELETION, not a read filter');
        $this->assertSame('operator@aimla', $row->retired_by);
        $this->assertSame('decommissioned', $row->retired_reason);
        $this->assertNotNull($row->retired_at);

        // 4 — every seat-scoped read surface agrees with the floor. § 4.10: "the READ QUERIES stop
        // selecting it" — plural. A desk gone from the floor and reachable by URL is the same row
        // existing on one surface and not another.
        $this->asMachine($this->readToken(), '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT)
            ->assertNotFound()
            ->assertJsonPath('error', 'seat_not_found');

        // DISCRIMINATING CONTROL — "a live seat in the same fleet is unaffected at every step."
        // Without it, a filter that emptied the whole snapshot would pass every assertion above.
        $this->assertArrayHasKey('aimla-impl', $seats);
        $this->assertNull($seats['aimla-impl']['retired']);
    }

    /**
     * ⛔ THE ARM THAT MUST NOT BE SKIPPED — **a seat that merely went quiet keeps its desk.**
     *
     * This is the half that proves card#9078 did not widen the removal into the inference the
     * whole design refuses. It drives the entire transport axis: past `stale` (300 s), past
     * `offline` (900 s), past the fold's own ceilings and past `Purge::RETENTION_DAYS` — with a
     * sweep pass at each step, so the seat is recomputed by the process that would be doing the
     * inferring — and asserts at every one that the desk is STILL RENDERED, still counted, and
     * never announced as retired.
     *
     * It is also the whole of pm's refuted argument, made checkable: a seat that went quiet is
     * *visibly present and degraded*, which is what makes a removed seat being *gone* legible as a
     * removal rather than ambiguous with silence.
     */
    public function test_a_seat_that_merely_went_quiet_keeps_its_desk(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->cleanTurn());
        $this->fold();

        foreach ([
            [400, 'stale'],
            [900, 'offline'],
            [Purge::RETENTION_DAYS * 86400, 'offline'],
        ] as [$seconds, $expected]) {
            $this->advanceServerClock($seconds);
            $this->sweep();

            $body = $this->snapshot();
            $seats = $this->index($body);

            $this->assertArrayHasKey(self::SEAT, $seats, sprintf(
                'the desk vanished after %d s of silence — a removal driven by an absence', $seconds,
            ));
            $this->assertSame($expected, $seats[self::SEAT]['render_state'],
                'a quiet seat renders DEGRADED, which is what makes a removal legible as a removal');
            $this->assertNull($seats[self::SEAT]['retired'], 'silence wrote a retirement');
            $this->assertSame(1, $body['fleet']['seats_total'], 'the quiet seat left the population');
        }

        // …and nothing announced anything. § 4.10: "no timeout, no purge, no silence."
        $this->artisan('mezzanine:purge')->assertSuccessful();
        Event::assertNotDispatched(SeatRetired::class);
        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('retired_at'));
        $this->assertArrayHasKey(self::SEAT, $this->snapshotSeats(),
            'the purge removed a desk — the purge deletes EVENTS, never seats');
    }

    /**
     * § 8.2.4's population follows the same filter as the seat list, so `seats_total` cannot
     * disagree with the seats beside it — now at the same INSTANT rather than on the same day.
     */
    public function test_the_fleet_counts_follow_the_same_read_filter_as_the_seat_list(): void
    {
        $this->secondSeat();
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->snapshot();
        $this->assertCount(2, $before['installs'][0]['seats']);
        $this->assertSame(2, $before['fleet']['seats_total']);

        $this->retire();

        $body = $this->snapshot();

        $this->assertCount(1, $body['installs'][0]['seats']);
        $this->assertSame(1, $body['fleet']['seats_total'],
            'the seat list and `seats_total` read different populations');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return $this->asMachine($this->readToken(), '/api/fleet/snapshot')->assertOk()->json();
    }

    /** @return array<string, array<string, mixed>> seat objects keyed by `seat_id` */
    private function snapshotSeats(): array
    {
        return $this->index($this->snapshot());
    }

    /** @return array<string, array<string, mixed>> */
    private function index(array $body): array
    {
        $out = [];

        foreach ($body['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $out[$seat['seat_id']] = $seat;
            }
        }

        return $out;
    }
}
