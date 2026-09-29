/**
 * THE CAMERA'S KEYS ON A PAGE — the keyboard and the zoom buttons, wired from a focusable drawing and
 * two buttons to a screen's camera acts. `docs/design/FLOOR.md` Appendix B rows 15 and 16.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343 r2-2). The floor page (`floor/main.js`, row 15) wired
 * these inline, and the lobby (`lobby/main.js`, row 16) had none — a building camera the wheel and the
 * drag reached and the keyboard did not, which is a camera a viewer without a pointer cannot move.
 * Both pages call `cameraKeys()`, beside `camera-gestures.js`'s `cameraGestures()`, with four arguments:
 * the drawing (`element`), its zoom-in and zoom-out buttons (`buttons`), the screen's acts (`acts`, with
 * its `camera()` as `cameraGestures()`'s have it) and `show` — `cameraGestures()`'s three, with the buttons
 * after the drawing. And both call `offerKeys()` with the same drawing and buttons on every render.
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
 *    it did before the lobby had a camera — the zoom buttons are hidden, and the drawing names no
 *    `aria-keyshortcuts`, so assistive technology is not told of keys that would do nothing. `offerKeys()`
 *    puts both back once the camera frames something. The page's markup starts with neither offered,
 *    because no camera frames anything before the first render.
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
 * The keys and the zoom buttons offered while the camera frames something, and withdrawn while it frames
 * nothing: the buttons shown or hidden, and the drawing's `aria-keyshortcuts` named or removed. A page
 * calls it with the camera each render leaves.
 *
 * @param {Element} element the focusable drawing `cameraKeys()` was handed
 * @param {{zoomIn: HTMLElement, zoomOut: HTMLElement}} buttons the same zoom buttons
 * @param {{bounds: object|null}} camera the screen's camera as the render leaves it
 */
export function offerKeys(element, buttons, camera) {
    const offered = !framesNothing(camera);

    buttons.zoomIn.hidden = !offered;
    buttons.zoomOut.hidden = !offered;

    if (offered) {
        element.setAttribute('aria-keyshortcuts', KEY_SHORTCUTS);
    } else {
        element.removeAttribute('aria-keyshortcuts');
    }
}
