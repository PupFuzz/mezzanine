#!/usr/bin/env php
<?php

/**
 * ci-store-probe.php — the MariaDB lane's evidence that it is talking to the engine it claims,
 * and that the step which just ran actually wrote to that engine. card#9250.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS. Until card#9250 no migration in this repository had ever been executed against
 * MariaDB: the suite and `php-tests` are SQLite, production is MariaDB (`docs/PLAN.md` D-15 as
 * amended 2026-09-09), and Laravel compiles the two through DIFFERENT schema grammars. The
 * `php-tests-mariadb` lane closes that. But a lane that *says* MariaDB and silently runs on
 * something else is worse than no lane at all — it is the exact shape
 * `docs/design/FLEET-STATE.md § 6.2` finding 4 records: a bridge repo's MariaDB matrix that
 * re-ran both legs on SQLite, green, testing nothing. So the lane does not assert its backend
 * from a declaration. It asks the server.
 *
 * WHAT IT CHECKS, and each one can fail:
 *
 *   1. THE PRODUCT. `SELECT VERSION()` must name MariaDB. A MySQL server answering on 3306 is a
 *      different engine with a different grammar and this lane's whole claim is the engine.
 *   2. THE VERSION FLOOR, **derived and never retyped**. `docs/design/FLEET-STATE.md § 6.1`'s
 *      engine row is the one place this repository states which MariaDB it requires; this file
 *      parses that row and compares the live server against it. The workflow's `services:` image
 *      tag CANNOT be derived — `services:` is resolved before any step runs — so it is a literal,
 *      and this check is what keeps that literal honest: raise § 6.1's floor above the pinned tag
 *      and the lane reds by name instead of silently certifying migrations on an engine below the
 *      floor production is promised.
 *   3. WHAT IS IN THE SCHEMA, against an expectation the caller states. `--expect-tables=none`
 *      before a migration and `=some` after it is a control pair: the `some` alone proves nothing
 *      (the tables could be left over from an earlier step), and the `none` alone proves nothing.
 *      Run in that order they prove the migration reached THIS database on THIS server. The lane
 *      uses the same pair a second time around `composer test`, which is the only way to show the
 *      SUITE — which selects its backend through `phpunit.xml` plus an exported variable, not
 *      through anything this script can see — ran on MariaDB rather than on SQLite.
 *
 * ⛔ WHAT IT IS NOT. It is not a schema check: it counts tables, it does not compare them to
 * `§ 6.4`. `Tests\Feature\MySqlColumnTypeTest` owns the emitted DDL, and the migrations
 * themselves own whether they apply. This file only proves WHERE they applied.
 *
 * Connection parameters come from the environment (`DB_HOST`, `DB_PORT`, `DB_DATABASE`,
 * `DB_USERNAME`, `DB_PASSWORD`) — the same variables the lane exports for Laravel, so the script
 * and the application cannot end up addressing different servers.
 */

declare(strict_types=1);

const FLEET_STATE = __DIR__.'/../docs/design/FLEET-STATE.md';

/** § 6.1's engine row. The `≥` is U+2265, as written in the document. */
const FLOOR_RE = '/^\|\s*Engine \/ version\s*\|\s*\*\*MariaDB\s*\x{2265}\s*([0-9]+(?:\.[0-9]+)+)\*\*\s*\|/mu';

function fail(string $message): never
{
    fwrite(STDERR, "::error::ci-store-probe: {$message}\n");
    exit(1);
}

function note(string $message): void
{
    fwrite(STDOUT, "ci-store-probe: {$message}\n");
}

/**
 * The MariaDB version floor, read from the document that owns it.
 *
 * A parse failure is a HARD error and never a skip: § 6.1's row having moved is precisely when a
 * silent default would certify the wrong engine.
 */
function declaredFloor(): string
{
    $path = FLEET_STATE;

    if (! is_file($path)) {
        fail("cannot read {$path} — the version floor is derived from § 6.1's engine row, not written here.");
    }

    if (preg_match(FLOOR_RE, (string) file_get_contents($path), $m) !== 1) {
        fail(
            'docs/design/FLEET-STATE.md § 6.1 has no engine row matching `| Engine / version | '
            .'**MariaDB >= X.Y.Z** |`. The floor is DERIVED from that row and is deliberately '
            .'written nowhere else, so this is a real drift, not a parser nit: fix the row or fix '
            .'FLOOR_RE in '.basename(__FILE__).'.'
        );
    }

    return $m[1];
}

/** Retry rather than fail on the first refused connection: the service container may still be opening. */
function connect(string $dsn, string $user, string $password): PDO
{
    $deadline = time() + 60;
    $last = '';

    do {
        try {
            return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $last = $e->getMessage();
            usleep(500_000);
        }
    } while (time() < $deadline);

    fail("could not connect to {$dsn} within 60s: {$last}");
}

$expect = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--expect-tables=')) {
        $expect = substr($arg, strlen('--expect-tables='));

        continue;
    }

    fail("unknown argument {$arg}. Usage: ci-store-probe.php --expect-tables=none|some");
}

if (! in_array($expect, ['none', 'some'], true)) {
    fail('--expect-tables=none|some is required.');
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_DATABASE') ?: '';
$user = getenv('DB_USERNAME') ?: 'root';
$password = (string) getenv('DB_PASSWORD');

if ($database === '') {
    fail('DB_DATABASE is unset. The lane pins it to the § 6.2 test database and this probe reads the same variable.');
}

$pdo = connect("mysql:host={$host};port={$port}", $user, $password);

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

// 1. The product. Laravel's mysql driver speaks to both, and only one of them is what D-15 pins.
if (stripos($version, 'mariadb') === false) {
    fail("the server on {$host}:{$port} reports VERSION() '{$version}', which does not name MariaDB. "
        .'docs/PLAN.md D-15 (amended 2026-09-09) pins the store to MariaDB; a lane certifying '
        .'migrations against anything else is certifying the wrong grammar.');
}

// 2. The floor, compared against the row that owns it.
$floor = declaredFloor();
$numeric = preg_match('/^[0-9]+(?:\.[0-9]+)*/', $version, $m) === 1 ? $m[0] : '0';

if (version_compare($numeric, $floor, '<')) {
    fail("the server reports {$numeric} ({$version}), below docs/design/FLEET-STATE.md § 6.1's "
        ."floor of MariaDB >= {$floor}. Either § 6.1's floor moved and the `services:` image tag "
        .'in .github/workflows/php-tests.yml was not moved with it, or the tag was lowered.');
}

note("VERSION() = {$version}; § 6.1 floor = {$floor} — satisfied.");

// 3. What is in the schema, against the caller's stated expectation.
$count = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
$count->execute([$database]);
$tables = (int) $count->fetchColumn();

if ($expect === 'none' && $tables !== 0) {
    fail("expected `{$database}` to be EMPTY and found {$tables} table(s). This probe is the control "
        .'half of a pair — without a measured empty state, the non-empty measurement after the '
        .'migration proves nothing about where the migration went.');
}

if ($expect === 'some' && $tables === 0) {
    fail("expected `{$database}` to hold tables after the step above and found NONE. The step did "
        .'not reach this server: it either failed silently or resolved to a different backend '
        .'(docs/design/FLEET-STATE.md § 6.2 finding 4 — the MariaDB matrix that ran on SQLite and '
        .'reported green).');
}

note("`{$database}` holds {$tables} table(s), as expected ({$expect}).");
