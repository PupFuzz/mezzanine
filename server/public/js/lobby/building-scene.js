/**
 * THE LOBBY's ROOM — `docs/design/FLOOR.md` § 4.1's *The room, the directory and the elevator* (card#12113, closing
 * card#11800): one room seen from inside, standing in it and facing the elevator wall, in a one-point perspective, of
 * § 12's *Lobby room drawing* size. Where every part stands in the room's own scene px, so that row 15's camera
 * (`../wire/camera.js`) can frame the room and the page can stand the directory on its board, and what the room is drawn
 * with, as shapes the page paints deciding nothing (`lobby/building-paint.js`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ SCENERY CARRYING NO FACT. Nothing here reads a plaque, a floor's key, name or count, or a seat: `lobbyScene()` and
 * `roomArt()` take no argument at all, so two buildings of any two shapes draw ONE room
 * (`Tests\Feature\Lobby\TheLobbyIsADrawnRoomTest`), and the room's only words are the sign's. The directory's figures
 * are HTML in the camera's layer over the board's rect (`lobby/directory.js`), never painted into the board.
 *
 * ⛔ NO CAB-POSITION INDICATOR (§ 4.1): no cue, no *at:* sign, no dial reading a floor. The sign over the door reads the
 * building's name; the elevator's leaves move only with the viewer's own ride, which the page draws from the ride frame
 * (`building-paint.js`), never from anything here.
 *
 * ⭐ THE ONE WINDOW CARRIES A17's SKY (§ 4.1; the operator's ruling of 2026-09-30 on card#7343, answer A, moved to the
 * room's one window by card#12113). `windowArt()` fills the left wall's window with the phase's sky at full strength —
 * its gradient, stars and a moon at night, the sun by day, the city's roofline lit by the phase — from ONE table,
 * `floor/floor-layout.js`'s `SKY_PAINT`, which the floor's windows read too. ⛔ THE PHASE IS NOT DECIDED HERE: it is
 * § 6.2 A17's value, set by the floor's own driver on a delivered `feed.heartbeat` (`lobby-screen.js`'s frame `sky`).
 * ⛔ NO TRANSITION: the sky STEPS to its new value in both of A17's columns (§ 6.2 A17, § 6.4). The drawing surface
 * around the room is the sheet's own dark ground, with no sky on it (`surfaceStyle()`). The lobby draws no wall clock —
 * that element is the floor's (§ 4.1).
 *
 * ⛔ THE NUMBERS ARE THE DRAWING's (§ 10.4's last bullet): the room's figures carry no fact. They are the reference
 * drawing's (`docs/design/lobby-preview/Lobby2G.dc.html`), kept at the size the operator accepted; the room's scene
 * size and its ceiling grid are § 12's *Lobby room drawing* row.
 */

import { skyPaint } from '../floor/floor-layout.js';

/** § 12's *Lobby room drawing*: the room's size in scene px. */
export const ROOM = Object.freeze({ w: 1440, h: 772 });

/** § 12's *Lobby room drawing*: the ceiling's recessed panels, across and in depth. */
export const CEILING_GRID = Object.freeze({ across: 4, deep: 3 });

/**
 * The room's one-point perspective, in the drawing's own figures: the vanishing point, and the depth `Z` of the far
 * wall — a point at depth `Z` is drawn at the scale `1 / (1 + Z)` about the vanishing point, so the far wall stands
 * at `1 / (1 + FAR_Z)` of the scene. A point of the near plane is given in scene px (`X` from the vanishing point's
 * line across, `Y` down from the scene's top).
 */
const VANISH = Object.freeze({ x: 720, y: 492.5 });
const FAR_Z = 0.6126;

/** A point of the room: `x`, `y` on the scene's near plane, carried back to depth `z`. */
function at(x, y, z) {
    const s = 1 / (1 + z);

    return [+(VANISH.x + (x - VANISH.x) * s).toFixed(2), +(VANISH.y + (y - VANISH.y) * s).toFixed(2)];
}

/** The far wall — the elevator wall — in scene px. */
const [WALL_L, WALL_T] = at(0, 0, FAR_Z);
const [WALL_R, WALL_B] = at(ROOM.w, ROOM.h, FAR_Z);

