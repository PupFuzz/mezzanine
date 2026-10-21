/**
 * THE LOBBY ROOM's PAINT — `docs/design/FLOOR.md` § 4.1's room and its elevator (card#12113). `building-scene.js`'s
 * `roomArt()` and `windowArt()` are shapes deciding nothing about the DOM; `lobby/main.js` owns the drawing's place on
 * the page and wires it to the render loop. This module is the DOM operations between them — building the room's
 * drawing once, painting its window from A17's phase, and painting the elevator's leaves from the ride — extracted so a
 * node probe can drive them on a stand-in `document` (`Tests\Feature\Lobby\TheLobbyIsADrawnRoomTest`,
 * `Tests\Feature\Lobby\TheLobbysElevatorIsTheFloorsRideTest`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ELEVATOR IS A CONTROL AND NOTHING ELSE ON THE WALL IS (§ 4.1): its leaves and its surround are one button —
 * `role="button"`, in the tab order, named *the elevator — ride to another floor*, pressed by a click, Enter or Space,
 * `aria-disabled` while a ride is in flight — exactly the floor's drawn elevator (`floor/painter.js`'s band), through
 * the same code path: a press is the page's `ride`, the lobby screen's `ride()`, which refuses a press mid-ride.
 *
 * ⛔ BEHIND ITS LEAVES IS THE CAB: `floor/cab-paint.js`'s `drawCabInterior()` from the landing's eye (`LANDING`), clipped
 * to the doorway, as the floor's band draws it, in the house theme's elevator palette when the page holds it and the
 * sheet's door frame and lamp without it (`.band-cab`) — scenery, drawn once, still.
 *
 * ⛔ THE LEAVES ARE THE RIDE's AND NOTHING ELSE's: `cab-paint.js`'s `rideLeaves()` over the frame's `scene.ride`, each
 * phase's door motion placed at the phase's elapsed time so a re-paint neither restarts nor reorders it, easing; with no
 * ride they are shut, whatever else renders. No walk, no fact and no render that is no ride moves them, and nothing
 * here or anywhere in the room says where the cab is.
 *
 * ⛔ THE WINDOW IS REPAINTED WHEN A17's PHASE CHANGES AND THE REST NEVER: the room is one drawing for every building, so
 * it is built once; the sky steps on a heartbeat.
 */

import { CAB_STYLE, LANDING, clippedTo, drawCabInterior, rideLeaves } from '../floor/cab-paint.js';
import { lobbyScene, roomArt, windowArt } from './building-scene.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** The elevator control's accessible name — the floor's band's own (§ 4.1, § 4.5). */
export const ELEVATOR_NAME = 'the elevator — ride to another floor';

/**
 * One of `building-scene.js`'s shapes as an SVG element — its attributes and its text, or the shapes it holds (a paint
 * server: `<defs>`, a gradient, its stops), deciding nothing.
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
 * The room's drawing — one `<svg>` the size of the room, in the camera's layer — built once: the room's scenery
 * (`roomArt()`, `aria-hidden`, never a pointer's target), the window's group (`windowArt()`, painted by
 * `paintRoom()`), the cab behind the elevator's leaves, and the elevator control over it, its two leaves and its hit
 * area. `ride` is the page's press — called once per click, Enter or Space.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in (`drawCabInterior()` writes through the global one)
 * @param {function(): void} ride the elevator's press
 * @returns {{art: Element, windows: Element, control: Element, leaves: Array<Element>, cab: Element}}
 */
