<?php

namespace Tests\Feature\Fold;

use App\Events\SeatRetired;
use App\Fold\Clock;
use App\Sweep\Sweep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AT-D2-23 — **a retired seat is rendered, not disappeared.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * § 11's BUILD IS EXPLICIT ABOUT THE MECHANISM UNDER TEST: "retire it by running
 * **`mezzanine:retire`** — NOT BY WRITING THE COLUMNS DIRECTLY, BECAUSE THE COMMAND *IS* THE
 * MECHANISM UNDER TEST". § 4.10 says why: three of the four things retirement is supposed to
 * produce had no producer at all — the `cause: operator` ENUM member had no writer, `seat.retired`
 * reached the wire from nowhere, and the immediate re-render was up to a sweep pass late and
 * mislabelled `staleness_sweep`.
 *
 * ⚠ SCOPE — THE SNAPSHOT HALF IS PART B's AND IS NOT ASSERTED HERE. § 11's GREEN says the seat is
 * "absent from the snapshot while its row is still in `seats`: assert BOTH, because the
 * disappearance must be a READ FILTER and not a deletion". The read filter lives in § 8.2's
 * snapshot query, which card #7339 PART B owns and which is not built. What is asserted here is the
 * half this card can be held to and the half the filter would be wrong without: THE ROW IS STILL
 * THERE after 14 days, nothing deletes it, and the render is still `retired`. Named in the PR body
 * rather than approximated with a query nobody will use.
 */
class At23RetiredSeatTest extends FoldTestCase
{
    /**
     * GREEN — the whole act, in one transaction, by the one writer.
     */
    public function test_retiring_a_seat_renders_it_retired_and_publishes_the_message_and_the_delta(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->assertSame('blocked', $this->state()->render_state);
        $before = (int) $this->state()->state_version;

        $this->retire();

        $state = $this->state();
        $seat = DB::table('seats')->where('id', $this->seatRef)->first();

        $this->assertSame('retired', $state->render_state);
        $this->assertNotNull($seat->retired_at);
        $this->assertSame('operator@aimla', $seat->retired_by);
        $this->assertSame('decommissioned', $seat->retired_reason);

        // "At THAT snapshot `link_state` / `activity_state` still carry what the seat was doing
        // when it was retired." Retirement "is an ADMINISTRATIVE fact, not a transport or activity
        // one" — it short-circuits above both axes and changes neither (§ 4.10).
        $this->assertSame('live', $state->link_state);
        $this->assertSame('blocked', $state->activity_state);

        // § 6.5's per-writer rule, stated for this command by name in § 4.10.
        $this->assertGreaterThan($before, (int) $state->state_version);

        $row = collect($this->transitions())->last();
        $this->assertSame('operator', $row['cause'], 'the ENUM member that had no writer');
        $this->assertSame('blocked', $row['from']);
        $this->assertSame('retired', $row['to']);

        Event::assertDispatched(SeatRetired::class, function (SeatRetired $e) use ($state) {
            return $e->seatRef === $this->seatRef
                && $e->installId === self::INSTALL
                && $e->seatId === self::SEAT
                && $e->retiredBy === 'operator@aimla'
                && $e->stateVersion === (int) $state->state_version;
        });
    }

    /**
     * GREEN — "PAST 14 DAYS the axes have kept deriving underneath — `link_state` has reached
     * `offline` and the render is STILL `retired`, because `retired` short-circuits above both axes
     * — and the seat is absent from the snapshot WHILE ITS ROW IS STILL IN `seats`."
     */
    public function test_the_axes_keep_deriving_underneath_and_the_row_is_never_deleted(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->retire();

        $this->advanceServerClock(15 * 86400);

        // The sweeper recomputes `link_state` for EVERY seat on every pass, retired ones included
        // — which is what "the axes keep deriving" means operationally (§ 4.10, § 2.1).
        app(Sweep::class)->pass();

        $state = $this->state();
        $this->assertSame('offline', $state->link_state, 'the transport axis kept deriving');
        $this->assertSame('idle', $state->activity_state, 'the drill-down still says what it was doing');
        $this->assertSame('retired', $state->render_state, '`retired` short-circuits above both');

        // A DELETION IS WHAT IS FORBIDDEN, and the purge is the only thing in this design that
        // deletes. § 4.10: "Is it purged? **No.** `seats` is retained forever; the 14 days is a READ
        // FILTER, not a deletion, so an operator query can still find the row AND ITS REASON."
        $this->artisan('mezzanine:purge')->assertSuccessful();

        $seat = DB::table('seats')->where('id', $this->seatRef)->first();
        $this->assertNotNull($seat, 'the row survives its own retention window');
        $this->assertSame('decommissioned', $seat->retired_reason);
        $this->assertNotNull(DB::table('seat_state')->where('seat_ref', $this->seatRef)->first());
    }

    /**
     * THIRD RED — THE COLUMNS WITHOUT THE COMMAND. "Set `retired_at` / `retired_by` /
     * `retired_reason` directly and let the ordinary machinery run. NO `seat.retired` EVER REACHES A
     * CONNECTED CLIENT — nothing else in this document publishes it — and the transition row the
     * sweeper eventually writes carries `cause: staleness_sweep` for a change an operator made, up
     * to a full sweep pass after they made it. **Assert the ABSENCE of the message and the CAUSE
     * VALUE, not the eventual `render_state`**: the render DOES converge, which is exactly why this
     * defect is invisible from the desk and has to be asserted on the wire and on the ledger."
     */
    public function test_third_red_the_columns_without_the_command_publish_nothing_and_mislabel_the_cause(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->cleanTurn());
        $this->fold();

