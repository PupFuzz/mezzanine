/**
 * ONE DESK, LAID OUT INSIDE ITS FURNITURE BOX — `docs/design/FLOOR.md` Appendix B row 14 and
 * § 10.3's `desks` row: the RULED GLANCE SET (§ 5.1's *the glance set*, the operator's ruling of
 * 2026-10-02, card#11058 Q0 (a) + Q1 (B)), placed at the rects `deskRects()` derives from the box,
 * together with the scene's ONE TRUNCATION PRIMITIVE, `fit()`.
 *
 * ⛔ THE DESK DRAWS EXACTLY THE RULED SET AND NOTHING ELSE: the character (or the chair), the desk,
 * the monitor and its text, the nameplate on its plate, the state chip, the label line, the currency
 * label, the lag line, the context bar and its %, a badge row of two (the treatment badges, then
 * `PINNED_BADGES`, then recognised badges in the wire's order), ONE flag `⚠ +N` for every unusual item whose raw form is
 * not drawn, the interns, the quiet age, § 7.4's fade, § 7.3's dimming and the bubble. Every other
 * fact — and every raw unrecognised string — is the drill-down's and the desk list's
 * (`drilldown/main.js`, `desk/desk-list.js`). `Tests\Feature\Floor\TheNewDeskKeepsEveryLeafTest` holds
 * both halves: every leaf the desk drew before card#11058 is carried by the panel AND the list, and
 * the desk draws exactly the ruled set.
 *
 * ⛔ NO RAW UNRECOGNISED STRING IS DRAWN ON THE DESK (Q0, read literally): the chip reads the fixed
 * word *unrecognised* for an unrecognised state, the label line, currency label and monitor draw the
 * model's non-raw forms (`desk_label`, `desk_currency`), and an unrecognised badge is never a chip —
 * it is counted into the flag.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERYTHING DRAWN FOR A DESK AT REST, EXCEPT THE BUBBLE, LIES INSIDE THE BOX — by construction,
 * at the worst case § 10.3 names: § 8's cap of interns with the *+N more* tag, the badge row of two
 * with the flag, and every string cut to the width it is given. The rects are a table derived from
 * the box (`deskRects()`), never a number of their own. That is what makes card#7341's
 * ruling true of a floor: § 3.2 gives distinct seats distinct slots, the map's slots are disjoint,
 * and a desk drawn inside its own slot cannot reach a neighbour's whatever the seat beside it
 * carries. `Tests\Feature\Floor\SeatFurnitureNeverOverlapsTest` (AT-D3-20) reads the rects this
 * returns.
 *
 * ⛔ EVERY STRING GOES THROUGH `fit()` AND NO DRAWING SITE CUTS ONE ITSELF (§ 10.3). A string the
 * fixtures never stretch — the currency label, the lag line, the gauge's % — is cut by the same code
 * as the ones they do, which is the only reason AT-D3-20's second RED,
 * planted in `fit()`, can speak for them. The monitor's text, the one string laid on more than one line
 * (card#12446), goes through `wrap()`, which breaks the lines and leaves the cut on the last to `fit()`.
 *
 * ⛔ THE STRINGS ARE THE DESK MODEL's (`desk/desk-render.js`), each as that model decided it. What
 * this module adds is WHERE: which column, which row, how wide. Three strings the model leaves as
 * numbers are worded here and say so (`OPEN_CALLS`, `MORE`, `FLAG_TEXT`).
 *
 * ⛔ THE POPULATION IS THE MODEL's OUTPUT, NOT A LIST WRITTEN HERE. `DRAWN_MEMBERS` and
 * `NOT_DRAWN_MEMBERS` below partition every member `deskModel()` returns, and
 * `Tests\Feature\Floor\TheSceneDrawsEveryDeskMemberTest` set-differences that partition against the
 * model's real keys in both directions — so a member added to the desk model reds until it is drawn
 * here or excluded by name with a reason.
 */

import { TRUNCATION_MARK } from '../desk/task-bubble.js';
import { BADGES } from '../wire/member-sets.js';
import { UNRECOGNISED } from '../desk/desk-render.js';

/** The pitch every text row is laid on, in scene pixels — the page's font is chosen to fit it. */
export const LINE = 12;

/** The page's desk text: the font the painter measures and draws with, sized to `LINE`. */
export const FONT = '10px sans-serif';

/**
 * The NAME role (§ 5.1's nameplate, the operator's ruling of 2026-10-02 on card#11058 Q2: "the
 * nameplate at 13 px bold — a second measured type role"): the one string the desk draws in it is the
 * nameplate, measured and drawn in this font, on this line.
 */
export const FONT_NAME = 'bold 13px sans-serif';

export const LINE_NAME = 16;

/**
 * The SCREEN role (FLOOR.md § 10.6's *The desk, re-laid*, card#11046 — the operator's "The monitor screen
 * text can be smaller so that more letters can fit"): the one string the desk draws in it is the monitor's
 * text, measured and drawn at 7 px on an 8 px line, wrapped to as many lines as the screen's text rect holds
 * (§ 12's *Screen type size* row). It was 8 px on the facts' 12 px line, one line, until card#12446 — the
 * operator's "Reduce monitor font size by 1 point and allow text to wrap lines to fill the entire screen".
 */
export const FONT_SCREEN = '7px sans-serif';

export const LINE_SCREEN = 8;

/**
 * THE DESK's THREE TYPE ROLES — the facts, the nameplate (Q2) and the monitor's screen (card#11046) — the
 * font each is measured and drawn in, its line, and the baseline's offset from the line's top. `fit()`
 * measures in a role, the page's measurer answers in a role
 * (`painter.js`'s `measurer()`), and the painter draws a text at its role's baseline; a role not in
 * this table is refused by the measurer rather than measured in a guessed font.
 */
export const TYPE_ROLES = Object.freeze({
    fact: Object.freeze({ font: FONT, line: LINE, baseline: LINE - 2 }),
    name: Object.freeze({ font: FONT_NAME, line: LINE_NAME, baseline: LINE_NAME - 3 }),
    screen: Object.freeze({ font: FONT_SCREEN, line: LINE_SCREEN, baseline: 6 }),
});

/** The screen's text inset, every side, from the screen's rect (§ 12's monitor row). */
export const SCREEN_INSET = 3;

/**
 * The bubble's inner padding — the text's inset from the bubble's rect. The padding holds the cloud's puffs (`CLOUD_PUFF`): the cloud is drawn INSIDE the rect, its
 * scallops reaching the rect's edge and its valleys `CLOUD_PUFF` in from it, so the text clears every valley and
 * the rect AT-D3-20 *(b)* reads is the cloud's own extent (card#11468).
 */
export const BUBBLE_PAD = 6;

/** The cloud's puff radius: each scallop of the bubble's outline is a half-circle of at most this radius (§ 5.1, card#11468). */
export const CLOUD_PUFF = 4;

/**
 * The thought trail (§ 5.1, card#11468; card#12446): the radii of its circles, from the one just above the creature's
 * head to the one nearest the cloud — each larger than the last, so the trail reads as a thought rising — and the least
 * gap between two. The trail is ALWAYS these circles and no more (the operator's ruling of 2026-10-28, *"There should be
 * fewer small bubbles from the character's head to the actual thought bubble. Instead, each one should grow progressively
 * in size"*): a longer trail spaces them further apart rather than drawing more (`trailCircles()`).
 */
export const TRAIL_RADII = Object.freeze([2, 3, 4]);

export const TRAIL_GAP = 1;

/**
 * THE TRAIL's LEAST REACH: `TRAIL_RADII` with a `TRAIL_GAP` under each circle and above the last — the least gap a trail
 * spans between a head and its bubble (`bubbleSeat()`), and the height the character's overdraw rises above its rect
 * (`characterOverdraw()`, the column the sleeper's z's are drawn in).
 */
export const TRAIL_REACH = TRAIL_RADII.reduce((h, r) => h + 2 * r + TRAIL_GAP, TRAIL_GAP);

/**
 * THE BUBBLE's TITLE LINES (§ 5.1, card#12446 — the operator's *"allow the text in the thought bubble to wrap to 3
 * lines"*): the title wraps at word boundaries on at most this many lines through the scene's one wrap (`wrap()`), the
 * last cut with the mark, and the source line is drawn under them.
 */
