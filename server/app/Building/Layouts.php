<?php

namespace App\Building;

use App\Floor\FloorMap;
use App\Fold\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and the one writer of `building_layout` — `docs/design/FLEET-STATE.md § 6.11`'s
 * authored layout, card#9208's reversal (2026-09-12).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ THE LAYOUT MOVED OUT OF `config/building.php` AND THE READER DID NOT CHANGE. § 4.6 wrote the
 * promise down before the store existed — "the SHAPE is the contract; the store is the caller's"
 * — and `App\Building\BuildingLayout` still takes a DECODED DOCUMENT and knows nothing about
 * where it came from. This class is that caller.
 *
 * ⚠ NO ROW IS *TODAY'S BUILDING*, NOT A MISSING ONE. `docs/design/FLEET-STATE.md § 8.7`:
 * "`layout_version` is `0` with an empty `floors` when no layout was ever saved: today's
 * building, one floor per install." So `version()` answers 0 and `document()` answers
 * `{"floors": []}` — which § 4.6 calls "a legal and meaningful document". Nothing seeds a row to
 * say that, because a seeded revision 1 would claim an operator pressed save.
 *
 * ⛔ SERIALISATION IS THIS CLASS'S, AND BOTH WRITE PATHS TAKE IT. § 6.11: "**Both paths take the
 * `building_layout` current row `FOR UPDATE` first**, so a layout write and a room-map write
 * serialise and neither can pass its check against a state the other is replacing." A room map's
 * save changes that room's EXTENT, and the layout's plan is what says whether that extent now
 * collides — two checks reading each other's inputs, which is exactly the pair that must not
 * interleave. `serialise()` below is the one place that lock is taken, so a third write path
 * added later inherits it instead of re-deriving it.
 *
 * ⚠ WHAT THE LOCK DOES WHEN THERE IS NO ROW, since that is the state this deployment starts in:
 * on InnoDB, `SELECT … WHERE id = 1 FOR UPDATE` over a missing primary-key row takes a gap lock,
 * so a concurrent INSERT of that row blocks — the two writers still serialise. On SQLite (the
 * suite's default store) a write transaction is exclusive for the whole database, so the question
 * does not arise. Neither is a claim this suite proves: § 6.1's concurrency cases need two
 * connections at once and are card#7523's, which `php-tests.yml` states in its own header.
 */
final class Layouts
{
    /** § 6.4: "always 1: the building has one layout", and the CHECK constraint says so too. */
    public const ROW_ID = 1;

    /** The current row, or `null` when no layout was ever saved. */
    public static function current(): ?object
    {
        return DB::table('building_layout')->where('id', self::ROW_ID)->first();
    }

    /** § 8.7: the current `layout_version`, and **0** when no layout was ever saved. */
    public static function version(): int
    {
        return (int) (self::current()->layout_version ?? 0);
    }

    /**
     * The stored document as TEXT — byte for byte as authored — or `null` when there is none.
     * This is what the console's editor shows and what a byte-identical save is compared against.
     */
    public static function documentText(): ?string
    {
        $row = self::current();

        return $row === null ? null : (string) $row->document;
    }

    /**
     * The stored document, decoded — `['floors' => []]` when no layout was ever saved.
     *
     * @return array<mixed>
     */
    public static function document(): array
    {
        $text = self::documentText();

        if ($text === null) {
            return ['floors' => []];
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidBuildingLayout(self::storedDocumentIsNot('JSON at all ('.$e->getMessage().')'), previous: $e);
        }

        if (! is_array($decoded)) {
            // No JSON error to report — the decode succeeded and answered with a scalar. Saying
            // so is the news; a `json_last_error_msg()` here would print *(No error)* beside a
            // sentence that says something went wrong (card#9295's shape, one surface over).
            throw new InvalidBuildingLayout(self::storedDocumentIsNot('a document at all, but a JSON '.get_debug_type($decoded)));
        }

        return $decoded;
    }

    /**
     * What a stored row this reader cannot use says to an operator — one sentence, because the
     * remedy is the same whatever the row holds: only the console writes it, so a row it would
     * not have written came from somewhere else, and the revision log still holds every document
     * that was ever saved.
     */
    private static function storedDocumentIsNot(string $what): string
    {
        return 'The stored building layout is not '.$what.'. Only the console writes this row '
            .'(docs/design/FLEET-STATE.md § 6.11), so this is a store that was written to from '
            .'somewhere else; the revision log still holds every document that was ever saved, '
            .'and restoring one repairs it.';
    }

    /**
     * The current layout, READ. It throws exactly as the deploy-time reader threw, and for § 4.6's
     * own reason: "a bad layout is a refusal and never a repair", reaching the author per REQUEST
     * on the surfaces that read it. A document the console accepted can still be refused later if
     * a rule tightens, and a reader that repaired it would hide that from the only person who can
     * fix it.
     */
    public static function layout(): BuildingLayout
    {
        return BuildingLayout::parse(self::document());
    }

    /**
     * § 6.11's serialisation: one transaction, with the `building_layout` current row locked
     * FIRST. Every write to the authored building store — the layout's and every room map's —
     * goes through here.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function serialise(callable $write): mixed
    {
        return DB::transaction(function () use ($write) {
            // The row is READ for the lock and the result deliberately discarded: what the
            // callers need is the lock, and each of them re-reads exactly the state it checks so
            // that no caller is reading a row somebody else fetched for a different reason.
            DB::table('building_layout')->where('id', self::ROW_ID)->lockForUpdate()->first();

            return $write();
        });
    }

    /**
     * ⭐ § 4.6's OVERLAP REFUSAL, resolved against the store and applied at BOTH of § 6.11's write
     * sites. It is one method for the reason § 6.11 gives: the second site is "the one an
     * implementer misses", and a second copy of this resolution is how the two would come to
     * check different things.
     *
     * @param  array<string, FloorMap|null>  $pending  documents about to become current in this transaction — see `App\Building\RoomExtents`
     */
    public static function refuseOverlaps(BuildingLayout $layout, array $pending = []): void
    {
        $placed = FloorPlan::placedRooms($layout->floors);

        if ($placed === []) {
            // No floor is planned, so there is no position to collide — § 4.6's default
            // arrangement is the client's and lays the rooms out side by side with a gap.
            return;
        }

        FloorPlan::refuseOverlaps($layout->floors, RoomExtents::resolve($placed, $pending));
    }

    /**
     * Save an authored layout document. Answers the new `layout_version`.
     *
     * @throws InvalidBuildingLayout on a document this reader refuses, on an overlap, or on a
     *                               byte-identical no-op
     */
    public static function save(string $document, string $by): int
    {
        $version = self::serialise(function () use ($document, $by) {
            self::refuseANoOp($document);

            $layout = BuildingLayout::fromJson($document);

            self::refuseOverlaps($layout);

            return self::write($document, null, $by);
        });

        // § 6.11: "on commit … publish". Outside the transaction, because a message announcing a
        // state the store never committed is the one thing a notification may never do.
        BuildingChanged::announce(BuildingChanged::LAYOUT, ['layout_version' => $version]);

        return $version;
    }

    /**
     * ⭐ § 6.11's RESTORE: "a new revision whose `document` copies revision K's, with
     * `restored_from = K`; history is never rewritten, and *undo the restore* is itself a
     * restore."
     *
     * ⛔ AND IT IS RE-CHECKED AGAINST TODAY'S ROOM MAPS, not the ones revision K was checked
     * against (§ 6.11, § 4.6) — "so *undo* cannot re-create an overlap a later map made". That is
     * the whole of why this method validates rather than trusting a document that was once valid.
     */
    public static function restore(int $revision, string $by): int
    {
        $version = self::serialise(function () use ($revision, $by) {
            $row = Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $revision);

            if ($row === null) {
                throw new InvalidBuildingLayout(sprintf(
                    'There is no layout revision %d to restore. The revisions list is the whole '
                    .'history (docs/design/FLEET-STATE.md § 6.11) and nothing removes a row from '
                    .'it, so a number that is not there was never written.',
                    $revision,
                ));
            }

            $document = (string) $row->document;

            self::refuseANoOp($document, $revision);

            $layout = BuildingLayout::fromJson($document);

            self::refuseOverlaps($layout);

            return self::write($document, $revision, $by);
        });

        BuildingChanged::announce(BuildingChanged::LAYOUT, ['layout_version' => $version]);

        return $version;
    }

    /**
     * ⛔ § 6.11's no-op rule. The comparison itself is `App\Building\Revisions::noOp()` — one
     * byte comparison for both kinds — and what belongs here is only how the refusal is WORDED to
     * an operator who is looking at the building rather than at a room.
     */
    private static function refuseANoOp(string $document, ?int $restoring = null): void
    {
        if (Revisions::noOp(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $document) === null) {
            return;
        }

        throw new InvalidBuildingLayout($restoring === null
            ? 'This layout is byte for byte the one that is already current, so there is nothing '
                .'to record. docs/design/FLEET-STATE.md § 6.11 refuses it rather than minting an '
                .'empty revision — a revision always records a change.'
            : sprintf(
                'Revision %d is byte for byte the layout that is already current, so restoring it '
                .'would change nothing. docs/design/FLEET-STATE.md § 6.11 refuses a no-op rather '
                .'than minting an empty revision.',
                $restoring,
            ));
    }

    /**
     * The two writes § 6.11 puts in one transaction: the revision, and the current row pointed at
     * it. Called only from inside `serialise()`.
     */
    private static function write(string $document, ?int $restoredFrom, string $by): int
    {
        $at = Clock::sql(now());

        $revision = Revisions::insert(
            Revisions::LAYOUT,
            Revisions::LAYOUT_SUBJECT,
            $document,
            $restoredFrom,
            $by,
            $at,
        );

        DB::table('building_layout')->updateOrInsert(
            ['id' => self::ROW_ID],
            [
                'document' => $document,
                // § 6.4: "= authored_revisions.revision of the row this IS".
                'layout_version' => $revision,
                'updated_by' => $by,
                'updated_at' => $at,
            ],
        );

        return $revision;
    }
}
