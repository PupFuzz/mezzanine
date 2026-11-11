/**
 * THE BUILDING VIEW's CAMERA ACTS — the act set the house wires (`wire/camera-gestures.js`, `wire/camera-keys.js`) are
 * given on `/building`, in place of a 2-D screen's: every act answers the VIEW the page is to show (`{ pose, azimuth }`,
 * `building/stack-model.js`'s), and the page's `show` turns a changed pose into a glide. Appendix B row 47 (card#13033
 * slice 3; `docs/design/plan-13033-3d-view.md` § 3.3).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT IS A MODEL: no document, no timer and no clock. A gesture's time is its INPUT EVENT's own `timeStamp`, which the
 * wire hands every wheel, Ctrl+wheel and pinch act (`wire/camera-gestures.js`), and whether a glide is in flight is handed
 * in (`gliding`, the view's `gliding()`), so `node` drives it under a stub clock
 * (`tests/Feature/Building/building-acts-probe.mjs`).
 *
 * ⛔ THE GAP IS THE INPUT's, NEVER THE HANDLER's (the seat's ruling of 2026-11-10, card#13033; plan § 3.3 item 3): a long
 * task on the page — a render of every floor's picture — delays the handler and never the user's input, which the
 * platform queues with its own timestamps. A gap read when the handler runs would see the long task as the user pausing
 * and re-arm a stream mid-gesture; read off the events, `GESTURE_GAP_MS` is a property of the input stream alone.
 *
 * ⛔ ONE GESTURE, ONE SNAP. A wheel's pixel-mode scroll, a Ctrl+wheel's (× `PINCH_GAIN`) and a pinch's `log(factor)`
 * fill a bucket, and at one notch the bucket fires the snap's *in* or *out* and empties. A line- or page-mode wheel event
 * is a notch of its own and fires alone (§ 12 item 22). From a firing the wheel and the pinch are MUTED until the gesture
 * has been quiet for `GESTURE_GAP_MS` — measured from the LAST event, not the firing one — so a trackpad's stream and its
 * inertia snap once, and a stream that outlives the glide cannot snap again from the new pose; while muted, or while a
 * glide is in flight, an act answers the resting view and changes nothing. Keys and clicks are one gesture each and need
 * no timer.
 *
 * ⛔ THE RESTING VIEW IS SET IN TWO PLACES: `arrived()`, which the page calls with the view it rests at — on a glide's or a
 * cut's arrival, and for a view at the resting pose it shows at once (*Fit* from a turned overview) — and a turn
 * (`drag()` at the overview), which never arrives. Every snap is aimed from it, and it is the page's one record of where
 * the view rests (`building/main.js`).
 */

import { NOTCH_PX, PINCH_GAIN, turnOf } from '../wire/camera.js';
import { liveView, snap } from './stack-model.js';

/** How long a gesture must be quiet before the next event starts a new one (§ 12 item 14, a seat ruling). */
export const GESTURE_GAP_MS = 300;

/** A pinch fires at this spread ratio either way (a quarter's spread). */
export const PINCH_SNAP = 1.25;

/** The view the page opens at — the whole building, from the front. */
export const HOME_VIEW = Object.freeze({ pose: 'overview', azimuth: 0 });

/**
 * One pixel-mode scroll added to a wheel bucket: `fire` is `'in'` at `−NOTCH_PX`, `'out'` at `+NOTCH_PX` (a negative
 * `deltaY` is in, as the 2-D wire's zoom reads it), and the bucket empties when it fires.
 *
 * @returns {{bucket: number, fire: 'in'|'out'|null}}
 */
export function wheelBucket(bucket, scroll) {
    const next = bucket + scroll;

    if (next <= -NOTCH_PX) {
        return { bucket: 0, fire: 'in' };
    }

    if (next >= NOTCH_PX) {
        return { bucket: 0, fire: 'out' };
    }

    return { bucket: next, fire: null };
}

/**
 * One pinch step added to the pinch bucket, over `log(factor)`: `'in'` once the spread has grown by `PINCH_SNAP`,
 * `'out'` once it has shrunk by it, emptying when it fires.
 *
 * @returns {{bucket: number, fire: 'in'|'out'|null}}
 */
export function pinchBucket(bucket, factor) {
    const next = bucket + Math.log(factor);
    const notch = Math.log(PINCH_SNAP);

    if (next >= notch) {
        return { bucket: 0, fire: 'in' };
    }

    if (next <= -notch) {
        return { bucket: 0, fire: 'out' };
    }

    return { bucket: next, fire: null };
}

