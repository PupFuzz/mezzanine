<?php

namespace Tests\Feature;

use SimpleXMLElement;
use Tests\TestCase;

/**
 * The declaration half of docs/design/FLEET-STATE.md § 6.2's store isolation.
 *
 * Tests\TestCase's guard asserts the RESOLVED values and aborts the run when they are wrong.
 * This asserts the FILE, and it exists for one failure mode the resolved check cannot see:
 * an <env force="true"> whose paired <server> was edited or deleted still resolves correctly
 * on a machine with nothing exported, and silently stops resisting an exported variable. The
 * divergence is invisible until the day it matters.
 */
class DatabasePinTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const PAIRED_KEYS = [
        'DB_DATABASE',
        'DB_URL',
        'REDIS_DB',
        'REDIS_CACHE_DB',
        'REDIS_URL',
    ];

    private function phpunitXml(): SimpleXMLElement
    {
        $path = base_path('phpunit.xml');

        $this->assertFileExists($path);

        return new SimpleXMLElement((string) file_get_contents($path));
    }

    public function test_every_isolation_critical_key_is_a_forced_env_and_a_matching_server_entry(): void
    {
        $xml = $this->phpunitXml();

        foreach (self::PAIRED_KEYS as $key) {
            $env = $xml->xpath(sprintf('//php/env[@name="%s"]', $key));
            $server = $xml->xpath(sprintf('//php/server[@name="%s"]', $key));

            $this->assertCount(1, $env ?: [], "phpunit.xml has no single <env> entry for {$key}.");
            $this->assertCount(1, $server ?: [], "phpunit.xml has no single <server> entry for {$key}.");

            $this->assertSame(
                'true',
                (string) $env[0]['force'],
                "The <env> entry for {$key} is not forced, so PHPUnit will not overwrite an inherited value."
            );

            $this->assertSame(
                (string) $env[0]['value'],
                (string) $server[0]['value'],
                "The <env> and <server> entries for {$key} disagree."
            );
        }
    }

    public function test_db_connection_is_declared_but_never_forced(): void
    {
        // Forcing it is what turned another repo's MariaDB matrix into a SQLite run
        // reporting green (§ 6.2). The absence of force="true" here is the fix, so it is
        // asserted rather than left to survive by nobody noticing it.
        $env = $this->phpunitXml()->xpath('//php/env[@name="DB_CONNECTION"]');

        $this->assertCount(1, $env ?: []);
        $this->assertNull($env[0]['force']);
    }

    public function test_the_resolved_store_configuration_is_the_pinned_one(): void
    {
        $this->assertSame('mezzanine_test', config('database.connections.mysql.database'));
        $this->assertSame('11', (string) config('database.redis.default.database'));
        $this->assertSame('10', (string) config('database.redis.cache.database'));
    }

    public function test_the_sqlite_connection_does_not_consume_the_pinned_db_database(): void
    {
        // If these two ever became the same variable again, the pin above would point SQLite
        // at a file named after a MySQL schema and the suite would stop running.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertNotSame(
            config('database.connections.mysql.database'),
            config('database.connections.sqlite.database'),
        );
    }
}
