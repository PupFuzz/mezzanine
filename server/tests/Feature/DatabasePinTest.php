<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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

    /**
     * Every key phpunit.xml CLAIMS as a pin, read from the file rather than listed here.
     *
     * A claim is either half of the pair — an <env force="true"> or a <server> — deliberately
     * UNIONED rather than intersected: a half-written pin is exactly the divergence this class
     * exists to catch, so it must enter the set and be judged, not fall out of it silently.
     * The unforced <env> entries (DB_CONNECTION, APP_ENV, and the rest) claim nothing: PHPUnit
     * lets an exported value beat them, so they are defaults, not pins.
     *
     * @return list<string>
     */
    private function declaredPinKeys(SimpleXMLElement $xml): array
    {
        $keys = [];

        foreach ($xml->xpath('//php/env[@force="true"]') ?: [] as $env) {
            $keys[] = (string) $env['name'];
        }

        foreach ($xml->xpath('//php/server') ?: [] as $server) {
            $keys[] = (string) $server['name'];
        }

        return array_values(array_unique($keys));
    }

    /**
     * The set-equality leg (card#9742), taking kanban-solo's shape from rt#506 as offered.
     *
     * The test below iterates PAIRED_KEYS, so before this existed the guard reported on whatever
     * subset that hand-written constant happened to name: a sixth pin added to phpunit.xml and not
     * to the constant was simply never visited, and the suite stayed green while the new pin — the
     * one most likely to be wrong, being the newest — was UNGUARDED. Set equality is what makes the
     * constant an assertion about the file instead of an agreement maintained by hand.
     *
     * BOTH DIRECTIONS, because a key removed from phpunit.xml while still named here is the same
     * defect pointed the other way: the guard then asserts a pairing for a pin that no longer
     * isolates anything.
     */
    public function test_the_guarded_keys_are_exactly_the_pins_phpunit_xml_declares(): void
    {
        $declared = $this->declaredPinKeys($this->phpunitXml());
        $guarded = self::PAIRED_KEYS;

        sort($declared);
        sort($guarded);

        $unguarded = array_diff($declared, $guarded);
        $absent = array_diff($guarded, $declared);

        $message = 'phpunit.xml and DatabasePinTest::PAIRED_KEYS no longer describe the same pin set, '
            .'so the guard below is reporting on a set it does not define '
            .'(docs/design/FLEET-STATE.md § 6.2 owns these pins).';

        if ($unguarded !== []) {
            $message .= sprintf(
                ' UNGUARDED: phpunit.xml pins %s, which PAIRED_KEYS does not name, so nothing asserts that pin is a forced <env> with a matching <server>. Add it to PAIRED_KEYS.',
                implode(', ', $unguarded)
            );
        }

        if ($absent !== []) {
            $message .= sprintf(
                ' GUARDED BUT ABSENT: PAIRED_KEYS names %s, which phpunit.xml no longer pins. Restore the pin, or drop the key here and in § 6.2.',
                implode(', ', $absent)
            );
        }

        $this->assertSame($guarded, $declared, $message);
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

    public function test_db_connection_is_declared_as_mysql_and_never_forced(): void
    {
        // phpunit.xml's comment argues both halves. UNFORCED, so an export of another connection
        // wins and Tests\TestCase then aborts the run by name, rather than a forced pin quietly
        // substituting `mysql` for what the caller asked. DECLARED AS `mysql`, asserted HERE and
        // not only through the resolved value: CI exports DB_CONNECTION=mysql, which beats this
        // declaration, so a flip back to another engine would pass the resolved guard in CI and
        // reach only the checkouts that export nothing.
        $env = $this->phpunitXml()->xpath('//php/env[@name="DB_CONNECTION"]');

        $this->assertCount(1, $env ?: []);
        $this->assertNull($env[0]['force'], 'phpunit.xml forces DB_CONNECTION, so an export of another connection is silently overridden instead of refused.');
        $this->assertSame(
            'mysql',
            (string) $env[0]['value'],
            'phpunit.xml declares a DB_CONNECTION other than mysql. MariaDB, through the mysql connection, is the only supported engine (docs/PLAN.md D-15, card#9328).'
        );
    }

    public function test_the_resolved_store_configuration_is_the_pinned_one(): void
    {
        $this->assertSame('mezzanine_test', config('database.connections.mysql.database'));
        $this->assertSame('11', (string) config('database.redis.default.database'));
        $this->assertSame('10', (string) config('database.redis.cache.database'));

        // AT-D2-14's resolved session time zone (§ 6.1): read from the SESSION, so it reds whether the
        // config key is removed or stops reaching the connection.
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS v')->v,
            "the store connection's session time zone is not UTC");
    }
}
