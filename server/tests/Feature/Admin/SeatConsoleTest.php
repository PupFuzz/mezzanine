<?php

namespace Tests\Feature\Admin;

use App\Events\SeatRetired;
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
}
