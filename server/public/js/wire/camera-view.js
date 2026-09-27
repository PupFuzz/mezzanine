/**
 * THE CAMERA ON A PAGE — how a page shows row 15's camera (`./camera.js`): at once, or as a glide
 * towards it. `docs/design/FLOOR.md` Appendix B rows 15 and 16.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343, row 16). The floor page (`floor/main.js`, row 15)
 * wrote this inline; the lobby (`lobby/main.js`) glides the same camera to a plate and back to the
 * whole building, and two copies of *how a glide steps* would be two answers to where the viewer is
 * looking halfway through one.
 *
 * ⛔ THIS FILE DECIDES NOTHING, and nothing in it is exercised headlessly: it is the browser's frame
 * clock and nothing else. Where the camera goes and how long it may take — none at all under
 * `prefers-reduced-motion`, where it cuts — are the screen's (`glide_ms`); the steps between are
 * `camera.js`'s `between()`. A glide is the viewer's and not the fleet's, so it takes no § 6.2 row.
 */

import { between } from './camera.js';

/**
 * @param {function(object): void} apply puts one camera on the drawing — the page's `viewBox` or transform
 * @returns {{show: function(object): void, glideTo: function(object, object, number, function(): void=): void,
 *            current: function(object): object}}
 */
export function cameraView(apply) {
    let shown = null;
    let glide = null;

    /** Show a camera on the drawing, stopping any glide in flight. */
    function show(camera) {
        if (glide !== null) {
            cancelAnimationFrame(glide);
            glide = null;
        }

        shown = camera;
        apply(camera);
    }

    /** A glide of `ms` from `from` to `to`, or a cut when it is none; `done` runs once `to` is shown. */
    function glideTo(from, to, ms, done = () => {}) {
        show(from);

        if (ms === 0) {
            show(to);
            done();

            return;
        }

        const start = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - start) / ms);

            shown = between(from, to, t);
            apply(shown);
            glide = t < 1 ? requestAnimationFrame(step) : null;

            if (glide === null) {
                done();
            }
        };

        glide = requestAnimationFrame(step);
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
