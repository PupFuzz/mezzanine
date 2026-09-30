/**
 * THE BUILDING'S SCENE — where each plate stands in the building's own coordinates, so that row 15's
 * camera (`../wire/camera.js`) can frame the whole building and zoom to one plate of it.
 * `docs/design/FLOOR.md` Appendix B row 16, slice A (card#7343).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ GEOMETRY AND NOTHING ELSE. A plate here is its key and a rect, and its text's size on the screen is
 * `LABEL_FONT` under `labelScale()` (below; `plate-row.js` sets it); every fact the plate carries —
 * its name, its rooms, its summary, its link — is `building-model.js`'s `plates()`, which this module
 * reads for the stack position alone (`level`) and never re-derives. So the cross-section still reads
 * no field the table does not and recounts nothing (row 16; AT-D3-15 stays at row 9).
 *
 * ⛔ THE STACK RUNS FIRST-AT-THE-TOP, because `level` is an index into § 4.1's ascending order and the
 * ratified reference draws the first of it at the top (`building-model.js`'s `plates()` says why that is
 * a rendering choice and not a ruling). A plate is `PLATE_H` below the one before it.
 *
 * ⛔ THE SURFACE IS A DRAWING ONLY WHILE THERE IS A BUILDING TO DRAW (card#7343 r3, the seat's ruling).
 * `surfaceStyle()` is `#lobby-building`'s own style: with a scene that has an extent it is the drawing —
 * a fixed height that clips the plates, which the camera alone moves; with none — no snapshot yet, § 9
 * F17's cold start with no layout (its rooms listed with no floor claimed), or no install — it is no
 * style at all, and the list flows in the page as the lobby's list always did, every row reachable by
 * the page's own scroll — the wheel's and the arrow keys' included, which `wire/camera-gestures.js` and
 * `wire/camera-keys.js` leave to the browser, with every other camera event, while the camera frames
 * nothing (card#7343 r3b, r4b).
 *
 * ⛔ THE NUMBERS ARE THE DRAWING's (§ 10.4's last bullet): a plate's size carries no fact. The plate is
 * a storey's proportion — the reference's storey has about the proportions of § 12's viewport
 * floor — so a zoom to one plate fills the surface rather than panning a strip across it.
 *
 * ⭐ THE PLATE DRAWN AS THE REFERENCE's SECTION — slice B (card#7343). `buildingArt()` is the building
 * the plates stand in, as data the page paints and decides nothing about (`lobby/main.js`'s painter, as
 * `floor/painter.js` paints `floor/scene.js`): the roof and its sign above the top plate, each plate a
 * storey — its wall, skirting, floorboards and slab, and its elevator doors in the shaft — and the ground
 * lobby under the bottom plate; `cabStyle()` stands the cab in its shaft at the viewer's plate. ⛔ SCENERY
 * CARRYING NO FACT: `buildingArt()` reads the scene's rects and nothing else — no plate's key, name,
 * summary or rooms — so two buildings of one height draw the same building
 * (`Tests\Feature\Lobby\TheBuildingIsDrawnAsTheReferencesSectionTest`). The roof and the ground lobby
 * stand inside the scene's `extent`, so the whole-building framing — the first framing and the
 * whole-building control — shows them.
 *
 * ⭐ THE SKY BEHIND THE BUILDING, AND NO CLOCK (the operator's ruling on card#7343, 2026-09-30; § 4.1).
 * `surfaceStyle()` paints the drawing surface — all of it the building does not cover, at every zoom —
 * with the sky of the phase it is handed, in the reference's dim treatment: the phase's gradient at
 * `SKY_OPACITY` under `SKY_WASH`, with stars at night (`docs/design/floor-preview/floor-preview.html`'s
 * building). ⛔ THE PHASE IS NOT DECIDED HERE: it is § 6.2 A17's value, set by the floor's own driver on a
 * delivered `feed.heartbeat` (`lobby-screen.js`'s frame `sky`), so this module maps a phase to paint and
 * reads no clock. ⛔ NO TRANSITION, in either of A17's columns: the sky STEPS to its new value (§ 6.2
 * A17, § 6.4). The lobby still draws no wall clock — that element is the floor's room render (§ 4.1).
 */

