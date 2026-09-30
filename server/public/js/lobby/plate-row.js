/**
 * ONE PLATE OF THE CROSS-SECTION, AS ELEMENTS — the row `lobby/main.js` stands at its rect in the
 * building's scene, and the text it carries. `docs/design/FLOOR.md § 4.1`, Appendix B row 16 (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NO FACT. Every string is `building-model.js`'s plate — its `name` (§ 4.6: the label,
 * else the key), its `summary`, its `rooms`, its `href` — and whether the cab is here is the building's
 * `elevator.at`, which the page passes in; where the plate stands is `building-scene.js`'s rect, and
 * where its LABEL stands — beside the plate, or falling back onto it — is `building-scene.js`'s
 * `labelSide()`/`labelMaxLeft()`/`labelMax()`/`labelLines()`, read once and handed in as `label` (card#7343
 * r3's fix round). What this module owns is how those are put into elements, and it is a module rather
 * than lines of the page because that is the part the plate's text size depends on: the harness builds
 * these very rows under `node` with a stand-in `document`
 * (`Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`), so where the text sits under the
 * camera's transform is read off the shipped construction rather than off a copy of it.
 *
 * ⛔ THE PLATE'S TEXT IS READ AT THE PAGE'S BODY TEXT SIZE — ITS NAME AND ITS STATUS LINE BOTH (operator
 * rulings on card#7343, 2026-09-27: F1 for the name, r2 for the status line). The lobby exists to pick a
 * floor, and at whole-building fit a plate is small — a storey's proportions, height-bound, so the more
 * floors the smaller. So all of a plate's text is ONE label the camera MOVES and never SCALES: set at
 * `building-scene.js`'s `LABEL_FONT` (the page's base size, `1rem`) under `scale(var(--label-scale))`,
 * the counter-scale `lobby/main.js`'s `view()` writes from the camera it shows.
 *
 * ⭐ THE LABEL STANDS BESIDE THE BUILDING — card#7343 r3's fix round, the operator's ruling 2026-09-30,
 * option A: *"the lobby's floor labels move BESIDE the building, as the ratified reference does"*
 * (`docs/design/floor-preview/floor-preview.html`'s per-storey name, drawn to the left of its storey,
 * `text-anchor="end"`, never over the drawing). `label.side === 'left'` stands the label's right edge at
 * the plate's own left edge, `LABEL_GAP_PX` real px clear of it, and grows LEFT, wrapped within
 * `label.maxWidth` — `building-scene.js`'s `labelMaxLeft()`, what is visible to the plate's left — text
 * right-aligned, as the reference's own placement reads. ⛔ NOT `right: '100%'` OF THE PLATE'S `<li>`
 * — that box-model solve was tried first and produced a WRONG position (a real-px `right` mixed with the
 * containing block's own SCENE-px width, so the ancestor's `scale(camera.zoom)` placed it wrong at every
 * zoom below the ceiling): `left` is set directly, in SCENE px, below — the code's own comment there
 * derives why. ⛔ THE FALLBACK, `label.side === 'plate'` (the operator's own words:
 * *"On a screen with no room beside the building (phone width), labels fall back to sitting over the
 * plate"*): the SAME placement this module carried before r3 — the label's left edge AT the plate's own
 * top-left, growing right, wrapped within `label.maxWidth` — now `building-scene.js`'s `labelMax()`,
 * unchanged — and left-aligned. `building-scene.js`'s `labelSide()` decides which, from the camera the
 * page shows; this module reads only its answer, never the camera.
 *
 * ⛔ EITHER WAY THE LABEL STANDS AT THE PLATE's TOP, GROWING DOWN (card#7343 r3b, the seat's ruling,
 * unchanged by r3's fix round — beside or on the plate, only the label's X axis moved): the name, the
 * label's first line, stands at the plate's top edge whatever the label's height; a label taller than its
 * plate runs down over the plate below it (fallback mode) or past its OWN storey's own height (beside
 * mode, where `label.lines`' drop order below is what keeps that from happening), and the clip cuts a
 * fallback label's last lines first at the building's own bottom edge.
 *
 * ⭐ NO LABEL OVERLAPS ANOTHER (card#7343 r3's ruling): a label beside the building never runs taller than
 * its OWN storey (`PLATE_H` at the camera's zoom), because it never shows more than `label.lines` of its
 * four possible lines — the name and the summary always, then the rooms line, then *the elevator is
 * here*, in that FIXED drop order (`building-scene.js`'s `labelLines()`'s own docblock says why this
 * order). A line beyond the budget is not REMOVED — it is VISUALLY HIDDEN (`VISUALLY_HIDDEN`, the same
 * clip the separator already used), so it is still in the DOM and still read by assistive technology:
 * *the elevator is here*, the line the ruling names by name as needing to *"remain perceivable somehow"*
 * for the storey that holds the cab, remains perceivable to anyone reading the page with a screen reader
 * even on a storey too short to show it — the cab's own drawn position in the shaft (`building-scene.js`'s
 * `CAB`) is what a SIGHTED viewer reads there instead, which is why the elevator's own line is the LAST
 * one this order keeps: it is the one line whose fact is drawn twice.
 *
 * ⛔ THE LABEL CARRIES ITS OWN HALO AND A BACKING, NEVER A BOX ON THE LABEL ITSELF (design review r2,
 * card#7343 row 16 F1, replacing r1's opaque plaque, which blanked the drawing under it): beside the
 * building a label sits over A17's dim backdrop (`building-scene.js`'s `surfaceStyle()`); falling back
 * onto the plate it can sit over the windows, the shaft or the cab — either way, legible because of a
 * wall-coloured `text-shadow` stacked behind its glyphs AND a near-opaque wall-coloured backing behind
 * each visible text SPAN (`LABEL_BACKING`, one box per line, `box-decoration-break: clone`), never because
 * of what stands behind it, and never by hiding what stands behind it either: the LABEL's own box carries
 * neither, so between and past its text spans the drawing (or the backdrop) still shows. The halo is set
 * on the label element (an INHERITED CSS property, so every descendant text carries it too); the backing
 * is set per visible span. Both are counter-scaled with the text and never scaled by the scene. The
 * halo's colour, `building-scene.js`'s `LABEL_HALO`, clears 4.5:1 against the link and the body text as a
 * colour pair; the backing's contrast over the rendered drawing AND over A17's backdrop, at every phase,
 * is MEASURED on pixels by `tools/design/lobby-label-contrast.browser.mjs`, which also measures that no
 * label pixel covers the building drawing where `label.side === 'left'`.
 *
 * ⛔ THE PLATE'S ACCESSIBLE NAME IS WHAT IT WAS: the link carries the name, ` — ` and the summary, in
 * that order, as it always did — the separator VISUALLY HIDDEN now that the two sit on lines of their own,
 * and still read. The rooms and the cab's word stay outside the link, as they were.
 */

