<?php

namespace Tests\Unit;

use App\Fold\Clock;
use App\Read\RetirementFilter;
use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `App\Read\RetirementFilter` — **the predicate that decides which seats are rendered, asserted at
 * the shape a clock cannot enter.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS IS A SQL-SHAPE TEST AND NOT A ROW TEST. The behaviour — a retired seat leaves the
 * snapshot, a quiet one does not — is asserted against the real store, on the real read surfaces,
 * by `Tests\Feature\Feed\At23WireSurfaceTest`. What THAT cannot see is the difference between "no
 * retired seat came back on this fixture's clock" and "no clock is consulted at all", because both
 * produce the same rows for any fixture that does not sit on a boundary. card#9078's ruling is
 * precisely about the second: **removal is an ANNOUNCEMENT, not a timer.** The observable that
 * separates them is that the compiled predicate BINDS NOTHING — a window has a value in it.
 *
 * ⚠ IT ALSO CARRIES ITS OWN CONTROL, and that is not decoration: it compiles the windowed
 * predicate this class used to hold (`retired_at IS NULL OR retired_at > cutoff`) and asserts that
 * the same assertions REJECT it. Without that arm a mistake in how bindings are read would leave a
 * test that passes on every input, which is the defect class this repository names most often.
 * Nothing here touches a connection: `toSql()` compiles against the grammar, so this arm runs
 * where a store does not.
 */
class RetirementFilterTest extends TestCase
{
    /** ⛔ NO CLOCK IN THE RENDER PREDICATE — the whole of card#9078's ruling, in one assertion. */
    public function test_the_render_predicate_binds_no_value_and_asks_only_whether_a_seat_was_retired(): void
    {
        $query = RetirementFilter::renderable(DB::table('seats'));

        $this->assertSame([], $query->getBindings(),
            'the render predicate consulted a value — a removal is announced, never timed');
        $this->assertMatchesRegularExpression('/retired_at.{0,3} is null/i', $query->toSql());
        $this->assertStringNotContainsStringIgnoringCase(' or ', $query->toSql());
    }

    /** The console's list is the exact complement, on the same column, with no window either. */
    public function test_the_retired_predicate_is_the_exact_complement(): void
    {
        $query = RetirementFilter::retired(DB::table('seats'));

        $this->assertSame([], $query->getBindings());
        $this->assertMatchesRegularExpression('/retired_at.{0,3} is not null/i', $query->toSql());
    }

    /**
     * THE CONTROL: the fourteen-day window card#9078 removed, compiled here and rejected by the
     * same three assertions. This is what makes the arms above evidence rather than decoration.
     */
    public function test_the_window_this_ruling_removed_would_fail_the_assertions_above(): void
    {
        $windowed = DB::table('seats')->where(fn ($q) => $q
            ->whereNull('seats.retired_at')
            ->orWhere('seats.retired_at', '>', Clock::sql(now()->copy()->subDays(Purge::RETENTION_DAYS))));

        $this->assertNotSame([], $windowed->getBindings(), 'a window binds its cutoff — this control cannot fire');
        $this->assertStringContainsStringIgnoringCase(' or ', $windowed->toSql());
    }
}