export const BUBBLE_TITLE_LINES = 3;

/**
 * THE TALLEST BUBBLE, in lines and in pixels (§ 5.1): the title's lines and the source line (the source and the degraded
 * note), padded. The least box (`leastBoxHeight()`) and the desk's art placeholder read it, so a taller bubble grows the
 * box rather than leaving it.
 */
export const BUBBLE_LINES = BUBBLE_TITLE_LINES + 1;

export const BUBBLE_BAND = BUBBLE_LINES * LINE + 2 * BUBBLE_PAD;

/** The gap a bubble keeps above the mark a pose draws over the head — the sleeper's z's, the `?` or the `!` (`bubbleSeat()`). */
export const BUBBLE_GAP = 1;

/** The art column's width — the character, the desk, the monitor, the chip and the plate (§ 2.2: Chosen). */
export const ART_W = 216;

/**
 * THE BUBBLE's WIDEST (card#12446, the operator's ruling (B) of 2026-10-28): the art column's width, so a bubble set on
 * its character — centred on the character's line, kept inside the box — never reaches the facts plate, whose left edge
 * is the art column's (`FACTS_PLATE_PAD`), and its title wraps rather than widening.
 */
export const BUBBLE_MAX_W = ART_W;

/**
 * § 7.4's FOLD-LAG FADE (card#12346, the operator's ruling of 2026-10-25 — FLOOR.md § 12's *Fold-lag fade*
 * row): the opacity a `fold_lag` desk's art pass — the chair, the character, the desk, the monitor's frame, the
 * desk props and the side table, or § 9 F14's placeholder standing in for them — is drawn at. Every fact stays
 * at full opacity, the nameplate and the lag line among them.
 */
export const FOLD_LAG_OPACITY = 0.5;

/** The gap between the art column and the facts column. */
export const GUTTER = 4;

/**
 * THE FACTS PLATE (§ 10.6's *The desk, re-laid*, card#11468 — the operator's "A is fine", 2026-10-07): the
 * facts column's one soft rounded backdrop. Its padding across is 4, so its left edge is the art column's edge
 * (`ART_W`); 6 down; its corners `FACTS_PLATE_RX`.
 */
export const FACTS_PLATE_PAD = 4;

export const FACTS_PLATE_PAD_Y = 6;

export const FACTS_PLATE_RX = 8;

/**
 * The facts column's rows, top first — the order they stack in. A row the desk does not carry takes no place:
 * the stack is bottom-anchored just above the side table and as tall as the rows it holds, and the plate is its
 * size (card#11468). `gauge` is one row holding the bar and its %.
 */
export const FACT_ROWS = Object.freeze(['label', 'currency', 'lag', 'gauge', 'badge', 'flag', 'quiet-age']);

/** The fact element kinds the plate is drawn behind — every element a `FACT_ROWS` row emits. */
export const PLATED_KINDS = Object.freeze(['label', 'currency', 'lag', 'gauge-bar', 'gauge-pct', 'badge', 'flag', 'quiet-age']);

/**
 * The character rect over the tree's footprint unit `SCENE_W × SCENE_H` (`resources/characters/index.js`): the
 * rect is this many times that (§ 12's *Character rect* row, Chosen). Sized by card#11058 at 3 for the pixel
 * tree and kept when the vector creatures replaced it (card#11046): they have no native size, so the unit is
 * the rect's aspect and this its scale. The operator's ask of 2026-10-26 (card#12444, *"The character sizes are
 * good"*) made the characters 25 % taller: 3.75, the rect growing UPWARD from a foot line that does not move
 * (`deskRects()`), and the furniture box is the least that holds the desk (`leastBoxHeight()`).
 */
export const CHARACTER_SCALE = 3.75;

/** § 8's cap on the interns drawn: the array D2 caps at 8 (§ 8.1's chosen cap). */
export const STOOL_CAP = 8;

/** The side table's least seat count (§ 12's *Side-table seats* row): an empty couch shows its seat cushions. */
export const SIDE_TABLE_SEATS = 4;

/** A seat's height on the side table, along its foot line (§ 10.6; row 20's flat shape). */
const SEAT_H = 6;

/**
 * D2 § 8.2.1's bound on `badges` — the union of D1's `degraded` members and D2 § 7.2's server-derived
 * badges, of which `epoch_reset` is in both — which is exactly the published member set, so it is that
 * set's size rather than a second number (§ 12's *`badges` bound* row).
 */
export const BADGE_BOUND = BADGES.size;

/** The badges drawn on the desk — Q1 (B): "up to two badges (treatment first)" (§ 12, Chosen). */
export const BADGES_SHOWN = 2;

/**
 * One badge chip's width, and the pitch the row lays them at (§ 12, Chosen): two chips and their gap are the
 * facts column's width inside the plate's padding (card#11468; 108 / 112 before the plate).
 */
export const BADGE_W = 104;

const BADGE_PITCH = 108;

/** A badge chip's inner padding: an id of at most ⌊(BADGE_W − 2 × BADGE_PAD) ÷ glyph width⌋ glyphs fits. */
const BADGE_PAD = 3;

/**
 * One intern's sprite rect (§ 8, § 10.4's art contract): 1.75 times card#11058's 20 × 32, the operator's ruling of
 * 2026-10-27 on card#12444 (*"Instead of twice today's size, try 1.75x today's size"*, superseding option D's 2×), its
 * aspect kept so the chibi frame's height in units is unchanged (`CHIBI_VIEWBOX`, 108 units over the rect's width). The
 * tree states the same rect for its chibi frame (`resources/characters/creatures.js`'s `CHIBI_STOOL`), held equal to this
 * one by `tools/characters/selftest.mjs` § 7b.
 */
export const STOOL_W = 35;

export const STOOL_H = 56;

/**
 * THE INTERNS' ROWS (the same rulings): `STOOL_CAP` interns in `STOOL_ROWS` rows of `STOOL_CAP / STOOL_ROWS`, the front row
 * on the desk's foot line, each row behind it `STOOL_H + STOOL_ROW_GAP` higher and each intern `STOOL_PITCH` right of the
 * last (`stoolSlots()`). The spacing is the LEAST the INK allows (the operator's *"Spacing between subagents can still be
 * reduced further"*, 2026-10-27): two neighbours' FRAMES may overlap, and no two interns' INK may touch.
 * `tools/characters/selftest.mjs` § 8b derives each on every run, from the chibi ink it measures over every species at the
 * widest girth and size — the plain chibi and the intern at its laptop, still and at both extremes of its fixed sway
 * (card#12448) — and reds when one is not that least, so a wider chibi or a larger sway re-derives them:
 * - `STOOL_PITCH`, the fewest whole pixels at which the rightmost ink any intern draws stays clear of the leftmost ink any
 *   neighbour draws one pitch over;
 * - `STOOL_FRAME`, the intern's FRAME across (`internOverdraw()`): the fewest whole pixels, centred on the rect, that hold
 *   every chibi's ink, so no frame clips a head;
 * - `STOOL_ROW_GAP`, the least at which no two interns' rects meet — the chibi frame is the rect's own height, its ink
 *   inside it top and bottom, so a row may stand on the rect of the row in front of it.
 * Where frames overlap, the PAINT ORDER keeps every drawing whole: the back row before the front, each row left to right
 * (`deskLayout()` emits the interns so). AT-D3-20 holds the rows at the cap inside the box, and the studio theme's seat
 * rows are held to these by `tools/floor-themes/selftest.mjs`.
 */
export const STOOL_ROWS = 2;

export const STOOL_PITCH = 41;

export const STOOL_FRAME = 41;

export const STOOL_ROW_GAP = 0;

/** Where the stool rows start, left of the facts column by this much: the side table's own inset (card#12444). */
const STOOL_INSET = 4;

/**
 * § 9 F14's per-stool fallback: the glyph an intern whose art failed is drawn as, inside its sprite
 * rect (dashed when untitled). One intern's art failing falls back for that stool alone — never the
 * desk's placeholder, which stands in for the seat's own art.
 */
export const STOOL_GLYPH = 8;

