/**
 * The lobby, wired to the page — `docs/design/FLOOR.md § 4.1`, § 4.4's `/` row, Appendix B row 9.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every string it writes comes from `lobby-screen.js`'s frame — built
 * over `lobby-model.js`, `building-model.js`, `../floor/status-strip.js` and
 * `../wire/failure-render.js` — and every one of those is exercised headlessly under `node`. This
 * layer is elements, and it is the part of the client that NO CHECK IN THIS REPOSITORY EXERCISES —
 * there is no browser on the build host, so nothing here has been laid out, painted or clicked.
 * What IS checked is that every element id it addresses exists on the page and vice versa, and that
 * it constructs the client protocol with its stream recovery (`LobbyPageWiringTest`).
 *
 * ⛔ THE LOBBY RUNS THE CLIENT PROTOCOL (card#7341 step 9). `wire/live-page.js` constructs it with
 * its scheduler — the floor page's own construction, shared — so this page opens the stream, reads
 * the snapshot through the protocol, and renders the population the protocol holds. § 4.1's
 * discrepancy trigger is the protocol's alone: this page issues no snapshot fetch of its own, so one
 * disagreement costs one request, not two.
 *
 * ⛔ NO ANIMATION. § 6.5: "a snapshot never animates", and § 4.1's plates carry no § 6.2 row — so
 * there is nothing here to suppress, stated because the next reader adding a transition to a
 * re-render is the person this line is for.
 */

import { livePage } from '../wire/live-page.js';
import { startLobbyScreen } from './lobby-screen.js';

/**
 * THE VIEWER'S OWN CAB POSITION, and it lives here because § 4.5 says navigation is never state:
 * it is not in the model's facts, it is never sent anywhere, and no fact on this page is read out
 * of it. `null` is "the viewer has not ridden yet", which `building-model.js` resolves to the first
 * plate.
 */
let cab = null;

/**
 * The last building this page RENDERED — the model the plates and the ride control were drawn
 * from, kept so the elevator's click can re-ask the model that produced the button rather than
 * composing a second one.
 */
let lastBuilding = null;

/**
 * ⛔ A MISSING ELEMENT THROWS RATHER THAN BEING GUARDED PAST. The guarded form — `if (node ===
 * null) return;` at every site — IS the silent no-op that is this layer's whole risk: the page
 * renders, the fetch runs, and one fact is written nowhere at all. A throw leaves the page's own
 * placeholders standing — each of which says it is WAITING, never a zero and never a calm
 * fleet — and puts the cause in the console; `LobbyPageWiringTest` makes it unreachable in the
 * first place by set-differencing these ids against the page's, in both directions.
 */
function el(id) {
    const node = document.getElementById(id);

    if (node === null) {
        throw new Error(`the lobby page declares no element #${id} — the client and the page have drifted`);
    }

    return node;
}

/** Text into an element, hidden when there is none. */
function say(id, text) {
    const node = el(id);

    node.textContent = text ?? '';
    node.hidden = text === null || text === undefined || text === '';
}

/** A list element rebuilt from lines the model has already decided the text of. */
function list(id, lines) {
    const node = el(id);

    node.replaceChildren(...lines.map((line) => {
        const item = document.createElement('li');

        item.textContent = line;

        return item;
    }));
    node.hidden = lines.length === 0;
}

/**
 * § 4.1's floor list, drawn as the ratified cross-section: one plate per FLOOR, stacked, the
 * plate being the link, with the elevator cab standing at one of them. A floor of more than one
 * room names its rooms on the plate (§ 4.1 row 1), and a room the client holds no seat for says
 * so in § 4.6's words — the client's own narration (§ 5.5), about SEATS and never about the
 * install.
 *
 * ⛔ ONE RENDERING OF ONE FACT. The plates REPLACE the flat list rather than joining it — § 4.1's
 * cross-section "is a *rendering* of this table", not a second surface beside it.
 *
 * ⚠ THE STACK IS DRAWN FIRST-AT-THE-TOP, which is the reference artifact's direction and not a
 * ruling in the document — see `building-model.js`.
 */