import { framesNothing } from '../wire/camera.js';

/** A plate's width in scene px. */
export const PLATE_W = 1600;

/** A plate's height in scene px — one storey. */
export const PLATE_H = 1000;

/**
 * The margin each plate stands INSIDE the extent, on both sides (design review r2, card#7343 row 16
 * F2): the frame — `buildingArt()`'s first shape — spans the extent's full width, and a plate exactly
 * as wide as the extent left nothing of it to show, painting the frame fully over (r1's mistake). Each
 * plate stands `PLATE_INSET` in from the extent's left edge, `PLATE_W` wide, so the frame shows as the
 * outer wall around it — the reference's own technique, its shell wider than its floors
 * (`docs/design/floor-preview/floor-preview.html:1094`).
 */
export const PLATE_INSET = 24;

/**
 * ⛔ A PLATE'S TEXT IS DRAWN AT A FIXED SCREEN SIZE, AND THAT SIZE IS THE PAGE'S OWN (operator rulings on
 * card#7343, 2026-09-27, Appendix B row 16: F1 for the name, then r2 for its status line — the summary,
 * the rooms and the cab's word). The lobby exists to pick a floor, so a plate is read before any zoom:
 * at whole-building fit a plate shrinks — a storey's proportions, height-bound, so the more floors the
 * smaller — and text drawn in scene px would shrink with it. So the name and the status line are one
 * label over the plate that the camera MOVES and never SCALES: `LABEL_FONT` is the lobby page's base
 * font size — `1rem`, the root's size, which the page's body text is set in because the page ships no
 * stylesheet that sets another (`Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest` reds if
 * one arrives) — and `labelScale()` is the counter-scale that undoes the camera's zoom on it. The
 * product is the one the page draws: text at `LABEL_FONT`, under the camera's `scale(zoom)`, under the
 * label's own `scale(labelScale(camera))`, is `LABEL_FONT` on the screen at every zoom — at fit, after a
 * wheel, a key or a drag, mid-glide and after a resize. No figure is chosen here: the size is the
 * viewer's own base size.
 *
 * ⚠ § 12's zoom-to-read rule is the FLOOR's, and it does not carry over to a plate's text.
 */
export const LABEL_FONT = '1rem';

/**
 * The scale a plate's label is drawn at inside the camera's transform: the inverse of its zoom, so the
 * text's size on the screen is `LABEL_FONT` whatever the camera does (`LABEL_FONT`'s ruling).
 *
 * @param {{zoom: number}} camera a `wire/camera.js` camera — the one the plates are shown under
 */
export function labelScale(camera) {
    return 1 / camera.zoom;
}

/**
 * The narrowest a plate's label is ever wrapped to, in CSS px: 320, the width WCAG 2.1's Reflow criterion
 * (SC 1.4.10) requires content to read at without scrolling in two dimensions — "a width equivalent to
 * 320 CSS pixels" (w3.org/WAI/WCAG21/Understanding/reflow.html, read 2026-09-29) — so a label wrapped
 * this narrow is still text laid out at a width the web's own reflow rule treats as readable. A figure
 * of the drawing's, carrying no fact.
 */
export const LABEL_MIN_PX = 320;

/**
 * The width a plate's label wraps within, in CSS px on the screen — `lobby/main.js`'s `--label-max` (the
 * seat's r4b ruling on card#7343, refining r3's "the surface's width"): what is VISIBLE of the surface to
 * the right of the plates' on-screen left edge, so at whole-building fit — where a height-bound fit insets
 * the building from the surface's left edge — a label reads to its end without a pan wherever at least
 * `LABEL_MIN_PX` is visible to the right of the plates. Clamped: never wider than the surface, so a pan
 * can always bring the whole label into view (a plate whose left edge is off the surface's left), and
 * never narrower than `LABEL_MIN_PX` (a plate panned towards the surface's right edge), unless the
 * surface itself is narrower still. ⛔ A PLATE STANDS `PLATE_INSET` IN FROM THE EXTENT's LEFT EDGE, NOT
 * ON IT (design review r2, F2: the frame shows as the outer wall around the plates, which stand inside
 * it) — so this reads the plate's OWN left edge, `camera.bounds.x + PLATE_INSET`, and never
 * `camera.bounds.x` alone, which is the FRAME's edge and would over-count the width available by one
 * margin. A camera that frames nothing has no plate, and its labels — none — wrap within the surface.
 *
 * @param {{surface: {width: number}, bounds: {x: number}|null, zoom: number, x: number}} camera a
 *        `wire/camera.js` camera — the one the plates are shown under
 */