/** The state chip's widest box, and its inner padding. */
const CHIP_MAX_W = 160;

const CHIP_PAD = 6;

/** The nameplate's text width inside its 160 px plate. */
const PLATE_TEXT_W = 148;

/** The badges a § 7 treatment reads — § 7.3's `config_invalid` and § 7.4's `fold_lag`. */
export const TREATMENT_BADGES = Object.freeze(['config_invalid', 'fold_lag']);

/**
 * The badges drawn after the treatment badges and ahead of every other — `FLOOR.md § 13` row 75 (the
 * operator's ruling (C) of 2026-10-25, amending row 44's order): `update_required`, so the mark the
 * operator's rule asks for is folded into the flag only when both treatment badges are present. NOT a
 * treatment: the desk is not treated as not-current for it (§ 7.2). Row 75 lists what reversing the
 * ruling to the wire's order would cost; in this module it empties this list and moves nothing else.
 */
export const PINNED_BADGES = Object.freeze(['update_required']);

/**
 * § 5.1's open-call count, which the model carries as a number.
 *
 * § 5.1: the count renders "when it exceeds 1", in the wording the operator ratified (card#7342,
 * 2026-09-25) — the number and the member it counts, nothing else. The DRILL-DOWN and the LIST draw
 * it (§ 5.1's row, card#11058); the desk does not.
 */
export const OPEN_CALLS = (n) => `${n} open calls`;

/** § 8's and § 10.3's *+N more*, the document's own words, for the interns past the cap. */
export const MORE = (n) => `+${n} more`;

/** The flag's glyph — ⚠ NOT RATIFIED, a display form (design § 7's *unratified display forms*). */
export const FLAG = '⚠';

/** The flag's text: the glyph and the count N (`flagCount()`). */
export const FLAG_TEXT = (n) => `${FLAG} +${n}`;

/** The flag chip's inner padding. */
const FLAG_PAD = 4;

/**
 * THE SCENE's ONE TRUNCATION PRIMITIVE (§ 10.3): `text` cut to `width` with a visible mark, by the
 * page's own measurer IN THE TYPE ROLE THE TEXT IS DRAWN IN (`TYPE_ROLES`) — never clipped silently
 * and never drawn past the edge. A string measured in one role and drawn in another is a width that
 * lies, so the role is the caller's to state and the measurer's to answer in.
 *
 * Cut by CODE POINT, for `desk/task-bubble.js`'s reason: a cut that splits an astral character
 * draws a replacement glyph, which is the one way a truncation mark lies about what was cut.
 *
 * @param {string} role a key of `TYPE_ROLES`; every desk string but the nameplate and the monitor's text is the fact role
 * @returns {{text: string, truncated: boolean, w: number, h: number}}
 */
export function fit(text, width, measure, role = 'fact') {
    const whole = measure(text, role);

    if (whole.w <= width) {
        return { text, truncated: false, w: whole.w, h: whole.h };
    }

    const points = [...text];
    let lo = 0;
    let hi = points.length;

    // The longest prefix whose cut form fits: a binary search over the prefix length.
    while (lo < hi) {
        const mid = Math.ceil((lo + hi) / 2);

        if (measure(points.slice(0, mid).join('') + TRUNCATION_MARK, role).w <= width) {
            lo = mid;
        } else {
            hi = mid - 1;
        }
    }

    const cut = points.slice(0, lo).join('') + TRUNCATION_MARK;
    const size = measure(cut, role);

    return { text: cut, truncated: true, w: size.w, h: size.h };
}

/**
 * THE SCENE's ONE WRAP (card#12446): `text` laid on at most `maxLines` lines of `width`, measured in `role` by the
 * page's measurer as `fit()` is. A line breaks at a word boundary — a space, which the break drops — and where one
 * word is wider than a whole line (a path, a URL) inside the word, by code point, so no line is drawn past the
 * width. The last line is `fit()`'s: whatever does not fit on it is cut there with the mark, so a wrapped string is
 * truncated visibly or not at all, by the same primitive as every other desk string.
 *
 * @param {number} maxLines at least 1
 * @returns {{text: string, lines: list<{text: string, w: number}>, truncated: boolean, w: number, h: number}}
 *          `text` is what the lines draw read as one string: the whole of `text`, or, cut, the prefix the lines hold
 *          and the mark; `w` the widest line; `h` the lines on the role's line (`TYPE_ROLES`)
 */
export function wrap(text, width, measure, role, maxLines) {
    const points = [...text];
    const lines = [];
    let start = 0;
    let last = 0;
    let truncated = false;

    while (lines.length < maxLines && start < points.length) {
        const rest = points.slice(start).join('');

        last = start;

        if (lines.length === maxLines - 1 || measure(rest, role).w <= width) {
            const cut = fit(rest, width, measure, role);

            lines.push({ text: cut.text, w: cut.w });
            truncated = cut.truncated;
            break;
        }

        // The longest run from `start` that fits — at least one code point, so a glyph wider than the line still
        // moves on — then back to the last space inside it; a run with no space in it is broken where it stops.
        const end = start + Math.max(1, longestRun(points, start, width, measure, role));
        const space = points[end] === ' ' ? end : points.lastIndexOf(' ', end - 1);
        const stop = space > start ? space : end;
        const line = points.slice(start, stop).join('');

        lines.push({ text: line, w: measure(line, role).w });
        start = stop;

        while (points[start] === ' ') {
            start++;
        }
    }

    return {
        text: truncated ? points.slice(0, last).join('') + lines[lines.length - 1].text : text,
        lines,
        truncated,
        w: Math.max(0, ...lines.map((l) => l.w)),
        h: lines.length * TYPE_ROLES[role].line,
    };
}

/** The most code points of `points` from `start` whose run measures within `width` in `role` — a binary search. */
function longestRun(points, start, width, measure, role) {
    let lo = 0;
    let hi = points.length - start;

    while (lo < hi) {
        const mid = Math.ceil((lo + hi) / 2);

        if (measure(points.slice(start, start + mid).join(''), role).w <= width) {
            lo = mid;
        } else {
            hi = mid - 1;
        }
    }

    return lo;
}

/**
 * THE DESK's RECT TABLE — § 2.2's, derived from the box (`resources/floor/furniture-box.js`'s one
 * declaration): every element's rect relative to the box's top-left — the art from the desk's slab, the
 * facts column stacked up from the side table on the desk's foot line, the hatch and the placeholder from the
 * tallest bubble's height (`BUBBLE_BAND`) under the box's top. A larger box keeps every rect inside; AT-D3-20 reds when the box shrinks below the table at
 * the cap. The facts rows here are the stack AT THE CAP; a desk's own stack is `deskLayout()`'s.
 *
 * @param {{width: number, height: number}} box the furniture box
 * @param {{w: number, h: number}} character the character tree's own `SCENE_W × SCENE_H`
 * @returns {object} `kind → {x, y, w, h}` — `chip.x` / `chip.w` are the chip's centre line and widest
 *          box, the chip being as wide as its word
 */
