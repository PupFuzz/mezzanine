/**
 * The lobby, wired to the page — `docs/design/FLOOR.md § 4.1`, § 4.4's `/` row, § 4.5's ride, Appendix B rows 9 and 24.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every string it writes comes from `lobby-screen.js`'s frame — built over
 * `lobby-model.js`, `building-model.js`, `../floor/status-strip.js`, `../wire/failure-render.js` and `../wire/ride.js`
 * — and every one of those is exercised headlessly under `node`. What IS checked of this layer is that every element
 * id it addresses exists on the page and the reverse, and how it wires the protocol, the camera and the ride
 * (`LobbyPageWiringTest`); the room's paint and the directory's are `building-paint.js`'s and `directory.js`'s, which
 * probes drive on a stand-in DOM.
 *
 * ⛔ THE LOBBY RUNS THE CLIENT PROTOCOL (card#7341 step 9). `wire/live-page.js` constructs it with its scheduler — the
 * floor page's own construction, shared — so this page opens the stream, reads the snapshot through the protocol and
 * renders the population the protocol holds. § 4.1's discrepancy trigger is the protocol's alone.
 *
 * ⛔ NO ANIMATION BUT A17's SKY. § 6.5: "a snapshot never animates". The one § 6.2 row this page draws is A17's — the
 * room's one window (§ 4.1) — whose value the screen sets on a delivered `feed.heartbeat` and which this page paints as
 * a STEP through the page's bounded animation log (`wire/live-page.js`), which this page hands to the screen and uses
 * nowhere else. The elevator's leaves and the cab are the viewer's own ride (§ 6.3's exception), the camera's glides
 * the viewer's own head (§ 4.5): neither is a fact.
 *
 * ⭐ THE ROOM, THE DIRECTORY AND THE CAMERA (§ 4.1, § 4.5). `#lobby-building` is the drawing surface — a clipping
 * drawing only while the room is drawn (`building-scene.js`'s `surfaceStyle()`) — and `#lobby-room` the camera's layer
 * in it: the room's drawing (`building-paint.js`'s `roomDrawing()`), the directory's box at the board's rect and the
 * readings' box under it (`directory.js`'s `boxStyles()`), all moved and scaled by the camera's one transform. The
 * camera hook, `view()`, is the one place a camera is shown — fit, wheel, pinch, key, drag, every glide step, resize —
 * and it calls `directory.js`'s `showDirectory()`, the one writer of the directory's counter-scale, on every camera it
 * shows and never from a render. The wheel, the drag, the pinch, the keys, the zoom buttons and *Fit the lobby* are
 * the floor page's own wires (`wire/camera-gestures.js`, `wire/camera-keys.js`, `wire/camera-view.js`); the directory
 * declares that it scrolls (`data-camera-scrolls`), and the gesture wire gives it the wheel and the drag while it can
 * move their way.
 *
 * ⭐ THE ELEVATOR RIDES AS THE FLOOR's DOES (§ 4.5, card#12113). The drawn elevator is a control in the room
 * (`building-paint.js`), and its press is the screen's `ride()`; everything after it — each phase's entry, the ride's
 * one timer, the cab dialog (`#lobby-cab`, `partials/ride-cab.blade.php` under the `lobby` prefix), a stop's press,
 * the cab's Escape, and the departing page's one `pageswap` rule — is `wire/ride-page.js`'s, which the floor wires with
 * its own dialog. This file hands it the screen, its paint, `floor/cab-paint.js`'s `paintCab()`, the drawn elevator
 * and the last painted frame's ride, and calls its `perform()` last in every paint. The cab and the room's cab are in
 * the house theme's elevator palette once `loadHousePalette()` answers. The arrival is the layout head script's: the
 * screen is told before its first render, and the hold ends on the first frame that drew the room — `revealed()` is
 * called there and nowhere else.
 */

import { livePage } from '../wire/live-page.js';
import { startAgeTicker } from '../wire/age-readout.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';
import { cameraKeys, offerKeys } from '../wire/camera-keys.js';
import { framesNothing } from '../wire/camera.js';
import { arrival, revealed } from '../wire/ride-arrival.js';
import { ridePage } from '../wire/ride-page.js';
import { cabTheme, loadHousePalette, paintCab } from '../floor/cab-paint.js';
import { startLobbyScreen } from './lobby-screen.js';
import { lobbyScene, surfaceStyle } from './building-scene.js';
import { paintRoom, roomDrawing, themeRoomCab } from './building-paint.js';
import { boxStyles, renderDirectory, showDirectory } from './directory.js';

/**
 * ⛔ A MISSING ELEMENT THROWS RATHER THAN BEING GUARDED PAST. The guarded form — `if (node === null) return;` at every
 * site — IS the silent no-op that is this layer's whole risk: the page renders, the fetch runs, and one fact is written
 * nowhere at all. A throw leaves the page's own placeholders standing — each of which says it is WAITING, never a
 * zero and never a calm fleet — and puts the cause in the console; `LobbyPageWiringTest` makes it unreachable in the
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

/** The document element: the layout head script's record of a ride arrival, and the hold it ended (§ 4.5). */
const html = document.documentElement;

