/**
 * THE CAMERA'S KEYS ON A PAGE — the keyboard and the zoom buttons, wired from a focusable drawing and
 * two buttons to a screen's camera acts. `docs/design/FLOOR.md` Appendix B rows 15 and 16.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343 r2-2). The floor page (`floor/main.js`, row 15) wired
 * these inline, and the lobby (`lobby/main.js`, row 16) had none — a building camera the wheel and the
 * drag reached and the keyboard did not, which is a camera a viewer without a pointer cannot move.
 * Both pages call `cameraKeys()`, beside `camera-gestures.js`'s `cameraGestures()`, with four arguments:
 * the drawing (`element`), its zoom-in and zoom-out buttons (`buttons`, which also carries the page's
 * framing control as `fit` — `offerKeys()`'s, and wired by the page itself), the screen's acts (`acts`, with
 * its `camera()` as `cameraGestures()`'s have it) and `show` — `cameraGestures()`'s three, with the buttons
 * after the drawing. And both call `offerKeys()` with the same drawing and buttons on every camera they
 * show (the floor on every render).
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT THE CAMERA. How far a notch zooms and a pan moves are the screen's
 * acts (`zoomStep(notches)`, `drag(dx, dy)`, each over `camera.js`); what comes back is handed to `show`.
 * What IS decided here is which key does what — row 15's, unchanged:
 *  · `+` or `=` zooms in and `-` or `_` out, one notch about the drawing's centre;
 *  · an arrow key pans by `PAN_STEP_PX`, the view moving the way the arrow points;
 *  · a key with a modifier is the browser's (Ctrl + is the page zoom) and passes through, and so does
 *    every other key — a focused desk's Enter and Space, a plate link's Enter — untouched;
 *  · the zoom-in and zoom-out buttons are one notch each, about the centre, as the keys are;
 *  · ⛔ NOTHING FRAMED, NOTHING OFFERED AND NOTHING TAKEN (card#7343 r4b, the seat's ruling — the same one
 *    gate as `camera-gestures.js`'s, `camera.js`'s `framesNothing()` over the screen's `camera()`). While
 *    the camera frames nothing, a key is the browser's — an arrow key scrolls the lobby's flowing list, as
 *    it did before the lobby had a camera — and nothing of the camera is offered: the zoom buttons, the
 *    page's framing control (the floor's *Fit the floor*, the lobby's *Whole building*) and the page's
 *    gesture hint (*scroll to pan · ctrl+scroll or pinch to zoom …*, card#11045 — untrue of a page whose
 *    wheel scrolls it) are hidden, the
 *    drawing is no tab stop, and it names no `aria-keyshortcuts`, so neither the keyboard nor assistive
 *    technology is sent to a camera that would do nothing (card#7343 comment 7692, items 1 and 2).
 *    `offerKeys()` puts all of it back once the camera frames something. The page's markup starts with
 *    none of it offered, because no camera frames anything before the first render; a drawing that held
 *    the keyboard's focus when its camera stopped framing loses it to the page, the accepted edge (c7692).
 *
 * Driven under `node` with stand-in elements by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { PAN_STEP_PX, framesNothing } from './camera.js';

/** The zoom keys, in notches — positive in, negative out. */
const KEY_ZOOM = { '+': 1, '=': 1, '-': -1, '_': -1 };

/** The pan keys, as a drag of the scene by `PAN_STEP_PX`: the view moves the way the arrow points. */
const KEY_PAN = {
    ArrowLeft: [PAN_STEP_PX, 0],
    ArrowRight: [-PAN_STEP_PX, 0],
    ArrowUp: [0, PAN_STEP_PX],
    ArrowDown: [0, -PAN_STEP_PX],
};

/**
 * The keys the drawing names in `aria-keyshortcuts` while it is offered: the zoom keys by their first
 * spelling each, and every pan key.
 */
const KEY_SHORTCUTS = ['+', '-', ...Object.keys(KEY_PAN)].join(' ');

/**
 * @param {EventTarget} element the focusable drawing the keys are pressed on
 * @param {{zoomIn: EventTarget, zoomOut: EventTarget}} buttons the page's zoom-in and zoom-out buttons
 * @param {{zoomStep: function(number): object, drag: function(number, number): object,
 *         camera: function(): {bounds: object|null}}} acts the screen's camera acts, each returning the
 *        camera it leaves, and the camera as it stands
 * @param {function(object): void} show puts a camera on the drawing (`camera-view.js`'s `show`)
 */
export function cameraKeys(element, buttons, acts, show) {
    element.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        // Gate: nothing framed — every key is the browser's (see the header).
        if (framesNothing(acts.camera())) {
            return;
        }

        if (event.key in KEY_ZOOM) {
            event.preventDefault();
            show(acts.zoomStep(KEY_ZOOM[event.key]));
        } else if (event.key in KEY_PAN) {
            event.preventDefault();
            show(acts.drag(...KEY_PAN[event.key]));
        }
    });

    buttons.zoomIn.addEventListener('click', () => {
        show(acts.zoomStep(1));
    });
    buttons.zoomOut.addEventListener('click', () => {
        show(acts.zoomStep(-1));
    });
}

/**
 * The camera offered while it frames something, and withdrawn while it frames nothing: the zoom buttons,
 * the page's framing control and its gesture hint shown or hidden, and the drawing a tab stop naming its
 * `aria-keyshortcuts`, or neither. A page calls it with the screen's camera — the one every gate reads,
 * and not a glide's step towards it (c7692 item 4) — on every camera it shows.
 *
 * ⛔ IT WRITES ONLY WHEN THE OFFER CHANGES, read off the drawing's own `aria-keyshortcuts`: a glide shows a
 * camera on every frame, and the offer then changes on none of them. That reading is sound because the
 * page's markup starts with the whole offer withdrawn — no `aria-keyshortcuts`, no `tabindex`, every button
 * hidden — which both pages' wiring tests hold, and because nothing but this function writes any of it.
 *
 * @param {Element} element the focusable drawing `cameraKeys()` was handed
 * @param {{zoomIn: HTMLElement, zoomOut: HTMLElement, fit: HTMLElement, hint: HTMLElement}} buttons the same
 *        zoom buttons, the page's framing control, and the page's gesture hint
 * @param {{bounds: object|null}} camera the screen's camera as it stands
 */
export function offerKeys(element, buttons, camera) {
    const offered = !framesNothing(camera);

    if (element.hasAttribute('aria-keyshortcuts') === offered) {
        return;
    }

    buttons.zoomIn.hidden = !offered;
    buttons.zoomOut.hidden = !offered;
    buttons.fit.hidden = !offered;
    buttons.hint.hidden = !offered;

    if (offered) {
        element.setAttribute('aria-keyshortcuts', KEY_SHORTCUTS);
        element.setAttribute('tabindex', '0');
    } else {
        element.removeAttribute('aria-keyshortcuts');
        element.removeAttribute('tabindex');
    }
}
