/**
 * The lobby, wired to the page — `docs/design/FLOOR.md § 4.1` and § 4.4's `/` row
 * ("Fetches on entry: `GET /api/fleet/snapshot`").
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every string it writes comes from `lobby-model.js`, which is
 * pure and is exercised directly. This layer is elements and a `fetch`, and it is the part of
 * the client that NO CHECK IN THIS REPOSITORY EXERCISES — there is no browser on the build host,
 * so nothing here has been laid out, painted or clicked. What IS checked is that every element
 * id it addresses exists on the page and vice versa (`LobbyPageWiringTest`). Keeping the split
 * sharp is what keeps the uncovered part free of decisions.
 *
 * ⛔ NO POLL, NO SOCKET, NO ADMIT. The delta feed (D2 § 8.3, § 8.4), § 2.2's ADMIT and § 9 F1's
 * 10 s degraded poll are all out of this slice. The one repeat fetch this file can make is
 * § 4.1's discrepancy budget, which is bounded by `DiscrepancyBudget` and is not a cadence.
 *
 * ⛔ NO ANIMATION. § 6.5: "a snapshot never animates". There is no animation in this slice at
 * all, so there is nothing here to suppress — stated because the next reader adding a transition
 * to a re-render is the person this line is for.
 */

import { lobbyModel, storeUnavailableStatement, DiscrepancyBudget } from './lobby-model.js';
import { buildingModel } from './building-model.js';

const budget = new DiscrepancyBudget();

/** Whether a floor has ever been rendered — § 9 F4's "on a cold start there is no floor to keep". */
let holding = false;

/**
 * THE VIEWER'S OWN CAB POSITION, and it lives here because § 4.5 says navigation is never state:
 * it is not in the model's snapshot-derived facts, it is never sent anywhere, and no fact on this
 * page is read out of it. `null` is "the viewer has not ridden yet", which `building-model.js`
 * resolves to the first plate.
 */
let cab = null;

/**
 * The last snapshot body this page rendered. An elevator ride is a camera move (§ 4.5), so it
 * re-renders the building from the body already in hand and issues NO request — a ride that
 * fetched would make navigation a source of load on the read plane, and would make the stack
 * change under the viewer for a reason that is not the fleet moving.
 *
 * ⚠ NOT `body`: `load()` already binds that name to the response it is parsing, and a module
 * scope and a function scope holding one name for two values is how the wrong one gets rendered.
 */
let lastSnapshot = null;

/**
 * ⛔ A MISSING ELEMENT THROWS RATHER THAN BEING GUARDED PAST. The guarded form — `if (node ===
 * null) return;` at every site — IS the silent no-op that is this layer's whole risk: the page
 * renders, the fetch runs, and one fact is written nowhere at all. A throw leaves the page's own
 * placeholders standing — each of which says it is WAITING, never a zero and never a calm
 * fleet — and puts the cause in the console; `LobbyPageWiringTest` makes it unreachable in the
 * first place by set-differencing these ids against the page's, in both directions. Between a
 * loud failure and a quiet one, on a product whose entire subject is quiet degradation, this is
 * not a close call.
 */
function el(id) {
    const node = document.getElementById(id);

    if (node === null) {
        throw new Error(`the lobby page declares no element #${id} — the client and the page have drifted`);
    }

    return node;
}

/**
 * The one statement region. § 9's rule for every failure row is that the user SEES something:
 * "a floor that fails quietly is indistinguishable from a fleet that has gone home."
 */
function statement(text, keptLabel) {
    const box = el('lobby-statement');

    box.textContent = text ?? '';
    box.hidden = text === null;

    const kept = el('lobby-kept');

    kept.textContent = keptLabel ?? '';
    kept.hidden = !keptLabel;
}

/**
 * § 4.1's floor list, drawn as the ratified cross-section: one plate per install, stacked, the
 * plate being the link, with the elevator cab standing at one of them.
 *
 * ⛔ ONE RENDERING OF ONE FACT. The plates REPLACE the flat list rather than joining it — § 4.1's
 * cross-section "is a *rendering* of this table", not a second surface beside it, and two
 * renderings of one floor's summary on one page is exactly what § 2.4's one-form-per-fact rule
 * refuses. There is one `#lobby-floors` and the plates are its children.
 *
 * ⚠ THE STACK IS DRAWN FIRST-AT-THE-TOP, which is the reference artifact's direction and not a
 * ruling in the document — see `building-model.js`, which keeps `level` an index into § 4.1's
 * ascending order and leaves the direction here, where a rendering choice belongs.
 */
