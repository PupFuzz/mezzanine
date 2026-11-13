/**
 * THE 3-D FLOOR's CLOCK — which still or loop frame each character, its marks and its screen show at an instant
 * (`docs/design/plan-13033-stage2-3d-floor.md` § 3.5's clock; `docs/design/FLOOR.md` § 6.3's timer bullet is the rule).
 * Pure: no DOM, no Three. `floor3d/body-paint.js` and `floor3d/room-paint.js` ask it on every drawn frame and set what it
 * answers; `tests/Feature/Floor/clip-clock-probe.mjs` drives it under node.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE TIME IS SET, NEVER ACCUMULATED. Every answer is a function of the time asked about and nothing the clock
 * remembers: a held loop's time is `since` — the ms since the paint that drew its held rendering, because a held
 * rendering carries no elapsed time and the 2-D painter's loop restarts on every paint — and a row in flight's is its
 * effect's `elapsed_ms` plus the ms since the paint. So a long task between two frames moves nothing the scene did not:
 * the frame after it shows the frame the time names.
 *
 * ⛔ A HELD LOOP STEPS ONLY WHILE ITS RENDERING SAYS `motion` (and so carries a `frame_interval_ms`): under reduced motion,
 * a dimmed or dark treatment or a stilled floor `heldRendering()` answers `motion: false`, and the form is its still at
 * every instant — whatever the row's class. Its frame is `(⌊since ÷ frame_interval_ms⌋ + loopOffset(key, length)) mod
 * length`, the character tree's own per-key offset over the loop's length, so two characters in one state step apart as
 * the 2-D floor's do; `length` is the 3-D loop's, which need not equal the 2-D frame count.
 *
 * ⛔ WHAT A FORM IS, PER ROW (`HELD_FORMS`): the clip of `creatures-3d.js`'s `CLIPS` the body steps (A3, A4, A23), the
 * eyes' blink (A3, A4: shut on the last frame of each `BLINK_LOOP`), the sleeper's z's and a state marker lit by the
 * character tree's pulse (`zLevels()`, `markerLevel()`, over `SLEEP_LOOP` — A6; A7, A9), and the monitor's replay
 * marker (A15, over `REPLAY_LOOP`). A row it does not name (A8's head in hands) is its pose, still.
 */

/** The eyes' blink, in loop frames: shut on the last of every this many (§ 6.2 A3/A4's *blink*; the seat's choice). */
export const BLINK_LOOP = 8;

/** The replay marker's sweep across the monitor (§ 6.2 A15), in loop frames: one position a frame (the seat's choice). */
export const REPLAY_LOOP = 8;

/** Each held row's 3-D form: what steps while it moves. */
export const HELD_FORMS = Object.freeze({
    A3: Object.freeze({ clip: 'work', blink: true }),
    A4: Object.freeze({ clip: 'think', blink: true }),
    A6: Object.freeze({ mark: 'zs' }),
    A7: Object.freeze({ mark: '!' }),
    A9: Object.freeze({ mark: '?' }),
    A15: Object.freeze({ screen: 'replay' }),
    A23: Object.freeze({ clip: 'intern-type' }),
});

const gcd = (a, b) => (b === 0 ? a : gcd(b, a % b));
const lcm = (a, b) => (a * b) / gcd(a, b);

/** A form's loop length, in loop frames: the least that holds every part of it whole. */
export function loopLength(characters, form) {
    let n = 1;

    if (form.clip !== undefined) {
        n = lcm(n, characters.CLIPS[form.clip].length);
    }

    if (form.blink) {
        n = lcm(n, BLINK_LOOP);
    }

    if (form.mark !== undefined) {
        n = lcm(n, characters.SLEEP_LOOP);
    }

    if (form.screen !== undefined) {
        n = lcm(n, REPLAY_LOOP);
    }

    return n;
}

/** The parts of `form` at loop frame `f` (`null` for the still): the pose, the blink, the marks' levels, the screen's. */
function partsAt(characters, form, pose, f) {
    const still = f === null;
    const clip = form?.clip === undefined ? null : characters.CLIPS[form.clip];
    const mark = form?.mark ?? null;

    return {
        pose: clip === null ? pose : clip[still ? 0 : f % clip.length],
        blink: form?.blink === true && !still && f % BLINK_LOOP === BLINK_LOOP - 1,
        // The still lights every z and the marker at full brightness — § 6.2's reduced cells for A6, A7 and A9.
        marks: mark === null ? null : {
            kind: mark,
            levels: mark === 'zs'
                ? (still ? characters.zLevels(0).map(() => 3) : characters.zLevels(f % characters.SLEEP_LOOP))
                : [still ? 3 : characters.markerLevel(f % characters.SLEEP_LOOP)],
        },
        screen: form?.screen === undefined ? null : { kind: form.screen, frame: still ? null : f % REPLAY_LOOP },
    };
}

/**
 * A seated character's form `since` ms after the paint that drew it.
 *
 * @param {object} characters the character tree (`CLIPS`, `POSES`, `SLEEP_LOOP`, `zLevels`, `markerLevel`, `loopOffset`)
 * @param {{key: string, pose: string, held: object|null}} body its seat or intern key, the pose the world gives it, and
 *        its held rendering (`heldRendering()`'s `{ animation_id, motion, form, frame_interval_ms }`, or `null`)
 * @param {number} since ms since the paint
 * @returns {{pose: string, blink: boolean, marks: object|null, screen: object|null, frame: number|null, next: number|null}}
 *          `frame` the loop frame drawn (`null` the still) and `next` the ms until the next step (`null`: nothing steps)
 */
export function heldAt(characters, { key, pose, held }, since) {
    const form = held === null ? undefined : HELD_FORMS[held.animation_id];
    const base = characters.POSES[pose] !== undefined ? pose : 'stand';
    const moving = form !== undefined && held.motion === true && typeof held.frame_interval_ms === 'number';

    if (!moving) {
        return { ...partsAt(characters, form, base, null), frame: null, next: null };
    }

    const length = loopLength(characters, form);
    const ms = held.frame_interval_ms;
    const frame = (Math.floor(since / ms) + characters.loopOffset(key, length)) % length;

    return { ...partsAt(characters, form, base, frame), frame, next: length > 1 ? ms - (since % ms) : null };
}

/**
 * A row in flight's time, ms into its effect, `since` ms after the paint that drew it — and whether it is still running.
 *
 * @param {{elapsed_ms?: number, frames: number, frame_interval_ms: number}} fx
 * @param {number} span the effect's whole length, ms (a walk's legs; a bird's flight, perch and rest)
 * @param {number} since
 * @returns {{t: number, running: boolean}}
 */
export function inFlightAt(fx, span, since) {
    const t = (fx.elapsed_ms ?? 0) + since;

    return { t, running: fx.frames > 0 && t < span };
}

/**
 * A walker's pose at `t` ms into its walk: the walk clip stepping at its frame rate while it runs, and its standing frame
 * (the clip's first) once it has ended — never a pose between two.
 */
export function walkAt(characters, fx, t, running) {
    const walk = characters.CLIPS.walk;

    return walk[running ? Math.floor(t / fx.frame_interval_ms) % walk.length : 0];
}
