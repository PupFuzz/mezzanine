/**
 * THE 3-D FLOOR's DOM ENTRY — `docs/design/FLOOR.md` § 4.4's `/floor/{floor}/3d`: the hoisted floor page
 * (`floor/floor-page.js`, which owns the page's wiring and states its rules) over the 3-D renderer
 * (`floor3d/floor-paint.js`), on the 3-D route — its second caller. `docs/design/plan-13033-stage2-3d-floor.md` § 4 and
 * § 7 (card#13033 stage 2 slice 4).
 *
 * ⛔ WITHOUT WebGL 2 NO RENDERER IS CONSTRUCTED (§ 7, m9): the statement is shown with its link to the 2-D floor, the
 * stage and the camera's controls are withdrawn, and the page runs over a renderer that draws nothing — so the list view
 * below the stage is the page, live, and nothing redirects. A context lost later says the same and withdraws the same;
 * a restored one draws again.
 *
 * ⛔ THE ONE READ THE BROWSER LEG TAKES: the canvas carries `inspect()` — the renderer's camera, frame count, walls and
 * § 6's figures (`floor-paint.js`'s `inspect()`), read-only — because a module's scope is not reachable from the page.
 */

import { startFloorPage } from '../floor/floor-page.js';
import { worldRenderer } from './floor-paint.js';

const canvas = document.getElementById('floor3d-canvas');
const root = document.getElementById('floor');

/** The statement shown and the stage and its camera withdrawn — or the reverse (`mezzanine.css`'s `data-webgl`). */
function webgl(available) {
    document.getElementById('floor3d-webgl').hidden = available;
    root.dataset.webgl = available ? 'ready' : 'none';
}

/** The page without a drawing: the screen's frames still paint the list view, the strip and the panel. */
function listOnly() {
    return {
        camera: (screen) => ({
            show() {},
            current: (camera) => camera,
            fit() {},
            resize: (surface) => screen.resize(surface),
            gestures() {},
            // Nothing is framed, so every key and the wheel stay the browser's.
            keys: { acts: { zoomStep: () => ({ bounds: null }), drag: () => ({ bounds: null }), camera: () => ({ bounds: null }) } },
        }),
        create: () => ({ paint() {}, refresh() {}, paintCab() {}, cabTheme() {} }),
    };
}

/** The 3-D renderer, with the drawing it creates held for `inspect()`. */
function inWorld() {
    const renderer = worldRenderer(document, window, {
        reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        lost: () => webgl(false),
        restored: () => webgl(true),
    });
    let drawing = null;

    canvas.inspect = (options) => drawing?.inspect(options) ?? null;

    return {
        camera: renderer.camera,
        create: (art, hooks) => {
            drawing = renderer.create(art, hooks);

            return drawing;
        },
    };
}

const available = document.createElement('canvas').getContext('webgl2') !== null;

webgl(available);
startFloorPage(document, window, { renderer: available ? inWorld() : listOnly(), route: '3d' });
