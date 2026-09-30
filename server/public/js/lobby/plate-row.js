/**
 * ONE PLATE OF THE CROSS-SECTION, AS ELEMENTS — the row `lobby/main.js` stands at its rect in the
 * building's scene, and the text it carries. **The label CONTRACT — placement, the three-line clip, the
 * drop order, the link and its accessible name, paint and contrast — is `docs/design/FLOOR.md` § 4.1's
 * alone; this module states only what its own code does.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS MODULE DECIDES NO FACT AND NO GEOMETRY. Every string is `building-model.js`'s plate — its
 * `name` (§ 4.6), `summary`, `rooms`, `href` — and whether the cab is here is the building's
 * `elevator.at`, which the page passes in; where the plate stands is `building-scene.js`'s rect. Where
 * the label stands, how wide it is, how many lines show and what it is painted in are NONE of this
 * module's business: `plateRow()` builds ONE static shape, every property read through a `var()`
 * `lobby/label-paint.js`'s `showLabels()` writes on `#lobby-floors` — never a number or a colour computed
 * here — so a plate's row, once built, needs no rebuild for its label to track the camera.
 *
 * ⛔ THE LABEL's OWN BOX CLIPS THROUGH `clip-path`, NEVER `overflow`: `clip-path` clips an element's own
 * rendered content — text, shadows, descendants — independent of the `overflow` property, and it is
 * never independently scrollable the way `overflow: hidden`/`auto` can be. `inset()`'s own reference box
 * is the element's border box, so its bottom edge tracks `max-height` exactly as `overflow` would have.
 *
 * ⛔ THE LINK's COLOUR IS SET DIRECTLY ON THE `<a>`, NEVER MERELY INHERITED: a browser's own `:visited`
 * rule targets an element directly and wins over an inherited value, though it never wins over a value
 * set directly on the element itself — so the link's own ink survives a visited link exactly as an
 * unvisited one. `--label-halo` is the one property left to inherit (set on the label element, an
 * inherited CSS property, so every descendant text carries it).
 */

import { LABEL_FONT, LABEL_LINE_PX } from './label-paint.js';

/**
 * The inset every visible line's backing extends past its text, LEFT AND RIGHT ONLY: room for a glyph's
 * own rendered ink, which can sit a few px past its line box's side edge, to stay on the backing (falling
 * back). There is no vertical inset, so a shorter line never looks padded above or below its neighbour —
 * and so the backing ends exactly at the line box's top and bottom edges.
 */
const LINE_PAD = '0 5px';

/** Every visible line's shared shape — a fixed row of text, never wrapped, ellipsized rather than clipped mid-glyph. */
const LINE_STYLE = {
    overflow: 'hidden',
    whiteSpace: 'nowrap',
    textOverflow: 'ellipsis',
    padding: LINE_PAD,
    backgroundColor: 'var(--label-backing)',
};

/**
 * One line: a flex row, never wider than the label (`justify-content: var(--label-align)` positions its
 * one child at the label's own beside/fallback edge), so a line shorter than the label's own width never
 * paints a backing bar past its own text (card#7343 r4 review m5).
 */
function lineRow(doc) {
    const row = doc.createElement('div');

    Object.assign(row.style, { display: 'flex', alignItems: 'baseline', justifyContent: 'var(--label-align)', gap: '0.25em' });

    return row;
}

/** One line's text-bearing element — shrinks and ellipsizes, carries the backing and its own ink. */
function textEl(doc, tag, ink) {
    const el = doc.createElement(tag);

    Object.assign(el.style, LINE_STYLE, { display: 'block', flex: '0 1 auto', minWidth: '0', color: ink });

    return el;
}

/**
 * The plate's `<li>`, standing at `rect` in the building's scene px, carrying its label.
 *
 * @param {Document} doc the page's `document` — or the harness's stand-in
 * @param {{floor: string, name: string, summary: string, href: string,
 *          rooms: Array<{install_id: string, form: string, reported: boolean}>}} plate a
 *        `building-model.js` plate
 * @param {{x: number, y: number, w: number, h: number}} rect where `building-scene.js` stands it
 * @param {boolean} here whether the elevator's cab is at this plate
 */
