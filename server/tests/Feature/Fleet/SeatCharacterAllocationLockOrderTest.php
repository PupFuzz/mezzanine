<?php

namespace Tests\Feature\Fleet;

use App\Characters\SpeciesList;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;

/**
 * AT-D2-31's concurrency legs (card#12443, `docs/design/FLEET-STATE.md § 6.13`): a seat's creation takes the one-row
 * `species_allocation_lock` mutex as its transaction's FIRST statement, so two creations serialise there and the second
 * allocates around the first.
 *
 * ⛔ ON THE REAL-COMMIT RIG, IN `SeatRetirementLockOrderTest`'s SHAPE. A probe connection that sees committed rows and
 * contends for real locks is what `RefreshDatabase` cannot give. Everything here stays within the rig's one install,
 * which its `tearDown()` deletes; the probe's own inserted seat is a row of that install.
 *
 * THE POSITION PATTERN, twice — one issue for a NEW seat run through `SeatTokens::$afterMutex`, the seam right after the
 * mutex lock, where a PROBE connection pinned to a one-second lock wait:
 *   · PRESENCE — tries `FOR UPDATE NOWAIT` on the mutex row and is refused: a second allocation would be waiting at its
 *     first statement. After the issue commits the same statement succeeds — the refusal was the lock's.
 *   · OUTCOME — commits a live seat of the rig's install holding the issuing seat's own draw, its `seat_id` sorting into
 *     the issuing seat's `uq_seat` gap. The issue proceeds and is allocated AROUND it. This is the leg that proves
 *     FIRST: under REPEATABLE READ a transaction's read view is opened by its first plain read, so a population read
 *     taken before the mutex would answer from a snapshot that predates the probe's commit.
 * The statement-position witness — `recordFoldTransactions()`, hoisted into the rig — holds the mutex as the first
 * statement of the issue's transaction.
 */
class SeatCharacterAllocationLockOrderTest extends CommittedSeatTestCase
{
    private const MUTEX_SQL = '/^select `id` from `species_allocation_lock` where `id` = \? limit 1 for update$/';

    private const NOWAIT = 'SELECT id FROM species_allocation_lock WHERE id = 1 FOR UPDATE NOWAIT';

    protected function install(): string
    {
        return 'species-lock-order';
    }

    /**
     * The preference order AS PUBLISHED (§ 6.13): the list rotated to start at the key's own draw — derived here from
     * the draw and the list, never through `SpeciesList::preference()`, the code under test.
     *
     * @return list<string>
     */
    private function preferenceOf(string $key): array
    {
        $keys = SpeciesList::current()->keys;
        $at = (int) array_search(SpeciesList::current()->drawOf($key), $keys, true);

        return array_merge(array_slice($keys, $at), array_slice($keys, 0, $at));
    }

    /**
     * A new seat id of the rig's install whose own draw no live seat in the store holds, with the live population
     * below the list's length — so the issue reads rule 1, and its own draw is what it gets unless something else
     * takes it. Asserted, not assumed: the store is shared with whatever ran before.
     */
    private function newSeat(): string
    {
        $list = SpeciesList::current();
        $live = DB::connection(self::FIXTURE)->table('seats')->whereNull('retired_at')->pluck('species')->all();

        $this->assertLessThan(count($list->keys) - 1, count($live),
            'precondition: the store\'s live population leaves rule 1 no room to answer, so this leg would read rule 2 or 3');

        for ($n = 0; $n < 1000; $n++) {
            $seat = $this->install()."-q{$n}";

            if (! in_array($list->drawOf($this->install()."/{$seat}"), $live, true)) {
                return $seat;
            }
        }

        $this->fail('precondition: no new seat id has a free own draw');
    }

    private function issue(string $seatId): void
    {
        $issued = $this->on(self::WRITER_1, fn () => SeatTokens::issue($this->install(), $seatId, 'suite'));

        $this->assertInstanceOf(SeatTokenIssued::class, $issued, "{$seatId} was refused a token");
    }

