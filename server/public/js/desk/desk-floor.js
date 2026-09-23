/**
 * The FLOOR OF DESKS — `desk/desk-render.js` run over every seat the client protocol holds, on
 * `docs/design/FLOOR.md § 2.5`'s two re-render triggers for a desk, with every § 6.2 row the
 * apply path fires recorded in § 11's animation log through `wire/animation-set.js`.
 * Appendix B row 5, card#7341 step 5; the set it hands its frames to is row 6's, step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ TWO TRIGGERS, AND THEY RE-RENDER DIFFERENT THINGS (§ 2.5).
 *   · `render()` is the APPLY path — a snapshot, a delta, a fetch the protocol has just applied.
 *     It re-reads the held seats, re-derives every desk, and is the ONLY caller of the animation
 *     set's own entries. Its caller is whoever applied something: today the harness after each
 *     settled event, and from step 8 the page that owns the client (below).
 *   · the 1 s TICK is `wire/age-readout.js`'s `startAgeTicker` — its first shipped caller, rather
 *     than a second ticker — and it re-renders "every age readout, and nothing else". It reads
 *     the seats as the last `render()` left them, never the protocol's live map: a delta applied
 *     since then is a state change, and a state change drawn by the tick would be a desk moved
 *     by the clock with no held-render row behind it (§ 6.3's second forbidden form).
 *
 * ⛔ EVERY § 6.2 ROW GOES THROUGH `wire/animation-set.js`, AND THIS FILE STARTS NONE ITSELF
 * (card#7341 step 6). Step 5 entered and left each desk's held render here because the desk floor
 * was the only renderer there was; the SET is what owns a § 6.2 row, so what this file does now is
 * hand it the journal the protocol drained, the frame, and the seats the frame was derived from —
 * and hold no episode state of its own. A second path into the log would be a second
 * implementation of the one record AT-D3-1 reads.
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
import { AnimationSet } from '../wire/animation-set.js';
import { correctedNowMs } from '../wire/duration.js';
import { deskModel } from './desk-render.js';

export class DeskFloor {
    #source;

    #clock;

    #set;

    #options;

    /** The seats as the last `render()` read them — what the tick draws ages over. */
    #held = new Map();

    /** key → what only the protocol knows about the seat, read at the last `render()`. */
    #facts = new Map();

    /**
     * @param {object} source  a `FleetClient` — its `seats`, `clockOffsetMs`, `readStatus(key)`,
     *                         `stampOf(key, member)` and `takeWire()`
     * @param {{now: function(): number}} clock  the browser's own clock, corrected here by the
     *                         protocol's offset before any row is dated with it
     * @param {object} log     `wire/animation-log.js`'s `createAnimationLog()`
     * @param {object} [options] `{ ref_bases }`, handed to the thought bubble, and `reduce`
     *                         (§ 6.4), handed to the desk render and to the animation set
     */
    constructor(source, clock, log, options = {}) {
        this.#source = source;
        this.#clock = clock;
        this.#set = new AnimationSet(log, { reduce: options.reduce === true });
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
     * § 2.5's apply path: re-read the held seats, re-derive every desk, and hand the animation
     * set what the protocol applied and what it holds having applied it. Returns the frame.
     */
    render() {
        // ⛔ THE JOURNAL IS DRAINED HERE AND NOWHERE ELSE, and it describes the same instant the
        // seats below do: what the protocol just handled, and what it holds having handled it.
        // The 1 s tick calls `view()` and drains nothing — a tick is not an apply (§ 2.5).
        const journal = this.#source.takeWire();

        this.#held = this.#source.seats;
        this.#facts = new Map([...this.#held.keys()].map((k) => [k, {
            missing: this.#source.readStatus(k).missing,
            derivation_stamp: this.#source.stampOf(k, 'derivation'),
        }]));

        const offset = this.#source.clockOffsetMs;
        const browserNow = this.#clock.now();
        const frame = this.view(floorAgeReadouts(this.#held, offset, browserNow));
        const at = correctedNowMs(offset, browserNow);

        // The `edge` rows first — each is caused by one of the messages in the journal — and the
        // `held` transitions after, because they are consequences of the state those messages left.
        this.#set.edges(journal, at);
        this.#set.held(frame.desks, this.#held, at);

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
}

/**
 * The floor, started: the 1 s tick running over it and `render()` handed back for the apply path.
 *
 * @param {object} source   a `FleetClient`
 * @param {{now: function(): number}} clock
 * @param {{setInterval: Function, clearInterval: Function}} timers  injected, as the ticker's are
 * @param {object} log      the animation log every § 6.2 row is recorded in
 * @param {function(object, string): void} draw  receives each frame and its trigger, `apply` or `tick`
 * @param {object} [options] `{ ref_bases, reduce }`
 */
export function startDeskFloor(source, clock, timers, log, draw, options = {}) {
    const floor = new DeskFloor(source, clock, log, options);
    const ticker = startAgeTicker(floor, clock, timers, (readouts) => draw(floor.view(readouts), 'tick'));

    return {
        render: () => draw(floor.render(), 'apply'),
        stop: () => ticker.stop(),
    };
}