/**
 * The act set, over the page's live stack and stage.
 *
 * @param {{stack: function(): (object|null), gliding: function(): boolean}} page `stack` the
 *        stack as it stands — `null` before any floor has an extent or while the stage has no size — whose `surface` is
 *        the stage every act reads; `gliding` whether a glide is in flight
 */
export function buildingActs({ stack, gliding }) {
    let resting = HOME_VIEW;
    let wheel = 0;
    let pinched = 0;
    let muted = false;
    let last = -Infinity;

    /** The snap's answer for an intent — the resting view while there is no stack to aim at. */
    const snapped = (intent) => {
        const live = stack();

        return live === null ? resting : snap(resting, intent, live, live.surface);
    };

    /**
     * One wheel, Ctrl+wheel or pinch step at its input event's time `at`: the gap, the mute, then `step()`'s firing
     * (`'in'`, `'out'` or `null`) as the snap's intent — in over `point` — answering the view to show.
     */
    const gesture = (step, point, at) => {
        const t = at;

        if (t - last >= GESTURE_GAP_MS) {
            muted = false;
            wheel = 0;
            pinched = 0;
        }

        last = t;

        if (gliding() || muted) {
            return resting;
        }

        const fire = step();

        if (fire === null) {
            return resting;
        }

        muted = true;

        return snapped(fire === 'in' ? { act: 'in', point } : { act: 'out' });
    };

    /** A wheel's deltas: a pixel-mode scroll through the bucket, a line- or page-mode event a notch of its own. */
    const wheelAct = (point, deltaY, deltaMode, gain, at) => ({
        camera: gesture(() => {
            if (deltaMode === 1 || deltaMode === 2) {
                return deltaY < 0 ? 'in' : deltaY > 0 ? 'out' : null;
            }

            const next = wheelBucket(wheel, deltaY * gain);

            wheel = next.bucket;

            return next.fire;
        }, point, at),
        consumed: true,
    });

    return {
        /**
         * A plain wheel event, with the point under it and the event's `timeStamp`: always consumed — the wheel over the
         * stage never scrolls the page.
         */
        pan({ deltaY = 0, deltaMode = 0 }, point, at) {
            return wheelAct(point, deltaY, deltaMode, 1, at);
        },

        /** A Ctrl+wheel — a Chromium trackpad's pinch: the same bucket, the scroll × `PINCH_GAIN`, at the event's `timeStamp`. */
        zoom(point, { deltaY, deltaMode = 0 }, at) {
            return wheelAct(point, deltaY, deltaMode, PINCH_GAIN, at);
        },

        /** A touch pinch's or Safari's gesture's step at its event's `timeStamp`: the pinch bucket, in over the fingers' midpoint `to`. */
        pinch(from, to, factor, at) {
            return gesture(() => {
                const next = pinchBucket(pinched, factor);

                pinched = next.bucket;

                return next.fire;
            }, to, at);
        },

        /**
         * A press that moved, or an arrow key: at the overview the building turns by `dx` — 2π over the stage's width, so
         * a drag across the stage is one turn — and the turn is the resting view; `dy` is nothing, and at a focus pose
         * nothing moves. The resting view is read through `liveView()` first, as every snap reads it: a focused floor the
         * layout dropped is the overview (§ 12 item 10), which a drag turns. The wires take no drag while the camera
         * frames nothing, so there is a stack here.
         */
        drag(dx) {
            const live = stack();

            resting = liveView(resting, live);

            if (resting.pose !== 'overview') {
                return resting;
            }

            const turned = resting.azimuth - turnOf(dx, live.surface.width);

            resting = Object.freeze({ pose: 'overview', azimuth: Math.atan2(Math.sin(turned), Math.cos(turned)) });

            return resting;
        },

        /** `+`/`−` and the two buttons: the snap table's **+** and **−** columns. */
        zoomStep(notches) {
            return snapped(notches > 0 ? { act: 'plus' } : { act: 'out' });
        },

        /** The resting view with the stack's bounds — `null` before any floor has an extent, which every wire gate reads. */
        camera() {
            return Object.freeze({ ...resting, bounds: stack()?.bounds ?? null });
        },

        /** The page rests at `view`: the resting view every later snap is aimed from. */
        arrived(view) {
            resting = view;
        },
    };
}
