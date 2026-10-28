/**
 * The ANIMATION SET — `docs/design/FLOOR.md § 6.2`'s CLOSED SET, every row of its table, as the renderer's
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
 * so it is `enterHeld()`/`leaveHeld()`, its entry's `cause` is a `state_version` rather than a
 * message, and its exit's is the one § 11's precedence gives (`exitCause()` below).
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
 * ⛔ WHICH HELD ROWS LOOP IS DERIVED FROM THOSE CELLS AND IS NOT A SECOND LIST. § 11's *states
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

import { DESK } from '../desk/desk-poses.js';
import { deskDrawsCharacter } from '../desk/task-bubble.js';
import { isRenderState } from '../lobby/render-state.js';

/**
 * § 6.2's table: each row's class, the Animation cell's words, and the Reduced-motion form cell's
 * (§ 6.4 — a first-class rendering, never a degradation). Both cells are the document's, plain:
 * links followed to their own text, emphasis and backticks dropped, whitespace collapsed.
 */
export const ANIMATION_SET = Object.freeze({
    A1: Object.freeze({ class: 'edge', animation: "arrive — the elevator's leaves open on the character standing in the cab, it steps out, walks its routed path to its desk and sits", reduced: 'the character is simply present' }),
    A2: Object.freeze({ class: 'edge', animation: 'depart — the character stands, walks its routed path to the elevator, waits there for up to a minute, the leaves open, it steps into the cab, and the leaves close over it, leaving the chair empty', reduced: 'the chair is empty and labelled' }),
    A3: Object.freeze({ class: 'held', animation: 'work — typing at the keyboard, with the eye blink and the gentle in-place wiggle, 4 fps loop', reduced: 'a working pose, static, with the glyph' }),
    A4: Object.freeze({ class: 'held', animation: 'think — leaning back, watching the monitor, with the same blink and wiggle, 4 fps loop', reduced: 'a thinking pose, static' }),
    A5: Object.freeze({ class: 'edge', animation: "tool-swap — the monitor's glyph changes, one 250 ms cross-fade", reduced: 'the glyph changes with no fade' }),
    A6: Object.freeze({ class: 'held', animation: "idle — the character is slumped asleep on the desk, the monitor off, with three z's standing above its head, still, and lit in turn — a pulse of brightness that starts at the character and travels up through z1, z2, z3 (ZZz), phase-offset per seat, 4 fps loop", reduced: "the static slumped pose, the three z's drawn once, every one at full brightness, and still" }),
    A7: Object.freeze({ class: 'held', animation: "attention — a raised hand and a marker above the desk, its glow lit and dark in turn by the sleeper's pulse of brightness, phase-offset per seat, 4 fps loop", reduced: 'a static raised-hand pose and the marker, at full brightness' }),
    A8: Object.freeze({ class: 'held', animation: 'stalled — head in hands, static, with the api_error_type line', reduced: 'identical' }),
    A9: Object.freeze({ class: 'held', animation: "unknown — a question marker over an occupied desk, its glow lit and dark in turn by the sleeper's pulse of brightness, phase-offset per seat, 4 fps loop", reduced: 'the question marker drawn once, at full brightness, and still' }),
    A10: Object.freeze({ class: 'edge', animation: 'intern-arrive / intern-leave — a stool at the side table fills or empties', reduced: 'the stool is simply occupied or empty' }),
    A11: Object.freeze({ class: 'edge', animation: 'badge-raise — a badge appears with a single 250 ms fade', reduced: 'the badge is simply present' }),
    A12: Object.freeze({ class: 'edge', animation: 'gauge — the context bar eases to its new value over 250 ms', reduced: 'the bar jumps to the value' }),
    A13: Object.freeze({ class: 'edge', animation: 'retire — the character stands, leaves, and the desk is removed from the floor (§ 3.5, card#9078: it used to clear the desk and stamp a plate)', reduced: 'the desk is simply absent on the next render' }),
    A14: Object.freeze({ class: 'edge', animation: 'feed-pulse — a one-frame pulse on the feed indicator', reduced: 'a last message HH:MM:SS readout that updates instead' }),
    A15: Object.freeze({ class: 'held', animation: 'catching-up — a replay marker sweeps the monitor, 4 fps loop', reduced: 'a static replay marker and the replaying label' }),
    A16: Object.freeze({ class: 'edge', animation: 'desk-move — a displaced character walks to its new desk', reduced: 'the desk appears in its new slot on the next render' }),
    A17: Object.freeze({ class: 'edge', animation: "room-tick — the wall clock's hands step to the viewer's current minute and the windows' sky is re-evaluated for that time", reduced: 'the hands jump to position and the sky steps to its new value with no cross-fade — the same fact, without the transition (§ 6.4)' }),
    // card#12239: the closed set's THIRD class. A `retired` row keeps its id — never renumbered — and has
    // no entry point here: nothing fires it and the log writes nothing for it (§ 6.2, decision 20).
    A18: Object.freeze({ class: 'retired', animation: "thread-line — retired by the operator's ruling of 2026-10-23 (card#12239); never drawn", reduced: 'none — a retired row has no form' }),
    A19: Object.freeze({ class: 'edge', animation: 'envelope — an envelope travels the line once, from the origin desk to each destination desk', reduced: 'a still envelope at the destination desk for the moving form\'s duration, then gone — never held' }),
    A20: Object.freeze({ class: 'edge', animation: 'broadcast-pulse — one ring expands from the origin desk across the floor', reduced: 'the origin desk carries a static broadcast marker for that post' }),
    A21: Object.freeze({ class: 'retired', animation: "call-marker — retired before it was built (card#12239's design round 1); never drawn", reduced: 'none — a retired row has no form' }),
    A22: Object.freeze({ class: 'edge', animation: "portal-bird — the bird carries the envelope from a resolved origin desk on this floor to the floor's bird portal and leaves, or arrives from the portal at a resolved destination desk on this floor, for a post whose other end resolves to a desk on another floor of this building", reduced: "a still envelope at the portal or at the desk for the moving form's duration, then gone" }),
    A23: Object.freeze({ class: 'held', animation: 'intern-type — each intern at the side table types at the laptop on its lap, swaying gently left and right, 4 fps loop', reduced: 'each intern sits still with its laptop on its lap' }),
});