/** The browser's timers, as the ride's one timer uses them. */
const timers = { after: (ms, fire) => window.setTimeout(fire, ms), cancel: (id) => window.clearTimeout(id) };

const building = el('lobby-building');
const layer = el('lobby-room');
const directoryBox = el('lobby-directory');
const readingsBox = el('lobby-readings');
const scene = lobbyScene();

/** The room's drawing surface in CSS px — the element the room is drawn and the camera looks in. */
function surface() {
    return { width: building.clientWidth, height: building.clientHeight };
}

/**
 * ⛔ THE CAMERA IS THE ONLY THING THAT MOVES THE VIEW. While it draws the room `#lobby-building` clips the layer, and a
 * clipping element can still be SCROLLED — focus moving to a plaque's link scrolls it into view — which slides the room
 * out from under the camera. So its scroll is held at the origin on every camera shown and on every scroll the browser
 * makes, and what the browser's scroll-into-view was for, a focused control the viewer can see, is the camera's
 * instead (the `focusin` below). The directory's own list is its box's scroller and is the browser's to scroll.
 */
function unscroll() {
    building.scrollTop = 0;
    building.scrollLeft = 0;
}

/**
 * The room's zoom buttons — handed to `wire/camera-keys.js` to wire — its framing control, *Fit the lobby*, and the
 * gesture hint under the drawing: all four offered by `offerKeys()` on every camera shown (card#7343 c7692).
 */
const zoomButtons = { zoomIn: el('lobby-zoom-in'), zoomOut: el('lobby-zoom-out'), fit: el('lobby-fit'), hint: el('lobby-hint') };

/**
 * THE PAGE's ONE CAMERA HOOK: one camera on the room — `wire/camera.js`'s `view` as the layer's CSS transform — and the
 * directory's counter-scale from that SAME camera (`showDirectory()`), on EVERY camera this function shows. Nothing
 * framed is no transform at all: the placeholders as they flow. The camera is offered only while it frames something
 * (card#7343 r4b): the keys, the zoom buttons, *Fit the lobby* and the drawing's tab stop are `offerKeys()`'s, from the
 * screen's camera, never a glide's step towards it.
 */
function view(camera) {
    const framed = !framesNothing(camera);

    if (framed) {
        unscroll();
    }

    layer.style.transform = framed ? `scale(${camera.zoom}) translate(${-camera.x}px, ${-camera.y}px)` : '';
    showDirectory(layer, camera);
    offerKeys(building, zoomButtons, screen.camera());
}

const { show, glideTo, current } = cameraView(view);

/** The ride of the frame the page last painted — what the departing page's `pageswap` rule reads (§ 4.5). */
let lastFrame = null;

/** The room's drawing, built once; its press is the screen's ride (`cab.act()`), wired below. */
const room = roomDrawing(document, () => cab.act(screen.ride()));

/** The phase the room's window was last painted at, threaded through `paintRoom()`. */
let painted = null;

/** The room, the directory's and the readings' boxes for one frame — every member set either way. */
function paintScene(frame) {
    const drawn = frame.scene !== null;

    Object.assign(building.style, surfaceStyle(frame.scene));
    Object.assign(layer.style, drawn
        ? { position: 'relative', width: `${scene.extent.w}px`, height: `${scene.extent.h}px`, transformOrigin: '0 0' }
        : { position: '', width: '', height: '', transformOrigin: '' });

    for (const [box, rect] of [[directoryBox, scene.directory], [readingsBox, scene.readings]]) {
        const styles = boxStyles(rect, drawn);

        Object.assign(box.style, styles.box);
        Object.assign(box.firstElementChild.style, styles.face);
    }

    room.art.style.display = drawn ? 'block' : 'none';
    // The sheet's plaques and panels are the board's colours only on the drawn room (`mezzanine.css`'s lobby block).
    layer.toggleAttribute('data-drawn', drawn);

    if (drawn) {
        painted = paintRoom(document, room, frame.scene.ride, frame.sky, painted);
    }
}

/** The directory's plaques for one frame — `null` before a snapshot has applied, when the placeholders stand. */
function paintDirectory(frame) {
    if (frame.summary !== null) {
        renderDirectory(document, el('lobby-floors'), frame.summary);
    }
}

