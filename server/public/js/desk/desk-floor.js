/**
 * The FLOOR OF DESKS — `desk/desk-render.js` run over every seat the client protocol holds, on
 * `docs/design/FLOOR.md § 2.5`'s two re-render triggers for a desk, with each desk's `held` render
 * recorded in § 11's animation log. Appendix B row 5, card#7341 step 5.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ TWO TRIGGERS, AND THEY RE-RENDER DIFFERENT THINGS (§ 2.5).
 *   · `render()` is the APPLY path — a snapshot, a delta, a fetch the protocol has just applied.
 *     It re-reads the held seats, re-derives every desk, and is the ONLY place a held render is
 *     entered or left in the log. Its caller is whoever applied something: today the harness
 *     after each settled event, and from step 8 the page that owns the client (below).
 *   · the 1 s TICK is `wire/age-readout.js`'s `startAgeTicker` — its first shipped caller, rather
 *     than a second ticker — and it re-renders "every age readout, and nothing else". It reads
 *     the seats as the last `render()` left them, never the protocol's live map: a delta applied
 *     since then is a state change, and a state change drawn by the tick would be a desk moved
 *     by the clock with no held-render row behind it (§ 6.3's second forbidden form).
 *
 * ⛔ A HELD RENDER IS ENTERED ON THE OBJECT THAT HOLDS IT AND LEFT ON THE OBJECT THAT ENDS IT (§ 11).
 * `cause` on an `entered` row is the `state_version` of the seat object the render is held by;
 * on a `left` row, the `state_version` of the first object in which the hold is false. An episode
 * is one continuous run of one render — so a change of the held row OR of whether its loop may run
 * (§ 7.3's treatment: a `fold_lag` badge arriving stops the loop) leaves one episode and enters
 * another, and `motion` on the `entered` row is the treatment's verdict: AT-D3-5 reads exactly
 * that row to see that a lagged desk was entered and drawn static.
 *
 * ⛔ A REFUSAL FROM THE LOG IS NOT CAUGHT. § 11 leaves "what a renderer does with a refusal" to the
 * step that builds one. A refusal here means this module asked the log for something impossible —
 * an `at` with no corrected clock, the exit of an episode it never entered — which is a defect in
 * this file, and swallowing it would hide from the one instrument that can see it the very row
 * the honesty tests exist to read. It throws, and the render that asked for it does not complete.
 *
 * ⚠ WHO CALLS `render()` ON A PAGE: NOBODY YET. The client protocol exposes no apply hook, and
 * Appendix B row 8 is where a page first constructs it ("No page constructs the client protocol
 * before this step"). That page owns choosing how it learns an apply happened; this module owns
 * what a desk looks like once it has.
 */

import { floorAgeReadouts, startAgeTicker } from '../wire/age-readout.js';
import { correctedNowMs } from '../wire/duration.js';
import { deskModel } from './desk-render.js';

export class DeskFloor {
    #source;

    #clock;

    #log;

    #options;

    /** The seats as the last `render()` read them — what the tick draws ages over. */
    #held = new Map();

    /** key → what only the protocol knows about the seat, read at the last `render()`. */
    #facts = new Map();

    /** key → the open held episode: `{ episode_id, animation_id, motion }`. */
    #episodes = new Map();

    /**
     * @param {object} source  a `FleetClient` — its `seats`, `clockOffsetMs`, `readStatus(key)`
     *                         and `stampOf(key, member)`
     * @param {{now: function(): number}} clock  the browser's own clock, corrected here by the
     *                         protocol's offset before any row is dated with it
     * @param {object} log     `wire/animation-log.js`'s `createAnimationLog()`
     * @param {object} [options] `{ ref_bases }`, handed to the thought bubble
     */
    constructor(source, clock, log, options = {}) {
        this.#source = source;
        this.#clock = clock;
        this.#log = log;
        this.#options = options;
    }

    /** The seats the last `render()` read — the age ticker's population (§ 2.5's tick row). */
    get seats() {
        return new Map(this.#held);
    }

    /** § 2.4's offset, read through from the protocol: the tick's ages are measured on it. */
    get clockOffsetMs() {
        return this.#source.clockOffsetMs;
    }

    /**
     * § 2.5's apply path: re-read the held seats, re-derive every desk, and enter or leave each
     * desk's held render in the log. Returns the frame.
     */
    render() {
        this.#held = this.#source.seats;
        this.#facts = new Map([...this.#held.keys()].map((k) => [k, {
            missing: this.#source.readStatus(k).missing,
            derivation_stamp: this.#source.stampOf(k, 'derivation'),
        }]));

        const offset = this.#source.clockOffsetMs;
        const browserNow = this.#clock.now();
        const frame = this.view(floorAgeReadouts(this.#held, offset, browserNow));

        this.#record(frame, correctedNowMs(offset, browserNow));

        return frame;
    }

    /**
     * Every desk over one set of age readouts, from the seats and facts the last `render()` read.
     * Pure: this is what the 1 s tick calls, and it enters and leaves nothing.
     */
    view(readouts) {
        const desks = {};

        for (const [k, seat] of this.#held) {
            const model = deskModel(seat, readouts.desks[k], this.#facts.get(k), this.#options);

            if (model !== null) {
                desks[k] = model;
            }
        }

        return { now_ms: readouts.now_ms, desks };
    }

    /** § 11's held rows, for every desk whose held render or treatment changed since the last apply. */
    #record(frame, nowMs) {
        for (const [k, open] of this.#episodes) {
            const want = frame.desks[k]?.held ?? null;

            if (want === null || want.animation_id !== open.animation_id || want.motion !== open.motion) {
                this.#log.leaveHeld(open.episode_id, { cause: this.#held.get(k)?.state_version ?? null, at: nowMs });
                this.#episodes.delete(k);
            }
        }

        for (const [k, desk] of Object.entries(frame.desks)) {
            if (desk.held === null || this.#episodes.has(k)) {
                continue;
            }

            const episodeId = this.#log.enterHeld({
                animation_id: desk.held.animation_id,
                cause: this.#held.get(k).state_version,
                install_id: desk.install_id,
                seat_id: desk.seat_id,
                motion: desk.held.motion,
                at: nowMs,
            });

            this.#episodes.set(k, { episode_id: episodeId, ...desk.held });
        }
    }
}

/**
 * The floor, started: the 1 s tick running over it and `render()` handed back for the apply path.
 *
 * @param {object} source   a `FleetClient`
 * @param {{now: function(): number}} clock
 * @param {{setInterval: Function, clearInterval: Function}} timers  injected, as the ticker's are
 * @param {object} log      the animation log every held render is recorded in
 * @param {function(object, string): void} draw  receives each frame and its trigger, `apply` or `tick`
 * @param {object} [options]
 */
export function startDeskFloor(source, clock, timers, log, draw, options = {}) {
    const floor = new DeskFloor(source, clock, log, options);
    const ticker = startAgeTicker(floor, clock, timers, (readouts) => draw(floor.view(readouts), 'tick'));

    return {
        render: () => draw(floor.render(), 'apply'),
        stop: () => ticker.stop(),
    };
}
