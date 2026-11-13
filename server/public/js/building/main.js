/**
 * THE BUILDING VIEW's DOM ENTRY — `docs/design/FLOOR.md § 4.4`'s `/building`, Appendix B row 47 (card#13033 slice 4;
 * `docs/design/plan-13033-3d-view.md` §§ 3.3, 3.4, 6 and 7).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every fact is `building/building-screen.js`'s frame — every floor composed and drawn as
 * the floor page composes and draws it, the stack, the status strip and the failure renders — every camera a pose of
 * `building/stack-model.js`'s, every act's answer `building/building-acts.js`'s, and each of those is driven headlessly
 * under `node`. What IS checked of this layer is that every element id it addresses exists on
 * `resources/views/building.blade.php` and the reverse, and how it wires the protocol, the wires and the glide
 * (`BuildingPageWiringTest`); what it draws, the browser leg reads (`tools/design/building-view.browser.mjs`).
 *
 * ⛔ THE CLIENT PROTOCOL IS `wire/live-page.js`'s, with its scheduler and the page's bounded animation log, which this
 * page hands to the screen and uses nowhere else (the floor's and the lobby's rule).
 *
 * ⛔ THE HOUSE WIRES, ON THE STAGE, WITH THE 3-D ACTS. `#building-stage` holds the canvas and the labels, and is the one
 * element both wires listen on, the one tab stop, and what `offerKeys()` offers — advertising only the keys that move
 * something here. Every act's answer reaches the page's `show`, which:
 *  · drops it while a glide is in flight — the view's own `show()` would interrupt the glide at its origin;
 *  · shows a view at the resting pose (a turn, or *Fit* from a turned overview) at once, and records it as the
 *    resting view;
 *  · glides to a view at another pose over the house glide (a cut under reduced motion), interpolated about the
 *    stack's axis, the desk labels culled and the stage's `data-gliding` its one writer here.
 * The glide's arrival tells the acts, re-culls the labels, writes the status line, and re-applies the keyboard's focus.
 *
 * ⛔ A RESIZE IS DRAWN AT ONCE: the stack is re-derived from the last frame's floors over the new stage
 * (`screen.resize()`), the slabs re-stood and the resting pose re-applied — a glide in flight completed at once — before
 * any gesture can read the stage, because the acts read it from the stack. A stage with no width or height builds no
 * stack, which every wire gate reads as framing nothing.
 *
 * ⛔ WITHOUT WebGL 2 THE LIST IS THE PAGE (§ 7): the statement is shown, the canvas and the camera row stay hidden, and
 * no picture is painted. WITH it, the page's *Drawing the building…* placeholder stands in the stage until the first stack
 * does, the list visually hidden and still read (`building-screen.js`'s `stagePhase()` decides which; `showPhase()` is its
 * one writer).
 */

import { livePage } from '../wire/live-page.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';
import { cameraKeys, offerKeys } from '../wire/camera-keys.js';
import { glideMs } from '../wire/camera.js';
import { loadArt, measurer } from '../floor/painter.js';
import { artInputs } from '../floor/floor-compose.js';
import { NO_WEBGL, stagePhase, startBuildingScreen } from './building-screen.js';
import { buildingActs } from './building-acts.js';
import { betweenPoses, liveView, poseCamera, samePose, snap, textureScale, viewStatus } from './stack-model.js';
import { floorPictures } from './floor-picture.js';
import { stackPaint } from './stack-paint.js';
import { buildingLabels } from './labels.js';

