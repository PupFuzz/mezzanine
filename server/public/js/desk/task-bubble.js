/**
 * The desk's THOUGHT BUBBLE — `docs/design/FLOOR.md § 5.1`'s one rendered form of `task`, and
 * the six rules stated under that table's `task` row.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY DECISION THE BUBBLE MAKES IS IN THIS FILE. Whether one is drawn at all, what text it
 * holds, where the text is cut, and where a bubble sits when two of them would collide are all
 * decided here and are exercised under `node` by `tests/Feature/Desk`. What is NOT here is a DOM
 * half, and that is deliberate rather than unfinished: THERE IS NO FLOOR PAGE YET — § 4.4's
 * `/floor/{install_id}` route is card#9208-blocked on a D2 read surface for an authored map, and
 * `drilldown/main.js` records the same gap for the same reason ("the element contract is the
 * floor page's to declare and the floor page's test to hold"). A `main.js` written now would
 * invent element ids nobody will serve. What the floor page needs from this module is a model it
 * can draw and a MEASURER it must supply — see `bubbleLayout` below.
 *
 * ⛔ THE BUBBLE REPLACES THE TEXT CHIP; IT DOES NOT JOIN IT (§ 5.1, rule 1). "A chip surviving
 * beside a bubble would be one fact drawn twice", which § 2.4's one-rendered-form-per-fact rule
 * refuses. There is no chip in this tree to delete — the desk is being built with the bubble as
 * its only `task` form — and this line is the next reader's warning not to add one.
 *
 * ⛔ NOTHING HERE MOVES (§ 5.1, rule 6): the bubble "appears, changes and disappears on the frame
 * the delta is applied, with no fade, no drift, no bob and no float", which is why the amendment
 * needed no § 6.2 animation row. So this module holds NO timer, NO interval, NO frame callback
 * and NO transition, and the desk suite re-mints exactly that defect to prove the guard can
 * fail. § 5.1 refuses the upstream bubble's 0.15 / 1.2 / 0.3 s state machine outright and says
 * why: a fade-out on a timer destroys rule 2, because *no bubble* would then mean "the task is
 * null, OR the linger expired", and a null render two different facts produce is not a null
 * render at all.
 */

import { taskFacts } from '../wire/task.js';
import { isRenderState } from '../lobby/render-state.js';

/**
 * The `render_state` members whose desk draws NO CHARACTER — read out of § 7.1's **Desk**
 * column, whose cells for these three are "**empty chair**, desk dimmed", "empty chair, desk
 * dark" and "**no desk**". Every other member's cell puts a character at the desk.
 *
 * ⛔ THIS IS A RESTATEMENT OF A MARKDOWN TABLE AND IT IS GUARDED, NOT TRUSTED. A browser cannot
 * read § 7.1, so the set cannot be deleted in favour of a pointer; the desk suite re-derives it
 * from `FLOOR.md` on every run and set-differences it against this array IN BOTH DIRECTIONS,
 * exactly as `LobbyMemberSetMatchesTheDocumentTest` does for § 7.1's member list.
 * ⛔ AND IT IS A DERIVED SET, NOT A PUBLISHED ONE — § 7.1 publishes the cells, not this
 * partition of them — which is why the guard parses the CELLS rather than comparing against a
 * hand-written expected list that would be a third copy free to agree with this one.
 */
export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired']);

/**
 * § 5.1 rule 3: "A desk that draws no character draws no bubble … A bubble floating over an
 * empty chair would draw a thinker who is not there."
 *
 * ⛔ AN UNRECOGNISED `render_state` DRAWS NO CHARACTER EITHER, and this is a reading taken rather
 * than a rule quoted — § 7.1's Desk column is defined over its ten members and says nothing about
 * an eleventh. The bubble is ANCHORED to the character, so a state whose desk this document does
 * not describe is a state with no known anchor, and drawing over it would claim a thinker the
 * page cannot know is there. The fact is not lost: § 5.4's unrecognised render is the DESK's to
 * draw (it carries the raw string and says it is unrecognised) and the drill-down still carries
 * `task` at full fidelity.
 */
