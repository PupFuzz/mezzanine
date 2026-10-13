/**
 * ONE DESK, LAID OUT INSIDE ITS FURNITURE BOX — `docs/design/FLOOR.md` Appendix B row 14 and
 * § 10.3's `desks` row: the RULED GLANCE SET (§ 5.1's *the glance set*, the operator's ruling of
 * 2026-10-02, card#11058 Q0 (a) + Q1 (B)), placed at the rects `deskRects()` derives from the box,
 * together with the scene's ONE TRUNCATION PRIMITIVE, `fit()`.
 *
 * ⛔ THE DESK DRAWS EXACTLY THE RULED SET AND NOTHING ELSE: the character (or the chair), the desk,
 * the monitor and its text, the nameplate on its plate, the state chip, the label line, the currency
 * label, the lag line, the context bar and its %, a badge row of two (the treatment badges, then
 * recognised badges in the wire's order), ONE flag `⚠ +N` for every unusual item whose raw form is
 * not drawn, the interns, the quiet age, § 7.4's hatch, § 7.3's dimming and the bubble. Every other
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
 * planted in `fit()`, can speak for them.
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
 * text, measured and drawn at 8 px on the facts' 12 px line (§ 12's *Screen type size* row — the smallest
 * that read at fit on a device-scale-1 screen, measured in headless Chromium).
 */
export const FONT_SCREEN = '8px sans-serif';

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
    screen: Object.freeze({ font: FONT_SCREEN, line: LINE, baseline: 9 }),
});

/**
 * The bubble's inner padding — the text's inset from the bubble's rect — and the band at the top of the box the
 * bubble is drawn in. The padding holds the cloud's puffs (`CLOUD_PUFF`): the cloud is drawn INSIDE the rect, its
 * scallops reaching the rect's edge and its valleys `CLOUD_PUFF` in from it, so the text clears every valley and
 * the rect AT-D3-20 *(b)* reads is the cloud's own extent (card#11468).
 */
export const BUBBLE_PAD = 6;

/** The cloud's puff radius: each scallop of the bubble's outline is a half-circle of at most this radius (§ 5.1, card#11468). */
export const CLOUD_PUFF = 4;

/**
 * The thought trail (§ 5.1, card#11468): the radii of its circles, from the one just above the creature's head
 * to the one nearest the cloud — growing, so the trail reads as a thought rising — and the gap between two.
 */
export const TRAIL_RADII = Object.freeze([2, 3, 4]);

export const TRAIL_GAP = 1;

export const BUBBLE_BAND = 2 * LINE + 2 * BUBBLE_PAD;

/** The art column's width — the character, the desk, the monitor, the chip and the plate (§ 2.2: Chosen). */
export const ART_W = 216;

/** The gap between the art column and the facts column. */
export const GUTTER = 4;

/**
 * THE FACTS PLATE (§ 10.6's *The desk, re-laid*, card#11468 — the operator's "A is fine", 2026-10-07): the
 * facts column's one soft rounded backdrop. Its padding across is 4, so its left edge is the art column's edge
 * (`ART_W`) and § 7.4's hatch, which covers the art column, never reaches it; 6 down; its corners `FACTS_PLATE_RX`.
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
 * The character rect over the tree's footprint unit `SCENE_W × SCENE_H` (18 × 32): the rect is 3 × that,
 * 54 × 96 (§ 12's *Character rect* row, Chosen). Sized by card#11058 for the pixel tree and kept when the
 * vector creatures replaced it (card#11046): they have no native size, so the unit is the rect's aspect
 * and this its scale, and neither the box nor any rect moved (`resources/characters/index.js`).
 */
export const CHARACTER_SCALE = 3;

/** § 8's cap on the interns drawn: the array D2 caps at 8 (§ 8.1's chosen cap). */
export const STOOL_CAP = 8;

/** The side table's least seat count (§ 12's *Side-table seats* row): an empty table shows its seats. */
export const SIDE_TABLE_SEATS = 4;

/** A seat's height on the side table, along its foot line (§ 10.6; row 20's flat shape). */
const SEAT_H = 6;

/**
 * D2 § 8.2.1's bound on `badges` — "the union of D1's 12 `degraded` members and § 7.2's 7, of
 * which `epoch_reset` is in both" — which is exactly the published member set, so it is that set's
 * size rather than a second number (§ 12's *`badges` bound* row).
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

/** One intern's sprite rect, and the pitch the row lays them at (§ 8, § 10.4's art contract). */
const STOOL_W = 20;