export function labelMax(camera) {
    const width = camera.surface.width;

    if (framesNothing(camera)) {
        return width;
    }

    const left = (camera.bounds.x + PLATE_INSET - camera.x) * camera.zoom;

    return Math.min(width, Math.max(LABEL_MIN_PX, width - left));
}

/** The roof and its sign, above the top plate, in scene px — the drawing's, carrying no fact. */
export const ROOF_H = 360;

/** The ground lobby, under the bottom plate, in scene px — the drawing's, carrying no fact. */
export const GROUND_H = 520;

/**
 * The scene for a stack of plates: `{ extent, plates: [{ floor, rect }] }`, `extent` the whole
 * building's rect — the roof, every plate and the ground lobby, `PLATE_INSET` wider on each side than a
 * plate so the frame shows as the outer wall around them (design review r2, F2); `null` when there is no
 * plate to frame — and each plate's `rect` at its `level`, under the roof, standing `PLATE_INSET` in from
 * the extent's left edge — `labelMax()` reads it there, not the extent's own (`camera.bounds.x`).
 *
 * @param {Array<{floor: string, level: number}>} plates `building-model.js`'s `plates()`, in its order
 */
export function buildingScene(plates) {
    return {
        extent: plates.length === 0 ? null : { x: 0, y: 0, w: PLATE_W + PLATE_INSET * 2, h: ROOF_H + PLATE_H * plates.length + GROUND_H },
        plates: plates.map((plate) => ({
            floor: plate.floor,
            rect: { x: PLATE_INSET, y: ROOF_H + plate.level * PLATE_H, w: PLATE_W, h: PLATE_H },
        })),
    };
}

/**
 * The storey's parts, in scene px from a plate's top-left — the drawing's, carrying no fact. The shaft
 * stands at the plate's RIGHT, so a label no wider than its own plate sits over the plain wall — but
 * `labelMax()` is the SURFACE's visible width, not the plate's, so at whole-building fit on three or more
 * floors every label is wider than its plate and routinely crosses the shaft, the cab and the plate below
 * it (design review r1, card#7343 row 16). ⛔ AN OPAQUE BACKING IS REFUSED (design review r2, F1): a
 * plaque tried first, and blanked the drawing under it — arches read as rectangles, the cab's top
 * covered at 7+ floors, ~65% of a storey at ten. What keeps a label legible wherever it lands, without
 * hiding what it lands on, is a HALO on the text itself — `LABEL_HALO` and `LABEL_HALO_RADII` below.
 */
const STOREY = {
    skirting: 836,
    floor: 856,
    slab: 948,
    shaft: { x: 1296, w: 232 },
    door: { x: 1316, y: 420, w: 192, h: 416 },
};

/** The palette — § 10.4's warm and whimsical, in the drawing's own figures. */
const INK = {
    shell: '#e7b98f',
    wall: '#fcf3e4',
    panel: '#f6e5cd',
    skirting: '#c98f63',
    floor: '#e2b07c',
    plank: '#c9925f',
    slab: '#b27b56',
    slabTop: '#f0cfa8',
    shaft: '#f2e2cb',
    guide: '#c9a27e',
    door: '#dbe5ee',
    doorEdge: '#9fb1c4',
    lamp: '#ffcf7d',
    roof: '#c7735a',
    roofTop: '#e08f6f',
    sign: '#3b2f4a',
    signInk: '#ffcf7d',
    leaf: '#7fb77e',
    leafDark: '#5f9a63',
    pot: '#d98b5f',
    glass: '#cfe8ef',
    mat: '#e7866d',
    ground: '#b9d98f',
    path: '#e9d8bf',
};

