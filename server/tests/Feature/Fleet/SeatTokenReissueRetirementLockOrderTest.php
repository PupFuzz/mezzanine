<?php

namespace Tests\Feature\Fleet;

use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;

/**
 * Re-issuing a seat's token while the same seat is being retired does not deadlock — card#12461,
 * `App\Fleet\SeatLock`'s order (`seat_state` row, then `seats` row).
 *
 * ⛔ TWO REAL TRANSACTIONS IN FLIGHT AT ONCE, SO ONE OF THEM RUNS IN ANOTHER PROCESS. A deadlock needs
 * each transaction blocked on a lock the other holds, and a statement that blocks on this test's own
 * thread blocks the thread: it times out (`1205`) and never closes a cycle. So the retirement runs here,
 * on the rig's `WRITER_2`, and the re-issue runs as the operator runs it — `php artisan
 * mezzanine:ingest-token:issue` — in a child process on the same store.
 *
 * THE INTERLEAVING, at `SeatRetirement::$afterBefore` (the retirement holds the seat's `seat_state`
 * row and has not yet written `seats`): the child is started and the test waits until the store shows a
 * transaction in `LOCK WAIT`, i.e. until the re-issue is blocked on the retirement. Then the retirement
 * goes on to its `UPDATE seats`.
 *   · Re-issue taking `seats` before `seat_state` (dev before card#12461): it is blocked on `seat_state`
 *     HOLDING the `seats` row, so the retirement's UPDATE closes the cycle and the engine kills one side
 *     with `1213` — the retirement (which then retries) or the re-issue (which fails the command).
 *   · Re-issue taking `seat_state` first: it is blocked holding nothing the retirement needs; the
 *     retirement commits on its first attempt, and the re-issue then reads the seat retired and is
 *     refused — the documented answer for a retired seat, with no token written.
 *
 * The outcome is read three ways, each of which the deadlock moves: the engine's own `Innodb_deadlocks`
 * counter (a server-global; the suite runs one test at a time), the retirement's attempt count, and the
 * child's exit and the seat's token rows.
 */
class SeatTokenReissueRetirementLockOrderTest extends CommittedSeatTestCase
{
    /** How long the child may take to reach its lock wait, and then to finish — a wedge fails, never hangs. */
    private const BOUND_S = 30;

    protected function install(): string
    {
        return 'reissue-retire-order';
    }

    public function test_a_re_issue_racing_a_retirement_of_the_same_seat_does_not_deadlock(): void
    {
        $deadlocksBefore = $this->deadlocks();
        $attempts = 0;
        $child = null;

        SeatRetirement::$beforeRetire = function () use (&$attempts) {
            $attempts++;
        };

        SeatRetirement::$afterBefore = function () use (&$child) {
            if ($child === null) {
                $child = $this->startReissue();
                $this->awaitLockWait($child);
            }
        };

        $outcome = $this->on(self::WRITER_2, fn () => app(SeatRetirement::class)->retire(
            $this->seat(), 'operator@example.com', 'lock order',
        ));

        $this->assertNotNull($child, 'the seam after $before never ran, so nothing raced the retirement');
        [$exit, $stderr] = $this->finish($child);

        $deadlocks = $this->deadlocks() - $deadlocksBefore;

        $this->assertSame(0, $deadlocks, sprintf(
            'the store detected %d deadlock(s) between the re-issue and the retirement; retirement attempts: %d, '
            .'re-issue exit: %d, re-issue stderr: %s',
            $deadlocks, $attempts, $exit, $stderr === '' ? '(empty)' : $stderr,
        ));
        $this->assertSame(1, $attempts, 'the retirement needed a second attempt — its first met a concurrency error');
        $this->assertSame(SeatRetirementOutcome::RETIRED, $outcome->outcome);
        $this->assertSame(1, $exit, 'the re-issue did not end in the command\'s refusal (FAILURE) for a retired seat');
        $this->assertSame(1, DB::connection(self::FIXTURE)->table('ingest_tokens')->where('seat_ref', $this->seatRef)->count(),
            'a token was written for a seat retired before the re-issue read it');
    }

    private function deadlocks(): int
    {
        return (int) DB::connection(self::PROBE)->selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->Value;
    }

    /**
     * The re-issue as an operator runs it, against the store this test's connections use: the child gets
     * the resolved connection settings in its environment, which beat its `.env`.
     *
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startReissue(): array
    {
        $db = DB::connection(self::FIXTURE)->getConfig();

        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => (string) ($db['host'] ?? ''),
            'DB_PORT' => (string) ($db['port'] ?? ''),
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
        ]);

        // The plaintext token goes to stdout on success; it is discarded unread.
        $process = proc_open(
            [PHP_BINARY, base_path('artisan'), 'mezzanine:ingest-token:issue', $this->seat(), '--by=suite'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process, 'could not start the re-issue process');

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + self::BOUND_S;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::connection(self::PROBE)->selectOne(
                "SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'",
            )->n;

            if ($waiting > 0) {
                return;
            }

            if (! proc_get_status($child['process'])['running']) {
                [$exit, $stderr] = $this->finish($child);
                $this->fail("the re-issue finished (exit $exit) without ever waiting on the retirement's lock: $stderr");
            }

            // ⚠ NOT FASTER: `INNODB_TRX` answers from a cache the engine refreshes only once it has gone
            // unread for a short idle time, so a tight poll keeps reading the snapshot taken before the
            // child arrived. Measured on MariaDB 11.8.6: a 20 ms poll never saw the wait in 30 s.
            usleep(250_000);
        }

        $this->fail('the re-issue never reached a lock wait within '.self::BOUND_S.' s');
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>}  $child
     * @return array{int, string}
     */
    private function finish(array $child): array
    {
        $deadline = microtime(true) + self::BOUND_S;

        while (($status = proc_get_status($child['process']))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($child['process']);
                $this->fail('the re-issue process did not finish within '.self::BOUND_S.' s');
            }

            usleep(20_000);
        }

        $stderr = trim((string) stream_get_contents($child['pipes'][2]));
        fclose($child['pipes'][2]);
        proc_close($child['process']);

        return [(int) $status['exitcode'], $stderr];
    }
}
