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
 * ⛔ AT MOST ONE WALK PER SEAT, AND A RENDER THAT TOUCHES THE SEAT CANCELS IT (item 6). A render
 * touches seat S, with a walk in flight, iff its journal applied an entry for S, S's § 2.3 row 5
 * condition or the floor's stilled condition changed, S's desk anchor differs from the one the walk
 * was computed against (the applying render's), or the elevator's threshold moved. No walk starts from
 * a cancelled one, and a render whose journal fires two walk rows for one seat draws neither — the
 * second delta touched the first walk. The 1 s age tick and the paint-only refresh are not renders and
 * never reach `render()`.
 */

/** The § 6.2 rows drawn as a walk, one per seat at most. */
export const WALK_ROWS = Object.freeze(['A1', 'A2']);

/** The held walks' seat keys. */
const keyOf = (effect) => `${effect.install_id}/${effect.seat_id}`;

const samePoint = (a, b) => a !== null && b !== null && a !== undefined && b !== undefined && a.x === b.x && a.y === b.y;

export class EffectsInFlight {
    /** `{ effect, at, frames, key, anchor, threshold, unconfirmed, stilled }`, oldest first. */
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
     * @param {object} ctx `{ journal, anchors: Map, threshold, unconfirmed: function(key): boolean|null, stilled }`
     * @returns {list<string>} the keys whose walk this render cancelled
     */
    render(effects, now, ctx) {
        const touchedKeys = new Set((ctx.journal ?? [])
            .filter((entry) => entry.outcome === 'applied' && typeof entry.seat_id === 'string')
            .map((entry) => `${entry.install_id}/${entry.seat_id}`));
        const cancelled = [];

        this.#held = this.#held.filter((h) => {
            if (this.#ended(h, now)) {
                return false;
            }

            if (h.key === null) {
                return true;
            }

            const touched = touchedKeys.has(h.key)
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
            if (!(effect.frames > 0)) {
                continue;
            }

            if (!WALK_ROWS.includes(effect.animation_id)) {
                this.#held.push({ effect, at: now, frames: effect.frames, key: null });

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
            });
        }

        return cancelled;
    }

    /** Every effect still in flight at `now`, each with the frame it has reached. */
    current(now) {
        return this.#held
            .filter((h) => !this.#ended(h, now))
            .map((h) => Object.freeze({ ...h.effect, elapsed_frames: this.#elapsed(h, now) }));
    }

    /** The seats whose A1 walker is still on its way to the desk at `now`. */
    inbound(now) {
        return new Set(this.#held
            .filter((h) => h.key !== null && h.effect.animation_id === 'A1' && !this.#ended(h, now))
            .map((h) => h.key));
    }

    /** Milliseconds until the next walk in flight ends, or `null` with none. */
    nextWalkEnd(now) {
        const ends = this.#held
            .filter((h) => h.key !== null && !this.#ended(h, now))
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