/**
 * The plate label's halo — the storey's own wall colour (`INK.wall`), stacked as several zero-offset,
 * blurred `text-shadow`s behind the label's text (design review r2, card#7343 row 16 F1, replacing r1's
 * opaque plaque, which blanked the drawing under it): `labelMax()` is the surface's visible width and
 * not the plate's, so at whole-building fit on three or more floors a label is routinely wider than its
 * plate and crosses the shaft, the cab or the plate below it — the halo is the label's own contrast
 * there, ON the glyphs rather than a box behind them, so the arches, the shaft and the cab stay visible
 * through it. ⚠ WHAT IS PINNED AND WHAT IS NOT (impl review r3 #3, design review r3 F-B): this COLOUR
 * clears WCAG 2.1 SC 1.4.3's 4.5:1 against both the link's default blue (#0000EE, ≈8.5:1) and the
 * plate's own body text (black, ≈19.1:1) — `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`
 * computes and holds both. That is a claim about the colour PAIR only: a blurred shadow is not an opaque
 * backing, so what a glyph actually stands on is the halo blended over whatever the drawing puts there,
 * and over the drawing's darker parts (the slab, #b27b56, is ≈2.6:1 against the link's blue) and over the
 * sky behind the building, which a label wider than its plate crosses (card#7343), legibility
 * rests on the STACK's density — `LABEL_HALO_RADII` below — which that test holds as built, and not on
 * any contrast ratio measured over the drawing: none is (there is no renderer on the build host).
 */
export const LABEL_HALO = INK.wall;

/**
 * The halo's stack — one zero-offset `text-shadow` layer of `LABEL_HALO` per entry, blurred by that many
 * CSS px, each radius TWICE (design review r2 F1, r3 F-B). A single blurred layer is translucent — a
 * Gaussian falloff, not a solid fill — so it leaves the glyphs' edges thin with the drawing showing
 * through; two layers of opacity *a* composite to 1 − (1 − *a*)², denser than either, which is the
 * standard way to make `text-shadow` alone read nearly solid at the glyph's edge, and the widening radii
 * carry it out past the stroke. The label's legibility over the drawing rests on this density, which is
 * why it is a named figure `ThePlateNameIsReadAtTheBodyTextSizeTest` reads — the built label must carry
 * every layer, at these radii — rather than a literal inside `plate-row.js`. A figure of the drawing's.
 */
export const LABEL_HALO_RADII = Object.freeze([1, 1, 2, 2, 3, 3, 4, 4]);

/** A shape: an SVG element name, its attributes, and the text it carries (only the roof sign's and the lobby's). */
function shape(el, attrs, text = null) {
    return text === null ? { el, attrs } : { el, attrs, text };
}

/** A potted plant standing on `y` at `x` — the roof garden's and the ground lobby's. */
function plant(x, y, size) {
    return [
        shape('ellipse', { cx: x, cy: y - size * 1.25, rx: size * 0.72, ry: size * 0.9, fill: INK.leaf }),
        shape('ellipse', { cx: x - size * 0.34, cy: y - size * 0.95, rx: size * 0.42, ry: size * 0.55, fill: INK.leafDark }),
        shape('ellipse', { cx: x + size * 0.36, cy: y - size * 1.05, rx: size * 0.4, ry: size * 0.52, fill: INK.leafDark }),
        shape('path', { d: `M ${x - size * 0.5} ${y - size * 0.55} L ${x + size * 0.5} ${y - size * 0.55} L ${x + size * 0.38} ${y} L ${x - size * 0.38} ${y} Z`, fill: INK.pot }),
    ];
}

/**
 * The elevator's column — its shaft and the doors in it — from the left edge of the column of plates it
 * serves: every storey's, the ground lobby's and the cab's are this one column, so none of them can stand
 * off the others (design review r3, card#7343 row 16 F-A: the ground lobby's shaft was placed from the
 * extent's left edge while the storeys' was placed from the plates', and jogged `PLATE_INSET` left once
 * the plates were inset). `storey()` passes its plate's left edge, `ground()` the plates' shared one, and
 * `CAB` is drawn at `shaftAt(0)` — its plate's own left edge, which `cabStyle()` translates it to.
 *
 * @param {number} left the column's left edge, in scene px
 */
