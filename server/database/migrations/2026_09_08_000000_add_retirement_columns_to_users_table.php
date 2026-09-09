<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retirement for USER accounts — card#9070's D2, and deliberately the same three columns
 * `seats` already carries (`docs/design/FLEET-STATE.md § 6.4`).
 *
 * ⛔ WHY THERE IS NO `DELETE`. A user who has minted a machine token, retired a seat, or appears
 * in any administrative record must not vanish: a deleted row turns every historical reference
 * into a dangling id, and the substance an operator actually wants from "delete" is that the
 * account stops working. That is what `retired_at` buys — the account is refused at every
 * credential path (`App\Auth\ActiveUserProvider`) while the record of who it was, who retired it
 * and why survives.
 *
 * ⚠ `users.email` STAYS UNIQUE ACROSS RETIRED ROWS, and that is a consequence rather than an
 * oversight: an address that belonged to a retired account cannot be handed to a new one. That is
 * the correct default for an audit trail (two accounts with one address are indistinguishable in
 * every later record), and the escape — if it is ever wanted — is a decision about erasure, which
 * D2 puts in its own louder command rather than in a console button.
 *
 * ⛔ THE UNIQUE INDEX IS ONLY HALF OF THAT, AND THE OTHER HALF IS A GUARD, NOT A CONSTRAINT. A
 * unique index stops two rows sharing an address; it does not stop the retired row being RENAMED
 * off its address and the address then being handed over. `App\Admin\UserProvisioning::update()`
 * refuses to write a retired account, which is what makes the sentence above true, and
 * `Tests\Feature\Admin\UserManagementTest` drives the whole two-request scenario through the real
 * routes. Before that guard existed the sentence was false and this comment said so anyway — which
 * is the reason it now names where to check.
 *
 * THE SHAPE IS COPIED FROM `seats` ON PURPOSE. `retired_by` and `retired_reason` are nullable
 * because a COLUMN cannot be non-null before the act; the ACT still owes both, and
 * `App\Admin\UserRetirement` is the one writer that enforces it — the same split
 * `App\Fleet\SeatRetirement` makes for a seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('retired_at')->after('two_factor_confirmed_at')->nullable();
            // 255, matching `users.email`, and NOT `seats.retired_by`'s 64: the author of an
            // account retirement is an operator's own address, which this application already
            // allows to be 255 characters. A narrower column would silently truncate the one
            // field whose whole job is to say who did it. (`seats.retired_by` stays at 64
            // because `docs/design/FLEET-STATE.md § 6.4` fixes it there and says names are
            // final — `App\Http\Controllers\Admin\SeatController` refuses an author it cannot
            // record faithfully rather than truncating one into that column.)
            $table->string('retired_by', 255)->after('retired_at')->nullable();
            $table->string('retired_reason', 255)->after('retired_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['retired_at', 'retired_by', 'retired_reason']);
        });
    }
};
