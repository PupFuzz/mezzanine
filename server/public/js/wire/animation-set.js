/**
 * The ANIMATION SET — `docs/design/FLOOR.md § 6.2`'s CLOSED SET, A1 through A20, as the renderer's
 * own artifact, and the one place a § 6.2 row is started. Appendix B row 6, card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SET IS CLOSED, AND `FIRED_BY` BELOW IS WHAT MAKES THAT CHECKABLE. Every row of § 6.2 has
 * an entry there naming WHAT starts it in the shipped client. A row § 6.2 carries and this file
 * does not is a row nobody draws; a row this file carries and § 6.2 does not is claim-bearing
 * motion with no driving fact, which is the defect the honesty principle exists to refuse.
 * `Tests\Feature\Floor\TheAnimationSetIsTheDocumentsClosedSetTest` set-differences both directions.
 *
 * ⛔ THE TWO CLASSES ARE NOT DECORATION (§ 6.2). An `edge` row HAS a causing message and is an
 * INSTANT — one `edge()` call, one row, no exit. A `held` row has NO causing message, is entered
 * whenever the object the client holds says so, and may be entered more than once on one seat —
 * so it is `enterHeld()`/`leaveHeld()` and its `cause` is a `state_version` rather than a message.
 * Which call a row goes through follows from `ANIMATION_SET[id].class` and is never decided at the
 * call site, because § 11's log records `class` from the entry point it is called through.
 *
 * ⛔ § 6.5: A SNAPSHOT, A RESYNC, A PER-SEAT FETCH AND A RECONNECT FIRE NO `edge` ROW, AND THAT
 * RULE LIVES HERE RATHER THAN IN THE PROTOCOL. `wire/fleet-client.js`'s journal says truthfully
 * what the client DID with each message — it carries the snapshot's rows and the fetch's beside
 * the deltas — and this file is what decides which of them may be animated. A renderer reading
 * that journal without the `seat.delta` test plays an arrival at every desk on every reconnect,
 * which is AT-D3-9's third RED; keeping the test here is what makes that RED reach the artifact
 * the claim is about.
 *
 * ⛔ THE STRINGS BELOW RESTATE § 6.2's Animation AND Reduced-motion form CELLS AND ARE GUARDED,
 * NOT TRUSTED — the same rule `desk/desk-render.js` carries for § 7.1's sentences: a browser
 * cannot read FLOOR.md, so a restatement a consumer cannot follow a pointer to is DELETED or
 * GUARDED, and the test above re-derives every cell from the document on each run.
 *
 * ⛔ WHICH HELD ROWS LOOP IS DERIVED FROM THOSE CELLS AND IS NOT A SECOND LIST. § 11's *two states
 * with no motion by design* are the held rows whose Animation cell names no loop, so `loops()`
 * below reads the cell this file already holds under guard rather than a hand-kept pair of ids
 * that would be free to disagree with it — which is what `desk/desk-render.js` held until this
 * step, unguarded.
 *
 * ⚠ NO CLOCK AND NO TIMER. Every `at` is the caller's corrected server-clock instant, as the
 * animation log's is, and the frames a loop runs at are a drawing layer's to schedule — this file
 * says WHICH render is held, WHAT form it takes and how far apart its frames are, never when one
 * is drawn.
 */

/**
 * § 6.2's table: each row's class, the Animation cell's words, and the Reduced-motion form cell's
 * (§ 6.4 — a first-class rendering, never a degradation). Both cells are the document's, plain:
 * links followed to their own text, emphasis and backticks dropped, whitespace collapsed.
 */
