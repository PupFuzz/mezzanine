/**
 * THE 3-D FLOOR's FRAME LOOP — the one place a frame of the 3-D floor is queued (`floor3d/floor-paint.js` draws, this
 * schedules). `docs/design/plan-13033-stage2-3d-floor.md` § 3.5's frame loop; `docs/design/FLOOR.md` § 6.3's timer
 * bullet is its rule.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE PENDING FRAME: `schedule()` queues a frame only when none is queued. The controls' `change` fires INSIDE a frame
 * (their update and the clamp's), so a frame that queued its successor directly would queue a second beside the one
 * that `change` woke — two chains, each doubling every tick. The browser leg's loop row (frames per display tick) holds it.
 *
 * ⛔ THE FRAME SAYS WHEN IT NEXT NEEDS ONE, AND NOTHING ELSE DOES: `draw(now)` answers `0` (the next display frame —
 * something is in flight), a delay in ms (a held loop's next step: one wake on a timer, never a frame a refresh), or
 * `null` (nothing moves: the loop stops, and `rested()` is told). A wake — a paint, a gesture, a resource ready — clears
 * a pending step's timer and queues a frame, which redraws everything, so a step and a wake never both draw.
 *
 * ⛔ THE CLOCK IS READ WHEN THE FRAME RUNS, never taken from the frame's start time: a step's timer wakes the frame AT the
 * step, and the frame's start can precede it, which would draw the step before it and wake again a moment later.
 */

/**
 * @param {Window} window
 * @param {function(number): (number|null)} draw one frame at `now` (ms, `performance.now()`); when it next needs one
 * @param {{rested?: function(): void}} [options] what is told when the loop stops
 * @returns {{wake: function(): void}}
 */
export function frameLoop(window, draw, { rested = () => {} } = {}) {
    let raf = null;
    let timer = null;

    const schedule = () => {
        raf ??= window.requestAnimationFrame(frame);
    };

    function frame() {
        const now = window.performance.now();

        raf = null;

        const next = draw(now);

        // A frame already queued — woken by a change during this one — redraws everything, so it stands in for a step's
        // timer: one pending wake, never a frame and a timer both.
        if (raf !== null || next === 0) {
            schedule();
        } else if (next !== null) {
            timer = window.setTimeout(() => {
                timer = null;
                wake();
            }, next);
        } else {
            rested();
        }
    }

    function wake() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }

        schedule();
    }

    return { wake };
}
