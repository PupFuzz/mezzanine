/**
 * THE FLOOR PAGE's DOM ENTRY — `docs/design/FLOOR.md` Appendix B row 8, § 4.4's `/floor/{floor}`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every fact on the page is `floor/floor-screen.js`'s frame — the
 * floor, its desks, the room render, the coordination line, the status strip
 * (`floor/status-strip.js`) and the failure renders (`wire/failure-render.js`) — and every one of
 * those is exercised headlessly under `node`. There is no browser on the build host, so nothing here
 * has been laid out, painted or clicked; what IS checked is that every element id it addresses
 * exists on `resources/views/floor.blade.php` and the reverse (`FloorPageWiringTest`). If a rule
 * appears below that is not in one of those modules, it is in the wrong file.
 *
 * ⛔ THE CLIENT PROTOCOL IS CONSTRUCTED WITH ITS SCHEDULER, AND ONLY WITH IT (Appendix B row 8's
 * ⛔) — by `wire/live-page.js`, which the lobby shares since card#7341 step 9. A real `EventSource`
 * held without the stream recovery inherits the browser's own reconnect, which re-runs none of
 * § 2.2's steps 1–5; the wiring test reds if this page stops constructing it there.
 *
 * ⛔ THE TWO BOUNDS A PAGE NEEDS AND THE HARNESS MUST NOT HAVE ARE APPLIED FOR IT: the animation log
 * is `wire/live-page.js`'s, constructed with § 12's retention (`ANIMATION_LOG_RETENTION`, § 14 item
 * 26), and the protocol holds its coordination envelopes to § 5.7's cap on its own (§ 14 item 25).
 *
 * ⛔ A RENDER FOLLOWS EVERY THING THAT CAN CHANGE WHAT THE PROTOCOL HOLDS, coalesced into one
 * `screen.render()` at a time (`wire/live-page.js` says why). The 1 s age tick re-draws the desks'
 * ages from the last frame and drains nothing (§ 2.5).
 *
 * ⛔ THE ROOM IS DRAWN BY `floor/painter.js` FROM THE FRAME's `scene` (Appendix B row 14). This file
 * imports the two art modules through the painter (the asset route's URLs), hands the screen the
 * scene's inputs once they answer, and paints each frame's scene; the painter reports every asset it
 * could not draw back to the screen, which is § 9 F14's placeholder and the strip's `art` line on the
 * next render. Below the drawing each desk is also its row of the LIST VIEW (Appendix B row 15, slice A),
 * the desks as text beside the drawing at every size: `desk/desk-list.js`'s `deskListRow()` decides
 * every line from the desk model and this file paints them; row 8's page-side `deskLine()` is gone.
 *
 * ⛔ THE CAMERA IS THE SCREEN's (Appendix B row 15, § 4.5); this file supplies
 * the drawing surface's size — the drawing element's own box, re-read whenever it changes — wires the wheel, the drag and the pinch (through
 * `wire/camera-gestures.js`) and the keyboard and the zoom buttons (through `wire/camera-keys.js`),
 * both of which the lobby shares, and the fit-floor control to the screen's camera acts, and sets the drawing's view from the camera
 * each returns — a camera act renders nothing. The drawing is shown at every window size — there is no
 * minimum and no substitute view (§ 4.5, the operator's ruling of 2026-10-01 on card#7341): a window
 * smaller than the room is one the viewer pans and zooms across, and the desk list — `paintDesks()`,
 * over `deskListRow()` — is painted below it at every size too. The whole-building control is a link to
 * `/` (§ 4.4's lobby route), never a second scale drawn here.
 * A glide is the page's alone — the camera's state is already its destination — and under
 * `prefers-reduced-motion` the screen hands back no glide at all, so the view cuts. How a glide steps
 * is `wire/camera-view.js`'s, which the lobby's glide shares (Appendix B row 16).
 *
 * ⛔ THE DRILL-DOWN IS OPENED FROM A DESK AND CLOSED TO THE FLOOR WITHOUT LEAVING THE PAGE (§ 4, § 4.3;
 * Appendix B row 10). Selecting a desk pushes `/floor/{floor}/{seat_id}` (§ 4.4) into the browser's
 * history and asks the screen to open the panel; the URL is the screen's to decide (`frame.seat`) and
 * this file's to perform, as the floor segment's redirect already is — a panel a retirement closed
 * takes the seat segment off the URL with it. The panel's strings are `drilldown/main.js`'s slots over
 * the model the frame carries; nothing is fetched here, and no second stream is opened.
 */