export const ANIMATION_SET = Object.freeze({
    A1: Object.freeze({ class: 'edge', animation: 'arrive — the character walks in and sits', reduced: 'the character is simply present' }),
    A2: Object.freeze({ class: 'edge', animation: 'depart — the character stands and walks out, leaving the chair empty', reduced: 'the chair is empty and labelled' }),
    A3: Object.freeze({ class: 'held', animation: 'work — typing at the keyboard, with the eye blink and the gentle in-place wiggle, 4 fps loop', reduced: 'a working pose, static, with the glyph' }),
    A4: Object.freeze({ class: 'held', animation: 'think — leaning back, watching the monitor, with the same blink and wiggle, 4 fps loop', reduced: 'a thinking pose, static' }),
    A5: Object.freeze({ class: 'edge', animation: "tool-swap — the monitor's glyph changes, one 250 ms cross-fade", reduced: 'the glyph changes with no fade' }),
    A6: Object.freeze({ class: 'held', animation: "idle — the character is slumped asleep on the desk, the monitor dimmed, with drifting z's, 4 fps loop", reduced: "the static slumped pose, z's drawn once and still" }),
    A7: Object.freeze({ class: 'held', animation: 'attention — a raised hand and a marker above the desk, 4 fps loop', reduced: 'a static raised-hand pose and the marker' }),
    A8: Object.freeze({ class: 'held', animation: 'stalled — head in hands, static, with the api_error_type line', reduced: 'identical' }),
    A9: Object.freeze({ class: 'held', animation: 'unknown — a question marker over an occupied desk', reduced: 'identical' }),
    A10: Object.freeze({ class: 'edge', animation: 'intern-arrive / intern-leave — a stool at the side table fills or empties', reduced: 'the stool is simply occupied or empty' }),
    A11: Object.freeze({ class: 'edge', animation: 'badge-raise — a badge appears with a single 250 ms fade', reduced: 'the badge is simply present' }),
    A12: Object.freeze({ class: 'edge', animation: 'gauge — the context bar eases to its new value over 250 ms', reduced: 'the bar jumps to the value' }),
    A13: Object.freeze({ class: 'edge', animation: 'retire — the character stands, leaves, and the desk is removed from the floor (§ 3.5, card#9078: it used to clear the desk and stamp a plate)', reduced: 'the desk is simply absent on the next render' }),
    A14: Object.freeze({ class: 'edge', animation: 'feed-pulse — a one-frame pulse on the feed indicator', reduced: 'a last message HH:MM:SS readout that updates instead' }),
    A15: Object.freeze({ class: 'held', animation: 'catching-up — a replay marker sweeps the monitor, 4 fps loop', reduced: 'a static replay marker and the replaying label' }),
    A16: Object.freeze({ class: 'edge', animation: 'desk-move — a displaced character walks to its new desk', reduced: 'the desk appears in its new slot on the next render' }),
    A17: Object.freeze({ class: 'edge', animation: "room-tick — the wall clock's hands step to the viewer's current minute and the windows' sky is re-evaluated for that time", reduced: 'the hands jump to position and the sky steps to its new value with no cross-fade — the same fact, without the transition (§ 6.4)' }),
    A18: Object.freeze({ class: 'held', animation: "thread-line — a line drawn between the desks a thread's participants resolve to, held for as long as the thread is open (§ 5.7)", reduced: 'the line is drawn static — same line, same endpoints, no travel along it' }),
    A19: Object.freeze({ class: 'edge', animation: 'envelope — an envelope travels the line once, from the origin desk to each destination desk', reduced: 'the bead is simply present at the destination end, with no travel' }),
    A20: Object.freeze({ class: 'edge', animation: 'broadcast-pulse — one ring expands from the origin desk across the floor', reduced: 'the origin desk carries a static broadcast marker for that post' }),
});

/**
 * Every § 6.2 row and WHAT starts it in the shipped client — the partition that makes the set's
 * coverage legible rather than a thing a reader counts by hand.
 *
 *   · `delta`     — an `edge` row fired by a `seat.delta` this client APPLIED (`DELTA_ROWS`).
 *   · `heartbeat` — an `edge` row fired by each `feed.heartbeat` RECEIVED.
 *   · `desk`      — a `held` render `desk/desk-render.js` selects for the state it draws, entered
 *                   and left by `held()` against the object that holds it.
 *   · `slot`      — A16, whose trigger is § 3.3's displacement and therefore a fact about the
 *                   SLOT FUNCTION, Appendix B row 7's artifact. `displaced()` is the entry that
 *                   row calls; the log row it writes is fully specified here (§ 11 names A16's
 *                   own `cause`), so what row 7 owes is the trigger and not a second decision.
 *   · `coord`     — ⚠ A18/A19/A20, DECLARED AND WRITTEN BY NO CALL HERE. Their triggers are
 *                   already built and are NOT this file's to re-mint: `coord/coord-model.js`'s
 *                   `threadAnimations()` and `roundAnimations()` decide them from the rendered
 *                   `coord.thread` / `coord.round` objects (card#8300). What is missing is the LOG
 *                   ROW, not the trigger — § 11's `cause` column enumerates four causing messages
 *                   and a `coord.round` is none of them, and its `held` column reads a seat
 *                   object's `state_version`, which a `coord.thread` has none of. A row written
 *                   here would therefore fail AT-D3-1's own closed-set GREEN (*one of the four
 *                   causing messages*) on a correct client, so this step writes none and the gap
 *                   is reported rather than guessed at — the same under-specification § 11 closed
 *                   for A14 and A17 with one line, still open for these three.
 */