export function deskDrawsCharacter(renderState) {
    return isRenderState(renderState) && !NO_CHARACTER_STATES.includes(renderState);
}

/**
 * § 5.1 rule 4's character cap, and the mark that makes a cut visible: "a title too long for the
 * bubble is truncated **with a mark**".
 *
 * ⚠ THE NUMBER IS THIS MODULE'S AND D3 PUBLISHES NONE — deliberately: "The cap's value is a
 * function of the art's type size and the zoom it is read at, neither of which § 10.4 specifies,
 * so a number written here would be a number about a drawing this document does not own", and
 * § 12 carries no row for it. What D3 requires is that a cap EXIST, that the box be MEASURED and
 * that the truncation be MARKED, and those three are what this module implements. The cap is
 * REACHABLE rather than theoretical: D2 § 8.2.1 bounds `task.title` at 120 B.
 */
export const MAX_CHARS = 48;

/** The mark itself — one character, so the cut stays inside the cap rather than beside it. */
export const TRUNCATION_MARK = '…';

/** The separator between the two members rule 4 names, when the wire carries both. */
export const REF_SEPARATOR = ' — ';

/**
 * § 5.1 rule 4: "The text is `task.ref` and `task.title`". `task.ref` is null on every seat this
 * deployment serves (`wire/task.js` says why), so today this is the title alone — and § 5.6's
 * `task.ref` row is what that renders as: "the title renders with **no link and no reference
 * text** — not an empty link, not *(no reference)*".
 *
 * ⛔ NO LINK IS EVER GUESSED. The bubble draws the reference as TEXT, never as a URL: § 5.2 puts
 * the link on the drill-down's row, under a configured base, because "a guessed URL is a link
 * that goes somewhere wrong, which is worse than no link". `wire/task.js` resolves the href for
 * the surface D3 gives one to, and this one does not take it.
 *
 * `null` when there is nothing to draw — including a `task` object carrying no title, which D2
 * marks non-null and which is therefore a wire violation rather than a case: the honest render
 * of it is the absence, never a placeholder (§ 5.1 rule 2).
 */
export function bubbleText(facts) {
    const title = typeof facts?.title === 'string' && facts.title !== '' ? facts.title : null;

    if (title === null) {
        return null;
    }

    const ref = typeof facts.ref === 'string' && facts.ref !== '' ? facts.ref : null;
    const full = ref === null ? title : ref + REF_SEPARATOR + title;
    const points = [...full];

    if (points.length <= MAX_CHARS) {
        return { text: full, truncated: false };
    }

    // Cut by CODE POINT rather than by UTF-16 unit, so a cap applied to an astral character
    // cannot split it into a replacement glyph — the one way a truncation mark lies about what
    // was cut. The mark is inside the cap: the drawn string is never longer than MAX_CHARS.
    return {
        text: points.slice(0, MAX_CHARS - 1).join('') + TRUNCATION_MARK,
        truncated: true,
    };
}

/**
 * The bubble for one seat, or `null` for no bubble at all.
 *
 * ⛔ THE TWO ABSENCES § 5.1 DISTINGUISHES RETURN THE SAME VALUE, ON PURPOSE. A null `task`
 * (§ 5.6: "**no thought bubble at all**, never an empty bubble and never a placeholder title")
 * and a desk with no character (rule 3) are different facts with the SAME render, and a caller
 * that could tell them apart is a caller that could draw them apart. Returning `null` for both
 * makes the placeholder unwritable rather than merely forbidden.
 *
 * What it carries when it is drawn:
 *   - `text` / `truncated` — rule 4, above.
 *   - `source` — `task.source`, the tier that answered, RAW off the wire. § 14 item 4's standing
 *     instruction for the floor built today: "tier 3 only, with `task.source` rendered so the
 *     tier is legible", which is D2's obligation T19 — "a floor showing tier 3 everywhere is
 *     visibly a floor whose board integration is dark".
 *   - `degraded_note` — T19's other half, in `wire/task.js`'s one wording, non-null only when
 *     `task.degraded`.
 *
 * ⚠ `task.as_of` IS DELIBERATELY NOT CARRIED, and since 2026-09-12 that is a RULED exclusion
 * rather than an unstated one: D3 § 5.1 rule 4 states it, and § 13 decision 26 records it with
 * its alternative and its reversal cost (operator ruling, card#7897). § 5.1's `task` row still
 * lists the member among the bubble's fields — the list was deliberately NOT trimmed, so the
 * exclusion is stated rather than the member merely being absent. The reason is § 2.4's: an
 * instant drawn beside a title is read as the title's freshness, and those are different claims.
 * The member is rendered at full fidelity in the drill-down (§ 5.2).
 */
