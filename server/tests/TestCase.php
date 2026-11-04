<?php

namespace Tests;

use App\Ingest\IngestPipeline;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use DetectsLostConnections;

    /**
     * The store isolation guard required by docs/design/FLEET-STATE.md § 6.2.
     *
     * It runs here, in createApplication(), because that is after the container has resolved
     * configuration and before any trait has had a chance to migrate. It aborts rather than
     * skips: a suite that cannot prove which database it is about to write to must not run
     * at all, and skipping would report that decision as a pass.
     *
     * The values asserted are RESOLVED values, never the declarations in phpunit.xml — all three
     * mechanisms § 6.2 records (an exported variable beating an unforced <env>, force="true"
     * missing $_SERVER, a URL's path replacing the database) leave the declaration looking
     * correct while the resolved value is something else. They are resolved by TWO reads, and
     * neither is redundant:
     *   1. config() for every PINS key — catches the first two mechanisms;
     *   2. the database name the default CONNECTION itself resolves — catches the third, which
     *      config() cannot see (a DB_URL is applied only when the connection is built; see the
     *      comment on that check below).
     * DatabasePinTest covers the declarations separately.
     *
     * `database.default` IS PINNED BECAUSE THE SUITE WRITES THROUGH IT (card#9328). The database
     * pin alone proves only that the `mysql` connection points at `mezzanine_test`; every
     * migration and query in the suite goes through the DEFAULT connection, so without this the
     * guard said nothing about the store the suite actually writes to. It could not be pinned
     * while the suite defaulted to SQLite, and SQLite is no longer a supported configuration.
     * phpunit.xml leaves DB_CONNECTION unforced on purpose, so an export of any other connection
     * reaches this check and aborts here rather than being silently overridden.
     *
     * @var array<string, string>
     */
    private const PINS = [
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'mezzanine_test',
        'database.redis.default.database' => '11',
        'database.redis.cache.database' => '10',
    ];

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (self::PINS as $key => $expected) {
            $actual = config($key);

            if ((string) $actual !== $expected) {
                throw new RuntimeException(sprintf(
                    'Store isolation guard: config(%s) resolved to %s, expected %s. '.
                    'Aborting before any migration — see docs/design/FLEET-STATE.md § 6.2. '.
                    'The usual cause is an exported environment variable beating the pin in phpunit.xml — '.
                    'or a pin in phpunit.xml disagreeing with § 6.2, which owns these values. Check BOTH '.
                    'before editing either: PINS here is not automatically the stale copy.',
                    $key,
                    var_export($actual, true),
                    var_export($expected, true),
                ));
            }
        }

        // ⛔ config() IS NOT THE WHOLE RESOLVED VALUE. A DB_URL's path replaces the database
        // (§ 6.2 finding 3), and Laravel applies it only when it BUILDS the connection, so
        // config('database.connections.mysql.database') keeps reporting the pinned name while the
        // connection points elsewhere — measured on card#9328: with both DB_URL pin entries
        // removed from phpunit.xml and a DB_URL exported, the loop above passed. So the connection
        // the suite writes through is asked directly. Its PDO is lazy: this opens no connection.
        $expected = self::PINS['database.connections.mysql.database'];
        $resolved = DB::connection()->getDatabaseName();

        if ($resolved !== $expected) {
            throw new RuntimeException(sprintf(
                'Store isolation guard: the default connection resolves to database %s, expected %s. '.
                'Aborting before any migration — see docs/design/FLEET-STATE.md § 6.2. '.
                'The usual cause is a DB_URL whose path names another database — set by an export, or '.
                'pinned in phpunit.xml to match a drifted § 6.2. Check BOTH before editing either.',
                var_export($resolved, true),
                var_export($expected, true),
            ));
        }

        return $app;
    }

    /**
     * Every in-test HTTP request, with the session bounds it pinned put back when it returns
     * (card#12530).
     *
     * ⛔ A TEST'S REQUEST RUNS ON THE TEST'S OWN CONNECTION. In production a request's connection is its
     * own — the stock non-persistent SAPI opens it for the request and closes it after — so
     * `IngestPipeline::boundTheWriteSession()`'s `SET SESSION` ends with the request, which is what its
     * docstring rests on. Here the kernel runs in the test's process and writes through the connection
     * that holds `RefreshDatabase`'s transaction, so without this the bounds stayed on it for the rest of
     * the test: `idle_transaction_timeout` then had the server END that connection once the test sat
     * longer than `IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S` with its transaction open, and the
     * teardown rollback failed `2006 MySQL server has gone away`. Run 40727257707 attempt 1 failed so,
     * in a test whose shipped-client probes ran past the bound on a slow runner.
     * `AnIngestRequestsSessionBoundsEndWithTheRequestTest` asserts the put-back.
     *
     * WHAT IS PUT BACK is `IngestPipeline::SESSION_BOUNDS`'s variables, on the connection that was the
     * default when the request began — the connection `boundTheWriteSession()` writes through. A
     * session that was open before the request gets the values it held; one the request opened, or
     * reconnected, gets the server's (`DEFAULT`), which is what a fresh session starts with. A session
     * the server ended during the request has nothing left to put back. The values are read and
     * written on the raw PDO, so no query event fires and no test that counts or matches emitted SQL
     * sees them.
     *
     * ⚠ THE SET IS THE INGEST'S, NOT EVERY SESSION VARIABLE. Snapshotting all of them costs a scan of
     * `information_schema.SESSION_VARIABLES` per request — measured at tens of milliseconds on a loaded
     * host — on every request in the suite. `App\Fleet\SeatRetirement` sets `innodb_lock_wait_timeout`
     * too, and restores it itself.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $name = DB::getDefaultConnection();
        $variables = array_keys(IngestPipeline::SESSION_BOUNDS);

        $opened = DB::connection($name)->getRawPdo();
        $held = $opened instanceof PDO
            ? $opened->query('SELECT '.implode(', ', array_map(
                static fn (string $v): string => "@@SESSION.{$v} AS {$v}",
                $variables,
            )))->fetch(PDO::FETCH_ASSOC)
            : null;

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->putBackSessionBounds(DB::connection($name)->getRawPdo(), $opened, $held, $variables);
        }
    }

    /**
     * @param  array<string, mixed>|null  $held  the session's values before the request, read on `$opened`
     * @param  list<string>  $variables
     */
    private function putBackSessionBounds(mixed $pdo, mixed $opened, ?array $held, array $variables): void
    {
        if (! $pdo instanceof PDO) {
            return;
        }

        $same = $pdo === $opened;

        $assignments = array_map(
            static fn (string $v): string => $v.' = '.($same ? (int) $held[$v] : 'DEFAULT'),
            $variables,
        );

        try {
            $pdo->exec('SET SESSION '.implode(', ', $assignments));
        } catch (PDOException $e) {
            // The server ended this session during the request (`IngestWriteBoundTest` drives that):
            // its variables ended with it.
            if (! $this->causedByLostConnection($e)) {
                throw $e;
            }
        }
    }

    /**
     * A table name quoted the way the CONNECTED store quotes it — for the tests that match on
     * emitted SQL text.
     *
     * ⛔ WHY THIS EXISTS, measured rather than imagined (card#9250). Two tests matched query text
     * against a hand-written `"events"` / `"seats"`, which is SQLite's identifier quoting. MariaDB
     * uses backticks, so the first run of the `php-tests-mariadb` lane split them cleanly:
     * `SeatConsoleTest` FAILED, because its injection hook never fired and its own precondition
     * assertion caught that — and `At13AtomicBatchRejectionTest` PASSED, vacuously, because its
     * filter matched nothing and an empty set is exactly what it asserts. The second is the
     * dangerous one: a check that cannot fail reports green forever.
     *
     * The grammar is asked rather than branched on, so a third site cannot mint the bug by
     * copying a neighbouring line, and no test carries a list of engines.
     */
    protected function wrapTable(string $table): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($table);
    }
}