export const FIRED_BY = Object.freeze({
    A1: 'delta',
    A2: 'delta',
    A3: 'desk',
    A4: 'desk',
    A5: 'delta',
    A6: 'desk',
    A7: 'desk',
    A8: 'desk',
    A9: 'desk',
    A10: 'delta',
    A11: 'delta',
    A12: 'delta',
    A13: 'delta',
    A14: 'heartbeat',
    A15: 'desk',
    A16: 'slot',
    A17: 'heartbeat',
    A18: 'coord',
    A19: 'coord',
    A20: 'coord',
});

/**
 * § 6.1 rule 2 and § 12's *Loop frame rate* row: **fixed** for every claim-bearing loop on the
 * floor and every seat, at one frame per D2 § 8.3's 250 ms stream tick — the fastest rate at which
 * the wire can inform this client of anything, so no loop can appear more informative than the
 * feed. A rate that varied with `open_calls`, tokens or throughput would render a quantity nothing
 * sent (§ 6.3's third forbidden form), which is AT-D3-1's second RED.
 */
export const LOOP_FPS = 4;

/** § 11's `cause` for the two rows the heartbeat fires: the message itself, which carries no id. */
export const HEARTBEAT = 'feed.heartbeat';

/** The two rows § 11 says belong to no seat — `seat_id` and `install_id` are both `null` on them. */
export const HEARTBEAT_ROWS = Object.freeze(Object.keys(FIRED_BY).filter((id) => FIRED_BY[id] === 'heartbeat'));

/**
 * Whether a row LOOPS, read off its own § 6.2 Animation cell — the `N fps loop` the cell ends
 * with, at the one rate above. A held row that names no loop is one of § 11's *states with no
 * motion by design*, and that is where the pair comes from rather than a list beside this one.
 */
export function loops(animationId) {
    return (ANIMATION_SET[animationId]?.animation ?? '').includes(`${LOOP_FPS} fps loop`);
}

/**
 * One row's class, or `null` for an id this set does not carry.
 *
 * ⛔ THE SET IS CLOSED, SO EVERY READER OF IT ANSWERS THE SAME WAY FOR AN ID THAT IS NOT IN IT:
 * `loops()` answers `false`, `animationForm()` answers `null`, and `motionOf()` below answers *no
 * motion* — never a plausible form, a rate nothing is using, or a throw from one reader while its
 * neighbours answer. A caller passing an out-of-table id has a bug either way; what this keeps is
 * that the bug looks the same wherever it lands.
 */
function classOf(animationId) {
    return ANIMATION_SET[animationId]?.class ?? null;
}

/** The frame interval of a row that loops, or `null` for a row that does not. */
export function frameIntervalMs(animationId) {
    return loops(animationId) ? 1000 / LOOP_FPS : null;
}

/**
 * § 6.4's rendering for one row: its ordinary form, or its reduced-motion form under
 * `prefers-reduced-motion: reduce`. The reduced form carries the SAME FACT — it is the row's other
 * rendering, never its absence — so a row this set does not carry has no form at all and answers
 * `null` rather than a plausible one.
 */
export function animationForm(animationId, reduce = false) {
    const row = ANIMATION_SET[animationId];

    if (row === undefined) {
        return null;
    }

    return reduce ? row.reduced : row.animation;
}

/**
 * ⛔ THE ONE PLACE § 6.4's CONDITION DECIDES `motion`, for every row of either class. § 11's
 * `motion` column names three reasons a row is drawn without it — a held row that loops at all,
 * a loop a § 7.3 currency treatment stopped, and reduced motion — and this is where all three
 * meet, so a second caller cannot answer the question differently.
 *
 * `permitted` is the CALLER's half and nothing more: for a held desk render it is § 7.3's verdict
 * (`desk/desk-render.js` owns it — a `fold_lag` badge, a `config_invalid` reporter, a desk with
 * nobody at it), and for an `edge` row there is no treatment to consult and it is simply true.
 */
function motionOf(animationId, permitted, reduce) {
    if (!permitted || reduce) {
        return false;
    }

    return classOf(animationId) === 'edge' || loops(animationId);
}

/**
 * One `held` row's RENDERING, for `desk/desk-render.js` to put on the desk it draws: which § 6.2
 * row is held, whether motion is drawn, § 6.4's form, and the interval a running loop's frames sit
 * at. `frame_interval_ms` is on the frame only where a loop is actually running — a render drawn
 * static has no frames to space, and a number there would be a rate nothing is using.
 */
export function heldRendering(animationId, permitted, reduce = false) {
    const motion = motionOf(animationId, permitted, reduce);

    return {
        animation_id: animationId,
        motion,
        form: animationForm(animationId, reduce),
        frame_interval_ms: motion ? frameIntervalMs(animationId) : null,
    };
}