const STOOL_H = 32;

const STOOL_PITCH = 24;

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
 * THE DESK's RECT TABLE — § 2.2's, derived from the box (`resources/floor/furniture-box.js`'s one
 * declaration): every element's rect relative to the box's top-left — the art from the desk's slab, the
 * facts column stacked up from the side table on the desk's foot line, the hatch and the placeholder from the
 * bubble's band. A larger box keeps every rect inside; AT-D3-20 reds when the box shrinks below the table at
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
    // inset 4 px inside it and 31 px tall; the text 3 px inside the screen (§ 12's monitor row).
    const frame = { x: desk.x + desk.w - 96, y: slab - 46, w: 96, h: 46 };
    // § 10.6 (card#11468): the side table stands on the desk's own foot line, its interns in front of it and the
    // *+N more* tag under it; the facts stack upward from just above it, inside the plate's padding. These row
    // rects are the stack AT THE CAP — every row present — and `deskLayout()` lays the rows a desk carries
    // bottom-up from the same foot (`stackRows()`).
    const foot = desk.y + desk.h;
    const table = { x: colB - 8, y: foot - 42, w: W - colB + 8, h: 42 };
    const rowY = stackRows(FACT_ROWS, table);

    return Object.freeze({
        character: { x: sitter, y: slab - 70, w: cw, h: ch },
        chair: { x: sitter, y: slab - 38, w: cw, h: 64 },
        placeholder: { x: 0, y: top, w: ART_W, h: H - 36 - top },
        'desk-sprite': desk,
        'monitor-frame': frame,
        // The desk's left third: the lamp and its light, the mug or the plant (§ 10.6).
        'desk-props': { x: desk.x - 12, y: slab - 34, w: 68, h: 50 },
        monitor: { x: frame.x + 4, y: frame.y + 4, w: 88, h: 31 },
        'monitor-text': { x: frame.x + 7, y: frame.y + 13, w: 82, h: LINE },
        chip: { x: mid, y: H - 16, w: CHIP_MAX_W, h: 14 },
        label: { x: colB, y: rowY.label, w: widthB, h: LINE },
        currency: { x: colB, y: rowY.currency, w: widthB, h: LINE },
        lag: { x: colB, y: rowY.lag, w: widthB, h: LINE },
        'gauge-bar': { x: colB, y: rowY.gauge + 2, w: 60, h: 8 },
        'gauge-pct': { x: colB + 64, y: rowY.gauge, w: 60, h: LINE },
        badge: { x: colB, y: rowY.badge, w: BADGE_W, h: LINE },
        flag: { x: colB, y: rowY.flag, w: widthB, h: LINE },
        'quiet-age': { x: colB, y: rowY['quiet-age'], w: widthB, h: LINE },
        stool: { x: colB, y: foot - STOOL_H, w: STOOL_W, h: STOOL_H },
        // § 10.6: drawn at every desk with art, under the facts and over the *+N more* row; its foot line (its
        // bottom edge) is the desk's and the interns', so they stand in front of it.
        'side-table': table,
        'stool-more': { x: colB, y: foot + 2, w: widthB, h: LINE },
        'lag-overlay': { x: 0, y: top, w: ART_W, h: H - top },
        plate: { x: mid - 80, y: H - 36, w: 160, h: 18 },
    });
}

/**
 * THE THOUGHT TRAIL (§ 5.1, card#11468 — the operator's "starting with a small bubble and increasing in size
 * until reaching the top card status bubble"): the circles between the creature and its cloud, box-relative,
 * smallest first. They stand on the character's centre line (`characterCentre()`), the first `TRAIL_GAP` above
 * the character's rect so no circle covers the creature, each next one `TRAIL_GAP` above the last, growing by
 * `TRAIL_RADII`. Their sizes are constants: the trail says nothing about the seat (§ 5.1 rule 2).
 *
 * @returns {list<{x: number, y: number, r: number}>} each circle's centre and radius
 */
