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

const budget = new DiscrepancyBudget();

/** Whether a floor has ever been rendered — § 9 F4's "on a cold start there is no floor to keep". */
let holding = false;

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

function renderFloors(model) {
    const list = el('lobby-floors');

    list.textContent = '';

    // § 9 F4's "never an empty office" has a sibling that is not a failure at all: a fleet with
    // no installs provisioned. It is rendered IN WORDS rather than as an empty list, because an
    // empty list and a lobby that failed to draw are the same pixels.
    if (model.floors.length === 0) {
        const none = document.createElement('li');
        none.textContent = 'no installs are provisioned — the fleet reports none';
        list.append(none);

        return;
    }

    for (const floor of model.floors) {
        const row = document.createElement('li');
        // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor".
        const link = document.createElement('a');
        link.href = floor.href;

        const name = document.createElement('span');
        name.textContent = floor.install_id;

        const summary = document.createElement('span');
        // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS,
        // never as a fleet fact. The label is what keeps it from being read as the second.
        summary.textContent = floor.summary === '' ? 'no seats held' : floor.summary;

        link.append(name, document.createTextNode(' — '), summary);
        row.append(link);
        list.append(row);
    }
}

function render(snapshot) {
    const model = lobbyModel(snapshot);

    renderFloors(model);

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

load();
