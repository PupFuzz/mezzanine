<?php

namespace Tests\Feature\Coord;

use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-17 extended with the digest chain (`docs/design/FLEET-STATE.md § 6.7`, card#12239):
 *
 *     GitHub redelivery reach  3 days  <  digest retention  14 days
 *
 * A digest purged while GitHub can still redeliver its delivery re-derives one real post as two.
 */
class TheDigestRetentionExceedsGitHubsRedeliveryReachTest extends CoordTestCase
{
    public function test_both_floors_the_purge_holds_sit_above_githubs_reach(): void
    {
        $this->assertGreaterThan(Purge::REDELIVERY_REACH_DAYS, Purge::RETENTION_DAYS);
        $this->assertGreaterThan(Purge::REDELIVERY_REACH_DAYS, Purge::DEDUP_WINDOW_DAYS,
            'the purge\'s lowest accepted retention would let a digest go while GitHub can still redeliver it');
    }

    public function test_a_digest_is_kept_inside_the_retention_and_purged_past_it(): void
    {
        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->travel(13)->days();
        app(Purge::class)->pass();
        $this->assertSame(1, DB::table('coord_deliveries')->count(), 'a 13-day-old digest was purged');

        // The digest still absorbs a redelivery at 13 days.
        $this->deliver('comment', $this->install)->assertStatus(202)->assertJson(['status' => 'duplicate']);

        $this->travel(2)->days();
        app(Purge::class)->pass();
        $this->assertSame(0, DB::table('coord_deliveries')->count(), 'a 15-day-old digest outlived the retention');
    }

    /** The second RED: a 2-day retention is refused — the digests it would delete are inside GitHub's reach. */
    public function test_red_a_purge_at_two_days_is_refused(): void
    {
        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->travel(2)->days();
        $this->travel(1)->hours();

        try {
            app(Purge::class)->pass(retentionDays: 2);
            $this->fail('the purge accepted a retention inside GitHub\'s redelivery reach');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(1, DB::table('coord_deliveries')->count());
    }
}