/** Every rect the page stands something at, in scene px — the drawing's, carrying no fact. */
const PARTS = Object.freeze({
    // The directory board on the far wall, left of the elevator: its wooden face.
    board: Object.freeze({ x: 292, y: 198, w: 520, h: 340 }),
    // The directory's box — the board's inner rectangle, where the plaques stand (§ 4.1).
    directory: Object.freeze({ x: 308, y: 214, w: 488, h: 308 }),
    // The building's readings, two brass-edged panels under the board (§ 4.1).
    readings: Object.freeze({ x: 292, y: 548, w: 520, h: 78 }),
    // The elevator, right of the wall's centre: its sign, frame, call plate and mat — what the keyboard's focus on it
    // brings the camera to.
    elevator: Object.freeze({ x: 816, y: 386, w: 193, h: 294 }),
    // The elevator's surround — its frame and its call plate — which is the control's hit area.
    surround: Object.freeze({ x: 830, y: 425, w: 179, h: 246 }),
    // The doorway the leaves close: the cab is drawn behind them, clipped to it.
    doorway: Object.freeze({ x: 841, y: 444, w: 121, h: 221 }),
});

/**
 * The room's scene: `{ extent, board, directory, readings, elevator, surround, doorway }`, each a rect in scene px —
 * `extent` the whole room, which the camera frames (fit on entry and on *Fit the lobby*). The same scene for every
 * building: it takes no argument (the header's ⛔).
 */
export function lobbyScene() {
    return Object.freeze({ extent: Object.freeze({ x: 0, y: 0, w: ROOM.w, h: ROOM.h }), ...PARTS });
}

/**
 * The palette — § 10.4's warm and whimsical, the inks the cross-section drew with, so nothing is re-coloured, plus the
 * board's two wood tones (`honey`, `wood`) and a shading ink (`shade`), which are the drawing's own. ⛔ `door`,
 * `doorEdge` and `mat` are HELD EQUAL to the sheet's `--door`, `--door-edge` and `--lobby-mat`
 * (`Tests\Feature\ThePageChromeIsOneLinkedStylesheetTest`), which the elevator's leaves and the lobby view read.
 */
export const INK = Object.freeze({
    shell: '#e7b98f',
    wall: '#fcf3e4',
    skirting: '#c98f63',
    floor: '#e2b07c',
    plank: '#c9925f',
    slab: '#b27b56',
    door: '#dbe5ee',
    doorEdge: '#9fb1c4',
    lamp: '#ffcf7d',
    sign: '#3b2f4a',
    signInk: '#ffcf7d',
    leaf: '#7fb77e',
    leafDark: '#5f9a63',
    pot: '#d98b5f',
    mat: '#e7866d',
    honey: '#c9a873',
    wood: '#8a6f5a',
    shade: '#3b2f2a',
});

/**
 * A shape: an SVG element name, its attributes, and the text it carries (only the sign's) — or, for a paint server, the
 * shapes it holds.
 */
function shape(el, attrs, text = null) {
    return text === null ? { el, attrs } : { el, attrs, text };
}

/** A shape that holds other shapes — `<defs>`, a gradient and its stops. */
function parent(el, attrs, children) {
    return { el, attrs, children };
}

/** Points as an SVG `points` list. */
const pts = (...ps) => ps.map((p) => p.join(',')).join(' ');

/** A shading overlay's gradient, clear at `from` and the shading ink at `opacity` at the other end. */
function shading(id, [x1, y1, x2, y2], opacity) {
    return parent('linearGradient', { id, x1, y1, x2, y2 }, [
        shape('stop', { offset: 0, 'stop-color': INK.shade, 'stop-opacity': 0 }),
        shape('stop', { offset: 1, 'stop-color': INK.shade, 'stop-opacity': opacity }),
    ]);
}

/**
 * The ceiling's recessed panels (the operator's request of 2026-10-20, *evenly spaced rectangular lights in a grid
 * pattern*): `CEILING_GRID.across` bays of equal width across the near edge, a panel `PANEL_SHARE` of its bay centred in
 * each, and `CEILING_GRID.deep` equal bays in depth to the far wall, the panel the same share of its bay — each carried
 * to the vanishing point, so panels and gaps foreshorten alike.
 */
const PANEL_SHARE = 200 / 360;

