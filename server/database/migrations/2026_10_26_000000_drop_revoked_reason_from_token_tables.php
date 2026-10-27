<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A token revocation takes no reason — card#12441, operator ruling 2026-10-26: "remove the reason
 * from command-line and also from DB". `ingest_tokens.revoked_reason` and
 * `feed_tokens.revoked_reason` are dropped; a revocation's record is its time and, on
 * `ingest_tokens`, its author (`revoked_by`).
 *
 * ⚠ THE DROP DISCARDS EVERY REASON ALREADY STORED, by the ruling. `down()` re-adds both columns as
 * nullable `VARCHAR(255)`, their original shape, and every row comes back NULL: nothing restores a
 * dropped value.
 *
 * `feed_tokens` now matches `docs/design/FLEET-STATE.md § 6.4`'s DDL, which never declared the
 * column. Seat retirement's `seats.retired_reason` is a different act and is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });

        Schema::table('feed_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ingest_tokens', function (Blueprint $table) {
            $table->string('revoked_reason', 255)->nullable()->after('revoked_by');
        });

        Schema::table('feed_tokens', function (Blueprint $table) {
            $table->string('revoked_reason', 255)->nullable()->after('revoked_at');
        });
    }
};
