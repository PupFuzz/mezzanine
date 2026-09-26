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
 * ⛔ THE TWO BOUNDS A PAGE NEEDS AND THE HARNESS MUST NOT HAVE ARE APPLIED HERE: the animation log is
 * constructed with § 12's retention (`ANIMATION_LOG_RETENTION`, § 14 item 26), and the protocol
 * holds its coordination envelopes to § 5.7's cap on its own (§ 14 item 25).
 *
 * ⛔ A RENDER FOLLOWS EVERY THING THAT CAN CHANGE WHAT THE PROTOCOL HOLDS, coalesced into one
 * `screen.render()` at a time (`wire/live-page.js` says why). The 1 s age tick re-draws the desks'
 * ages from the last frame and drains nothing (§ 2.5).
 *
 * ⛔ THE ROOM IS DRAWN BY `floor/painter.js` FROM THE FRAME's `scene` (Appendix B row 14). This file
 * imports the two art modules through the painter (the asset route's URLs), hands the screen the
 * scene's inputs once they answer, and paints each frame's scene; the painter reports every asset it
 * could not draw back to the screen, which is § 9 F14's placeholder and the strip's `art` line on the
 * next render.
 *
 * ⛔ THE CAPABILITY FLOOR AND THE CAMERA ARE THE SCREEN's (Appendix B row 15, § 4.5); this file supplies
 * the viewport and the drawing surface's size, wires the wheel, the drag and the fit-floor control to
 * the screen's camera acts, and sets the drawing's view from the camera each returns — a camera act
 * renders nothing. Below § 12's viewport floor the frame is the list view and this file paints the desk
 * list and hides the drawing; at or above it, the drawing under the camera and no list. The
 * whole-building control is a link to `/` (§ 4.4's lobby route), never a second scale drawn here.
 * A glide is the page's alone — the camera's state is already its destination — and under
 * `prefers-reduced-motion` the screen hands back no glide at all, so the view cuts.
 *
 * ⛔ THE DRILL-DOWN IS OPENED FROM A DESK AND CLOSED TO THE FLOOR WITHOUT LEAVING THE PAGE (§ 4, § 4.3;
 * Appendix B row 10). Selecting a desk pushes `/floor/{floor}/{seat_id}` (§ 4.4) into the browser's
 * history and asks the screen to open the panel; the URL is the screen's to decide (`frame.seat`) and
 * this file's to perform, as the floor segment's redirect already is — a panel a retirement closed
 * takes the seat segment off the URL with it. The panel's strings are `drilldown/main.js`'s slots over
 * the model the frame carries; nothing is fetched here, and no second stream is opened.
 */

import { livePage } from '../wire/live-page.js';
import { createAnimationLog } from '../wire/animation-log.js';
import { startAgeTicker } from '../wire/age-readout.js';
import { startFloorScreen } from './floor-screen.js';
import { renderDrillDown } from '../drilldown/main.js';
import { createPainter, loadArt, measurer } from './painter.js';
import { between } from '../wire/camera.js';

/** § 12's *The floor page's animation-log retention* — the page's bound, and no one else's. */
const ANIMATION_LOG_RETENTION = 2000;

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
 * One desk's line, from the desk model's own strings — nothing composed here but the joins. Row 8's
 * text render, and the list view's until Appendix B row 15's list module replaces it at its ONE call
 * in `paintDesks()`.
 */
function deskLine(desk) {
    return [
        desk.nameplate,
        desk.glyph,
        desk.label_line,
        desk.quiet_age,
        desk.badges.length > 0 ? `badges: ${desk.badges.join(', ')}` : null,
        desk.unrecognised.length > 0 ? `unrecognised: ${desk.unrecognised.join(', ')}` : null,
    ].filter((part) => part !== null && part !== '').join(' — ');
}

const root = el('floor');

let lastFrame = null;

/** `floor/painter.js`'s painter, once the art modules have answered (Appendix B row 14). */
let painter = null;

/**
 * The camera the drawing shows right now — the screen's, or a glide's step towards it — and the
 * glide's frame request, if one is running (Appendix B row 15).
 */
let shown = null;
let glide = null;

/** The viewer's viewport in CSS px — what § 4.5's capability floor reads. */
function viewport() {
    return { width: window.innerWidth, height: window.innerHeight };
}

/** The drawing surface: the floor section's width, the viewport's height (the view's stylesheet). */
function surface() {
    return { width: root.clientWidth, height: window.innerHeight };
}

/** Show a camera on the drawing, stopping any glide in flight. */
function show(camera) {
    if (glide !== null) {
        cancelAnimationFrame(glide);
        glide = null;
    }

    shown = camera;
    painter?.view(camera);
}

/** The fit-floor control's move: a glide of the given length to `to`, or a cut when it is none. */
function glideTo(from, to, ms) {
    show(from);

    if (ms === 0) {
        show(to);

        return;
    }

    const start = performance.now();
    const step = (now) => {
        const t = Math.min(1, (now - start) / ms);

        shown = between(from, to, t);
        painter?.view(shown);
        glide = t < 1 ? requestAnimationFrame(step) : null;
    };

    glide = requestAnimationFrame(step);
}

const { client, clock, fetch: pageFetch, requestRender } = livePage(() => screen.render());

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
 * The desks, each a link to its own drill-down (§ 4.3: "opened by selecting a desk"). The link is a
 * real URL (`/floor/{floor}/{seat_id}`, § 4.4) so it can be opened, copied or bookmarked; a plain
 * click opens the panel in place.
 */
function paintDesks(frame, desks) {
    const node = el('floor-desks');

    node.replaceChildren(...Object.values(desks).map((desk) => {
        const item = document.createElement('li');
        const link = document.createElement('a');

        link.href = routeOf(floorSegment(), desk.seat_id);
        // ⛔ THE LIST VIEW's ONE CALL (Appendix B row 15): the text of a desk's row comes from here and
        // nowhere else, so the list module row 15 builds beside `desk/desk-render.js` replaces it on
        // this line.
        link.textContent = deskLine(desk);
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openDesk(desk.install_id, desk.seat_id);
        });
        item.append(link);

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

    // § 4.5's capability floor, decided by the screen: the drawing under the camera, or the list view.
    const drawn = frame.capability === 'floor';

    el('floor-drawing').hidden = !drawn;
    // § 9 F6/F7: the floor beneath the sign-in prompt is dimmed, never blanked — the drawing as the list.
    el('floor-drawing').dataset.dimmed = String(frame.failure.sign_in !== null);
    el('floor-camera').hidden = !drawn || frame.scene === null;
    el('floor-desks-heading').hidden = drawn;

    if (drawn) {
        // A render never moves the viewer: a glide in flight keeps its step, and otherwise the drawing
        // shows the screen's camera, which the render left where the viewer put it.
        shown = glide === null ? frame.camera : shown;
        painter?.paint(frame.scene ?? null, shown);
    } else {
        show(frame.camera);
        painter?.paint(null, frame.camera);
    }

    paintDesks(frame, drawn ? {} : frame.desks.desks);
    paintPanel(frame.panel);
    list('floor-log', client.eventLog);
}

const screen = startFloorScreen(client, pageFetch, clock, createAnimationLog(ANIMATION_LOG_RETENTION), paint, {
    floor: root.dataset.floor,
    seat: root.dataset.seat === '' ? null : root.dataset.seat,
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    viewport: viewport(),
    surface: surface(),
});

// § 2.5's 1 s tick: the ages over the desks the last render read — and the open panel's — with
// nothing drained.
startAgeTicker(screen.desks, clock, window, (readouts) => {
    if (lastFrame !== null) {
        const desks = screen.desks.view(readouts);

        if (lastFrame.capability === 'floor') {
            painter?.refresh(screen.sceneView(desks));
        } else {
            paintDesks(lastFrame, desks.desks);
        }

        paintPanel(screen.panelView(lastFrame.floor?.name ?? null));
    }
});

// Appendix B row 14: the art modules, by the asset route. The screen draws no scene until they have
// answered; a module that failed is reported as the failed asset it is (§ 9 F14).
loadArt().then(({ furniture, characters, failed }) => {
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

// Appendix B row 15: the viewer's camera. The wheel zooms about the cursor and a drag pans; neither
// renders — each sets the drawing's view from the camera the screen hands back.
const drawing = el('floor-drawing');
let drag = null;
let dragged = false;

drawing.addEventListener('wheel', (event) => {
    event.preventDefault();

    const r = drawing.getBoundingClientRect();

    show(screen.wheel({ x: event.clientX - r.left, y: event.clientY - r.top }, event.deltaY));
}, { passive: false });
drawing.addEventListener('pointerdown', (event) => {
    dragged = false;
    drag = { x: event.clientX, y: event.clientY, moved: false };
});
drawing.addEventListener('pointermove', (event) => {
    if (drag === null) {
        return;
    }

    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;

    if (!drag.moved && Math.hypot(dx, dy) < 4) {
        return;
    }

    if (!drag.moved) {
        drag.moved = true;
        drawing.setPointerCapture(event.pointerId);
    }

    drag.x = event.clientX;
    drag.y = event.clientY;
    show(screen.drag(dx, dy));
});
drawing.addEventListener('pointerup', () => {
    dragged = drag?.moved === true;
    drag = null;
});
// A drag that moved is not a click on the desk it ended over.
drawing.addEventListener('click', (event) => {
    if (dragged) {
        event.stopPropagation();
        dragged = false;
    }
}, { capture: true });
el('floor-fit').addEventListener('click', () => {
    const { from, to, glide_ms: ms } = screen.fitFloor();

    glideTo(from, to, ms);
});
window.addEventListener('resize', () => {
    screen.resize(viewport(), surface());
    requestRender();
});

// Back and forward move the seat segment; the screen resolves it on the next render (§ 4.4).
window.addEventListener('popstate', () => {
    screen.routeSeat(seatSegment());
    requestRender();
});

// § 4.4: "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
client.start();
screen.enter().then(requestRender);