export function plateRow(doc, plate, rect, here) {
    const row = doc.createElement('li');

    // The key the keyboard's focus reports back to the screen (`lobby/main.js`'s focus-into-view).
    row.dataset.floor = plate.floor;
    Object.assign(row.style, {
        position: 'absolute',
        left: `${rect.x}px`,
        top: `${rect.y}px`,
        width: `${rect.w}px`,
        height: `${rect.h}px`,
    });

    const label = doc.createElement('div');

    Object.assign(label.style, {
        position: 'absolute',
        top: '0',
        left: 'var(--label-left)',
        width: 'var(--label-width)',
        maxHeight: `calc(var(--label-lines) * ${LABEL_LINE_PX}px)`,
        clipPath: 'inset(-4px -4px 0 -4px)',
        fontSize: LABEL_FONT,
        lineHeight: `${LABEL_LINE_PX}px`,
        transformOrigin: '0 0',
        transform: 'scale(var(--label-scale))',
        textShadow: 'var(--label-halo)',
    });

    // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS.
    const summaryText = plate.summary === '' ? 'no seats held' : plate.summary;

    // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor". Line 1: shrinks and ellipsizes
    // before the cue does (`flex: 0 1 auto; min-width: 0`), never grows past its own content width
    // (`flex-grow: 0`) so a short name sits snug against the cue rather than stretched away from it.
    const link = textEl(doc, 'a', 'var(--label-ink)');

    link.href = plate.href;
    link.textContent = plate.name;
    // The focus ring's own outline draws OUTSIDE the element's border box by default, where a one-line
    // label's own clip-path bottom inset (0) can cut it; -2px pulls the ring INSIDE instead.
    link.style.outlineOffset = '-2px';
    // `aria-label`, not `aria-labelledby`: the latter joins every referenced id's own name with ONE
    // forced space regardless of the referenced text, so a hidden separator span could not remove the
    // stray space it left before the comma (found empirically, a Chromium accessibility-tree read). A
    // literal string here is byte-for-byte control instead — see FLOOR.md § 4.1 for the contract.
    link.setAttribute('aria-label', `${plate.name}, ${summaryText}`);

    const line1 = lineRow(doc);

    line1.append(link);

    if (here) {
        // § 4.5: "Colour is never the only carrier of a fact" — so the cab is a word, never dropped
        // (design review P2: "never ellipsizes away"), and stays OUTSIDE both links and the accessible
        // name they build, as it always has.
        const cue = textEl(doc, 'span', 'var(--label-text-ink)');

        cue.textContent = ' — the elevator is here';
        cue.style.flex = '0 0 auto';
        line1.append(cue);
    }

    // Line 2: the summary — a SECOND link to the SAME floor (the operator's ruling: "clicking the
    // summary also opens the floor"), out of the tab order and never announced on its own: the name
    // link's `aria-label` has already read this text as part of ONE accessible name.
    const summaryLink = textEl(doc, 'a', 'var(--label-text-ink)');

    summaryLink.href = plate.href;
    summaryLink.tabIndex = -1;
    summaryLink.setAttribute('aria-hidden', 'true');
    summaryLink.textContent = summaryText;
    // The name link keeps its own underline as the visible link cue; the summary link drops it — a click
    // target, never a second visible link (FLOOR.md § 4.1).
    summaryLink.style.textDecoration = 'none';
    summaryLink.style.outlineOffset = '-2px';

    const line2 = lineRow(doc);

    line2.append(summaryLink);

    label.append(line1, line2);

    // Line 3: the rooms, where the plate names any — dropped last of the three by `--label-lines`'s own
    // DOM-order priority (never removed: `clip-path` hides it visually, not from the accessibility tree).
    if (plate.rooms.length > 1 || plate.rooms.some((room) => !room.reported)) {
        // `div`, never `span` — the browser tool's own `isCue` reads a line's tag (`tools/design/
        // lobby-label-contrast.browser.mjs`'s `BOXES`), and the cue is the only `span` a label ever holds.
        const rooms = textEl(doc, 'div', 'var(--label-text-ink)');

        rooms.textContent = ' — rooms: ' + plate.rooms
            .map((room) => `${room.install_id} (${room.form}${room.reported ? '' : ' — no seats reported for this room'})`)
            .join(', ');

        const line3 = lineRow(doc);

        line3.append(rooms);
        label.append(line3);
    }

    row.append(label);

    return row;
}
