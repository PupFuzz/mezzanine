<?php

namespace Tests\Feature\Fold;

use App\Feed\Outbox;
use App\Feed\SeatRetired;
use App\Fleet\SeatRetirement;
use App\Fold\Clock;
use App\Sweep\Sweep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\OutboxWire;
use Tests\Feature\Sweep\SweepTestCase;

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
 * ⚠ SCOPE — THE READ SURFACES ARE `Tests\Feature\Feed\At23WireSurfaceTest`'s HALF, not this
 * file's. That half now asserts the desk going AT `retired_at` (card#9078's operator ruling —
 * "when an agent is removed, its seat and desk should go away immediately") and the arm that
 * proves the removal did not widen into an inference from absence. What is asserted HERE is the
 * store: the act, its transaction, its `cause: operator` row, and the property the read filter
 * would be wrong without — THE ROW IS STILL THERE, nothing deletes it, and the stored render is
 * still `retired`.
 */
class At23RetiredSeatTest extends SweepTestCase
{
    /**
     * GREEN — the whole act, in one transaction, by the one writer.
     */
    public function test_retiring_a_seat_renders_it_retired_and_publishes_the_message_and_the_delta(): void
    {
        $wire = new OutboxWire;

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

        // The message, committed in the act's transaction as an outbox row (card#9300).
        $retired = $wire->ofType('seat.retired');
        $this->assertCount(1, $retired);
        $this->assertSame(self::INSTALL, $retired[0]['install_id']);
        $this->assertSame(self::SEAT, $retired[0]['payload']['seat_id']);
        $this->assertSame('decommissioned', $retired[0]['payload']['reason']);
        $this->assertSame((int) $state->state_version, $retired[0]['payload']['state_version']);
    }

    /**
     * GREEN — the axes keep deriving underneath a retired seat: `link_state` reaches `offline`
     * and the stored render is STILL `retired`, because `retired` short-circuits above both axes
     * (§ 4.2) — and the row is never deleted, whatever the purge does.
     *
     * ⚠ AFTER card#9078 NOTHING RENDERS WHAT THIS DERIVES: the seat left every read surface at
     * `retired_at`, so the sweeper's continued recompute of a retired seat now has no reader on
     * the floor, and it still publishes a `seat.delta` on each pass that moves a version-bearing
     * fact. That is the CURRENT behaviour, asserted here as current behaviour and not endorsed —
     * it is reported as a finding on card#9078 rather than changed inside a card about removal.
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
        $wire = new OutboxWire;

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
        $this->assertSame([], $wire->ofType('seat.retired'));

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
        $wire = new OutboxWire;

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

        $this->assertCount(1, $wire->ofType('seat.retired'));
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

    /**
     * § 4.5: retirement is "an act with an AUTHOR and a REASON" — neither is defaulted.
     *
     * ⛔ THE WHITESPACE-ONLY ROWS ARE THE ONES THAT MATTER, and they were added in card#9070's
     * SECOND review round because they were the measured defect. The act refuses on `trim()`;
     * this command refused on `=== ''`. `--by="   "` therefore walked past the command's refusal
     * and hit the act's `throw`, so an operator who typed a space got an uncaught
     * `InvalidArgumentException` and a stack trace at exit code 1, on a surface whose own class
     * docblock claimed it "refuses first, with a message better than an exception". Two spellings
     * of one rule is one spelling too many; both now read
     * `App\Support\RetirementAttribution`. Exit code 2 IS the assertion — a 1 here is the
     * exception path.
     */
    public function test_the_command_refuses_without_an_author_or_a_reason(): void
    {
        $seat = self::INSTALL.'/'.self::SEAT;

        foreach ([
            ['--by' => 'x'],
            ['--reason' => 'x'],
            ['--by' => '   ', '--reason' => 'x'],
            ['--by' => 'x', '--reason' => "  \t "],
            ['--by' => ' ', '--reason' => ' '],
        ] as $options) {
            $this->artisan('mezzanine:retire', ['--seat' => $seat] + $options)
                ->assertExitCode(2);
        }

        $this->assertNull(DB::table('seats')->where('id', $this->seatRef)->value('retired_at'));
    }

    /**
     * A seat another writer kept busy past retirement's bounded wait and retries is refused in a
     * sentence with a non-zero exit, as the console's retire route refuses it — card#9466.
     *
     * At transaction level 2 here (`RefreshDatabase`, then `Outbox::transaction()`), where a
     * concurrency error is rethrown at once without a retry, so a seam that throws the `1205`
     * message is the whole fixture. The exception is caught rather than left to fail the test, so
     * that a command which lets it escape fails on the assertion that names that.
     */
    public function test_a_busy_seat_refuses_the_retirement_with_a_sentence_and_a_failing_exit(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        SeatRetirement::$beforeRetire = fn () => throw ConcurrencyError::raised(1205);

        $exit = null;
        $thrown = null;

        try {
            $exit = Artisan::call('mezzanine:retire', [
                '--seat' => self::INSTALL.'/'.self::SEAT, '--by' => 'operator@aimla', '--reason' => 'decommissioned',
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            SeatRetirement::$beforeRetire = null;
        }

        $this->assertNull($thrown, 'the refusal escaped as an exception: '.$thrown?->getMessage());
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('nothing was changed', Artisan::output());
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

    /**
     * **The publish is ordered by the transaction and never survives its rollback** — § 4.10's
     * "in the transaction that sets the columns", bought by `App\Feed\Outbox::transaction()`
     * inserting the row as the transaction's LAST statement (card#9300).
     *
     * ⚠ WHY THIS ARM EXISTS AT ALL, WHEN THREE TESTS ABOVE ALREADY ASSERT THE MESSAGE. Every one of
     * them runs the command on a transaction that COMMITS, and on that path a publish before the
     * commit, inside it, or after it are indistinguishable. The rollback is the only fixture that
     * separates them — which is how the earlier shape (published from outside the transaction)
     * survived a full suite.
     *
     * The transaction is driven directly rather than through `mezzanine:retire`, because the
     * property under test belongs to the OUTBOX and the command has no failure injection point.
     */
    public function test_the_retired_message_never_reaches_a_client_from_a_transaction_that_rolled_back(): void
    {
        $wire = new OutboxWire;

        $this->deliver($this->cleanTurn());
        $this->fold();

        $message = fn () => new SeatRetired($this->seatRef, self::INSTALL, self::SEAT,
            Clock::sql(now()), 'operator@aimla', 'decommissioned', 1);

        try {
            Outbox::transaction(function () use ($message) {
                Outbox::enqueue($message());

                // Anything that aborts the act after the publish has been ordered: a constraint
                // violation on one of the three columns, a lost connection, a raise in the shared
                // recompute. A client told a seat retired here could never be told otherwise.
                throw new \RuntimeException('the retirement did not commit');
            });
        } catch (\RuntimeException) {
            // expected — the rollback is the fixture
        }

        $this->assertSame([], $wire->ofType('seat.retired'));

        // …and the SAME enqueue, on a transaction that commits, does write the row. Without this half
        // the assertion above would pass against a message that is never written at all.
        Outbox::transaction(fn () => Outbox::enqueue($message()));

        $this->assertCount(1, $wire->ofType('seat.retired'));

        // ⛔ AND OUTSIDE A TRANSACTION IT IS REFUSED, loudly — a message with no commit to precede
        // could not be the last statement before one.
        $this->expectException(\LogicException::class);
        Outbox::enqueue($message());
    }
}