/**
 * Every § 6.2 row and WHAT starts it in the shipped client — the partition that makes the set's
 * coverage legible rather than a thing a reader counts by hand.
 *
 *   · `delta`     — an `edge` row fired by a `seat.delta` this client APPLIED (`DELTA_ROWS`). A13
 *                   is fired by the `seat.retired` message too, which § 6.2 names beside the delta
 *                   as its Driving fact (`edges()`, Appendix B step 10).
 *   · `heartbeat` — an `edge` row fired by each `feed.heartbeat` RECEIVED.
 *   · `desk`      — a `held` render `desk/desk-render.js` selects for the state it draws, entered
 *                   and left by `held()` against the object that holds it: the desk's own (`held`), and
 *                   A23, its side table's (`interns_held`, card#12448), under the derived key `INTERNS`.
 *   · `slot`      — A16, whose trigger is § 3.3's displacement and therefore a fact about the
 *                   SLOT FUNCTION, Appendix B row 7's artifact. `displaced()` is the entry that
 *                   row calls, and `floor/floor-screen.js` calls it (card#7341 step 7); the log row
 *                   it writes was fully specified here before there was a caller (§ 11 names A16's
 *                   own `cause`), so what row 7 owed was the trigger and not a second decision.
 *   · `coord`     — A19/A20/A22, whose triggers are `coord/coord-model.js`'s `roundAnimations()` and
 *                   `fleetModel()` over each applied `coord.round` (card#8300, card#12239) and are NOT
 *                   this file's to re-mint. What this file owns is the LOG ROW: `seat_id` is `null` on
 *                   all three (they are drawn BETWEEN desks or between a desk and the birdhouse, and claim
 *                   nothing about one desk), `install_id` is the room of the desk on this floor the edge
 *                   leaves or reaches, and `cause` is the post's own `post_ref`.
 *   · `retired`   — A18 and A21 (card#12239): ids kept and never renumbered, which nothing fires and
 *                   for which the log writes nothing. A retired row has no entry point here at all.
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
    A18: 'retired',
    A19: 'coord',
    A20: 'coord',
    A21: 'retired',
    A22: 'coord',
    A23: 'desk',
});

/**
 * THE SIDE TABLE's EPISODE KEY (card#12448; FLOOR.md § 13's decision on A23): `<seat key>~interns`. `held()` holds
 * one open episode per key, and the desk's own key is taken by the one held row its `render_state` selects, so A23 —
 * held beside it, by the same seat object — is entered and left under this derived key. It is a key of the episode
 * map alone and never of the held seats: `held()` strips it to find the seat whose `state_version` is the entry's
 * `cause` and whose object the exit reads. The row it writes carries the seat's own `install_id` and `seat_id`.
 */
