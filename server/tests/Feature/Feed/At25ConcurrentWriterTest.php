<?php

namespace Tests\Feature\Feed;

use App\Feed\BuildingLayoutChanged;
use App\Feed\Outbox;
use App\Fold\Clock;
use App\Fold\Fold;
use Illuminate\Support\Facades\DB;

/**
 * **AT-D2-25 — a concurrent writer cannot strand a message behind a stream's cursor**
 * (`docs/design/FLEET-STATE.md § 11`, § 8.3's two reads and its visibility lag). card#9300.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ THE RACE IS DRIVEN, ON REAL CONNECTIONS — unlike AT-D2-22's file, which could not. The suite's own
 * connection is inside `RefreshDatabase`'s transaction, where a commit is a savepoint and every read
 * sees every write. So each actor here has its OWN connection to `mezzanine_test` — two writers and
 * the streams — cloned from the pinned `mysql` one (§ 6.2's pin is what they inherit), and their
 * COMMITs are real: a row one writer holds uncommitted is invisible to a stream's read exactly as
 * MariaDB makes it. `Processes` orders the statements: writer 1 inserts (the LOWER id) and holds its
 * transaction; writer 2 inserts and commits; a stream reads, and a second stream connects, between
 * the two commits; writer 1 commits. Twenty iterations, as AT-D2-22's build asks.
 *
 * Writer 1's message is a `coord.round`, "because that is the one class no gap check can recover";
 * the assertion is on the MESSAGE SET each stream received, never on a client's final seat state.
 *
 * The rows these connections commit are outside the suite's transaction, so the test deletes them.
 */
class At25ConcurrentWriterTest extends FeedTestCase
{
    private const CONNECTIONS = ['feed_writer_1', 'feed_writer_2', 'feed_stream_a', 'feed_stream_b'];

    private int $floor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CONNECTIONS as $name) {
            config(["database.connections.$name" => config('database.connections.mysql')]);
        }

        $this->floor = (int) DB::connection('feed_stream_a')->table('feed_outbox')->max('id');
    }

    protected function tearDown(): void
    {
        DB::connection('feed_stream_a')->table('feed_outbox')->where('id', '>', $this->floor)->delete();

        foreach (self::CONNECTIONS as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_both_writers_messages_reach_a_stream_open_before_them_and_one_that_connected_between_their_commits(): void
    {
        $user = $this->enrolled();

        for ($iteration = 1; $iteration <= 20; $iteration++) {
            $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);
            $t0 = $this->nowMs();
            $processes = new Processes;
            $tag = 'it'.$iteration;

            $processes->spawn('stream_a', $this->streamProcess($user), $t0, 'feed_stream_a');

            // Writer 1: INSERT, then hold the transaction open across writer 2's whole write.
            $processes->spawn('writer_1', function (Processes $p) use ($tag) {
                DB::beginTransaction();
                $this->insertRow('coord.round', self::INSTALL, ['coord_round' => ['post_ref' => $tag.'-writer-1']]);
                $p->waitMs(1000);
                DB::commit();
            }, $t0 + 500, 'feed_writer_1');

            // Writer 2: INSERT and COMMIT inside writer 1's open window — through the primitive.
            $processes->spawn('writer_2', function () use ($tag) {
                Outbox::transaction(fn () => Outbox::enqueue(new BuildingLayoutChanged(2, $tag.'-writer-2')));
            }, $t0 + 700, 'feed_writer_2');

            // Stream B connects BETWEEN the two commits — the arm a tick-only test passes over.
            $processes->spawn('stream_b', $this->streamProcess($user), $t0 + 1000, 'feed_stream_b');

            $processes->spawn('reload', fn () => $this->writeReload(), $t0 + 4000, 'feed_writer_2');

            $processes->run($t0 + 20_000);

            foreach (['stream_a', 'stream_b'] as $stream) {
                $this->assertTrue($processes->finished($stream), "iteration $iteration: $stream never ended");

                $frames = $processes->results[$stream];
                $marks = array_values(array_filter(array_map(
                    fn ($e) => $e['coord_round']['post_ref'] ?? (str_starts_with((string) ($e['at'] ?? ''), $tag) ? $e['at'] : null),
                    $frames,
                )));

                $this->assertSame([$tag.'-writer-1', $tag.'-writer-2'], $marks,
                    "iteration $iteration: $stream did not receive both messages, in id order");
                $this->assertSame('reload', end($frames)['reason']);
            }
        }
    }

    /**
     * ⛔ THE WRITER-SIDE RULE — the third RED's subject, asserted through the primitive: a writer whose
     * transaction runs LONGER THAN THE LAG after it enqueued its message still cannot be stranded,
     * because `Outbox::transaction()` inserts the row as the transaction's last statement — its id is
     * taken at the end, not when the message was learned. (The RED — moving the insert to the first
     * statement — is card#9300's control → mutant → control run against `App\Feed\Outbox`.)
     */
    public function test_a_writer_that_enqueues_early_and_commits_late_is_not_stranded(): void
    {
        $user = $this->enrolled();
        $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);
        $t0 = $this->nowMs();
        $processes = new Processes;

        $processes->spawn('stream', $this->streamProcess($user), $t0, 'feed_stream_a');

        $processes->spawn('slow_writer', function (Processes $p) {
            Outbox::transaction(function () use ($p) {
                Outbox::enqueue(new BuildingLayoutChanged(1, 'slow-writer'));
                $p->waitMs((Fold::VISIBILITY_LAG_S + 1) * 1000);    // the rest of a long transaction
            });
        }, $t0 + 500, 'feed_writer_1');

        // Raw, not through `Outbox`: the slow writer is suspended INSIDE `Outbox::transaction()`, whose
        // pending list is per process, and two fibers of one PHP process would share it.
        $processes->spawn('quick_writer', function () {
            DB::transaction(fn () => $this->insertRow('coord.round', self::INSTALL, ['coord_round' => ['post_ref' => 'quick']]));
        }, $t0 + 700, 'feed_writer_2');

        $processes->spawn('reload', fn () => $this->writeReload(), $t0 + 8000, 'feed_writer_2');

        $processes->run($t0 + 30_000);

        $frames = $processes->results['stream'];
        $got = array_values(array_filter(array_map(fn ($e) => $e['coord_round']['post_ref'] ?? ($e['at'] ?? null), $frames),
            fn ($v) => in_array($v, ['quick', 'slow-writer'], true)));

        $this->assertSame(['quick', 'slow-writer'], $got, 'the long writer\'s message was stranded or reordered');
    }

    /** A raw committed-on-commit outbox row on the calling process's connection. */
    private function insertRow(string $type, ?string $installId, array $body): void
    {
        DB::table('feed_outbox')->insert([
            'created_at' => Clock::sql(now()),
            't' => $type,
            'install_id' => $installId,
            'message' => json_encode(['feed_version' => 1, 't' => $type, 'server_time' => Clock::wire(Clock::sql(now()))] + $body),
        ]);
    }
}
