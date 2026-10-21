<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card#12179 · the store behind "Remember this browser for 30 days" on the two-factor code page.
 * `App\Auth\RememberedBrowser` is the only reader and writer, and its docblock owns the argument.
 *
 * ⛔ THE COOKIE'S TOKEN IS NEVER STORED. `token_hash` is the SHA-256 of the 256-bit random value the
 * browser holds, and the lookup is BY that hash — a fast digest, for the reason the two-factor reset
 * table gives (`2026_09_09_000000_create_two_factor_reset_tokens_table.php`): a digest of a
 * high-entropy random value is not brute-forceable, and bcrypt's per-row salt would make "find the
 * row for this token" a scan.
 *
 * ⛔ `expires_at` IS WRITTEN ONCE, AT ISSUE, AND NEVER MOVED. The expiry is fixed at 30 days from the
 * tick; `last_used_at` records use and does not extend it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remembered_browsers', function (Blueprint $table) {
            $table->id();

            // No `constrained()` foreign key, matching `two_factor_reset_tokens.user_id` and
            // `web_sessions.user_id`: nothing in this application deletes a user (accounts RETIRE).
            $table->foreignId('user_id')->index();

            // 64 hex characters, ASCII and binary-collated so a lookup compares EXACTLY — see the
            // reset-token migration on why the store's case-insensitive default collation is wrong
            // for an identifier.
            Ddl::ascii($table->char('token_hash', 64))->unique();

            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remembered_browsers');
    }
};
