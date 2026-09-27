/**
 * THE CAMERA'S KEYS ON A PAGE — the keyboard and the zoom buttons, wired from a focusable drawing and
 * two buttons to a screen's camera acts. `docs/design/FLOOR.md` Appendix B rows 15 and 16.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343 r2-2). The floor page (`floor/main.js`, row 15) wired
 * these inline, and the lobby (`lobby/main.js`, row 16) had none — a building camera the wheel and the
 * drag reached and the keyboard did not, which is a camera a viewer without a pointer cannot move.
 * Both pages call `cameraKeys()`, beside `camera-gestures.js`'s `cameraGestures()`, with four arguments:
 * the drawing (`element`), its zoom-in and zoom-out buttons (`buttons`), the screen's acts (`acts`) and
 * `show` — `cameraGestures()`'s three, with the buttons after the drawing.
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT THE CAMERA. How far a notch zooms and a pan moves are the screen's
 * acts (`zoomStep(notches)`, `drag(dx, dy)`, each over `camera.js`); what comes back is handed to `show`.
 * What IS decided here is which key does what — row 15's, unchanged:
 *  · `+` or `=` zooms in and `-` or `_` out, one notch about the drawing's centre;
 *  · an arrow key pans by `PAN_STEP_PX`, the view moving the way the arrow points;
 *  · a key with a modifier is the browser's (Ctrl + is the page zoom) and passes through, and so does
 *    every other key — a focused desk's Enter and Space, a plate link's Enter — untouched;
 *  · the zoom-in and zoom-out buttons are one notch each, about the centre, as the keys are.
 *
 * Driven under `node` with stand-in elements by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { PAN_STEP_PX } from './camera.js';

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
 * @param {EventTarget} element the focusable drawing the keys are pressed on
 * @param {{zoomIn: EventTarget, zoomOut: EventTarget}} buttons the page's zoom-in and zoom-out buttons
 * @param {{zoomStep: function(number): object, drag: function(number, number): object}} acts the
 *        screen's camera acts, each returning the camera it leaves
 * @param {function(object): void} show puts a camera on the drawing (`camera-view.js`'s `show`)
 */
export function cameraKeys(element, buttons, acts, show) {
    element.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) {
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
