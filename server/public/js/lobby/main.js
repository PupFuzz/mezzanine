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
 *
 * ⭐ THE CAMERA AT BUILDING SCALE AND THE RIDE'S ARRIVAL ARE THE SCREEN's (Appendix B row 16, slice A,
 * card#7343); this file supplies the building's drawing surface, places each plate at the rect the
 * screen's scene gives it, shows the screen's camera on the plates as one transform, and wires the
 * wheel, the drag, the whole-building control and the ride to the screen's camera acts — none of which
 * renders. A ride's click moves the cab — the page's `cab`, set to the stop `ride()` names — glides
 * the camera to the plate (or cuts, under `prefers-reduced-motion`) and then ARRIVES: the page goes to
 * the route the screen handed back, `/floor/{key}`, which the floor page serves on a cold start. The
 * click commits the ride: an interrupted glide cuts to the plate and arrives, and the control is
 * disabled while the ride is in flight. The glide is the viewer's, and it steps through
 * `wire/camera-view.js`; the wheel and the drag are `wire/camera-gestures.js`'s — both the floor
 * page's own.
 */

import { livePage } from '../wire/live-page.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';
import { startLobbyScreen } from './lobby-screen.js';

/**
 * THE VIEWER'S OWN CAB POSITION, and it lives here because § 4.5 says navigation is never state:
 * it is not in the model's facts, it is never sent anywhere, and no fact on this page is read out
 * of it. `null` is "the viewer has not ridden yet", which `building-model.js` resolves to the first
 * plate.
 */
let cab = null;

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

/** The building's drawing surface in CSS px — the element the plates are drawn and the camera looks in. */
function surface() {
    const node = el('lobby-building');

    return { width: node.clientWidth, height: node.clientHeight };
}

/**
 * ⛔ THE CAMERA IS THE ONLY THING THAT MOVES THE VIEW. `#lobby-building` clips the plates
 * (`overflow: hidden`), and a clipping element can still be SCROLLED — focus moving to a plate outside
 * the view scrolls it into view — which slides the plates out from under the camera: the transform
 * says one place and the pixels show another. So its scroll is held at the origin, on every camera
 * shown and on every scroll the browser makes. The alternative, bringing the camera to the focused
 * plate on `focusin`, would be a new camera act on the screen for a behaviour no row states yet;
 * holding the scroll is one line in the one function every camera already passes through.
 */
function unscroll() {
    const node = el('lobby-building');

    node.scrollTop = 0;
    node.scrollLeft = 0;
}

/**
 * One camera on the plates: the scene point at the camera's `x`, `y` at the surface's top-left, at its
 * zoom — `wire/camera.js`'s `view`, as a CSS transform. Nothing framed is no transform at all: the list
 * as it flows, which is how a lobby with no plate reads (§ 9 F17's rooms, or no install).
 */
function view(camera) {
    unscroll();
    el('lobby-floors').style.transform = camera.bounds === null
        ? ''
        : `scale(${camera.zoom}) translate(${-camera.x}px, ${-camera.y}px)`;
}

const { show, glideTo, current } = cameraView(view);

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
function renderBuilding(building, scene, unclaimed, riding) {
    const rows = el('lobby-floors');

    rows.textContent = '';
    // The plates stand where the scene puts them (Appendix B row 16); with none, the list flows.
    rows.style.position = scene?.extent ? 'relative' : '';
    rows.style.width = scene?.extent ? `${scene.extent.w}px` : '';
    rows.style.height = scene?.extent ? `${scene.extent.h}px` : '';

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
        // `level` indexes the stack and the scene alike (`building-scene.js`).
        const rect = scene.plates[plate.level].rect;

        Object.assign(row.style, {
            position: 'absolute',
            left: `${rect.x}px`,
            top: `${rect.y}px`,
            width: `${rect.w}px`,
            height: `${rect.h}px`,
            // Scene px, which the camera scales: a size the drawing's, carrying no fact.
            fontSize: '48px',
        });
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
    // ⛔ THE DARK CASE IS REFUSED AT THE CONTROL, not only explained beside it — and so is a second ride
    // while one is in flight: the click commits the ride (`lobby-screen.js`'s `ride()`).
    ride.disabled = building.elevator.next === null || riding;

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

    // The cab is re-seated on what the model RESOLVED it to, so a stranded cab reports itself once
    // and the next render is an ordinary one. An uncomposed lobby (§ 9 F17) resolved nothing.
    if (building.composed) {
        cab = building.elevator.at;
    }

    renderBuilding(building, frame.scene, summary.unclaimed, frame.riding);
    // A render never moves the viewer: a glide in flight keeps its step, and otherwise the plates show
    // the screen's camera, which the render left where the viewer put it.
    view(current(frame.camera));

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
const screen = startLobbyScreen(client, pageFetch, paint, {
    surface: surface(),
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
});

// § 2.3 names "the lobby's refresh control" as one of the three paths to a fresh membership
// picture: one full snapshot through the protocol, then the layout.
el('lobby-refresh').addEventListener('click', () => {
    screen.refresh().then(requestRender);
});

/**
 * § 4.1's elevator, as § 4.5's camera (Appendix B row 16): the cab moves to the next stop and is
 * re-drawn there from what is held, the camera glides to that plate — a cut under
 * `prefers-reduced-motion` — and the page ARRIVES at the route the screen handed back, `/floor/{key}`.
 * No fetch and no animation: the ride is navigation. The refusal is the screen's (`ride()` answers
 * `null` where there is nowhere to ride, or while a ride is in flight), re-asked of the drawn model
 * rather than read off the button.
 *
 * ⛔ THE CLICK COMMITS THE RIDE (card#7343 r1 ruling). The glide is `camera-view.js`'s COMMITTED glide:
 * a wheel, a drag, a resize or the whole-building control during it cuts it to the plate and arrives,
 * and the ride control is disabled from the click until the page is left.
 */
el('lobby-elevator').addEventListener('click', () => {
    const ride = screen.ride();

    if (ride === null) {
        return;
    }

    cab = ride.cab;
    screen.draw(cab);
    glideTo(ride.from, ride.to, ride.glide_ms, () => {
        window.location.assign(ride.route);
    }, { commit: true });
});

// Back to a lobby the browser kept whole (the back-forward cache): the ride that left it has arrived,
// so the viewer may ride again.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        screen.returned();
        screen.draw(cab);
    }
});

// The whole-building control: every plate in view, gliding there — or cutting, under reduced motion.
el('lobby-whole-building').addEventListener('click', () => {
    const { from, to, glide_ms: ms } = screen.wholeBuilding();

    glideTo(from, to, ms);
});

// The wheel zooms about the cursor in proportion to its scroll, and a drag with the primary button pans
// — row 15's acts at building scale, wired by `wire/camera-gestures.js`, the floor's too. Neither
// renders; each shows the camera the screen hands back. A drag that moved is no click on the plate —
// the link — it ended over.
const building = el('lobby-building');

cameraGestures(building, { wheel: screen.wheel, drag: screen.drag }, show);
// Focus can scroll the clipping surface; the camera alone moves the view (`unscroll()`).
building.addEventListener('scroll', unscroll);
// A resize re-shows the screen's camera at once, stopping a glide in flight over the old surface — or
// finishing a ride's, which then arrives.
window.addEventListener('resize', () => {
    show(screen.resize(surface()));
});

// § 4.4: the stream first, then the snapshot (§ 2.2) — and the layout after it, on the first render
// that finds the snapshot applied.
client.start();
requestRender();
