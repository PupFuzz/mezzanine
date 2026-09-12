<?php

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Building\Revisions;
use App\Fold\Clock;
use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE AUTHORED BUILDING STORE — `docs/design/FLEET-STATE.md § 6.11` and § 6.4's DDL block,
 * card#9208's reversal of 2026-09-12. Two new tables and one new column on `floors`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW IS. `authored_revisions` is the APPEND-ONLY log of everything the console authors —
 * a room's map (`kind = room_map`, subject the room's `install_id`) and the building layout
 * (`kind = building_layout`, subject `''`). `building_layout` is the layout that is CURRENT, and
 * `floors` becomes the same thing for room maps: § 6.11's "the current table is a projection of
 * it". § 6.4's rule over this block is that "names are final; a builder may reorder columns and
 * add nothing", and nothing here adds a column that section does not declare.
 *
 * ⛔ TWO INVARIANTS THIS MIGRATION MUST ESTABLISH, NOT JUST DECLARE, and both are about rows that
 * already exist:
 *
 *   1. **No current row without a revision behind it.** § 6.11: "the migration that adds
 *      `map_version` seeds one `room_map` revision 1 per `floors` row from the row's own `map`,
 *      `updated_by` and `updated_at`, so the invariant holds from the first migration onward."
 *      Without it, every map authored before today would be a room whose history starts with a
 *      hole, and the console's *restore* would have nothing to offer for exactly the rooms an
 *      operator has been drawing longest.
 *
 *   2. **The operator's configured layout is not silently dropped.** Until this change the layout
 *      lived in `config/building.php`, a deploy-time file, and this is the change that retires it
 *      (`docs/design/FLOOR.md § 4.6`, Appendix B row 11). If that document still declares floors
 *      when this runs, they are seeded as the store's revision 1 — VALIDATED FIRST, and the
 *      migration fails loudly rather than seeding a document the reader would refuse per request.
 *      ⚠ THE ROOM SHAPE MOVED IN THE SAME CARD: card#9292 made a room's value a RECORD
 *      (`['form' => …]`) where it was a bare form string, so the seed rewrites what it reads —
 *      the one place in this application that knows the pre-card#9292 shape, and it is a
 *      migration, which is what that shape is.
 *
 * ⚠ AND THE ORDER THIS SEED CANNOT CONTROL, STATED RATHER THAN LEFT TO BITE. `bin/deploy.sh`
 * checks the new code out and then migrates, so a deployment that had EDITED
 * `config/building.php` in its own checkout loses that edit at the checkout, before this runs —
 * `config()` then answers with the shipped empty document (or a cached config from the previous
 * release, which is the one case the seed below still catches). The shipped file declares NO
 * floors (verified on this repository at the commit that removes it) and this product has one
 * deployment, so the seed's real job is to be correct for a checkout that carried a document, not
 * to be the only way one survives: re-authoring a layout in the console is now one save, which is
 * the point of the reversal.
 *
 * ⛔ NO SEED WHEN NOTHING WAS AUTHORED, AND THAT IS A STATEMENT RATHER THAN AN OMISSION. § 8.7:
 * "`layout_version` is `0` with an empty `floors` when no layout was ever saved: today's
 * building, one floor per install." A seeded revision 1 carrying an empty document would claim an
 * operator pressed save, and *undo* would then have a revision to go back to that nobody made.
 */
