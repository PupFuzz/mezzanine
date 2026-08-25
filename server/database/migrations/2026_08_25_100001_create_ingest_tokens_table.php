<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seat-token table. `docs/design/FLEET-STATE.md § 6.4` names it and deliberately does not
 * specify it: "The token rows themselves live in the ingest's own table (card #7338,
 * D1 § 3.3) and are never read by anything in this document." So this shape is this card's,
 * and D1 § 3.3 is its only constraint:
 *
 *   - `Authorization: Bearer mzn_<43 base64url chars>`;
 *   - server storage is the SHA-256 of the token, NEVER the plaintext — "a token table an app
 *     can read is a fleet-wide credential dump the first time any read primitive leaks";
 *   - the row binds exactly one `(install_id, seat_id)`.
 *
 * TWO DELIBERATE DIVERGENCES FROM `feed_tokens`, THE SIBLING D2 DOES SPECIFY.
 *
 * 1. `token_hash` is CHAR(64) hex, not BINARY(32). D2 § 6.3 makes exactly this trade for ULIDs
 *    and decides it the same way: BINARY(16) "would save 10 B/row and make every diagnostic
 *    query require a conversion function". Here the saving is 32 B/row on a table with one row
 *    per seat — tens of rows, not millions — and the cost of the binary form is a unique index
 *    MySQL cannot build over a BLOB without a prefix length, plus an unreadable column. Same
 *    argument, same answer, one table over.
 *
 * 2. There is NO `expires_at`, and its absence is a decision rather than an omission. D2's
 *    `feed_tokens.expires_at` is NOT NULL, so copying the shape would have made every seat
 *    token expire. D1 § 3.3 specifies rotation by *overlap and revocation* — issue and activate
 *    the new token server-side first, then write it into the seat config, then revoke the old
 *    one — and specifies no expiry anywhere. A mandatory expiry with no stated window is a
 *    mechanism that darkens a seat on a date nobody chose, which is the silent-failure direction
 *    `docs/VERSIONING.md § The failure direction must be safe` forbids. `revoked_at` is the one
 *    deactivation path, and it is an act with an actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_tokens', function (Blueprint $table) {
            $table->increments('id');

            // SHA-256 of the presented token, lowercase hex. The plaintext is never stored and
            // is printed exactly once, by `mezzanine:ingest-token:issue`.
            Ddl::ascii($table->char('token_hash', 64));

            // "mzn_" + the first 8 characters of the token, for identification in a log line or
            // an operator conversation. D1 § 12.1's attribution table requires a refusal at step
            // 4 to be attributable to "the presented token's hash prefix" — this is the column
            // that makes a *resolved* token nameable without printing it. A token that resolves
            // to nothing has no row here, and § 12.1 attributes that one to the hash of what was
            // presented, computed at the time and never stored.
            Ddl::ascii($table->char('prefix', 12), false);

            // The binding. D1 § 3.3: "The token row binds exactly one (install_id, seat_id)."
            // It is a reference to `seats`, not a copy of the pair, because the pair is already
            // that table's unique key and a second copy is a second thing to keep in step.
            $table->unsignedInteger('seat_ref');

            $table->dateTime('created_at', 3);
            $table->string('created_by', 64);
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 255)->nullable();

            // Written outside the ingest transaction, on a best-effort basis: see
            // App\Ingest\TokenResolver. They answer "is this seat's credential in use, and from
            // where", which is the question a rotation asks.
            $table->dateTime('last_used_at', 3)->nullable();
            $table->binary('last_used_ip')->nullable();

            $table->unique('token_hash', 'uq_hash');
            $table->index('prefix', 'ix_prefix');
            $table->index(['seat_ref', 'revoked_at'], 'ix_seat_active');
            $table->foreign('seat_ref', 'fk_token_seat')->references('id')->on('seats');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_tokens');
    }
};
