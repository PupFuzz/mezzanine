/**
 * THE EFFECTS IN FLIGHT — `docs/design/FLOOR.md § 6.2`'s walk note items 6 and 8 (card#9566): the one
 * holder of every multi-frame `edge` drawing across the renders that land while it is still running,
 * and the one place a walk is cancelled.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A WALK IS PRESENTATION, NEVER STATE. Nothing here writes the animation log, fires a row or
 * decides a desk's state: the rows were written at the apply by `wire/animation-set.js`, and this
 * module only keeps drawing what a render already logged — at its elapsed frame, with the geometry
 * of the render that wrote it — until its last frame, or until something touches its seat.
 *
 * ⛔ THE PAINTER REBUILDS THE DRAWING ON EVERY RENDER, so without this holder an effect lives only
 * until the next one: a heartbeat or any seat's delta cut A19's envelope and A20's ring short, and a
 * walk drawn the same way would be cut too. One holder, every multi-frame row (item 8).
 *
 * ⛔ AT MOST ONE WALK PER SEAT, AND A RENDER THAT TOUCHES THE SEAT CANCELS IT (item 6, card#12194). A
 * render touches seat S, with a walk in flight, iff its journal applied a NON-DELTA entry for S (a snapshot,
 * resync, insert or reconnect replacing S's object, or S's removal); its model draws S's desk on another SIDE of
 * § 7.1's *Desk* column than the walk delivered (staffed, empty or neither — `walkSide()`), or its rows carry an
 * A1 or A2 for S; S's § 2.3 row 5 condition or the floor's stilled condition changed; S's desk anchor differs from
 * the one the walk was computed against (the applying render's); the elevator's threshold moved; or the floor's
 * lattice key differs from the one the walk was routed on. A same-side delta — `working ↔ idle`, an action or a
 * context change under an A1, `stale → offline` under an A2 — leaves the walker on its way: under a walk the desk
 * draws every fact of the applied object but the character, and the walker IS that character. No walk starts from
 * a cancelled one, and a render whose journal fires two walk rows for one seat draws neither. The 1 s age tick and
 * the paint-only refresh are not renders and never reach `render()`.
 *
 * ⛔ AN INTERN WALKS TOO, ONE WALK PER INTERN (§ 6.2's *Interns ride the elevator too*, items 13–17; card#12450). An
 * intern's A10 walk is held under its own key, `<seat key>~<call_id>`, beside its seat's walk and never in its place. Every
 * clause above that touches the SEAT cancels its interns' walks with it — a non-delta entry for the seat, row 5's or the
 * stilled condition changing, the threshold or the lattice key — and the anchor clause is read against the intern's SLOT
 * foot, which a sibling's gain or loss never moves; the seat's own side and its A1/A2 rows do not touch an intern (the
 * chair emptying moves no intern). An arrival whose intern leaves the array while it is in flight is REPLACED by item 16's
 * turn-round when the loss walks, and is a plain cancel when it does not (item 15's cap). A24's laptop fade is held per
 * intern too, and a later fade replaces it from the opacity it reached.
 *
 * ⛔ THE DEPARTURES QUEUE AT THE ELEVATOR, AND THIS IS WHERE (item 9, card#12705). Each departure — A2's walk, an intern's
 * walk out — arrives with its queue part, drawn alone; on every render, after its cancels and admissions, the holder
 * gives each new one its waiting spot and re-times every departure in flight together (`#board()`): the leaves open for
 * one, every one waiting boards in turn, and they close after the last. It writes nothing and fires nothing: a re-timed
 * walk is the same row's drawing, re-timed by a delivered message that admitted or cancelled another walk.
 *
 * ⛔ THE DEPARTURES PRESS THE CALL PLATES ONE AT A TIME, AND THIS IS WHERE EACH WAITS ITS TURN (item 9, card#12747). Before
 * the queue's re-timing, each new departure that walks by a plate is given its turn there (`#call()`): it waits where it
 * set off until the walker before it has pressed and walked a walker's width away. The turn is given on the render that
 * admits it and never changes, so the walk to the plates is never re-timed.
 *
 * ⛔ RIDERS STAND APART IN THE CAB, AND THIS IS WHERE EACH STANDS (item 10, card#12744). After the queue's re-timing,
 * every walk in flight with a `cab` leg is given a rider spot (`#seat()`) — the lowest no other rider holds over its
 * cab leg, kept once that leg has begun — and its cab leg is stood there. It moves where a cab leg stands and never
 * when any leg is.
 *
 * ⛔ THE SNACK TRIPS ARE HELD HERE TOO, ONE PER WALKER (FLOOR.md § 6.6, card#12749), beside the walks and never in their
 * place: a trip is the drawing of § 6.2's HELD row A25, which no render's rows start, so it is admitted by `snack()` —
 * on every render, paint-only refresh and tick — at its seeded second, and only while the walker's hold permits it and
 * the strip reads *live*. The walk's cancel clauses end it on a render (a non-delta entry for the seat, which re-derives
 * it at its phase; row 5 or the stilled floor; the lattice key; the threshold; the anchor), an A2 or an A10 leave of the
 * walker takes it over from where it stands (the scene routes those from the trip's point), and a delivered change that
 * recalls the walker — out of `idle` and `working` (card#12785) — walks it home along its route (`snackHome()`, the
 * operator's ruling of 2026-11-02). Its spot is kept from its admission until it leaves the spot, and nothing a later
 * render admits or cancels re-times it. Breaks due on one pass are decided in start-second order (`snack()`, item 3).
 */

import { emptyChair, staffed } from '../wire/animation-set.js';
import { pointAt } from './route.js';
import { ELEVATOR_DOOR, SNACK_GAP_S, departureLegs, queueLater, queueShape, seatInCab, snackHome, waitFrom } from './scene.js';