export function deskRects(box, character) {
    const W = box.width;
    const H = box.height;
    const top = BUBBLE_BAND;
    const slab = H - 100;
    const colB = ART_W + GUTTER;
    // The facts are cut inside the plate's right padding, so the plate never reaches past the box.
    const widthB = W - colB - FACTS_PLATE_PAD;
    const mid = ART_W / 2;
    const cw = character.w * CHARACTER_SCALE;
    const ch = character.h * CHARACTER_SCALE;
    // § 10.6: the desk is 180 wide under the art column's centre, and the creature's centre stands at a
    // third of its width (the operator's ruling of 2026-10-06, card#11046); the chair follows it.
    const desk = { x: mid - 90, y: slab, w: 180, h: 60 };
    const sitter = desk.x + desk.w / 3 - cw / 2;
    // The monitor's frame: 96 × 46, its right edge the desk's, standing on the desk's top; the screen
    // inset 4 px inside it and 31 px tall; the text `SCREEN_INSET` inside the screen on every side, so its
    // rect's height is what decides how many lines the screen holds (§ 12's monitor row, card#12446).
    const frame = { x: desk.x + desk.w - 96, y: slab - 46, w: 96, h: 46 };
    const screen = { x: frame.x + 4, y: frame.y + 4, w: 88, h: 31 };
    // § 10.6 (card#11468): the side table stands on the desk's own foot line, its interns in front of it and the
    // *+N more* tag under it; the facts stack upward from just above it, inside the plate's padding. These row
    // rects are the stack AT THE CAP — every row present — and `deskLayout()` lays the rows a desk carries
    // bottom-up from the same foot (`stackRows()`).
    const foot = desk.y + desk.h;
    // The interns' rows (ruling D, card#12444): the front row on the foot line, the rest behind it; the facts stack up
    // from just above the back row, and the side table runs from the back row's foot line down to the desk's, so every
    // intern stands at a seat of it.
    const perRow = STOOL_CAP / STOOL_ROWS;
    const rowStep = STOOL_H + STOOL_ROW_GAP;
    const stoolX = colB - STOOL_INSET;
    const rows = { x: stoolX, y: foot - STOOL_ROWS * STOOL_H - (STOOL_ROWS - 1) * STOOL_ROW_GAP, w: W - stoolX, h: 0 };
    rows.h = foot - rows.y;
    const table = { x: colB - 8, y: foot - 42 - (STOOL_ROWS - 1) * rowStep, w: W - colB + 8, h: 42 + (STOOL_ROWS - 1) * rowStep };
    const rowY = stackRows(FACT_ROWS, rows);

    return Object.freeze({
        // THE FOOT LINE is pinned 26 px under the desk's slab, where card#11058's rect stood it (card#12444): a taller
        // rect grows UPWARD, and the chair and the desk keep their place under it.
        character: { x: sitter, y: slab + 26 - ch, w: cw, h: ch },
        chair: { x: sitter, y: slab - 38, w: cw, h: 64 },
        placeholder: { x: 0, y: top, w: ART_W, h: H - 36 - top },
        'desk-sprite': desk,
        'monitor-frame': frame,
        // The desk's left third: the lamp and its light, the mug or the plant (§ 10.6).
        'desk-props': { x: desk.x - 12, y: slab - 34, w: 68, h: 50 },
        monitor: screen,
        'monitor-text': { x: screen.x + SCREEN_INSET, y: screen.y + SCREEN_INSET, w: screen.w - 2 * SCREEN_INSET, h: screen.h - 2 * SCREEN_INSET },
        chip: { x: mid, y: H - 16, w: CHIP_MAX_W, h: 14 },
        label: { x: colB, y: rowY.label, w: widthB, h: LINE },
        currency: { x: colB, y: rowY.currency, w: widthB, h: LINE },
        lag: { x: colB, y: rowY.lag, w: widthB, h: LINE },
        'gauge-bar': { x: colB, y: rowY.gauge + 2, w: 60, h: 8 },
        'gauge-pct': { x: colB + 64, y: rowY.gauge, w: 60, h: LINE },
        badge: { x: colB, y: rowY.badge, w: BADGE_W, h: LINE },
        flag: { x: colB, y: rowY.flag, w: widthB, h: LINE },
        'quiet-age': { x: colB, y: rowY['quiet-age'], w: widthB, h: LINE },
        // the first slot of `stoolSlots()` — the front row's left — and the rows it heads (`perRow` a row)
        stool: { x: stoolX, y: foot - STOOL_H, w: STOOL_W, h: STOOL_H, per_row: perRow, row_step: rowStep },
        'stool-rows': rows,
        // § 10.6: drawn at every desk with art, under the facts and over the *+N more* row; its foot line (its
        // bottom edge) is the desk's and the front row's, and its seats stand under every row (ruling D).
        'side-table': table,
        'stool-more': { x: colB, y: foot + 2, w: widthB, h: LINE },
        plate: { x: mid - 80, y: H - 36, w: 160, h: 18 },
    });
}

/**
 * THE TOP OF THE CHARACTER's FRAME on its centre line (`characterCentre()`), box-relative (§ 5.1): the character rect's
 * top, the line the tallest crest reaches — where the bubble is set, and where the thought trail starts when the scene
 * has no drawn head to start it at (`scene.js`'s `crestOf()` lowers it to the head's own crest).
 *
 * @returns {{x: number, y: number}}
 */
export function trailFoot(box, character) {
    const head = deskRects(box, character).character;
    const x = head.x + head.w / 2;

    return Object.freeze({ x, y: head.y });
}

/**
 * THE THOUGHT TRAIL (§ 5.1, card#11468 — the operator's "starting with a small bubble and increasing in size
 * until reaching the top card status bubble"; card#12446 — the operator's ruling of 2026-10-28, *"There should be fewer
 * small bubbles from the character's head to the actual thought bubble. Instead, each one should grow progressively in
 * size"*): the circles from a character's head `top` up to its bubble's bottom `bottom`, on the line `x`, smallest first
 * — always `TRAIL_RADII`, each larger than the one under it, and a longer trail spaced further apart rather than drawn
 * with more: the gap's slack is shared evenly under each circle and above the last, so the last circle stands as far
 * under the cloud as the first stands over the creature. Their sizes say nothing about the seat (§ 5.1 rule 2).
 *
 * @param {number} x the trail's line @param {number} top the character's drawn head, or the line `bubbleSeat()` starts it at
 * @param {number} bottom the bubble's bottom, at least `TRAIL_REACH` above `top`
 * @returns {list<{x: number, y: number, r: number}>} each circle's centre and radius
 */
export function trailCircles(x, top, bottom) {
    const gap = (top - bottom - 2 * TRAIL_RADII.reduce((sum, r) => sum + r, 0)) / (TRAIL_RADII.length + 1);
    let y = top;

    return Object.freeze(TRAIL_RADII.map((r) => {
        y -= gap + r;
        const circle = Object.freeze({ x, y, r });

        y -= r;

        return circle;
    }));
}

/**
 * The thought trail at its LEAST REACH from the character rect's top, box-relative (`trailCircles()` from `trailFoot()` up
 * `TRAIL_REACH`): the column the character's overdraw rises to and the sleeper's z's keep out of (`characterOverdraw()`).
 * Where a bubble is set, and so where its trail runs, is `bubbleSeat()`'s.
 */
export function thoughtTrail(box, character) {
    const foot = trailFoot(box, character);

    return trailCircles(foot.x, foot.y, foot.y - TRAIL_REACH);
}

/**
 * The character tree's frame units per floor pixel: a seat's `SEAT_RECT` (108 × 192 units) is drawn into the
 * character's rect, `CHARACTER_SCALE` times the tree's `SCENE_W × SCENE_H` (`resources/characters/index.js`'s
 * `SCENE_W` note). The floor cannot import the tree (it loads apart, and may fail — § 9 F14), so the figure is
 * stated here and held equal to the tree's own (`SEAT_RECT.w ÷ (SCENE_W × CHARACTER_SCALE)`) by
 * `tools/characters/selftest.mjs` § 7b.
 */
export const FRAME_UNITS_PER_PX = 1.6;

/**
 * THE CHARACTER's OVERDRAW AND ITS CLEAR RECTS (FLOOR.md § 10.4's frame contract, § 12's *Character frame*;
 * card#12187 for the sleeper, card#12259 for every pose). Every seat document is drawn in the OVERDRAW — the
 * character's rect widened by half its width each side and raised to the top of the thought trail at its normal reach,
 * a pixel under where its bubble sits (`TRAIL_REACH`) — while the character element's own rect, which every overlap rule reads, is unchanged. The sleeper's
 * z's stand above and beside its head, past the rect; a standing or walking body's tilt and lean carry its widest parts
 * past the rect's side, which the painter's viewport would clip (§ 14 item 33(6)). Beside it the CLEAR rects: the
 * floor's furniture the z's keep out of — the monitor's frame, the desk's props, the desk and the thought trail's
 * column — in the tree's frame units from the character rect's top-left, so no figure of the desk is copied into the
 * tree. One function, read by the layout, the tree's caller and the measuring tool alike
 * (`tools/design/sleeper-zs.browser.mjs`).
 *
 * @returns {{overdraw: {x: number, y: number, w: number, h: number}, clear: list<{name: string, x: number, y: number, w: number, h: number}>}}
 *          the overdraw box-relative in px, the clear rects in frame units
 */
