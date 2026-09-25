/**
 * ONE DESK, LAID OUT INSIDE ITS FURNITURE BOX — `docs/design/FLOOR.md` Appendix B row 14 and
 * § 10.3's `desks` row: every element step 5's desk model emits, placed at a rect relative to the
 * box's top-left corner, together with the scene's ONE TRUNCATION PRIMITIVE, `fit()`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERYTHING DRAWN FOR A DESK AT REST, EXCEPT THE BUBBLE, LIES INSIDE THE BOX — by construction,
 * at the worst case § 10.3 names: § 8's cap of stools with the *+N more* tag, D2's bound of badges
 * with its mark, and every string cut to the width it is given. That is what makes card#7341's
 * ruling true of a floor: § 3.2 gives distinct seats distinct slots, the map's slots are disjoint,
 * and a desk drawn inside its own slot cannot reach a neighbour's whatever the seat beside it
 * carries. `Tests\Feature\Floor\SeatFurnitureNeverOverlapsTest` (AT-D3-20) reads the rects this
 * returns.
 *
 * ⛔ EVERY STRING GOES THROUGH `fit()` AND NO DRAWING SITE CUTS ONE ITSELF (§ 10.3). A string the
 * fixtures never stretch — the model label, the currency label, a raw API-error string, the gauge's
 * text — is cut by the same code as the ones they do, which is the only reason AT-D3-20's second RED,
 * planted in `fit()`, can speak for them.
 *
 * ⛔ THE STRINGS ARE THE DESK MODEL's (`desk/desk-render.js`), each as that model decided it. What
 * this module adds is WHERE: which column, which row, how wide. Two strings the model leaves as
 * numbers are worded here and say so (`OPEN_CALLS`, `MORE`).
 *
 * ⛔ THE POPULATION IS THE MODEL's OUTPUT, NOT A LIST WRITTEN HERE. `DRAWN_MEMBERS` and
 * `NOT_DRAWN_MEMBERS` below partition every member `deskModel()` returns, and
 * `Tests\Feature\Floor\TheSceneDrawsEveryDeskMemberTest` set-differences that partition against the
 * model's real keys in both directions — so a member added to the desk model reds until it is drawn
 * here or excluded by name with a reason.
 */

import { TRUNCATION_MARK } from '../desk/task-bubble.js';
import { BADGES } from '../wire/member-sets.js';

/** The pitch every text row is laid on, in scene pixels — the page's font is chosen to fit it. */
export const LINE = 12;

/** The page's desk text: the font the painter measures and draws with, sized to `LINE`. */
export const FONT = '10px sans-serif';

/** The bubble's inner padding, and the band at the top of the box the bubble is drawn in. */
export const BUBBLE_PAD = 3;

export const BUBBLE_BAND = 2 * LINE + 2 * BUBBLE_PAD;

/** Column A — the art: the character, the desk sprite, the monitor, the nameplate. */
export const ART_W = 120;

/** The gap between columns. */
export const GUTTER = 4;

/**
 * The character's drawn scale over the tree's own `SCENE_W × SCENE_H` — the interim pixel art is
 * 18 × 32, and at 1 it is smaller than the monitor it sits behind. An integer, because the tree
 * blits nearest-neighbour (`resources/characters/index.js`).
 */
export const CHARACTER_SCALE = 2;

/** § 8's cap on the side table: the array D2 caps at 8 (§ 8.1's chosen cap). */
export const STOOL_CAP = 8;

/**
 * D2 § 8.2.1's bound on `badges` — "the union of D1's 12 `degraded` members and § 7.2's 7, of
 * which `epoch_reset` is in both" — which is exactly the published member set, so it is that set's
 * size rather than a second number (§ 12's *`badges` bound* row).
 */
export const BADGE_BOUND = BADGES.size;

/** The badge chips laid per row of the cluster. */
export const BADGES_PER_ROW = 3;

/** The side of a stool's glyph. */
const STOOL_GLYPH = 8;

/** The badges a § 7 treatment reads — § 7.3's `config_invalid` and § 7.4's `fold_lag`. */
const TREATMENT_BADGES = Object.freeze(['config_invalid', 'fold_lag']);

/**
 * § 5.1's open-call count, which the model carries as a number.
 *
 * § 5.1: the count renders "when it exceeds 1", in the wording the operator ratified (card#7342,
 * 2026-09-25) — the number and the member it counts, nothing else.
 */
export const OPEN_CALLS = (n) => `${n} open calls`;

