<?php

namespace App\Http\Controllers\Admin;

use App\Building\AuthoredDocument;
use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\RevisionDiff;
use App\Building\Revisions;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The console's BUILDING LAYOUT module — card#9208's reversal (2026-09-12),
 * `docs/design/FLEET-STATE.md § 6.11`, `docs/design/FLOOR.md § 4.6`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ WHY THE LAYOUT IS HERE AT ALL, and it is a ratified call rather than an inference now. The
 * layout used to be `config/building.php`, a deploy-time file, *because* the room map was a build
 * artifact — a layout in a runtime store beside a deploy-time map would have given one building
 * two change paths. card#9208 made the map a runtime document, and the same argument then ran the
 * other way, so the layout moved with it (§ 4.6). D3 recorded that as this design's INFERENCE;
 * the operator ratified it on 2026-09-12 (card#9208 comment 5), and § 4.6 now says so.
 *
 * ⛔ WHAT AN OPERATOR AUTHORS HERE IS *WHERE A ROOM IS DRAWN*, AND NOTHING ELSE. § 4.6: "the
 * layout decides where a room is drawn and contributes no count, no state, no membership and no
 * message". No form here names a seat, no field carries a floor id (a floor has none — its key is
 * DERIVED from its rooms), and nothing here is fleet state.
 *
 * ⛔ THE DOCUMENT IS EDITED AS JSON, and that is the same decision § 10.3 made for the map: the
 * console "does not grow an editor of its own" for a room, and a form-per-floor here would be a
 * second authoring surface for a document whose shape is already published (§ 4.6's member
 * table). What the console owes instead is a REFUSAL AN AUTHOR CAN ACT ON, which is what
 * `App\Building\BuildingLayout` is written to produce.
 *
 * ⚠ THE PREVIEW IS NOT IN THIS SLICE, and § 6.11's review row is what that costs: until the
 * floor's renderer exists (Appendix B step 7), restore is the only thing between a bad save and
 * every viewer. Every page in this module therefore puts the revisions list one click away.
 */
class LayoutController extends Controller
{
    /** What the editor offers when nothing was ever saved — § 8.7's *today's building*. */
    private const EMPTY_DOCUMENT = "{\n    \"floors\": []\n}";

    public function edit(): View
    {
        $current = Layouts::current();

        return view('console.layout.edit', [
            'active' => 'layout',
            'document' => $current === null ? self::EMPTY_DOCUMENT : (string) $current->document,
            'version' => (int) ($current->layout_version ?? 0),
            'updatedBy' => $current?->updated_by,
            'updatedAt' => $current?->updated_at,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['layout' => ['required', 'string']]);

        $document = AuthoredDocument::fromForm($validated['layout']);

        try {
            $version = Layouts::save($document, (string) $request->user()->email);
        } catch (InvalidBuildingLayout $e) {
            return back()->withInput()->withErrors(['layout' => $e->getMessage()]);
        }

        return redirect()->route('admin.layout.edit')->with('status', sprintf(
            'The building layout is saved as revision %d. Rooms the layout does not place still '
            .'render, each on a floor of its own (docs/design/FLOOR.md § 4.6), so this document is '
            .'a departure from that default and never a list anything depends on being complete.',
            $version,
        ));
    }

    public function revisions(): View
    {
        return view('console.layout.revisions', [
            'active' => 'layout',
            'version' => Layouts::version(),
            'revisions' => Revisions::history(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT)
                ->map(fn (object $row) => [
                    'revision' => (int) $row->revision,
                    'restored_from' => $row->restored_from === null ? null : (int) $row->restored_from,
                    'authored_by' => (string) $row->authored_by,
                    'authored_at' => (string) $row->authored_at,
                    'floors' => self::floorCount($row->document),
                    'bytes' => strlen((string) $row->document),
                ])->all(),
        ]);
    }

    public function diff(Request $request): View
    {
        $validated = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ]);

        $from = Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, (int) $validated['from']);
        $to = Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, (int) $validated['to']);

        abort_if($from === null || $to === null, 404);

        return view('console.layout.diff', [
            'active' => 'layout',
            'from' => (int) $from->revision,
            'to' => (int) $to->revision,
            'diff' => RevisionDiff::between((string) $from->document, (string) $to->document),
        ]);
    }

    /**
     * § 6.11's export, for the layout: the operator's own copy against a lost store. It is
     * `.json` rather than `.tmj` because a layout is not a Tiled map — the hallway inside it is,
     * and it is exported as part of the document it belongs to, which is the same reason it has
     * no `map_version` of its own (§ 4.6, card#9292).
     */
    public function export(int $revision): Response
    {
        $row = Revisions::get(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, $revision);

        abort_if($row === null, 404);

        return response((string) $row->document, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="building-layout-r'.$revision.'.json"',
        ]);
    }

    /**
     * ⛔ A RESTORE IS RE-CHECKED AGAINST TODAY'S ROOM MAPS (§ 6.11, § 4.6) — not the ones revision
     * K was checked against — "so *undo* cannot re-create an overlap a later map made". The
     * refusal that produces is the operator's to act on and comes back on this page.
     */
    public function restore(Request $request, int $revision): RedirectResponse
    {
        try {
            $version = Layouts::restore($revision, (string) $request->user()->email);
        } catch (InvalidBuildingLayout $e) {
            return redirect()->route('admin.layout.revisions')->withErrors(['revision' => $e->getMessage()]);
        }

        return redirect()->route('admin.layout.revisions')->with('status', sprintf(
            'Revision %d is the current layout again, as revision %d. A restore is a forward '
            .'revision (docs/design/FLEET-STATE.md § 6.11), so nothing was rewritten and undoing '
            .'this restore is itself a restore.',
            $revision,
            $version,
        ));
    }

    /** How many floors a revision composed — the one figure a revisions list can honestly show. */
    private static function floorCount(?string $document): ?int
    {
        $decoded = json_decode((string) $document, true);

        return is_array($decoded) && is_array($decoded['floors'] ?? null) ? count($decoded['floors']) : null;
    }
}
