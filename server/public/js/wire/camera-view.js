/**
 * THE CAMERA ON A PAGE — how a page shows row 15's camera (`./camera.js`): at once, or as a glide
 * towards it. `docs/design/FLOOR.md` Appendix B rows 15, 16 and 24.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343, row 16). The floor page (`floor/main.js`, row 15)
 * wrote this inline; the lobby (`lobby/main.js`) glides the same camera to the elevator, the board and
 * back to the whole room, and two copies of *how a glide steps* would be two answers to where the viewer
 * is looking halfway through one.
 *
 * ⛔ THIS FILE DECIDES WHERE NOTHING GOES: it is the browser's frame clock and nothing else. Where the
 * camera goes and how long it may take — none at all under `prefers-reduced-motion`, where it cuts —
 * are the screen's (`glide_ms`); the steps between are `camera.js`'s `between()`. A glide is the
 * viewer's and not the fleet's, so it takes no § 6.2 row.
 *
 * ⛔ AN INTERRUPTED GLIDE STOPS WHERE IT IS: whatever interrupts it — a wheel the camera takes, a pinch, a
 * drag, a resize, another glide — shows its own camera from there. ⚠ Until card#12113 a glide could be
 * COMMITTED — finished rather than stopped, its arrival run — for the lobby's ride to a plate (card#7343
 * r1); the lobby's ride is now § 4.5's cab, which glides no camera, and the committed glide went with it.
 * It is driven under `node` with a stubbed frame clock by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { between } from './camera.js';

/**
 * @param {function(object): void} apply puts one camera on the drawing — the page's `viewBox` or transform
 * @returns {{show: function(object): void,
 *            glideTo: function(object, object, number): void,
 *            current: function(object): object}}
 */
export function cameraView(apply) {
    let shown = null;

    /** The glide in flight — its frame request — or `null`. */
    let glide = null;

    /** Stop the glide in flight, if any, where it is. */
    function interrupt() {
        if (glide !== null) {
            cancelAnimationFrame(glide.frame);
            glide = null;
        }
    }

    /** Show a camera on the drawing, stopping any glide in flight. */
    function show(camera) {
        interrupt();
        shown = camera;
        apply(camera);
    }

    /** A glide of `ms` from `from` to `to`, or a cut when it is none — stopping any glide in flight first. */
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
            apply(shown);

            if (t < 1) {
                glide.frame = requestAnimationFrame(step);

                return;
            }

            glide = null;
        };

        glide = { frame: requestAnimationFrame(step) };
    }

    /**
     * The camera a render should draw with: a glide in flight keeps its step, and otherwise the screen's
     * camera, which a render leaves where the viewer put it.
     */
    function current(camera) {
        shown = glide === null ? camera : shown;

        return shown;
    }

    return { show, glideTo, current };
}
