<?php

namespace Tests\Feature\Coord;

use App\Coord\CoordCounters;
use Illuminate\Support\Facades\DB;

/**
 * D2 AT-D2-26 — a delivery leaves a digest and its `coord.round` on the feed, and no thread, post or
 * membership anywhere (`docs/design/FLEET-STATE.md § 6.12`; the operator's ruling of 2026-10-23).
 * card#12239.
 */
class AtD2_26TheReceiptPublishesTheMessageAndHoldsNothingTest extends CoordTestCase
{
    /** Row counts of every table but the three a receipt may move (digests, outbox, counters). */
    private function store(): array
    {
        $tables = collect(DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
            ->pluck('t')->reject(fn ($t) => in_array($t, ['coord_deliveries', 'feed_outbox', 'global_counters', 'migrations'], true));

        return $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->sort()->all();
    }

    public function test_green_a_comment_and_an_opening_post_each_publish_one_round_carrying_the_bindings_scope(): void
    {
        $before = $this->store();

        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->deliver('opened', $this->install)->assertStatus(202);

        $rounds = $this->rounds();
        $this->assertCount(2, $rounds);
        $this->assertSame([['install', 'one'], ['install', 'one']], array_map(fn ($r) => [$r['scope'], $r['install_id']], $rounds));
        $this->assertSame(['one', 'one'], DB::table('feed_outbox')->where('t', 'coord.round')->orderBy('id')->pluck('install_id')->all());
        $this->assertSame(0, DB::table('feed_outbox')->where('t', 'coord.thread')->count());
        $this->assertSame(2, DB::table('coord_deliveries')->count());
        $this->assertSame($before, $this->store(), 'a receipt wrote a row outside the digest store and the feed');
        $this->assertSame(2, $this->counter(CoordCounters::RECEIVED.$this->install['hook']));
    }

    public function test_green_the_actions_it_does_not_derive_from_publish_nothing_and_commit_no_digest(): void
    {
        $before = $this->store();

        foreach (['closed', 'reopened', 'labeled', 'ping'] as $key) {
            $this->deliver($key, $this->install)->assertStatus(202)->assertJson(['status' => 'ignored']);
        }

        $this->assertSame([], $this->rounds());
        $this->assertSame(0, DB::table('feed_outbox')->count());
        $this->assertSame(0, DB::table('coord_deliveries')->count(), 'D1 § 18.8 step 8: an ignored delivery commits no digest');
        $this->assertSame(3, $this->counter(CoordCounters::IGNORED.'issues'));
        $this->assertSame(1, $this->counter(CoordCounters::IGNORED.'ping'));
        $this->assertSame(0, $this->counter(CoordCounters::RECEIVED.$this->install['hook']),
            'an ignored delivery never reaches step 9, which is what `coord_delivery_received` counts');
        $this->assertSame($before, $this->store());
    }

    public function test_an_ignored_delivery_redelivered_is_counted_again_rather_than_absorbed(): void
    {
        $this->deliver('closed', $this->install)->assertStatus(202);
        $this->deliver('closed', $this->install)->assertStatus(202);

        $this->assertSame(2, $this->counter(CoordCounters::IGNORED.'issues'),
            'D1 § 18.8.1: the ignored counter counts deliveries, not distinct ones');
    }

    public function test_the_fleet_health_surface_carries_every_coordination_member_at_zero_and_one_per_binding(): void
    {
        $members = \App\Read\FleetHealth::members();

        foreach ([$this->install['hook'], $this->fleet['hook']] as $hook) {
            $this->assertContains(CoordCounters::RECEIVED.$hook, $members);
        }

        $this->assertLessThan(
            array_search(CoordCounters::SIGNATURE_INVALID, $members, true),
            array_search(CoordCounters::RECEIVED.$this->fleet['hook'], $members, true),
            'D2 § 8.2.4 lists the per-binding family ahead of the route\'s other counters',
        );
    }
}