function shaftAt(left) {
    const x = left + STOREY.shaft.x;
    const door = left + STOREY.door.x;

    return {
        x,
        w: STOREY.shaft.w,
        mid: x + STOREY.shaft.w / 2,
        door: { x: door, w: STOREY.door.w, mid: door + STOREY.door.w / 2 },
    };
}

/** One storey at a plate's rect: wall, panels, skirting, floorboards, slab, shaft and its doors. */
function storey(rect) {
    const { x, y, w } = rect;
    const shaft = shaftAt(x);
    const shapes = [
        shape('rect', { x, y, width: w, height: STOREY.skirting, fill: INK.wall }),
    ];

    // Arched wall panels between the building's left wall and the shaft — moulding, identical on every storey.
    for (let px = x + 96; px + 220 <= shaft.x - 48; px += 300) {
        shapes.push(shape('path', {
            d: `M ${px} ${y + STOREY.skirting - 60} L ${px} ${y + 380} Q ${px} ${y + 260} ${px + 110} ${y + 260} Q ${px + 220} ${y + 260} ${px + 220} ${y + 380} L ${px + 220} ${y + STOREY.skirting - 60} Z`,
            fill: INK.panel,
        }));
    }

    shapes.push(
        shape('rect', { x, y: y + STOREY.skirting, width: w, height: STOREY.floor - STOREY.skirting, fill: INK.skirting }),
        shape('rect', { x, y: y + STOREY.floor, width: w, height: STOREY.slab - STOREY.floor, fill: INK.floor }),
    );

    for (let px = x + 120; px < x + w; px += 160) {
        shapes.push(shape('rect', { x: px, y: y + STOREY.floor, width: 4, height: STOREY.slab - STOREY.floor, fill: INK.plank }));
    }

    shapes.push(
        shape('rect', { x, y: y + STOREY.slab, width: w, height: PLATE_H - STOREY.slab, fill: INK.slab }),
        shape('rect', { x, y: y + STOREY.slab, width: w, height: 10, fill: INK.slabTop }),
        // The shaft, its guide rail, and this storey's doors with the lamp over them — lit alike on every storey.
        shape('rect', { x: shaft.x, y, width: shaft.w, height: STOREY.skirting, fill: INK.shaft }),
        shape('line', { x1: shaft.mid, y1: y, x2: shaft.mid, y2: y + STOREY.door.y - 24, stroke: INK.guide, 'stroke-width': 6, 'stroke-dasharray': '14 12' }),
        shape('rect', { x: shaft.door.x, y: y + STOREY.door.y, width: shaft.door.w, height: STOREY.door.h, rx: 18, fill: INK.door, stroke: INK.doorEdge, 'stroke-width': 6 }),
        shape('line', { x1: shaft.door.mid, y1: y + STOREY.door.y, x2: shaft.door.mid, y2: y + STOREY.door.y + STOREY.door.h, stroke: INK.doorEdge, 'stroke-width': 5 }),
        shape('circle', { cx: shaft.door.mid, cy: y + STOREY.door.y - 40, r: 14, fill: INK.lamp }),
    );

    return shapes;
}

