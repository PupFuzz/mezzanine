/**
 * ONE PLATE OF THE CROSS-SECTION, AS ELEMENTS — the row `lobby/main.js` stands at its rect in the
 * building's scene, and the text it carries. `docs/design/FLOOR.md § 4.1`, Appendix B row 16 (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NO FACT, AND NO GEOMETRY EITHER (card#7343 r4's fix round, replacing the r1–r3
 * mechanism). Every string is `building-model.js`'s plate — its `name` (§ 4.6: the label, else the
 * key), its `summary`, its `rooms`, its `href` — and whether the cab is here is the building's
 * `elevator.at`, which the page passes in; where the plate stands is `building-scene.js`'s rect. Where
 * its LABEL stands, how wide it is, how many lines show and what it is painted in are NONE of this
 * module's business any more: `plateRow()` builds ONE static shape, EVERY property read through a
 * `var()` `lobby/label-paint.js`'s `showLabels()` writes on `#lobby-floors` — never a number or a colour
 * computed here from `plate`, `rect` or a camera. This is the structural fix the round exists for: a
 * plate's row, once built, needs no rebuild for its label to track the camera, because nothing about the
 * label lives in what this module builds — it lives entirely in the custom properties `showLabels()`
 * rewrites on every `view()`.
 *
 * ⛔ THE PLATE'S TEXT IS READ AT THE PAGE'S BODY TEXT SIZE (operator rulings on card#7343, 2026-09-27):
 * `label-paint.js`'s `LABEL_FONT` (the page's base size, `1rem`) under `scale(var(--label-scale))`, the
 * counter-scale `lobby/main.js`'s `view()` writes (through `showLabels()`) from the camera it shows.
 *
 * ⭐ THE LABEL STANDS BESIDE THE BUILDING (the operator's ruling, 2026-09-30, option A): `--label-left`
 * (`label-paint.js`) stands the label's right edge a fixed gap clear of the SHELL's own left edge and it
 * grows LEFT, wrapped within `--label-width`, right-aligned (`--label-align`), as the reference's own
 * `text-anchor="end"` placement reads. ⛔ THE FALLBACK, `data-label-side="plate"` (a TEST HOOK ONLY — this
 * module reads no attribute, only `var()`s; the operator's own words: *"On a screen with no room beside
 * the building (phone width), labels fall back to sitting over the plate"*): `--label-left` is `0` and
 * `--label-align` is `'left'`, so the SAME styles below read as the label's ORIGINAL on-plate placement,
 * left-aligned at the plate's own top-left, with no branch in this module at all — the fallback is not a
 * second shape `plateRow()` builds, it is the SAME shape under different numbers.
 *
 * ⭐ THREE LINES, DETERMINISTIC, WHOLE-LINE CLIPPED (design review P2, replacing the r1–r3 mechanism's
 * JS item-count budget, which counted OPTIONAL SPANS rather than wrapped lines and let a long rooms line
 * overlap the plate below regardless): each line is `white-space: nowrap; overflow: hidden; text-overflow:
 * ellipsis` — a fixed row of text that never wraps and never grows taller than one line, with its FULL
 * text always in the DOM. Line 1 is the NAME, with *the elevator is here* riding the SAME line on the
 * cab's plate. Line 2 is the summary. Line 3 is the rooms, where the plate names any. **Each line is now
 * the SAME shape (card#7343 r4 review m5, design + impl): a flex row (`lineRow()`) whose ONE text-bearing
 * child shrinks and ellipsizes (`flex: 0 1 auto; min-width: 0`) — never a full-width block.** The r4
 * shape used a full-width `<div>` for lines 2–3, so their backing (`background-color`) painted a bar the
 * width of the WHOLE label, crossing the windows, the shaft and the sky in the fallback; the shared line
 * shape makes every line's backing hug its own text, exactly as line 1's already did.
 *
 * ⛔ THE LABEL's OWN BOX IS `max-height: calc(var(--label-lines) * {LINE_PX}px)`, CLIPPED BY `clip-path`,
 * NEVER `overflow` (card#7343 r4 review MAJOR 2, both rounds: a plaque tried `overflow: clip` with
 * `overflow-clip-margin: 4px` for the halo's and the focus ring's own breathing room — but
 * `overflow-clip-margin` pushes the clip boundary OUT ON EVERY SIDE, including the BOTTOM, so at floor
 * counts where a storey affords only one line, the NEXT (clipped-away) line's own glyph tops and halo
 * painted through the 4px gap between `max-height` and where the clip actually cut — a real, rendered
 * defect, proven by an A/B render, not a theoretical one). `clip-path: inset(-4px -4px 0 -4px)` clips
 * the SAME box — 4px of bleed on the top and both sides (so a glyph's own halo, and a focused link's own
 * outline, are not cut where they sit close to the label's own edges), `0` on the bottom, exactly at
 * `max-height`'s own edge, where the NEXT line must never show through. `clip-path` needs no `overflow`
 * property at all: it clips an element's own rendered content — text, shadows, descendants — to the
 * shape given, independent of `overflow`, and it is never independently scrollable the way `overflow:
 * hidden`/`auto` can be (the concern that ruled out `hidden` in the first place) — `inset()`'s own
 * reference box is the element's border box, so the bottom edge tracks `max-height` exactly as `overflow`
 * would have, and only the asymmetry (bleed on three sides, none on the fourth) is new.
 *
 * ⭐ NEITHER A DROPPED LINE NOR THE CUE IS REMOVED FROM THE ACCESSIBILITY TREE. `clip-path` hides a
 * clipped line VISUALLY; it stays a normal DOM node, read by a screen reader in ordinary document order
 * regardless of whether the line-count budget currently shows it — so the rooms line, when a storey is
 * too short to show it, is still announced to anyone reading the page with a screen reader, exactly as
 * the summary and the name are on every storey.
 *
 * ⭐ THE LINK's ACCESSIBLE NAME IS THE NAME AND THE SUMMARY TOGETHER (the operator's ruling, card#7343,
 * 2026-09-30, option A, opq-1790766555-81d4 — restoring the r1–r3 mechanism's own contract, which card#7343
 * r4's fix round had DROPPED to "the name alone" for a reason its own review round found FALSE: nothing
 * about the flex-row line shape actually requires it): *"The link announces the name with its summary
 * (e.g. 'Floor 2, 4 seats · 3 live'), and clicking the summary also opens the floor. Do it without
 * changing the layout."* Two elements, no layout change: the name `<a>` carries `aria-label`, set to the
 * literal string `` `${name}, ${summary}` `` — replacing the link's own text-derived name entirely, so a
 * screen reader announces exactly that one string. ⚠ `aria-labelledby` (naming itself, a hidden separator
 * span and the summary) was tried first and REFUTED empirically, not assumed sound: a Chromium
 * accessibility-tree read showed "Floor 2 , 4 seats · 3 live" — `aria-labelledby` joins every referenced
 * id's own name with ONE forced space regardless of what the referenced text itself is, so no separator
 * text can remove the stray space before the comma; `aria-label` gives this module byte-for-byte control
 * instead. The summary (line 2) is a SECOND `<a>` to the SAME `href`, `tabindex="-1"` (never a second tab
 * stop) and `aria-hidden="true"` (never announced a second time, since the name link's `aria-label` has
 * already read this text as part of ONE accessible name) — clickable, because it is a real link, without
 * duplicating either the keyboard stop or the announcement. The cue and the rooms line stay OUTSIDE both
 * links, exactly as before: the cue is the cab's own fact and the rooms line is read in ordinary document
 * order right after the (one, combined) accessible name. `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`
 * holds the accessible name and reds a name link whose summary drops out of it.
 *
 * ⛔ THE HALO AND THE BACKING ARE `label-paint.js`'s VALUES, READ THROUGH `var()`s, NEVER COMPUTED HERE:
 * `--label-halo` (a thin dark wash beside the building, guarding a glyph against a bright star pixel
 * behind it; `none` falling back, where the near-opaque backing already holds contrast) is set on the
 * label element (an INHERITED CSS property, so every descendant text carries it); `--label-backing` (the
 * per-line background — `transparent` beside, P3: "no backing"; the wall's near-opaque cream falling
 * back) and the two inks (`--label-ink` for the two links, `--label-text-ink` for the rooms line and the
 * cue) are set per element, because `color` for a link must be set DIRECTLY on the `<a>` rather than
 * merely inherited — a value the browser's own `:visited` rule could otherwise beat (a `:visited` rule
 * TARGETS the element directly and wins over an INHERITED value, even though it never wins over a value
 * set directly on the element itself) — so gold survives a visited link exactly as an unvisited one.
 */

import { LABEL_FONT, LABEL_LINE_PX } from './label-paint.js';

/**
 * The small inset every visible line stands on, every side: room for a glyph's own rendered ink, which
 * can sit a few px past its line box's edge and still read as part of the same word, so it does not fall
 * outside the backing (falling back) onto whatever is behind it. Horizontal only, per P3's own
 * instruction, so a shorter line never looks padded above or below its neighbour.
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
    // The operator's ruling: the name and the summary are ONE accessible name, e.g. "Floor 2, 4 seats ·
    // 3 live". ⚠ `aria-labelledby` (the first attempt, empirically tested — a Chromium accessibility-tree
    // read, never assumed) does NOT give this string: a screen reader joins every referenced id's own
    // name with ONE forced space, so a hidden ", " separator comes out "Floor 2 , 4 seats..." — a stray
    // space before the comma no amount of separator-text tuning can remove, because the join itself, not
    // the referenced text, inserts that space. `aria-label` sets the accessible name to a literal string
    // this module controls byte-for-byte instead, replacing the link's own text-derived name entirely
    // (never announced twice) and matching the ruling's own punctuation exactly.
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