import { LABEL_BACKING, LABEL_FONT, LABEL_HALO, LABEL_HALO_RADII } from './building-scene.js';

/**
 * The halo itself: one zero-offset `text-shadow` layer of `LABEL_HALO` per radius in
 * `LABEL_HALO_RADII` — why the stack is dense, and why each radius is repeated (two layers of opacity
 * *a* composite to 1 − (1 − *a*)²), is that figure's docblock. Built once, here, rather than on every
 * row `plateRow()` builds.
 */
const LABEL_TEXT_SHADOW = LABEL_HALO_RADII.map((r) => `0 0 ${r}px ${LABEL_HALO}`).join(', ');

/**
 * The backing each of the label's visible text spans stands on (card#7343 r1, the seat's ruling R2): the
 * wall's colour behind the TEXT, one box per line (`box-decoration-break: clone`) — so a label that runs off
 * its plate, over a dark window or the sky behind the building, still stands on the wall's colour, and the
 * drawing shows between the lines and past their ends. Never on the label's own box: that plaque blanked
 * the drawing (design review r1). `LABEL_BACKING`'s docblock says what is measured over it.
 */
const TEXT_BACKING = {
    backgroundColor: LABEL_BACKING,
    boxDecorationBreak: 'clone',
    webkitBoxDecorationBreak: 'clone',
    // ⛔ A SMALL PADDING, EVERY SIDE (card#7343 r3's fix round MINOR 3): with none, the backing's own box
    // is the span's LINE BOX alone, and a glyph's own rendered ink can sit close enough to that box's
    // edge that a few px beyond it — still legible space a viewer's eye reads as part of the same word —
    // falls OUTSIDE the backing, onto whatever is behind it. `tools/design/lobby-label-contrast.browser
    // .mjs` measures a ring 2–3 px out from every glyph pixel for exactly this reason; this padding is
    // what keeps that ring inside the backing rather than past its edge.
    padding: '5px',
};