export function characterOverdraw(box, character) {
    const R = deskRects(box, character);
    const c = R.character;
    const trail = thoughtTrail(box, character);
    const top = Math.min(...trail.map((t) => t.y - t.r));
    const reach = Math.max(...TRAIL_RADII);
    const column = { x: trail[0].x - reach, y: top, w: 2 * reach, h: c.y - top };
    const unit = (r) => Object.freeze({ x: (r.x - c.x) * FRAME_UNITS_PER_PX, y: (r.y - c.y) * FRAME_UNITS_PER_PX, w: r.w * FRAME_UNITS_PER_PX, h: r.h * FRAME_UNITS_PER_PX });

    return Object.freeze({
        overdraw: Object.freeze({ x: c.x - c.w / 2, y: top, w: 2 * c.w, h: c.y + c.h - top }),
        clear: Object.freeze([['monitor', R['monitor-frame']], ['props', R['desk-props']], ['desk', R['desk-sprite']], ['trail', column]]
            .map(([name, r]) => Object.freeze({ name, ...unit(r) }))),
    });
}

/**
 * THE INTERN's OVERDRAW (FLOOR.md § 8, § 10.4's frame contract item 3; card#12259): the intern's chibi frame is drawn
 * in its stool's rect widened to `STOOL_FRAME`, centred — `(STOOL_FRAME − STOOL_W) / 2` px each side, what holds every
 * chibi's ink — and a neighbour's frame may overlap it (the pitch is the ink's, `STOOL_PITCH`), while the stool element's
 * own rect, which the dashed edge of an untitled intern and every overlap rule read, is unchanged. The tree's
 * `CHIBI_VIEWBOX` is this rect in the chibi frame's units (the rect's 108 units over its `STOOL_W` px), held equal to it
 * by `tools/characters/selftest.mjs` § 7b.
 *
 * @param {{x: number, y: number, w: number, h: number}} stool the intern's rect, box-relative
 * @returns {{x: number, y: number, w: number, h: number}} the overdraw, box-relative in px
 */
export function internOverdraw(stool) {
    const margin = (STOOL_FRAME - stool.w) / 2;

    return Object.freeze({ x: stool.x - margin, y: stool.y, w: stool.w + 2 * margin, h: stool.h });
}

/**
 * THE INTERNS' SLOTS (ruling D, card#12444) — the `STOOL_CAP` places a desk lays interns at, as an ORDERED list, row-major:
 * the front row left to right, then the row behind it, so a stable slot index (card#12450's per-call slot) is a place
 * on the table. Each slot is the intern's rect, box-relative, with `row`, `col`, `index`, the `pitch` (`STOOL_PITCH`, the
 * least the ink allows) and its `overdraw` (`internOverdraw()`).
 *
 * @returns {list<{x: number, y: number, w: number, h: number, index: number, row: number, col: number, pitch: number,
 *          overdraw: object}>}
 */
export function stoolSlots(box, character) {
    const R = deskRects(box, character);
    const { per_row: perRow, row_step: rowStep } = R.stool;
    const pitch = STOOL_PITCH;

    return Object.freeze(Array.from({ length: STOOL_CAP }, (_, index) => {
        const row = Math.floor(index / perRow);
        const col = index % perRow;
        const at = { x: R.stool.x + col * pitch, y: R.stool.y - row * rowStep, w: R.stool.w, h: R.stool.h };

        return Object.freeze({ ...at, index, row, col, pitch, overdraw: internOverdraw(at) });
    }));
}

/**
 * WHERE A BUBBLE IS SET ON ITS CHARACTER (§ 5.1; card#12444's *"anchor the bubble to the character"*, and card#12446 —
 * the operator's ruling of 2026-10-28, *"The bubble can also be lowered"*, with the seat's bounds: never over the eyes,
 * the mouth or the facts plate, and the trail still leading to the head), box-relative: `{ bottom, foot }`, the base
 * rect's bottom and the line the thought trail starts at. `head` is the character tree's `headClear()` for the seat in
 * its pose — `face`, the topmost ink of its eyes, brows and mouth, and `mark`, the top of what the pose draws over the
 * head (the sleeper's z's, the `?` or the `!`), or `null` where it draws none — in frame units under the rect's top;
 * `crest` is its drawn head (`crestTop()`), in the same units.
 *
 * The bubble is set as LOW as those allow: `TRAIL_REACH` above the face, so the whole trail fits between the face and
 * the cloud, and `BUBBLE_GAP` above the mark. It may overlap the top of the head — ears, a hat, a crown — and then the
 * trail starts `TRAIL_REACH` under it, on the head; under a lower head it starts at the head's crest. With no `head`
 * (no tree, or a seat it does not hold — § 9 F14) the bubble is set as high as any seat's is, `BUBBLE_GAP` above the
 * character's frame, which holds every mark (§ 10.4's frame contract) — the one height `leastBoxHeight()` reads.
 *
 * @param {{face: number|null, mark: number|null}|null} head @param {number} crest
 * @returns {{bottom: number, foot: number}}
 */
export function bubbleSeat(box, character, head, crest) {
    const top = trailFoot(box, character).y;
    const highest = characterOverdraw(box, character).overdraw.y - BUBBLE_GAP;
    const px = (units) => top + units / FRAME_UNITS_PER_PX;
    const bottom = head === null
        ? highest
        : Math.min(head.face === null ? Infinity : px(head.face) - TRAIL_REACH, head.mark === null ? Infinity : px(head.mark) - BUBBLE_GAP);

    return Object.freeze({ bottom, foot: Math.max(px(crest), bottom + TRAIL_REACH) });
}

/**
 * THE LEAST FURNITURE BOX for a width (ruling D, card#12444: *"the desk area grows taller again to hold the second
 * row"*, by what the layout needs and no more): the smallest whole-pixel height at which the tallest bubble
 * (`BUBBLE_BAND`) stays inside the box set as high as `bubbleSeat()` ever sets one — `BUBBLE_GAP` above the character's
 * frame — and the facts plate at the cap does too. A bubble is never wider than the art column (`BUBBLE_MAX_W`), so it
 * never reaches the plate and is never held above it (card#12446). `resources/furniture-box.js`'s height is held equal to
 * it for its width by `tools/characters/selftest.mjs` § 7b, so the box is derived, never guessed.
 *
 * @returns {number}
 */
export function leastBoxHeight(width, character) {
    for (let height = BUBBLE_BAND; height < 4096; height++) {
        const box = { width, height };
        const R = deskRects(box, character);

        if (bubbleSeat(box, character, null, 0).bottom - BUBBLE_BAND >= 0 && R.label.y - FACTS_PLATE_PAD_Y >= 0) {
            return height;
        }
    }

    throw new RangeError(`no furniture box ${width} wide holds the desk`);
}

/**
 * THE CLOUD (§ 5.1, card#11468 — "drawn like a cloud"): the bubble's outline, a scalloped edge of half-circle
 * puffs laid round the rect inset by `CLOUD_PUFF`, each bulging outward. An edge of length L takes
 * ⌈L ÷ 2·CLOUD_PUFF⌉ puffs of equal width, so a puff's radius is at most `CLOUD_PUFF` and its crown at most the
 * rect's edge: the cloud lies INSIDE the rect, which is what AT-D3-20 *(b)* and § 5.1 rule 5 read as the
 * bubble. Traced clockwise from the inset rect's top-left corner, as one closed SVG path.
 *
 * @param {{x: number, y: number, w: number, h: number}} rect the bubble's measured rect
 * @returns {{puffs: list<{x: number, y: number, r: number, side: string}>, d: string}} each puff's centre (on the
 *          inset rect's edge), radius and the side it bulges to, and the path drawn
 */
