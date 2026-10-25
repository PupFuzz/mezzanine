<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ingest_tokens` gains the revoking author and a wider issuing author — card#12331, the console's
 * *Seat tokens* page.
 *
 *   `revoked_by`  who revoked the token. Until now a revocation had a time and a reason and no
 *                 author; the row is the token acts' audit record (there is no audit table, and
 *                 retirement's record is the same shape: `seats.retired_at` / `retired_by` /
 *                 `retired_reason`). The CLI writes its `--by` (default: the OS user), the console
 *                 the operator's email.
 *   `created_by`  64 → 255. The console's author is the account's email, and `users.email` is 255.
 *                 This table's shape is card#7338's own, not `docs/design/FLEET-STATE.md § 6.4`'s
 *                 (the create migration's docblock), so widening is in bounds — where
 *                 `seats.retired_by` is fixed at 64 by § 6.4 and the console refuses a long
 *                 address instead. Widening is the upstream change that avoids a second copy of
 *                 that refusal.
 *
 * ⚠ NO BACKFILL, BY DECISION. A revocation written before this migration has `revoked_by` NULL and
 * the page renders it as "not recorded": a backfilled author would be invented.
 *
 * ⚠ `change()` REDEFINES THE COLUMN IN FULL, so `created_by` restates NOT NULL (the create
 * migration's `string('created_by', 64)` carries no `nullable()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_tokens', function (Blueprint $table) {
            $table->string('created_by', 255)->nullable(false)->change();
            $table->string('revoked_by', 255)->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('ingest_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_by');
            $table->string('created_by', 64)->nullable(false)->change();
        });
    }
};
