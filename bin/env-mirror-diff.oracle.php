<?php

/**
 * env-mirror-diff.oracle.php — the ORACLE side of bin/env-mirror-diff.sh.
 *
 * It answers, for a directory holding a `.env`, the two questions `bin/deploy.sh` MIRRORS in bash and
 * therefore cannot answer about itself:
 *
 *   scan     — does vlucas/phpdotenv parse that FILE at all, and what does it then HOLD for each key?
 *              A file it refuses is a file Laravel reads NO value from: every request and every artisan
 *              command on that host dies at boot.
 *   locality — where does the app actually CONNECT, and does its connection carry a CA? Resolved through
 *              the real `server/config/database.php`, the real `Illuminate\Support\ConfigurationUrlParser`
 *              and the real `MySqlConnector::getDsn()` — the DSN pdo_mysql would be handed.
 *
 * ⚠ NOTHING HERE IS A RE-IMPLEMENTATION. Every answer comes out of `server/vendor/` and `server/config/`.
 * The one judgement this file makes that no app code makes is WHICH HOST NAMES ARE THIS HOST, and that
 * one is stated below, in `LOOPBACK_HOSTS`, with the driver holding it against `bin/deploy.sh`'s own
 * `ENV_LOOPBACK_HOSTS` on every run (`env-mirror-diff.sh`'s loopback-set drift check) so the two cannot
 * drift apart silently. It is deliberately a SECOND statement rather than a read of the first: an oracle
 * that took its answer from the script under test could not disagree with it.
 *
 * ISOLATION. Both modes run the whole population in ONE process — a fork per fixture costs ~60 ms of PHP
 * startup and the population is in the hundreds — and each fixture gets a FRESH `ArrayAdapter` repository
 * that touches neither `$_ENV`, `$_SERVER` nor `putenv`. So no fixture can see another's values, and no
 * variable already in the process environment can reach `env()`: `H::install()` below writes that fresh
 * repository into `Illuminate\Support\Env`'s own static, which is what `env()` reads. Proven on this
 * surface rather than assumed: the driver's `--self-check` runs the population twice, the second time in
 * reverse order, and reds unless every cell answers identically.
 *
 * USAGE — fixture directories on stdin, one per line, one TSV line out per directory:
 *   php bin/env-mirror-diff.oracle.php scan KEY…   →  DIR  parse   VALUE-PER-KEY…
 *   php bin/env-mirror-diff.oracle.php locality    →  DIR  boot  STORE  CA  CACHE-DRIVER
 *   php bin/env-mirror-diff.oracle.php loopback-hosts  →  this file's LOOPBACK_HOSTS, space separated
 *
 * A VALUE is `unset` when the key is not defined, or `=` followed by the value with `\`, TAB, CR, LF and
 * NUL escaped — the same encoding `env-mirror-diff.mirror.sh` prints, so a cell compares as one string.
 * `=` prefixes every present value so that an empty one is not the same token as an absent one.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;

/**
 * WHICH HOST NAMES REACH A STORE WITHOUT LEAVING THIS HOST. See the header: this is the one judgement
 * here that no app code makes, and the driver checks it against bin/deploy.sh's ENV_LOOPBACK_HOSTS on
 * every run. `localhost` is pdo_mysql's name for the Unix socket; `[::1]` is how parse_url hands ::1 back.
 */
const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

/** Cache drivers that keep nothing past the request that wrote it (docs/PLAN.md § 5). */
const EPHEMERAL_CACHE_DRIVERS = ['array', 'null'];

function fail(string $message): never
{
    fwrite(STDERR, "env-mirror-diff.oracle.php: $message\n");
    exit(2);
}

$repoRoot = getenv('MEZZ_REPO');
if ($repoRoot === false || $repoRoot === '') {
    fail('MEZZ_REPO is not set. It names the checkout whose server/vendor is the oracle.');
}
$autoload = $repoRoot . '/server/vendor/autoload.php';
if (! is_file($autoload)) {
    fail("no $autoload — run `composer install` in server/. This harness reads phpdotenv, Illuminate\\Support "
        . 'and config/database.php out of the REAL vendor tree; there is nothing to compare against without it.');
}
require $autoload;

$mode = $argv[1] ?? '';
$keys = array_slice($argv, 2);

if ($mode === 'loopback-hosts') {
    echo implode(' ', LOOPBACK_HOSTS), "\n";
    exit(0);
}
if ($mode !== 'scan' && $mode !== 'locality') {
    fail("unknown mode '$mode' (scan | locality | loopback-hosts)");
}
if ($mode === 'scan' && $keys === []) {
    fail('scan mode needs at least one KEY to compare');
}

/**
 * Installs a repository into Illuminate\Support\Env's own static, which is what the global `env()` helper
 * reads. `$repository` is `protected static` on Env and is NOT redeclared here, so this writes the very
 * property `Env::getRepository()` returns — the app's boot path, pointed at a repository that holds one
 * fixture and nothing else.
 */
final class H extends Env
{
    public static function install(\Dotenv\Repository\RepositoryInterface $repository): void
    {
        static::$repository = $repository;
    }
}

