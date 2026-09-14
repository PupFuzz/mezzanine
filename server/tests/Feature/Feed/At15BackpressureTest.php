<?php

namespace Tests\Feature\Feed;

use App\Feed\FeedStream;
use App\Feed\Outbox;
use App\Fold\Clock;
use App\Sweep\Purge;
use Illuminate\Support\Facades\DB;

/**
 * **AT-D2-15 — feed backpressure closes one connection and no others** (`docs/design/FLEET-STATE.md
 * § 11`, § 8.5's stall bound, § 8.3's handler). card#9300.
 *
 * Two streams run AT THE SAME TIME on one clock (`Processes`): a healthy one that drains every frame
 * the moment it is written, and one whose consumer is slow or frozen — its write returns late, or not
 * at all — while a writer keeps the outbox moving.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT THIS FILE DRIVES AND WHAT NEEDS A DEPLOYMENT, STATED BY NAME (§ 11 makes saying so the test):
 *
 *   DRIVEN — leg (a), the SLOW consumer: its handler ends the stream on its own clock with
 *     `feed.close{reason:"stalled"}` as its last message, counts `feed_resync_required` once, and
 *     RETURNS (its process finishes — the handler's own exit); the healthy stream misses nothing; and a
 *     stream blocked past § 6.7's 60 s retention while the purge ran ends rather than reading past the
 *     purged row (the placement the third RED guards).
 *   DRIVEN — leg (b)'s first half: while a FROZEN consumer holds its write, the handler is blocked in
 *     it and ENDS NOTHING — no `feed.close`, no counter — and the healthy stream misses nothing.
 *   NEEDS A REAL DEPLOYMENT, NOT DRIVEN HERE:
 *     · the worker being returned to the POOL by the proxy's finite client-send timeout (R2's teardown
 *       clause) — there is no proxy, no socket and no FPM pool in this process;
 *     · "the stalled worker's RSS is flat across the stall" — RSS is a process's, and every stream
 *       here shares the suite's. The structural half is visible in the handler: it holds one tick's
 *       rows and nothing else, so a backlog lives in `feed_outbox` and the kernel's socket buffer.
 */
class At15BackpressureTest extends FeedTestCase
{
    /** Leg (a) — the slow consumer ends on the handler's clock; the healthy stream misses nothing. */
    public function test_a_slow_consumer_is_closed_on_its_own_clock_and_the_healthy_stream_misses_nothing(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);

        $user = $this->enrolled();
        $head = $this->wire->mark();
        $processes = new Processes;
        $blocked = false;

        $processes->spawn('healthy', $this->streamProcess($user));
        $processes->spawn('slow', $this->streamProcess($user, function (array $envelope, Processes $p) use (&$blocked) {
            // The first delta's write returns LATE — past the 45 s bound. A slow consumer, not a
            // frozen one: the write does return, and the handler's next pass sees the gap.
            if ($envelope['t'] === 'seat.delta' && ! $blocked) {
                $blocked = true;
                $p->waitMs((FeedStream::STALL_BOUND_S + 5) * 1000);
            }
        }));
        $processes->spawn('writer', function (Processes $p) {
            $p->waitMs(1000);
            $this->deliver($this->blockedPair(requestOnly: true));
            $this->fold();
            $p->waitMs(20_000);
            $this->stayAlive();   // more traffic while the slow write is still blocked
            $this->deliver($this->clearKill());
            $this->fold();
            $p->waitMs(40_000);
            $this->writeReload();
        });

        $processes->run($this->nowMs() + 300_000);

        // THE SLOW STREAM: ended by its handler, SAYING why, and the handler returned.
        $this->assertTrue($processes->finished('slow'), 'the slow stream\'s handler never returned');
        $slow = $processes->results['slow'];
        $this->assertSame('feed.close', end($slow)['t']);
        $this->assertSame('stalled', end($slow)['reason']);
        $this->assertSame(1, $this->globalCounter('feed_resync_required'), 'counted not exactly once');

        // THE HEALTHY STREAM: every row committed after it connected, in order, and the deploy's end.
        $this->assertTrue($processes->finished('healthy'));
        $healthy = $processes->results['healthy'];
        $delivered = $this->dataFrames($healthy);
        $written = array_column((new OutboxWire)->allFrom($head), 'payload');

        $this->assertNotEmpty($written, 'the writer wrote nothing, so "misses nothing" measures nothing');
        $this->assertSame($written, $delivered, 'the healthy stream missed, duplicated or reordered a row');
        $this->assertSame('reload', end($healthy)['reason']);