/** § 8's and § 10.3's *+N more*, the document's own words, for the side table and the cluster. */
export const MORE = (n) => `+${n} more`;

/**
 * THE SCENE's ONE TRUNCATION PRIMITIVE (§ 10.3): `text` cut to `width` with a visible mark, by the
 * page's own measurer — never clipped silently and never drawn past the edge.
 *
 * Cut by CODE POINT, for `desk/task-bubble.js`'s reason: a cut that splits an astral character
 * draws a replacement glyph, which is the one way a truncation mark lies about what was cut.
 *
 * @returns {{text: string, truncated: boolean, w: number, h: number}}
 */
export function fit(text, width, measure) {
    const whole = measure(text);

    if (whole.w <= width) {
        return { text, truncated: false, w: whole.w, h: whole.h };
    }

    const points = [...text];
    let lo = 0;
    let hi = points.length;

    // The longest prefix whose cut form fits: a binary search over the prefix length.
    while (lo < hi) {
        const mid = Math.ceil((lo + hi) / 2);

        if (measure(points.slice(0, mid).join('') + TRUNCATION_MARK).w <= width) {
            lo = mid;
        } else {
            hi = mid - 1;
        }
    }

    const cut = points.slice(0, lo).join('') + TRUNCATION_MARK;
    const size = measure(cut);

    return { text: cut, truncated: true, w: size.w, h: size.h };
}

/**
 * Every member `deskModel()` returns, and the element kinds that draw it. A member a desk carries a
 * `null` for draws nothing — § 5.6's null render is the model's, already decided.
 */
export const DRAWN_MEMBERS = Object.freeze({
    nameplate: ['nameplate'],
    glyph: ['glyph'],
    character: ['character', 'chair'],
    pose: ['character', 'chair'],
    lighting: ['desk-sprite'],
    render_state: ['glyph'],
    label_line: ['label'],
    currency_label: ['currency'],
    lag: ['lag-overlay', 'lag'],
    config_note: ['config-note'],
    monitor: ['monitor', 'monitor-text', 'subagent-marker'],
    open_calls: ['open-calls'],
    action: ['action-started', 'action-elapsed'],
    quiet_age: ['quiet-age'],
    last_kind: ['last-kind'],
    last_event_time: ['last-event-time'],
    gauge: ['gauge-bar', 'gauge-pct', 'gauge-numerals', 'gauge-statement', 'gauge-source', 'gauge-sampled', 'gauge-age'],
    model_label: ['model-label'],
    badges: ['badge', 'badge-more'],
    unrecognised: ['unrecognised', 'badge'],
    oldest_badge_since: ['oldest-badge-since'],
    side_table: ['side-table', 'stool', 'stool-label', 'stool-type', 'stool-started', 'stool-more'],
    bubble: ['bubble'],
    held: ['character'],
    unconfirmed: ['chair'],
});

/** The members the desk model returns that no element draws, each with the reason. */
export const NOT_DRAWN_MEMBERS = Object.freeze({
    install_id: "the desk's key with `seat_id` (§ 3.1) — it positions the desk; the room is the floor's to name",
    seat_id: 'drawn as the nameplate, which is the same string (§ 5.1)',
    dark: "its two strings are `label_line`'s own — `labelLine()` splices them in, and drawing them again would be one fact twice",
});

/**
 * One desk's elements, relative to its box's top-left.
 *
 * @param {object} desk one desk model (`desk/desk-render.js`'s `deskModel()`)
 * @param {object} ctx `{ box: {width, height}, measure, character: {w, h}, sprite: {url, w, h}|null,
 *        placeholder: boolean }` — `placeholder` is § 9 F14's: every fact, no art
 * @returns {{elements: list<object>, bubble: object|null}}
 */
