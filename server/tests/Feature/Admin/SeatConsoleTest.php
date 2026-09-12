<?php

namespace Tests\Feature\Admin;

use App\Events\SeatRetired;
use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Sweep\SweepTestCase;

/**
 * The console's AGENT module — card#9070's third scope item: **manage and remove only.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ASSERTION THAT MATTERS IS THAT THE CONSOLE PERFORMS `mezzanine:retire`'s ACT AND NOT A
 * LOOKALIKE. `docs/design/FLEET-STATE.md § 4.10` names four products of retirement, three of
 * which had no producer before that command existed: the recomputed `render_state`, the
 * `cause: operator` transition row, and the `seat.retired` publish. A console that wrote the three
 * columns itself would satisfy every "is it retired?" assertion and would leave the desk on every
 * connected floor until a sweep pass, mislabelled `staleness_sweep` when it finally moved. So this
 * file asserts the same four things AT-D2-23 asserts of the command, through the HTTP route.
 *
 * It extends `SweepTestCase` for the real rig: fixtures are POSTed through the real ingest and
 * folded, so the seat this retires is a seat that reported — which, per
 * `docs/design/FLOOR.md § 3.4`, is the only way a seat comes to exist.
 */
class SeatConsoleTest extends SweepTestCase
{
    private function operator(string $email = 'ops@example.com'): User
    {
        return User::factory()->twoFactorConfirmed()->create(['email' => $email]);
    }

    private function seatRow(): object
    {
        return DB::table('seats')->where('id', $this->seatRef)->first();
    }

    public function test_the_agent_list_shows_a_reporting_seat_and_its_state(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->actingAs($this->operator())
            ->get(route('admin.agents.index'))
            ->assertOk()
            ->assertSee(self::INSTALL)
            ->assertSee(self::SEAT)
            ->assertSee('blocked');
    }

