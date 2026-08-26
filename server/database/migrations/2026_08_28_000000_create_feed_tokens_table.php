<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `docs/design/FLEET-STATE.md § 6.4`'s `feed_tokens` — the READ side's credential store (§ 9).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ ONE DELIBERATE DIVERGENCE FROM § 6.4's DDL, AND IT IS A REUSE RATHER THAN A DRIFT.
 *
 * § 6.4 declares `token_hash BINARY(32)`. This table stores the same SHA-256 as `CHAR(64)` ASCII
 * hex, which is what the sibling `ingest_tokens` table has stored since card #7338 and what
 * `App\Ingest\TokenResolver` compares against with `hash('sha256', $presented)`. Two
 * representations of one thing in one schema is the defect — an implementer reading one table
 * and writing the other gets a silent never-matches — so the second table takes the first's
 * shape. The information content is identical; only the encoding differs, and the encoding is
 * not a property § 9, § 8.6 or any acceptance test turns on.
 *
 * REPORTED, NOT PATCHED: the divergence is D2's to reconcile (either table's spelling is fine,
 * but the document should name one). Card #7827's PR body carries it.
 *
 * `expires_at` IS NOT NULL and § 9 fixes it at **90 days** — "long enough that rotation is
 * quarterly rather than constant, short enough that a forgotten token dies". A nullable column
 * would make "never expires" representable, and the one thing § 9 does not permit is a read
 * credential for the whole fleet's activity picture that nothing ages out.
 *
 * NO FOREIGN KEY AND NO `seat_ref`, unlike `ingest_tokens`: a read token is fleet-scoped
 * (§ 9: "any `fleet_read` token" sees every install), so there is no seat to bind it to. That is
 * the identity asymmetry between the two planes — the ingest binds a token to one seat by
 * D1 § 12.1's identity rule, the read plane deliberately does not — and it is why these are two
 * tables rather than one with a nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 64);                          // "bridge autonomy watchdog"
            Ddl::ascii($table->char('token_hash', 64));           // SHA-256 hex; plaintext never stored
            Ddl::ascii($table->char('prefix', 12), false);        // "mzr_" + first 8, for identification
            $table->enum('scope', ['fleet_read']);
            $table->dateTime('created_at', 3);
            $table->string('created_by', 64);
            $table->dateTime('expires_at', 3);                   // § 9: 90 days, never null
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 255)->nullable();

            // § 8.6: on a REVOKED token's use these two are NOT updated — "a revoked token's use
            // is recorded in `global_counters` and the log, not on the row, so a revoked row
            // cannot be made to look live". The columns exist for the accepted path only.
            $table->dateTime('last_used_at', 3)->nullable();

            // ⛔ THE LENGTH IS LOAD-BEARING AND ITS ABSENCE IS SILENT. § 6.4 declares
            // `VARBINARY(16)`; `MySqlGrammar::typeBinary()` emits `varbinary({$length})` only
            // `if ($column->length)` and falls through to **`blob`** otherwise — so
            // `binary('last_used_ip')` with no length shipped a `blob` under a document saying
            // `VARBINARY(16)`, on both engines, with nothing failing. 16 is `inet_pton()`'s
            // widest output (an IPv6 address; IPv4 is 4), which is what `ReadTokens` writes here.
            // `Tests\Feature\MySqlColumnTypeTest` is the guard, and it compiles the real MySQL
            // grammar because the suite's own store (SQLite, § 6.2) cannot tell the two apart.
            $table->binary('last_used_ip', 16)->nullable();

            $table->unique('token_hash', Ddl::index('feed_tokens', 'uq_hash'));
            $table->index('prefix', Ddl::index('feed_tokens', 'ix_prefix'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_tokens');
    }
};