function renderBuilding(building) {
    const list = el('lobby-floors');

    list.textContent = '';

    // § 9 F4's "never an empty office" has a sibling that is not a failure at all: a fleet with
    // no installs provisioned. It is rendered IN WORDS rather than as an empty list, because an
    // empty list and a lobby that failed to draw are the same pixels.
    if (building.plates.length === 0) {
        const none = document.createElement('li');
        none.textContent = 'no installs are provisioned — the fleet reports none';
        list.append(none);
    }

    for (const plate of building.plates) {
        const row = document.createElement('li');
        // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor" — and the cross-section
        // changes nothing in that row: "**the plate is the link** exactly as the list row was".
        const link = document.createElement('a');
        link.href = plate.href;

        const name = document.createElement('span');
        name.textContent = plate.install_id;

        const summary = document.createElement('span');
        // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS,
        // never as a fleet fact. The label is what keeps it from being read as the second.
        summary.textContent = plate.summary === '' ? 'no seats held' : plate.summary;

        link.append(name, document.createTextNode(' — '), summary);
        row.append(link);

        if (plate.install_id === building.elevator.at) {
            // § 4.5: "Colour is never the only carrier of a fact" — and while the cab carries no
            // FACT at all, a viewer who cannot see where the elevator is standing cannot use it.
            // So the cab is a word, not a highlight.
            const here = document.createElement('span');
            here.textContent = ' — the elevator is here';
            row.append(here);
        }

        list.append(row);
    }

    const ride = el('lobby-elevator');

    // The destination is named on the control, so a ride is chosen rather than discovered.
    ride.textContent = building.elevator.next === null
        ? 'Ride the elevator'
        : `Ride the elevator to ${building.elevator.next}`;
    // ⛔ THE DARK CASE IS REFUSED AT THE CONTROL, not only explained beside it. The reason is the
    // notices below; a control that still invited a click would be a working elevator drawn over
    // a building that has no second floor.
    ride.disabled = building.elevator.next === null;

    const notices = el('lobby-elevator-notices');

    notices.textContent = '';
    notices.hidden = building.elevator.notices.length === 0;

    for (const notice of building.elevator.notices) {
        const line = document.createElement('li');
        line.textContent = notice;
        notices.append(line);
    }
}

function render(snapshot) {
    const model = lobbyModel(snapshot);
    const building = buildingModel(snapshot, cab);

    lastSnapshot = snapshot;
    // The cab is re-seated on what the model RESOLVED it to, so a stranded cab reports itself
    // once and the next render is an ordinary one.
    cab = building.elevator.at;

    renderBuilding(building);

    el('lobby-totals').textContent = model.totals;

    const discrepancy = el('lobby-discrepancy');

    discrepancy.textContent = model.discrepancy ?? '';
    discrepancy.hidden = model.discrepancy === null;

    el('lobby-stamp').textContent = model.stamp;

    // Three indicators plus § 5.3's ingest recency, each into its OWN element. There is no
    // element on this page that carries a combined verdict, which is D2 § 8.2.4's "no aggregate
    // rolls three health facts into one" held by the shape of the page and not by a convention.
    for (const indicator of model.indicators) {
        el(`lobby-${indicator.key}`).textContent = indicator.detail === null
            ? `${indicator.label}: ${indicator.value}`
            : `${indicator.label}: ${indicator.value} · ${indicator.detail}`;
    }

    statement(model.store_unavailable, model.store_unavailable === null ? null : 'last known good');

    holding = true;

    return model;
}

async function load() {
    let response;

    try {
        response = await fetch('/api/fleet/snapshot', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
    } catch {
        // The request never reached a status. Say so; do not draw an empty lobby.
        statement(
            'fleet state could not be requested — the browser could not reach the server',
            holding ? 'last known good' : null,
        );

        return;
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        // § 9 F4 — `503 fleet_unavailable`, with the refusal's own `server_time`.
        // Every other non-200 is reported with the code D2 § 8.6 puts in the body rather than
        // being folded into F4's sentence, which would name a cause that is not true.
        statement(
            response.status === 503
                ? storeUnavailableStatement(body.server_time)
                : `fleet state is unavailable — the read surface answered HTTP ${response.status}`
                    + (typeof body.error === 'string' ? ` (${body.error})` : ''),
            holding ? 'last known good' : 'nothing has been rendered yet — there is no earlier floor to keep',
        );

        return;
    }

    const model = render(body);

    // § 4.1's discrepancy check: ONE snapshot fetch per distinct (N, M) observation. A
    // disagreement still standing after that fetch is rendered and not re-fetched.
    if (model.discrepancy !== null && budget.admits(model.held, body?.fleet?.seats_total)) {
        await load();
    }
}

// § 2.3 names "the lobby's refresh control" as one of the three paths to a fresh membership
// picture, so this is a published element rather than a convenience added here.
el('lobby-refresh').addEventListener('click', () => {
    load();
});

/**
 * § 4.1's elevator, as § 4.5's camera: it moves the cab and re-renders the building from the body
 * already in hand. No fetch, no route change, no animation — "a camera move animates nothing in
 * § 6.2's sense: it renders no fact, it has no driving D2 field, and it gets no row in that
 * table".
 *
 * ⚠ Where the ride is SUPPOSED to arrive — § 4.5's camera at `/floor/{install_id}` — is not built
 * (card#9208), so this ride moves between the plates of this screen and the plate's own link is
 * still the only way to that route. `building-model.js` says so in full.
 */
el('lobby-elevator').addEventListener('click', () => {
    // ⛔ THE REFUSAL IS RE-ASKED OF THE MODEL RATHER THAN READ OFF THE BUTTON. Before the first
    // snapshot lands there is no building to ride and the control has not been disabled yet, so
    // `disabled` is not the only thing standing between a click and a ride to nowhere — and a
    // ride to nowhere would put the cab on `null`, which resolves to the first plate and reads as
    // a successful ride the viewer never took.
    const next = lastSnapshot === null ? null : buildingModel(lastSnapshot, cab).elevator.next;

    if (next === null) {
        return;
    }

    cab = next;
    render(lastSnapshot);
});

load();
