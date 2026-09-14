<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * card#9464 — every fold transaction that samples `$before` takes the seat's `seat_state` lock as its
 * FIRST statement, so `$before` does not depend on how the store's sessions are configured.
 *
 * THE OBSERVABLE IS A WRITER REFUSED WHILE THE WINDOW IS OPEN. A write committed before the fold's
 * pass starts is seen by any shape of the fold, because the window's first plain read already follows
 * it. The write that separates the shapes lands between `readable()` and the first `$before` sample
 * (`Fold::$afterReadable`): with the lock taken first it cannot commit at all; with a plain read
 * first it commits, and whether the fold then raises `1020`, folds on a stale `$before`, or reads it
 * fresh depends on `innodb_snapshot_isolation` and the isolation level — which is why every
 * combination of the two runs, set on the fold's own session.
 *
 * THE POSITION IS ASSERTED ON THE STATEMENTS THEMSELVES: the fold connection's first statement after
 * its transaction begins is the lock. A lock taken after `readable()` still refuses a later writer
 * and would pass a test that only probed for it.
 *
 * The recovery tests drive the two sibling transactions — a one-event attempt and the quarantine —
 * finding the seat locked, through `Fold::$beforeLock` and the probe connection.
 */
class FoldLockFirstTest extends CommittedSeatTestCase
{
    private const GAP_MODEL_LABEL = 'written-in-the-gap';

    private const LOCK_SQL = '/^select `seat_ref` from `seat_state` where `seat_ref` = \? limit 1 for update skip locked$/';

    /** Whether the probe connection holds this seat's row right now (`holdTheSeatFrom()`). */
    private bool $probeHolds = false;

    protected function install(): string
    {
        return 'aimla-lockfirst';
    }

    /** @return array<string, array{string, string}> */
    public static function sessions(): array
    {
        return [
            'snapshot isolation ON, REPEATABLE READ' => ['ON', 'REPEATABLE READ'],
            'snapshot isolation OFF, REPEATABLE READ' => ['OFF', 'REPEATABLE READ'],
            'snapshot isolation ON, READ COMMITTED' => ['ON', 'READ COMMITTED'],
            'snapshot isolation OFF, READ COMMITTED' => ['OFF', 'READ COMMITTED'],
        ];
    }

