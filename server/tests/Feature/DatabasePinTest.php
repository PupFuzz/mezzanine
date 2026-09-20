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
 *
 * It also asserts the DOCUMENT against the file, for the pins where nothing else would (card#9803
 * — DOCUMENTED_VALUE_KEYS below states which ones those are and why the rest are left out).
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

    /**
     * The pins whose VALUE § 6.2's verbatim block is compared against, and the only ones (card#9803).
     *
     * ⛔ THIS IS A SHORTER LIST THAN PAIRED_KEYS ON PURPOSE, AND THE OMISSIONS ARE A FINDING RATHER
     * THAN AN OVERSIGHT. § 6.2 declares itself the OWNER of these values, so a seat that finds
     * phpunit.xml disagreeing with it edits the FILE to match the DOCUMENT. What decides whether a
     * key belongs here is therefore one question asked per key: if a drifted value in § 6.2 were
     * copied into phpunit.xml, would anything already red?
     *
     *   - `DB_DATABASE`, `REDIS_DB`, `REDIS_CACHE_DB` — YES, so they are NOT re-checked here. Each
     *     one moves a RESOLVED value that Tests\TestCase::PINS asserts by name
     *     (`database.connections.mysql.database`, `database.redis.default.database`,
     *     `database.redis.cache.database`), and that guard ABORTS the run in createApplication()
     *     before any trait migrates. The drift cannot reach a green run, and a second statement of
     *     the same guarantee here would be another copy of the values to keep in step.
     *   - `REDIS_URL` — NO. Nothing asserts it anywhere: config('database.redis.*.database') keeps
     *     reporting the pinned index, because RedisManager::resolve() applies the URL through
     *     ConfigurationUrlParser only when it BUILDS a connection, and that parser's getDatabase()
     *     takes the index from the URL's PATH. Measured on this tree against the framework's own
     *     parser: the config array config/database.php builds for `redis.default` with the pinned
     *     `database` and a `url` of `redis://127.0.0.1:6379/9` parses to database `9`, which
     *     PhpRedisConnector then SELECTs. § 6.2's own bullet says the same in prose.
     *   - `DB_URL` — PARTIALLY, which is not enough. Tests\TestCase's second read compares
     *     DB::connection()->getDatabaseName(), so a URL whose path names another database is
     *     refused. But ConfigurationUrlParser::getPrimaryOptions() also replaces the driver, host,
     *     port, username and password, and nothing compares those: measured on this tree, a DB_URL
     *     of `mysql://…@other-host:3307/mezzanine_test` resolves to database `mezzanine_test` on
     *     host `other-host`, which every existing guard passes — while the suite destructively
     *     rebuilds a database of that name on a server nobody chose.
     *
     * @var list<string>
     */
    private const DOCUMENTED_VALUE_KEYS = [
        'DB_URL',
        'REDIS_URL',
    ];

    private function phpunitXml(): SimpleXMLElement
    {
        $path = base_path('phpunit.xml');

        $this->assertFileExists($path);

        return new SimpleXMLElement((string) file_get_contents($path));
    }

    /**
     * § 6.2's verbatim XML block, handed to the SAME reader that reads phpunit.xml.
     *
     * The block is a FRAGMENT — a comment and the pin entries, with no root element — so it is
     * wrapped in the two elements the reader's xpath walks. Wrapping rather than re-parsing is the
     * point: declaredPinKeys() and declaredPinValues() then answer "what does this declare?" for
     * the document and for the file through one implementation. A second idea of what a pin is, in
     * a check whose whole subject is two copies of the pins disagreeing, would be the defect one
     * layer further out again.
     */
    private function documentedPinBlock(): SimpleXMLElement
    {
        $path = base_path('../docs/design/FLEET-STATE.md');

        $this->assertFileExists($path);

        $doc = (string) file_get_contents($path);

        $from = strpos($doc, '### 6.2 Database names, pinned and published');
        $this->assertIsInt($from, '§ 6.2 is not in FLEET-STATE.md under the heading this check reads');

        $to = strpos($doc, '### 6.3', $from);
        $this->assertIsInt($to, '§ 6.3 is not where this check expects it, so it cannot tell where § 6.2 ends');

        $matched = preg_match('/```xml\n(.*?)```/s', substr($doc, $from, $to - $from), $block);

        $this->assertSame(1, $matched,
            '§ 6.2 no longer carries an xml block. It declares itself the OWNER of the store-isolation '
            .'pin values and states them there; if that block has moved, this check is reading a section '
            .'with no pins in it and would pass everything.');

        return new SimpleXMLElement('<phpunit><php>'.$block[1].'</php></phpunit>');
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
     * The two halves of one pin as a source DECLARES them — the companion to declaredPinKeys(),
     * over the same element, so the document and the file are read by one reader here too.
     *
     * A half that is absent is `null` rather than `''`, because "declares nothing" and "declares
     * the empty string" are the two states this family of pins is built out of.
     *
     * @return array{env: string|null, server: string|null}
     */
    private function declaredPinValues(SimpleXMLElement $xml, string $key): array
    {
        $env = $xml->xpath(sprintf('//php/env[@name="%s"]', $key)) ?: [];
        $server = $xml->xpath(sprintf('//php/server[@name="%s"]', $key)) ?: [];

        return [
            'env' => isset($env[0]) ? (string) $env[0]['value'] : null,
            'server' => isset($server[0]) ? (string) $server[0]['value'] : null,
        ];
    }

    /**
     * The set-equality leg (card#9742), taking kanban-solo's shape from rt#506 as offered.
     *
     * The test below iterates PAIRED_KEYS, so before this existed the guard reported on whatever
     * subset that hand-written constant happened to name: a pin added to phpunit.xml and not
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

    /**
     * The doc↔file leg (card#9803), and the last copy of these values that nothing compared.
     *
     * `docs/design/FLEET-STATE.md` § 6.2 declares itself the OWNER of the store-isolation pin
     * values and carries them as a VERBATIM xml block. The tests above assert the file against
     * itself and against a constant in this class; none of them ever opened the document. So the
     * owning copy could drift from the implementing one in either direction, silently — and the
     * harm is not the disagreement, it is the CORRECTION: a seat that finds phpunit.xml
     * disagreeing with the section that owns the values edits the FILE, which on a shared host is
     * an edit to the pins deciding which database RefreshDatabase rebuilds destructively and which
     * Redis index a flush reaches.
     *
     * ⚠ SCOPED TO DOCUMENTED_VALUE_KEYS, not to the whole block. Every pin it leaves out is
     * already covered — a drifted value copied into the file aborts the run at Tests\TestCase's
     * resolved read — and that constant's docblock argues it key by key. Re-checking them here for
     * symmetry would add a copy of the values without adding a guarantee.
     */
    public function test_section_62s_declared_url_pins_are_the_ones_phpunit_xml_carries(): void
    {
        $document = $this->documentedPinBlock();
        $file = $this->phpunitXml();

        // A CONTROL FIRST, on the document side, because that is the side this check newly reads.
        // Without it, a block that stopped parsing — or a heading that moved — would compare null
        // against null for every key and report green over a document it never read.
        $missingFromDocument = array_values(array_diff(
            self::DOCUMENTED_VALUE_KEYS,
            $this->declaredPinKeys($document),
        ));

        $this->assertSame([], $missingFromDocument, sprintf(
            '§ 6.2\'s xml block no longer declares %s. That section owns these values, so a block '
            .'that has stopped naming a pin either dropped it — and a seat copying the block into '
            .'phpunit.xml would then delete that pin — or this check has stopped reading the block.',
            implode(', ', $missingFromDocument),
        ));

        foreach (self::DOCUMENTED_VALUE_KEYS as $key) {
            $declared = $this->declaredPinValues($document, $key);
            $carried = $this->declaredPinValues($file, $key);

            $this->assertSame($declared, $carried, sprintf(
                'docs/design/FLEET-STATE.md § 6.2 and server/phpunit.xml disagree about the %s pin. '
                .'§ 6.2 declares <env> %s and <server> %s; phpunit.xml carries <env> %s and <server> %s. '
                .'§ 6.2 OWNS these values, so the reflex on reading this is to edit the file to match '
                .'the document — establish which copy drifted before doing that, because nothing else '
                .'in this suite notices %s resolving somewhere new.',
                $key,
                var_export($declared['env'], true),
                var_export($declared['server'], true),
                var_export($carried['env'], true),
                var_export($carried['server'], true),
                $key,
            ));
        }
    }
}
