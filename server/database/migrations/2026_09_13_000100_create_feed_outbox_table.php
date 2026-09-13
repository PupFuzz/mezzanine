<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `docs/design/FLEET-STATE.md § 6.4`'s `feed_outbox` — the one queue between every writer of a feed
 * message and every open stream (§ 8.3), card#9300.
 *
 * WRITTEN AS THE DDL § 6.4 STATES IT rather than through the schema builder, for the two clauses the
 * builder has no spelling for: the `CHECK (LENGTH(message) <= 8192)` that makes § 8.3's 8 KiB message
 * bound fail AT THE WRITE, and the `t` ENUM whose membership is § 8.3's message table minus
 * `feed.close`. `feed.close` is deliberately absent: one handler mints it about its own stream, and a
 * row carrying it would be fanned out to every open stream (§ 6.4's comment on the column).
 *
 * ONE DEPARTURE FROM § 6.4's TEXT, AND § 6.4 NOW SAYS SO: `created_at` has NO `DEFAULT
 * CURRENT_TIMESTAMP(3)`. The writer stamps it (`App\Feed\Outbox`) from the same application clock the
 * handler's visibility-lag term is computed against, which is how `events.received_at` and § 6.5's fold
 * lag already work. A store-clock default read against an application-clock `server_now` would put
 * the two hosts' clock skew inside a 2 s bound (§ 6.1 puts the store on its own host).
 *
 * `install_id` is spelled as `installs.install_id` is (ASCII, binary collation, 32), because § 9's
 * per-subscriber filter keys on it and a second collation for one identifier is a join that silently
 * disagrees about case.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE feed_outbox (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              created_at  DATETIME(3) NOT NULL,
              t           ENUM('seat.delta','feed.heartbeat','seat.retired','fleet.reload','fleet.health',
                               'coord.thread','coord.round','room.map','building.layout') NOT NULL,
              install_id  VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
              message     JSON NOT NULL CHECK (LENGTH(message) <= 8192),
              KEY ix_created (created_at)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_outbox');
    }
};
