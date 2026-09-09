<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The DDL this application emits **on MySQL**, compiled without a MySQL server.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS EXISTS. `docs/design/FLEET-STATE.md § 6.4` declares
 * `feed_tokens.last_used_ip VARBINARY(16)`, and both token migrations shipped
 * `$table->binary('last_used_ip')` — which `Illuminate\…\Grammars\MySqlGrammar::typeBinary()`
 * compiles to **`blob`**, because it emits `varbinary({$length})` only `if ($column->length)` and
 * otherwise falls through. Two copies of one line, a document saying something else, and NOTHING
 * that could notice: the suite is pinned to SQLite (§ 6.2), where `binary()` is `blob` either way,
 * so the divergence was invisible to every green run this card ever produced.
 *
 * ⚠ WHAT THIS IS AND IS NOT EVIDENCE OF, stated because a green here is easy to over-read.
 * It proves the SQL TEXT this application would send to MySQL. It proves nothing about what MySQL
 * then DOES with it — that a `VARBINARY(16)` rejects a 17th byte, that an `ENUM` refuses an
 * unlisted value, that `ascii_bin` compares case-exactly. Those need the engine, they remain on
 * the PR body's unexercised list, and they are card #7523's (the store host). The gap this closes
 * is the narrow one that actually bit: a column TYPE that silently differs from the document.
 *
 * ⚠ NO SERVER IS CONTACTED. `Connection::statement()` returns `true` before it ever reaches
 * `getPdo()` while `pretending()`, so the migration below compiles and is never executed. The
 * `database` name is overridden to one that cannot exist, so that if that ever stopped being
 * true this test would fail loudly against a missing database rather than quietly create tables
 * on a real one.
 */
class MySqlColumnTypeTest extends TestCase
{
    /** § 6.4's own declarations, for the columns whose emitted type this file guards. */
    private const DECLARED = [
        'feed_tokens' => ['`last_used_ip` varbinary(16) null'],
        'ingest_tokens' => ['`last_used_ip` varbinary(16) null'],
    ];

    public function test_the_token_tables_emit_section_64s_varbinary_16_on_mysql(): void
    {
        foreach (self::DECLARED as $table => $fragments) {
            $ddl = $this->createTableSqlOnMySql($table);

            foreach ($fragments as $fragment) {
                $this->assertStringContainsString($fragment, $ddl,
                    $table.' does not emit § 6.4\'s declared column type on MySQL');
            }

            // The control that makes the assertions above a measurement rather than a match
            // against a string that happens to be there: `blob` is what a length-less `binary()`
            // emits, it is what this schema used to emit, and it must be gone.
            $this->assertStringNotContainsString('blob', $ddl,
                $table.' still emits a length-less binary column');
        }
    }

    /**
     * ⛔ THE CONTROL FOR THE WHOLE FILE. If `binary()` with and without a length compiled to the
     * same thing, every assertion above would pass over a defect — so the discriminating
     * behaviour is asserted directly, on the real grammar, in both directions.
     */
    public function test_a_length_less_binary_column_really_does_compile_to_blob(): void
    {
        $connection = $this->mysqlConnection();

        $blueprint = new Blueprint($connection, 'probe');
        $blueprint->create();
        $blueprint->binary('without_length');
        $blueprint->binary('with_length', 16);

        $sql = implode("\n", $blueprint->toSql());

        $this->assertStringContainsString('`without_length` blob', $sql);
        $this->assertStringContainsString('`with_length` varbinary(16)', $sql);
    }

    /**
     * ⛔ CARD#9077's RESET-TOKEN TABLE, ON THE STORE IT DEPLOYS TO. `token_hash` is an identifier
     * column in § 6.1's sense — it is LOOKED UP BY EQUALITY and nothing else — so it must compare
     * exactly. The store's configured collation `utf8mb4_unicode_ci` is case-insensitive, and a digest
     * matched case-insensitively is a digest that matches rows it does not equal: on a 64-character
     * hex value that is a 2^64-fold reduction in the work of finding a colliding lookup key.
     *
     * ⚠ THE SUITE CANNOT SEE THIS ANY OTHER WAY, which is the whole reason this file exists: § 6.2
     * pins the suite to a store where every collation is already binary, so the divergence is
     * invisible to a green run. Compiled here, never executed — see the class docblock.
     */
    public function test_the_reset_token_table_emits_an_exactly_comparing_identifier_column_on_mysql(): void
    {
        $ddl = $this->createTableSqlOnMySql('two_factor_reset_tokens');

        $this->assertStringContainsString("`token_hash` char(64) character set ascii collate 'ascii_bin'", $ddl);

        // THE CONTROL: a column that is NOT an identifier keeps the table's own charset, so this
        // arm discriminates between `Ddl::ascii()` applied where it belongs and `Ddl::ascii()`
        // applied to everything — which would pass a `contains` check on its own.
        $this->assertStringContainsString('`consumed_user_agent` varchar(255) null', $ddl);
        $this->assertStringContainsString("default character set utf8mb4 collate 'utf8mb4_unicode_ci'", $ddl);
    }

    /** The `CREATE TABLE` this application's migration for `$table` would send to MySQL. */
    private function createTableSqlOnMySql(string $table): string
    {
        $connection = $this->mysqlConnection();

        // The migrations call the `Schema` FACADE, which resolves the DEFAULT connection — so the
        // default is what has to move, not just the one we hold a handle to.
        config(['database.default' => 'mysql']);

        $log = $connection->pretend(function () use ($table) {
            $matches = glob(database_path('migrations/*_create_'.$table.'_table.php'));

            $this->assertCount(1, $matches, 'expected exactly one migration creating '.$table);

            (require $matches[0])->up();
        });

        $this->assertNotEmpty($log, 'the migration compiled to no statements at all');

        $create = array_values(array_filter(
            array_column($log, 'query'),
            fn (string $q) => str_starts_with($q, 'create table'),
        ));

        $this->assertCount(1, $create, 'expected exactly one CREATE TABLE for '.$table);

        return $create[0];
    }

    private function mysqlConnection(): Connection
    {
        // A database name nothing can resolve — see the class docblock: this is the failure the
        // "no server is contacted" claim is held to, not a decoration.
        config(['database.connections.mysql.database' => 'mezzanine_ddl_compile_only']);

        DB::purge('mysql');

        $connection = DB::connection('mysql');
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }
}
