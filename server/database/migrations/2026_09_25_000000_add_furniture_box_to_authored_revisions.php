<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `authored_revisions` gains `furniture_box` — `docs/design/FLOOR.md § 14` item 28(1)(iii),
 * operator-ruled 2026-09-25 (card#7341 comment 6488), built by Appendix B row 14's slice C, spelled
 * as `docs/design/FLEET-STATE.md § 6.4`'s DDL declares it.
 *
 * WHAT IT RECORDS: `App\Floor\FurnitureBox::signature()` — `"<width>x<height>"` — of the box a room
 * map's document was validated against when that revision was written, save or restore. The
 * console's room index re-validates a room's current map whenever the box in the tree has a
 * different signature from the one its current revision records, and lists the room if it fails;
 * it refuses nothing after the fact.
 *
 * ⛔ NO BACKFILL, AND `NULL` MEANS *VALIDATED AGAINST NO RECORDED BOX*. Every revision written
 * before this column existed was stored with no item-28(1) check at all, so writing today's
 * signature onto it would be recording a validation that never happened — the log would then
 * vouch for maps nobody checked. A `NULL` differs from every signature, so every such room is
 * re-validated on the index and listed if it fails: never assumed passing. The column is also
 * `NULL` on every revision that holds no desks to validate — a layout, and a map's removal — and a
 * reader never asks it of either, because only a room's CURRENT map is re-validated and a current
 * map always has a document.
 *
 * § 6.9 rule 3: nullable and additive, and there is no backfill to bound — the paragraph above is
 * why none is wanted. Rule 1 governs `events` alone, and this touches no `events` column. And why a new
 * migration rather than an edit to `2026_09_12_000100_create_the_authored_building_store.php`: that
 * file has run on every fielded store, so an edit would change only what a FRESH database gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authored_revisions', function (Blueprint $table) {
            // § 6.4: `VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL`. ascii_bin, because the
            // comparison the index makes is byte equality with `FurnitureBox::signature()`.
            Ddl::ascii($table->string('furniture_box', 16))->nullable()->after('authored_at');
        });
    }

    public function down(): void
    {
        Schema::table('authored_revisions', function (Blueprint $table) {
            $table->dropColumn('furniture_box');
        });
    }
};
