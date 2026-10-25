<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card#12331 · the one-time keys behind the console's *Seat tokens* issue form.
 * `App\Admin\SeatTokenIssueKey` is the only reader and writer, and its docblock owns the argument:
 * a key is minted with each render of the page and CONSUMED by the issue POST with a
 * `DELETE … WHERE key_hash = ? AND user_id = ?` whose affected-row count decides — so two requests
 * carrying one key issue at most one token, which a key held in the session cannot promise (the
 * session driver is `database` and takes no lock).
 *
 * The key is not a credential (CSRF and the session gate the form), but it is stored as its SHA-256
 * like every other random value this application keeps, so a read of the table yields no usable key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_token_issue_keys', function (Blueprint $table) {
            Ddl::ascii($table->char('key_hash', 64))->primary();

            // No `constrained()` foreign key, matching `remembered_browsers.user_id`: nothing in
            // this application deletes a user (accounts RETIRE).
            $table->foreignId('user_id')->index();

            $table->dateTime('created_at', 3)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_token_issue_keys');
    }
};
