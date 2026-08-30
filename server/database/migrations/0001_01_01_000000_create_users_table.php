<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // RENAMED FROM THE STOCK `sessions`, and the rename is forced rather than preferred.
        // `docs/design/FLEET-STATE.md § 6.4` gives the name `sessions` to the fold's per-session
        // projection and says "Names are final" — and that table is referenced by name throughout
        // the document (§ 4.3's `sessions.stalled_since`, § 4.5, § 6.7's retention table, § 8.2.3).
        // Laravel's own web-session store is the renameable one: `config/session.php` has carried a
        // `table` key since forever, so moving it costs one config line, while renaming the design's
        // table would put every citation in D2 out of step with the store. Nothing is deployed and
        // no row exists, so this is a rename on paper. See card #7339's PR body.
        Schema::create(config('session.table', 'web_sessions'), function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists(config('session.table', 'web_sessions'));
    }
};