export function cloudOutline(rect) {
    const p = CLOUD_PUFF;
    const [x0, y0, x1, y1] = [rect.x + p, rect.y + p, rect.x + rect.w - p, rect.y + rect.h - p];
    const edges = [
        ['top', [x0, y0], [x1, y0]],
        ['right', [x1, y0], [x1, y1]],
        ['bottom', [x1, y1], [x0, y1]],
        ['left', [x0, y1], [x0, y0]],
    ];
    const puffs = [];
    const round = (v) => Math.round(v * 1000) / 1000;
    let d = `M ${round(x0)} ${round(y0)}`;

    for (const [side, [ax, ay], [bx, by]] of edges) {
        const length = Math.hypot(bx - ax, by - ay);
        const n = Math.max(1, Math.ceil(length / (2 * p)));
        const r = length / n / 2;

        for (let i = 0; i < n; i++) {
            const [ex, ey] = [ax + ((bx - ax) * (i + 1)) / n, ay + ((by - ay) * (i + 1)) / n];

            puffs.push(Object.freeze({ x: ax + ((bx - ax) * (i + 0.5)) / n, y: ay + ((by - ay) * (i + 0.5)) / n, r, side }));
            // Clockwise round the rect, so sweep 1 bulges every puff outward.
            d += ` A ${round(r)} ${round(r)} 0 0 1 ${round(ex)} ${round(ey)}`;
        }
    }

    return Object.freeze({ puffs: Object.freeze(puffs), d: `${d} Z` });
}

/**
 * THE FACTS STACK (card#11468): each row in `rows` (a subsequence of `FACT_ROWS`, in its order) its top y, the
 * last row ending `2 + FACTS_PLATE_PAD_Y` above the side table — so the plate, which pads the rows by
 * `FACTS_PLATE_PAD_Y`, ends 2 px above the table and no text row meets the table (§ 10.6's rule 3).
 *
 * @returns {object} row → y, box-relative
 */
export function stackRows(rows, table) {
    const bottom = table.y - 2 - FACTS_PLATE_PAD_Y;

    return Object.fromEntries(rows.map((row, i) => [row, bottom - (rows.length - i) * LINE]));
}

/**
 * THE DESK's ANCHOR — the character rect's centre line, box-relative (FLOOR.md § 10.6: the bubble is
 * anchored to the character, § 5.1 rule 3, and the coordination birds and the walks meet the desk where its
 * sitter sits). The one primitive every anchor reads, so a re-laid character moves them all.
 */
export function characterCentre(box, character) {
    const r = deskRects(box, character).character;

    return r.x + r.w / 2;
}

/**
 * WHERE A THREAD, AN ENVELOPE, A RING OR A WALK MEETS THE DESK (§ 5.7, § 10.6) — box-relative: the character's
 * centre line, at the desk's own mid-height. The height is the desk's rather than the box's (card#11468): every
 * facts plate ends above the side table, whose top is below the desk's top, so a line between two desks of one
 * row runs at a height no plate of either reaches, and meets each desk under its art.
 */
export function deskAnchor(box, character) {
    const d = deskRects(box, character)['desk-sprite'];

    return { x: characterCentre(box, character), y: d.y + d.h / 2 };
}

/**
 * THE ONE DEFINITION OF N (§ 5.1's *the glance set*, § 12's flag row): the number of unusual items
 * whose raw form is not drawn on the desk = every line of the model's `unrecognised` list
 * (`field: value` — `render_state`, `link_state`, `activity_state`, `unknown_reason`,
 * `api_error_type` and `badges: <id>` alike, since none of them is drawn) + every RECOGNISED badge not
 * in the row. D2 § 8.2.1 forbids duplicate badge ids; a duplicate is counted per occurrence, here and
 * in the guard alike.
 */
export function flagCount(desk, row) {
    return desk.unrecognised.length + clusterOrder(desk).length - row.length;
}

/**
 * Every member `deskModel()` returns, and the element kinds that draw it. A member a desk carries a
 * `null` for draws nothing — § 5.6's null render is the model's, already decided.
 */
export const DRAWN_MEMBERS = Object.freeze({
    nameplate: ['nameplate'],
    glyph: ['chip'],
    character: ['character', 'chair'],
    pose: ['character', 'chair'],
    lighting: ['desk-sprite', 'desk-props'],
    render_state: ['chip'],
    desk_label: ['label', 'monitor-text'],
    desk_currency: ['currency'],
    lag: ['lag'],
    monitor: ['monitor', 'monitor-text', 'monitor-frame'],
    quiet_age: ['quiet-age'],
    gauge: ['gauge-bar', 'gauge-pct'],
    badges: ['badge', 'flag'],
    unrecognised: ['flag'],
    side_table: ['side-table', 'stool', 'stool-more'],
    bubble: ['bubble'],
    held: ['character'],
    // card#12443: the delivered body the character is drawn in, and the body each intern is kept off.
    species: ['character', 'stool'],
    // § 6.2 A23 (card#12448): the interns' held render, drawn as each intern's laptop still or typing loop.
    interns_held: ['stool'],
    // Drawn on the chip's hollow dashed form; the desk's dimming is the group's lighting class (FLOOR.md decision 63).
    unconfirmed: ['chip'],
});

/**
 * The members the desk model returns that no element draws, each with the reason. Every one the
 * ruling of 2026-10-02 moved off the desk is printed by the drill-down AND the desk list — which
 * `TheNewDeskKeepsEveryLeafTest` checks of every leaf the desk drew before, rather than taking the
 * reason's word for it.
 */
export const NOT_DRAWN_MEMBERS = Object.freeze({
    install_id: "the desk's key with `seat_id` (§ 3.1) — it positions the desk; the room is the floor's to name",
    seat_id: 'drawn as the nameplate, which is the same string (§ 5.1)',
    dark: "its two strings are `label_line`'s own — `labelLine()` splices them in, and drawing them again would be one fact twice",
    label_line: 'drawn in its non-raw form, `desk_label` (Q0: no raw unrecognised string on the desk); the line itself, raw string '
        + 'included, is the drill-down\'s header and the desk list\'s',
    currency_label: 'drawn in its non-raw form, `desk_currency` (Q0); the label itself is the drill-down\'s and the desk list\'s',
    config_note: "§ 7.3's *sending nothing* — the drill-down's and the desk list's (Q1 B); the desk keeps the `config_invalid` badge "
        + 'and its dimming',
    open_calls: "§ 5.1's *N open calls* — the drill-down's and the desk list's (Q1 B)",
    action: "the action's start and *running for* — the drill-down's and the desk list's (Q1 B, Q5 b); the desk's monitor shows "
        + 'the descriptor',
    last_kind: 'the drill-down\'s and the desk list\'s (Q1 B)',
    last_event_time: 'the drill-down\'s and the desk list\'s (Q1 B)',
    model_label: 'the drill-down\'s and the desk list\'s (Q1 B)',
    oldest_badge_since: 'the drill-down\'s and the desk list\'s (Q1 B); the desk draws the badge row and the flag',
});

/**
 * One desk's elements, relative to its box's top-left, in § 2.2's layer order (bottom first): the
 * painter draws them in this order — the art pass first (the chair, the character, the desk, the
 * monitor's frame, the desk props, the side table), then every fact (FLOOR.md § 10.6's rule 2) — so a
 * `fold_lag` desk's faded art (§ 7.4, `FOLD_LAG_OPACITY`) is all under the facts, which keep full opacity.
 *
 * @param {object} desk one desk model (`desk/desk-render.js`'s `deskModel()`)
 * @param {object} ctx `{ box: {width, height}, measure, character: {w, h}, theme: string|null,
 *        placeholder: boolean, failed: Set<string> }` — `theme` the floor's theme that draws the furniture
 *        set; `placeholder` is § 9 F14's: every fact, no art; `failed` the asset ids the painter reported,
 *        which an intern's sprite is looked up in
 * @returns {{elements: list<object>, bubble: object|null}}
 */