function renderBuilding(building, unclaimed) {
    const rows = el('lobby-floors');

    rows.textContent = '';

    // § 9 F17's cold start: no layout was ever loaded, so no floor is composed and each install
    // the client holds is listed as a room with no floor claimed — every seat still reachable
    // through its own link, which § 4.4 resolves once the layout is readable.
    for (const room of unclaimed) {
        const row = document.createElement('li');
        const link = document.createElement('a');
        link.href = room.href;
        link.textContent = `${room.install_id} — no floor claimed`;
        row.append(link);
        rows.append(row);
    }

    // A fleet with no installs provisioned is rendered IN WORDS rather than as an empty list,
    // because an empty list and a lobby that failed to draw are the same pixels.
    if (building.plates.length === 0 && unclaimed.length === 0) {
        const none = document.createElement('li');
        none.textContent = 'no installs are provisioned — the fleet reports none';
        rows.append(none);
    }

    for (const plate of building.plates) {
        const row = document.createElement('li');
        // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor".
        const link = document.createElement('a');
        link.href = plate.href;

        const name = document.createElement('span');
        // § 4.6 (card#9273): the floor reads as its LABEL where the layout gives it one, else as
        // its key. The link above is the key either way.
        name.textContent = plate.name;

        const summary = document.createElement('span');
        // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS.
        summary.textContent = plate.summary === '' ? 'no seats held' : plate.summary;

        link.append(name, document.createTextNode(' — '), summary);
        row.append(link);

        if (plate.rooms.length > 1 || plate.rooms.some((room) => !room.reported)) {
            const rooms = document.createElement('span');
            rooms.textContent = ' — rooms: ' + plate.rooms
                .map((room) => `${room.install_id} (${room.form}${room.reported ? '' : ' — no seats reported for this room'})`)
                .join(', ');
            row.append(rooms);
        }

        if (plate.floor === building.elevator.at) {
            // § 4.5: "Colour is never the only carrier of a fact" — so the cab is a word.
            const here = document.createElement('span');
            here.textContent = ' — the elevator is here';
            row.append(here);
        }

        rows.append(row);
    }

    const ride = el('lobby-elevator');

    // The destination is named on the control the way the plate is (§ 4.6: the label else the key).
    ride.textContent = building.elevator.next === null
        ? 'Ride the elevator'
        : `Ride the elevator to ${building.elevator.destination}`;
    // ⛔ THE DARK CASE IS REFUSED AT THE CONTROL, not only explained beside it.
    ride.disabled = building.elevator.next === null;

    list('lobby-elevator-notices', [...building.elevator.notices]);
}

/** One lobby frame onto the page. */
function paint(frame) {
    // § 5.5 / § 4.1: the feed status with the resync count beside it — this page's own connection.
    say('lobby-feed', `feed: ${frame.strip.feed}`);
    say('lobby-resyncs', frame.strip.resyncs);

    // § 9 F4/F5, F6/F7 and F8 — the failure renders, the floor page's words for the lobby too.
    const failure = frame.failure;

    say('lobby-statement', failure.statement);
    say('lobby-kept', failure.kept);
    say('lobby-banner', failure.banner);
    el('lobby-signin').hidden = failure.sign_in === null;
    say('lobby-signin-prompt', failure.sign_in?.prompt ?? null);
    say('lobby-not-live', failure.sign_in?.label ?? null);

    // § 4.1: the disagreement, rendered rather than resolved by picking a winner.
    say('lobby-discrepancy', frame.discrepancy);

    // § 5.5's record: this client's own narration, newest first.
    list('lobby-log', [...frame.event_log]);

    const summary = frame.summary;

    // Nothing applied yet: the placeholders say the page is waiting, and the failure render above
    // says why when a read failed. A building drawn now would be a calm empty office.
    if (summary === null) {
        return;
    }

    const building = frame.building;

    lastBuilding = building;

    // The cab is re-seated on what the model RESOLVED it to, so a stranded cab reports itself once
    // and the next render is an ordinary one. An uncomposed lobby (§ 9 F17) resolved nothing.
    if (building.composed) {
        cab = building.elevator.at;
    }

    renderBuilding(building, summary.unclaimed);

    // § 9 F17's statement, in its own region beside F4/F5's store statement.
    say('lobby-layout-statement', summary.layout_statement);
    say('lobby-layout-kept', summary.layout_kept);

    el('lobby-totals').textContent = summary.totals;
    el('lobby-stamp').textContent = summary.stamp;

    // Three indicators plus § 5.3's ingest recency, each into its OWN element — D2 § 8.2.4's "no
    // aggregate rolls three health facts into one" held by the shape of the page.
    for (const indicator of summary.indicators) {
        el(`lobby-${indicator.key}`).textContent = indicator.detail === null
            ? `${indicator.label}: ${indicator.value}`
            : `${indicator.label}: ${indicator.value} · ${indicator.detail}`;
    }
}

const { client, fetch: pageFetch, requestRender } = livePage(() => screen.render(cab));
const screen = startLobbyScreen(client, pageFetch, paint);

// § 2.3 names "the lobby's refresh control" as one of the three paths to a fresh membership
// picture: one full snapshot through the protocol, then the layout.
el('lobby-refresh').addEventListener('click', () => {
    screen.refresh().then(requestRender);
});

/**
 * § 4.1's elevator, as § 4.5's camera: it moves the cab and re-draws the building from what is
 * held. No fetch, no route change, no animation.
 *
 * ⚠ The ride moves between the plates of this screen; the plate's own link is the way to the
 * floor route (`/floor/{floor}`, Appendix B row 8), which § 4.5's camera would arrive at.
 */
el('lobby-elevator').addEventListener('click', () => {
    // ⛔ THE REFUSAL IS RE-ASKED OF THE MODEL RATHER THAN READ OFF THE BUTTON. Before the first
    // snapshot lands there is no building to ride, and a ride to nowhere would put the cab on
    // `null`, which resolves to the first plate and reads as a ride the viewer never took.
    const next = lastBuilding === null ? null : lastBuilding.elevator.next;

    if (next === null) {
        return;
    }

    cab = next;
    screen.draw(cab);
});

// § 4.4: the stream first, then the snapshot (§ 2.2) — and the layout after it, on the first render
// that finds the snapshot applied.
client.start();
requestRender();