/** The § 6.2 rows drawn as a walk, one per seat at most. */
export const WALK_ROWS = Object.freeze(['A1', 'A2']);

/**
 * The SIDE of § 7.1's *Desk* column a `render_state` draws (item 6, clause 1): `staffed`, `empty`, or `neither`
 * (`retired`, an unrecognised value, or no desk) — through the two predicates A1 and A2 fire on, never a copy.
 */
export function walkSide(renderState) {
    return staffed(renderState) ? 'staffed' : emptyChair(renderState) ? 'empty' : 'neither';
}

/** The held walks' seat keys. */
const keyOf = (effect) => `${effect.install_id}/${effect.seat_id}`;

/** An intern's A10 walk (card#12450) — keyed by its intern key, never by its seat's. */
const isInternWalk = (effect) => effect.animation_id === 'A10' && typeof effect.intern_key === 'string';

/** An intern's A24 laptop fade (card#12451). */
const isFade = (effect) => effect.animation_id === 'A24' && typeof effect.intern_key === 'string';

const samePoint = (a, b) => a !== null && b !== null && a !== undefined && b !== undefined && a.x === b.x && a.y === b.y;

/** A departure that queues at the elevator (walk note item 9, card#12705): A2's walk and an intern's walk out. */
const queues = (h) => (h.effect.queue ?? null) !== null;

/** Two keys by code unit, never `localeCompare` — the tie-break every viewer agrees on. */
const byKey = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

/**
 * THE FEWEST WHOLE FRAMES a part of an effect starting at `now` must wait so that it meets none of `taken` — the one rule
 * the birdhouse's ledge (card#12445) and the call plates (card#12747) share: `own` is the part in frames from the effect's
 * start, `taken` the parts already held, in ms on the page's clock; each wait that meets one moves past it, until none
 * meets.
 */
function clearOf(own, taken, now, I) {
    let wait = 0;
    let moved = true;

    while (moved) {
        moved = false;

        for (const t of taken) {
            const from = now + (wait + own.from) * I;
            const to = now + (wait + own.to) * I;

            if (from < t.to && t.from < to) {
                wait = Math.ceil((t.to - now) / I) - own.from;
                moved = true;
            }
        }
    }

    return wait;
}

export class EffectsInFlight {
    /** `{ effect, at, frames, key, anchor, threshold, unconfirmed, stilled, side, lattice_key }`, oldest first. */
    #held = [];

    #intervalMs;

    /**
     * card#12749 — the snack trips in flight, walker key → `{ effect, at, frames, key, seat, intern, anchor, threshold,
     * unconfirmed, stilled, lattice_key, home }`: `at` the trip's start on the page's clock (in the past for a trip
     * admitted at its phase), `home` whether it is a recall's walk home.
     */
    #trips = new Map();

    /** `<walker key> <break start second>` → each break decided once: a trip started, or no trip for that break. */
    #decided = new Set();

    /** The windows a non-delta entry re-derives (§ 6.6 item 5): decided again, at their phase, on the next live pass. */
    #rederive = new Set();

    /** The corrected server instant (ms) of the first snack pass — a break that began before it is entered at its phase. */
    #observedFrom = null;

    /** § 5.5's *no open route … snack table* lines already written, `<seat key> <lattice key>`. */
    #narrated = new Set();

    /** @param {number} intervalMs one loop frame (§ 12's loop rate) */
    constructor(intervalMs) {
        this.#intervalMs = intervalMs;
    }

    /**
     * One render: cancel every walk the render touched, drop every effect past its last frame, and
     * admit the effects this render's rows drew.
     *
     * @param {list<object>} effects the scene's effects for the rows this render wrote
     * @param {number} now the browser's clock, ms
     * @param {object} ctx `{ journal, anchors: Map, threshold, unconfirmed: function(key): boolean|null, stilled,
     *        side: function(key): string, lattice_key, slot_foot: function(effect): ?{x, y}, interns: Set<string> }` —
     *        `slot_foot` an intern walk's slot foot in this render's scene (item 17's anchor clause), `interns` the intern
     *        keys this render's arrays hold
     * @returns {list<string>} the keys whose walk this render cancelled
     */
    render(effects, now, ctx) {
        // A delta touches a walk only through the side it draws (clause 1); every other applied entry replaces the
        // seat's object or removes it, and touches it as it always did (clause 2).
        const touchedKeys = new Set((ctx.journal ?? [])
            .filter((entry) => entry.outcome === 'applied' && entry.t !== 'seat.delta' && typeof entry.seat_id === 'string')
            .map((entry) => `${entry.install_id}/${entry.seat_id}`));
        // Two opposite edges applied in one render leave the side unchanged and still cancel: their rows are here.
        const rowKeys = new Set(effects.filter((effect) => WALK_ROWS.includes(effect.animation_id)).map(keyOf));
        const cancelled = [];
        // An intern a walk of this render replaces (item 16's turn-round), and an intern whose fade a newer one replaces.
        const replaced = new Set(effects.filter((e) => isInternWalk(e) && e.frames > 0).map((e) => e.intern_key));
        const refaded = new Set(effects.filter(isFade).map((e) => e.intern_key));
        // card#12744: where an arrival item 16 turns round in the cab stands — its rider spot and its feet now — which its
        // turn-round keeps, so the intern turns from where it is.
        const inCab = new Map(this.#held
            .filter((h) => h.intern === true && replaced.has(h.key) && h.cab_slot !== undefined && !this.#ended(h, now))
            .map((h) => [h.key, { slot: h.cab_slot, at: pointAt(h.effect, now - h.at) }]));

        this.#held = this.#held.filter((h) => {
            if (this.#ended(h, now)) {
                return false;
            }

            if (h.fade === true) {
                // A newer fade for the intern, or any walk of it, takes the laptop over.
                return !refaded.has(h.key) && !replaced.has(h.key);
            }

            if (h.intern === true) {
                const seat = keyOf(h.effect);
                const touched = touchedKeys.has(seat)
                    || h.lattice_key !== ctx.lattice_key
                    || h.unconfirmed !== ctx.unconfirmed(seat)
                    || h.stilled !== ctx.stilled
                    || !samePoint(h.anchor, (ctx.slot_foot ?? (() => null))(h.effect))
                    || !samePoint(h.threshold, ctx.threshold)
                    // Item 15: an arrival whose intern left the array with no walk of its own — the cap dropped it — is a
                    // plain cancel. A departure's intern has left the array by definition.
                    || (h.effect.leg_kind === 'arrive' && !(ctx.interns ?? new Set()).has(h.key) && !replaced.has(h.key));

                if (touched) {
                    cancelled.push(h.key);
                }

                return !touched && !replaced.has(h.key);
            }

            if (h.key === null) {
                return true;
            }

            const touched = touchedKeys.has(h.key)
                || rowKeys.has(h.key)
                || ctx.side(h.key) !== h.side
                || ctx.lattice_key !== h.lattice_key
                || ctx.unconfirmed(h.key) !== h.unconfirmed
                || ctx.stilled !== h.stilled
                || !samePoint(ctx.anchors.get(h.key) ?? null, h.anchor)
                || !samePoint(ctx.threshold, h.threshold);

            if (touched) {
                cancelled.push(h.key);
            }

            return !touched;
        });