return new class extends Migration
{
    /** § 6.11: the seed's author is the file it came from, because no operator pressed save. */
    private const SEEDED_BY = 'config/building.php';

    public function up(): void
    {
        Schema::create('authored_revisions', function (Blueprint $table) {
            $table->increments('id');

            // § 6.3 / § 6.4: an ENUM, because "the console is the only writer and it enumerates
            // the set, so an unknown kind is a bug to reject, not a value to record".
            $table->enum('kind', [Revisions::ROOM_MAP, Revisions::LAYOUT]);

            // The room's `install_id` for a `room_map`; `''` for the layout. § 6.4's comment
            // carries the no-collision argument: D1 § 3.1's slug is at least two characters long,
            // so no install can ever be named `''`. Same width and collation as
            // `floors.install_id` so the two compare without a coercion.
            Ddl::ascii($table->string('subject', 32));

            $table->unsignedInteger('revision');

            // NULL = a REMOVAL: the subject went back to its default (§ 6.11). A removed map is
            // as retrievable as an edited one precisely because the row stays and says so.
            $table->mediumText('document')->nullable();

            // The revision this one copies, when it is a restore. § 6.11: "history is never
            // rewritten, and *undo the restore* is itself a restore".
            $table->unsignedInteger('restored_from')->nullable();

            // `users.email` as the console holds it (255), matching `floors.updated_by`.
            $table->string('authored_by', 255);
            $table->dateTime('authored_at', 3);

            // § 6.4's key, and the backstop that makes revision numbering safe: two writers that
            // somehow read the same N cannot both insert N + 1.
            $table->unique(['kind', 'subject', 'revision'], Ddl::index('authored_revisions', 'uq_revision'));
        });

        Schema::create('building_layout', function (Blueprint $table) {
            // § 6.4: "always 1: the building has one layout". Not an auto-increment: the row's
            // identity is the constant, and an increment here would be a second layout waiting to
            // be inserted by a typo.
            $table->unsignedTinyInteger('id')->primary();

            $table->mediumText('document');
            $table->unsignedInteger('layout_version');
            $table->string('updated_by', 255);
            $table->dateTime('updated_at', 3);
        });

        // § 6.4's `CONSTRAINT ck_one_layout CHECK (id = 1)`, where the store has the syntax.
        // SQLite cannot add a check constraint to an existing table and the suite's default store
        // is SQLite (`phpunit.xml`), so the constraint is emitted for the engine production runs
        // — the same shape `App\Support\Ddl` takes for `ascii_bin` and for index names, and for
        // the same reason: the document's DDL is what the deployed store gets.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE building_layout ADD CONSTRAINT ck_one_layout CHECK (id = 1)');
        }

        // ⛔ NULLABLE FIRST, BACKFILLED, THEN NOT NULL. § 6.4 declares `map_version INT UNSIGNED
        // NOT NULL`, and a NOT NULL column cannot be added to a table with rows without either a
        // default this schema does not declare or a value for every row — and the value for every
        // row is the revision this migration is about to write for it.
        Schema::table('floors', function (Blueprint $table) {
            $table->unsignedInteger('map_version')->nullable()->after('map');
        });

        $this->seedARevisionPerAuthoredMap();

        Schema::table('floors', function (Blueprint $table) {
            $table->unsignedInteger('map_version')->nullable(false)->change();
        });

        $this->seedTheConfiguredLayout();
    }

    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropColumn('map_version');
        });

        Schema::dropIfExists('building_layout');
        Schema::dropIfExists('authored_revisions');
    }

    /**
     * § 6.11's invariant 1: one `room_map` revision 1 per existing `floors` row, from that row's
     * own `map`, `updated_by` and `updated_at` — the row's own history, not a new authorship.
     */
    private function seedARevisionPerAuthoredMap(): void
    {
        foreach (DB::table('floors')->orderBy('install_id')->get() as $floor) {
            DB::table('authored_revisions')->insert([
                'kind' => Revisions::ROOM_MAP,
                'subject' => $floor->install_id,
                'revision' => 1,
                'document' => $floor->map,
                'restored_from' => null,
                'authored_by' => $floor->updated_by,
                'authored_at' => $floor->updated_at,
            ]);

            DB::table('floors')->where('id', $floor->id)->update(['map_version' => 1]);
        }
    }

    /**
     * § 6.11's invariant 2: the deploy-time document becomes the store's revision 1, or the
     * migration fails naming what it could not seed.
     */
    private function seedTheConfiguredLayout(): void
    {
        $floors = config('building.floors');

        if (! is_array($floors) || $floors === []) {
            return;
        }

        $document = ['floors' => array_map($this->toRecords(...), array_values($floors))];

        try {
            BuildingLayout::parse($document);
        } catch (InvalidBuildingLayout $e) {
            // ⛔ FAIL LOUD RATHER THAN SEED A DOCUMENT THE READER WILL REFUSE PER REQUEST. The
            // deploy stops here with the reason in front of the person running it; seeding it
            // would put the same refusal on every page load of the console and the floor, with
            // nothing saying where it came from.
            throw new RuntimeException(
                'The building layout in config/building.php cannot be seeded into the authored '
                .'building store, because this reader refuses it: '.$e->getMessage()
                .' Fix that document (or empty its `floors` list, which is the default building — '
                .'one floor per install) and run the migration again.',
                previous: $e,
            );
        }

        $text = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // The instant the store LEARNED this document, which is what actually happened: nobody
        // pressed save, and a timestamp copied from the file's mtime or from a floors row would
        // be a claim about when it was authored that this migration cannot make.
        $at = Clock::sql(now());

        DB::table('authored_revisions')->insert([
            'kind' => Revisions::LAYOUT,
            'subject' => Revisions::LAYOUT_SUBJECT,
            'revision' => 1,
            'document' => $text,
            'restored_from' => null,
            'authored_by' => self::SEEDED_BY,
            'authored_at' => $at,
        ]);

        DB::table('building_layout')->insert([
            'id' => 1,
            'document' => $text,
            'layout_version' => 1,
            'updated_by' => self::SEEDED_BY,
            'updated_at' => $at,
        ]);
    }

    /**
     * ⚠ THE PRE-card#9292 ROOM SHAPE, TRANSLATED — and this migration is the only place in the
     * application that knows it. § 4.6: a room's value was the bare form string and is now a
     * record, "the one shape, paid for now". A reader that accepted both would be two shapes
     * living forever; a migration that translated neither would drop the operator's floors at the
     * refusal above.
     *
     * @return array<string, mixed>
     */
    private function toRecords(mixed $entry): array
    {
        if (! is_array($entry) || ! is_array($entry['rooms'] ?? null)) {
            // Not a shape this translation understands. It is handed on unchanged so that the
            // REFUSAL above is the one that names it — a second refusal here would say the same
            // thing in worse words, from a place with no reader's authority.
            return is_array($entry) ? $entry : ['rooms' => $entry];
        }

        $rooms = [];

        foreach ($entry['rooms'] as $installId => $record) {
            $rooms[(string) $installId] = is_string($record) ? ['form' => $record] : $record;
        }

        return ['rooms' => $rooms] + array_diff_key($entry, ['rooms' => null]);
    }
};