        DB::table('seats')->where('id', $this->seatRef)->update([
            'retired_at' => Clock::sql(now()),
            'retired_by' => 'operator@aimla',
            'retired_reason' => 'decommissioned',
        ]);

        // The ordinary machinery: the sweeper's own recompute, one pass later.
        $this->advanceServerClock(Sweep::CADENCE_S);
        app(Sweep::class)->pass();

        // THE RENDER CONVERGES — which is the point of the RED, not a contradiction of it.
        $this->assertSame('retired', $this->state()->render_state);

        // …and the two things that do NOT.
        Event::assertNotDispatched(SeatRetired::class);

        $row = collect($this->transitions())->last();
        $this->assertSame('staleness_sweep', $row['cause']);
        $this->assertNotContains('operator', $this->causes());
    }

    /**
     * SECOND RED — THE STALE RENDER. "Keep the seat but leave `render_state` at its last derived
     * value → it renders `offline`, which is a claim about the transport of a seat that has been
     * DECOMMISSIONED, and nothing on the object says an operator did it."
     *
     * Driven by retiring a seat that is ALREADY offline: without the recompute inside the command's
     * transaction, the stored render would still read `offline` and every consumer would be told
     * the transport story about a seat nobody is coming back to.
     */
    public function test_second_red_an_already_offline_seat_renders_retired_and_not_its_last_transport_state(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->advanceServerClock(1000);
        app(Sweep::class)->pass();
        $this->assertSame('offline', $this->state()->render_state);

        $this->retire();

        $this->assertSame('retired', $this->state()->render_state);
        $this->assertSame('operator', collect($this->transitions())->last()['cause']);
    }

    /** § 2.1: "Re-running it on an already-retired seat is a NO-OP." */
    public function test_re_running_on_an_already_retired_seat_is_a_no_op(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->retire();

        $at = DB::table('seats')->where('id', $this->seatRef)->value('retired_at');
        $version = (int) $this->state()->state_version;
        $rows = count($this->transitions());

        $this->advanceServerClock(60);
        $this->artisan('mezzanine:retire', [
            '--seat' => self::INSTALL.'/'.self::SEAT,
            '--by' => 'somebody-else',
            '--reason' => 'a different reason',
        ])->assertSuccessful();

        // The record of WHO retired a seat is written once: a second act would overwrite the author
        // and the reason, and would tell every connected client the seat was retired twice.
        $seat = DB::table('seats')->where('id', $this->seatRef)->first();
        $this->assertSame($at, $seat->retired_at);
        $this->assertSame('operator@aimla', $seat->retired_by);
        $this->assertSame('decommissioned', $seat->retired_reason);
        $this->assertSame($version, (int) $this->state()->state_version);
        $this->assertCount($rows, $this->transitions());

        Event::assertDispatchedTimes(SeatRetired::class, 1);
    }

    /**
     * ⛔ NO TIMEOUT MAY EVER STAND IN FOR AN OPERATOR RETIREMENT (§ 2.1, § 4.10, § 4.5).
     *
     * The strongest form of that invariant is that no amount of TIME produces it, so this drives
     * the whole time axis past every ceiling in the design — offline quiescence at 900 s, the
     * 60-minute attention ceiling, the 14-day retention window — and asserts the seat is still not
     * retired. "Nothing else — no timeout, no purge, no silence — ever removes a row from the fleet."
     */
    public function test_no_timeout_purge_or_silence_ever_retires_a_seat(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        foreach ([1000, 3600, 15 * 86400] as $seconds) {
            $this->advanceServerClock($seconds);
            app(Sweep::class)->pass();
        }

        $this->artisan('mezzanine:purge')->assertSuccessful();

        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('retired_at'));
        $this->assertSame('offline', $this->state()->render_state);
        $this->assertNotContains('operator', $this->causes());
    }

    /** § 4.5: retirement is "an act with an AUTHOR and a REASON" — neither is defaulted. */
    public function test_the_command_refuses_without_an_author_or_a_reason(): void
    {
        $this->artisan('mezzanine:retire', ['--seat' => self::INSTALL.'/'.self::SEAT, '--by' => 'x'])
            ->assertExitCode(2);

        $this->artisan('mezzanine:retire', ['--seat' => self::INSTALL.'/'.self::SEAT, '--reason' => 'x'])
            ->assertExitCode(2);

        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('retired_at'));
    }

    /** § 11's DISCRIMINATING CONTROL: "a live seat in the same fleet is unaffected at every step." */
    public function test_a_live_seat_in_the_same_fleet_is_unaffected(): void
    {
        [$token, $otherRef] = $this->issueToken('aimla', 'aimla-moodle');

        $this->deliver($this->cleanTurn());
        $this->deliver($this->cleanTurn('other-session'), token: $token, seat: 'aimla-moodle');
        $this->fold();

        $this->retire();
        app(Sweep::class)->pass();

        $this->assertSame('retired', $this->state()->render_state);
        $this->assertSame('idle', $this->state($otherRef)->render_state);
        $this->assertNull(DB::table('seats')->where('id', $otherRef)->value('retired_at'));
        $this->assertNotContains('operator', $this->causes($otherRef));
    }

    private function retire(): void
    {
        $this->artisan('mezzanine:retire', [
            '--seat' => self::INSTALL.'/'.self::SEAT,
            '--by' => 'operator@aimla',
            '--reason' => 'decommissioned',
        ])->assertSuccessful();
    }

    /** @return list<string> */
    private function causes(?int $seatRef = null): array
    {
        return array_column($this->transitions($seatRef), 'cause');
    }
}