        // card#12749 (§ 6.6 item 5): the walk's cancel clauses, read for each snack trip — and an A2 or an A10 leave of the
        // walker, which the scene drew from the trip's point, takes the trip over. A trip a non-delta entry ended is
        // re-derived from the new object on the next pass: the same key and break give the same trip, at its phase.
        for (const [k, t] of this.#trips) {
            const nonDelta = touchedKeys.has(t.seat);
            const touched = this.#ended(t, now)
                || nonDelta
                || (t.intern ? replaced.has(k) || !(ctx.interns ?? new Set()).has(k) : rowKeys.has(t.seat) || ctx.side(t.seat) !== 'staffed')
                || t.lattice_key !== ctx.lattice_key
                || t.unconfirmed !== ctx.unconfirmed(t.seat)
                || t.stilled !== ctx.stilled
                || !samePoint(t.anchor, t.intern ? (ctx.slot_foot ?? (() => null))(t.effect) : ctx.anchors.get(t.seat) ?? null)
                || !samePoint(t.threshold, ctx.threshold);

            if (touched) {
                this.#trips.delete(k);

                if (nonDelta && !t.home && !this.#ended(t, now)) {
                    this.#rederive.add(`${k} ${t.effect.start_s}`);
                    this.#decided.delete(`${k} ${t.effect.start_s}`);
                }
            }
        }

        const walkCount = new Map();

        for (const effect of effects) {
            if (WALK_ROWS.includes(effect.animation_id)) {
                walkCount.set(keyOf(effect), (walkCount.get(keyOf(effect)) ?? 0) + 1);
            }
        }

        for (const effect of effects) {
            if (!(effect.frames > 0) && !(effect.still_frames > 0)) {
                continue;
            }

            if (isFade(effect)) {
                this.#held.push({ effect, at: now, frames: effect.frames, key: effect.intern_key, fade: true });

                continue;
            }

            if (isInternWalk(effect)) {
                // One walk per intern; a seat-touching render that cancels the seat's interns starts none of them.
                if (cancelled.includes(effect.intern_key) || touchedKeys.has(keyOf(effect))) {
                    continue;
                }

                const was = inCab.get(effect.intern_key);

                this.#held.push({
                    effect,
                    at: now,
                    frames: effect.frames,
                    key: effect.intern_key,
                    intern: true,
                    ...(was !== undefined && was.at.where === 'cab' && effect.legs[0]?.kind === 'cab'
                        ? { cab_slot: was.slot, cab_from: Object.freeze({ x: was.at.x, y: was.at.y }) }
                        : {}),
                    anchor: effect.anchor ?? null,
                    threshold: ctx.threshold,
                    unconfirmed: ctx.unconfirmed(keyOf(effect)),
                    stilled: ctx.stilled,
                    lattice_key: effect.lattice_key,
                });

