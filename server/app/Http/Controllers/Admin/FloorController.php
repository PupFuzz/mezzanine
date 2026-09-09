<?php

namespace App\Http\Controllers\Admin;

use App\Floor\FloorInventory;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\TiledMap;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The console's FLOORS module — card#9085, the (a) half of the operator's 2026-09-08 ruling:
 * *"add/remove floors; author the MAP — how many desk slots, where they sit, the room's shape"*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THIS MODULE AUTHORS IS A ROOM, NEVER A SEATING PLAN. `docs/design/FLOOR.md § 3.2`
 * assigns each seat to a desk slot by a pure function of the rendered seat set, "so two browsers,
 * two reloads and two server restarts agree without a stored position and without a server
 * field". So there is no action here that names a seat, and `App\Floor\Floors` has no column that
 * could hold one: pinning a named seat to a chosen desk is `card#9071`'s open ruling, and the
 * test that decides it — *does the feature store a fact the system currently derives?* — is what
 * keeps a later "while I'm here" edit from answering it by accident.
 *
 * ⛔ AND THERE IS NO "CREATE AN INSTALL" EITHER, for the same reason the agent module has no
 * "add a seat". A floor IS an install (`§ 3.1`), an install row is written by exactly one act —
 * `mezzanine:ingest-token:issue`, where issuing the credential and creating the row are one
 * step — and `card#9071`'s ruling keeps that on the CLI, behind shell access. What "add a floor"
 * means here is therefore precise and is the whole of what (a) asked for: **give a floor its
 * map.** A map may only be authored for an install the snapshot renders, so this module can never
 * mint a floor that does not exist.
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
     * ⛔ THE ONE ACT BEHIND BOTH WRITES. Authoring a floor's first map and replacing it are the
     * same act on the same row, so they are one method: two copies would be free to disagree
     * about what gets recorded, and the first thing they would disagree about is who last
     * authored the map.
     */
    private function save(Request $request, string $installId, string $document): RedirectResponse
    {
        $map = FloorMap::parse($document);

        Floors::save($installId, $map, (string) $request->user()->email);

        return redirect()->route('admin.floors.index')->with('status', sprintf(
            '%s now has a map declaring %d desk slot%s. Which seat sits at which desk is still '
            .'derived from the seats themselves (docs/design/FLOOR.md § 3.2) — the map decides how '
            .'many desks there are and where they sit, never who is at them.',
            $installId,
            $map->slots,
            $map->slots === 1 ? '' : 's',
        ));
    }

    /**
     * ⚠ A POST TO A NAMED ACT RATHER THAN A `DELETE` ON THE RESOURCE, which is card#9070's D2
     * shape — and the substance differs from the user and seat cases, so it is stated rather than
     * copied. Retirement exists because a user or a seat has a HISTORY that must survive it. A
     * floor map has none: it is an authored artifact with nothing referring to it, and it lives in
     * the operator's Tiled project besides. Removing it removes a drawing, not a record — and it
     * removes nothing of the floor itself, which is fleet state this console does not own.
     */
    public function remove(string $installId): RedirectResponse
    {
        if (! Floors::remove($installId)) {
            return redirect()->route('admin.floors.index')->withErrors([
                'floor' => 'No map is authored for '.$installId.', so there was nothing to remove.',
            ]);
        }

        return redirect()->route('admin.floors.index')->with('status', sprintf(
            'The map for %s was removed. The floor, its seats and their state are untouched — '
            .'this removed the room, not the install.',
            $installId,
        ));
    }
}