export function thoughtTrail(box, character) {
    const head = deskRects(box, character).character;
    const x = head.x + head.w / 2;
    const out = [];
    let bottom = head.y - TRAIL_GAP;

    for (const r of TRAIL_RADII) {
        out.push(Object.freeze({ x, y: bottom - r, r }));
        bottom -= 2 * r + TRAIL_GAP;
    }

    return Object.freeze(out);
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
 * anchored to the character, § 5.1 rule 3, and the thread line and the walks meet the desk where its
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
    lag: ['lag-overlay', 'lag'],
    monitor: ['monitor', 'monitor-text', 'monitor-frame'],
    quiet_age: ['quiet-age'],
    gauge: ['gauge-bar', 'gauge-pct'],
    badges: ['badge', 'flag'],
    unrecognised: ['flag'],
    side_table: ['side-table', 'stool', 'stool-more'],
    bubble: ['bubble'],
    held: ['character'],
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
 * monitor's frame, the desk props, the side table), then every fact (FLOOR.md § 10.6's rule 2) — so the
 * hatch is over the art and the chip, and the plate is above the hatch.
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
    const elements = [];

    // card#11468: the facts column STACKED — the rows this desk carries, in `FACT_ROWS`' order, bottom-up from
    // just above the side table, with no empty row for one it does not carry. Same facts, same widths; only
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
    const rowY = stackRows(rows, R['side-table']);

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

        const cut = fit(String(value), r.w, measure, extra.role ?? 'fact');
        const e = { kind, member, x: r.x, y: r.y, w: cut.w, h: cut.h, text: cut.text, truncated: cut.truncated, ...extra };

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
            rect('character', 'character', R.character, { asset: characterAsset(desk), pose: desk.pose, animation: desk.held });
        }

        rect('desk-sprite', 'lighting', R['desk-sprite'], { lighting: desk.lighting, doc: doc('desk') });
        rect('monitor-frame', 'monitor', R['monitor-frame'], { doc: doc('monitorFrame') });
        rect('desk-props', 'lighting', R['desk-props'], { doc: doc('deskProps') });
        // § 10.6: the side table at every desk with art, with its seats — at least SIDE_TABLE_SEATS, one
        // more per intern drawn past them, so its width says nothing the row of interns does not. The theme
        // is handed the seat count and nothing else about the seat; it lays each seat under an intern's
        // place, the row `seat_dx` states as offsets inside the table (the interns stand from `STOOL_PITCH`'s
        // row at `R.stool.x`).
        const seats = Math.max(SIDE_TABLE_SEATS, shown.length);

        rect('side-table', 'side_table', R['side-table'], {
            seats,
            seat_dx: Array.from({ length: seats }, (_, i) => R.stool.x - R['side-table'].x + i * STOOL_PITCH),
            seat: { dy: R['side-table'].h - SEAT_H, w: STOOL_W, h: SEAT_H },
            doc: doc('sideTable', { ...key, seats }),
        });
    }

    // card#11468: the facts plate goes HERE — after every art element (§ 10.6's rule 2: it is a fact element, the
    // facts' backdrop) and before every fact it is drawn behind. It is as wide as the widest fact it holds, which is
    // known once the facts are cut, so it is spliced in at this index after them.
    const plateAt = elements.length;

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

    // Q1 (B): the badge row — the treatment badges, then recognised badges in the wire's order.
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

    // § 8: one sprite per intern, in front of the side table (emitted in the art pass above).
    shown.forEach((stool, i) => {
        const asset = internAsset(desk, stool.call_id);

        // Q3: the sprite is keyed by the intern's call, never by its place in the row; § 9 F14: an
        // intern whose art failed falls back to the glyph, that stool alone.
        rect('stool', 'side_table', { ...R.stool, x: R.stool.x + i * STOOL_PITCH }, {
            call_id: stool.call_id,
            untitled: stool.untitled,
            index: i,
            // What the character tree is handed — the install and the intern key in the seat's place —
            // from the same model the asset id is, so the two can never name different interns.
            install_id: desk.install_id,
            key: internKey(desk.seat_id, stool.call_id),
            asset,
            art: !ctx.failed.has(asset),
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

    // ── Layer 5: § 7.4's hatch over the art and the chip ────────────────────────────────────────
    if (desk.lag !== null) {
        rect('lag-overlay', 'lag', R['lag-overlay'], { overlay: desk.lag.overlay });
    }

    // ── Layer 6: the plate and the nameplate, above the hatch ───────────────────────────────────
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
 * The badge row's order (§ 10.3's cut order, amended by the ruling of 2026-10-02): the badges a § 7
 * treatment reads first, then the RECOGNISED badges in the wire's own order. An unrecognised badge is
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
        ...known.filter((id) => !TREATMENT_BADGES.includes(id)),
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