function ceilingPanels() {
    const bayX = ROOM.w / CEILING_GRID.across;
    const bayZ = FAR_Z / CEILING_GRID.deep;
    const out = [];

    for (let row = 0; row < CEILING_GRID.deep; row += 1) {
        const zc = (row + 0.5) * bayZ;
        const [z0, z1] = [zc - (bayZ * PANEL_SHARE) / 2, zc + (bayZ * PANEL_SHARE) / 2];

        for (let col = 0; col < CEILING_GRID.across; col += 1) {
            const xc = (col + 0.5) * bayX;
            const [x0, x1] = [xc - (bayX * PANEL_SHARE) / 2, xc + (bayX * PANEL_SHARE) / 2];
            const quad = pts(at(x0, 0, z0), at(x1, 0, z0), at(x1, 0, z1), at(x0, 0, z1));

            out.push(
                shape('polygon', { points: quad, fill: INK.lamp, opacity: 0.25, stroke: INK.lamp, 'stroke-width': 8, 'stroke-linejoin': 'round', class: 'lobby-ceiling-glow' }),
                shape('polygon', { points: quad, fill: INK.wall, stroke: INK.shell, 'stroke-width': 1.5, 'stroke-linejoin': 'round', class: 'lobby-ceiling-panel' }),
            );
        }
    }

    return out;
}

/** A potted plant standing on `y` at `x`. */
function plant(x, y, size) {
    return [
        shape('path', { d: `M ${x - size * 0.9} ${y - size * 1.05} L ${x + size * 0.9} ${y - size * 1.05} L ${x + size * 0.7} ${y} L ${x - size * 0.7} ${y} Z`, fill: INK.pot }),
        shape('ellipse', { cx: x, cy: y - size * 2.2, rx: size, ry: size * 1.2, fill: INK.leaf }),
        shape('ellipse', { cx: x - size * 0.65, cy: y - size * 1.78, rx: size * 0.55, ry: size * 0.75, fill: INK.leafDark }),
        shape('ellipse', { cx: x + size * 0.65, cy: y - size * 1.82, rx: size * 0.5, ry: size * 0.7, fill: INK.leafDark }),
    ];
}

/**
 * The room as shapes for the page to paint, bottom first, in scene px — `{ box, shapes }`, `box` the room's extent:
 * the ceiling with its panel grid, the floor in boards, the two side walls, the far wall, the rail along the three
 * walls and the skirting along their foot, the room's four edges as faint lines, the bench on the left wall, the
 * picture on the right one, the plant in the back right corner, the directory board's wood and fixings, and the
 * elevator's scenery — its frame, the dark recess, the lamp arc over the doors, the sill, the mat, the call plate and
 * the brass sign. The elevator's leaves and the cab behind them are the page's (`building-paint.js`), because they
 * move with the ride; the window is `windowArt()`'s, because it carries A17's sky.
 */