export function taskBubble(seat, options = {}) {
    if (!deskDrawsCharacter(seat?.render_state)) {
        return null;
    }

    const facts = taskFacts(seat?.task ?? null, options.ref_bases);

    if (facts === null) {
        return null;
    }

    const text = bubbleText(facts);

    if (text === null) {
        return null;
    }

    return {
        text: text.text,
        truncated: text.truncated,
        source: facts.source,
        degraded_note: facts.degraded_note,
    };
}

/** The gap left between two bubbles the resolver has to part. Layout, and this module's own. */
export const SEPARATION_GAP_PX = 6;

/**
 * § 5.1 rule 5: "Two bubbles that would overlap are separated DETERMINISTICALLY, and the
 * separation carries no fact. The resolution is a pass over the **base** rects — the desks' own
 * geometry, in a fixed order — rather than over already-displaced ones, so two browsers
 * rendering one fleet place them identically with nothing stored."
 *
 * @param bubbles  the bubbles to place, each `{ install_id, seat_id, anchor, text }` — the
 *                 anchor is the character's own point, in the floor's coordinates, and the box
 *                 is raised from it. Input ORDER is not read: the pass runs in § 3.1's identity
 *                 order, `(install_id, seat_id)`, which is the only fixed order this page has.
 * @param measure  the page's own text measurement, `(text) => ({ w, h })`.
 *
 * ⛔ THE MEASURER IS REQUIRED AND IS NEVER DEFAULTED. Rule 4: "the box is never sized from a
 * guess at the text's width", because "a box of fixed width would meet [the bound] by SILENTLY
 * CLIPPING, and a title clipped with no mark is read as the whole title — a claim about the wire
 * the wire did not make". A default here would be that guess, so a caller with nothing to
 * measure with gets a throw rather than a plausible number.
 *
 * ⛔ EVERY COLLISION IS TESTED BETWEEN BASE RECTS, INCLUDING FOR THE ONES ALREADY LIFTED.
 * Resolving against displaced rects is what makes a layout depend on its own output: a
 * neighbour's title changing would then walk a cascade across the floor, and "a bubble does not
 * jitter when a neighbour's title changes". A bubble's displacement is a function of the BASE
 * geometry alone and carries no fact about its seat.
 */
export function bubbleLayout(bubbles, measure) {
    if (typeof measure !== 'function') {
        throw new TypeError("bubbleLayout needs the page's own text measurer — § 5.1 rule 4 forbids sizing a bubble from a guess");
    }

    const ordered = [...bubbles].sort((a, b) => (identity(a) < identity(b) ? -1 : 1));

    const base = ordered.map((bubble) => {
        const { w, h } = measure(bubble.text);

        return {
            install_id: bubble.install_id,
            seat_id: bubble.seat_id,
            // Centred on the character and sitting above it: the anchor is the bubble's own
            // bottom edge, which is the point rule 3 anchors to.
            x: bubble.anchor.x - w / 2,
            y: bubble.anchor.y - h,
            w,
            h,
        };
    });

    return base.map((rect, i) => {
        let lifted = 0;

        for (let j = 0; j < i; j++) {
            if (overlaps(rect, base[j])) {
                lifted++;
            }
        }

        return { ...rect, y: rect.y - lifted * (rect.h + SEPARATION_GAP_PX), lifted };
    });
}

/** § 3.1's key, and the whole of it: the pair, in a form that sorts. */
function identity(bubble) {
    return [bubble.install_id, bubble.seat_id].join(' ');
}

/** Rect intersection, exclusive on the edges: two boxes that merely touch do not overlap. */
function overlaps(a, b) {
    return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
}
