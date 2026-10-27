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
 */

import { emptyChair, staffed } from '../wire/animation-set.js';

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

const samePoint = (a, b) => a !== null && b !== null && a !== undefined && b !== undefined && a.x === b.x && a.y === b.y;

export class EffectsInFlight {
    /** `{ effect, at, frames, key, anchor, threshold, unconfirmed, stilled, side, lattice_key }`, oldest first. */
    #held = [];

    #intervalMs;

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
     *        side: function(key): string, lattice_key }`
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

        this.#held = this.#held.filter((h) => {
            if (this.#ended(h, now)) {
                return false;
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

        return cancelled;
    }

    /**
     * Every effect still in flight at `now`, each with the frame it has reached, the instant it started on the
     * page's clock (`started_at`) and the milliseconds since — `elapsed_ms`, the exact instant a CONTINUOUS motion
     * (A19's and A22's flight, card#12345) is drawn at, so a repaint mid-frame continues the flight where it is
     * rather than snapping it back to the start of its frame.
     */
    current(now) {
        return this.#held
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
     * Milliseconds until the next effect in flight ends — a walk, a traveller's rest, a ring, or a
     * reduced form shown for its duration (A19's and A22's still envelope) — or `null` with none. Every
     * held effect, because an effect whose end nothing re-paints stays drawn on a quiet feed, which is a
     * held render § 6.2's transient cells refuse (card#12239).
     */
    nextEnd(now) {
        const ends = this.#held
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