export function roomDrawing(doc, ride) {
    const scene = lobbyScene();
    const { box, shapes } = roomArt();
    const art = doc.createElementNS(SVG_NS, 'svg');
    const scenery = doc.createElementNS(SVG_NS, 'g');
    const windows = doc.createElementNS(SVG_NS, 'g');
    const style = doc.createElementNS(SVG_NS, 'style');

    art.setAttribute('viewBox', `${box.x} ${box.y} ${box.w} ${box.h}`);
    art.setAttribute('width', String(box.w));
    art.setAttribute('height', String(box.h));
    art.setAttribute('class', 'lobby-room');
    art.setAttribute('role', 'group');
    art.setAttribute('aria-label', 'the lobby');
    art.style.position = 'absolute';
    art.style.left = '0';
    art.style.top = '0';
    art.style.display = 'block';
    // The leaves' keyframes and the cab's classes — `cab-paint.js`'s, the floor's own (the sheet's tokens, no colour here).
    style.textContent = CAB_STYLE;
    scenery.setAttribute('aria-hidden', 'true');
    scenery.style.pointerEvents = 'none';
    scenery.append(...shapes.map((shape) => svgShape(doc, shape)));
    windows.setAttribute('aria-hidden', 'true');
    windows.style.pointerEvents = 'none';
    art.append(style, scenery, windows);

    // The cab behind the leaves, seen from the landing, clipped to the doorway — still, and nothing says it is there.
    const inside = doc.createElementNS(SVG_NS, 'g');

    inside.setAttribute('class', 'band-cab');
    inside.setAttribute('aria-hidden', 'true');
    art.append(inside);

    const defs = doc.createElementNS(SVG_NS, 'defs');

    inside.append(defs);
    drawCabInterior(clippedTo(inside, defs, scene.doorway, 'lobby-cab'), scene.doorway, 'lobby-cab', LANDING);

    // The control: the two leaves meeting at the seam, each edged in the door's edge colour, and its hit area over the
    // surround — the frame and the call plate.
    const control = doc.createElementNS(SVG_NS, 'g');
    const d = scene.doorway;
    const seam = d.x + d.w / 2;

    for (const [name, value] of Object.entries({ class: 'elevator', 'data-key': 'elevator', tabindex: '0', role: 'button', 'aria-label': ELEVATOR_NAME })) {
        control.setAttribute(name, value);
    }

    const leaves = [
        svgShape(doc, { el: 'rect', attrs: { x: d.x, y: d.y, width: d.w / 2 - 1, height: d.h, rx: 2, class: 'elevator-leaf left' } }),
        svgShape(doc, { el: 'rect', attrs: { x: seam + 1, y: d.y, width: d.w / 2 - 1, height: d.h, rx: 2, class: 'elevator-leaf right' } }),
    ];
    const s = scene.surround;

    control.append(...leaves, svgShape(doc, { el: 'rect', attrs: { x: s.x, y: s.y, width: s.w, height: s.h, class: 'elevator-hit' } }));
    control.addEventListener('click', () => ride());
    // A `role="button"` activates on Enter and on Space; Space's default would scroll the page.
    control.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            ride();
        }
    });
    art.append(control);

    return { art, windows, control, leaves, cab: inside };
}

/**
 * The cab behind the leaves in the house theme's elevator palette — `--cab-frame` / `--cab-lamp` on its group, as the
 * floor's band carries them — or, with no palette, the sheet's door frame and lamp (`.band-cab`, `CAB_STYLE`'s). The page
 * calls it once `cab-paint.js`'s `loadHousePalette()` has answered.
 *
 * @param {Element} cab `roomDrawing()`'s `cab`
 * @param {{frame: string, lamp: string}|null} palette
 */
export function themeRoomCab(cab, palette) {
    if (palette === null) {
        cab.removeAttribute('style');

        return;
    }

    cab.setAttribute('style', `--cab-frame:${palette.frame};--cab-lamp:${palette.lamp}`);
}

/**
 * Paints the room for one frame: the window's sky at A17's `phase` when it changed, and the elevator's leaves from the
 * ride — `rideLeaves()` over `ride`, shut with none — with the control `aria-disabled` while a ride is in flight.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in
 * @param {{windows: Element, control: Element, leaves: Array<Element>}} nodes `roomDrawing()`'s
 * @param {object|null} ride the frame's `scene.ride` — `wire/ride.js`'s `sceneRide()`
 * @param {string|null} phase § 6.2 A17's sky phase — the lobby frame's `sky`
 * @param {string|null} painted the phase last painted — the caller's own state
 * @returns {string} the phase painted, to thread back as the next call's `painted`
 */
export function paintRoom(doc, { windows, control, leaves }, ride, phase, painted) {
    const sky = phase ?? 'unset';

    if (sky !== painted) {
        windows.replaceChildren(...windowArt(phase).shapes.map((shape) => svgShape(doc, shape)));
    }

    const style = rideLeaves(ride ?? null);

    for (const leaf of leaves) {
        if (style === null) {
            leaf.removeAttribute('style');
        } else {
            leaf.setAttribute('style', style);
        }
    }

    if (ride === null || ride === undefined) {
        control.removeAttribute('aria-disabled');
    } else {
        control.setAttribute('aria-disabled', 'true');
    }

    return sky;
}
