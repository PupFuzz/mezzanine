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
 * re-render is the person this line is for. The one transition on this page is the cab's, and it is
 * the ride's: navigation, over the ride's own glide, and cut on every render that is no ride (the
 * building's `drawing`, below).
 *
 * ⭐ THE CAMERA AT BUILDING SCALE AND THE RIDE'S ARRIVAL ARE THE SCREEN's (Appendix B row 16, slice A,
 * card#7343); this file supplies the building's drawing surface — a clipping drawing only while there
 * is a building to draw (`building-scene.js`'s `surfaceStyle()`) — stands each plate at the rect the
 * screen's scene gives it (`plate-row.js`'s row) over the building `building-scene.js` draws — the roof and
 * its sign, a storey under each plate, the ground lobby and the cab (slice B) — shows the screen's camera
 * on the plates and the drawing as one
 * transform — each plate's text, its name and its status line, counter-scaled from that same camera,
 * so it is read at the page's body text size at every zoom (the operator's rulings, `building-scene.js`'s
 * `LABEL_FONT`), and wrapped within what is visible of the surface (`building-scene.js`'s `labelMax()`) — and wires the wheel, the drag, the keys, the zoom buttons, the keyboard's focus on a
 * plate, the whole-building control and the ride to the screen's camera acts — none of which renders.
 * A ride's click moves the cab — the page's `cab`, set to the stop `ride()` names — glides the camera to
 * the plate (or cuts, under `prefers-reduced-motion`) and then ARRIVES: the page goes to the route the
 * screen handed back, `/floor/{key}`, which the floor page serves on a cold start. The click commits
 * the ride: an interrupted glide cuts to the plate and arrives, the control is disabled until the
 * glide has arrived, and a plate link clicked meanwhile does not navigate. The glide is the viewer's,
 * and it steps through `wire/camera-view.js`; the wheel and the drag are `wire/camera-gestures.js`'s
 * and the keys and the zoom buttons `wire/camera-keys.js`'s — each the floor page's own.
 */

import { livePage } from '../wire/live-page.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';
import { cameraKeys, offerKeys } from '../wire/camera-keys.js';
import { framesNothing } from '../wire/camera.js';
import { startLobbyScreen } from './lobby-screen.js';
import { labelMax, labelScale, surfaceStyle } from './building-scene.js';
import { buildingDrawing, keepDrawing, paintBuilding } from './building-paint.js';
import { resolveCab } from './cab-position.js';
import { plateRow } from './plate-row.js';
import { holdPlateLinks } from './ride-hold.js';

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
 * ⛔ THE CAMERA IS THE ONLY THING THAT MOVES THE VIEW. While it draws a building `#lobby-building` clips
 * the plates (`overflow: hidden`, `building-scene.js`'s `surfaceStyle()`), and a clipping element can
 * still be SCROLLED — focus moving to a plate outside the view scrolls it into view — which slides the plates out from under the camera: the transform
 * says one place and the pixels show another. So its scroll is held at the origin, on every camera
 * shown and on every scroll the browser makes — and what the browser's scroll-into-view was FOR, a
 * focused plate the viewer can see, is the camera's instead: the keyboard's focus on a plate outside
 * the view brings the camera to it (the `focusin` below, the screen's `focusPlate()`). With no building
 * drawn the surface clips nothing and scrolls nothing — the list flows in the page — so `view()` holds
 * the scroll only while a camera is framed.
 */
function unscroll() {
    const node = el('lobby-building');

    node.scrollTop = 0;
    node.scrollLeft = 0;
}

/**
 * The building's zoom buttons — handed to `wire/camera-keys.js` to wire — and its framing control, the
 * whole-building control: all three offered by `offerKeys()` on every camera shown (card#7343 c7692).
 */
const zoomButtons = { zoomIn: el('lobby-zoom-in'), zoomOut: el('lobby-zoom-out'), fit: el('lobby-whole-building') };

/**
 * One camera on the plates: the scene point at the camera's `x`, `y` at the surface's top-left, at its
 * zoom — `wire/camera.js`'s `view`, as a CSS transform. Nothing framed is no transform at all: the list
 * as it flows, which is how a lobby with no plate reads (§ 9 F17's rooms, or no install).
 *
 * ⛔ THE SAME CAMERA SETS THE PLATES' TEXT's COUNTER-SCALE (`building-scene.js`'s `labelScale()`, the
 * operator's rulings), as `--label-scale` on the plates, which every plate label's own transform reads
 * (`plate-row.js`). One camera, one write: a plate's name and status line are moved by the camera and
 * never scaled by it, at fit, after a wheel, a key or a drag, on every step of a glide and after a
 * resize, because each of them is shown through this function.
 *
 * ⛔ AND WHAT IS VISIBLE IS THE LABELS' WIDTH (card#7343 r4b, the seat's ruling, refining r3's surface
 * width): `--label-max`, the width each plate label wraps within (`plate-row.js`), is `building-scene.js`'s
 * `labelMax()` of the same camera — the surface to the right of the plates' on-screen left edge, never
 * wider than the surface and never narrower than its `LABEL_MIN_PX`. A label's px are screen px under its
 * counter-scale, so at whole-building fit a label reads to its end without a pan wherever at least that
 * minimum is visible beside the plates, and a label no wider than the surface is one a pan can always
 * bring wholly into view.
 *
 * ⛔ AND THE CAMERA IS OFFERED ONLY WHILE IT FRAMES SOMETHING (card#7343 r4b, the seat's ruling, and
 * comment 7692): the keys, the zoom buttons, the whole-building control and the building's tab stop —
 * `wire/camera-keys.js`'s `offerKeys()`, from the screen's camera, which every gate reads, and never a
 * glide's step towards it: a building that stops framing mid-glide withdraws them at once.
 */
function view(camera) {
    const framed = !framesNothing(camera);

    if (framed) {
        unscroll();
    }

    const floors = el('lobby-floors');

    floors.style.transform = framed
        ? `scale(${camera.zoom}) translate(${-camera.x}px, ${-camera.y}px)`
        : '';
    floors.style.setProperty('--label-scale', String(labelScale(camera)));
    floors.style.setProperty('--label-max', `${labelMax(camera)}px`);
    offerKeys(el('lobby-building'), zoomButtons, screen.camera());
}

const { show, glideTo, current } = cameraView(view);

/**
 * ⭐ THE BUILDING's DRAWING — Appendix B row 16, slice B (card#7343): the roof and its sign, each plate as a
 * storey of the reference's section, the ground lobby, and the cab in its shaft, all `building-scene.js`'s
 * (`buildingArt()`, `CAB`, `cabStyle()`) and painted here deciding nothing, as `floor/painter.js` paints the
 * floor's scene. One `<svg>` in the first row of `#lobby-floors`, so the camera's one transform moves and
 * scales it with the plates; the plates' rows stand after it, so each plate's label reads over its storey
 * and is never scaled by it (`plate-row.js`). Scenery carrying no fact: hidden from assistive technology,
 * never a pointer's target, and painted from the scene's rects alone.
 *
 * ⛔ IT IS KEPT ACROSS RENDERS — `renderBuilding()` replaces every other row and never this one
 * (`building-paint.js`'s `keepDrawing()`) — because the cab GLIDES: a ride sets `cabGlide` to its
 * `glide_ms` before it draws the cab at its next stop, and a CSS transition carries the cab there over
 * the ride's glide, which a row rebuilt on the render would cut. Arriving sets it back to `0`, so every
 * other render — a snapshot, a delta, a re-seated cab — cuts the cab to where it stands, and under
 * `prefers-reduced-motion` the ride's glide is `0` and the cab cuts too. The ride is navigation (§ 4.6's
 * elevator row): the glide writes no animation-log row and starts nothing through the set, and the lobby
 * loads no module that could. The construction, the keeping and the painting are `building-paint.js`'s —
 * DOM operations extracted so a node probe can drive them (`Tests\Feature\Lobby\TheBuildingDrawingKeepsItsElementTest`).
 */
const { drawing, art, scenery, cabNode } = buildingDrawing(document);

/** How long the cab may take to its stop on the next render — a ride's `glide_ms` while it is in flight, else `0`. */
let cabGlide = 0;

/** The building box the scenery was last painted for, so a render that changed no box repaints none. */
let paintedBox = null;

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

    // Every row but the building's drawing, which stays so the cab can glide (see `drawing`).
    keepDrawing(rows, drawing);

    // The surface is a drawing that clips only while there is a building to draw; with none — § 9 F17's
    // cold start, or no install — it is no box at all and the list flows in the page (card#7343 r3).
    Object.assign(el('lobby-building').style, surfaceStyle(scene));
    // The plates stand where the scene puts them (Appendix B row 16); with none, the list flows.
    rows.style.position = scene?.extent ? 'relative' : '';
    rows.style.width = scene?.extent ? `${scene.extent.w}px` : '';
    rows.style.height = scene?.extent ? `${scene.extent.h}px` : '';
    // The roof, the storeys, the ground lobby and the cab at the viewer's stop — nothing with no building.
    paintedBox = paintBuilding(document, { drawing, art, scenery, cabNode }, scene, building.elevator.level, cabGlide, paintedBox);

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

    // Each plate stands at the rect the scene gives it (`level` indexes the stack and the scene alike),
    // its name and its status line one label over it at the page's body text size (`plate-row.js`).
    for (const plate of building.plates) {
        rows.append(plateRow(document, plate, scene.plates[plate.level].rect, plate.floor === building.elevator.at));
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
    // and the next render is an ordinary one — UNLESS a ride is in flight, whose cab is the viewer's own
    // act and not a fact this render may undo (`cab-position.js`'s `resolveCab()`, impl review r1 finding
    // 2): a render whose fetch landed after the click, carrying the model's pre-ride facts, must never
    // glide the cab backwards to the floor the ride just left. An uncomposed lobby (§ 9 F17) resolved
    // nothing either way.
    cab = resolveCab(cab, building, frame.riding);

    renderBuilding(building, frame.scene, summary.unclaimed, frame.riding);
    // The render may just have made the surface a drawing, or stopped it being one (`surfaceStyle()`), so
    // the camera is sized to the surface as it now stands — a camera still at fit stays at fit.
    const size = surface();
    const camera = size.width === frame.camera.surface.width && size.height === frame.camera.surface.height
        ? frame.camera
        : screen.resize(size);

    // A render never moves the viewer: a glide in flight keeps its step, and otherwise the plates show
    // the screen's camera, which the render left where the viewer put it.
    view(current(camera));

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
 * ⛔ THE CLICK COMMITS THE RIDE, AND THE HOLD PROTECTS THE GLIDE (card#7343 r1 ruling; r2-4). The glide
 * is `camera-view.js`'s COMMITTED glide: a wheel, a key, a zoom button, a drag, a resize or the
 * whole-building control during it cuts it to the plate and arrives, and the ride control is disabled
 * from the click until the glide has arrived. A plate link clicked during it — by the pointer, or by the
 * keyboard's Enter, which is the same `click` — does not navigate: the committed ride wins
 * (`ride-hold.js`, below; card#7343 r3b). The keyboard's focus on a plate during it leaves the glide
 * running (the screen's `focusPlate()` moves nothing while a ride is in flight). Arriving asks for the
 * route and then ends the hold (`returned()`) and re-draws — so a navigation the browser cancels, or one
 * that never completes, leaves a lobby whose controls work.
 */
el('lobby-elevator').addEventListener('click', () => {
    const ride = screen.ride();

    if (ride === null) {
        return;
    }

    cab = ride.cab;
    // The cab glides to its stop with the camera — over the ride's glide, which is none under reduced motion.
    cabGlide = ride.glide_ms;
    screen.draw(cab);
    glideTo(ride.from, ride.to, ride.glide_ms, () => {
        cabGlide = 0;
        window.location.assign(ride.route);
        screen.returned();
        screen.draw(cab);
    }, { commit: true });
});

// Back to a lobby the browser kept whole (the back-forward cache): the ride that left it has arrived,
// so the viewer may ride again — ended already on arrival, and ended here too for a page kept at any
// other moment.
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

// The wheel zooms about the cursor in proportion to its scroll, and a drag with the primary button pans,
// wired by `wire/camera-gestures.js`; the keys zoom about the centre and pan by a step, and so do the
// zoom buttons, wired by `wire/camera-keys.js` — row 15's acts at building scale, each module the
// floor's too. None renders; each shows the camera the screen hands back — and each leaves its event to
// the browser while the camera frames nothing, the uncomposed list flowing in the page (card#7343 r4b).
// A drag that moved is no click on the plate — the link — it ended over.
const building = el('lobby-building');

cameraGestures(building, { wheel: screen.wheel, drag: screen.drag, camera: screen.camera }, show);
// The committed ride wins (card#7343 r3b): a plate link clicked while a ride is in flight does not navigate.
holdPlateLinks(building, screen.riding);
cameraKeys(building, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);
// Focus can scroll the clipping surface; the camera alone moves the view (`unscroll()`).
building.addEventListener('scroll', unscroll);
// Focus-into-view (card#7343 r2-2): the keyboard's focus on a plate outside the view brings the camera
// to it — the screen's `focusPlate()`, which leaves a plate already in view where it is. Only the
// keyboard's focus: a press on a plate focuses its link too, and a press is a click or a pan, never a
// request to move the camera to the plate (`:focus-visible` is the browser's own line between them).
building.addEventListener('focusin', (event) => {
    const row = event.target.closest('li[data-floor]');

    if (row === null || !event.target.matches(':focus-visible')) {
        return;
    }

    const focus = screen.focusPlate(row.dataset.floor);

    if (focus !== null) {
        glideTo(focus.from, focus.to, focus.glide_ms);
    }
});
// A resize re-shows the screen's camera at once, stopping a glide in flight over the old surface — or
// finishing a ride's, which then arrives.
window.addEventListener('resize', () => {
    show(screen.resize(surface()));
});

// § 4.4: the stream first, then the snapshot (§ 2.2) — and the layout after it, on the first render
// that finds the snapshot applied.
client.start();
requestRender();
