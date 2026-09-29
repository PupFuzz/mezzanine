/**
 * ONE PLATE OF THE CROSS-SECTION, AS ELEMENTS — the row `lobby/main.js` stands at its rect in the
 * building's scene, and the text it carries. `docs/design/FLOOR.md § 4.1`, Appendix B row 16 (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NO FACT. Every string is `building-model.js`'s plate — its `name` (§ 4.6: the label,
 * else the key), its `summary`, its `rooms`, its `href` — and whether the cab is here is the building's
 * `elevator.at`, which the page passes in; where the plate stands is `building-scene.js`'s rect. What
 * this module owns is how those are put into elements, and it is a module rather than lines of the
 * page because that is the part the plate's text size depends on: the harness builds these very rows
 * under `node` with a stand-in `document` (`Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`),
 * so where the text sits under the camera's transform is read off the shipped construction rather than
 * off a copy of it.
 *
 * ⛔ THE PLATE'S TEXT IS READ AT THE PAGE'S BODY TEXT SIZE — ITS NAME AND ITS STATUS LINE BOTH (operator
 * rulings on card#7343, 2026-09-27: F1 for the name, r2 for the status line). The lobby exists to pick a
 * floor, and at whole-building fit a plate is small — a storey's proportions, height-bound, so the more
 * floors the smaller. So all of a plate's text is ONE label over the plate, which the camera MOVES and
 * never SCALES: set at `building-scene.js`'s `LABEL_FONT` (the page's base size, `1rem`) under
 * `scale(var(--label-scale))`, the counter-scale `lobby/main.js`'s `view()` writes from the camera it
 * shows. Stacked, the name first: the name, a line of its own, then the status line — the summary, the
 * rooms where the plate names them, and *the elevator is here* on the cab's plate. The label wraps within
 * `--label-max`, which `view()` writes too — `building-scene.js`'s `labelMax()`, what is visible of the
 * surface to the right of the plate's on-screen left edge, never wider than the surface (card#7343 r4b) —
 * so at whole-building fit a label reads to its end without a pan wherever the surface shows at least
 * `LABEL_MIN_PX` beside the plates, and on a narrow or browser-zoomed surface a long status line takes
 * more lines rather than running past the surface's edge, where the drawing's clip would hide it from
 * every pan. The label stands at the plate's TOP-LEFT corner and grows down from it (card#7343 r3b, the
 * seat's ruling), so a floor's name — the label's first line — is on its plate's top
 * edge however many lines the wrap makes. Two plates' labels can meet only where a plate on the screen is
 * shorter than its label's lines; a label then runs down over the plate below it, and the bottom plate's
 * below the building's bottom edge, so what the surface's clip cuts first is a label's last lines — the
 * status line's. A name is reached only where a plate on the screen is shorter than the name's own lines,
 * past the point where labels meet (a plate shorter than the name's lines, not all of the label's). Anchored at the bottom instead, a label taller than its plate ran
 * up past the building's top edge, and the clip cut the top floor's name first (Appendix B row 16,
 * decision 38).
 *
 * ⛔ THE PLATE'S ACCESSIBLE NAME IS WHAT IT WAS: the link carries the name, ` — ` and the summary, in
 * that order, as it always did — the separator VISUALLY HIDDEN now that the two sit on lines of their own, and
 * still read. The rooms and the cab's word stay outside the link, as they were.
 */

import { LABEL_FONT } from './building-scene.js';

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
        // ⛔ AT THE PLATE's TOP-LEFT, GROWING DOWN (card#7343 r3b, the seat's ruling): the name, the label's
        // first line, stands at the plate's top edge whatever the label's height; a label taller than its
        // plate runs down, and what falls past the surface's edge first is its last lines — the status line's.
        left: '0',
        top: '0',
        fontSize: LABEL_FONT,
        // ⛔ WRAPPED WITHIN WHAT IS VISIBLE (card#7343 r3, then r4b, the seat's rulings): the label's px are
        // screen px (the counter-scale), so a label no wider than `--label-max` — `building-scene.js`'s
        // `labelMax()`, which `lobby/main.js`'s `view()` writes: the surface to the right of the plate's
        // on-screen left edge, never wider than the surface — reads to its end at fit wherever at least
        // `LABEL_MIN_PX` is visible, and is one a pan can always bring wholly into view.
        whiteSpace: 'normal',
        overflowWrap: 'anywhere',
        maxWidth: 'var(--label-max)',
        // The counter-scale about that same corner, so the scaled label's top stays on the plate's top edge.
        transformOrigin: '0 0',
        transform: 'scale(var(--label-scale))',
    });

    // § 4.1: "one row per floor, THE ROW BEING THE LINK to the floor".
    const link = doc.createElement('a');

    link.href = plate.href;

    const name = doc.createElement('span');

    // § 4.6 (card#9273): the floor reads as its LABEL where the layout gives it one, else as its key.
    // The link is the key either way. A line of its own: the status line stands under it.
    name.textContent = plate.name;
    name.style.display = 'block';

    const separator = doc.createElement('span');

    separator.textContent = ' — ';
    Object.assign(separator.style, VISUALLY_HIDDEN);

    const summary = doc.createElement('span');

    // § 2.1 row 5: the per-floor count is labelled as a count of the seats THE CLIENT HOLDS.
    summary.textContent = plate.summary === '' ? 'no seats held' : plate.summary;
    link.append(name, separator, summary);

    const status = [];

    if (plate.rooms.length > 1 || plate.rooms.some((room) => !room.reported)) {
        const rooms = doc.createElement('span');

        rooms.textContent = ' — rooms: ' + plate.rooms
            .map((room) => `${room.install_id} (${room.form}${room.reported ? '' : ' — no seats reported for this room'})`)
            .join(', ');
        status.push(rooms);
    }

    if (here) {
        // § 4.5: "Colour is never the only carrier of a fact" — so the cab is a word.
        const cab = doc.createElement('span');

        cab.textContent = ' — the elevator is here';
        status.push(cab);
    }

    label.append(link, ...status);
    row.append(label);

    return row;
}
