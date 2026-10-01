/**
 * THE BUILDING'S DRAWING'S PAINT — Appendix B row 16, slice B (card#7343). `building-scene.js`'s
 * `buildingArt()`, `CAB` and `cabStyle()` are shapes and style, deciding nothing about the DOM;
 * `lobby/main.js` owns the drawing's place on the page and wires it to the render loop. This module is
 * the DOM operations between them — building the drawing element once, keeping it across renders, and
 * painting it from a scene — extracted so a node probe can drive them on a stand-in `document`
 * (`building-paint-probe.mjs`), as `wire/camera-keys.js`'s `offerKeys()` is driven by
 * `camera-wire-probe.mjs`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS EXISTS (impl review r1, card#7343 row 16): row 16 claimed `LobbyPageWiringTest` reds a
 * drawing rebuilt on every render, a cab whose style is dropped, scenery that goes unpainted and a
 * drawing reachable by the pointer — but that test is SOURCE-PRESENCE only, a `str_contains()` over
 * `main.js`'s text, and four real defects of exactly those shapes (the cab's `Object.assign` deleted,
 * `scenery.replaceChildren()` deleted, an unconditional `drawing.remove()` added, `pointerEvents`
 * dropped from its construction) all stayed green against it. Behaviour it never ran cannot be held by
 * reading its own text, so what makes each claim true is here, where a probe RUNS it on a stand-in DOM
 * and reads what actually happened — `Tests\\Feature\\Lobby\\TheBuildingDrawingKeepsItsElementTest`.
 *
 * ⛔ THE DRAWING SURVIVES A REBUILD (`keepDrawing()`): every OTHER row of `#lobby-floors` is removed and
 * rebuilt each render (`main.js`'s plates), and the drawing is the one row that never is — the SAME
 * element from one render to the next — because the cab's CSS transition lives on it, and an element
 * removed from the DOM (even if immediately re-added) is not the element a running transition ran on.
 *
 * ⛔ THE CAB's STYLE IS RE-APPLIED EVERY PAINT, THE WINDOWS WHEN THE BOX OR A17's PHASE CHANGES, THE SCENERY
 * ONLY WHEN ITS BOX CHANGES (`paintBuilding()`):
 * the roof, the storeys and the ground lobby are one box's worth of shapes that do not change while the
 * stack does not, so they are repainted only when `buildingArt()`'s `box` changes (`painted`, the
 * caller's own, threaded through and handed back); the windows carry A17's sky and are repainted when the
 * phase steps as well; the cab moves independently of the box — the viewer
 * rides while the stack stays the same height — so `Object.assign(cabNode.style, cabStyle(…))` runs on
 * every call, box changed or not.
 */

import { CAB, buildingArt, cabStyle, windowArt } from './building-scene.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * One of `building-scene.js`'s shapes as an SVG element — its attributes and its text, or the shapes it
 * holds (a paint server: `<defs>`, a gradient, its stops), deciding nothing.
 */
export function svgShape(doc, { el, attrs, text, children }) {
    const node = doc.createElementNS(SVG_NS, el);

    for (const [name, value] of Object.entries(attrs)) {
        node.setAttribute(name, String(value));
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    if (children !== undefined) {
        node.append(...children.map((child) => svgShape(doc, child)));
    }

    return node;
}

/**
 * The building's drawing element — one `<li>` holding one `<svg>` — built once so `main.js` and the
 * probe build the identical element: `aria-hidden`, `pointerEvents: 'none'` and no `tabindex`, so it is
 * inert to assistive technology and to a pointer alike, and never a target either can reach.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in
 */
export function buildingDrawing(doc) {
    const drawing = doc.createElement('li');
    const art = doc.createElementNS(SVG_NS, 'svg');
    const scenery = doc.createElementNS(SVG_NS, 'g');
    const windows = doc.createElementNS(SVG_NS, 'g');
    const cabNode = doc.createElementNS(SVG_NS, 'g');

    drawing.setAttribute('aria-hidden', 'true');
    Object.assign(drawing.style, { position: 'absolute', left: '0', top: '0', listStyle: 'none', pointerEvents: 'none' });
    art.setAttribute('overflow', 'visible');
    art.style.display = 'block';
    cabNode.append(...CAB.map((shape) => svgShape(doc, shape)));
    art.append(scenery, windows, cabNode);
    drawing.append(art);

    return { drawing, art, scenery, windows, cabNode };
}

/**
 * Keeps the drawing element in `rows` across a rebuild: every OTHER row is removed, and the drawing is
 * left standing — or, the first time (or after it was ever detached), stood at the front.
 *
 * @param {Element} rows the page's `#lobby-floors`
 * @param {Element} drawing the one drawing element, kept
 */
export function keepDrawing(rows, drawing) {
    for (const row of [...rows.children]) {
        if (row !== drawing) {
            row.remove();
        }
    }

    if (drawing.parentNode !== rows) {
        rows.prepend(drawing);
    }
}

/**
 * Paints the building's drawing for a scene, with the cab at `level` gliding over `cabGlide` and the
 * plates' windows showing A17's sky of `phase`.
 *
 * ⛔ THE WINDOWS ARE REPAINTED WHEN THE BOX OR THE PHASE CHANGES, THE SCENERY ONLY WHEN THE BOX DOES: the
 * sky steps on a heartbeat while the stack stays the same height, and a window left at the phase it was
 * first painted with would be a sky frozen on a live feed.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in
 * @param {{drawing: Element, art: Element, scenery: Element, windows: Element, cabNode: Element}} nodes `buildingDrawing()`'s
 * @param {object|null} scene `building-scene.js`'s `buildingScene()`
 * @param {number|null} level the plate the cab stands at
 * @param {number} cabGlide how long the cab may take to its stop — `0` cuts
 * @param {{box: string, sky: string}|null} painted what was last painted — the caller's own state
 * @param {string|null} phase § 6.2 A17's sky phase — the lobby frame's `sky`
 * @returns {{box: string, sky: string}|null} what is painted after this call — thread back as the next call's `painted`
 */
export function paintBuilding(doc, { drawing, art, scenery, windows, cabNode }, scene, level, cabGlide, painted, phase) {
    const drawn = buildingArt(scene);

    drawing.hidden = drawn === null;

    if (drawn === null) {
        return painted;
    }

    const { x, y, w, h } = drawn.box;
    const box = `${x} ${y} ${w} ${h}`;
    const sky = `${box} ${phase ?? 'unset'}`;

    if (box !== painted?.box) {
        art.setAttribute('viewBox', box);
        art.setAttribute('width', String(w));
        art.setAttribute('height', String(h));
        Object.assign(drawing.style, { left: `${x}px`, top: `${y}px` });
        scenery.replaceChildren(...drawn.shapes.map((shape) => svgShape(doc, shape)));
    }

    if (sky !== painted?.sky) {
        windows.replaceChildren(...windowArt(scene, phase).shapes.map((shape) => svgShape(doc, shape)));
    }

    const cabAt = cabStyle(scene, level, cabGlide);

    cabNode.style.display = cabAt === null ? 'none' : '';
    Object.assign(cabNode.style, cabAt ?? {});

    return { box, sky };
}