const toolName = (seat) => seat.action?.tool_name ?? null;
const callIds = (seat) => new Set((Array.isArray(seat.subagents) ? seat.subagents : []).map((s) => s.call_id));
const badges = (seat) => new Set(Array.isArray(seat.badges) ? seat.badges : []);
const sameMembers = (a, b) => a.size === b.size && [...a].every((v) => b.has(v));
const gained = (a, b) => [...b].some((v) => !a.has(v));

/**
 * The `edge` rows a `seat.delta` can fire, each with the `changed[]` member its § 6.2 row names
 * and the rest of that row's condition over the objects before and after the merge.
 *
 * ⛔ `changed[]` IS THE GATE ON EVERY ONE OF THEM, and it is the delta's own patch keys
 * (D2 § 8.3.1) rather than a diff this file computes. A diff cannot tell a member the wire re-sent
 * unchanged from one it never sent, and § 2.5 is explicit that re-sending a held value still
 * counts as a change — which is what `changed[]` is for.
 *
 * ⚠ EACH ROW IS ITS OWN PREDICATE, exactly as § 6.2 writes them: the table states one exclusion
 * (A3 against A4, which `desk/desk-render.js` owns) and no other, so no row here suppresses
 * another and one delta satisfying two conditions writes two rows.
 *
 * ⛔ A1 READS THE OBJECT BEFORE THE MERGE, BECAUSE *LEAVES `offline`* IS A TRANSITION AND NOT A
 * VALUE. Read as *the new value is not `offline`* it would fire an arrival on every state change
 * a desk ever makes; § 3.4's table and this row's own *its absence means* — **the seat has not
 * left `offline`** — both make it the transition out of that state.
 *
 * ⛔ AND A1 EXCLUDES A13's CONDITION, THROUGH A13's OWN PREDICATE RATHER THAN A COPY OF IT. An
 * `offline → retired` delta leaves `offline` and is not an arrival: A13 removes the desk, and a
 * client that fired both played a character walking in to a desk it was deleting. § 6.2 hosts the
 * exclusion on A1 — the row that yields — exactly as it hosts A3's exclusion of A4 (card#7341
 * step 6), and `retiring` below is the one definition both rows read.
 */
const retiring = (before, after) => after.render_state === 'retired';

const DELTA_ROWS = Object.freeze([
    Object.freeze({ id: 'A1', changed: 'render_state', fires: (before, after) => before.render_state === 'offline' && after.render_state !== 'offline' && !retiring(before, after) }),
    Object.freeze({ id: 'A2', changed: 'render_state', fires: (before, after) => after.render_state === 'offline' }),
    Object.freeze({ id: 'A5', changed: 'action', fires: (before, after) => toolName(before) !== toolName(after) }),
    Object.freeze({ id: 'A10', changed: 'subagents', fires: (before, after) => !sameMembers(callIds(before), callIds(after)) }),
    Object.freeze({ id: 'A11', changed: 'badges', fires: (before, after) => gained(badges(before), badges(after)) }),
    Object.freeze({ id: 'A12', changed: 'context', fires: () => true }),
    Object.freeze({ id: 'A13', changed: 'render_state', fires: retiring }),
]);

/** The `changed[]` member each delta-driven row is gated on — read by the closed-set assertions. */
export const DELTA_ROW_DRIVERS = Object.freeze(Object.fromEntries(DELTA_ROWS.map((r) => [r.id, r.changed])));

export class AnimationSet {
    #log;

    #reduce;

    /** key → the open `held` episode: `{ episode_id, animation_id, motion }`. */
    #episodes = new Map();

    /**
     * @param {object} log  `wire/animation-log.js`'s `createAnimationLog()` — the one instrument
     *                      every row below is written through
     * @param {{reduce?: boolean}} [options]  § 6.4's condition, as a page reads it from
     *                      `prefers-reduced-motion`: a rendering, never a degradation
     */
    constructor(log, options = {}) {
        this.#log = log;
        this.#reduce = options.reduce === true;
    }

    /** Whether this set draws § 6.4's reduced-motion form of every row. */
    get reduce() {
        return this.#reduce;
    }