/** The CA option key `server/config/database.php` itself uses. */
$caOption = null;
if ($mode === 'locality') {
    if (! extension_loaded('pdo_mysql')) {
        // Without the driver `config/database.php` hands back `'options' => []` for EVERY fixture, so the
        // CA axis would report "no CA" everywhere and agree with anything. A differential whose axis is
        // dead reports zero disagreements for the wrong reason; refuse instead of reporting that.
        fail('pdo_mysql is not loaded, so config/database.php builds no `options` array at all and the '
            . 'CA axis of this differential cannot fail. Install the extension.');
    }
    $caOption = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA;
}

/** The escaping bin/env-mirror-diff.mirror.sh's `esc` applies, so both sides print one comparable string. */
function enc(?string $value): string
{
    if ($value === null) {
        return 'unset';
    }

    return '=' . strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\r" => '\\r', "\n" => '\\n', "\0" => '\\0']);
}

/** A fresh, immutable, process-environment-free repository. */
function freshRepository(): \Dotenv\Repository\RepositoryInterface
{
    return RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->immutable()->make();
}

/**
 * Where the app CONNECTS, read off the DSN `Illuminate\Database\Connectors\MySqlConnector` would build.
 * Calling the connector's own method is the point: `hasSocket()`'s `isset() && ! empty()` decides whether
 * `DB_SOCKET=false` or `DB_SOCKET=0` is a socket at all, and a restatement of that here would be a second
 * answer to a question the framework already answers.
 */
function storeFromDsn(array $connection): string
{
    static $getDsn = null;
    if ($getDsn === null) {
        $getDsn = new ReflectionMethod(\Illuminate\Database\Connectors\MySqlConnector::class, 'getDsn');
        $getDsn->setAccessible(true);
    }
    $dsn = $getDsn->invoke(new \Illuminate\Database\Connectors\MySqlConnector, $connection);

    if (str_starts_with($dsn, 'mysql:unix_socket=')) {
        return 'socket';
    }
    // `mysql:host=…;port=…` — the first key follows the scheme's `:`, every later one a `;`.
    if (! preg_match('/[:;]host=([^;]*)/', $dsn, $m)) {
        return 'remote';   // no host in the DSN at all: not established as this host, so not exempt.
    }

    return in_array($m[1], LOOPBACK_HOSTS, true) ? 'loopback' : 'remote';
}

/**
 * The cache driver the app would end up on, resolved through the real server/config/cache.php.
 *
 * That file calls `storage_path()` for the `file` store's paths, and `storage_path()` asks the container
 * for them — so reading the real file needs a container in place. The one below answers that single
 * question and nothing else: it is scaffolding to let the REAL config file be read, not a stand-in for it.
 * Which store the app ends up on is decided by `env('CACHE_STORE')` and that file's own `stores` map.
 */
function cacheDriver(string $repoRoot): string
{
    // `Container::getInstance()` MINTS a bare container when there is none, so "is one installed?" cannot
    // be asked of it — this flag is what records that ours is the one in place.
    static $installed = false;
    if (! $installed) {
        $installed = true;
        \Illuminate\Container\Container::setInstance(new class extends \Illuminate\Container\Container
        {
            public function storagePath($path = '')
            {
                return sys_get_temp_dir() . '/env-mirror-diff-storage' . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
            }
        });
    }

    $cache = require $repoRoot . '/server/config/cache.php';
    $default = $cache['default'] ?? null;
    if ($default === null) {
        // CacheManager::getDefaultDriver() hands null on to the `null` store — the DISCARD driver.
        return 'null';
    }
    if (! is_string($default)) {
        return 'undefined';
    }

    return $cache['stores'][$default]['driver'] ?? 'undefined';
}

$out = fopen('php://stdout', 'w');
while (($line = fgets(STDIN)) !== false) {
    $dir = rtrim($line, "\n");
    if ($dir === '') {
        continue;
    }

    $repository = freshRepository();
    H::install($repository);

    try {
        Dotenv::create($repository, $dir)->load();
        $parsed = true;
    } catch (\Throwable $e) {
        $parsed = false;
    }

    if ($mode === 'scan') {
        $fields = [$dir, $parsed ? 'ok' : 'reject'];
        foreach ($keys as $key) {
            // The REPOSITORY's value, not Env::get's — this compares against env_get, which is deploy.sh's
            // reader of a line's TEXT. What Env::get then RESOLVES that text to is env_laravel_value's
            // question, and the locality differential is where it is asked.
            $fields[] = $parsed ? enc($repository->get($key)) : '-';
        }
        fwrite($out, implode("\t", $fields) . "\n");

        continue;
    }

    if (! $parsed) {
        fwrite($out, implode("\t", [$dir, 'bootfail', '-', '-', '-']) . "\n");

        continue;
    }

    $connection = (require $repoRoot . '/server/config/database.php')['connections']['mysql'];
    $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);

    fwrite($out, implode("\t", [
        $dir,
        'ok',
        storeFromDsn($connection),
        array_key_exists($caOption, $connection['options'] ?? []) ? 'set' : 'none',
        cacheDriver($repoRoot),
    ]) . "\n");
}
fclose($out);
