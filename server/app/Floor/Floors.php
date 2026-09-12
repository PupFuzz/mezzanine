<?php

namespace App\Floor;

use App\Building\BuildingChanged;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Fold\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and the one writer of the `floors` table — card#9085's authored room maps, and
 * since card#9208's reversal the CURRENT-ROW half of `docs/design/FLEET-STATE.md § 6.11`'s
 * authored building store.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ EVERY WRITE IS A REVISION, AND THE CURRENT ROW IS A PROJECTION OF THE LOG (§ 6.11). A save
 * inserts revision N+1 into `authored_revisions` and points `floors.map_version` at it in ONE
 * transaction; a restore is a forward revision copying revision K, with `restored_from = K`; a
 * removal deletes the `floors` row and inserts a `document NULL` revision in that same
 * transaction, "so a removed map is as retrievable as an edited one, and the room renders the
 * shipped default until it is authored again". `floors` therefore holds only rooms with an
 * authored map current, and § 6.7's *purged by nothing* is a claim about the log.
 *
 * ⛔ THE TRANSACTION IS `App\Building\Layouts::serialise()`'s AND NOT ONE OPENED HERE, because
 * § 6.11 requires the `building_layout` current row to be taken `FOR UPDATE` **first** on both
 * write paths. A room map's write changes that room's EXTENT (§ 4.6, card#9292), so it has to be
 * checked against the plan in the same transaction as a layout write that could be replacing it —
 * this is the second write site § 6.11 names as "the one an implementer misses".
 *
 * ⛔ AND THE OVERLAP IS RE-CHECKED ON EVERY ONE OF THE THREE — save, restore AND removal. A
 * removal is the least obvious and is a real change of extent: the room falls back to the shipped
 * default's grid (§ 8.7), which may be larger than the map it replaces.
 *
 * ⚠ IT HOLDS THE AUTHORED ARTIFACT AND NOTHING DERIVED. `S` is not stored (see
 * `App\Floor\FloorMap`), and no seat is named anywhere: which seat sits at which desk is
 * `docs/design/FLOOR.md § 3.2`'s pure function, and storing it is card#9071's ruling — no.
 *
 * ⚠ THE TIMESTAMPS ARE `App\Fold\Clock::sql()`'s, the same server-clock spelling every other
 * write in this application uses, rather than Eloquent's automatic ones — the table is a plain
 * store table beside `installs` and `seats`, not a model with its own conventions.
 */
final class Floors
{
    /**
     * Every authored floor, keyed by `install_id`.
     *
     * @return Collection<string, object>
     */
    public static function all(): Collection
    {
        return DB::table('floors')->orderBy('install_id')->get()->keyBy('install_id');
    }

    public static function forInstall(string $installId): ?object
    {
        return DB::table('floors')->where('install_id', $installId)->first();
    }

    /**
     * Author or replace one room's map. Answers the new `map_version`.
     *
     * @throws InvalidFloorMap on a byte-identical no-op
     * @throws InvalidBuildingLayout when the room's new extent would overlap a neighbour
     */
    public static function save(string $installId, FloorMap $map, string $by): int
    {
        $written = Layouts::serialise(function () use ($installId, $map, $by) {
            // § 6.11's no-op rule, asked of `App\Building\Revisions` so that this store has ONE
            // byte comparison rather than one per write path (the layout's is the same rule).
            $current = Revisions::noOp(Revisions::ROOM_MAP, $installId, $map->document);

            if ($current !== null) {
                throw new InvalidFloorMap(sprintf(
                    'This map is byte for byte revision %d, which is already current for %s, so '
                    .'there is nothing to record. docs/design/FLEET-STATE.md § 6.11 refuses a '
                    .'no-op rather than minting an empty revision.',
                    (int) $current->revision,
                    $installId,
                ));
            }

            Layouts::refuseOverlaps(Layouts::layout(), [$installId => $map]);

            return self::writeCurrent($installId, $map->document, null, $by);
        });

        self::announce($installId, $written['version'], $written['at']);

        return $written['version'];
    }

    /**
     * § 6.11's RESTORE for a room map: a new revision copying revision K, checked against TODAY's
     * layout. Answers the new `map_version`, or `null` when revision K was a REMOVAL and
     * restoring it puts the room back on the shipped default.
     *
     * ⛔ THE DOCUMENT IS RE-VALIDATED, not trusted because it was valid once. The rules a map is
     * held to can TIGHTEN between two saves — card#9292 added two of them — and a restore is a
     * write like any other: `App\Floor\FloorInventory` already surfaces a stored map this parser
     * refuses, and restoring one would be storing it again as current.
     *
     * @throws InvalidFloorMap when revision K is not there, is a no-op, or is a document this store no longer holds
     * @throws InvalidBuildingLayout when the restored extent would overlap a neighbour
     */
    public static function restore(string $installId, int $revision, string $by): ?int
    {
        $written = Layouts::serialise(function () use ($installId, $revision, $by) {
            $row = Revisions::get(Revisions::ROOM_MAP, $installId, $revision);

            if ($row === null) {
                throw new InvalidFloorMap(sprintf(
                    'There is no revision %d for %s. The revisions list is the whole history '
                    .'(docs/design/FLEET-STATE.md § 6.11) and nothing removes a row from it, so a '
                    .'number that is not there was never written.',
                    $revision,
                    $installId,
                ));
            }

            $current = Revisions::noOp(Revisions::ROOM_MAP, $installId, $row->document);

            if ($current !== null) {
                throw new InvalidFloorMap(sprintf(
                    'Revision %d is byte for byte what is already current for %s (revision %d), '
                    .'so restoring it would change nothing. docs/design/FLEET-STATE.md § 6.11 '
                    .'refuses a no-op rather than minting an empty revision.',
                    $revision,
                    $installId,
                    (int) $current->revision,
                ));
            }

            // Restoring a REMOVAL is a removal: the document it copies is NULL, and § 6.11's
            // *undo the restore is itself a restore* is what makes that coherent rather than a
            // special case — the room goes back to the shipped default and revision N+1 records
            // that it was revision K that put it there.
            if ($row->document === null) {
                return ['version' => null, 'at' => self::writeRemoval($installId, $revision, $by)];
            }

            $map = FloorMap::parse((string) $row->document);

            Layouts::refuseOverlaps(Layouts::layout(), [$installId => $map]);

            return self::writeCurrent($installId, $map->document, $revision, $by);
        });

        self::announce($installId, $written['version'], $written['at']);

        return $written['version'];
    }

    /**
     * § 6.11's REMOVAL: the `floors` row goes and a `document NULL` revision records it, in one
     * transaction. Answers whether there was a map to remove.
     *
     * @throws InvalidBuildingLayout when the room would fall back to an extent that overlaps a
     *                               neighbour on a planned floor
     */
    public static function remove(string $installId, string $by): bool
    {
        $removed = Layouts::serialise(function () use ($installId, $by) {
            if (self::forInstall($installId) === null) {
                return null;
            }

            // The room's extent becomes the shipped default's (§ 8.7), which is a change of
            // extent like any other and is checked as one.
            Layouts::refuseOverlaps(Layouts::layout(), [$installId => null]);

            return self::writeRemoval($installId, null, $by);
        });

        if ($removed === null) {
            return false;
        }

        self::announce($installId, null, $removed);

        return true;
    }

    /**
     * The revision, then the current row pointed at it. Called only inside `serialise()`.
     *
     * @return array{version: int, at: string} the revision written, and the instant it records —
     *                                         the same value the row and the message carry, read
     *                                         once so the three cannot disagree
     */
    private static function writeCurrent(string $installId, string $document, ?int $restoredFrom, string $by): array
    {
        $at = Clock::sql(now());

        $revision = Revisions::insert(Revisions::ROOM_MAP, $installId, $document, $restoredFrom, $by, $at);

        // The document as authored. Storing the document rather than a re-encoding of the parsed
        // structure is what makes the bytes the renderer reads the bytes Tiled wrote — a re-encode
        // would silently drop every property this parser does not read.
        $written = [
            'map' => $document,
            // § 6.4: "= authored_revisions.revision of the row this IS".
            'map_version' => $revision,
            'updated_by' => $by,
            'updated_at' => $at,
        ];

        // UPDATE-then-INSERT rather than `updateOrInsert`, for one reason: `created_at` is when
        // this room was FIRST authored and a replacement is not a new room, and `updateOrInsert`
        // has no way to write a column on the insert only.
        $updated = DB::table('floors')->where('install_id', $installId)->update($written);

        if ($updated === 0) {
            DB::table('floors')->insert($written + [
                'install_id' => $installId,
                'created_at' => $at,
            ]);
        }

        return ['version' => $revision, 'at' => $at];
    }

    /**
     * § 6.11: the row goes and the revision records the removal. Inside `serialise()` only.
     *
     * @return string the instant the removal records
     */
    private static function writeRemoval(string $installId, ?int $restoredFrom, string $by): string
    {
        $at = Clock::sql(now());

        Revisions::insert(Revisions::ROOM_MAP, $installId, null, $restoredFrom, $by, $at);

        DB::table('floors')->where('install_id', $installId)->delete();

        return $at;
    }

    /**
     * § 6.11: "on commit … publish". The seam is `App\Building\BuildingChanged` and there is no
     * transport behind it yet — card#9287 — so this call is where slice 2 hooks the message up,
     * and it is made outside the transaction because a notification about a state the store never
     * committed is the one thing it may never send.
     */
    private static function announce(string $installId, ?int $version, string $at): void
    {
        BuildingChanged::announce(BuildingChanged::ROOM_MAP, [
            'install_id' => $installId,
            // § 8.7: `null` after a removal — the room is back on the shipped default.
            'map_version' => $version,
            // § 8.3's `at`, in the wire spelling, and it is the instant the REVISION records
            // rather than the moment this line runs: a message that timestamped itself would
            // disagree with the row it announces.
            'at' => Clock::wire($at),
        ]);
    }
}