export function roomArt() {
    const { w, h } = ROOM;
    const near = { tl: [0, 0], tr: [w, 0], bl: [0, h], br: [w, h] };
    const far = { tl: [WALL_L, WALL_T], tr: [WALL_R, WALL_T], bl: [WALL_L, WALL_B], br: [WALL_R, WALL_B] };
    const ceiling = pts(near.tl, near.tr, far.tr, far.tl);
    const floor = pts(near.bl, near.br, far.br, far.bl);
    const left = pts(near.tl, far.tl, far.bl, near.bl);
    const right = pts(near.tr, far.tr, far.br, near.br);
    const shapes = [
        parent('defs', {}, [
            shading('lobby-shade-ceiling', [0, 0, 0, 1], 0.2),
            shading('lobby-shade-floor', [0, 1, 0, 0], 0.3),
            shading('lobby-shade-left', [0, 0, 1, 0], 0.18),
            shading('lobby-shade-right', [1, 0, 0, 0], 0.18),
            parent('radialGradient', { id: 'lobby-wall-glow', cx: 0.5, cy: 0, r: 0.8 }, [
                shape('stop', { offset: 0, 'stop-color': INK.lamp, 'stop-opacity': 0.38 }),
                shape('stop', { offset: 1, 'stop-color': INK.lamp, 'stop-opacity': 0 }),
            ]),
        ]),
        shape('polygon', { points: ceiling, fill: INK.wall }),
        shape('polygon', { points: ceiling, fill: 'url(#lobby-shade-ceiling)' }),
        shape('polygon', { points: floor, fill: INK.floor }),
    ];

    // The floor's boards, converging on the far wall, and the seams across them at four depths.
    for (let x = w / 8; x < w; x += w / 8) {
        const [a, b] = [[x, h], at(x, h, FAR_Z)];

        shapes.push(shape('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], stroke: INK.plank, 'stroke-width': 2, 'stroke-opacity': 0.75 }));
    }

    for (const z of [0.05, 0.12, 0.2, 0.26]) {
        const [a, b] = [at(0, h, z), at(w, h, z)];

        shapes.push(shape('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], stroke: INK.plank, 'stroke-width': 1.5, 'stroke-opacity': 0.5 }));
    }

    shapes.push(
        shape('polygon', { points: floor, fill: 'url(#lobby-shade-floor)' }),
        shape('polygon', { points: left, fill: INK.wall }),
        shape('polygon', { points: left, fill: 'url(#lobby-shade-left)' }),
        shape('polygon', { points: right, fill: INK.wall }),
        shape('polygon', { points: right, fill: 'url(#lobby-shade-right)' }),
        shape('rect', { x: WALL_L, y: WALL_T, width: WALL_R - WALL_L, height: WALL_B - WALL_T, fill: INK.wall }),
        shape('rect', { x: WALL_L, y: WALL_T, width: WALL_R - WALL_L, height: WALL_B - WALL_T, fill: INK.shade, 'fill-opacity': 0.07 }),
        shape('rect', { x: WALL_L, y: WALL_T, width: WALL_R - WALL_L, height: 260, fill: 'url(#lobby-wall-glow)' }),
    );

    // The rail along the three walls, and the skirting along their foot.
    const railY = 610;
    const [railFar] = [at(0, railY, FAR_Z)];

    shapes.push(
        shape('line', { x1: 0, y1: railY, x2: railFar[0], y2: railFar[1], stroke: INK.shell, 'stroke-width': 3 }),
        shape('line', { x1: w, y1: railY, x2: w - railFar[0], y2: railFar[1], stroke: INK.shell, 'stroke-width': 3 }),
        shape('line', { x1: WALL_L, y1: railFar[1], x2: WALL_R, y2: railFar[1], stroke: INK.shell, 'stroke-width': 2.5 }),
    );

    const skirtTop = at(0, h - 21.6, FAR_Z)[1];

    shapes.push(
        shape('rect', { x: WALL_L, y: skirtTop, width: WALL_R - WALL_L, height: WALL_B - skirtTop, fill: INK.skirting }),
        shape('polygon', { points: pts([0, h - 21.6], [WALL_L, skirtTop], far.bl, near.bl), fill: INK.skirting }),
        shape('polygon', { points: pts([w, h - 21.6], [WALL_R, skirtTop], far.br, near.br), fill: INK.skirting }),
    );

    // The room's four edges, as faint lines.
    for (const [a, b] of [[near.tl, far.tl], [near.tr, far.tr], [near.bl, far.bl], [near.br, far.br]]) {
        shapes.push(shape('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], stroke: INK.shade, 'stroke-opacity': 0.28, 'stroke-width': 1.5 }));
    }

    shapes.push(...ceilingPanels());

    // A picture on the right wall — a hill under a sun — a plant in the back right corner, a bench on the left wall.
    shapes.push(
        shape('polygon', { points: pts([1253.5, 353], [1346.1, 328.6], [1346.1, 485.2], [1253.5, 486.3]), fill: INK.slab }),
        shape('polygon', { points: pts([1262, 362], [1337, 342], [1337, 475], [1262, 476]), fill: INK.shell }),
        shape('polygon', { points: pts([1262, 445], [1337, 440], [1337, 475], [1262, 476]), fill: INK.leafDark }),
        shape('circle', { cx: 1312, cy: 382, r: 11, fill: INK.lamp }),
        ...plant(1152, 679, 40),
        shape('polygon', { points: pts([60, 700], [230, 660], [230, 676], [60, 718]), fill: INK.slab }),
        shape('polygon', { points: pts([60, 718], [230, 676], [230, 690], [60, 732]), fill: INK.wood }),
        shape('rect', { x: 66, y: 732, width: 10, height: 34, fill: INK.wood }),
        shape('rect', { x: 218, y: 690, width: 8, height: 28, fill: INK.wood }),
    );

    // The directory board: a honey face, a darker inset and four brass fixings. Its words are the directory's HTML.
    const { board } = PARTS;

    shapes.push(
        shape('rect', { x: board.x, y: board.y, width: board.w, height: board.h, rx: 18, fill: INK.honey }),
        shape('rect', { x: board.x + 8, y: board.y + 8, width: board.w - 16, height: board.h - 16, rx: 14, fill: INK.wood }),
        ...[[20, 20], [board.w - 20, 20], [20, board.h - 20], [board.w - 20, board.h - 20]]
            .map(([dx, dy]) => shape('circle', { cx: board.x + dx, cy: board.y + dy, r: 3.5, fill: INK.lamp })),
    );

    // The elevator's scenery: its frame round a dark recess, the lamp arc over the doors, the sill, the mat before them
    // and a two-button call plate beside the frame — none lit — and over the door the brass sign, the building's name.
    const { doorway: d } = PARTS;

    shapes.push(
        shape('rect', { x: 830, y: 425, width: 143, height: 246, rx: 6, fill: INK.honey, stroke: INK.wood, 'stroke-width': 1.5 }),
        shape('rect', { x: d.x - 1, y: d.y - 1, width: d.w + 2, height: d.h + 2, fill: INK.sign }),
        shape('path', { d: `M ${d.x + d.w / 2 - 22} ${d.y - 4} A 24 24 0 0 1 ${d.x + d.w / 2 + 22} ${d.y - 4}`, fill: 'none', stroke: INK.wood, 'stroke-width': 1.5 }),
        shape('circle', { cx: d.x + d.w / 2, cy: d.y - 14, r: 3, fill: INK.lamp }),
        shape('rect', { x: 826, y: d.y + d.h - 1, width: 151, height: 5, fill: INK.doorEdge }),
        shape('rect', { x: 816, y: 670, width: 172, height: 10, rx: 4, fill: INK.mat }),
        shape('rect', { x: 987, y: 520, width: 22, height: 58, rx: 4, fill: INK.wall, stroke: INK.wood, 'stroke-width': 1 }),
        shape('circle', { cx: 998, cy: 537, r: 6, fill: INK.door, stroke: INK.wood, 'stroke-width': 1 }),
        shape('circle', { cx: 998, cy: 561, r: 6, fill: INK.door, stroke: INK.wood, 'stroke-width': 1 }),
        shape('rect', { x: 830, y: 386, width: 143, height: 30, rx: 6, fill: INK.sign }),
        shape('text', { x: 901.5, y: 406, 'text-anchor': 'middle', 'font-family': 'Quicksand, ui-rounded, system-ui, sans-serif', 'font-weight': 700, 'font-size': 13, 'letter-spacing': 2.5, fill: INK.signInk }, 'MEZZANINE'),
    );

    return { box: lobbyScene().extent, shapes };
}

/** The window's frame and the city's inks — the reference window's own (`floor-preview.html`'s `floorBody()`). */
const WINDOW_INK = Object.freeze({
    frame: '#4a5670',
    star: '#e8ecff',
    moon: '#fff3cf',
    sun: '#ffe9a3',
    lit: '#ffcf7d',
    cityFlat: '#454b63',
    cityNight: '#1b2547',
    cityDay: '#5a7086',
});

/** The one gradient the window's glass is filled with — the phase's `top` to `bot`. */
export const WINDOW_SKY_ID = 'lobby-window-sky';

/** The window on the left wall: its frame's and its glass's corners, near edge first, in scene px. */
const WINDOW = Object.freeze({
    frame: Object.freeze([[84, 236], [206, 284], [206, 492], [84, 488]]),
    glass: Object.freeze([[90, 247], [200, 291], [200, 484], [90, 480]]),
});

/** The point `u` across and `v` down the glass, `0..1` each, on the wall's perspective. */
function onGlass(u, v) {
    const [a, b, c, dd] = WINDOW.glass;
    const top = [a[0] + (b[0] - a[0]) * u, a[1] + (b[1] - a[1]) * u];
    const bot = [dd[0] + (c[0] - dd[0]) * u, dd[1] + (c[1] - dd[1]) * u];

    return [+(top[0] + (bot[0] - top[0]) * v).toFixed(2), +(top[1] + (bot[1] - top[1]) * v).toFixed(2)];
}

/**
 * THE ROOM's WINDOW, CARRYING THE TIME OF DAY: the left wall's one window filled with A17's sky at FULL strength — the
 * phase's gradient `top` to `bot`; seven stars at night; the city's roofline, lit at the phase's `city_lit`, the flat
 * unlit ink when `unset`; the moon at night and the sun at the phase's `sun_y`; the frame and its mullions. `unset` (no
 * live feed yet, § 6.5) is the reference's flat treatment: no star, sun, moon or lit window — never a plausible time of
 * day. It reads the phase alone (A17's value, `lobby-screen.js`'s frame `sky`).
 *
 * @param {string|null} phase § 6.2 A17's sky phase
 * @returns {{shapes: Array<object>}}
 */
export function windowArt(phase) {
    const sky = skyPaint(phase);
    const glass = pts(...WINDOW.glass);
    const shapes = [
        parent('defs', {}, [
            parent('linearGradient', { id: WINDOW_SKY_ID, x1: 0, y1: 0, x2: 0, y2: 1 }, [
                shape('stop', { offset: 0, 'stop-color': sky.top }),
                shape('stop', { offset: 1, 'stop-color': sky.bot }),
            ]),
        ]),
        shape('polygon', { points: pts(...WINDOW.frame), fill: WINDOW_INK.frame }),
        shape('polygon', { points: glass, fill: `url(#${WINDOW_SKY_ID})` }),
    ];

    if (sky.stars) {
        for (let i = 0; i < 7; i += 1) {
            const [cx, cy] = onGlass(0.12 + ((i * 0.37) % 0.76), 0.08 + ((i * 0.23) % 0.45));

            shapes.push(shape('circle', { cx, cy, r: 1.5 + (i % 2), fill: WINDOW_INK.star, opacity: 0.8 }));
        }
    }

    if (sky.moon) {
        const [cx, cy] = onGlass(0.66, 0.22);

        shapes.push(
            shape('circle', { cx, cy, r: 12, fill: WINDOW_INK.moon, opacity: 0.95 }),
            shape('circle', { cx: cx - 5, cy: cy - 4, r: 10, fill: sky.top }),
        );
    }

    if (sky.sun) {
        const [cx, cy] = onGlass(0.36, 0.1 + sky.sun_y * 0.5);

        shapes.push(
            shape('circle', { cx, cy, r: 17, fill: WINDOW_INK.sun }),
            shape('circle', { cx, cy, r: 24, fill: WINDOW_INK.sun, opacity: 0.25 }),
        );
    }

    // The city's roofline across the glass's foot: six blocks, their heights the drawing's.
    const roofs = [0.62, 0.55, 0.66, 0.5, 0.6, 0.57];
    const city = [onGlass(0, 1)];

    roofs.forEach((v, i) => {
        city.push(onGlass(i / roofs.length, v), onGlass((i + 1) / roofs.length, v));
    });
    city.push(onGlass(1, 1));

    shapes.push(shape('polygon', {
        points: pts(...city),
        fill: sky.flat ? WINDOW_INK.cityFlat : sky.city_lit > 0 ? WINDOW_INK.cityNight : WINDOW_INK.cityDay,
        opacity: sky.city_lit > 0 ? 1 : 0.85,
    }));

    if (sky.city_lit > 0) {
        roofs.forEach((v, i) => {
            const [x, y] = onGlass((i + 0.35) / roofs.length, v + 0.06);

            shapes.push(shape('rect', { x, y, width: 4, height: 4, fill: WINDOW_INK.lit, opacity: sky.city_lit }));
        });
    }

    const [mt, mb] = [onGlass(0.5, 0), onGlass(0.5, 1)];
    const [ml, mr] = [onGlass(0, 0.5), onGlass(1, 0.5)];

    shapes.push(
        shape('line', { x1: mt[0], y1: mt[1], x2: mb[0], y2: mb[1], stroke: WINDOW_INK.frame, 'stroke-width': 3 }),
        shape('line', { x1: ml[0], y1: ml[1], x2: mr[0], y2: mr[1], stroke: WINDOW_INK.frame, 'stroke-width': 3 }),
    );

    return { shapes };
}

/**
 * `#lobby-building`'s style: the clipping drawing surface the camera looks in while the room is drawn — the room's own
 * shape, the sheet's dark ground around it where the camera leaves any, and no sky on it — and nothing while it is not
 * (no snapshot yet), so the page's placeholders flow in the page and every event over them is the browser's
 * (`wire/camera-gestures.js`, `wire/camera-keys.js`). Every member is set either way, so a lobby whose room goes away
 * stops clipping.
 *
 * @param {object|null} scene `lobbyScene()`, or `null` where the room is not drawn
 */
export function surfaceStyle(scene) {
    const drawn = scene !== null;

    return {
        aspectRatio: drawn ? `${ROOM.w} / ${ROOM.h}` : '',
        maxHeight: drawn ? '80vh' : '',
        overflow: drawn ? 'hidden' : '',
        position: drawn ? 'relative' : '',
        touchAction: drawn ? 'none' : '',
        cursor: drawn ? 'grab' : '',
        backgroundColor: drawn ? 'var(--ground-deep)' : '',
    };
}
