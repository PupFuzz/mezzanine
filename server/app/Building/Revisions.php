<?php

namespace App\Building;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ THE APPEND-ONLY REVISION LOG for everything the console authors —
 * `docs/design/FLEET-STATE.md § 6.11`'s `authored_revisions`, card#9208's reversal.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ APPEND-ONLY IS THE WHOLE MECHANISM, AND NOTHING HERE UPDATES OR DELETES A ROW. § 6.11 gives
 * back three of the four things version control had — revert, blame and diff — out of this one
 * property: "a save inserts revision N+1 for its `(kind, subject)` and points `floors.map_version`
 * — or `building_layout.layout_version` — at it in one transaction. A **restore** is a new
 * revision whose `document` copies revision K's, with `restored_from = K`; history is never
 * rewritten, and *undo the restore* is itself a restore." A method here that rewrote a revision
 * would take the revert away and leave the console claiming to still have it.
 *
 * ⛔ A REMOVAL IS A REVISION, NOT AN ABSENCE: `document NULL` records that the subject went back
 * to its default, "so a removed map is as retrievable as an edited one". That is why `document`
 * is nullable and why nothing here treats NULL as missing data.
 *
 * ⚠ THE SUBJECT OF A LAYOUT REVISION IS THE EMPTY STRING, and § 6.4's DDL says why it can never
 * collide with a room's: `docs/design/EVENT-SCHEMA.md § 3.1`'s slug is at least two characters
 * long, so no `install_id` can ever be `''`.
 *
 * ⚠ RETAINED FOREVER (§ 6.7): "a row is written by an operator's save in the admin console and by
 * nothing else", so the population is the number of times a person pressed save — and a revision
 * purged is exactly the prior document the recovery rule exists to keep retrievable. There is
 * deliberately no purge method here for the sweeper to find.
 *
 * ⛔ THIS TABLE IS NOT FLEET STATE AND THE FOLD NEVER READS IT (§ 6.6, § 6.11): a wall is not a
 * fact any seat reported, so a rebuild from the log neither writes nor destroys a row here —
 * and § 6.10's durability posture is what changed instead: a total loss of the store loses every
 * authored floor, and no replay brings one back.
 */
final class Revisions
{
    /** § 6.4's `kind` ENUM — the console is the only writer, so the set is closed. */
    public const ROOM_MAP = 'room_map';

    public const LAYOUT = 'building_layout';

    /** § 6.11: a layout revision's subject. § 6.4's comment carries the no-collision argument. */
    public const LAYOUT_SUBJECT = '';

    /**
     * Insert revision N+1 for `(kind, subject)`, and answer with N+1.
     *
     * ⚠ IT MUST BE CALLED INSIDE `App\Building\Layouts::serialise()`. The next number is read and
     * written in one transaction, and what makes that safe is the lock that transaction takes on
     * the `building_layout` current row — § 6.11: "both paths take the `building_layout` current
     * row `FOR UPDATE` first, so a layout write and a room-map write serialise". The UNIQUE key
     * `(kind, subject, revision)` is the backstop that turns a mistake here into a failed write
     * rather than two revisions numbered alike.
     */
    public static function insert(
        string $kind,
        string $subject,
        ?string $document,
        ?int $restoredFrom,
        string $by,
        string $at,
    ): int {
        $revision = (int) DB::table('authored_revisions')
            ->where('kind', $kind)
            ->where('subject', $subject)
            ->max('revision') + 1;

        DB::table('authored_revisions')->insert([
            'kind' => $kind,
            'subject' => $subject,
            'revision' => $revision,
            'document' => $document,
            'restored_from' => $restoredFrom,
            'authored_by' => $by,
            'authored_at' => $at,
        ]);

        return $revision;
    }

    /**
     * ⛔ § 6.11's NO-OP RULE, IN ONE PLACE: "a save whose document is byte-identical to the current
     * revision is refused as a no-op rather than minting an empty revision, so a revision always
     * records a change." Answers the current revision when `$document` IS it, and `null`
     * otherwise; the REFUSAL's wording is the caller's, because a map's names the room and a
     * layout's names the building.
     *
     * ⚠ It is asked of the current REVISION and never of the current row, and the difference is
     * the room whose map was REMOVED: it has no row and its current revision is the removal, so a
     * comparison against the row would have nothing to compare and would call the re-authoring of
     * a removed map a first save. The row is a projection of the log (§ 6.11); the log is where a
     * question about *what is current* is answered.
     */
    public static function noOp(string $kind, string $subject, ?string $document): ?object
    {
        $current = self::current($kind, $subject);

        return $current !== null && $current->document === $document ? $current : null;
    }

    /** The highest revision for a subject, or `null` when nothing was ever authored for it. */
    public static function current(string $kind, string $subject): ?object
    {
        return DB::table('authored_revisions')
            ->where('kind', $kind)
            ->where('subject', $subject)
            ->orderByDesc('revision')
            ->first();
    }

    public static function get(string $kind, string $subject, int $revision): ?object
    {
        return DB::table('authored_revisions')
            ->where('kind', $kind)
            ->where('subject', $subject)
            ->where('revision', $revision)
            ->first();
    }

    /**
     * A subject's whole history, newest first — what the console's revisions module lists.
     *
     * @return Collection<int, object>
     */
    public static function history(string $kind, string $subject): Collection
    {
        return DB::table('authored_revisions')
            ->where('kind', $kind)
            ->where('subject', $subject)
            ->orderByDesc('revision')
            ->get();
    }
}
