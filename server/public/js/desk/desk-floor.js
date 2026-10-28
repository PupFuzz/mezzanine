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
 *     settled event, and the floor page (`floor/main.js`, step 8), through the floor screen.
 *   · the 1 s TICK is `wire/age-readout.js`'s `startAgeTicker` — its first shipped caller, rather
 *     than a second ticker — and it re-renders "every age readout, and nothing else". It reads
 *     the seats as the last `render()` left them, never the protocol's live map: a delta applied
 *     since then is a state change, and a state change drawn by the tick would be a desk moved
 *     by the clock with no held-render row behind it (§ 6.3's second forbidden form).
 *
 * ⛔ EVERY § 6.2 ROW GOES THROUGH `wire/animation-set.js`, AND THIS FILE STARTS NONE ITSELF
 * (card#7341 step 6). Step 5 entered and left each desk's held render here because the desk floor
 * was the only renderer there was; the SET is what owns a § 6.2 row, so what this file does now is
 * hand it the journal the protocol drained, the frame, the seats the frame was derived from, and
 * what § 11's precedence compares with the previous render — and hold no episode state of its own.
 * A second path into the log would be a second implementation of the one record AT-D3-1 reads.
 *
 * ⛔ A REFUSAL FROM THE LOG IS NOT CAUGHT. § 11 leaves "what a renderer does with a refusal" to the
 * step that builds one. A refusal here means this module asked the log for something impossible —
 * an `at` with no corrected clock, the exit of an episode it never entered — which is a defect in
 * this file, and swallowing it would hide from the one instrument that can see it the very row
 * the honesty tests exist to read. It throws, and the render that asked for it does not complete.
 *
 * ⚠ WHO CALLS IT ON A PAGE: the floor page (`floor/main.js`, Appendix B row 8), through
 * `floor/floor-screen.js`'s `renderWith()`. The client protocol exposes no apply hook, so that page
 * asks for a render after each event that can change what the protocol holds; this module owns
 * what a desk looks like once it has.
 */

import { floorAgeReadouts, startAgeTicker } from '../wire/age-readout.js';
import { AnimationSet } from '../wire/animation-set.js';
import { correctedNowMs } from '../wire/duration.js';
import { deskModel, unrecognisedValues } from './desk-render.js';
import { advanceSlots, rebuildSlots } from './intern-slots.js';

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
     * key → the seat's interns' slots, `call_id → slot` (FLOOR.md § 6.2's *Interns ride the elevator too*, item 13;
     * `desk/intern-slots.js`): kept across renders, advanced by each applied delta and rebuilt by each non-delta replace.
     */
    #slots = new Map();

    /** Whether the last `render()` drew the floor stilled (§ 9 F6) — § 11's precedence compares with it. */
    #stilled = false;

    /**
     * § 9 F9's dedup, `field value` pairs already written into the record — "the client's event log
     * records it once per distinct value" (§ 2.1 row 7: client narration). Bounded by the values the
     * server can send, not by time.
     */
    #unrecognisedSeen = new Set();

    /**
     * @param {object} source  a `FleetClient` — its `seats`, `clockOffsetMs`, `readStatus(key)`,
     *                         `stampOf(key, member)` and `takeWire()`
     * @param {{now: function(): number}} clock  the browser's own clock, corrected here by the
     *                         protocol's offset before any row is dated with it
     * @param {object} log     `wire/animation-log.js`'s `createAnimationLog()`
     * @param {object} [options] `{ reduce }` (§ 6.4), handed to the desk render and to the
     *                         animation set
     */
    constructor(source, clock, log, options = {}) {
        this.#source = source;
        this.#clock = clock;
        this.#set = new AnimationSet(log, { reduce: options.reduce === true });
        this.#options = options;
    }

    /**
     * The animation set this floor writes every § 6.2 row through.
     *
     * ⛔ IT IS EXPOSED SO THAT A SCREEN ABOVE THIS ONE WRITES THROUGH THE SAME SET, never so that a
     * caller may reach past it: `floor/floor-screen.js` draws A16 and the three coordination rows,
     * and a set of its own would be a second way into the one record AT-D3-1 reads — which is the
     * defect step 6 removed between this file and the log.
     */
    get set() {
        return this.#set;
    }

    /**
     * Whether the last `render()` drew the floor stilled (§ 9 F6) — read by a screen above this one
     * for what it draws beside the desks (`floor/floor-screen.js`'s effects in flight), so the floor is
     * stilled by one derivation and not by a second reading of the feed.
     */
    get stilled() {
        return this.#stilled;
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
     * § 2.5's apply path for a screen that draws DESKS AND NOTHING ELSE: drain the journal and
     * draw over it.
     *
     * ⛔ THE DRAIN BELONGS TO WHOEVER OWNS THE APPLY, which is the SCREEN. This entry is the
     * desk-only screen's — the one step 5 built, when a floor of desks was the whole of what a
     * renderer was. `floor/floor-screen.js` (step 7) draws desks BESIDE a room, a composition and a
     * coordination line from the same journal, so it drains once itself and calls `renderWith()`
     * below. The two are mutually exclusive by construction: a screen that owns the drain never
     * calls this, so there is one drain per apply on either path and never two.
     */
    render() {
        return this.renderWith(this.#source.takeWire());
    }

    /**
     * § 2.5's apply path over a journal the CALLER drained: re-read the held seats, re-derive every
     * desk, and hand the animation set what the protocol applied and what it holds having applied
     * it. Returns the frame.
     *
     * The journal describes the same instant the seats do: what the protocol just handled, and what
     * it holds having handled it. The 1 s tick calls `view()` and drains nothing — a tick is not an
     * apply (§ 2.5).
     */
    renderWith(journal) {
        this.#held = this.#source.seats;

        // § 9 F6: once any read returned 401 the floor beneath the prompt is still, and dimmed.
        const stilled = (this.#source.feed?.signed_out ?? null) !== null;
        const before = { facts: this.#facts, stilled: this.#stilled };

        this.#facts = new Map([...this.#held.keys()].map((k) => [k, {
            missing: this.#source.readStatus(k).missing,
            derivation_stamp: this.#source.stampOf(k, 'derivation'),
            stilled,
        }]));
        this.#stilled = stilled;

        this.#recordUnrecognised();

        const { interns, flips } = this.#internMoves(journal);
        const offset = this.#source.clockOffsetMs;
        const browserNow = this.#clock.now();
        const readouts = floorAgeReadouts(this.#held, offset, browserNow);
        const frame = { ...this.view(readouts), interns, flips };
        const at = correctedNowMs(offset, browserNow);

        // The `edge` rows first — each is caused by one of the messages in the journal — and the
        // `held` transitions after, because they are consequences of the state those messages left.
        this.#set.edges(journal, at);
        // A seat the protocol REMOVED in this journal (§ 3.5's announcement, § 2.3 row 4's backstop)
        // has no held object left, so the version that ended its desk's held render is the removal's.
        this.#set.held(frame.desks, this.#held, at, new Map(journal
            .filter((entry) => entry.t === 'seat.removed')
            .map((entry) => [`${entry.install_id}/${entry.seat_id}`, entry.cause])), this.#conditions(frame, readouts, before));

        return frame;
    }

    /**
     * The interns' slots over one drained journal (item 13), and what each applied delta did to a seat's interns:
     * `{ key, cause, gained, lost, exact }` per delta whose `changed[]` names `subagents`, in journal order — the scene's
     * input for A10's walks (items 13–16, 19). A non-delta entry applied for a seat REBUILDS its map; a seat with no map
     * yet is rebuilt from what is held; a seat no longer held drops its map. The tick never reaches this.
     *
     * `flips` is A24's input (card#12451): per such delta, the interns present on both sides whose `activity_state`
     * changed, each with the state it left and the one it took — `{ key, cause, calls: [{ call_id, from, to }] }` — which
     * the scene fades the laptop of, from where it was to where the delta left it.
     */
    #internMoves(journal) {
        const moves = [];
        const flips = [];
        const keyOf = (entry) => `${entry.install_id}/${entry.seat_id}`;

        for (const entry of journal) {
            if (entry.outcome !== 'applied' || typeof entry.seat_id !== 'string') {
                continue;
            }

            const key = keyOf(entry);

            if (entry.t !== 'seat.delta') {
                this.#slots.delete(key);

                continue;
            }

            if (!(entry.changed ?? []).includes('subagents')) {
                continue;
            }

            const prev = this.#slots.get(key) ?? rebuildSlots(entry.before);
            const moved = advanceSlots(prev, entry.before, entry.after);

            this.#slots.set(key, moved.slots);

            if (moved.gained.length > 0 || moved.lost.length > 0) {
                moves.push(Object.freeze({ key, cause: entry.state_version, gained: moved.gained, lost: moved.lost, exact: moved.exact }));
            }

            const was = new Map((entry.before?.subagents ?? []).map((s) => [s.call_id, s.activity_state]));
            const flipped = (entry.after?.subagents ?? []).filter((s) => was.has(s.call_id) && was.get(s.call_id) !== s.activity_state)
                .map((s) => Object.freeze({ call_id: s.call_id, from: was.get(s.call_id), to: s.activity_state }));

            if (flipped.length > 0) {
                flips.push(Object.freeze({ key, cause: entry.state_version, calls: Object.freeze(flipped) }));
            }
        }

        for (const key of [...this.#slots.keys()]) {
            if (!this.#held.has(key)) {
                this.#slots.delete(key);
            }
        }

        for (const [key, seat] of this.#held) {
            if (!this.#slots.has(key)) {
                this.#slots.set(key, rebuildSlots(seat));
            }
        }

        return { interns: Object.freeze(moves), flips: Object.freeze(flips) };
    }

    /**
     * What § 11's precedence compares with the previous render, per seat held now — the animation
     * set's `held()` parameter of that name. The set owns the precedence; this owns the desk render,
     * so this is where the object held now is drawn with § 2.3 row 5's condition and the stilled
     * floor at the values the previous render had (`prior`). Where neither changed, that drawing is
     * the frame's own. A seat the previous render did not hold has no episode open, so nothing reads
     * its entry; its own row-5 state stands in for the one it never had.
     */
    #conditions(frame, readouts, before) {
        const conditions = new Map();

        for (const [k, seat] of this.#held) {
            const now = this.#facts.get(k);
            const wasMissing = before.facts.get(k)?.missing ?? now.missing;
            const changed = wasMissing !== now.missing || before.stilled !== now.stilled;
            const model = changed
                ? deskModel(seat, readouts.desks[k], { ...now, missing: wasMissing, stilled: before.stilled, slots: this.#slots.get(k) ?? null }, this.#options)
                : frame.desks[k];

            conditions.set(k, {
                prior: model?.held ?? null,
                // § 6.2 A23 (card#12448): the side table's held rendering under the same prior conditions.
                prior_interns: model?.interns_held ?? null,
                unconfirmed: now.missing && !wasMissing,
                stilled: now.stilled && !before.stilled,
            });
        }

        return conditions;
    }

    /**
     * § 9 F9: one line in § 5.5's record per DISTINCT unrecognised value, written through the
     * protocol's own door (`record()`), which is the one capped, stamped, newest-first path every
     * line takes.
     */
    #recordUnrecognised() {
        for (const [k, seat] of this.#held) {
            for (const { field, value } of unrecognisedValues(seat)) {
                const id = `${field} ${value}`;

                if (!this.#unrecognisedSeen.has(id)) {
                    this.#unrecognisedSeen.add(id);
                    this.#source.record(`unrecognised ${field} value "${value}" (first seen on ${k})`);
                }
            }
        }
    }

    /**
     * Every desk over one set of age readouts, from the seats and facts the last `render()` read.
     * Pure: this is what the 1 s tick calls, and it enters and leaves nothing.
     */
    view(readouts) {
        const desks = {};

        for (const [k, seat] of this.#held) {
            const model = deskModel(seat, readouts.desks[k], { ...this.#facts.get(k), slots: this.#slots.get(k) ?? null }, this.#options);

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
 * @param {object} [options] `{ reduce }`
 */
export function startDeskFloor(source, clock, timers, log, draw, options = {}) {
    const floor = new DeskFloor(source, clock, log, options);
    const ticker = startAgeTicker(floor, clock, timers, (readouts) => draw(floor.view(readouts), 'tick'));

    return {
        render: () => draw(floor.render(), 'apply'),
        stop: () => ticker.stop(),
    };
}