/** The roof over the building's top: its slab with an overhang, a chimney, the sign and a roof garden. */
function roof(extent) {
    const { x, w } = extent;
    const top = extent.y;
    const eave = top + ROOF_H - 64;

    return [
        shape('rect', { x: x + w - 330, y: eave - 150, width: 120, height: 150, rx: 14, fill: INK.roof }),
        shape('rect', { x: x + w - 346, y: eave - 172, width: 152, height: 30, rx: 12, fill: INK.roofTop }),
        shape('rect', { x: x + 120, y: top + 36, width: 760, height: 200, rx: 36, fill: INK.sign, stroke: INK.signInk, 'stroke-width': 10 }),
        shape('line', { x1: x + 260, y1: top + 236, x2: x + 260, y2: eave, stroke: INK.sign, 'stroke-width': 14 }),
        shape('line', { x1: x + 740, y1: top + 236, x2: x + 740, y2: eave, stroke: INK.sign, 'stroke-width': 14 }),
        // font-size 76 and letter-spacing ≈10.36 are the reference's own sign proportions (44px text on a
        // 440-wide sign, floor-preview.html:1098-1099) scaled to this sign's 760px width — no textLength or
        // lengthAdjust, which distort an unshipped Quicksand's fallback glyphs (design review r1, row 16 F3).
        shape('text', { x: x + 500, y: top + 160, 'text-anchor': 'middle', 'font-family': 'Quicksand, ui-rounded, system-ui, sans-serif', 'font-weight': 700, 'font-size': 76, 'letter-spacing': 10.36, fill: INK.signInk }, 'MEZZANINE'),
        ...plant(x + 1000, eave, 60),
        ...plant(x + 1110, eave, 44),
        shape('rect', { x, y: eave, width: w, height: 64, rx: 20, fill: INK.roof }),
        shape('rect', { x, y: eave, width: w, height: 18, rx: 9, fill: INK.roofTop }),
    ];
}

/**
 * The ground lobby under the bottom plate: its wall, the entrance, the lobby's own name, plants and the
 * ground — spanning the extent, with the elevator's shaft and doors in the plates' column (`shaftAt()`).
 *
 * @param {{x: number, y: number, w: number, h: number}} extent the building's
 * @param {number} column the plates' shared left edge, which the shaft is placed from
 */
function ground(extent, column) {
    const { x, w } = extent;
    const shaft = shaftAt(column);
    const top = extent.y + extent.h - GROUND_H;
    const floor = top + GROUND_H - 80;
    const doors = { x: x + 560, w: 360, h: 300 };

    return [
        shape('rect', { x, y: top, width: w, height: GROUND_H - 80, fill: INK.wall }),
        shape('rect', { x: shaft.x, y: top, width: shaft.w, height: GROUND_H - 80, fill: INK.shaft }),
        shape('rect', { x: shaft.door.x, y: floor - STOREY.door.h, width: shaft.door.w, height: STOREY.door.h, rx: 18, fill: INK.door, stroke: INK.doorEdge, 'stroke-width': 6 }),
        shape('line', { x1: shaft.door.mid, y1: floor - STOREY.door.h, x2: shaft.door.mid, y2: floor, stroke: INK.doorEdge, 'stroke-width': 5 }),
        // The entrance: two glass doors under an arch, and the welcome mat before them.
        shape('path', { d: `M ${doors.x} ${floor} L ${doors.x} ${floor - doors.h + 90} Q ${doors.x + doors.w / 2} ${floor - doors.h - 60} ${doors.x + doors.w} ${floor - doors.h + 90} L ${doors.x + doors.w} ${floor} Z`, fill: INK.glass, stroke: INK.skirting, 'stroke-width': 12 }),
        shape('line', { x1: doors.x + doors.w / 2, y1: floor - doors.h + 20, x2: doors.x + doors.w / 2, y2: floor, stroke: INK.skirting, 'stroke-width': 8 }),
        shape('rect', { x: doors.x - 30, y: floor - 6, width: doors.w + 60, height: 20, rx: 10, fill: INK.mat }),
        // The same defect as the roof sign's (design review r1, row 16 F3), fixed the same way: no
        // textLength or lengthAdjust, and letter-spacing at the roof sign's own ratio to font-size (6/44).
        shape('text', { x: x + 300, y: top + 140, 'text-anchor': 'middle', 'font-family': 'Quicksand, ui-rounded, system-ui, sans-serif', 'font-weight': 700, 'font-size': 72, 'letter-spacing': 9.82, fill: INK.skirting }, 'LOBBY'),
        ...plant(x + 170, floor, 90),
        ...plant(x + 1080, floor, 90),
        shape('rect', { x, y: floor, width: w, height: 80, fill: INK.ground }),
        shape('rect', { x: doors.x - 30, y: floor, width: doors.w + 60, height: 80, fill: INK.path }),
    ];
}