export function deskLayout(desk, ctx) {
    const { measure } = ctx;
    const R = { ...deskRects(ctx.box, ctx.character) };
    const slots = stoolSlots(ctx.box, ctx.character);
    const elements = [];

    // card#11468: the facts column STACKED — the rows this desk carries, in `FACT_ROWS`' order, bottom-up from
    // just above the interns' back row (ruling D, card#12444), with no empty row for one it does not carry. Same facts, same widths; only
    // their y is the stack's. A row is carried exactly when its element is emitted below.
    const badgeRow = clusterOrder(desk).slice(0, BADGES_SHOWN);
    const carried = {
        label: present(desk.desk_label),
        currency: present(desk.desk_currency),
        lag: present(desk.lag?.line),
        gauge: desk.gauge.reported,
        badge: badgeRow.length > 0,
        flag: flagCount(desk, badgeRow) > 0,
        'quiet-age': present(desk.quiet_age),
    };
    const rows = FACT_ROWS.filter((row) => carried[row]);
    const rowY = stackRows(rows, R['stool-rows']);

    for (const row of rows) {
        if (row === 'gauge') {
            R['gauge-bar'] = { ...R['gauge-bar'], y: rowY.gauge + 2 };
            R['gauge-pct'] = { ...R['gauge-pct'], y: rowY.gauge };
        } else {
            R[row] = { ...R[row], y: rowY[row] };
        }
    }

    const rect = (kind, member, r, extra = {}) => {
        elements.push({ kind, member, x: r.x, y: r.y, w: r.w, h: r.h, ...extra });
    };

    const text = (kind, member, value, r, extra = {}) => {
        if (!present(value)) {
            return null;
        }

        // A rect taller than one line of its role holds as many lines as fit in it (the screen's, card#12446),
        // wrapped; every other text is one line, cut.
        const role = extra.role ?? 'fact';
        const rows = Math.floor(r.h / TYPE_ROLES[role].line);
        const cut = rows > 1 ? wrap(String(value), r.w, measure, role, rows) : fit(String(value), r.w, measure, role);
        const e = {
            kind, member, x: r.x, y: r.y, w: cut.w, h: cut.h, text: cut.text, truncated: cut.truncated,
            ...(rows > 1 ? { lines: cut.lines } : {}),
            ...extra,
        };

        elements.push(e);

        return e;
    };

    // § 8 / Q1 (B): the interns drawn, up to the cap and none hidden; a skewed wire past the cap is
    // counted into the tag, never dropped.
    const stools = desk.side_table.stools;
    const shown = stools.slice(0, STOOL_CAP);
    const more = (desk.side_table.more ?? 0) + (stools.length - shown.length);

    // ── THE ART PASS, then THE FACTS (FLOOR.md § 10.6's rule 2): every fact element is emitted — and so
    // painted — after every art element and the character, so no art can cover a fact wherever their rects
    // lie. § 9 F14's placeholder stands in for the art, and the facts are drawn over it as on an intact desk.
    if (ctx.placeholder) {
        rect('placeholder', null, R.placeholder);
    } else {
        // § 10.6 item 8: the desk's FURNITURE SET — one theme document per art element, each at its rect, all
        // drawn or none (the painter generates the set before it emits any), reported under one asset id.
        const key = { install_id: desk.install_id, seat_id: desk.seat_id };
        const set = furnitureAsset(ctx.theme, desk);
        const doc = (fn, input = key) => ({ set, theme: ctx.theme, fn, input });

        // § 10.6: the chair stands behind every creature; with nobody in it, it is § 7.1's empty chair —
        // an absence, never a sleeper (§ 7.5).
        rect('chair', 'character', R.chair, { pose: desk.pose, unconfirmed: desk.unconfirmed, occupied: desk.character, doc: doc('chair') });

        if (desk.character) {
            // `desk_top`: where the desk's top edge crosses the character's rect, as a share of its height — what a
            // pose that reaches the desk meets (the sleeper's arms and head, § 10.4's frame contract item 4).
            rect('character', 'character', R.character, {
                asset: characterAsset(desk),
                // card#12443: the delivered body (`character.species`), which the painter hands the tree.
                species: desk.species,
                pose: desk.pose,
                // § 7.1's glyph reaches the tree with the pose (card#12444): `present` with `question` draws the question's
                // face and its `?` marker, which one pose cannot say alone.
                glyph: desk.glyph,
                animation: desk.held,
                desk_top: (R['desk-sprite'].y - R.character.y) / R.character.h,
                // § 10.4's frame contract (card#12187, card#12259): every character image is drawn in its overdraw,
                // past the element's rect — a sleeper's z's and a standing or walking body's tilted parts both
                // reach past it — and a sleeper's z's keep out of the clear rects the tree is handed.
                ...characterOverdraw(ctx.box, ctx.character),
            });
        }

        rect('desk-sprite', 'lighting', R['desk-sprite'], { lighting: desk.lighting, doc: doc('desk') });
        rect('monitor-frame', 'monitor', R['monitor-frame'], { doc: doc('monitorFrame') });
        rect('desk-props', 'lighting', R['desk-props'], { doc: doc('deskProps') });
        // § 10.6: the side table at every desk with art, with its seats — at least SIDE_TABLE_SEATS, one
        // more per intern drawn past them, so its width says nothing the row of interns does not. The theme
        // is handed the seat count and nothing else about the seat; it lays each seat under an intern's
        // place, which `seat_at` states as offsets inside the table (the interns stand at `stoolSlots()`).
        // card#12450: the highest HELD slot plus one (`desk/desk-render.js`'s `seats`), so a hole a departure left is an
        // empty seat between interns rather than a row that closes up under the seated.
        const seats = Math.max(SIDE_TABLE_SEATS, desk.side_table.seats);

        rect('side-table', 'side_table', R['side-table'], {
            seats,
            // each seat under its slot, row-major as the interns take them (`stoolSlots()`), offsets inside the table
            seat_at: slots.slice(0, seats).map((slot) => ({ dx: slot.x - R['side-table'].x, dy: slot.y + slot.h - SEAT_H - R['side-table'].y })),
            seat: { w: STOOL_W, h: SEAT_H },
            doc: doc('sideTable', { ...key, seats }),
        });
    }

    // card#11468: the facts plate goes HERE — after every art element (§ 10.6's rule 2: it is a fact element, the
    // facts' backdrop) and before every fact it is drawn behind. It is as wide as the widest fact it holds, which is
    // known once the facts are cut, so it is spliced in at this index after them.
    const plateAt = elements.length;

    // § 7.4's fade (card#12346): on a `fold_lag` desk every element of the art pass above — and only those — is drawn
    // at `FOLD_LAG_OPACITY`; the facts below, the lag line and the nameplate among them, keep full opacity.
    if (desk.lag !== null) {
        for (const e of elements) {
            e.opacity = FOLD_LAG_OPACITY;
        }
    }

    // The monitor is a fact (its light, Q1 B's task text), drawn on the placeholder too.
    rect('monitor', 'monitor', R.monitor, { lit: desk.monitor.lit });

    if (desk.monitor.lit !== 'off') {
        // § 5.1: the current action's descriptor, or with no call open the desk's state text — in
        // its non-raw form (Q0) — in the screen role, in the ink of its lit state.
        text('monitor-text', 'monitor', desk.action === null ? desk.desk_label : desk.monitor.text, R['monitor-text'],
            { role: 'screen', lit: desk.monitor.lit });
    }

    // ── Layer 4: the chip, then the facts column ────────────────────────────────────────────────
    // Q4 (a): the chip reads the model's glyph; an unrecognised state's glyph carries its raw string,
    // so its chip reads the fixed word instead (Q0).
    const chipCut = fit(desk.render_state.recognised ? desk.glyph : UNRECOGNISED, R.chip.w - 2 * CHIP_PAD, measure);
    const chipW = Math.min(R.chip.w, chipCut.w + 2 * CHIP_PAD);

    elements.push({
        kind: 'chip',
        member: 'glyph',
        x: R.chip.x - chipW / 2,
        y: R.chip.y,
        w: chipW,
        h: R.chip.h,
        text: chipCut.text,
        truncated: chipCut.truncated,
        text_w: chipCut.w,
        render_state: desk.render_state.value,
        unrecognised: !desk.render_state.recognised,
        unconfirmed: desk.unconfirmed,
    });

    text('label', 'desk_label', desk.desk_label, R.label);
    text('currency', 'desk_currency', desk.desk_currency, R.currency);
    text('lag', 'lag', desk.lag?.line ?? null, R.lag);

    const gauge = desk.gauge;

    if (gauge.reported) {
        // § 5.6: an unreported gauge draws nothing on the desk — never a bar at 0 %; its *not
        // reported* is the drill-down's and the list's.
        rect('gauge-bar', 'gauge', R['gauge-bar'], { pct: gauge.bar });
        text('gauge-pct', 'gauge', gauge.pct, R['gauge-pct']);
    }

    // Q1 (B), row 75: the badge row — the treatment badges, the pinned ones, then the wire's order.
    const row = badgeRow;

    row.forEach((badge, i) => {
        const x = R.badge.x + i * BADGE_PITCH;
        const cut = fit(badge, BADGE_W - 2 * BADGE_PAD, measure);

        elements.push({
            kind: 'badge',
            member: 'badges',
            x,
            y: R.badge.y,
            w: BADGE_W,
            h: R.badge.h,
            text: cut.text,
            truncated: cut.truncated,
            // The text's offset inside the chip — an offset, so it moves with the desk when it is placed.
            text_dx: BADGE_PAD,
            badge,
        });
    });

    // Q0: ONE flag for every unusual item whose raw form is not drawn.
    const n = flagCount(desk, row);

    if (n > 0) {
        // Drawn as a chip as wide as its text: the text cut to the row less the chip's padding, and
        // its offset inside the chip carried, as the badge's is.
        const cut = fit(FLAG_TEXT(n), R.flag.w - 2 * FLAG_PAD, measure);

        elements.push({
            kind: 'flag',
            member: 'unrecognised',
            x: R.flag.x,
            y: R.flag.y,
            w: cut.w + 2 * FLAG_PAD,
            h: R.flag.h,
            text: cut.text,
            truncated: cut.truncated,
            text_dx: FLAG_PAD,
            count: n,
        });
    }

    // § 8: one sprite per intern, in front of the side table (emitted in the art pass above), in PAINT ORDER — the back row
    // before the front, each row left to right — so where two interns' frames overlap each is drawn whole (`STOOL_PITCH`).
    // card#12450: each at its intern's KEPT slot (`stool.slot`, § 6.2's *Interns ride the elevator too* item 13), never its
    // index in the newest-first array, so a gain or a loss moves no seated intern.
    shown.map((stool) => [stool, stool.slot]).sort(([, a], [, b]) => slots[b].row - slots[a].row || a - b).forEach(([stool, i]) => {
        const asset = internAsset(desk, stool.call_id);

        // Q3: the sprite is keyed by the intern's call, never by its place in the row; § 9 F14: an
        // intern whose art failed falls back to the glyph, that stool alone.
        const { x, y, w, h } = slots[i];
        const at = { x, y, w, h };

        rect('stool', 'side_table', at, {
            call_id: stool.call_id,
            untitled: stool.untitled,
            index: i,
            // § 10.4's frame contract item 3 (card#12259): the sprite is drawn in the stool's overdraw, the rect widened
            // to the row's pitch, so a chibi frame's widest head is not clipped at the rect's side.
            overdraw: slots[i].overdraw,
            // What the character tree is handed — the install and the intern key in the seat's place —
            // from the same model the asset id is, so the two can never name different interns.
            install_id: desk.install_id,
            key: internKey(desk.seat_id, stool.call_id),
            // card#12443: the body the intern must not wear — its seat's DELIVERED species, never the seat key's draw.
            seat_species: desk.species,
            asset,
            art: !ctx.failed.has(asset),
            // § 6.2 A23 (card#12448): the interns' held rendering, which the desk model derives — whether the
            // interns type, § 6.4's form, and the interval their sway steps at.
            animation: desk.interns_held,
            // card#12451: the intern's own state — `working` draws its laptop, `idle` none (FLOOR.md § 8).
            activity: stool.activity,
        });
    });

    if (more > 0) {
        text('stool-more', 'side_table', MORE(more), R['stool-more']);
    }

    // Q5 (b): *nothing done for N* stays on the desk.
    text('quiet-age', 'quiet_age', desk.quiet_age, R['quiet-age']);

    // The plate: behind every row the stack holds, padded `FACTS_PLATE_PAD` across and `FACTS_PLATE_PAD_Y` down,
    // as wide as the widest of them — none when the desk carries no row.
    const plated = union(elements.filter((e) => PLATED_KINDS.includes(e.kind)));

    if (plated !== null) {
        const x = R.label.x - FACTS_PLATE_PAD;
        const y = plated.y - FACTS_PLATE_PAD_Y;

        elements.splice(plateAt, 0, {
            kind: 'facts-plate',
            member: null,
            x,
            y,
            w: plated.x + plated.w + FACTS_PLATE_PAD - x,
            h: plated.y + plated.h + FACTS_PLATE_PAD_Y - y,
            rx: FACTS_PLATE_RX,
        });
    }

    // ── Layer 5: the plate and the nameplate ────────────────────────────────────────────────────
    rect('plate', 'nameplate', R.plate);

    // Q2: the nameplate is the one string in the name role — measured, cut and centred in it.
    const name = fit(desk.nameplate, PLATE_TEXT_W, measure, 'name');

    elements.push({
        kind: 'nameplate',
        member: 'nameplate',
        x: R.plate.x + (R.plate.w - name.w) / 2,
        y: R.plate.y + (R.plate.h - LINE_NAME) / 2,
        w: name.w,
        h: name.h,
        text: name.text,
        truncated: name.truncated,
        role: 'name',
    });

    return { elements, bubble: desk.bubble };
}

