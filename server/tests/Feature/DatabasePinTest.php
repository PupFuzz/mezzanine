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
 * It also asserts the DOCUMENT that owns these values against the file that implements them
 * (card#9803 — the last test in this class, which states what that leg is worth key by key).
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
     * § 6.2's verbatim XML block, handed to the SAME reader that reads phpunit.xml.
     *
     * The block is a FRAGMENT — a comment and the pin entries, with no root element — so it is
     * wrapped in the two elements the reader's xpath walks. Wrapping rather than re-parsing is the
     * point: declaredPinKeys() and declaredPin() then answer "what does this declare?" for
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
     * One pin AS A SOURCE DECLARES IT — the companion to declaredPinKeys(), over the same element,
     * so the document and the file are read by one reader here too.
     *
     * A half that is absent is `null` rather than `''`, because "declares nothing" and "declares
     * the empty string" are the two states this family of pins is built out of.
     *
     * ⛔ `force` RIDES ALONG WITH THE VALUES BECAUSE IT IS AS LOAD-BEARING AS THEY ARE. § 6.2
     * finding 1 is that an unforced pin loses to an exported variable, so a block whose
     * `force="true"` has been dropped is a block that isolates nothing once copied into the file —
     * the same drift as a changed value, spelled in an attribute. Measured on the previous tree:
     * dropping `force` from § 6.2's DB_DATABASE entry left every test in this class green.
     *
     * ⚠ FROM THE <env> HALF ONLY, AND THAT ASYMMETRY IS THE BEHAVIOUR RATHER THAN AN OVERSIGHT.
     * PHPUnit's schema accepts `force` on a <server> entry too (`namedValueType` in phpunit.xsd
     * carries it), and its loader parses it — but PhpHandler::handleServerVariables() assigns
     * $_SERVER[$name] = $value unconditionally and never reads the flag. A `force` on a <server>
     * therefore changes nothing, so comparing it would report a disagreement with no consequence.
     *
     * @return array{env: string|null, force: string|null, server: string|null}
     */
    private function declaredPin(SimpleXMLElement $xml, string $key): array
    {
        $env = $xml->xpath(sprintf('//php/env[@name="%s"]', $key)) ?: [];
        $server = $xml->xpath(sprintf('//php/server[@name="%s"]', $key)) ?: [];

        return [
            'env' => isset($env[0]) ? (string) $env[0]['value'] : null,
            'force' => isset($env[0]) && isset($env[0]['force']) ? (string) $env[0]['force'] : null,
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
     * ⛔ IT COVERS EVERY PIN, AND THE FIRST VERSION OF IT DID NOT — worth recording, because that
     * first version was this class's own defect re-minted one layer out. It compared a hand-written
     * subset (the two URL pins, which the per-key finding below identified as the only ones a
     * drifted value could reach a green run through) in the very class whose card#9742 finding is
     * that a hand-written key list here silently under-reports. A sixth pin entered PAIRED_KEYS
     * under the compulsion of that leg and entered the subset only if someone remembered to redo
     * the argument by hand — and a URL-shaped one would then drift unwatched. Nothing here names a
     * key any more: the population is phpunit.xml's own pin set, read at run time. Covering
     * everything costs no copy of anything: the key sets are read from the two files, and so is
     * every pin's declaration.
     *
     * ⭐ WHAT THE PER-KEY FINDING ESTABLISHED, kept because it says what this leg is WORTH key by
     * key rather than which keys it visits:
     *   - REDIS_URL — this leg is the ONLY thing standing behind it. Its value is asserted nowhere:
     *     config('database.redis.*.database') keeps reporting the pinned index, because
     *     RedisManager::resolve() applies the URL through ConfigurationUrlParser only when it BUILDS
     *     a connection, and that parser's getDatabase() takes the index from the URL's PATH.
     *     Measured against the framework's own parser: the array config/database.php builds for
     *     redis.default, with `database` pinned and a `url` of redis://127.0.0.1:6379/9, parses to
     *     database '9' — which PhpRedisConnector then SELECTs.
     *   - DB_URL — this leg is the only thing standing behind every component but the database
     *     name. Tests\TestCase compares DB::connection()->getDatabaseName(), while
     *     ConfigurationUrlParser::getPrimaryOptions() also replaces driver, host, port, username and
     *     password. Measured with a DB_URL of mysql://127.0.0.1:3399/mezzanine_test pinned here:
     *     every config() pin passed, the bootstrap guard did not abort, and the run opened a
     *     connection to that host and port.
     *   - DB_DATABASE, REDIS_DB, REDIS_CACHE_DB — this leg is not their only guard, and which red
     *     you get depends on WHICH copy moved. A drift in the DOCUMENT reaches this leg and only
     *     this leg: the file still runs correctly, so nothing else has anything to complain about,
     *     and the disagreement is caught one step BEFORE a seat copies it into the file. A drift in
     *     the FILE never reaches this leg at all — it moves a resolved value, so Tests\TestCase
     *     aborts every test in createApplication() first. That abort is the weaker report, which is
     *     why it now names this cause too: on its own it blames an exported variable, which in this
     *     card's own harm sequence points the reader at the wrong copy.
     */
    public function test_section_62s_declared_pins_are_the_ones_phpunit_xml_carries(): void
    {
        $document = $this->documentedPinBlock();
        $file = $this->phpunitXml();

        // THE KEY LEG, WHICH IS ALSO THE CONTROL. Both sides are read from their own file, so this
        // is a direct doc↔file comparison and stays true whatever PAIRED_KEYS says. Without it the
        // comparison below could compare null against null, key by key, over a block that had
        // stopped parsing, and report green on a document it never read.
        //
        // Both assertSame() calls here put the DOCUMENT first and the FILE second, so PHPUnit's
        // "Expected" column is what § 6.2 declares and "Actual" is what phpunit.xml implements.
        // That is an ORIENTATION, not a verdict: § 6.2 owns these values, which makes it the
        // natural expectation to read against, and neither copy is automatically the correct one —
        // the messages say so outright.
        $declaredKeys = $this->declaredPinKeys($document);
        $pinnedKeys = $this->declaredPinKeys($file);

        sort($declaredKeys);
        sort($pinnedKeys);

        $undeclared = array_diff($pinnedKeys, $declaredKeys);
        $unpinned = array_diff($declaredKeys, $pinnedKeys);

        $message = 'docs/design/FLEET-STATE.md § 6.2 and server/phpunit.xml no longer pin the same '
            .'keys. § 6.2 declares itself the OWNER of these values and states them verbatim, so the '
            .'two copies have to name the same set.';

        if ($undeclared !== []) {
            $message .= sprintf(
                ' UNDECLARED: phpunit.xml pins %s, which § 6.2\'s block does not declare — a seat copying that block into the file would delete the pin. Add it to § 6.2, or drop the pin in both places.',
                implode(', ', $undeclared)
            );
        }

        if ($unpinned !== []) {
            $message .= sprintf(
                ' DECLARED BUT NOT PINNED: § 6.2 declares %s, which phpunit.xml does not pin — the owning document promises isolation the suite does not have. Restore the pin, or drop the key in both places.',
                implode(', ', $unpinned)
            );
        }

        $this->assertSame($declaredKeys, $pinnedKeys, $message);

        // And every pin's DECLARATION. The population is $pinnedKeys — phpunit.xml's own pin set,
        // read from the file a moment ago and just proven equal to the document's — so no list
        // anyone maintains decides which pins are compared, and a pin added to the file is
        // compared on the run that adds it. PAIRED_KEYS is deliberately NOT the population here:
        // it would be a correct answer only while someone keeps it current, and the leg that makes
        // them do that is a separate test which could be red for its own reasons.
        foreach ($pinnedKeys as $key) {
            $inDocument = $this->declaredPin($document, $key);
            $inFile = $this->declaredPin($file, $key);

            $this->assertSame($inDocument, $inFile, sprintf(
                'docs/design/FLEET-STATE.md § 6.2 and server/phpunit.xml disagree about the %s pin. '
                .'§ 6.2 declares <env value=%s force=%s> and <server value=%s>; phpunit.xml carries '
                .'<env value=%s force=%s> and <server value=%s>. '
                .'§ 6.2 OWNS these values, so the reflex on reading this is to edit the file to match '
                .'the document — establish which copy drifted before doing that, because this message '
                .'prints what each copy says and deliberately does not rule on which one is wrong. '
                .'A dropped force= isolates nothing once copied (§ 6.2 finding 1). For DB_URL and '
                .'REDIS_URL nothing else in this suite would notice; for the rest, copying this '
                .'disagreement into the file is what turns it into an abort that blames an exported '
                .'variable instead.',
                $key,
                var_export($inDocument['env'], true),
                var_export($inDocument['force'], true),
                var_export($inDocument['server'], true),
                var_export($inFile['env'], true),
                var_export($inFile['force'], true),
                var_export($inFile['server'], true),
            ));
        }
    }
}