    /**
     * § 6.1 consequence 3: the `edge` rows the wire messages the client has just handled fire.
     *
     * @param {Iterable<object>} journal `FleetClient#takeWire()`'s entries, in handling order
     * @param {number} at § 2.4's corrected server-clock instant these rows are written at
     */
    edges(journal, at) {
        for (const entry of journal) {
            if (entry.t === HEARTBEAT) {
                this.#heartbeat(at);

                continue;
            }

            // § 6.5, and the whole of it: a row the client took from a snapshot, a resync or a
            // per-seat fetch animates NOTHING, and neither does a delta it buffered or discarded
            // — nor any message it did not apply, which is where a `room.map` falls (§ 2.5: "a
            // map is a layout act and not a fleet event, so no § 6.2 row fires").
            if (entry.t !== 'seat.delta' || entry.outcome !== 'applied') {
                continue;
            }

            const changed = new Set(entry.changed);

            for (const row of DELTA_ROWS) {
                if (changed.has(row.changed) && row.fires(entry.before, entry.after)) {
                    this.#edge(row.id, entry.state_version, entry.install_id, entry.seat_id, at);
                }
            }
        }
    }

    /**
     * § 11: A14 and A17 belong to no seat, so `install_id` and `seat_id` are both `null` on them
     * and `cause` — the heartbeat itself — is the whole provenance of a row that claims nothing
     * about any desk. One row per firing row per message RECEIVED, whatever the client did with
     * the `fleet{}` that message carried: a heartbeat overtaken by a newer one still arrived, and
     * a clock stopped on it would claim § 9 F1's feed-down condition on a live feed.
     */
    #heartbeat(at) {
        for (const id of HEARTBEAT_ROWS) {
            this.#edge(id, HEARTBEAT, null, null, at);
        }
    }

    /**
     * § 3.3's displacement: an arriving seat took an incumbent's slot, so the incumbent walks to
     * its new one. `cause` is § 11's own answer for this row — the seat-set change, recorded as
     * the ARRIVING seat's key — while the row itself names the desk that MOVED.
     *
     * ⚠ NO CALLER ON A PAGE YET: the trigger is a fact about the slot function, which is
     * Appendix B row 7's artifact, and AT-D3-3 is gated there. This entry exists so that step
     * reads § 6.4's form and § 11's `cause` off this set rather than minting a second answer.
     */
    displaced(installId, seatId, arrivingKey, at) {
        this.#edge('A16', arrivingKey, installId, seatId, at);
    }

    /** Every `edge` row goes through here, so `motion` has one answer for the whole class. */
    #edge(animationId, cause, installId, seatId, at) {
        this.#log.edge({
            animation_id: animationId,
            cause,
            install_id: installId,
            seat_id: seatId,
            motion: motionOf(animationId, true, this.#reduce),
            at,
        });
    }

    /**
     * § 6.2's `held` class over one frame: enter every desk's held render against the object that
     * holds it, and leave the ones whose hold has ended against the object that ended it.
     *
     * ⛔ AN EPISODE IS ONE CONTINUOUS RUN OF ONE RENDER ON ONE SEAT (§ 11). A change of the held
     * row OR of whether motion is drawn leaves one episode and enters another, because two rows
     * identical in every field cannot say which states a desk held or for how long — AT-D3-5 reads
     * exactly that to see a lagged desk entered and drawn static.
     *
     * ⚠ THIS MOVED HERE FROM `desk/desk-floor.js` AT STEP 6 AND IS NOT A SECOND COPY OF IT. Step 5
     * entered and left each desk's held render in the log because the desk floor was the only
     * renderer there was; the set is what owns a § 6.2 row, so the floor now delegates and holds
     * no episode state of its own.
     *
     * @param {Record<string, object>} desks the frame's desks, keyed as the client keys its seats
     * @param {Map<string, object>} seats the held seat objects the frame was derived from
     * @param {number} at § 2.4's corrected server-clock instant
     */
    held(desks, seats, at) {
        for (const [k, open] of this.#episodes) {
            const want = desks[k]?.held ?? null;

            if (want === null || want.animation_id !== open.animation_id || want.motion !== open.motion) {
                // § 11: a `left` row's cause is the `state_version` of the object that ENDED the
                // hold — the first object the client applied in which the condition is false.
                this.#log.leaveHeld(open.episode_id, { cause: seats.get(k)?.state_version ?? null, at });
                this.#episodes.delete(k);
            }
        }

        for (const [k, desk] of Object.entries(desks)) {
            if (desk.held === null || this.#episodes.has(k)) {
                continue;
            }

            const episodeId = this.#log.enterHeld({
                animation_id: desk.held.animation_id,
                cause: seats.get(k).state_version,
                install_id: desk.install_id,
                seat_id: desk.seat_id,
                motion: desk.held.motion,
                at,
            });

            this.#episodes.set(k, {
                episode_id: episodeId,
                animation_id: desk.held.animation_id,
                motion: desk.held.motion,
            });
        }
    }
}
