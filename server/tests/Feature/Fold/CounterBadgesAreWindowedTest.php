<?php

namespace Tests\Feature\Fold;

use App\Fold\Badges;
use App\Fold\Clock;
use App\Sweep\Sweep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * card#9491 — a COUNTER-DERIVED server badge is raised while its counter rose within the last 24 h,
 * and clears on its own after that (D2 § 7.2; operator ruling 2026-09-14).
 *
 * `seat_counters` is monotonic and never reset, so a badge read off "non-zero" was all-time: one
 * reinstall badged a seat `epoch_reset` for the rest of its life. Every case below drives the real
 * ingest and the real fold, and clears through the real SWEEPER, because a badge that clears on a
 * clock is cleared by the one writer that recomputes on a clock.
 */
class CounterBadgesAreWindowedTest extends FoldTestCase
{
    public function test_epoch_reset_clears_one_window_after_the_epoch_change_and_the_count_stays(): void
    {
        $this->epochChange();
        $this->assertContains('epoch_reset', $this->serverBadges());

        $rose = Clock::toMs(DB::table('seat_counters')->where('seat_ref', $this->seatRef)
            ->where('name', 'seq_epoch_change')->value('last_increased_at'));

        // One millisecond inside the window: still raised.
        $this->sweepAt($rose + Badges::COUNTER_WINDOW_MS - 1);
        $this->assertContains('epoch_reset', $this->serverBadges());

        // Exactly one window later: cleared, announced, and the count is untouched.
        $version = (int) $this->state()->state_version;
        $this->sweepAt($rose + Badges::COUNTER_WINDOW_MS);
        $this->assertNotContains('epoch_reset', $this->serverBadges());
        $this->assertGreaterThan($version, (int) $this->state()->state_version, 'the clear was never announced');
        $this->assertSame(1, $this->counter('seq_epoch_change'));
    }

    public function test_a_seq_gap_clears_after_the_window_and_a_new_gap_raises_it_again(): void
    {
        $this->deliver([$this->seqEvent(1000)]);
        $this->deliver([$this->seqEvent(1005)]);
        $this->fold();
        $this->assertContains('seq_gap', $this->serverBadges());

        $this->sweepAt($this->nowMs() + Badges::COUNTER_WINDOW_MS);
        $this->assertNotContains('seq_gap', $this->serverBadges());
        $this->assertSame(4, $this->counter('seq_gap'));

        $this->deliver([$this->seqEvent(1010)]);
        $this->fold();
        $this->assertContains('seq_gap', $this->serverBadges());
        $this->assertSame(8, $this->counter('seq_gap'));
    }

    /**
     * `reporter_ahead`'s counters are written by the INGEST, not the fold, so this is the case that
     * covers `Counters::seat()`'s default receipt time.
     */
    public function test_reporter_ahead_from_an_unknown_kind_clears_after_the_window(): void
    {
        // The heartbeat beside it is what the fold applies; an ignored kind alone is never stored.
        $this->deliver([$this->event('fleet.kind_from_a_newer_reporter', ['x' => 1]), ...$this->heartbeats(1)]);
        $this->fold();
        $this->assertSame(1, $this->counter('ignored_unknown_kinds'));
        $this->assertContains('reporter_ahead', $this->serverBadges());

        $this->sweepAt($this->nowMs() + Badges::COUNTER_WINDOW_MS);
        $this->assertNotContains('reporter_ahead', $this->serverBadges());
    }

    /**
     * AT-D2-10 holds across the window: a rebuild after the badge cleared leaves it cleared. A fold
     * that stamped the rise with the time it RAN would re-date the replayed epoch change to the
     * rebuild and raise the badge again, a rebuild disagreeing with the fold it replaces.
     */
    public function test_a_rebuild_after_the_window_does_not_raise_the_cleared_badge_again(): void
    {
        $this->epochChange();
        $this->sweepAt($this->nowMs() + Badges::COUNTER_WINDOW_MS + 1000);
        $this->assertNotContains('epoch_reset', $this->serverBadges());

        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();

        $this->assertNotContains('epoch_reset', $this->serverBadges());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Two batches under two epochs, the second numbered from a lower `seq` (D1 § 10.2). */
    private function epochChange(): void
    {
        $this->deliver($this->heartbeats(1), ['seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH']);
        $this->fold();

        $new = $this->heartbeats(1)[0];
        $new['seq'] = 5;
        $this->deliver([$new], ['seq_epoch' => '01K9ZZZZZA5N7M2X9V4B6D0FGH']);
        $this->fold();

        $this->assertSame(1, $this->counter('seq_epoch_change'));
    }

    /** @return array<string, mixed> */
    private function seqEvent(int $seq): array
    {
        $e = $this->heartbeats(1)[0];
        $e['seq'] = $seq;

        return $e;
    }

    private function sweepAt(int $ms): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs($ms, 'UTC'));
        app(Sweep::class)->pass();
    }

    private function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }

    /** @return list<string> */
    private function serverBadges(): array
    {
        return json_decode((string) $this->state()->server_badges, true) ?: [];
    }
}