import { livePage } from '../wire/live-page.js';
import { startAgeTicker } from '../wire/age-readout.js';
import { startFloorScreen } from './floor-screen.js';
import { renderDrillDown } from '../drilldown/main.js';
import { createPainter, loadArt, measurer } from './painter.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';
import { cameraKeys, offerKeys } from '../wire/camera-keys.js';
import { deskListRow } from '../desk/desk-list.js';

/** A missing element throws rather than being guarded past — the lobby's rule, for its reason. */
function el(id) {
    const node = document.getElementById(id);

    if (node === null) {
        throw new Error(`the floor page has no #${id}`);
    }

    return node;
}

/** Text into an element, hidden when there is none. */
function say(id, text) {
    const node = el(id);

    node.textContent = text ?? '';
    node.hidden = text === null || text === undefined || text === '';
}

/** One `<li>` per line, each line text a model has already decided. */
function items(lines) {
    return lines.map((line) => {
        const item = document.createElement('li');

        item.textContent = line;

        return item;
    });
}

/** A list element rebuilt from lines the model has already decided the text of. */
function list(id, lines) {
    const node = el(id);

    node.replaceChildren(...items(lines));
    node.hidden = lines.length === 0;
}

const root = el('floor');

/**
 * The drawing's zoom buttons — handed to `wire/camera-keys.js` to wire — its framing control, *Fit the
 * floor*, and the gesture hint under the drawing (card#11045): all four offered by `offerKeys()` on every
 * render (card#7343 comment 7692).
 */
const zoomButtons = { zoomIn: el('floor-zoom-in'), zoomOut: el('floor-zoom-out'), fit: el('floor-fit'), hint: el('floor-hint') };

let lastFrame = null;

/** `floor/painter.js`'s painter, once the art modules have answered (Appendix B row 14). */
let painter = null;

/**
 * The camera the drawing shows right now — the screen's, or a glide's step towards it (Appendix B row
 * 15) — shown through `wire/camera-view.js`: `show()` at once, `glideTo()` over a glide's length.
 */
const { show, glideTo, current } = cameraView((camera) => painter?.view(camera));

/**
 * The drawing surface: `#floor-drawing`'s own box, as the lobby reads `#lobby-building`'s. Its height is
 * the page chrome sheet's (`public/css/mezzanine.css`: the room takes the viewport height the chrome above
 * it leaves, which changes whenever a banner, a statement or a notice is shown), so it is read off the
 * element and never off the window — a surface the drawing is not is one the camera fits wrongly, and the
 * painter's `viewBox` then letterboxes the room off every pointer position (card#11045, design review r3
 * MAJOR-A).
 */
function surface() {
    const box = el('floor-drawing');

    return { width: box.clientWidth, height: box.clientHeight };
}

const { client, clock, fetch: pageFetch, requestRender, log } = livePage(() => screen.render());

/** § 4.4's two URL shapes for this page, the floor segment first. */
function routeOf(floor, seat) {
    return seat === null
        ? `/floor/${encodeURIComponent(floor)}`
        : `/floor/${encodeURIComponent(floor)}/${encodeURIComponent(seat)}`;
}

/** The floor segment the URL carries now — the route's own, until a redirect replaces the page. */
function floorSegment() {
    return root.dataset.floor;
}

/** The seat segment the URL carries now, or `null` on the floor alone. */
function seatSegment() {
    const parts = window.location.pathname.split('/').filter((part) => part !== '');

    return parts.length >= 3 ? decodeURIComponent(parts[2]) : null;
}

/** § 4.3's "opened by selecting a desk" — from the drawing or the list, one path. */
function openDesk(installId, seatId) {
    window.history.pushState(null, '', routeOf(floorSegment(), seatId));
    screen.openPanel(installId, seatId).then(requestRender);
    requestRender();
}

/**
 * The desks, each its list-view row (Appendix B row 15): the row's first line — the nameplate's — is a
 * link to the desk's own drill-down (§ 4.3: "opened by selecting a desk"), and the rest of its lines
 * follow it. The link is a real URL (`/floor/{floor}/{seat_id}`, § 4.4) so it can be opened, copied or
 * bookmarked; a plain click opens the panel in place.
 */