/** A missing element throws rather than being guarded past — the lobby's rule, for its reason. */
function el(id) {
    const node = document.getElementById(id);

    if (node === null) {
        throw new Error(`the building page has no #${id}`);
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

const stage = el('building-stage');
const canvas = el('building-canvas');
const lobbyHref = el('building').dataset.lobbyHref;
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** The camera row's buttons and the gesture hint — offered by `offerKeys()` only while the stack is drawn. */
const zoomButtons = { zoomIn: el('building-zoom-in'), zoomOut: el('building-zoom-out'), fit: el('building-fit'), hint: el('building-hint') };

/** The keys that move something here: the vertical arrows reach a drag's `dy`, which turns nothing (plan § 3.3). */
const SHORTCUTS = '+ - ArrowLeft ArrowRight';

/** The stage in CSS px — whole pixels, as `clientWidth` reads them (the focus search is bounded on whole sizes). */
function surface() {
    return { width: stage.clientWidth, height: stage.clientHeight };
}

/**
 * The stack as last stood, `null` while nothing is drawn; the camera last applied; the pictures last painted, and the
 * raster scale each floor was painted at; the last building frame, and whether the art modules have answered — what the
 * stage's phase reads.
 */
const live = { stack: null, shown: null, pictures: new Map(), scales: new Map(), shape: null, frame: null, art: false };

/** The stage's phase onto the page (`stagePhase()`): the placeholder, whether the list is drawn, and the canvas. */
function showPhase() {
    const phase = stagePhase({ webgl: paint !== null, art: live.art, frame: live.frame, stack: live.stack });

    stage.dataset.phase = phase;
    el('building-drawing').hidden = phase !== 'drawing';
    el('building-floors').classList.toggle('visually-hidden', phase === 'drawing');
    canvas.hidden = phase !== 'stage';
}

/** A floor's raster scale at a view (§ 3.2 step 8): the focused floor's at its pose, every other's 1. */
function scaleOf(stack, at, floor) {
    return textureScale(stack.slabs.find((slab) => slab.floor === floor), at, stack, stack.surface, window.devicePixelRatio, paint.maxTexture);
}

/** The glide in flight's destination view, and the stack's axis it rounds. */
let destination = null;
let axis = null;

/** The desk label the cull leads with (§ 3.4's one source: the keyboard's focus), or `null`. */
let leader = null;

const { client, clock, fetch: pageFetch, requestRender, log } = livePage(() => screen.render());

const acts = buildingActs({ stack: () => live.stack, gliding: () => view.gliding() });

/** The resting view the acts hold, as a view (`{ pose, azimuth }`), read through the stack's live floors. */
function resting() {
    const { pose, azimuth } = acts.camera();

    return liveView({ pose, azimuth }, live.stack);
}

/** The value a view's camera is shown as: the view and the camera its pose names over the live stack. */
function shownOf(at) {
    return { view: at, camera: poseCamera(at, live.stack, live.stack.surface) };
}

/** One camera onto the stage: the slabs drawn from it and every docked label re-projected under it. */
function apply(shown) {
    if (paint === null || live.stack === null) {
        return;
    }

    live.shown = shown;
    paint.apply(shown.camera);
    labels.place(live.stack, shown.camera, paint.project);
}

/** The keyboard's focus on a label of a floor other than `at`'s — the floor to bring into view, or `null`. */
function focusElsewhere(at) {
    const active = document.activeElement;
    const entry = active === null ? null : labels.entryOf(active);
    const focused = at.pose === 'overview' ? null : at.pose.floor;

    return entry !== null && entry.floor !== null && entry.floor !== focused && active.matches(':focus-visible') ? entry.floor : null;
}

/** The desk label the keyboard's focus is on, when it is the focused floor's — the cull's leader at `at`. */
function focusedDesk(at) {
    const active = document.activeElement;
    const entry = active === null ? null : labels.entryOf(active);

    return entry !== null && entry.desk !== null && at.pose !== 'overview' && entry.floor === at.pose.floor ? entry.desk : null;
}

/** A glide or a cut reached its destination: the view rests there. */
function arrive(to) {
    destination = null;
    acts.arrived(to.view);
    stage.dataset.gliding = 'false';

    if (live.stack === null) {
        return;
    }

    stage.dataset.view = to.view.pose === 'overview' ? 'overview' : `focus:${to.view.pose.floor}`;
    say('building-view-status', viewStatus(to.view, live.stack));
    leader = focusedDesk(to.view);
    labels.cull(to.view, live.stack, leader);

    // Focus that landed on another floor's label during the glide brings that floor on now (§ 3.4, round 5's minor 8).
    const elsewhere = focusElsewhere(to.view);

    if (elsewhere !== null) {
        show(snap(to.view, { act: 'floor', floor: elsewhere }, live.stack, live.stack.surface));
    }

    // The focused floor's picture at its pose's scale, the one it left back at 1 (§ 3.2 step 8) — a render only where a
    // scale changed: a render repaints every floor, and one landing in the gesture that caused this arrival is a long
    // task inside its stream.
    if (live.stack.slabs.some((slab) => live.scales.has(slab.floor) && live.scales.get(slab.floor) !== scaleOf(live.stack, to.view, slab.floor))) {
        requestRender();
    }
}

const view = cameraView(apply, { between: (a, b, t) => betweenPoses(a, b, t, axis), onArrive: arrive });

/**
 * THE PAGE's `show` — what every act's answer is handed to (see the header): dropped while a glide is in flight, shown
 * at once at the resting pose, glided to at any other.
 */
function show(next) {
    if (view.gliding() || live.stack === null) {
        return;
    }

    const from = resting();
    const to = liveView(next, live.stack);

    if (samePose(from, to)) {
        acts.arrived(to);
        view.show(shownOf(to));

        return;
    }

    destination = to;
    axis = live.stack.axis;
    labels.cull(null, live.stack, null);
    stage.dataset.gliding = 'true';
    view.glideTo(shownOf(from), shownOf(to), glideMs(reduce));
}

/** The stack's geometry as a string — what decides whether the slabs moved under the camera. */
function shapeOf(stack) {
    return JSON.stringify([stack.surface, stack.slabs.map((slab) => [slab.floor, slab.box]), stack.ground.box]);
}

/**
 * The stack stood on the stage — after a render (its pictures new) or a resize (its pictures the last render's): the
 * slabs built, and the camera re-applied where the stack moved under it (§ 3.3's resize rule).
 */
function stand(stack) {
    live.stack = stack;
    showPhase();

    el('building-camera-row').hidden = stack === null;

    if (stack === null) {
        labels.dock('flow');
        offerKeys(stage, zoomButtons, acts.camera(), SHORTCUTS);

        return;
    }

    labels.dock('stage');
    paint.resize(stack.surface);
    paint.build(stack, live.pictures);

    const shape = shapeOf(stack);
    const moved = shape !== live.shape;

    live.shape = shape;

    if (view.gliding() && moved) {
        // A glide in flight completes at once to its destination's new pose — never stopped halfway.
        view.glideTo(shownOf(destination), shownOf(destination), 0);
    } else if (live.shown === null) {
        // The first frame with an extent: the stack at the resting pose, a setting (§ 6.5), arrived at.
        view.glideTo(shownOf(resting()), shownOf(resting()), 0);
    } else if (!view.gliding()) {
        // At rest the resting pose is re-applied — a focused floor the layout dropped is the overview, at once.
        acts.arrived(resting());
        view.show(shownOf(resting()));
        labels.cull(resting(), stack, leader);
    } else {
        labels.place(stack, live.shown.camera, paint.project);
    }

    offerKeys(stage, zoomButtons, acts.camera(), SHORTCUTS);
}

/** One building frame onto the page. */
async function draw(frame) {
    const strip = frame.strip;

    live.frame = frame;

    say('building-feed', `feed: ${strip.feed}`);
    say('building-resyncs', strip.resyncs);
    say('building-fleet-counts', `building: ${strip.totals}`);
    // § 9 F14: the strip's own line when art a floor's drawing asked for did not load.
    say('building-art', strip.art);

    for (const indicator of strip.indicators) {
        say(`building-${indicator.key}`, indicator.detail === null
            ? `${indicator.label}: ${indicator.value}`
            : `${indicator.label}: ${indicator.value} · ${indicator.detail}`);
    }

    const failure = frame.failure;

    say('building-statement', failure.statement);
    say('building-kept', failure.kept);
    say('building-banner', failure.banner);
    say('building-layout-statement', frame.statement);
    el('building-signin').hidden = failure.sign_in === null;
    say('building-signin-prompt', failure.sign_in?.prompt ?? null);
    say('building-not-live', failure.sign_in?.label ?? null);
    list('building-log', client.eventLog);

    labels.render(frame.floors, lobbyHref);

    if (paint === null) {
        return;
    }

    const stack = frame.stack;

    if (stack !== null && pictures !== null) {
        const at = liveView(resting(), stack);

        live.scales = new Map(stack.slabs.map((slab) => [slab.floor, scaleOf(stack, at, slab.floor)]));
        live.pictures = await pictures.paint(frame.floors, (key) => live.scales.get(key), paint.maxTexture);
    }

    // The pictures were awaited: a stage resized meanwhile is re-stacked at its size now.
    const size = surface();

    stand(stack !== null && (size.width !== stack.surface.width || size.height !== stack.surface.height) ? screen.resize(size) : stack);
}

/** The floors' list and the labels over the stage. */
const labels = buildingLabels(document, { list: el('building-floors'), readFloor: el('building-read-floor'), measure: measurer() }, {
    // The keyboard's focus on another floor's label brings that floor into view — the snap table's click on its slab;
    // on the focused floor's culled label it leads the cull, so focus never rests on an invisible link.
    focused: (at) => {
        if (live.stack === null || view.gliding()) {
            return;
        }

        const now = resting();

        if (at.floor !== null && (now.pose === 'overview' || at.floor !== now.pose.floor)) {
            show(snap(now, { act: 'floor', floor: at.floor }, live.stack, live.stack.surface));
        } else if (at.desk !== null) {
            leader = at.desk;
            labels.cull(now, live.stack, leader);
        }
    },
    // Focus leaving a label: the cull is led by the label focus moved to, when it is the focused floor's, or by none.
    blurred: (to) => {
        if (live.stack === null || view.gliding()) {
            return;
        }

        const now = resting();

        leader = to !== null && to.desk !== null && now.pose !== 'overview' && to.floor === now.pose.floor ? to.desk : null;
        labels.cull(now, live.stack, leader);
    },
});

/** The paint, or `null` where the browser offers no WebGL 2 (§ 7) — and then the list is the page. */
let paint = null;
let pictures = null;
const page = window.getComputedStyle(document.documentElement);

if (document.createElement('canvas').getContext('webgl2') === null) {
    say('building-webgl', NO_WEBGL);
    showPhase();
} else {
    paint = stackPaint(canvas, {
        slab: page.getPropertyValue('--house-trim').trim(),
        pixelRatio: window.devicePixelRatio,
        lost: () => {
            say('building-webgl', NO_WEBGL);
        },
        restored: () => {
            say('building-webgl', null);

            if (live.stack !== null) {
                paint.build(live.stack, live.pictures);
                apply(live.shown ?? shownOf(resting()));
            }
        },
    });
}

const screen = startBuildingScreen(client, pageFetch, clock, log, draw, {
    surface: surface(),
    reduce,
    lobby_href: lobbyHref,
    wake: requestRender,
});

// The art modules, by the asset route: the screen draws no scene until they answer, and every floor's painter reports
// an asset it could not draw back to the screen (§ 9 F14).
loadArt().then(({ furniture, characters, registry, themes, failed }) => {
    live.art = true;

    if (paint !== null) {
        pictures = floorPictures(document, el('building-pictures'), {
            characters,
            themes,
            house: registry?.HOUSE_THEME ?? null,
            clock,
            failed: (ids) => {
                screen.assetsFailed(ids);
                requestRender();
            },
            wake: requestRender,
            record: (line) => client.record(line),
        });
    }

    if (furniture !== null) {
        screen.sceneInputs(artInputs({ furniture, characters, registry, themes }, measurer()));
    }

    screen.assetsFailed(failed);
    requestRender();
});

// The camera (§ 3.3): a left-drag or one finger turns the building, the wheel, a pinch or a click on a slab snaps to a
// floor and back, the keys and the buttons through the house wire — each answering the page's `show`, and each leaving
// its event to the browser while the stack is not drawn.
cameraGestures(stage, { pan: acts.pan, zoom: acts.zoom, pinch: acts.pinch, drag: acts.drag, camera: acts.camera }, show);
cameraKeys(stage, zoomButtons, { zoomStep: acts.zoomStep, drag: acts.drag, camera: acts.camera }, show);

// *Fit the building*: the overview from the front.
el('building-fit').addEventListener('click', () => {
    if (live.stack !== null) {
        show(snap(resting(), { act: 'fit' }, live.stack, live.stack.surface));
    }
});

// A click on a slab the wire did not veto (a press that never dragged), not on a label, and not during a glide: the
// snap table's *click* — a snap, never a navigation; the links open things.
stage.addEventListener('click', (event) => {
    if (event.target.closest('a') !== null || view.gliding() || live.stack === null) {
        return;
    }

    const r = stage.getBoundingClientRect();

    show(snap(resting(), { act: 'click', point: { x: event.clientX - r.left, y: event.clientY - r.top } }, live.stack, live.stack.surface));
});

// A resize of the STAGE — the chrome above it grows and shrinks with what the page has to say — re-stands the stack at
// once, then asks for a render for the pictures' scale.
new ResizeObserver(() => {
    if (paint !== null) {
        stand(screen.resize(surface()));
    } else {
        screen.resize(surface());
    }

    requestRender();
}).observe(stage);

// § 4.4: the stream first, then the snapshot (§ 2.2) — and the layout after it, on the first render that finds the
// snapshot applied.
client.start();
requestRender();
