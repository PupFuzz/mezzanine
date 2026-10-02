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
 * ⛔ THIS FILE DECIDES WHERE NOTHING GOES: it is the browser's frame clock and nothing else. Where the
 * camera goes and how long it may take — none at all under `prefers-reduced-motion`, where it cuts —
 * are the screen's (`glide_ms`); the steps between are `camera.js`'s `between()`. A glide is the
 * viewer's and not the fleet's, so it takes no § 6.2 row.
 *
 * ⛔ A COMMITTED GLIDE IS FINISHED, NEVER ABANDONED (row 16, card#7343 r1 ruling: the click commits
 * the ride). The floor's fit and the lobby's whole-building glide are uncommitted, and whatever
 * interrupts them — a wheel the camera takes, a pinch, a drag, a resize — stops them where they are. The lobby's ride is
 * committed: an interruption cuts it to the plate and runs its arrival, so a ride the viewer clicked
 * always arrives. It is driven under `node` with a stubbed frame clock by
 * `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { between } from './camera.js';

/**
 * @param {function(object): void} apply puts one camera on the drawing — the page's `viewBox` or transform
 * @returns {{show: function(object): void,
 *            glideTo: function(object, object, number, function(): void=, {commit?: boolean}=): void,
 *            current: function(object): object}}
 */
export function cameraView(apply) {
    let shown = null;

    /** The glide in flight — its frame request, where it is going, what runs there, and whether it is committed. */
    let glide = null;

    /**
     * Stop the glide in flight, if any. An uncommitted glide simply stops where it is. A COMMITTED one is
     * not stopped but FINISHED: its destination is shown at once and its `done` runs — row 16's ride,
     * where the click commits (card#7343 r1 ruling): an interrupted ride cuts to the plate and arrives.
     *
     * @returns {boolean} whether a committed glide was finished — the interruption then does nothing else
     */
    function interrupt() {
        if (glide === null) {
            return false;
        }

        const stopped = glide;

        glide = null;
        cancelAnimationFrame(stopped.frame);

        if (!stopped.commit) {
            return false;
        }

        shown = stopped.to;
        apply(stopped.to);
        stopped.done();

        return true;
    }

    /**
     * Show a camera on the drawing, stopping any glide in flight — or, when that glide is committed,
     * finishing it instead and showing nothing else.
     */
    function show(camera) {
        if (interrupt()) {
            return;
        }

        shown = camera;
        apply(camera);
    }

    /**
     * A glide of `ms` from `from` to `to`, or a cut when it is none; `done` runs once `to` is shown. A
     * `commit`ted glide cannot be abandoned: whatever interrupts it — a `show()` or another `glideTo()` —
     * cuts it to `to` and runs `done` (see `interrupt()`). A glide asked for while a committed one is in
     * flight finishes that one and does not start.
     */
    function glideTo(from, to, ms, done = () => {}, { commit = false } = {}) {
        if (interrupt()) {
            return;
        }

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

            if (t < 1) {
                glide.frame = requestAnimationFrame(step);

                return;
            }

            glide = null;
            done();
        };

        glide = { frame: requestAnimationFrame(step), to, done, commit };
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