/**
 * The building the plates stand in, as shapes for the page to paint under the plates' labels:
 * `{ box, shapes: [{ el, attrs, text? }] }` in scene px, bottom first — `box` the scene's `extent` —
 * or `null` when the scene has no extent (nothing composed, or no plate), where the list flows and no
 * building is drawn.
 *
 * ⛔ IT READS THE SCENE's RECTS AND NOTHING ELSE (row 16: scenery carrying no fact) — never a plate's
 * key — so it is a function of the stack's height alone.
 *
 * @param {{extent: object|null, plates: Array<{rect: object}>}|null} scene `buildingScene()`'s
 */
export function buildingArt(scene) {
    const extent = scene?.extent ?? null;

    if (extent === null) {
        return null;
    }

    return {
        box: extent,
        shapes: [
            // The extent's full width (design review r1 F4, then r2 F2): the whole-building fit frames
            // the extent exactly, so a frame drawn wider than it would be clipped there — and the plates
            // are the ones inset `PLATE_INSET` inside it now, so this shows as the outer wall around them.
            shape('rect', { x: extent.x, y: extent.y + ROOF_H - 40, width: extent.w, height: extent.h - ROOF_H + 40 - 80, rx: 28, fill: INK.shell }),
            ...scene.plates.flatMap(({ rect }) => storey(rect)),
            ...roof(extent),
            ...ground(extent, scene.plates[0].rect.x),
        ],
    };
}

/** The cab's own column: `shaftAt()` at its plate's left edge, where `cabStyle()` translates it. */
const CAB_SHAFT = shaftAt(0);

/**
 * The cab, in scene px from its plate's top-left: a frame around that storey's doors, a lamp on top and
 * the cable it hangs from, down from its storey's ceiling — the reference's cab, which carries no fact: where it stands is the viewer's
 * ride (`lobby/main.js`'s `cab`, § 4.5: navigation is never state), and the plate's label already says
 * *the elevator is here* in words.
 */
export const CAB = Object.freeze([
    shape('line', { x1: CAB_SHAFT.mid, y1: 0, x2: CAB_SHAFT.mid, y2: STOREY.door.y - 32, stroke: INK.sign, 'stroke-width': 5 }),
    shape('rect', { x: CAB_SHAFT.door.x - 14, y: STOREY.door.y - 32, width: CAB_SHAFT.door.w + 28, height: STOREY.door.h + 46, rx: 26, fill: 'none', stroke: '#f2b84b', 'stroke-width': 14 }),
    shape('rect', { x: CAB_SHAFT.door.mid - 30, y: STOREY.door.y - 52, width: 60, height: 22, rx: 11, fill: '#f2b84b' }),
]);

/**
 * The cab's style: standing at the plate the elevator is at — `building-model.js`'s `elevator().level`,
 * the viewer's own stop — and gliding there over `ms`, the ride's `glide_ms` (none on any other render,
 * and none under `prefers-reduced-motion`, where the ride's glide is `0` and the cab cuts). A CSS
 * transition on the one cab element: it starts nothing through the animation set and writes no log row,
 * because the ride is navigation (§ 4.6's elevator row: no § 6.2 row). `null` where the cab stands at no
 * plate — nothing composed, or no plate.
 *
 * @param {{plates: Array<{rect: object}>}|null} scene `buildingScene()`'s
 * @param {number|null} level the plate the cab stands at
 * @param {number} ms how long the cab may take to get there — `0` cuts
 */
export function cabStyle(scene, level, ms) {
    const plate = level === null ? null : scene?.plates[level] ?? null;

    if (plate === null) {
        return null;
    }

    return {
        transform: `translate(${plate.rect.x}px, ${plate.rect.y}px)`,
        transition: ms > 0 ? `transform ${ms}ms linear` : 'none',
    };
}

/** The drawing surface's height while it draws a building — the drawing's, carrying no fact. */
export const SURFACE_H = '70vh';

