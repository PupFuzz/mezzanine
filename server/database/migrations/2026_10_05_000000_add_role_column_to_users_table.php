<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.role` — card#9415: an account is an `observer` (reads the floor, the lobby, the drill-down
 * and the fleet REST endpoints) or an `operator` (all of that, plus the admin console and the per-desk
 * write controls). The gate that reads it is `operate`, defined in `App\Providers\AppServiceProvider`.
 *
 * ⛔ EVERY EXISTING ROW BECOMES `operator`, AND THAT IS THE MIGRATION'S WHOLE OBLIGATION. Until this
 * column existed every account was an operator (card#9070's D3), so the faithful backfill is the role
 * each account already had — nobody loses access on migrate. The column is added with that DEFAULT,
 * which is how MariaDB fills the rows that are already there.
 *
 * ⛔ THEN THE DEFAULT BECOMES `observer`, the safer tier, for every row written after this one.
 * `App\Admin\UserProvisioning::create()` always names the role, so the default only decides what a
 * writer that forgot it would get — and that should be the tier that cannot administer anything.
 * ⚠ DROPPING THE DEFAULT WOULD NOT HAVE MADE THE STORE REFUSE SUCH A WRITE: MariaDB gives a
 * `NOT NULL` ENUM with no default its FIRST member, strict mode or not (measured on this card), so
 * the default is stated rather than left to the order of the list.
 *
 * Values are literals rather than `App\Models\User`'s constants: a migration records what the schema
 * was on the day it ran, and must not move if the model's constants ever do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['observer', 'operator'])->default('operator')->after('retired_reason');
        });

        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'observer'");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
