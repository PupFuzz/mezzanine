<?php

namespace App\Http\Controllers\Admin;

use App\Building\AuthoredDocument;
use App\Building\InvalidBuildingLayout;
use App\Building\RevisionDiff;
use App\Building\Revisions;
use App\Floor\FloorInventory;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\InvalidFloorMap;
use App\Floor\TiledMap;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The console's FLOORS module — card#9085, the (a) half of the operator's 2026-09-08 ruling:
 * *"add/remove floors; author the MAP — how many desk slots, where they sit, the room's shape"* —
 * and since card#9208's reversal its **revisions, diff, restore and export** as well
 * (`docs/design/FLEET-STATE.md § 6.11`, `docs/design/FLOOR.md § 10.3`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ WHAT THE REVISION MODULE IS FOR, AND IT IS NOT BOOKKEEPING. § 6.11: the authored store took
 * away the four things version control gave a build artifact — a diff, a review, a revert and a
 * blame — and gives back three. This module is those three. ⚠ The fourth, REVIEW, is the one it
 * does not give back: "every authenticated user is an operator (card#9070), so no second person
 * stands between a save and the floor", and until the console can PREVIEW a document with the
 * floor's own renderer — Appendix B step 7's renderer, which does not exist — **restore is the
 * only thing between a bad save and every viewer.** That is why restore ships in this slice and
 * the preview does not: one of them can be built today.
 *
 * ⛔ WHAT THIS MODULE AUTHORS IS A ROOM, NEVER A SEATING PLAN. `docs/design/FLOOR.md § 3.2`
 * assigns each seat to a desk slot by a pure function of the rendered seat set, "so two browsers,
 * two reloads and two server restarts agree without a stored position and without a server
 * field". So there is no action here that names a seat, and `App\Floor\Floors` has no column that
 * could hold one: pinning a named seat to a chosen desk is card#9071's ruling — **no**, 2026-09-12
 * — and `App\Floor\FloorMap` now refuses a desk object carrying ANY property so that a seat's name
 * cannot arrive as one.
 *
 * ⛔ AND THERE IS NO "CREATE AN INSTALL" EITHER, for the same reason the agent module has no
 * "add a seat". A floor IS an install (`§ 3.1`), an install row is written by exactly one act —
 * `mezzanine:ingest-token:issue`, where issuing the credential and creating the row are one
 * step — and `card#9071`'s ruling keeps that on the CLI, behind shell access. What "add a floor"
 * means here is therefore precise and is the whole of what (a) asked for: **give a floor its
 * map.** A map may only be authored for an install the snapshot renders, so this module can never
 * mint a floor that does not exist.
 *
 * ⚠ THE STORE'S REFUSALS ARE CAUGHT AND RENDERED, NOT LET THROUGH AS A 500. A map can be refused
 * by `App\Floor\FloorMap` at validation time (the form rule), and it can be refused by the WRITE
 * for two reasons the document alone cannot show: it is byte-identical to what is already current
 * (§ 6.11's no-op), or the room's new extent would overlap a neighbour on a planned floor (§ 4.6,
 * card#9292). Both are the operator's to fix and both name what to do, so both come back on the
 * form they were submitted from.
 *
 * ⚠ NO `Authorize`/policy CALLS — card#9070's D3: every authenticated user is an operator, and
 * the authorization statement is the route group's middleware. `routes/admin.php` carries the
 * decision and the trigger that would void it.
 */
class FloorController extends Controller
{
    public function index(): View
    {
        return view('console.floors.index', [
            'active' => 'floors',
            'rows' => FloorInventory::rows(),
        ]);
    }

    public function create(): View
    {
        return view('console.floors.create', [
            'active' => 'floors',
            'installs' => FloorInventory::installsWithoutAMap(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                // ⛔ THE WRITE-SIDE MEMBERSHIP CHECK. The form offers a `<select>` of the floors
                // this deploy renders; that is a read-time convenience and this is the rule. An
                // install id nobody reports is a floor nothing draws, and a map authored for one
                // is an artifact that can only ever be a typo.
                'install_id' => ['required', 'string', Rule::in(FloorInventory::renderedInstalls())],
                'map' => ['required', 'string', new TiledMap],
            ],
            [
                'install_id.in' => 'No floor with that install id renders on this deploy. A floor '
                    .'is an install, and an install exists because a seat was provisioned for it '
                    .'with `php artisan mezzanine:ingest-token:issue` — the console does not '
                    .'create one (docs/design/FLOOR.md § 3.1, § 3.4).',
            ],
        );

        return $this->save($request, $validated['install_id'], $validated['map']);
    }

    public function edit(string $installId): View
    {
        $floor = Floors::forInstall($installId);

        abort_if($floor === null, 404);

        return view('console.floors.edit', [
            'active' => 'floors',
            'installId' => $installId,
            'map' => $floor->map,
            'version' => (int) $floor->map_version,
        ]);
    }

    public function update(Request $request, string $installId): RedirectResponse
    {
        abort_if(Floors::forInstall($installId) === null, 404);

        $validated = $request->validate([
            'map' => ['required', 'string', new TiledMap],
        ]);

        return $this->save($request, $installId, $validated['map']);
    }

    /**
     * § 6.11's BLAME and the way into its revert: every revision of this room's map, newest
     * first, with who authored it, when, and what `S` it declares.
     *
     * ⚠ A ROOM WITH NO REVISIONS IS A ROOM NOBODY HAS AUTHORED, and it is a legal state rather
     * than a 404: it renders the shipped default (§ 8.7), and the page says so. A 404 would be
     * the *unauthored room is a failure* defect § 2.2's register names, one surface out.
     */
    public function revisions(string $installId): View
    {
        return view('console.floors.revisions', [
            'active' => 'floors',
            'installId' => $installId,
            'current' => Floors::forInstall($installId),
            'revisions' => Revisions::history(Revisions::ROOM_MAP, $installId)->map(fn (object $row) => [
                'revision' => (int) $row->revision,
                'removal' => $row->document === null,
                'restored_from' => $row->restored_from === null ? null : (int) $row->restored_from,
                'authored_by' => (string) $row->authored_by,
                'authored_at' => (string) $row->authored_at,
                'slots' => self::slotsOf($row->document),
                'bytes' => $row->document === null ? null : strlen((string) $row->document),
            ])->all(),
        ]);
    }

    /**
     * § 6.11's DIFF: "the tile layers whose data differ, the desk count `S` before and after, and
     * a line diff of the two documents pretty-printed".
     */
    public function diff(Request $request, string $installId): View
    {
        $validated = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ]);

        $from = Revisions::get(Revisions::ROOM_MAP, $installId, (int) $validated['from']);
        $to = Revisions::get(Revisions::ROOM_MAP, $installId, (int) $validated['to']);

        abort_if($from === null || $to === null, 404);

        return view('console.floors.diff', [
            'active' => 'floors',
            'installId' => $installId,
            'from' => (int) $from->revision,
            'to' => (int) $to->revision,
            'diff' => RevisionDiff::between(
                $from->document === null ? null : (string) $from->document,
                $to->document === null ? null : (string) $to->document,
            ),
        ]);
    }

    /**
     * § 6.11's EXPORT: "The console serves any revision as a `.tmj` download. That is the
     * operator's own copy against a lost store, and it is how an authored room becomes the
     * repository's shipped default."
     *
     * ⚠ A REMOVAL HAS NO DOCUMENT TO SERVE, and the refusal says which revision and why rather
     * than downloading an empty file called a map.
     */
    public function export(string $installId, int $revision): Response|RedirectResponse
    {
        $row = Revisions::get(Revisions::ROOM_MAP, $installId, $revision);

        abort_if($row === null, 404);

        if ($row->document === null) {
            return redirect()->route('admin.floors.revisions', $installId)->withErrors([
                'revision' => sprintf(
                    'Revision %d records that %s\'s map was REMOVED, so there is no document to '
                    .'export (docs/design/FLEET-STATE.md § 6.11). Export the revision before it, '
                    .'which is the map that was removed.',
                    $revision,
                    $installId,
                ),
            ]);
        }

        return self::download((string) $row->document, self::filename($installId).'-r'.$revision.'.tmj');
    }

    /**
     * § 6.11's REVERT: "a new revision whose `document` copies revision K's, with
     * `restored_from = K`; history is never rewritten, and *undo the restore* is itself a
     * restore."
     */
    public function restore(Request $request, string $installId, int $revision): RedirectResponse
    {
        try {
            $version = Floors::restore($installId, $revision, (string) $request->user()->email);
        } catch (InvalidFloorMap|InvalidBuildingLayout $e) {
            return redirect()->route('admin.floors.revisions', $installId)->withErrors(['revision' => $e->getMessage()]);
        }

        return redirect()->route('admin.floors.revisions', $installId)->with('status', $version === null
            ? sprintf(
                'Revision %d recorded a removal, so %s is back on the shipped default map and '
                .'revision %d records that it was revision %d that put it there. Nothing was '
                .'rewritten: every revision is still there to restore.',
                $revision,
                $installId,
                (int) Revisions::current(Revisions::ROOM_MAP, $installId)->revision,
                $revision,
            )
            : sprintf(
                'Revision %d is current for %s again, as revision %d — a restore is a forward '
                .'revision (docs/design/FLEET-STATE.md § 6.11), so undoing this restore is itself '
                .'a restore and nothing was rewritten.',
                $revision,
                $installId,
                $version,
            ));
    }

    /**
     * ⛔ THE ONE ACT BEHIND BOTH WRITES. Authoring a floor's first map and replacing it are the
     * same act on the same row, so they are one method: two copies would be free to disagree
     * about what gets recorded, and the first thing they would disagree about is who last
     * authored the map.
     */
    private function save(Request $request, string $installId, string $document): RedirectResponse
    {
        $map = FloorMap::parse(AuthoredDocument::fromForm($document));

        try {
            $version = Floors::save($installId, $map, (string) $request->user()->email);
        } catch (InvalidFloorMap|InvalidBuildingLayout $e) {
            return back()->withInput()->withErrors(['map' => $e->getMessage()]);
        }

        return redirect()->route('admin.floors.index')->with('status', sprintf(
            '%s now has a map declaring %d desk slot%s, saved as revision %d. Which seat sits at '
            .'which desk is still derived from the seats themselves (docs/design/FLOOR.md § 3.2) '
            .'— the map decides how many desks there are and where they sit, never who is at them.',
            $installId,
            $map->slots,
            $map->slots === 1 ? '' : 's',
            $version,
        ));
    }

    /**
     * ⚠ A POST TO A NAMED ACT RATHER THAN A `DELETE` ON THE RESOURCE, which is card#9070's D2
     * shape — and the substance differs from the user and seat cases, so it is stated rather than
     * copied. Retirement exists because a user or a seat has a HISTORY that must survive it.
     *
     * ⭐ SINCE card#9208 A MAP HAS ONE TOO, and it survives this act rather than being destroyed
     * by it: § 6.11 makes a removal a REVISION with a `document NULL`, "so a removed map is as
     * retrievable as an edited one, and the room renders the shipped default until it is authored
     * again". What this removes is the room's CURRENT map, not its history — and it removes
     * nothing of the floor itself, which is fleet state this console does not own.
     */
    public function remove(Request $request, string $installId): RedirectResponse
    {
        try {
            $removed = Floors::remove($installId, (string) $request->user()->email);
        } catch (InvalidBuildingLayout $e) {
            return redirect()->route('admin.floors.index')->withErrors(['floor' => $e->getMessage()]);
        }

        if (! $removed) {
            return redirect()->route('admin.floors.index')->withErrors([
                'floor' => 'No map is authored for '.$installId.', so there was nothing to remove.',
            ]);
        }

        return redirect()->route('admin.floors.index')->with('status', sprintf(
            'The map for %s was removed and the room is back on the shipped default. The removal '
            .'is itself a revision (docs/design/FLEET-STATE.md § 6.11), so the map it removed is '
            .'still there to restore. The floor, its seats and their state are untouched — this '
            .'removed the room, not the install.',
            $installId,
        ));
    }

    /** `S` for a revision's document, or `null` for a removal or a map a later rule refuses. */
    private static function slotsOf(?string $document): ?int
    {
        if ($document === null) {
            return null;
        }

        try {
            return FloorMap::parse($document)->slots;
        } catch (InvalidFloorMap) {
            return null;
        }
    }

    /**
     * ⚠ THE DOWNLOAD'S NAME IS BUILT FROM A ROUTE SEGMENT, so it is reduced to the characters an
     * `install_id` can contain (`docs/design/EVENT-SCHEMA.md § 3.1`) before it reaches a header.
     * A `Content-Disposition` filename is a header value, and a route segment is operator input:
     * the two meet here and nowhere else.
     */
    private static function filename(string $installId): string
    {
        return preg_replace('/[^a-z0-9-]/', '', strtolower($installId)) ?: 'room';
    }

    private static function download(string $document, string $filename): Response
    {
        return response($document, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