/**
 * The sky's paint per § 4.2 phase — the reference's own `SKY` table (`floor-preview.html`), top and bottom
 * of its gradient, and whether the phase has stars. `unset` is the reference's null render (§ 6.5): the
 * sky of a page that has never been live — flat, starless, no time of day at all, never a plausible one.
 * Figures of the drawing's, carrying no fact: the fact is the phase.
 */
export const SKY = Object.freeze({
    night: Object.freeze({ top: '#141c3a', bot: '#25335e', stars: true }),
    dawn: Object.freeze({ top: '#3a3a6e', bot: '#e8927c', stars: false }),
    day: Object.freeze({ top: '#8fc4e8', bot: '#cfe6f2', stars: false }),
    dusk: Object.freeze({ top: '#4a3a6e', bot: '#f0a86a', stars: false }),
    unset: Object.freeze({ top: '#2a2f42', bot: '#39405a', stars: false }),
});

/** The reference's dim treatment: the sky's gradient at this opacity, under `SKY_WASH` … */
export const SKY_OPACITY = 0.45;

/** … a wash of the reference's night ink at `.55`, over the reference drawing's own ground, `SKY_GROUND`. */
export const SKY_WASH = 'rgba(18, 20, 31, 0.55)';

/** The ground the reference paints its sky over (`floor-preview.html`'s drawing surface). */
export const SKY_GROUND = '#1a1724';

/** The night's stars, as points on the surface in percent of its size — the reference's own scatter, 26 of them. */
const STARS = Object.freeze(Array.from({ length: 26 }, (_, i) => Object.freeze({
    x: ((i * 181) % 1000) / 10,
    y: ((i * 97) % 1000) / 10,
    r: 1.2 + (i % 2),
})));

/** A `#rrggbb` at `alpha`, as CSS. */
function rgba(hex, alpha) {
    const n = Number.parseInt(hex.slice(1), 16);

    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

/**
 * The sky behind the building for a phase, as the surface's CSS background: the stars at night, over the
 * wash, over the phase's gradient at `SKY_OPACITY`, over `SKY_GROUND` — `unset`'s when `phase` is `null`
 * (no live feed yet, § 6.5).
 *
 * @param {string|null} phase § 6.2 A17's sky phase — `lobby-screen.js`'s frame `sky`
 */
export function skyBackground(phase) {
    const sky = SKY[phase ?? 'unset'];
    const stars = sky.stars
        ? STARS.map((s) => `radial-gradient(circle at ${s.x}% ${s.y}%, rgba(232, 236, 255, 0.5) ${s.r}px, transparent ${s.r + 0.6}px)`)
        : [];

    return {
        backgroundColor: SKY_GROUND,
        backgroundImage: [
            ...stars,
            `linear-gradient(${SKY_WASH}, ${SKY_WASH})`,
            `linear-gradient(to bottom, ${rgba(sky.top, SKY_OPACITY)}, ${rgba(sky.bot, SKY_OPACITY)})`,
        ].join(', '),
    };
}

/**
 * `#lobby-building`'s style for a scene: the clipping drawing surface the camera looks in when the scene
 * has an extent — the sky of `phase` filling it behind the building (`skyBackground()`) — and nothing — the
 * list flowing in the page — when it has none or there is no scene. Every member is set either way, so a
 * lobby whose building goes away stops clipping and loses its sky.
 *
 * ⛔ `transition` IS ALWAYS `none`: A17's sky steps to its new value in both of its columns (§ 6.2 A17,
 * § 6.4), so no cross-fade between two phases is ever drawn — with motion or without.
 *
 * @param {{extent: object|null}|null} scene `buildingScene()`'s, or `null` where nothing is composed
 * @param {string|null} phase § 6.2 A17's sky phase — `lobby-screen.js`'s frame `sky`
 */
export function surfaceStyle(scene, phase) {
    const drawn = (scene?.extent ?? null) !== null;
    const sky = drawn ? skyBackground(phase) : { backgroundColor: '', backgroundImage: '' };

    return {
        height: drawn ? SURFACE_H : '',
        overflow: drawn ? 'hidden' : '',
        touchAction: drawn ? 'none' : '',
        cursor: drawn ? 'grab' : '',
        ...sky,
        transition: 'none',
    };
}
