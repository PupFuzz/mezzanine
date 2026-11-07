<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card#12186 · the name a remembered browser is listed under in "Remembered browsers" on the
 * recovery codes page (`two-factor.codes`) — "Firefox on Linux", say — written once, at the tick, by
 * `App\Auth\RememberedBrowser::issue()`, which owns the argument.
 *
 * ⛔ IT HOLDS A LABEL FROM A CLOSED VOCABULARY, NEVER THE USER AGENT. `RememberedBrowser::label()`
 * picks a browser name and a system name from fixed lists, so no text the browser sent is stored.
 *
 * ⚠ NULLABLE, AND NOT BACKFILLED: a row written before this column existed has no user agent to
 * derive one from. The page lists such a row as "Browser remembered on <date>", and it expires within
 * `RememberedBrowser::LIFETIME_DAYS` of the upgrade. A row written after it is null only when the
 * user agent named nothing the vocabulary knows, and is listed the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remembered_browsers', function (Blueprint $table) {
            // Wider than the longest name `RememberedBrowser::label()` can build from its two lists.
            $table->string('label', 64)->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('remembered_browsers', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
