<?php

namespace App\Http\Controllers\Admin;

use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use App\Http\Controllers\Controller;
use App\Read\Snapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's AGENT module — card#9070's third scope item, and deliberately the narrowest of
 * the three: **manage and remove only.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THERE IS NO SEAT-CREATE ACTION, AND ITS ABSENCE IS THE DESIGN RATHER THAN AN UNFINISHED EDGE.
 * `docs/design/FLOOR.md § 3.4`: a seat exists because it REPORTED; retirement is an explicit
 * operator act and there is no "add a seat". Hand-authoring one would store a fact the system
 * currently derives — the class the operator deferred to `card#9071` — so this module reads state
 * and performs the one existing act.
 *
 * ⛔ THE RETIREMENT IT PERFORMS IS `mezzanine:retire`'s, NOT A COPY OF IT. Both call
 * `App\Fleet\SeatRetirement`, which is the single writer § 4.10 requires; a console that set the
 * three columns itself would skip the recompute, the `cause: operator` transition row and the
 * `seat.retired` publish, and the desk would stay on every connected floor until a sweep pass.
 *
 * ⚠ THE LIST IS `Snapshot::seats()` — THE SAME READ THE FLEET RENDERS — rather than a second
 * query. That means `App\Read\RetirementFilter` applies: a seat retired more than
 * `Purge::RETENTION_DAYS` ago is not listed here, exactly as it is not rendered on the floor.
 * § 4.10 is explicit that this is a READ FILTER and not a deletion ("an operator query can still
 * find the row and its reason"), so nothing is lost — but the row is past the window in which
 * anything on this page could act on it, and a second hand-written query is how the console and
 * the floor would come to disagree about which seats exist.
 */
class SeatController extends Controller
{
    /**
     * ⚠ `docs/design/FLEET-STATE.md § 6.4` fixes `seats.retired_by` at 64 characters and says
     * names are final. An operator's own email may be up to 255 (`users.email`), so an author
     * that does not fit is REFUSED rather than truncated: a truncated author is a wrong answer to
     * the one question the column exists to answer, and under MySQL's strict mode it would be a
     * 500 instead.
     */
    private const AUTHOR_MAX = 64;

    public function index(): View
    {
        return view('console.seats.index', ['active' => 'agents', 'seats' => Snapshot::seats()]);
    }

    public function retire(
        Request $request,
        SeatRetirement $retirement,
        string $installId,
        string $seatId,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $by = (string) $request->user()->email;

        if (mb_strlen($by) > self::AUTHOR_MAX) {
            return redirect()->route('admin.agents.index')->withErrors([
                'retire' => sprintf(
                    'Refused: this seat store records the retiring operator in %d characters '
                    .'(docs/design/FLEET-STATE.md § 6.4) and your address is %d. Retire from the '
                    .'shell with `php artisan mezzanine:retire --seat=%s/%s --by=<short name> '
                    .'--reason=…` so the record names you correctly.',
                    self::AUTHOR_MAX, mb_strlen($by), $installId, $seatId,
                ),
            ]);
        }

        $outcome = $retirement->retire($installId, $seatId, $by, $validated['reason']);

        $seat = $installId.'/'.$seatId;

        if ($outcome->outcome === SeatRetirementOutcome::NO_SUCH_SEAT) {
            return redirect()->route('admin.agents.index')->withErrors([
                'retire' => 'No such seat: '.$seat.'. Nothing was changed.',
            ]);
        }

        $message = $outcome->outcome === SeatRetirementOutcome::ALREADY_RETIRED
            ? $seat.' was already retired (at '.$outcome->at.') — no-op. The original author and '
                .'reason are kept.'
            : $seat.' retired at '.$outcome->at.' — state_version '.$outcome->version
                .'. Every connected floor was told in the same transaction.';

        return redirect()->route('admin.agents.index')->with('status', $message);
    }
}
