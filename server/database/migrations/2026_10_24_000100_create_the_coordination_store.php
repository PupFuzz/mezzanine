<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE COORDINATION STORE — `docs/design/FLEET-STATE.md § 6.4`'s three tables and § 6.12's rules,
 * card#12239: the hook BINDINGS, their SECRETS, and the DIGEST of every delivery the receipt derived
 * from. No thread, post, membership, body, event name, action or delivery GUID is stored anywhere
 * (§ 6.12; the operator's ruling of 2026-10-23 that the floor holds no coordination state).
 *
 * WRITTEN AS THE DDL § 6.4 STATES IT rather than through the schema builder, for the one clause the
 * builder has no spelling for: the STORED generated `active_key` that `uq_binding` keys on — ONE
 * unrevoked binding per repository at any scope (r4 MINOR-2), while a revoked binding keeps its row
 * for the digests that reference it and does not block re-issuing the same repository (r3 MINOR-4).
 *
 * ⚠ `active_key` IS `IF(revoked_at IS NULL, 1, NULL)`, NOT § 6.4's FIRST SPELLING `IF(…, 0, id)`, and
 * § 6.4 now carries this one. The first spelling cannot be created: MariaDB 11.8 refuses a generated
 * column that reads an AUTO_INCREMENT column (ERROR 1901, measured on the suite's own server while
 * building this card). The replacement keeps the property and needs no id: every unrevoked row of a
 * repository carries `1`, so a second one collides on `uq_binding`; a revoked row carries `NULL`, and a
 * unique index admits any number of `NULL`s, so revoked rows never collide with each other or with
 * the live one. `Tests\Feature\Coord\AtD2_27TheDigestAndTheFeedRowsCommitTogetherTest` exercises both halves on the engine (its one-unrevoked-binding, casing and binding-key tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE coord_hooks (
              id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              hook_id         CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              scope           ENUM('install','fleet') NOT NULL,
              install_ref     SMALLINT UNSIGNED NULL,
              active_key      TINYINT UNSIGNED AS (IF(revoked_at IS NULL, 1, NULL)) STORED,
              repository      VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              shared_identity BOOLEAN NOT NULL,
              created_at      DATETIME(3) NOT NULL,
              created_by      VARCHAR(64) NOT NULL,
              revoked_at      DATETIME(3) NULL,
              UNIQUE KEY uq_hook (hook_id),
              UNIQUE KEY uq_binding (repository, active_key),
              CONSTRAINT fk_hook_install FOREIGN KEY (install_ref) REFERENCES installs (id)
            ) ENGINE=InnoDB
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE coord_hook_secrets (
              id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              hook_ref          SMALLINT UNSIGNED NOT NULL,
              secret_ciphertext VARBINARY(512) NOT NULL,
              prefix            CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              created_at        DATETIME(3) NOT NULL,
              revoked_at        DATETIME(3) NULL,
              KEY ix_hook (hook_ref, revoked_at),
              CONSTRAINT fk_secret_hook FOREIGN KEY (hook_ref) REFERENCES coord_hooks (id)
            ) ENGINE=InnoDB
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE coord_deliveries (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              hook_ref    SMALLINT UNSIGNED NOT NULL,
              digest      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              received_at DATETIME(3) NOT NULL,
              UNIQUE KEY uq_digest (hook_ref, digest),
              KEY ix_received (received_at),
              CONSTRAINT fk_delivery_hook FOREIGN KEY (hook_ref) REFERENCES coord_hooks (id)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('coord_deliveries');
        Schema::dropIfExists('coord_hook_secrets');
        Schema::dropIfExists('coord_hooks');
    }
};
