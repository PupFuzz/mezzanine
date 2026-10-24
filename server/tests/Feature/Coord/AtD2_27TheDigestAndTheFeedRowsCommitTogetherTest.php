<?php

namespace Tests\Feature\Coord;

use App\Coord\CoordCounters;
use App\Coord\HookBinding;
use App\Coord\Receipt;
use App\Coord\StoreFault;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * D2 AT-D2-27 — D1 § 18.8 step 9's "steps 7 and 9 commit together", as a property of
 * `App\Feed\Outbox::transaction()` rather than of a call site; the `(hook_ref, digest)` primitive; the
 * fault discipline; and the one-binding-per-repository refusal (`docs/design/FLEET-STATE.md § 6.12`,
 * r4 MINOR-2). card#12239.
 */
class AtD2_27TheDigestAndTheFeedRowsCommitTogetherTest extends CoordTestCase
{
    /** Make the next statement matching `$sql` throw `$make()` before it runs, once. */
    private function failNext(string $sql, \Closure $make): void
    {
        $armed = true;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed, $sql, $make) {
            if ($armed && str_contains($query, $sql)) {
                $armed = false;

                throw $make($query, $bindings);
            }
        });
    }

    public function test_green_a_derivation_that_throws_after_the_digest_leaves_nothing_and_the_repost_derives(): void
    {
        $binding = HookBinding::find($this->install['hook']);

        try {
            Receipt::write($binding, str_repeat('a', 64), function () {
                throw new \RuntimeException('the derivation failed after the digest insert');
            });
            $this->fail('the write swallowed a failed derivation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after the digest insert', $e->getMessage());
        }

        $this->assertSame(0, DB::table('coord_deliveries')->count(), 'a failed derivation left its digest behind');
        $this->assertSame(0, DB::table('feed_outbox')->count());

        // Through the route: a non-store fault on the outbox insert is `500 internal`, and nothing commits.
        $this->failNext('insert into `feed_outbox`', fn () => new \RuntimeException('a defect, not the store'));
        $this->deliver('comment', $this->install)->assertStatus(500)->assertJson(['error' => 'server_error', 'detail' => 'internal']);
        $this->assertSame(0, DB::table('coord_deliveries')->count());

        // The operator's Redeliver is a real second chance.
        $this->deliver('comment', $this->install)->assertStatus(202)->assertJson(['status' => 'accepted']);
        $this->assertCount(1, $this->rounds());
    }

    public function test_green_a_store_fault_on_the_outbox_insert_is_503_and_logs_none_of_the_messages_names(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        $fault = null;
        $this->failNext('insert into `feed_outbox`', function (string $query, array $bindings) {
            return new QueryException('mysql', $query, $bindings, new \PDOException('SQLSTATE[HY000]: forced'));
        });

        try {
            Receipt::write(HookBinding::find($this->install['hook']), str_repeat('b', 64), fn () => \App\Coord\Derive::delivery(
                'issue_comment', $this->spec()->payload('comment'), HookBinding::find($this->install['hook']), '2026-01-02T04:00:00.000Z'));
        } catch (StoreFault $e) {
            $fault = $e;
        }

        $this->assertInstanceOf(StoreFault::class, $fault, 'the outbox insert\'s QueryException was not rethrown as StoreFault');
        $this->assertNull($fault->getPrevious(), 'D2 § 6.12: a StoreFault carries no `previous`, which would carry the bindings');
        $this->assertStringContainsString(str_repeat('b', 64), $fault->getMessage(), 'the digest names the delivery');
        $this->assertStringNotContainsString('coder', $fault->getMessage(), 'a body-derived name reached the fault\'s message');
        $this->assertSame(0, DB::table('coord_deliveries')->count());

        // Through the route, and the log: `503 store_failed`, and no logged line names the post's ends.
        $this->failNext('insert into `feed_outbox`', function (string $query, array $bindings) {
            return new QueryException('mysql', $query, $bindings, new \PDOException('SQLSTATE[HY000]: forced'));
        });
        $this->deliver('comment', $this->install)->assertStatus(503)->assertJson(['detail' => 'store_failed']);

        $this->assertNotSame([], $logged, 'nothing was logged, so the absence below would prove nothing');

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('"coder"', $line, 'a logged line carries the serialized message');
        }
    }

    public function test_green_a_redeliver_of_a_committed_delivery_is_a_counted_duplicate_and_nothing_else(): void
    {
        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->deliver('comment', $this->install)->assertStatus(202)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->rounds());
        $this->assertSame(1, DB::table('coord_deliveries')->count());
        $this->assertSame(1, $this->counter(CoordCounters::DUPLICATE));
    }

    /**
     * The concurrent pair's mechanism, driven deterministically: a byte-identical twin commits between
     * this request's pre-check and its insert. The `ON DUPLICATE KEY` insert affects no row, so the
     * request answers `202 duplicate` and enqueues nothing — never `503` on a `1062`.
     */
    public function test_green_a_twin_landing_between_the_precheck_and_the_insert_answers_202_duplicate(): void
    {
        $body = $this->spec()->delivery('comment');
        $digest = hash('sha256', $body);
        $hookRef = (int) DB::table('coord_hooks')->where('hook_id', $this->install['hook'])->value('id');
        $armed = true;

        DB::connection()->beforeExecuting(function (string $query) use (&$armed, $digest, $hookRef) {
            if ($armed && str_contains($query, 'INSERT INTO coord_deliveries')) {
                $armed = false;
                DB::table('coord_deliveries')->insert(['hook_ref' => $hookRef, 'digest' => $digest, 'received_at' => now()->format('Y-m-d H:i:s.v')]);
            }
        });

        $this->deliver('comment', $this->install)->assertStatus(202)->assertJson(['status' => 'duplicate']);
        $this->assertSame([], $this->rounds(), 'the losing twin enqueued a second message for one post');
        $this->assertSame(1, $this->counter(CoordCounters::DUPLICATE));
    }

    /** Two DISTINCT deliveries share no row: both commit, two digests and two rounds. */
    public function test_green_two_distinct_deliveries_on_one_thread_both_commit(): void
    {
        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->deliver('comment-close', $this->install)->assertStatus(202);

        $this->assertSame(2, DB::table('coord_deliveries')->count());
        $this->assertCount(2, $this->rounds());
    }

    public function test_one_unrevoked_binding_per_repository_at_any_scope_and_reissue_after_revoke(): void
    {
        $this->artisanIssue($this->spec()->repository('fleet'), ['--scope' => 'install', '--install' => 'one'])
            ->expectsOutputToContain('already has an unrevoked binding')->assertFailed();
        $this->artisanIssue($this->spec()->repository('install'), ['--scope' => 'fleet'])
            ->expectsOutputToContain('already has an unrevoked binding')->assertFailed();

        $this->artisan('mezzanine:coord-hook:revoke', ['hook' => $this->fleet['hook']])->assertSuccessful();
        $this->artisanIssue($this->spec()->repository('fleet'), ['--scope' => 'fleet'])->assertSuccessful();

        $this->assertSame(2, DB::table('coord_hooks')->where('repository', $this->spec()->repository('fleet'))->count(),
            'the revoked binding keeps its row for the digests that reference it');
    }

    /** The key the refusal rests on, read off the engine: `(repository, active_key)` and nothing wider. */
    public function test_the_binding_key_is_repository_and_active_key(): void
    {
        $columns = collect(DB::select(
            "SELECT COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coord_hooks' AND INDEX_NAME = 'uq_binding' ORDER BY SEQ_IN_INDEX"
        ))->pluck('c')->all();

        $this->assertSame(['repository', 'active_key'], $columns,
            'a key carrying the scope or the install would let one repository be bound twice (r4 MINOR-2)');
    }

    private function artisanIssue(string $repository, array $options)
    {
        $dir = sys_get_temp_dir().'/coord-issue-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        return $this->artisan('mezzanine:coord-hook:issue', ['repository' => $repository, '--shared-identity' => 'true',
            '--secret-out' => "{$dir}/secret", '--hook-request-out' => "{$dir}/request.json"] + $options);
    }
}