/** One lobby frame onto the page. */
function paint(frame) {
    lastFrame = frame;

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

    // The surface is a drawing that clips only while the room is drawn — applied BEFORE `surface()` is read below,
    // because that style is what can change the surface's own measured size.
    paintScene(frame);

    const summary = frame.summary;

    if (summary !== null) {
        const size = surface();
        const camera = size.width === frame.camera.surface.width && size.height === frame.camera.surface.height
            ? frame.camera
            : screen.resize(size);

        paintDirectory(frame);

        // § 4.5: a floor's ride into the lobby holds the cab's snapshot until the first drawn room, and ends it there.
        revealed(html);

        // A render never moves the viewer: a glide in flight keeps its step, and otherwise the room shows the screen's
        // camera, which the render left where the viewer put it.
        view(current(camera));

        // § 9 F17's statement, in its own region beside F4/F5's store statement.
        say('lobby-layout-statement', summary.layout_statement);
        say('lobby-layout-kept', summary.layout_kept);

        el('lobby-totals').textContent = summary.totals;
        el('lobby-stamp').textContent = summary.stamp;

        // Three indicators plus § 5.3's ingest recency, each into its OWN element — D2 § 8.2.4's "no aggregate rolls
        // three health facts into one" held by the shape of the page.
        for (const indicator of summary.indicators) {
            el(`lobby-${indicator.key}`).textContent = indicator.detail === null
                ? `${indicator.label}: ${indicator.value}`
                : `${indicator.label}: ${indicator.value} · ${indicator.detail}`;
        }
    } else {
        view(current(frame.camera));
    }

    // Last, so a phase performed at once paints its next frame over this one, never under it.
    cab.perform({
        ride: frame.ride,
        cab: frame.scene?.ride ?? null,
        feed: `feed: ${frame.strip.feed}`,
        layout: summary?.layout_kept ?? null,
    });
}

/**
 * THE ELEVATOR's CAB (§ 4.5) — the page's `#lobby-cab` dialog, wired to the screen's ride by the module both pages
 * share (card#12113: one ride, one cab). The drawing is `cab-paint.js`'s `paintCab()`, from the frame's `scene.ride`.
 */
const cab = ridePage({
    prefix: 'lobby',
    screen: () => screen,
    paint,
    paintCab,
    elevator: () => room.control,
    lastRide: () => lastFrame?.ride ?? null,
    timers,
});

const { client, clock, fetch: pageFetch, requestRender, log } = livePage(() => screen.render());
const screen = startLobbyScreen(client, pageFetch, clock, log, paint, {
    surface: surface(),
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    lobby_href: el('lobby').dataset.lobbyHref,
});

// The room's drawing stands first in the camera's layer, under the directory's and the readings' boxes.
layer.prepend(room.art);

// § 4.5 (card#12039): the cab's drawing, its button popup and the room's cab in the house theme's elevator palette.
loadHousePalette().then((palette) => {
    cabTheme(cab.dialog, palette);
    themeRoomCab(room.cab, palette);
});

// § 4.5: entered by a ride — the layout head script's record — so the first drawn room arrives (the screen's
// `arriving`), told before the first render.
const rideArrival = arrival(html, performance.now());

if (rideArrival !== null) {
    screen.arriveByRide(rideArrival);
}

// § 2.5's 1 s tick (`wire/age-readout.js`'s ticker, its second page): the directory's ages and its long-call threshold
// re-read at the corrected clock, nothing drained, no camera moved.
startAgeTicker(client, clock, window, () => {
    const frame = screen.tickView();

    if (frame !== null) {
        lastFrame = frame;
        paintDirectory(frame);
    }
});

// § 2.3 names "the lobby's refresh control" as one of the three paths to a fresh membership picture: one full
// snapshot through the protocol, then the layout.
el('lobby-refresh').addEventListener('click', () => {
    screen.refresh().then(requestRender);
});

// *Fit the lobby*: the whole room in view, gliding there — or cutting, under reduced motion.
el('lobby-fit').addEventListener('click', () => {
    const { from, to, glide_ms: ms } = screen.fitLobby();

    glideTo(from, to, ms);
});

// A plain wheel or a trackpad's two-finger scroll pans; a Ctrl+wheel or a trackpad's pinch zooms about the cursor; one
// finger or the primary button drags to pan, and two fingers pinch to zoom about their midpoint (§ 4.5, the operator's
// ruling of 2026-10-01 on card#11045) — the floor's wires, each leaving its event to the browser while the camera
// frames nothing, and each giving a wheel or a drag begun over the directory to the directory while it can move.
cameraGestures(building, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);
cameraKeys(building, zoomButtons, { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera }, show);
// Focus can scroll the clipping surface; the camera alone moves the view (`unscroll()`).
building.addEventListener('scroll', unscroll);
// The keyboard's focus on the elevator, or on a plaque's link, outside the view brings the camera to the elevator's or
// the board's rect — the screen's `focusElevator()` / `focusBoard()`, which leave a rect already in view where it is.
// Only the keyboard's focus: a press focuses too, and a press is a click or a pan (`:focus-visible` is the line).
building.addEventListener('focusin', (event) => {
    if (!event.target.matches(':focus-visible')) {
        return;
    }

    const focus = event.target.closest('[data-key="elevator"]') !== null
        ? screen.focusElevator()
        : event.target.closest('#lobby-floors') !== null ? screen.focusBoard() : null;

    if (focus !== null) {
        glideTo(focus.from, focus.to, focus.glide_ms);
    }
});
// A resize re-shows the screen's camera at once, stopping a glide in flight over the old surface.
window.addEventListener('resize', () => {
    show(screen.resize(surface()));
});

// § 4.4: the stream first, then the snapshot (§ 2.2) — and the layout after it, on the first render that finds the
// snapshot applied.
client.start();
requestRender();