        // …and the slow stream really was the one that stalled: it had delivered less.
        $this->assertLessThan(count($healthy), count($slow));
    }

    /**
     * Leg (a), past § 6.7's retention — the THIRD RED's placement, asserted as a GREEN: a stream whose
     * write was blocked longer than the 60 s the outbox keeps a row, while the purge ran, ENDS at the
     * top of its next pass. It writes nothing but its `feed.close` after the block — in particular not
     * the rows written after the one the purge took, which is what a check-after-read would deliver
     * across the hole, silently, with a `coord.round` among them that no gap check can recover.
     */
    public function test_a_stream_blocked_past_the_outbox_retention_ends_instead_of_reading_past_a_purged_row(): void
    {
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);

        $user = $this->enrolled();
        $processes = new Processes;
        $afterBlock = null;

        $processes->spawn('slow', $this->streamProcess($user, function (array $envelope, Processes $p) use (&$afterBlock) {
            if ($afterBlock !== null) {
                $afterBlock[] = $envelope;

                return;
            }

            if ($envelope['t'] === 'building.layout') {
                $afterBlock = [];
                $p->waitMs((Purge::FEED_OUTBOX_RETENTION_S + 10) * 1000);
            }
        }));
        $processes->spawn('writer', function (Processes $p) {
            $p->waitMs(1000);
            $this->rawRow('building.layout', null, ['layout_version' => 1]);    // the frame the write blocks on
            $p->waitMs(3000);
            $this->rawRow('coord.round', self::INSTALL, ['coord_round' => ['post_ref' => 'lost-if-skipped']]);
            $p->waitMs((Purge::FEED_OUTBOX_RETENTION_S + 2) * 1000);
            $this->artisan('mezzanine:purge')->assertSuccessful();               // takes the coord.round
            $this->rawRow('coord.round', self::INSTALL, ['coord_round' => ['post_ref' => 'after-the-hole']]);
        });

        $processes->run($this->nowMs() + 300_000);

        $this->assertTrue($processes->finished('slow'));
        $this->assertNotNull($afterBlock, 'the fixture never blocked the write');
        $this->assertSame([['feed.close', 'stalled']], array_map(fn ($e) => [$e['t'], $e['reason'] ?? null], $afterBlock),
            'after a block past the retention the stream wrote something other than its close');
        $this->assertSame(0, DB::table('feed_outbox')->where('t', 'coord.round')->where('message', 'like', '%lost-if-skipped%')->count(),
            'the purge did not take the row, so the fixture does not reach the hole');
    }

    /**
     * Leg (b)'s first half — a FROZEN consumer: its write never returns while the connection is held.
     * The handler is blocked IN that write and ends nothing; the healthy stream misses nothing. What
     * ends the frozen one is the host (R2's teardown clause), which this process does not have — see
     * the class docblock.
     */
    public function test_a_frozen_consumer_ends_nothing_while_it_holds_the_write_and_the_healthy_stream_misses_nothing(): void
    {
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);

        $user = $this->enrolled();
        $head = $this->wire->mark();
        $processes = new Processes;

        $processes->spawn('healthy', $this->streamProcess($user));
        $processes->spawn('frozen', $this->streamProcess($user, function (array $envelope, Processes $p) {
            if ($envelope['t'] === 'seat.delta') {
                $p->waitMs(10_000_000);     // never, within this run
            }
        }));
        $processes->spawn('writer', function (Processes $p) {
            $p->waitMs(1000);
            $this->deliver($this->cleanTurn());
            $this->fold();
            $p->waitMs(90_000);
            $this->stayAlive();
            $p->waitMs(5000);
            $this->writeReload();
        });

        $processes->run($this->nowMs() + 200_000);

        $this->assertFalse($processes->finished('frozen'), 'the frozen stream ended — nothing in the handler can end it');
        $this->assertSame(0, $this->globalCounter('feed_resync_required'),
            'feed_resync_required counted on the frozen path, where the handler never resumes to count it');

        $this->assertTrue($processes->finished('healthy'));
        $healthy = $processes->results['healthy'];
        $this->assertSame(array_column((new OutboxWire)->allFrom($head), 'payload'), $this->dataFrames($healthy));
        $this->assertSame('reload', end($healthy)['reason']);
    }

    /**
     * The frames a stream relayed from the outbox — every one but the handler's own on-connect
     * `fleet.health` and `feed.close`, which are never rows.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private function dataFrames(array $frames): array
    {
        return array_values(array_filter(array_slice($frames, 1), fn ($e) => $e['t'] !== 'feed.close'));
    }

    /** A committed outbox row of a type no writer in this tree produces yet (`coord.*`), or any other. */
    private function rawRow(string $type, ?string $installId, array $body): void
    {
        DB::table('feed_outbox')->insert([
            'created_at' => Clock::sql(now()),
            't' => $type,
            'install_id' => $installId,
            'message' => json_encode(['feed_version' => 1, 't' => $type, 'server_time' => Clock::wire(Clock::sql(now()))] + $body),
        ]);
    }
}