function paintDesks(frame, desks) {
    const node = el('floor-desks');

    node.replaceChildren(...Object.values(desks).map((desk) => {
        const [first, ...rest] = deskListRow(desk);
        const item = document.createElement('li');
        const link = document.createElement('a');

        link.href = routeOf(floorSegment(), desk.seat_id);
        link.textContent = first;
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openDesk(desk.install_id, desk.seat_id);
        });
        item.append(link);

        if (rest.length > 0) {
            const lines = document.createElement('ul');

            lines.replaceChildren(...items(rest));
            item.append(lines);
        }

        return item;
    }));
    node.hidden = Object.keys(desks).length === 0;
    node.dataset.dimmed = String(frame.failure.sign_in !== null);
}

/** The open drill-down, or none — `drilldown/main.js` fills the slots from the frame's model. */
function paintPanel(panel) {
    const node = el('floor-panel');

    node.hidden = panel === null;

    if (panel !== null) {
        renderDrillDown(node, panel);
        el('floor-panel-retry').hidden = !panel.can_retry;
        el('floor-panel-more').hidden = panel.activity.next_before === null;
    }
}

function paint(frame) {
    lastFrame = frame;

    // § 4.4 row 3: the redirect is decided by the screen and performed here — on the FLOOR segment,
    // with the seat segment untouched ("a published drill-down link resolves for the same reason a
    // published floor link does").
    if (frame.redirect !== null) {
        window.location.replace(routeOf(frame.redirect, frame.seat));

        return;
    }

    // The seat segment follows the screen's: a panel a retirement closed takes it off the URL.
    const route = routeOf(floorSegment(), frame.seat);

    if (window.location.pathname !== route) {
        window.history.replaceState(null, '', route);
    }

    const strip = frame.strip;

    say('floor-feed', `feed: ${strip.feed}`);
    say('floor-connection', `stream: ${strip.connection}`);
    say('floor-resyncs', strip.resyncs);
    say('floor-fleet-counts', `building: ${strip.totals}`);
    say('floor-last-message', strip.last_message);
    // § 9 F14: the strip's own line when art the room drawing asked for did not load.
    say('floor-art', strip.art);

    for (const indicator of strip.indicators) {
        say(`floor-${indicator.key}`, indicator.detail === null
            ? `${indicator.label}: ${indicator.value}`
            : `${indicator.label}: ${indicator.value} · ${indicator.detail}`);
    }

    const failure = frame.failure;

    say('floor-statement', failure.statement);
    say('floor-kept', failure.kept);
    say('floor-banner', failure.banner);
    say('floor-layout-statement', frame.statement);
    el('floor-signin').hidden = failure.sign_in === null;
    say('floor-signin-prompt', failure.sign_in?.prompt ?? null);
    say('floor-not-live', failure.sign_in?.label ?? null);

    // § 4.6's one rendering rule, `label ?? key`, is the screen's; before a floor is composed the
    // heading names the segment the route was entered on.
    el('floor-name').textContent = frame.floor === null ? `The floor ${root.dataset.floor}` : frame.floor.name;

    // § 6.5: a room with no value is drawn as having none — "unset", never a plausible time.
    const room = frame.room;
    const face = el('floor-clock');

    face.textContent = room === null ? 'clock not set — no live feed yet' : `${room.text} (${room.label})`;
    face.setAttribute('aria-label', room === null ? 'clock not set' : room.text);
    say('floor-sky', room === null ? 'sky not set' : `sky: ${room.sky}`);

    list('floor-notices', [...frame.notices]);
    list('floor-rooms', frame.composed ? [] : frame.rooms.map((r) => `${r.install_id} — no floor claimed`));
    list('floor-overflow', [...(frame.overflow ?? [])]);
    list('floor-coord', Object.values(frame.coord ?? {}).flatMap((model) => model.threads.map((thread) => (
        `${thread.thread_ref} — ${thread.lifecycle} — ${thread.beads_label} posts`
        + (thread.unresolved.length > 0 ? ` — unresolved: ${thread.unresolved.join(', ')}` : '')
    ))));

    // § 9 F6/F7: the floor beneath the sign-in prompt is dimmed, never blanked — the drawing as the list.
    el('floor-drawing').dataset.dimmed = String(frame.failure.sign_in !== null);
    el('floor-camera').hidden = frame.scene === null;
    // The camera is offered only while it frames the floor (card#7343 r4b, comment 7692): with nothing
    // framed its controls do nothing, so the zoom buttons and *Fit the floor* are hidden and the drawing is
    // no tab stop and names no keys.
    offerKeys(el('floor-drawing'), zoomButtons, frame.camera);

    // § 4.5: the room is drawn at every window size. A render never moves the viewer: a glide in flight
    // keeps its step, and otherwise the drawing shows the screen's camera, which the render left where
    // the viewer put it.
    painter?.paint(frame.scene ?? null, current(frame.camera));

    paintDesks(frame, frame.desks.desks);
    paintPanel(frame.panel);
    list('floor-log', client.eventLog);
}

