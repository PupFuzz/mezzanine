<?php

/**
 * app-mysql-endpoint.php — where the application's `mysql` connection CONNECTS, answered by the framework's
 * own code. Shared by every out-of-process checker that has to address the store the app addresses
 * (card#10318): tools/ci-store-probe.php, which connects there, and bin/env-mirror-diff.oracle.php, which
 * classifies it. REQUIRED after server/vendor/autoload.php, never run.
 *
 * NOTHING HERE IS A RE-IMPLEMENTATION. The two steps Laravel takes between a connection's config array and
 * the DSN pdo_mysql is handed are taken here by calling them:
 *   · `Illuminate\Support\ConfigurationUrlParser::parseConfiguration()` — what `DatabaseManager::configuration()`
 *     does to every connection: DB_URL's components, and its query string, merged over the connection's keys;
 *   · `Illuminate\Database\Connectors\MySqlConnector::getDsn()` — the socket-over-host choice (`hasSocket()`'s
 *     `isset() && ! empty()`, so `DB_SOCKET=0` is no socket at all) and the DSN itself.
 * A checker restating either rule is a second answer to a question the framework already answers, and the
 * two drift: that is exactly how the store probe came to ignore DB_SOCKET while the app honoured it.
 */

declare(strict_types=1);
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\ConfigurationUrlParser;

/**
 * @param  array<string, mixed>  $connection  a `connections.mysql` array as config/database.php returns it
 * @return array{config: array<string, mixed>, dsn: string} the URL-merged config and the DSN built from it
 */
function app_mysql_endpoint(array $connection): array
{
    static $getDsn = null;
    if ($getDsn === null) {
        $getDsn = new ReflectionMethod(MySqlConnector::class, 'getDsn');
    }

    $config = (new ConfigurationUrlParser)->parseConfiguration($connection);

    return ['config' => $config, 'dsn' => $getDsn->invoke(new MySqlConnector, $config)];
}