                continue;
            }

            if (!WALK_ROWS.includes(effect.animation_id)) {
                // An edge with a tail (A19's arrival rest, card#12241; A22's ledge dwell and hop, card#12445) is held
                // through its whole span — a ring's frames are its whole span; a reduced form with a duration (A19's
                // still envelope) is held for that duration, then gone.
                const admitted = (effect.perch ?? null) !== null ? this.#onTheLedge(effect, now) : effect;
                const frames = admitted.frames > 0 ? (admitted.span_frames ?? admitted.frames) : admitted.still_frames;

                this.#held.push({ effect: admitted, at: now, frames, key: null });

                continue;
            }

            const key = keyOf(effect);

            // No walk starts from a cancelled one, and two walk rows for one seat in one render are a
            // walk the second delta cancelled.
            if (cancelled.includes(key) || walkCount.get(key) > 1 || this.#held.some((h) => h.key === key)) {
                continue;
            }

            this.#held.push({
                effect,
                at: now,
                frames: effect.frames,
                key,
                anchor: ctx.anchors.get(key) ?? null,
                threshold: ctx.threshold,
                unconfirmed: ctx.unconfirmed(key),
                stilled: ctx.stilled,
                side: effect.side,
                lattice_key: effect.lattice_key,
            });
        }

        this.#call();
        this.#board(now, ctx.queue_spot ?? (() => null));
        this.#seat(now, ctx.threshold ?? null);

        return cancelled;
    }

    /**
     * card#12744 — RIDERS STAND APART IN THE CAB (walk note item 10): every walk in flight with a `cab` leg is given a
     * rider spot (`scene.js`'s `cabSpot()`) and its cab leg stood there (`seatInCab()`), on each render after the queue's
     * re-timing. Only where the cab leg stands moves; no frame of any walk is re-timed.
     *
     * - **The lowest spot free over its whole cab leg.** Walks are seated in the order their cab legs begin, ties by key:
     *   each takes the lowest spot no other walk already seated holds over any instant of its own cab leg. So a lone
     *   rider stands at spot 0, the threshold, as it always has; boarders take spots in the order they step in, the
     *   first — nearest the door, item 9 — on the threshold and each later one further in; arrivals revealed together
     *   in one opening stand apart from the frame the leaves begin to open.
     * - **A rider in the cab keeps its spot.** A walk whose cab leg has begun by now keeps the spot it was drawn at on
     *   every later render, whatever a later render admits or cancels, so no rider moves in the cab between two
     *   renders; a walk whose cab leg is still to come is re-seated with the rest. Item 16's turn-round in the cab
     *   keeps the spot of the arrival it replaces, and steps from where that arrival stood.
     * - **Past the last spot**, riders share points (`cabSpot()`): a cab holds `CAB_SPOTS` apart.
     */
    #seat(now, door) {
        if (door === null) {
            return;
        }

        const I = this.#intervalMs;
        const riders = this.#held
            .filter((h) => !this.#ended(h, now) && (h.effect.legs ?? []).some((l) => l.kind === 'cab'))
            .map((h) => {
                const cab = h.effect.legs.find((l) => l.kind === 'cab');

                return { h, from: h.at + cab.start * I, to: h.at + (cab.start + cab.frames) * I };
            });
        const seated = riders.filter((r) => r.h.cab_slot !== undefined && r.from <= now);
        const rest = riders.filter((r) => !seated.includes(r)).sort((a, b) => a.from - b.from || byKey(a.h.key, b.h.key));

        for (const r of rest) {
            const taken = new Set(seated.filter((o) => o.from < r.to && r.from < o.to).map((o) => o.h.cab_slot));
            let k = 0;

            while (taken.has(k)) {
                k++;
            }

            r.h.cab_slot = k;
            seated.push(r);
        }

        for (const { h } of riders) {
            h.effect = Object.freeze({ ...h.effect, legs: seatInCab(h.effect.legs, door, h.cab_slot, h.cab_from ?? null) });
        }
    }

    /**
     * card#12747 — THE CALL PLATES, ONE PRESSER AT A TIME (walk note item 9): each departure with a press that the holder has
     * not yet given its turn — in the order they were admitted, so a render's new ones in their rows' order — waits where
     * it sets off (`queueLater()`, its `line`) the fewest whole frames that keep its time at the plates — from reaching them
     * to the end of its press and its `gap` after (the frames § 12's *Queue spacing* takes at § 12's *Walk speed*) — clear
     * of every departure already given its turn. Both plates are one place: their standing points are closer than two
     * walkers keep apart. A turn is given once, on the render that admits the departure, before any frame of it is drawn,
     * and never changes: nothing a later render admits or cancels re-times a walker's way to the plates.
     */
    #call() {
        const I = this.#intervalMs;
        const span = (q) => ({ from: q.press.stand.start, to: q.press.stand.start + q.press.stand.frames + q.press.gap });
        const pressing = this.#held.filter((h) => h.key !== null && (h.effect.queue?.press ?? null) !== null);

        for (const h of pressing.filter((x) => x.called !== true)) {
            const taken = pressing.filter((o) => o.called === true).map((o) => {
                const s = span(o.effect.queue);

                return { from: o.at + s.from * I, to: o.at + s.to * I };
            });
            const wait = clearOf(span(h.effect.queue), taken, h.at, I);

            if (wait > 0) {
                h.effect = Object.freeze({ ...h.effect, queue: queueLater(h.effect.queue, wait) });
            }

            h.called = true;
        }
    }

    /**
     * card#12705 — THE QUEUE AT THE ELEVATOR (walk note item 9): every departure in flight, re-timed together on each
     * render, after its cancels and its admissions.
     *
     * - **Spots.** A departure the holder has not placed yet takes the LOWEST spot (`queueSpot(k)`, the scene's
     *   `spotsNear()` spots: 0 the threshold, each later one at least § 12's *Queue spacing* from every other) that no
     *   other departure still stands on or walks to; new ones of one render are placed in the order their routes reach
     *   the threshold, ties by key. The threshold is also every boarder's way in, so spot 0 is free only while no other
     *   departure in flight is still to step in by the time this one reaches it. A spot never changes once taken. Past
     *   the last spot the floor holds, a walker waits on the threshold.
     * - **Batches.** The leaves open at the first of: a waiting departure's own wait running out (its seeded draw, from
     *   reaching its spot), or a walk with fixed door frames opening them (an arrival, item 19's blink, item 16's
     *   turn-round in the cab or at the door). EVERY departure at its spot by then boards, one at a time, nearest the
     *   door first (by spot, the spots being numbered outward from the threshold): each leaves its spot once the one
     *   before it has stepped in (the first as the leaves begin opening, or once an arrival has stepped out), walks to
     *   the threshold, and steps in once the leaves are open. A departure that reaches its spot before the leaves begin
     *   to close joins at the back, in the order they arrive (ties by key). The leaves close after the last has stepped
     *   in; a departure whose wait runs out while they close waits for them to shut and opens them again.
     * - **The past is committed.** A departure that has left its spot keeps its leaving and its step (below, `left`), and
     *   no departure is re-timed to leave before `now` — so no walker's drawn position changes between two renders.
     * - **Committed batches stay put.** A batch whose leaves began opening by a render keeps that instant on every later
     *   render, so a cancel among its walkers re-times the rest of it from the same opening, never undoes it.
     *
     * Every number is a function of the walks in flight and the instants they began, so a replay draws one queue. A walk
     * with fixed door frames is never re-timed.
     */
    #board(now, queueSpot) {
        const I = this.#intervalMs;
        const D = ELEVATOR_DOOR;
        const departing = this.#held.filter((h) => !this.#ended(h, now) && queues(h));
        // When a departure's route reaches the threshold — the order new ones of one render are placed in.
        const reach = (h) => h.at + (h.effect.queue.walk.start + h.effect.queue.walk.frames) * I;
        const placed = departing.filter((h) => h.shape !== undefined);
        const taken = new Set(placed.filter((h) => h.leaves_spot > now).map((h) => h.shape.spot.index));
        const threshold = (h) => Object.freeze({ index: 0, at: h.effect.queue.threshold, path: Object.freeze([h.effect.queue.threshold]) });
        const fresh = departing.filter((h) => h.shape === undefined).sort((a, b) => reach(a) - reach(b) || byKey(a.key, b.key));

        for (const h of fresh) {
            // The threshold, while anyone else is still to pass through it, is not a place to wait.
            const through = fresh.some((o) => o !== h && o.shape !== undefined) || placed.some((o) => o.steps_in > reach(h));
            let k = 0;

            while ((taken.has(k) || (k === 0 && through)) && queueSpot(k) !== null) {
                k++;
            }

            const spot = queueSpot(k);

            taken.add(k);
            h.shape = queueShape(h.effect.queue, spot === null ? threshold(h) : Object.freeze({ index: k, ...spot }));
        }

        // ⛔ THE PAST IS COMMITTED. A departure that has left its spot by now — its `approach` (the frame it leaves, or at
        // spot 0 the frame the leaves begin to open on it) at or before `now` — keeps its leaving and its step exactly:
        // nothing a later render admits or cancels moves it back to its spot or along its walk. Only the departures still
        // standing at or walking to their spots are re-timed, and none to leave before `now`. So no walker's drawn
        // position changes between two renders, and a spot whose walker has left is never stood on by it again.
        const left = (h) => h.plan !== undefined && h.at + h.plan.approach * I <= now;
        const committed = departing.filter(left).sort((a, b) => a.plan.open - b.plan.open || a.steps_in - b.steps_in);
        const waiting = departing.filter((h) => !left(h)).map((h) => {
            const arrive = h.at + h.shape.arrive * I;

            // Its own opening: its wait run out, or the opening of the batch it was committed to — never before it is at
            // its spot, so the walker whose instant opens a batch is always in it.
            // card#12747: the wait is counted from its press — the call it waits for — where it pressed one (`waitFrom()`).
            return { h, arrive, own: Math.max(arrive, h.pinned ?? h.at + (waitFrom(h.effect.queue, h.shape) + h.effect.queue.wait) * I), board: h.shape.board?.frames ?? 0 };
        }).sort((a, b) => a.arrive - b.arrive || byKey(a.h.key, b.h.key));
        const fixed = this.#held
            .filter((h) => h.key !== null && !this.#ended(h, now) && !queues(h) && (h.effect.door ?? null) !== null)
            .map((h) => ({ open: h.at + h.effect.door.start * I, clear: h.at + (h.effect.door.start + h.effect.door.frames - D.close) * I }))
            .sort((a, b) => a.open - b.open);
        const plans = new Map();
        // An instant in a departure's own frames: on a frame boundary that frame, anywhere else the next one.
        const frame = (h, ms) => {
            const x = (ms - h.at) / I;

            return Math.abs(x - Math.round(x)) < 1e-6 ? Math.round(x) : Math.ceil(x);
        };
        let f = 0;
        let c = 0;
        let shut = -Infinity;

        while (waiting.length > 0 || f < fixed.length || c < committed.length) {
            const due = waiting.length === 0 ? Infinity : Math.max(shut, Math.min(...waiting.map((w) => w.own)));
            // The next opening: a committed batch's (it already happened), a fixed walk's, or a waiting departure's own.
            const kept = c < committed.length ? committed[c].plan.open : Infinity;
            const byWalk = f < fixed.length && fixed[f].open <= due && fixed[f].open < kept;
            const open = Math.min(kept, byWalk ? fixed[f].open : due);
            // When the next boarder may leave its spot, and when the leaves may begin to close.
            let free = byWalk && open === fixed[f].open ? fixed[f].clear : open;
            let closing = byWalk && open === fixed[f].open ? fixed[f].clear : open + D.open * I;
            const absorb = () => {
                for (; f < fixed.length && fixed[f].open <= closing; f++) {
                    closing = Math.max(closing, fixed[f].clear);
                    free = Math.max(free, fixed[f].clear);
                }
            };
            // A committed boarder keeps its leaving and its step; the next is released from its step as drawn.
            const keep = (h) => {
                free = Math.max(free, h.at + (h.plan.step + D.step) * I);
                closing = Math.max(closing, free);
                plans.set(h, { open: h.plan.open, approach: h.plan.approach, step: h.plan.step, kept: h.plan });
                absorb();
            };
            // Each boarder's leaving and step in its OWN whole frames (`frame()`), never before `now`, and the next
            // released from the step as drawn, so two walks begun between each other's loop frames step a whole step apart.
            const board = (w) => {
                const approach = Math.max(w.h.shape.arrive, frame(w.h, Math.max(free, w.arrive, now)));
                const step = Math.max(approach + w.board, frame(w.h, open + D.open * I));

                free = w.h.at + (step + D.step) * I;
                closing = Math.max(closing, free);
                plans.set(w.h, { open, approach, step });
                absorb();
            };

            if (byWalk && open === fixed[f].open) {
                f++;
            }

            // The committed boarders of every opening this batch reaches, in the order they stepped in; then everyone at a
            // spot when the leaves began to open, nearest the door first; then whoever arrives before they begin to
            // close, in the order they arrive.
            for (; c < committed.length && committed[c].plan.open <= closing; c++) {
                keep(committed[c]);
            }

            absorb();

            const there = waiting.filter((w) => w.arrive <= open).sort((a, b) => a.h.shape.spot.index - b.h.shape.spot.index || byKey(a.h.key, b.h.key));

            for (const w of there) {
                waiting.splice(waiting.indexOf(w), 1);
                board(w);
            }

            while (waiting.length > 0 && waiting[0].arrive <= closing) {
                board(waiting.shift());
            }

            const close = closing + D.close * I;

            // This batch's boarders are the plans with no closing yet.
            for (const p of plans.values()) {
                p.close ??= close;
            }

            shut = close;
        }

        for (const h of departing) {
            const p = plans.get(h);
            // Leaves already closing behind a committed walker close as they began to: its cab leg is not cut short.
            const close = p.kept !== undefined && p.kept.close - D.close * I <= now ? Math.max(p.close, p.kept.close) : p.close;
            const drawn = departureLegs(h.effect.queue, h.shape, { open: frame(h, p.open), approach: p.approach, step: p.step, close: Math.max(frame(h, close), p.step + D.step + D.close) });

            h.effect = Object.freeze({ ...h.effect, ...drawn });
            h.frames = drawn.frames;
            h.plan = Object.freeze({ open: p.open, approach: p.approach, step: p.step, close });
            // It holds its spot until it leaves it — at spot 0, the threshold, until it steps in.
            h.leaves_spot = h.at + (h.shape.spot.index === 0 ? p.step : p.approach) * I;
            h.steps_in = h.at + p.step * I;

            if (p.open <= now) {
                h.pinned = p.open;
            }
        }
    }

    /**
     * card#12749 — THE SNACK PASS (FLOOR.md § 6.6 items 4–6), on every render, paint-only refresh and tick, over the scene
     * just built (`snack`, `scene.js`'s `snackCorner()`):
     *
     * 1. **A recall walks home.** A trip whose walker's hold has ended while the desk or the stool still draws it — the
     *    held field left A25's values (`recalled`; card#12785: `idle ↔ working` is not a recall) — becomes its walk home
     *    from where it is now (`snackHome()`); one whose hold the desk's treatment stilled ((2b): a lag, `config_invalid`,
     *    an unrecognised value, a dark desk) ends, and the character is drawn at its desk.
     * 2. **Each break is decided once** (card#12785: the breaks are the walker's chain, `scene.js`'s `snackBreaks()`, each
     *    one 60 to 120 minutes after the one before). A break is decided at the first pass on or after its seeded second,
     *    and at that pass only: a trip starts where the walker's hold permits it (`eligible`), the strip reads *live*, the
     *    walker has no trip in flight, and the scene finds it a table and a spot — otherwise no trip for that break. A
     *    break that began before this page was watching, or that a non-delta entry re-derives, is entered at its phase on
     *    the first pass that reads *live*. The trip's start is its break's second, so the walker is drawn where its trip
     *    has it, never from the start.
     * 3. **Breaks due on one pass are decided in START-SECOND order, then by walker key** (card#12785, the card#12749
     *    review's N1): the order a viewer watching throughout decided them in, one pass each, so a page loaded during two
     *    overlapping trips gives each the spot it took then rather than the spot the walkers' desk order would give it.
     *
     * @param {number} now the page's clock, ms
     * @param {{ snack: ?object, server_ms: ?number, live: boolean, unconfirmed: function(string): ?boolean,
     *          stilled: boolean, threshold: ?object }} ctx
     * @returns {list<string>} § 5.5's lines this pass owes — a desk inside a room with no open route to its table
     */
    snack(now, ctx) {
        const I = this.#intervalMs;
        const lines = [];
        const corner = ctx.snack ?? null;

        for (const [k, t] of this.#trips) {
            if (this.#ended(t, now)) {
                this.#trips.delete(k);
            }
        }

        if (corner === null || ctx.server_ms === null || ctx.server_ms === undefined) {
            return lines;
        }

        this.#observedFrom ??= ctx.server_ms;

        const walkers = new Map(corner.walkers.map((w) => [w.key, w]));

        for (const [k, t] of this.#trips) {
            const w = walkers.get(k);

            if (t.home || w === undefined || w.eligible) {
                continue;
            }

            if (w.recalled) {
                const home = snackHome(t.effect, now - t.at);

                this.#trips.set(k, { ...t, effect: home, at: now, frames: home.frames, home: true });
            } else {
                this.#trips.delete(k);
            }
        }

        const nowS = Math.floor(ctx.server_ms / 1000);

        for (const id of this.#decided) {
            if (Number(id.slice(id.lastIndexOf(' ') + 1)) < nowS - 2 * SNACK_GAP_S.max) {
                this.#decided.delete(id);
            }
        }

        // Every break whose second has come and that no pass has decided — at most one per walker in a gap's length,
        // since the gaps are at least an hour — oldest first, ties by key.
        const due = [];

        for (const w of corner.walkers) {
            for (const s of corner.schedule(w.key, nowS - SNACK_GAP_S.max, nowS)) {
                const id = `${w.key} ${s.start_s}`;

                if (!this.#decided.has(id) && ctx.server_ms >= s.start_s * 1000) {
                    due.push({ w, s, id });
                }
            }
        }

        due.sort((a, b) => a.s.start_s - b.s.start_s || byKey(a.w.key, b.w.key));

        for (const { w, s, id } of due) {
            const late = s.start_s * 1000 < this.#observedFrom || this.#rederive.has(id);

            if (late && !ctx.live) {
                continue;
            }

            this.#decided.add(id);
            this.#rederive.delete(id);

            // A break whose second lies further back than its dwell and ten minutes of walking is not asked for a route:
            // a bound chosen past the routes of the floors shipped and measured, and the cost of a longer one is only that
            // a page loaded during its trip does not enter it at its phase.
            if (!ctx.live || !w.eligible || this.#trips.has(w.key) || ctx.server_ms - s.start_s * 1000 > (s.dwell_s + 600) * 1000) {
                continue;
            }

            const taken = [...this.#trips.values()].filter((t) => !t.home).map((t) => t.effect.spot);
            const effect = corner.plan(w, s, taken);

            if (effect === null || effect.full === true) {
                continue;
            }

            if (effect.no_route === true) {
                const once = `${w.seat_key} ${ctx.lattice_key ?? ''}`;

                if (w.in_room && !this.#narrated.has(once)) {
                    this.#narrated.add(once);
                    lines.push(`${w.seat_key}: no open route between its desk and the snack table — drawn without the trip`);
                }

                continue;
            }

            const at = now - (ctx.server_ms - s.start_s * 1000);

            if (now >= at + effect.frames * I) {
                continue;
            }

            this.#trips.set(w.key, {
                effect,
                at,
                frames: effect.frames,
                key: w.key,
                seat: w.seat_key,
                intern: w.kind === 'intern',
                anchor: w.anchor,
                threshold: ctx.threshold,
                unconfirmed: ctx.unconfirmed(w.seat_key),
                stilled: ctx.stilled,
                lattice_key: effect.lattice_key,
                home: false,
            });
        }

        return lines;
    }

    /**
     * card#12749: milliseconds until the next break to decide begins — any walker's, eligible or not, since its break is
     * decided at its second whatever the walker holds then — or `null` with none, so the screen paints at it. A walker's
     * next break is never more than a gap's length away (card#12785).
     */
    nextSnack(now, snack, serverMs) {
        if ((snack ?? null) === null || serverMs === null || serverMs === undefined) {
            return null;
        }

        const nowS = Math.floor(serverMs / 1000);
        let next = null;

        for (const w of snack.walkers) {
            for (const s of snack.schedule(w.key, nowS, nowS + SNACK_GAP_S.max)) {
                if (!this.#decided.has(`${w.key} ${s.start_s}`) && s.start_s * 1000 > serverMs) {
                    next = Math.min(next ?? Infinity, s.start_s * 1000 - serverMs);
                }
            }
        }

        return next;
    }

    /** card#12749: every snack trip in flight at `now`, walker key → `{ effect, elapsed_ms }` — what an A2 or A10 leaves from. */
    trips(now) {
        return new Map([...this.#trips.values()]
            .filter((t) => !this.#ended(t, now))
            .map((t) => [t.key, Object.freeze({ effect: Object.freeze({ ...t.effect }), elapsed_ms: Math.max(0, now - t.at) })]));
    }

    /** card#12749: the walkers away on a trip at `now` — their desk draws its empty chair, their stool draws empty. */
    away(now) {
        return new Set([...this.#trips.values()].filter((t) => !this.#ended(t, now)).map((t) => t.key));
    }

    /**
     * Every effect still in flight at `now`, each with the frame it has reached, the instant it started on the
     * page's clock (`started_at`) and the milliseconds since — `elapsed_ms`, the exact instant a CONTINUOUS motion
     * (A19's and A22's flight, card#12345) is drawn at, so a repaint mid-frame continues the flight where it is
     * rather than snapping it back to the start of its frame. The snack trips in flight are among them (card#12749).
     */
    current(now) {
        return [...this.#held, ...this.#trips.values()]
            .filter((h) => !this.#ended(h, now))
            .map((h) => Object.freeze({ ...h.effect, elapsed_frames: this.#elapsed(h, now), started_at: h.at, elapsed_ms: Math.max(0, now - h.at) }));
    }

    /** The seats whose A1 walker is still on its way to the desk at `now`. */
    inbound(now) {
        return new Set(this.#held
            .filter((h) => h.key !== null && h.effect.animation_id === 'A1' && !this.#ended(h, now))
            .map((h) => h.key));
    }

    /**
     * The interns whose arrival is still in flight at `now` (card#12450): their stools draw empty, the walker drawing the
     * intern through its whole arrival, its `seat` leg included, until the paint-only refresh at its end seats it.
     */
    inboundInterns(now) {
        return new Set(this.#held
            .filter((h) => h.intern === true && h.effect.leg_kind === 'arrive' && !this.#ended(h, now))
            .map((h) => h.key));
    }

    /** Every intern walk in flight at `now`, intern key → `{ effect, elapsed_ms }` — what item 16 turns an arrival from. */
    interns(now) {
        return new Map(this.#held
            .filter((h) => h.intern === true && !this.#ended(h, now))
            .map((h) => [h.key, Object.freeze({ effect: h.effect, elapsed_ms: Math.max(0, now - h.at) })]));
    }

    /**
     * An intern's laptop opacity at `now` where something in flight is moving it, else `null` (its held state decides):
     * an A24 fade at its point between its two ends, or an arrival — 0 before its `seat` leg, rising through it.
     */
    laptopAt(key, now) {
        const trip = this.#trips.get(key);

        // card#12749: an intern on a snack trip has no laptop with it — a working one's (card#12785) is drawn again with the
        // intern, on its stool, at the paint-only refresh that ends the trip.
        if (trip !== undefined && !this.#ended(trip, now)) {
            return 0;
        }

        const h = this.#held.find((x) => x.key === key && !this.#ended(x, now) && (x.fade === true || (x.intern === true && x.effect.leg_kind === 'arrive')));

        if (h === undefined) {
            return null;
        }

        const ms = Math.max(0, now - h.at);

        if (h.fade === true) {
            return h.effect.from + (h.effect.to - h.effect.from) * Math.min(1, ms / (h.effect.frames * this.#intervalMs));
        }

        const seat = h.effect.legs.find((l) => l.kind === 'seat') ?? null;

        if (seat === null) {
            return 0;
        }

        const into = ms - seat.start * this.#intervalMs;

        return into <= 0 ? 0 : Math.min(1, into / (seat.frames * this.#intervalMs)) * seat.laptop_to;
    }

    /**
     * Milliseconds until the next effect in flight ends — a walk, a traveller's rest, a ring, or a
     * reduced form shown for its duration (A19's and A22's still envelope) — or `null` with none. Every
     * held effect, because an effect whose end nothing re-paints stays drawn on a quiet feed, which is a
     * held render § 6.2's transient cells refuse (card#12239).
     */
    nextEnd(now) {
        const ends = [...this.#held, ...this.#trips.values()]
            .filter((h) => !this.#ended(h, now))
            .map((h) => h.at + h.frames * this.#intervalMs - now);

        return ends.length === 0 ? null : Math.max(0, Math.min(...ends));
    }

    /**
     * The elevator's leaves (item 10): open while any walk in flight is in its door frames. Each
     * interval is in frames from `now`, merged where two overlap, so the leaves open once.
     */
    doors(now) {
        const spans = this.#held
            .filter((h) => h.key !== null && !this.#ended(h, now) && h.effect.door !== null && h.effect.door !== undefined)
            .map((h) => {
                const elapsed = this.#elapsed(h, now);

                return { from: h.effect.door.start - elapsed, to: h.effect.door.start + h.effect.door.frames - elapsed };
            })
            .filter((s) => s.to > 0)
            .sort((a, b) => a.from - b.from);
        const merged = [];

        for (const s of spans) {
            const last = merged[merged.length - 1];

            if (last !== undefined && s.from <= last.to) {
                last.to = Math.max(last.to, s.to);
            } else {
                merged.push({ ...s });
            }
        }

        return merged.map((s) => Object.freeze(s));
    }

    /**
     * card#12445: THE LEDGE HOLDS ONE BIRD. A portal bird stands on the birdhouse's perch from its ledge part's first
     * frame — the hop out coming in, the dwell going out — to the end of its hop in or its dwell, and a bird whose
     * ledge part would meet a held bird's on the same perch WAITS: every part of it starts `wait_frames` later, the
     * fewest whole frames that clear every held bird's — so the second of two birds at once is never drawn on the
     * first. A bird coming in waits inside the house; one going out leaves its desk that much later.
     */
    #onTheLedge(effect, now) {
        const I = this.#intervalMs;
        const part = (fx) => {
            const p = fx.perch;
            const from = Math.min(p.dwell_start, p.hop_start);

            return { from, to: Math.max(p.dwell_start + p.dwell_frames, p.hop_start + p.hop_frames) };
        };
        const own = part(effect);
        const taken = this.#held
            .filter((h) => (h.effect.perch ?? null) !== null && samePoint(h.effect.perch.point, effect.perch.point))
            .map((h) => {
                const p = part(h.effect);

                return { from: h.at + (h.effect.wait_frames + p.from) * I, to: h.at + (h.effect.wait_frames + p.to) * I };
            });
        const wait = clearOf(own, taken, now, I);

        return wait === 0 ? effect : Object.freeze({ ...effect, wait_frames: wait, span_frames: effect.span_frames + wait });
    }

    #elapsed(h, now) {
        return Math.max(0, Math.floor((now - h.at) / this.#intervalMs));
    }

    #ended(h, now) {
        return this.#elapsed(h, now) >= h.frames;
    }
}

/**
 * ⛔ THE INBOUND DESK — THE ONE PLUG POINT FOR THE OPERATOR's RULING ON IT (card#9566, r3 F1). While an
 * A1 walker is on its way, its desk draws the empty chair: no character, so no bubble (§ 5.1 rule 3) and
 * no held marker, with its label line, chip, badges and every other fact the applied object's. That is
 * option A, the seat's recommendation, in force until the ruling.
 */
export function inboundDesk(model) {
    return Object.freeze({ ...model, character: false, pose: 'empty-chair', bubble: null, held: null });
}

/**
 * ⛔ THE AWAY DESK — a desk whose character is away on its snack break (FLOOR.md § 6.6 item 4; card#12749, card#12785).
 * The desk keeps EVERY fact of the applied object at the desk — its chip, its label line, its monitor lit as its state
 * lights it, its badges, its facts plate and its task bubble, the card the seat works on — and draws its chair empty: no
 * character, so no pose and no held marker (no z's, no typing). The bubble stays with no thought trail, the trail being
 * the thinker's and away with it (`scene.js`'s `placeBubbles()`); § 5.1 rule 3's *a desk that draws no character draws
 * no bubble* is read for a state that draws no character, and this desk's state draws one, which is away. An A1
 * walker's desk is `inboundDesk()`'s, and is not this.
 */
export function awayDesk(model) {
    return Object.freeze({
        ...model,
        character: false,
        pose: 'empty-chair',
        bubble: model.bubble === null || model.bubble === undefined ? null : Object.freeze({ ...model.bubble, trail: false }),
        held: null,
    });
}