/**
 * The fixed gap between a beside label's right edge and the plate's own left edge, in real CSS px —
 * small and non-zero so the rendered pixels never touch regardless of rounding. Real, the same frame
 * `LABEL_MIN_PX` and `label.maxWidth` already live in: `plateRow()`'s own docblock, at its `left`, says
 * why turning this back into a SCENE quantity (dividing by `label.zoom`) is what makes it real rather
 * than assuming it already is.
 */
const LABEL_GAP_PX = 8;

/**
 * Text kept for assistive technology and never painted — the conventional clip, since the page ships
 * no stylesheet to put a class in.
 */
const VISUALLY_HIDDEN = {
    position: 'absolute',
    width: '1px',
    height: '1px',
    margin: '-1px',
    padding: '0',
    border: '0',
    overflow: 'hidden',
    clipPath: 'inset(50%)',
    whiteSpace: 'nowrap',
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
export function plateRow(doc, plate, rect, here, label) {
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

    const beside = label.side === 'left';
    const labelEl = doc.createElement('div');

    Object.assign(labelEl.style, {
        position: 'absolute',
        // ⛔ AT THE PLATE's TOP, GROWING DOWN, EITHER WAY (card#7343 r3b's ruling, unchanged by r3's fix
        // round): the name, the label's first line, stands at the plate's top edge whatever the label's
        // height. Falling back, its LEFT edge stands AT the plate's own top-left and it grows right,
        // left-aligned, as it always did — what falls past the surface's edge first there is its last
        // lines, the status line's.
        //
        // ⛔ BESIDE, `left` IS A SCENE-PX NUMBER, NEVER A REAL-PX ONE LIKE `width` BELOW (card#7343 r3's
        // fix round — the bug its own history is worth stating, because the fix is not obvious from the
        // result). This element's OWN `transform: scale(labelScale)` is what makes a REAL-px number
        // (`label.maxWidth`, `LABEL_FONT`) render at that many SCREEN px regardless of the camera's zoom
        // — but that cancellation holds ONLY for a length INSIDE this element's own box (its width, its
        // font), read off the fixed point its `transformOrigin` anchors (`'0 0'`, this element's own
        // pre-transform top-left) and NEVER MOVED by this element's own transform. `left`/`top` are NOT
        // inside that box — they are where the ANCESTOR `<ul>`'s `scale(camera.zoom)` places this
        // element's own anchor POINT, the same one `rect.x`/`rect.y` are placed by — so a `left` meant to
        // read as `label.maxWidth` REAL px would, read as a SCENE quantity by that ancestor, land at only
        // `label.maxWidth · camera.zoom` real px: correct at the ceiling zoom (≈1), wrong (and shrinking
        // further) at every zoom below it — which is every whole-building fit this round exists for. So
        // `left` is `label.maxWidth` (plus the gap) turned back into SCENE px by DIVIDING by the same
        // `camera.zoom` the ancestor is about to multiply it by, undoing that division exactly and
        // landing the label's right edge `LABEL_GAP_PX` real px clear of the plate's own left edge — real
        // px, because `LABEL_GAP_PX` is divided by the SAME zoom for the SAME reason.
        top: '0',
        ...(beside
            ? { left: `${-(label.maxWidth + LABEL_GAP_PX) / label.zoom}px`, textAlign: 'right' }
            : { left: '0' }),
        fontSize: LABEL_FONT,
        // ⛔ WRAPPED WITHIN WHAT IS VISIBLE (card#7343 r3, r4b, and r3's fix round): the label's px are
        // screen px (the counter-scale), so a label no wider than `label.maxWidth` — `building-scene.js`'s
        // `labelMaxLeft()` beside the building, or its `labelMax()` falling back onto the plate — never
        // wider than the surface, is one a pan can always bring wholly into view.
        //
        // ⛔ BESIDE, THE WIDTH IS FIXED, NEVER `max-width`: with `left` ALREADY placing this element's own
        // right edge exactly `LABEL_GAP_PX` clear of the plate (the docblock above), an auto/shrink-to-fit
        // width would still need SOME 'available width' figure to shrink-to-fit against, and the only one
        // this element's own containing block (the `<li>`) can state is ITS OWN, unrelated to the real px
        // this element is meant to read at — so `width` is set directly, the SAME `label.maxWidth` real-px
        // number `maxWidth` used before r3, and short text (most floor names) sits right-aligned inside it
        // rather than filling it — invisible, since only the SPANS carry a backing, never this box.
        whiteSpace: 'normal',
        overflowWrap: 'anywhere',
        [beside ? 'width' : 'maxWidth']: `${label.maxWidth}px`,
        // The counter-scale about the label's own top-left, so the scaled label's top stays on the
        // plate's top edge whichever way it grows (its OWN box is already sized/positioned by `right`
        // or `left` above before this transform is ever applied).
        transformOrigin: '0 0',
        transform: 'scale(var(--label-scale))',
        // ⛔ THE HALO, NEVER A BOX ON THE LABEL ITSELF (design review r2, card#7343 row 16 F1): a
        // wall-coloured `text-shadow` stacked behind the glyphs, set here so it is counter-scaled with the
        // text and never scaled by the scene, and inherited by every descendant span — the name, the
        // summary, the rooms and the cab's word alike. No background, no padding, no radius on the label
        // ITSELF: the drawing (or A17's backdrop) shows between and past its text spans, which carry their
        // own backing (`TEXT_BACKING`).
        textShadow: LABEL_TEXT_SHADOW,
    });

    // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor".
    const link = doc.createElement('a');

    link.href = plate.href;

    const name = doc.createElement('span');

    // § 4.6 (card#9273): the floor reads as its LABEL where the layout gives it one, else as its key.
    // The link is the key either way. A line of its own: the status line stands under it.
    name.textContent = plate.name;
    // A line of its own, as wide as its text: its backing hugs the name rather than spanning the label.
    // Beside the building, `textAlign: 'right'` (on `labelEl`, below) moves INLINE content but not this
    // BLOCK one — a block child with a fit-content width sits at its parent's own left edge regardless
    // of the parent's `text-align` — so beside, `marginLeft: 'auto'` pushes it to the label's right edge
    // (where its own text-align then reads, matching the reference's `text-anchor="end"`) the same way a
    // block-level auto margin always has; falling back, the label is left-aligned and the name already
    // sits where it should.
    Object.assign(name.style, { display: 'block', width: 'fit-content', ...(beside ? { marginLeft: 'auto' } : {}) }, TEXT_BACKING);

    const separator = doc.createElement('span');

    separator.textContent = ' — ';
    Object.assign(separator.style, VISUALLY_HIDDEN);

    const summary = doc.createElement('span');

    // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS.
    summary.textContent = plate.summary === '' ? 'no seats held' : plate.summary;
    Object.assign(summary.style, TEXT_BACKING);
    link.append(name, separator, summary);

    // ⛔ THE DROP ORDER — name, summary, rooms, *the elevator is here* (card#7343 r3's ruling, this
    // module's own docblock): the name and the summary are on the LINK and are never dropped; rooms and
    // the cab's word are built here in that priority order, and `label.lines` — `building-scene.js`'s
    // `labelLines()`, the storey's own height divided into lines — decides how many of them, beyond the
    // two required, are VISUALLY shown. A dropped one is not removed: it is `VISUALLY_HIDDEN`, so it is
    // still in the DOM and still read by assistive technology — the elevator's own line, dropped last of
    // the two (this order's whole point), is also the one line a sighted viewer still reads another way,
    // off the cab's own drawn position in the shaft.
    const optional = [];

    if (plate.rooms.length > 1 || plate.rooms.some((room) => !room.reported)) {
        const rooms = doc.createElement('span');

        rooms.textContent = ' — rooms: ' + plate.rooms
            .map((room) => `${room.install_id} (${room.form}${room.reported ? '' : ' — no seats reported for this room'})`)
            .join(', ');
        optional.push(rooms);
    }

    if (here) {
        // § 4.5: "Colour is never the only carrier of a fact" — so the cab is a word.
        const cab = doc.createElement('span');

        cab.textContent = ' — the elevator is here';
        optional.push(cab);
    }

    const shown = Math.max(0, label.lines - 2);

    optional.forEach((span, i) => {
        Object.assign(span.style, TEXT_BACKING);

        if (i >= shown) {
            Object.assign(span.style, VISUALLY_HIDDEN);
        }
    });

    labelEl.append(link, ...optional);
    row.append(labelEl);

    return row;
}