    #[DataProvider('sessions')]
    public function test_no_writer_commits_the_seat_between_the_window_read_and_its_before_sample(string $snapshot, string $isolation): void
    {
        $fold = DB::connection(self::FOLD);
        $fold->statement("SET SESSION innodb_snapshot_isolation = $snapshot");
        $fold->statement("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");

        // The combination is the fold session's, read back — not merely requested.
        $session = $fold->selectOne('SELECT @@session.innodb_snapshot_isolation AS snapshot, @@session.transaction_isolation AS isolation');
        $this->assertSame($snapshot === 'ON' ? 1 : 0, (int) $session->snapshot);
        $this->assertSame(str_replace(' ', '-', $isolation), $session->isolation);

        $this->write(self::WRITER_1, $this->batch([$this->turnStart()]));

        $versionBefore = (int) $this->state()->state_version;
        $outboxFrom = (int) DB::connection(self::FIXTURE)->table('feed_outbox')->max('id');

        $statements = null;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$statements) {
            if ($e->connectionName === self::FOLD && $statements === null) {
                $statements = [];
            }
        });

        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$statements) {
            if ($q->connectionName === self::FOLD && $statements !== null) {
                $statements[] = $q->sql;
            }
        });

        $seamFired = false;
        $writer = null;

        Fold::$afterReadable = function (int $seatRef) use (&$seamFired, &$writer) {
            Fold::$afterReadable = null;
            $seamFired = true;

            $gap = DB::connection(self::WRITER_2);
            $gap->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $gap->transaction(function () use ($gap, $seatRef) {
                    $gap->table('seat_state')->where('seat_ref', $seatRef)->lockForUpdate()->value('seat_ref');
                    $gap->table('seat_state')->where('seat_ref', $seatRef)->update(['model_label' => self::GAP_MODEL_LABEL]);
                });

                $writer = 'committed';
            } catch (QueryException $e) {
                $writer = 'refused: '.($e->errorInfo[1] ?? $e->getMessage());
            }
        };

        $applied = $this->foldPass(self::FOLD);

        $this->assertTrue($seamFired, 'the seam between readable() and the first $before sample never fired');
        $this->assertSame('refused: 1205', $writer,
            "a writer committed the seat's seat_state row between the window's readable() and its \$before sample ($snapshot, $isolation)");

        $this->assertNotEmpty($statements, 'the fold connection never began a transaction');
        $this->assertMatchesRegularExpression(self::LOCK_SQL, $statements[0],
            "the window's first statement after BEGIN is not the seat's seat_state lock");

        // The second observable: the refused write reached neither the seat nor any delta the pass
        // published, and the pass bumped the version for its own event alone.
        $this->assertSame(1, $applied);
        $state = $this->state();
        $this->assertNull($state->model_label);
        $this->assertSame($versionBefore + 1, (int) $state->state_version);

        $published = DB::connection(self::FIXTURE)->table('feed_outbox')
            ->where('id', '>', $outboxFrom)->where('install_id', $this->install())->pluck('message')->all();
        $this->assertNotEmpty($published, 'the pass published no delta');

        foreach ($published as $message) {
            $this->assertStringNotContainsString(self::GAP_MODEL_LABEL, $message, 'a delta carried the write the fold never folded');
        }
    }

    public function test_a_recovery_attempt_that_finds_the_seat_locked_yields_without_quarantining(): void
    {
        $call = $this->ulid();
        $this->write(self::WRITER_1, $this->batch([$this->turnStart(), $this->toolStart($call)]));
        [$first, $second] = $this->eventIds();

        // The window fails for a reason of its own, which routes the pass into the one-at-a-time
        // recovery; nothing after that fails.
        $failsOnce = new class extends Projector
        {
            private bool $failed = false;

            public function apply(FoldEvent $e): void
            {
                if (! $this->failed) {
                    $this->failed = true;

                    throw new \RuntimeException('the window fails for a reason of its own');
                }

                parent::apply($e);
            }
        };

        // Lock attempts, in order: the window; the first event's recovery attempt; the second
        // event's. The probe holds the seat from that last one until a later attempt — which only a
        // misrouted yield makes: a second attempt, then the quarantine, which the probe lets through.
        $attempts = $this->holdTheSeatFrom(3, releaseAt: 5);

        try {
            $applied = $this->foldPass(self::FOLD, new Fold($failsOnce));
        } finally {
            $this->releaseTheSeat();
        }

        $this->assertGreaterThanOrEqual(3, $attempts(), 'the probe never held the seat at a recovery attempt');
        $this->assertSame(0, $this->counter('fold_error'), 'a recovery attempt that found the seat locked quarantined an event');
        $this->assertSame(0, (int) $this->state()->fold_errors, 'a recovery attempt that found the seat locked quarantined an event');
        $this->assertSame($first, (int) $this->state()->fold_cursor_event_id, 'the cursor is not on the last event the recovery applied');
        $this->assertFalse(DB::connection(self::FIXTURE)->table('calls')->where('seat_ref', $this->seatRef)->where('call_id', $call)->exists(),
            'the event whose attempt found the seat locked was applied anyway');
        $this->assertSame(1, $applied, 'the pass did not report the one event the recovery applied');

        $this->foldUntilCaughtUp(self::FOLD);
        $this->assertSame($second, (int) $this->state()->fold_cursor_event_id);
        $this->assertSame(0, (int) $this->state()->fold_errors);
    }

    public function test_a_quarantine_that_finds_the_seat_locked_yields_without_counting_a_fold_error(): void
    {
        $this->write(self::WRITER_1, $this->batch([$this->turnStart(), $this->toolStart($this->ulid())]));
        [$first] = $this->eventIds();

        // The second event is poison: it raises in the window and in both recovery attempts.
        $poison = new class extends Projector
        {
            public function apply(FoldEvent $e): void
            {
                if ($e->kind === 'tool.start') {
                    throw new \RuntimeException('the second event is poison');
                }

                parent::apply($e);
            }
        };

        // Lock attempts: the window; the first event's attempt; the second event's two attempts; the
        // quarantine — which the probe holds.
        $attempts = $this->holdTheSeatFrom(5, releaseAt: null);
        $thrown = null;

        try {
            $applied = $this->foldPass(self::FOLD, new Fold($poison));
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $this->releaseTheSeat();
        }

        $this->assertNull($thrown, 'a quarantine that found the seat locked threw out of the pass: '.($thrown === null ? '' : $thrown::class));
        $this->assertSame(5, $attempts(), 'the probe never held the seat at the quarantine');
        $this->assertSame(0, $this->counter('fold_error'), 'a quarantine that found the seat locked counted a fold error');
        $this->assertSame(0, (int) $this->state()->fold_errors);
        $this->assertSame($first, (int) $this->state()->fold_cursor_event_id, 'the cursor moved past the event whose quarantine yielded');
        $this->assertSame(1, $applied, 'the pass did not report the one event the recovery applied');
    }

    /** @return list<int> this seat's event ids, in id order */
    private function eventIds(): array
    {
        return DB::connection(self::FIXTURE)->table('events')->where('seat_ref', $this->seatRef)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Arm `Fold::$beforeLock` so the probe connection holds this seat's row from the fold's
     * `$from`-th lock attempt on, releasing it at attempt `$releaseAt`.
     *
     * @return callable(): int the number of lock attempts the fold made
     */
    private function holdTheSeatFrom(int $from, ?int $releaseAt): callable
    {
        $attempts = 0;

        Fold::$beforeLock = function (int $seatRef) use (&$attempts, $from, $releaseAt) {
            $attempts++;

            if ($attempts === $from) {
                $probe = DB::connection(self::PROBE);
                $probe->beginTransaction();
                $probe->table('seat_state')->where('seat_ref', $seatRef)->lockForUpdate()->value('seat_ref');
                $this->probeHolds = true;
            } elseif ($attempts === $releaseAt) {
                $this->releaseTheSeat();
            }
        };

        return function () use (&$attempts) {
            return $attempts;
        };
    }

    private function releaseTheSeat(): void
    {
        if ($this->probeHolds) {
            DB::connection(self::PROBE)->rollBack();
            $this->probeHolds = false;
        }
    }
}