export function deskLayout(desk, ctx) {
    const { box, measure } = ctx;
    const W = box.width;
    const H = box.height;
    const elements = [];
    const colB = ART_W + GUTTER;
    const widthB = Math.floor((W - ART_W - 2 * GUTTER) / 2);
    const colC = colB + widthB + GUTTER;
    const widthC = W - colC;
    const row = (r) => BUBBLE_BAND + r * LINE;

    const text = (kind, member, value, x, y, width, extra = {}) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        const cut = fit(String(value), width, measure);

        elements.push({ kind, member, x, y, w: cut.w, h: cut.h, text: cut.text, truncated: cut.truncated, ...extra });
    };

    // ── Column A: the art, the glyph and the nameplate ────────────────────────────────────────
    text('glyph', 'glyph', desk.glyph, 0, row(0), ART_W, { render_state: desk.render_state.value });

    const spriteW = ctx.sprite?.w ?? ART_W - 4;
    const spriteH = ctx.sprite?.h ?? 57;
    const deskTop = H - spriteH;

    if (ctx.placeholder) {
        // § 9 F14's placeholder: "a plain rectangle carrying the nameplate, the state label and the
        // badge cluster — every fact, no art". The rectangle takes the art's place; every text
        // element below is drawn exactly as on an intact desk.
        elements.push({ kind: 'placeholder', member: null, x: 0, y: row(1), w: ART_W, h: H - row(1) });
    } else {
        const cw = ctx.character.w * CHARACTER_SCALE;
        const ch = ctx.character.h * CHARACTER_SCALE;
        // Seated behind the desk: the lower quarter of the figure is behind the desk's top.
        const charBottom = deskTop + Math.round(ch / 4);

        if (desk.character) {
            elements.push({
                kind: 'character',
                member: 'character',
                x: Math.round(ART_W / 2 - cw / 2),
                y: charBottom - ch,
                w: cw,
                h: ch,
                asset: characterAsset(desk),
                pose: desk.pose,
                animation: desk.held,
            });
        } else {
            // § 7.1: the empty chair — an absence, never a sleeper (§ 7.5).
            elements.push({
                kind: 'chair',
                member: 'character',
                x: Math.round(ART_W / 2 - 16),
                y: deskTop - 40,
                w: 32,
                h: 40,
                pose: desk.pose,
                unconfirmed: desk.unconfirmed,
            });
        }

        elements.push({
            kind: 'desk-sprite',
            member: 'lighting',
            x: 2,
            y: deskTop,
            w: spriteW,
            h: spriteH,
            asset: ctx.sprite?.url ?? null,
            lighting: desk.lighting,
        });

        const monitor = { x: ART_W - 36, y: deskTop - 22, w: 32, h: 22 };

        elements.push({ kind: 'monitor', member: 'monitor', ...monitor, lit: desk.monitor.lit });

        if (desk.monitor.subagent_call) {
            elements.push({ kind: 'subagent-marker', member: 'monitor', x: monitor.x + monitor.w - 8, y: monitor.y, w: 8, h: 8 });
        }
    }

    text('nameplate', 'nameplate', desk.nameplate, 4, H - LINE - 4, ART_W - 8);

    if (desk.lag !== null) {
        // § 7.4's hatched overlay, over the art where the state is drawn.
        elements.push({ kind: 'lag-overlay', member: 'lag', x: 0, y: row(0), w: ART_W, h: H - row(0), overlay: desk.lag.overlay });
    }

    // ── Column B: the desk's lines ─────────────────────────────────────────────────────────────
    const lines = [
        ['label', 'label_line', desk.label_line],
        ['currency', 'currency_label', desk.currency_label],
        ['lag', 'lag', desk.lag?.line ?? null],
        ['config-note', 'config_note', desk.config_note],
        ['monitor-text', 'monitor', desk.monitor.text],
        ['action-started', 'action', desk.action?.started_at ?? null],
        ['action-elapsed', 'action', desk.action?.elapsed ?? null],
        ['open-calls', 'open_calls', desk.open_calls === null ? null : OPEN_CALLS(desk.open_calls)],
        ['quiet-age', 'quiet_age', desk.quiet_age],
        ['last-kind', 'last_kind', desk.last_kind],
        ['last-event-time', 'last_event_time', desk.last_event_time],
    ];

    lines.forEach(([kind, member, value], r) => text(kind, member, value, colB, row(r), widthB));

    // The gauge takes two rows: the bar and its numerals, then the sample's source, clock and age.
    const gauge = desk.gauge;
    const g1 = row(lines.length);
    const g2 = row(lines.length + 1);

    if (gauge.reported) {
        const barW = 40;

        elements.push({ kind: 'gauge-bar', member: 'gauge', x: colB, y: g1 + 2, w: barW, h: LINE - 4, pct: gauge.bar });
        text('gauge-pct', 'gauge', gauge.pct, colB + barW + 4, g1, 44);
        text('gauge-numerals', 'gauge', gauge.numerals, colB + barW + 52, g1, widthB - barW - 52);

        const third = Math.floor((widthB - 8) / 3);

        text('gauge-source', 'gauge', gauge.source, colB, g2, third);
        text('gauge-sampled', 'gauge', gauge.sampled_at, colB + third + 4, g2, third);
        text('gauge-age', 'gauge', gauge.age, colB + 2 * (third + 4), g2, widthB - 2 * (third + 4));
    } else {
        // § 5.6: *not reported*, and no bar — never a bar at 0 %.
        text('gauge-statement', 'gauge', gauge.statement, colB, g1, widthB);
    }

    text('model-label', 'model_label', desk.model_label, colB, row(lines.length + 2), widthB);
    text('oldest-badge-since', 'oldest_badge_since', desk.oldest_badge_since, colB, row(lines.length + 3), widthB);
    text('unrecognised', 'unrecognised', desk.unrecognised.length === 0 ? null : desk.unrecognised.join(', '),
        colB, row(lines.length + 4), widthB);

    // ── Column C: the side table, then the badge cluster ───────────────────────────────────────
    elements.push({ kind: 'side-table', member: 'side_table', x: colC, y: row(0), w: widthC, h: (STOOL_CAP + 1) * LINE });

    const stools = desk.side_table.stools;
    // § 8: every stool the array carries up to the cap is drawn and none is hidden; a skewed wire
    // past the cap is counted into the tag, never dropped.
    const shown = stools.slice(0, STOOL_CAP);
    const more = (desk.side_table.more ?? 0) + (stools.length - shown.length);
    const labelW = Math.floor((widthC - STOOL_GLYPH - 4) * 0.48);
    const typeW = Math.floor((widthC - STOOL_GLYPH - 4) * 0.2);
    const timeX = colC + STOOL_GLYPH + 4 + labelW + 2 + typeW + 2;

    shown.forEach((stool, i) => {
        const y = row(i);

        elements.push({
            kind: 'stool',
            member: 'side_table',
            x: colC,
            y: y + Math.floor((LINE - STOOL_GLYPH) / 2),
            w: STOOL_GLYPH,
            h: STOOL_GLYPH,
            call_id: stool.call_id,
            untitled: stool.untitled,
        });
        text('stool-label', 'side_table', stool.label, colC + STOOL_GLYPH + 4, y, labelW, { untitled: stool.untitled });
        text('stool-type', 'side_table', stool.type, colC + STOOL_GLYPH + 4 + labelW + 2, y, typeW);
        text('stool-started', 'side_table', stool.started_at, timeX, y, colC + widthC - timeX);
    });

    if (more > 0) {
        text('stool-more', 'side_table', MORE(more), colC, row(STOOL_CAP), widthC);
    }

    const badges = clusterOrder(desk);
    const drawn = badges.slice(0, BADGE_BOUND);
    const chipW = Math.floor((widthC - (BADGES_PER_ROW - 1) * 3) / BADGES_PER_ROW);
    const firstBadgeRow = STOOL_CAP + 1;

    drawn.forEach((badge, i) => {
        const x = colC + (i % BADGES_PER_ROW) * (chipW + 3);
        const y = row(firstBadgeRow + Math.floor(i / BADGES_PER_ROW));

        text('badge', badge.unrecognised ? 'unrecognised' : 'badges', badge.id, x, y, chipW, {
            badge: badge.id,
            unrecognised: badge.unrecognised,
            chip_w: chipW,
        });
    });

    if (badges.length > drawn.length) {
        text('badge-more', 'badges', MORE(badges.length - drawn.length), colC,
            row(firstBadgeRow + Math.ceil(BADGE_BOUND / BADGES_PER_ROW)), widthC);
    }

    return { elements, bubble: desk.bubble };
}

/**
 * § 10.3's order for the cluster's cut: unrecognised badges first, so F9's marker never falls under
 * the mark (AT-D3-11); then the badges a § 7 treatment reads; then the rest in the wire's own order.
 */
export function clusterOrder(desk) {
    const unknown = new Set(desk.unrecognised
        .filter((line) => line.startsWith('badges: '))
        .map((line) => line.slice('badges: '.length)));
    const all = desk.badges.map((id) => ({ id, unrecognised: unknown.has(id) }));

    return [
        ...all.filter((b) => b.unrecognised),
        ...all.filter((b) => !b.unrecognised && TREATMENT_BADGES.includes(b.id)),
        ...all.filter((b) => !b.unrecognised && !TREATMENT_BADGES.includes(b.id)),
    ];
}

/** The asset id a desk's character is reported under when the painter cannot draw it (§ 9 F14). */
export function characterAsset(desk) {
    return `character:${desk.install_id}/${desk.seat_id}`;
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
