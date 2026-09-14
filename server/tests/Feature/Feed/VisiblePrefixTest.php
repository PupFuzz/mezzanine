<?php

namespace Tests\Feature\Feed;

use App\Feed\Outbox;
use App\Fold\Clock;
use App\Sweep\Purge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s two outbox reads as a VISIBLE PREFIX, and the two counters that
 * watch the prefix (§ 7.2) — card#9467.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ONE CONNECTION IS ENOUGH HERE, AND WHY. Every leg below is about which COMMITTED rows a read may
 * return, never about a row still inside an open transaction — that is AT-D2-25's, on real
 * connections. A reversed pair is two committed rows whose `id` order disagrees with their
 * `created_at` order: the lower id stamped LATER. Two writers produce it when one stamps, the other
 * stamps and inserts, and the first inserts after (a stamp-to-INSERT gap, or two app hosts whose
 * clocks disagree). The suite writes the pair directly with the stamps a reversal leaves, because
 * the property under test is the READ's answer to that table state, however it arose.
 */
class VisiblePrefixTest extends FeedTestCase
{
    private int $floor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->floor = (int) DB::table('feed_outbox')->max('id');
    }

    /**
     * ⛔ THE TICK STOPS AT A YOUNG ROW; IT NEVER READS PAST ONE. A filter on `created_at` returns the
     * aged row above the young one, the stream's cursor moves to that id, and the young row — which
     * becomes visible a moment later, BELOW the cursor — is never delivered.
     */
    public function test_the_tick_read_stops_at_a_young_row_instead_of_skipping_it(): void
    {
        [$young, $aged] = $this->reversedPair();

        $this->assertSame([], $this->ids(Outbox::after($this->floor)),
            'the tick read returned a row above a younger, lower id: a stream cursor would pass that id for ever');

        // The stop is a DELAY, not a loss: once the young row is past the lag, both arrive, in id order.
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);

        $this->assertSame([$young, $aged], $this->ids(Outbox::after($this->floor)));
    }

    /**
     * ⛔ THE CONNECT READ IS THE SAME PREFIX. A stream starts its cursor at `headBehindLag()`, and a
     * head computed over the rows past the lag lands on the aged, HIGHER id — so the young, lower one
     * is below a cursor that has not yet read anything.
     */
    public function test_the_connect_read_starts_below_a_young_row_of_a_reversed_pair(): void
    {
        [$young] = $this->reversedPair();

        $this->assertLessThan($young, Outbox::headBehindLag(),
            'the connect read started a stream above a younger, lower id it will never look below again');
    }

    /**
     * `feed_prefix_future` counts a read whose HOLDING row — the lowest id still inside the lag — carries
     * a `created_at` later than the reader's own clock. A row stamped in the future is the only way the
     * prefix can be held longer than the lag, so each such read is counted, by both reads.
     */
    public function test_a_read_held_by_a_row_stamped_in_the_future_is_counted_and_an_ordinary_hold_is_not(): void
    {
        // An ordinary hold: the holding row is young but in the reader's past. Nothing to count.
        $this->row(Clock::sql(now()->subMilliseconds(500)), 'young');
        Outbox::after($this->floor);
        Outbox::headBehindLag();

        $this->assertSame(0, $this->globalCounter('feed_prefix_future'), 'an ordinary young row was counted as a clock defect');

        DB::table('feed_outbox')->where('id', '>', $this->floor)->delete();

        // A hold from the future: a writer whose clock runs ahead of this reader's.
        $this->row(Clock::sql(now()->addSeconds(5)), 'from-the-future');
        $this->row(Clock::sql(now()->subSeconds(Outbox::VISIBILITY_LAG_S + 1)), 'held-behind-it');

        Outbox::after($this->floor);
        $this->assertSame(1, $this->globalCounter('feed_prefix_future'), 'the tick read held by a future stamp was not counted');

        Outbox::headBehindLag();
        $this->assertSame(2, $this->globalCounter('feed_prefix_future'), 'the connect read held by a future stamp was not counted');
    }

    /**
     * `feed_outbox_boundary_stalled` — a sweep pass that finds a committed row ABOVE the prefix that is
     * `retention − lag` old. § 6.7's purge deletes by each row's own `created_at`, delivered or not, so
     * such a row is one lag from being purge-eligible without any stream having read it. The threshold is
     * DERIVED from the two constants here, never typed.
     */
    public function test_the_stalled_counter_fires_at_retention_minus_the_lag_and_not_before(): void
    {
        $thresholdS = Purge::FEED_OUTBOX_RETENTION_S - Outbox::VISIBILITY_LAG_S;

        // The hold: a row stamped far enough ahead that it is still young when the threshold arrives.
        $this->row(Clock::sql(now()->addSeconds($thresholdS * 10)), 'holding');
        $this->row(Clock::sql(now()), 'stuck');

        Carbon::setTestNow(Carbon::now()->addSeconds($thresholdS)->subMilliseconds(1));
        $this->sweep();
        $this->assertSame(0, $this->globalCounter('feed_outbox_boundary_stalled'), 'counted before the stuck row reached retention − lag');

        Carbon::setTestNow(Carbon::now()->addMilliseconds(1));
        $this->sweep();
        $this->assertSame(1, $this->globalCounter('feed_outbox_boundary_stalled'), 'not counted at retention − lag');
    }

    /** The control: the same aged row with nothing holding the prefix below it is delivered, not stalled. */
    public function test_an_old_row_the_prefix_has_passed_is_not_counted_as_stalled(): void
    {
        $this->row(Clock::sql(now()), 'delivered');

        $this->advanceServerClock(Purge::FEED_OUTBOX_RETENTION_S);
        $this->sweep();

        $this->assertSame(0, $this->globalCounter('feed_outbox_boundary_stalled'));
    }

    /**
     * Two committed rows, the LOWER id stamped now (inside the lag) and the higher id stamped past it.
     *
     * @return array{int, int} [young id, aged id]
     */
    private function reversedPair(): array
    {
        $young = $this->row(Clock::sql(now()), 'young-lower-id');
        $aged = $this->row(Clock::sql(now()->subSeconds(Outbox::VISIBILITY_LAG_S + 1)), 'aged-higher-id');

        $this->assertLessThan($aged, $young);

        return [$young, $aged];
    }

    private function row(string $createdAt, string $mark): int
    {
        return (int) DB::table('feed_outbox')->insertGetId([
            'created_at' => $createdAt,
            't' => 'coord.round',
            'install_id' => self::INSTALL,
            'message' => json_encode(['feed_version' => 1, 't' => 'coord.round', 'server_time' => Clock::wire($createdAt), 'coord_round' => ['post_ref' => $mark]]),
        ]);
    }

    /** @return list<int> */
    private function ids(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        return $ids;
    }
}