/**
 * The badge row's order (§ 10.3's cut order, amended by the ruling of 2026-10-02 and by § 13 row 75):
 * the badges a § 7 treatment reads first, then `PINNED_BADGES`, then the other RECOGNISED badges in the
 * wire's own order. An unrecognised badge is
 * never in it — its raw id is not drawn on the desk (Q0); it is counted into the flag through its
 * `badges: <id>` line.
 *
 * @returns {list<string>} badge ids
 */
export function clusterOrder(desk) {
    const unknown = new Set(desk.unrecognised
        .filter((line) => line.startsWith('badges: '))
        .map((line) => line.slice('badges: '.length)));
    const known = desk.badges.filter((id) => !unknown.has(id));

    return [
        ...known.filter((id) => TREATMENT_BADGES.includes(id)),
        ...known.filter((id) => PINNED_BADGES.includes(id)),
        ...known.filter((id) => !TREATMENT_BADGES.includes(id) && !PINNED_BADGES.includes(id)),
    ];
}

/**
 * The asset id a desk's FURNITURE SET is reported under when the painter cannot draw it (§ 9 F14, FLOOR.md
 * § 10.6 item 8): `theme:<name>/desk:<install_id>/<seat_id>` — the five documents are one unit.
 */
export function furnitureAsset(theme, desk) {
    return `theme:${theme}/desk:${desk.install_id}/${desk.seat_id}`;
}

/** The asset id a desk's character is reported under when the painter cannot draw it (§ 9 F14). */
export function characterAsset(desk) {
    return `character:${desk.install_id}/${desk.seat_id}`;
}

/**
 * THE INTERN KEY — `seat~<call_id>` (§ 10.4; the operator's ruling of 2026-10-02 on card#11058 Q3: "intern
 * sprites keyed by call_id"). The character tree is handed it in the seat's place, so an intern's look is
 * a pure function of `(install_id, seat_id, call_id)`: it keeps its sprite when `subagents[]` reorders or
 * a sibling leaves, and two siblings never share one. `~` is outside the `seat_id` alphabet
 * (`App\Support\Slug::SEAT_ID`), so no intern key is ever a seat's.
 */
export function internKey(seatId, callId) {
    return `${seatId}~${callId}`;
}

/** The asset id an intern's sprite is reported under when the painter cannot draw it (§ 9 F14). */
export function internAsset(desk, callId) {
    return `intern:${desk.install_id}/${internKey(desk.seat_id, callId)}`;
}

/** Whether a string fact is there to draw: the model's null, an absent member and an empty string draw nothing. */
function present(value) {
    return value !== null && value !== undefined && value !== '';
}

/** The union of a list of rects, or `null` for none. */
export function union(rects) {
    if (rects.length === 0) {
        return null;
    }

    const x = Math.min(...rects.map((r) => r.x));
    const y = Math.min(...rects.map((r) => r.y));

    return {
        x,
        y,
        w: Math.max(...rects.map((r) => r.x + r.w)) - x,
        h: Math.max(...rects.map((r) => r.y + r.h)) - y,
    };
}
