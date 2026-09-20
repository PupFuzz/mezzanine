<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
