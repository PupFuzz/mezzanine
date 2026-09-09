<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store behind the emailed two-factor reset — card#9077, on the operator's ruling that a reset
 * may go to the address already on the account.
 *
 * ⛔ WHY NOT `password_reset_tokens`. That table exists (`0001_01_01_000000_create_users_table.php`)
 * and is Laravel's broker table for a feature this application does not enable
 * (`config/fortify.php` omits `Features::resetPasswords()`). Borrowing it would put two different
 * acts — "prove you own the mailbox, then change a password" and "prove you own the mailbox, then
 * REMOVE a security control" — in one row shape keyed on an email address, so a token minted for
 * one would be presentable to the other the moment the password feature is ever turned on. It is
 * also keyed on the ADDRESS rather than the account, which cannot express the audit obligation
 * below.
 *
 * ⛔ THE PLAINTEXT CODE IS NEVER STORED. `token_hash` is the SHA-256 of the normalised code, and
 * the lookup is BY that hash — which is why it is a fast digest rather than bcrypt: bcrypt's
 * per-row salt makes "find the row for this code" a full-table scan with one hash per row, and the
 * property bcrypt buys (slowing a guess against a LOW-entropy secret) is not needed for a value
 * `App\Auth\TwoFactorReset` mints with 100 bits of entropy from `random_int()`. A digest of a
 * high-entropy secret is not brute-forceable; a digest of a password would be.
 *
 * ⛔ THE ROW IS THE AUDIT RECORD, and that is a requirement rather than a convenience: consuming
 * one of these is the single act in this application that REMOVES a security control, so who, when
 * and from where must survive it. `consumed_at`/`consumed_ip`/`consumed_user_agent` are written by
 * the same UPDATE that claims the token, so a consumption cannot exist without its record.
 *
 * ⚠ CONSUMED ROWS ARE KEPT AND UNCONSUMED ONES ARE NOT. `TwoFactorReset::request()` deletes the
 * account's outstanding (unconsumed) rows before inserting, which is both the "at most one live
 * code per account" rule and the whole of this table's housekeeping — an expired code is an
 * unconsumed row and goes on the next request. Nothing sweeps the consumed rows, deliberately:
 * they are the audit trail, and a fleet of operator accounts mints these at a rate measured in
 * events per year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_reset_tokens', function (Blueprint $table) {
            $table->id();

            // No `constrained()` foreign key, matching `web_sessions.user_id`: nothing in this
            // application deletes a user (accounts RETIRE — see the retirement migration), so the
            // cascade a foreign key exists to express has no act to fire on.
            $table->foreignId('user_id')->index();

            // 64 hex characters. ASCII and binary-collated per `docs/design/FLEET-STATE.md § 6.1`:
            // this is an identifier column and it must compare EXACTLY — MySQL's default
            // `utf8mb4_0900_ai_ci` is case-insensitive, which would make two digests differing
            // only in case compare equal on lookup.
            Ddl::ascii($table->char('token_hash', 64))->unique();

            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            // 45 = an IPv4-mapped IPv6 address in text form, the same width `web_sessions` uses.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->string('consumed_user_agent', 255)->nullable();

            // The predicate `request()` deletes on and `consume()` reads back.
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_reset_tokens');
    }
};