const screen = startFloorScreen(client, pageFetch, clock, log, paint, {
    floor: root.dataset.floor,
    seat: root.dataset.seat === '' ? null : root.dataset.seat,
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    surface: surface(),
    // § 6.2's walk note item 4: a walk's last frame is a paint-only refresh on the browser's timer — it
    // reaches `paint` and never `screen.render()`, so it drains nothing and writes no animation-log row.
    timers: { after: (ms, fire) => window.setTimeout(fire, ms), cancel: (id) => window.clearTimeout(id) },
});

// § 2.5's 1 s tick: the ages over the desks the last render read — and the open panel's — with
// nothing drained.
startAgeTicker(screen.desks, clock, window, (readouts) => {
    if (lastFrame !== null) {
        const desks = screen.desks.view(readouts);

        painter?.refresh(screen.sceneView(desks));
        paintDesks(lastFrame, desks.desks);

        paintPanel(screen.panelView(lastFrame.floor?.name ?? null));
    }
});

// Appendix B row 14: the art modules, by the asset route. The screen draws no scene until they have
// answered; a module that failed is reported as the failed asset it is (§ 9 F14).
loadArt().then(({ furniture, characters, themes, failed }) => {
    painter = createPainter({
        characters,
        failed: (ids) => {
            screen.assetsFailed(ids);
            requestRender();
        },
        select: openDesk,
    });

    if (furniture !== null) {
        screen.sceneInputs({
            box: furniture.FURNITURE_BOX,
            desk_sprite: furniture.DESK_SPRITE,
            measure: measurer(),
            character: { w: characters?.SCENE_W ?? 0, h: characters?.SCENE_H ?? 0 },
            // FLOOR.md § 10.6 item 5: the registry each floor's `theme` is resolved against (§ 9 F23);
            // a registry that failed to load is § 9 F14's, and resolves nothing.
            themes: themes === null ? null : { names: themes.THEMES, house: themes.HOUSE_THEME },
        });
    }

    screen.assetsFailed(failed);
    requestRender();
});

// § 4.3: the panel's user actions. Each asks for a render once its requests have answered.
el('floor-panel-close').addEventListener('click', () => {
    window.history.pushState(null, '', routeOf(floorSegment(), null));
    screen.closePanel();
    requestRender();
});
el('floor-panel-retry').addEventListener('click', () => {
    screen.retryPanel().then(requestRender);
});
el('floor-panel-more').addEventListener('click', () => {
    screen.morePanel().then(requestRender);
});

// Appendix B row 15: the viewer's camera (§ 4.5, the operator's ruling of 2026-10-01 on card#11045). A
// plain wheel or a trackpad's two-finger scroll pans; a Ctrl+wheel or a trackpad's pinch (a wheel event
// with `ctrlKey`, which the camera scales by its `PINCH_GAIN`) zooms about the cursor in proportion to its
// scroll; one finger or the primary button drags to pan, and two fingers pinch to zoom about their
// midpoint — all wired by `wire/camera-gestures.js`; the keyboard and the zoom buttons zoom about the drawing's centre and pan
// by a step, wired by `wire/camera-keys.js` — both modules the lobby's too. None renders — each sets
// the drawing's view from the camera the screen hands back, and each leaves its event to the browser
// while the camera frames nothing (card#7343 r4b).
const drawing = el('floor-drawing');

cameraGestures(drawing, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);
cameraKeys(drawing, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);
el('floor-fit').addEventListener('click', () => {
    const { from, to, glide_ms: ms } = screen.fitFloor();

    glideTo(from, to, ms);
});
// A resize of the DRAWING re-shows the screen's camera at once — stopping a glide in flight, whose every
// later step would otherwise be computed over the surface it started on — before the render it asks for.
// The drawing's box, not the window's: the chrome above it grows and shrinks with what the page has to
// say (a banner, a statement, a notice), and each of those resizes the drawing with no window resize at
// all (card#11045, design review r3 MAJOR-A).
new ResizeObserver(() => {
    screen.resize(surface());
    show(screen.camera());
    requestRender();
}).observe(drawing);

// Back and forward move the seat segment; the screen resolves it on the next render (§ 4.4).
window.addEventListener('popstate', () => {
    screen.routeSeat(seatSegment());
    requestRender();
});

// § 4.4: "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
client.start();
screen.enter().then(requestRender);