export const INTERNS = '~interns';

/**
 * The held renders one desk carries — the desk's own, then its side table's — each with its episode key's suffix, the
 * desk model's member it is, and the member of `held()`'s `conditions` entry that is its prior.
 */
const HOLDS = Object.freeze([
    Object.freeze({ suffix: '', field: 'held', prior: 'prior' }),
    Object.freeze({ suffix: INTERNS, field: 'interns_held', prior: 'prior_interns' }),
]);

/** A key of the episode map → its seat's key and the hold it is the episode of. */
function holdOf(key) {
    const interns = key.endsWith(INTERNS);

    return [interns ? key.slice(0, -INTERNS.length) : key, HOLDS[interns ? 1 : 0]];
}

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

/** § 11's `cause` for A13 fired by the retirement MESSAGE (§ 6.2: "or the `seat.retired` message"). */
export const RETIRED = 'seat.retired';

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
 * § 6.4's *identical* — the reduced-motion form of a row that has NO motion to replace, which is
 * the whole of what makes a held row static by design. § 11 names that population in words (*the
 * states with no motion by design*, its count in a word) and this is where it is DERIVED: a row whose reduced form
 * is its ordinary one is a row nothing was removed from.
 *
 * ⛔ IT IS NOT `loops()`, AND THE DIFFERENCE WAS A18 (retired by card#12239). `loops()` answers *does this row run frames at
 * § 12's rate*, which decides a frame interval; this answers *does this row move at all*. Every
 * held row of § 6.2 was a 4 fps loop or one of those states until A18 — a line whose reduced
 * cell says *drawn static … no travel along it*, so its ordinary form DOES move and it runs no
 * frame loop. Deriving motion from `loops()` drew that line with `motion: false`, which is § 11's
 * claim that one of its reasons applied when none did (card#7341 step 7, the step that first
 * fires the row).
 */
function staticByDesign(animationId) {
    return (ANIMATION_SET[animationId]?.reduced ?? null) === 'identical';
}

/**
 * ⛔ THE ONE PLACE § 6.4's CONDITION DECIDES `motion`, for every row of either class. § 11's
 * `motion` column names the reasons a row is drawn without it, and they meet here as three
 * inputs — a held render static by design (this set's own answer), the caller's `permitted`, and
 * reduced motion — so a second caller cannot answer the question differently.
 *
 * `permitted` is the CALLER's half and nothing more: for a held desk render it is the desk
 * render's verdict (`desk/desk-render.js` owns it — § 7.3's currency treatment of a `fold_lag`
 * badge or a `config_invalid` reporter, a value § 9 F9 does not recognise, and a floor § 9 F6
 * stilled), and for an `edge` row there is no treatment to consult and it is simply true.
 */
function motionOf(animationId, permitted, reduce) {
    const cls = classOf(animationId);

    // An id this closed set does not carry answers *no motion*, as every other reader of the set
    // answers for one: a caller passing one has a bug either way, and what this keeps is that the
    // bug looks the same wherever it lands.
    if (cls === null || cls === 'retired' || !permitted || reduce) {
        return false;
    }

    return cls === 'edge' || !staticByDesign(animationId);
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
 * ⛔ A1 AND A2 ARE EDGES BETWEEN § 7.1's *Desk* COLUMN's TWO SIDES, READ OFF THE OBJECT BEFORE AND
 * AFTER THE MERGE (card#9566, FLOOR § 6.2's walk note item 1). STAFFED is a value that column draws a
 * character for — `deskDrawsCharacter()` — and EMPTY is one it draws the empty chair for — `DESK`'s
 * `empty-chair` pose. ⚠ THE TWO ARE NOT COMPLEMENTS: `retired` draws neither and an unrecognised value
 * is neither, so *not staffed* is never read as *empty* — which is what keeps A2 off `working →` an
 * unrecognised value. Keyed on one side alone, A2 would fire on `stale → offline` and on a re-sent
 * `offline` (a walk out of a chair already empty), and A1 on `working → idle`.
 *
 * ⛔ AND NEITHER FIRES WITH A13, BY CONSTRUCTION RATHER THAN BY A CLAUSE. An `offline → retired`
 * delta is not an arrival and a `working → retired` one is not a departure: A13 removes the desk, and
 * a client that fired both would play a walk to or from a desk it was deleting. § 6.2 states the
 * exclusion on A1 and A2; it holds here because `retired` is on neither side — it is no `DESK` key and
 * is one of `NO_CHARACTER_STATES` — so a `!retiring` clause on either row could never be reached.
 * `retiring` below is A13's predicate alone.
 */
const retiring = (before, after) => after.render_state === 'retired';

/**
 * § 7.1's *Desk* column's empty chair, read off `desk/desk-poses.js`'s `DESK` — the column itself, in a
 * module of its own so this file imports nothing that imports it back.
 */
export const emptyChair = (state) => isRenderState(state) && DESK[state]?.pose === 'empty-chair';
/** § 7.1's *Desk* column draws a character for it — exported with `emptyChair` for the walk's side (`floor/in-flight.js`). */
export const staffed = (state) => deskDrawsCharacter(state);

const DELTA_ROWS = Object.freeze([
    // A1's and A2's exclusions of A13 (§ 6.2) hold by construction rather than by a clause: `retired` is
    // neither staffed nor an empty chair, so each side's own predicate refuses it. Reading either side as
    // the other's complement is the defect that would let a retirement walk in or out too.
    Object.freeze({ id: 'A1', changed: 'render_state', fires: (before, after) => emptyChair(before.render_state) && staffed(after.render_state) }),
    Object.freeze({ id: 'A2', changed: 'render_state', fires: (before, after) => staffed(before.render_state) && emptyChair(after.render_state) }),
    Object.freeze({ id: 'A5', changed: 'action', fires: (before, after) => toolName(before) !== toolName(after) }),
    Object.freeze({ id: 'A10', changed: 'subagents', fires: (before, after) => !sameMembers(callIds(before), callIds(after)) }),
    Object.freeze({ id: 'A11', changed: 'badges', fires: (before, after) => gained(badges(before), badges(after)) }),
    Object.freeze({ id: 'A12', changed: 'context', fires: () => true }),
    Object.freeze({ id: 'A13', changed: 'render_state', fires: retiring }),
]);

/** The `changed[]` member each delta-driven row is gated on — read by the closed-set assertions. */
export const DELTA_ROW_DRIVERS = Object.freeze(Object.fromEntries(DELTA_ROWS.map((r) => [r.id, r.changed])));

/** § 11's literal `cause` for exit (3): § 2.3 row 5's condition drew the empty chair. */
export const UNCONFIRMED = 'unconfirmed';

/** § 11's literal `cause` for exit (4): § 9 F6/F7 stilled the floor. */
export const STILLED = 'stilled';

/**
 * The `cause` of one desk episode's `left` row — `docs/design/FLOOR.md § 11`'s precedence, which
 * owns the rule, asked in its order: (1), (2a), (3), (2b), (4). `open` is the episode being left;
 * `conditions` is `held()`'s parameter of that name.
 *
 * ⛔ THE OBJECT IS ASKED ABOUT UNDER THE PREVIOUS RENDER'S CONDITIONS, which is what `prior` is. An
 * object held unchanged since that render draws exactly what that render drew — the episode, still
 * open — so (2a) and (2b) never fire on it and a literal names the exit; an object applied since
 * then that draws another row (or none) is (2a) even where a literal also changed, and one that
 * changes only `motion` yields to (3) and is named ahead of (4).
 *
 * ⚠ THE LAST `null` IS NO STEP APPLYING — a render § 11 says ends no episode. Nothing reaches it
 * while the held rendering is a function of the object, row 5's condition, the stilled floor and
 * reduced motion; `null` is the log's mark of a row with nothing behind it, which AT-D3-1 reds on.
 */
function exitCause(k, open, seats, removed, conditions, hold) {
    if (!seats.has(k)) {
        // (1): the removal's answer — the retired object's version, which both announcements carry
        // (§ 3.5), or § 2.3 row 4's `snapshot`, which the protocol journals as the removal's cause.
        return removed.get(k) ?? null;
    }

    const version = seats.get(k).state_version;
    const { unconfirmed, stilled, [hold.prior]: prior } = conditions.get(k);

    if (prior === null || prior.animation_id !== open.animation_id) {
        return version; // (2a)
    }

    if (unconfirmed) {
        return UNCONFIRMED; // (3)
    }

    if (prior.motion !== open.motion) {
        return version; // (2b)
    }

    if (stilled) {
        return STILLED; // (4)
    }

    return null;
}

export class AnimationSet {
    #log;

    #reduce;

    /**
     * The `edge` rows this surface draws — every one when the constructing caller names none. The lobby
     * names `['A17']` (card#7343): its sky behind the building is A17's value and the lobby draws no
     * other § 6.2 row — no feed pulse, no desk, no thread — so a heartbeat on the lobby writes an A17
     * row and nothing else, and a delta writes none (§ 6.2 A17: *"On the lobby it is this row or
     * nothing"*).
     */
    #edgeRows;

    /** key → the open `held` episode: `{ episode_id, animation_id, motion }`. */
    #episodes = new Map();


    /**
     * @param {object} log  `wire/animation-log.js`'s `createAnimationLog()` — the one instrument
     *                      every row below is written through
     * @param {{reduce?: boolean, edgeRows?: Array<string>}} [options]  `reduce` is § 6.4's condition,
     *                      as a page reads it from `prefers-reduced-motion`: a rendering, never a
     *                      degradation; `edgeRows` the `edge` rows the surface draws (`#edgeRows`)
     */
    constructor(log, options = {}) {
        this.#log = log;
        this.#reduce = options.reduce === true;
        this.#edgeRows = options.edgeRows === undefined ? null : new Set(options.edgeRows);
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

            // § 6.2 A13's Driving fact names TWO messages — "`render_state == "retired"`, or the
            // `seat.retired` message" — and the client may receive either first (§ 2.5). The delta is
            // `DELTA_ROWS`' below; this is the message, and § 11's `cause` for it is the message type,
            // as it is for the heartbeat's two rows. The protocol journals the SECOND announcement of
            // one retirement as `discarded`, so one removal fires A13 once, in either arrival order.
            if (entry.t === RETIRED) {
                if (entry.outcome === 'applied') {
                    this.#edge('A13', RETIRED, entry.install_id, entry.seat_id, at);
                }

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
     * the ARRIVING seat's key (or a departure's, § 3.5), or, where an applied delta changed which
     * seat the reserved desk seats (§ 3.2, card#11144), that delta's `state_version` — while the row
     * itself names the desk that MOVED.
     *
     * ⚠ THE CALLER IS `floor/floor-screen.js` (card#7341 step 7), and it is the slot function's
     * own answer rather than a diff of two renders: the screen re-assigns § 3.2's slots over the
     * new seat set and compares each incumbent's slot with the one it held. This entry existed
     * before that caller so that the step read § 6.4's form and § 11's `cause` off this set rather
     * than minting a second answer.
     */
    displaced(installId, seatId, cause, at) {
        this.#edge('A16', cause, installId, seatId, at);
    }

    /**
     * § 6.2 A19: one envelope leaves the origin desk for EACH destination desk, so the caller calls
     * this once per resolved destination — "a destination that does not resolve gets no envelope and
     * no line, and the ones that do still get theirs". `cause` is § 11's forward contract for this
     * row: the post's own `post_ref`.
     */
    envelope(installId, postRef, at) {
        this.#edge('A19', postRef, installId, null, at);
    }

    /** § 6.2 A20: ONE ring per broadcast post, expanding from the origin desk it resolved to. */
    broadcast(installId, postRef, at) {
        this.#edge('A20', postRef, installId, null, at);
    }

    /**
     * § 6.2 A22: the PORTAL BIRD (card#12239) — one row per departure from an origin desk on this floor
     * toward its birdhouse (once per post), and one per arrival at a destination desk here from it.
     * `installId` is the room of the desk on THIS floor the bird leaves or reaches; `cause` is the
     * post's own `post_ref`, as A19's and A20's.
     */
    portal(installId, postRef, at) {
        this.#edge('A22', postRef, installId, null, at);
    }

    /**
     * Every `edge` row goes through here, so `motion` has one answer for the whole class — and so does
     * whether the surface draws the row at all (`#edgeRows`).
     */
    #edge(animationId, cause, installId, seatId, at) {
        if (this.#edgeRows !== null && !this.#edgeRows.has(animationId)) {
            return;
        }

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
     * holds it, and leave the ones whose hold has ended with the `cause` § 11's precedence gives.
     *
     * ⛔ A DESK CARRIES TWO HOLDS (card#12448): its own, and its side table's A23 under `INTERNS`'s
     * derived key, each entered and left by the same rule below against the same seat object.
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
     * @param {Map<string, *>} removed key → the `cause` the protocol journalled when it REMOVED the
     *        seat (`seat.removed`, Appendix B step 10): a removed seat has no held object left to read
     *        a version off, so the version that ended its hold travels with the removal instead
     * @param {Map<string, {prior: ?object, prior_interns: ?object, unconfirmed: boolean, stilled: boolean}>} conditions
     *        key → what § 11's precedence compares with the render before this one, for each seat
     *        held now: `prior`, the held rendering the object held NOW draws with every client
     *        condition at the value it had in the previous render (the desk floor derives it, because
     *        the desk render is its module and not this one), and `prior_interns` the same for its side
     *        table's (A23, card#12448); `unconfirmed`, whether § 2.3 row 5's
     *        condition holds in this render and did not in the previous one; and `stilled`, the same
     *        for § 9 F6's stilled floor
     */
    held(desks, seats, at, removed, conditions) {
        for (const [key, open] of this.#episodes) {
            const [k, hold] = holdOf(key);
            const want = desks[k]?.[hold.field] ?? null;

            if (want === null || want.animation_id !== open.animation_id || want.motion !== open.motion) {
                this.#log.leaveHeld(open.episode_id, { cause: exitCause(k, open, seats, removed, conditions, hold), at });
                this.#episodes.delete(key);
            }
        }

        for (const [k, desk] of Object.entries(desks)) {
            for (const hold of HOLDS) {
                const rendering = desk[hold.field] ?? null;
                const key = k + hold.suffix;

                if (rendering === null || this.#episodes.has(key)) {
                    continue;
                }

                const episodeId = this.#log.enterHeld({
                    animation_id: rendering.animation_id,
                    cause: seats.get(k).state_version,
                    install_id: desk.install_id,
                    seat_id: desk.seat_id,
                    motion: rendering.motion,
                    at,
                });

                this.#episodes.set(key, {
                    episode_id: episodeId,
                    animation_id: rendering.animation_id,
                    motion: rendering.motion,
                });
            }
        }
    }
}
