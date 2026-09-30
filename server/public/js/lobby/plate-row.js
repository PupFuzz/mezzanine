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
 * cab's plate (a flex row: the link `flex: 0 1 auto; min-width: 0`, so it shrinks and ellipsizes FIRST;
 * the cue `flex: 0 0 auto`, so it never shrinks and never ellipsizes away — design review P2's own
 * example shape). Line 2 is the summary. Line 3 is the rooms, where the plate names any. The label's own
 * box is `max-height: calc(var(--label-lines) * {LINE_PX}px); overflow: clip` (never `hidden` — design
 * review's refute round: `hidden` can make a clipped box independently SCROLLABLE, reachable by a focus
 * move or find-in-page in some browsers, which `clip` never is) — `--label-lines` (`label-paint.js`)
 * decides how many of the three lines are not clipped away, floored at `1` so line 1 (the name and the
 * cab's own cue) never disappears; DOM priority is the drop order, so a storey too short for the summary
 * or the rooms line clips them from the BOTTOM, never the name.
 *
 * ⛔ THE NAME-ONLY BOUND, IN LINES OF THE VIEWER'S FONT, NOT PX OR FLOOR COUNTS (design review P2: state
 * this honestly in FLOOR.md § 4.1, which does — this module's own note is the same fact). `--label-lines`
 * is floored at `1`, never `0`, but the label's OWN `max-height` is still exactly `1 × {LINE_PX}px` at
 * that floor — so on a storey whose own on-screen height is SHORTER than one line of the viewer's font,
 * the label's box (line 1 alone) runs past the storey's own bottom edge and can overlap the plate below
 * it. This is the SAME "no rule chooses what the lobby does then" case the r1–r3 mechanism already
 * declared at the two-lines-never-dropped floor; the floor is `1` line now rather than `2`, so it is
 * REACHED LESS OFTEN, never removed.
 *
 * ⭐ NEITHER A DROPPED LINE NOR THE CUE IS REMOVED FROM THE ACCESSIBILITY TREE. `overflow: clip` hides a
 * clipped line VISUALLY; it stays a normal DOM node, read by a screen reader in ordinary document order
 * regardless of whether the line-count budget currently shows it — so the rooms line, when a storey is
 * too short to show it, is still announced to anyone reading the page with a screen reader, exactly as
 * the summary and the name are on every storey.
 *
 * ⛔ THE LINK's ACCESSIBLE NAME IS THE PLATE'S NAME ALONE (a disclosed change from the r1–r3 mechanism's
 * contract, which concatenated the summary into the link too): the cue rides line 1 beside the link but
 * OUTSIDE it (design review P2's own instruction, preserving the one part of the old contract that still
 * applies — the cue was always outside the link), and the summary is now its own line, ALSO outside the
 * link, for a structural reason rather than a style choice — a flex row's items are each ONE line tall,
 * and the link would need to span two lines (name, then summary) to keep the summary inside it, which
 * breaks line 1's own one-line flex layout with the cue. The summary and the rooms line are still
 * ordinary DOM text, read by a screen reader in sequence right after the link, exactly as the rooms line
 * and the cue already were outside the link in the r1–r3 mechanism — only the summary's position in that
 * ordering is new. `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest` holds this reading order
 * and the link's accessible name.
 *
 * ⛔ THE HALO AND THE BACKING ARE `label-paint.js`'s VALUES, READ THROUGH `var()`s, NEVER COMPUTED HERE:
 * `--label-halo` (a thin dark wash beside the building, guarding a glyph against a bright star pixel
 * behind it; `none` falling back, where the near-opaque backing already holds contrast) is set on the
 * label element (an INHERITED CSS property, so every descendant text carries it); `--label-backing` (the
 * per-line background — `transparent` beside, P3: "no backing"; the wall's near-opaque cream falling
 * back) and the two inks (`--label-ink` for the link, `--label-text-ink` for the summary, the rooms line
 * and the cue) are set per element, because `color` for the link must be set DIRECTLY on the `<a>` rather
 * than merely inherited — a value the browser's own `:visited` rule could otherwise beat (a `:visited`
 * rule TARGETS the element directly and wins over an INHERITED value, even though it never wins over a
 * value set directly on the element itself) — so gold survives a visited link exactly as an unvisited one.
 */

import { LABEL_FONT, LABEL_LINE_PX } from './label-paint.js';

/**
 * The small inset every visible line stands on, every side: room for the halo's blur and the focus
 * ring not to be cut by the label's own `overflow: clip` (design review P2), and — falling back, where
 * the backing is opaque — room so a glyph's own rendered ink, which can sit a few px past its line box's
 * edge and still read as part of the same word, does not fall outside the backing onto whatever is
 * behind it. Horizontal only, per P3's own instruction, so a shorter line never looks padded above or
 * below its neighbour.
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
        overflow: 'clip',
        overflowClipMargin: '4px',
        fontSize: LABEL_FONT,
        lineHeight: `${LABEL_LINE_PX}px`,
        transformOrigin: '0 0',
        transform: 'scale(var(--label-scale))',
        textShadow: 'var(--label-halo)',
    });

    // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor". Line 1: shrinks and ellipsizes
    // before the cue does (`flex: 0 1 auto; min-width: 0`), never grows past its own content width
    // (`flex-grow: 0`) so a short name sits snug against the cue rather than stretched away from it.
    const link = doc.createElement('a');

    link.href = plate.href;
    link.textContent = plate.name;
    Object.assign(link.style, LINE_STYLE, {
        display: 'block',
        flex: '0 1 auto',
        minWidth: '0',
        color: 'var(--label-ink)',
    });

    const line1 = doc.createElement('div');

    Object.assign(line1.style, {
        display: 'flex',
        alignItems: 'baseline',
        justifyContent: 'var(--label-align)',
        gap: '0.25em',
    });
    line1.append(link);

    if (here) {
        // § 4.5: "Colour is never the only carrier of a fact" — so the cab is a word, never dropped
        // (design review P2: "never ellipsizes away").
        const cue = doc.createElement('span');

        cue.textContent = ' — the elevator is here';
        Object.assign(cue.style, LINE_STYLE, {
            display: 'block',
            flex: '0 0 auto',
            color: 'var(--label-text-ink)',
        });
        line1.append(cue);
    }

    // Line 2: the summary — outside the link (this module's own docblock says why), still read right
    // after it in ordinary document order.
    const summary = doc.createElement('div');

    // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS.
    summary.textContent = plate.summary === '' ? 'no seats held' : plate.summary;
    Object.assign(summary.style, LINE_STYLE, { textAlign: 'var(--label-align)', color: 'var(--label-text-ink)' });

    label.append(line1, summary);

    // Line 3: the rooms, where the plate names any — dropped last of the three by `--label-lines`'s own
    // DOM-order priority (never removed: `overflow: clip` hides it visually, not from the accessibility tree).
    if (plate.rooms.length > 1 || plate.rooms.some((room) => !room.reported)) {
        const rooms = doc.createElement('div');

        rooms.textContent = ' — rooms: ' + plate.rooms
            .map((room) => `${room.install_id} (${room.form}${room.reported ? '' : ' — no seats reported for this room'})`)
            .join(', ');
        Object.assign(rooms.style, LINE_STYLE, { textAlign: 'var(--label-align)', color: 'var(--label-text-ink)' });
        label.append(rooms);
    }

    row.append(label);

    return row;
}