    /**
     * ⭐ card#9078 — **the desk goes at once, and the record MOVES HERE.**
     *
     * The operator ruled that a removed seat's desk goes immediately, which takes the retirement
     * record off the floor: there is no retired desk left to carry `at` / `by` / `reason`. It does
     * not disappear with the desk — this page is its home, and that home is what makes the
     * immediate removal a REMOVAL rather than a deletion.
     *
     * ⛔ BOTH HALVES IN ONE TEST, DELIBERATELY. "Gone from the live list" passes just as well when
     * the record was destroyed, and "listed as retired" passes just as well when the desk never
     * went. The pair is the assertion.
     */
    public function test_a_retired_seat_leaves_the_live_list_and_its_record_is_still_on_the_page(): void
    {
        [, $liveRef] = $this->issueToken(self::INSTALL, 'aimla-impl');

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), [
                'reason' => 'the box was decommissioned',
            ])
            ->assertRedirect(route('admin.agents.index'));

        $page = $this->actingAs($operator)->get(route('admin.agents.index'))->assertOk();

        // 1 — the record is on the page: who, when, why.
        $page->assertSee('Retired seats')
            ->assertSee(self::SEAT)
            ->assertSee($operator->email)
            ->assertSee('the box was decommissioned')
            ->assertSee((string) $this->seatRow()->retired_at);

        // 2 — and the seat is no longer offerable for retirement, because it is no longer in the
        // live list at all. The retire FORM is the live list's own control, so its absence for
        // this seat is that seat's absence from that list — asserted through the route the form
        // posts to, which is unique per seat.
        $page->assertDontSee(route('admin.agents.retire', [self::INSTALL, self::SEAT]), escape: false);

        // DISCRIMINATING CONTROL — the seat that was NOT retired still has its form. Without it, a
        // page that rendered no forms at all would pass assertion 2.
        $page->assertSee(route('admin.agents.retire', [self::INSTALL, 'aimla-impl']), escape: false);
        $this->assertNull(DB::table('seats')->where('id', $liveRef)->value('retired_at'));
    }

    public function test_retiring_through_the_console_performs_the_whole_act(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->assertSame('blocked', $this->state()->render_state);
        $before = (int) $this->state()->state_version;

        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), [
                'reason' => 'the box was decommissioned',
            ])
            ->assertRedirect(route('admin.agents.index'))
            ->assertSessionHasNoErrors();

        $seat = $this->seatRow();
        $state = $this->state();

        // 1 — the three columns, with the author the console knows and the reason it was given.
        $this->assertNotNull($seat->retired_at);
        $this->assertSame($operator->email, $seat->retired_by);
        $this->assertSame('the box was decommissioned', $seat->retired_reason);

        // 2 — the recomputed render, through § 4.2's precedence rather than an assigned literal.
        $this->assertSame('retired', $state->render_state);

        // 3 — the transition row carrying the cause an operator act owes.
        $row = collect($this->transitions())->last();
        $this->assertSame('operator', $row['cause']);
        $this->assertSame('retired', $row['to']);

        // 4 — the version bump and the publish, both in the same transaction.
        $this->assertGreaterThan($before, (int) $state->state_version);

        Event::assertDispatched(SeatRetired::class, fn (SeatRetired $e) => $e->seatRef === $this->seatRef
            && $e->installId === self::INSTALL
            && $e->seatId === self::SEAT
            && $e->retiredBy === $operator->email
            && $e->stateVersion === (int) $state->state_version);
    }

    public function test_a_reason_is_required_and_nothing_is_written_without_one(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->actingAs($this->operator())
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($this->seatRow()->retired_at);
    }

    public function test_re_retiring_through_the_console_is_a_no_op_that_keeps_the_first_act(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->retire();   // the COMMAND, by 'operator@aimla', reason 'decommissioned'

        $first = $this->seatRow();

        $this->actingAs($this->operator())
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), ['reason' => 'a second reason'])
            ->assertSessionHasNoErrors();

        $again = $this->seatRow();

        $this->assertSame($first->retired_by, $again->retired_by, 'who retired a seat is written once');
        $this->assertSame('decommissioned', $again->retired_reason);
        $this->assertEquals($first->retired_at, $again->retired_at);
    }

    public function test_an_unknown_seat_is_refused_and_changes_nothing(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->actingAs($this->operator())
            ->post(route('admin.agents.retire', [self::INSTALL, 'a-seat-that-never-reported']), [
                'reason' => 'typo in the seat id',
            ])
            ->assertSessionHasErrors('retire');

        $this->assertNull($this->seatRow()->retired_at);
    }

    /**
     * `docs/design/FLEET-STATE.md § 6.4` fixes `seats.retired_by` at 64 characters. An operator
     * whose address does not fit is refused with the shell command to use instead — a truncated
     * author would be a wrong answer to the one question the column exists to answer, and under
     * MySQL's strict mode it would be a 500 instead of a message.
     */
    public function test_an_author_too_long_for_the_column_is_refused_rather_than_truncated(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $long = str_repeat('a', 56).'@example.com';   // 68 characters
        $this->assertGreaterThan(64, mb_strlen($long));

        $this->actingAs($this->operator($long))
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), ['reason' => 'decommissioned'])
            ->assertSessionHasErrors('retire');

        $this->assertNull($this->seatRow()->retired_at);

        // THE CONTROL: the same request from an operator whose address fits retires the seat, so
        // the refusal above is the length rule and not a route that refuses every retirement.
        $this->actingAs($this->operator('short@example.com'))
            ->post(route('admin.agents.retire', [self::INSTALL, self::SEAT]), ['reason' => 'decommissioned'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->seatRow()->retired_at);
    }

    /**
     * ⛔ THE ACT OWES AN AUTHOR AND A REASON, AND HOLDS THAT ITSELF (§ 4.5). Both entry points
     * refuse an empty one first, with better messages than an exception — the command with
     * `INVALID`, the console with a validation error. Until card#9070's first review round that was
     * ALL there was: the extraction that exists so there is one implementation of the act had left
     * the act's own precondition duplicated in its two callers, so `card#9071`'s third caller would
     * have inherited nothing.
     */
    public function test_the_seat_retirement_act_refuses_an_empty_author_or_reason(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $act = app(SeatRetirement::class);

        foreach ([['', 'decommissioned'], ['ops@aimla', ''], ['  ', 'decommissioned'], ['ops@aimla', " \t "]] as [$by, $reason]) {
            try {
                $act->retire(self::INSTALL, self::SEAT, $by, $reason);
                $this->fail(sprintf('an empty author or reason was accepted: by=%s reason=%s', json_encode($by), json_encode($reason)));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('author and a reason', $e->getMessage());
            }
        }

        $this->assertNull($this->seatRow()->retired_at, 'and none of them wrote anything');

        // THE CONTROL — the same call with both, on the same path.
        $this->assertSame(
            SeatRetirementOutcome::RETIRED,
            $act->retire(self::INSTALL, self::SEAT, 'ops@aimla', 'decommissioned')->outcome,
        );
    }

    /**
     * ⛔ THE § 2.1 NO-OP IS DECIDED BY THE WRITE, NOT BY A READ TAKEN BEFORE IT — card#9070's
     * third review round. The act used to test `retired_at` on a `first()` taken before its
     * transaction opened and then UPDATE on `id` alone, so a retirement that committed inside that
     * window was overwritten: its author, its reason and its timestamp replaced by the second
     * caller's, and a second `seat.retired` telling every connected floor the seat retired twice.
     *
     * ⚠ WHAT THIS DRIVES AND WHAT IT CANNOT. It drives the guard's PLACE: the other act is
     * committed on the connection immediately before the UPDATE is executed, so the UPDATE's
     * predicate is the only thing that can still see it — the old code passes its guard here and
     * writes, this one matches zero rows. It does NOT drive concurrency: the suite runs on ONE
     * connection on either store — `lockForUpdate()` is a no-op on SQLite and SQLite serialises
     * writers anyway, and on the MariaDB of the `php-tests-mariadb` lane (card#9250) the lock is
     * real but has no second session to exclude — so two genuinely interleaved transactions are
     * not producible here on any store the suite has. That leg is reasoned in
     * `App\Fleet\SeatRetirement`, not executed.
     */
    public function test_a_retirement_that_lands_after_another_one_writes_nothing(): void
    {
        Event::fake([SeatRetired::class]);

        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $seatRef = $this->seatRef;
        $injected = false;

        // ⛔ THE TABLE IS QUOTED BY THE CONNECTED STORE'S GRAMMAR, NEVER BY HAND — card#9250.
        // This read `update "seats"`, which is SQLite's quoting; on MariaDB it is backticks, the
        // hook never fired, and `$injected` below is what caught it on the new lane's first run.
        $updateSeats = 'update '.$this->wrapTable('seats');

        DB::beforeExecuting(function (string $query) use (&$injected, $seatRef, $updateSeats): void {
            if ($injected || ! str_contains($query, $updateSeats)) {
                return;
            }

            $injected = true;

            DB::table('seats')->where('id', $seatRef)->update([
                'retired_at' => '2026-01-01 00:00:00',
                'retired_by' => 'first@example.com',
                'retired_reason' => 'the first act',
            ]);
        });

        $outcome = app(SeatRetirement::class)
            ->retire(self::INSTALL, self::SEAT, 'second@example.com', 'the second act');

        // The test's own precondition. Without it every assertion below would also pass on a run
        // where the other act was never injected at all.
        $this->assertTrue($injected, 'the competing retirement was never injected');

        $seat = $this->seatRow();

        $this->assertSame('first@example.com', $seat->retired_by, 'who retired a seat is written once');
        $this->assertSame('the first act', $seat->retired_reason);
        $this->assertSame('2026-01-01 00:00:00', (string) $seat->retired_at);

        $this->assertSame(SeatRetirementOutcome::ALREADY_RETIRED, $outcome->outcome);
        $this->assertSame(
            '2026-01-01 00:00:00',
            $outcome->at,
            'and the caller is told WHEN it was retired — both callers print this value',
        );

        Event::assertNotDispatched(SeatRetired::class);
    }
}