    private function species(string $seatId): ?string
    {
        return DB::connection(self::FIXTURE)->table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $this->install())->where('seats.seat_id', $seatId)->value('seats.species');
    }

    /** Whether the probe's `NOWAIT` on the mutex row was refused by the lock (read by code), or succeeded. */
    private function probeIsRefused(): bool
    {
        $probe = DB::connection(self::PROBE);
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $probe->select(self::NOWAIT);

            return false;
        } catch (QueryException $e) {
            // ER_LOCK_WAIT_TIMEOUT — what MariaDB answers a NOWAIT that finds the row locked.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
                throw $e;
            }

            return true;
        }
    }

    /** A live seat of the rig's install, committed by the probe — the test-only insert no application path makes. */
    private function probeCommitsASeat(string $seatId, string $species): void
    {
        $store = DB::connection(self::PROBE);
        $installRef = (int) $store->table('installs')->where('install_id', $this->install())->value('id');

        $store->table('seats')->insert([
            'install_ref' => $installRef, 'seat_id' => $seatId, 'species' => $species,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** The issue's transaction opened with the mutex, as its first statement. */
    private function assertTheMutexOpensTheIssue(callable $transactions): void
    {
        $issue = array_values(array_filter($transactions(), static fn (array $t): bool => $t !== []));

        $this->assertNotEmpty($issue, 'the issue began no transaction on its connection');
        $this->assertMatchesRegularExpression(self::MUTEX_SQL, $issue[0][0], 'the first statement of the issue\'s transaction is not the species mutex');
    }

    public function test_presence_the_mutex_is_held_at_the_seam_and_released_by_the_commit(): void
    {
        $seat = $this->newSeat();
        $refused = null;

        SeatTokens::$afterMutex = function () use (&$refused) {
            $refused = $this->probeIsRefused();
        };

        $transactions = $this->recordFoldTransactions(self::WRITER_1);
        $this->issue($seat);

        $this->assertNotNull($refused, 'the seam after the mutex never ran');
        $this->assertTrue($refused, 'at the seam the probe locked the mutex row — a second allocation would have run concurrently with this one');
        $this->assertTheMutexOpensTheIssue($transactions);

        // CONTROL (a) — after the issue commits, the same statement succeeds: the refusal was the lock, not the harness.
        $this->assertFalse($this->probeIsRefused(), 'the probe is refused with no allocation running — the refusal above proves nothing about the lock');
    }

    public function test_outcome_a_seat_committed_while_the_issue_holds_the_mutex_is_allocated_around(): void
    {
        $list = SpeciesList::current();
        $seat = $this->newSeat();
        $own = $list->drawOf($this->install()."/{$seat}");
        $next = $this->preferenceOf($this->install()."/{$seat}")[1];
        $live = DB::connection(self::FIXTURE)->table('seats')->whereNull('retired_at')->pluck('species')->all();
        $this->assertNotContains($next, $live, 'precondition: the species past the issuing seat\'s draw is taken, so "around" has no single answer');

        $fired = false;

        SeatTokens::$afterMutex = function () use (&$fired, $seat, $own) {
            $fired = true;
            // `{seat}a` sorts straight after `{seat}` in `uq_seat`, inside the gap the issue's lookup reads.
            $this->probeCommitsASeat($seat.'a', $own);
        };

        $transactions = $this->recordFoldTransactions(self::WRITER_1);
        $this->issue($seat);

        $this->assertTrue($fired, 'the seam after the mutex never ran');
        $this->assertSame($next, $this->species($seat),
            "the issuing seat was allocated {$this->species($seat)}: the probe's committed seat holding {$own} was invisible to "
            .'its population read — the read view predates the mutex');
        $this->assertTheMutexOpensTheIssue($transactions);
    }

    /** CONTROL (a), the outcome half: a seat committed BEFORE the issue starts is allocated around too. */
    public function test_outcome_control_a_seat_committed_before_the_issue_is_allocated_around(): void
    {
        $list = SpeciesList::current();
        $seat = $this->newSeat();
        $own = $list->drawOf($this->install()."/{$seat}");

        $this->probeCommitsASeat($seat.'a', $own);
        $this->issue($seat);

        $this->assertSame($this->preferenceOf($this->install()."/{$seat}")[1], $this->species($seat));
    }
}
