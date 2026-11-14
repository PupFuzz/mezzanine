// THE HOUSE THEME's 3-D PIECES — the standing kinds Kenney's Furniture Kit carries no piece for, as part lists the 3-D
// floor builds (`docs/design/FLOOR.md § 10.6` item 1's optional `piece3d`; `docs/design/plan-13033-stage2-3d-floor.md`
// § 3.4; card#13033 stage 2 slice 4). `theme.js` exports `piece3d(kind, inputs)` over this module with its own
// `PALETTE`, so a floor in a theme that exports none is drawn in the house theme's pieces and colours.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// ⛔ PURE, TOTAL, NO I/O, NO THREE — the theme's own rules. A part is the character tree's part vocabulary
// (`resources/characters/creatures-3d.js`): `{ shape, params, at, rotate, scale, colour }`, the shapes `box`,
// `cylinder`, `sphere`, `cone`, `torus` and `lathe`, each centred where `at` puts it (a lathe and a cone from their
// foot). The renderer builds each with `server/public/js/floor3d/part-geometry.js` and merges a piece into one mesh.
//
// ⛔ THE FRAME: world units, the piece's foot centre at the origin, `y` up, its FRONT toward `+z` — the side the 2-D
// tile draws — and its size the tile's: `w` its width and `h` its height in units, the 2-D picture's own (its rect over
// the stage-1 unit), so a piece stands as tall in 3-D as it is drawn on the 2-D floor. Its depth is chosen per kind.
//
// ⛔ A THEME TAKES NO FACT: the inputs are the tile's size and nothing about a seat. The restroom door's leaf and its
// slider's field are not the theme's (FLOOR.md § 6.7 item 2): the renderer draws them at the scene's rects, the leaf's
// motion and the field's state the scene's (`floor3d/room-paint.js`).

/** A part: a shape and its parameters, where it stands, its turn in degrees, its scale and its colour. */
function part(shape, params, at, colour, rotate = [0, 0, 0], scale = [1, 1, 1]) {
    return Object.freeze({ shape, params: Object.freeze(params), at: Object.freeze(at), rotate: Object.freeze(rotate), scale: Object.freeze(scale), colour });
}

/** A box `w × h × d` whose foot centre is at `(x, y, z)`. */
function block(w, h, d, x, y, z, colour) {
    return part('box', { size: [w, h, d] }, [x, y + h / 2, z], colour);
}

/** An upright cylinder of radius `r`, `h` tall, its foot centre at `(x, y, z)`. */
function post(r, h, x, y, z, colour) {
    return part('cylinder', { radius: r, height: h }, [x, y + h / 2, z], colour);
}

const PIECES = Object.freeze({
    // A bottled cooler: a cabinet with a dark recess and a tap, a blue bottle upside down on top.
    'water-cooler': (P, { w, h }) => {
        const d = w * 0.8, body = h * 0.62, r = w * 0.36;

        return [
            block(w * 0.9, body, d, 0, 0, 0, P.body),
            block(w * 0.5, body * 0.28, 0.01, 0, body * 0.38, d / 2 + 0.005, P.recess),
            block(w * 0.1, w * 0.08, w * 0.12, 0, body * 0.62, d / 2 + w * 0.06, P.tap),
            post(r * 0.55, h * 0.04, 0, body, 0, P.cap),
            post(r, h - body - h * 0.06, 0, body + h * 0.04, 0, P.water),
            post(r * 0.6, h * 0.02, 0, h - h * 0.02, 0, P.cap),
        ];
    },
    // Three drawers in a painted cabinet, each with its handle and label.
    'filing-cabinet': (P, { w, h }) => {
        const d = w * 0.85, drawer = (h * 0.92) / 3;
        const parts = [block(w, h, d, 0, 0, 0, P.body)];

        for (let i = 0; i < 3; i++) {
            const y = h * 0.04 + i * drawer;

            parts.push(block(w * 0.86, drawer * 0.86, 0.02, 0, y + drawer * 0.07, d / 2 + 0.01, P.body));
            parts.push(block(w * 0.3, drawer * 0.08, 0.03, 0, y + drawer * 0.55, d / 2 + 0.025, P.handle));
            parts.push(block(w * 0.22, drawer * 0.16, 0.025, 0, y + drawer * 0.7, d / 2 + 0.02, P.label));
        }

        parts.push(block(w * 0.5, h * 0.02, d * 0.6, 0, h, 0, P.papers));

        return parts;
    },
    // A floor copier: a body on dark feet, a lid with its trim, the paper tray out at the front, the key panel on top.
    copier: (P, { w, h }) => {
        const d = w * 0.7, foot = h * 0.05, body = h * 0.8;

        return [
            block(w * 0.9, foot, d * 0.9, 0, 0, 0, P.feet),
            block(w, body, d, 0, foot, 0, P.body),
            block(w * 1.02, h * 0.06, d * 1.02, 0, foot + body - h * 0.06, 0, P.trim),
            block(w, h - foot - body, d, 0, foot + body, 0, P.lid),
            block(w * 0.3, h * 0.03, d * 0.25, -w * 0.3, foot + body + (h - foot - body), -d * 0.3, P.keys),
            block(w * 0.5, h * 0.02, d * 0.35, 0, foot + body * 0.55, d / 2 + d * 0.17, P.paper),
        ];
    },
    // A board on two wooden legs: the frame and the white face, three marker strokes and its sticky notes.
    whiteboard: (P, { w, h }) => {
        const leg = h * 0.42, board = h - leg, t = 0.04;

        return [
            block(0.05, leg + board * 0.9, 0.05, -w * 0.44, 0, 0, P.wood),
            block(0.05, leg + board * 0.9, 0.05, w * 0.44, 0, 0, P.wood),
            block(w * 0.25, 0.04, w * 0.3, -w * 0.44, 0, 0, P.wood),
            block(w * 0.25, 0.04, w * 0.3, w * 0.44, 0, 0, P.wood),
            block(w, board, t, 0, leg, 0.03, P.frame),
            block(w * 0.92, board * 0.88, 0.01, 0, leg + board * 0.06, 0.03 + t / 2 + 0.005, P.board),
            ...P.ink.map((c, i) => block(w * (0.5 - i * 0.1), board * 0.03, 0.005, -w * 0.12, leg + board * (0.7 - i * 0.18), 0.03 + t / 2 + 0.012, c)),
            ...P.notes.map((c, i) => block(w * 0.1, w * 0.1, 0.005, w * (0.22 + (i % 2) * 0.13), leg + board * (0.62 - i * 0.2), 0.03 + t / 2 + 0.012, c)),
        ];
    },
    // A tank on a wooden stand: the water, the sand, two weeds, three fish, the lid.
    aquarium: (P, { w, h }) => {
        const d = w * 0.45, stand = h * 0.45, tank = h * 0.48, lid = h - stand - tank;

        return [
            block(w, stand, d, 0, 0, 0, P.stand),
            block(w * 0.96, tank * 0.12, d * 0.92, 0, stand, 0, P.sand),
            block(w * 0.96, tank * 0.86, d * 0.92, 0, stand + tank * 0.12, 0, P.water),
            part('cone', { radius: w * 0.05, height: tank * 0.6, dir: [0, 1, 0] }, [-w * 0.3, stand + tank * 0.12, 0], P.weed),
            part('cone', { radius: w * 0.04, height: tank * 0.45, dir: [0, 1, 0] }, [w * 0.28, stand + tank * 0.12, -d * 0.1], P.weed),
            ...P.fish.map((c, i) => part('sphere', { r: w * 0.035 }, [w * (-0.1 + i * 0.13), stand + tank * (0.45 + (i % 2) * 0.2), d * 0.12], c, [0, 0, 0], [1.6, 1, 0.7])),
            block(w, lid, d, 0, stand + tank, 0, P.lid),
        ];
    },
    // A slumped bag: a squashed ball with a dimple where it is sat in.
    'bean-bag': (P, { w, h }) => [
        part('sphere', { r: 0.5 }, [0, h * 0.42, 0], P.colours[0], [0, 0, 0], [w, h * 0.85, w * 0.9]),
        part('sphere', { r: 0.5 }, [0, h * 0.75, -w * 0.15], P.colours[0], [0, 0, 0], [w * 0.7, h * 0.55, w * 0.55]),
    ],
    // A globe on its stand: a wooden foot and post, a brass meridian ring, the sea and a land mass.
    globe: (P, { w, h }) => {
        const r = Math.min(w * 0.42, h * 0.3), cy = h - r * 1.05;

        return [
            part('lathe', { points: [[w * 0.05, h * 0.06], [w * 0.3, h * 0.03], [w * 0.32, 0]] }, [0, 0, 0], P.wood),
            post(w * 0.04, cy - h * 0.05, 0, h * 0.05, 0, P.wood),
            part('sphere', { r }, [0, cy, 0], P.sea, [0, 0, 23.5]),
            part('sphere', { r: r * 0.5 }, [r * 0.55, cy + r * 0.25, r * 0.5], P.land, [0, 0, 0], [1, 0.8, 0.5]),
            part('torus', { radius: r * 1.08, tube: r * 0.05 }, [0, cy, 0], P.meridian, [0, 90, 23.5]),
        ];
    },
    // The door into the restroom: its frame and pediment, the sill, the dark recess, its sign plate — the leaf over the
    // recess is the renderer's.
    'restroom-door': (P, { w, h }) => {
        const d = 0.12, jamb = w * 0.12, head = h * 0.1;

        return [
            block(w, 0.03, d * 1.6, 0, 0, d * 0.3, P.sill),
            block(jamb, h - head, d, -(w - jamb) / 2, 0, 0, P.frame),
            block(jamb, h - head, d, (w - jamb) / 2, 0, 0, P.frame),
            block(w * 1.08, head, d * 1.2, 0, h - head, 0, P.pediment),
            block(w - 2 * jamb, h - head, d * 0.4, 0, 0, -d * 0.2, P.recess),
            block(w * 0.28, w * 0.18, 0.01, 0, h * 0.62, d * 0.05 + 0.025, P.plate),
        ];
    },
});

/** The kinds this module draws. */
export const KINDS_3D = Object.freeze(Object.keys(PIECES));

/** `p` turned `deg` degrees about `x` — where a point of a tilted part's own plane stands. */
function tilted([x, y, z], deg) {
    const a = deg * Math.PI / 180;

    return [x, y * Math.cos(a) - z * Math.sin(a), y * Math.sin(a) + z * Math.cos(a)];
}

/** One donut at `c`, lying in its box tipped toward the front: a dough ring, its topping over its top half, sprinkles on one. */
function donut(P, c, k, topping) {
    const R = 2.8 * k, t = 1.3 * k, tip = -60;
    const at = (p) => tilted(p, tip).map((v, i) => v + c[i]);
    const icing = topping === 'glazed' ? P.glaze : topping === 'chocolate' ? P.chocolate : P.icing;
    const parts = [
        part('torus', { radius: R, tube: t }, c, P.dough, [tip, 0, 0]),
        part('torus', { radius: R, tube: t * 0.95 }, at([0, 0, t * 0.3]), icing, [tip, 0, 0]),
    ];

    if (topping === 'sprinkled') {
        [30, 95, 160, 220, 280, 335].forEach((deg, i) => {
            const a = deg * Math.PI / 180;

            parts.push(part('box', { size: [1.4 * k, 0.5 * k, 0.5 * k] }, at([R * Math.cos(a), R * Math.sin(a), t * 1.2]), P.sprinkles[i % P.sprinkles.length], [tip, 0, deg + 60]));
        });
    }

    return parts;
}

/** One cream cup at `(x, y, z)`: a tapered body banded in gold, coffee at its brim, its handle to the right. */
function cup(P, x, y, z, k) {
    return [
        part('lathe', { points: [[2.8 * k, 6.2 * k], [2.5 * k, 3 * k], [2.2 * k, 0.6 * k], [1.6 * k, 0]] }, [x, y, z], P.cup),
        post(2.62 * k, 1.1 * k, x, y + 2.8 * k, z, P.band),
        post(2.6 * k, 0.3 * k, x, y + 5.4 * k, z, P.coffee),
        part('torus', { radius: 1.2 * k, tube: 0.4 * k }, [x + 2.9 * k, y + 3.4 * k, z], P.cup),
    ];
}

/**
 * A SNACK TABLE's DRESSING — what `theme.js`'s `snackTable()` draws on the table, standing on the kit's table: a cloth over
 * the top whose front hangs in scallops, the coffee maker at the left (its foot with the hotplate, its tower, its hood,
 * the carafe of coffee on the plate, its lamp a fixed warm dot), the open donut box (`n` donuts — a glazed, a chocolate,
 * a sprinkled — its lid tipped back) and `n` cups, the first at the back right and the rest in front of the box. The
 * 2-D steam curl is a drawn line and has no body here.
 *
 * Laid out as the 2-D picture lays them, at its scale: `k` is a 2-D picture px at the tall table's 72 px across, so the
 * low set is the same things, smaller; the table's top stands at the 2-D table's `top` (its picture px, `px` units
 * each), and its depth is half its width.
 */
function snackDressing(P, { top, n }, { w, px }) {
    const k = w / 72;
    const T = top * px;
    const d = w / 2;
    const cloth = 0.8 * k;
    const base = T + cloth;
    const parts = [];

    // the cloth: a sheet over the top, its front's hem a band hanging in scallops
    const hem = 3 * k;
    const front = (d + 1.2 * k) / 2 + 0.2 * k;
    const scallops = Math.max(3, Math.round((w - 4 * k) / (8 * k)));
    const sw = (w - 4 * k) / scallops;

    parts.push(block(w + 1.2 * k, cloth, d + 1.2 * k, 0, T, 0, P.cloth));
    parts.push(block(w + 1.2 * k, hem, 0.4 * k, 0, base - hem, front, P.cloth));

    for (let i = 0; i < scallops; i++) {
        parts.push(part('sphere', { r: 0.5 }, [-w / 2 + 2 * k + (i + 0.5) * sw, base - hem, front], P.cloth, [0, 0, 0], [sw, 5 * k, 0.4 * k]));
    }

    // the coffee maker, its left edge 5 px in from the table's
    const mx = -w / 2 + 5 * k, mw = 19 * k, mh = 31 * k, zm = -d / 2 + 7 * k;
    const cx = mx + 6.5 * k, cz = zm + 1.5 * k, plate = base + 3.4 * k;

    parts.push(block(mw + k, 3.4 * k, 11 * k, mx + mw / 2, base, zm, P.maker));
    parts.push(block(7 * k, mh - 11.4 * k, 9 * k, mx + mw - 3.5 * k, plate, zm - k, P.maker));
    parts.push(block(mw, 8 * k, 11 * k, mx + mw / 2, base + mh - 8 * k, zm, P.maker));
    parts.push(part('lathe', { points: [[0, 6 * k], [4.3 * k, 6 * k], [4.5 * k, 3 * k], [3.6 * k, 0]] }, [cx, plate, cz], P.coffee));
    parts.push(part('lathe', { points: [[3.2 * k, 13 * k], [4.2 * k, 9 * k], [4.4 * k, 6 * k]] }, [cx, plate, cz], P.carafe));
    parts.push(block(1.2 * k, 5 * k, 1.2 * k, cx - 5 * k, plate + 4 * k, cz, P.maker));
    parts.push(part('sphere', { r: 1.3 * k }, [mx + mw - 3.5 * k, base + 6 * k, zm + 3.7 * k], P.lamp));

    // the donut box, open: a tray with its lid tipped back from its back edge, the donuts in it
    const bx = -w / 2 + 25 * k, bw = 28 * k, bd = 18 * k, wall = 5 * k, side = 0.8 * k, zb = -k;
    const lid = 16 * k, tip = 20 * Math.PI / 180;

    parts.push(block(bw, side, bd, bx + bw / 2, base, zb, P.box));
    parts.push(block(bw, wall, side, bx + bw / 2, base, zb + bd / 2 - side / 2, P.box));
    parts.push(block(bw, wall, side, bx + bw / 2, base, zb - bd / 2 + side / 2, P.box));
    parts.push(block(side, wall, bd, bx + side / 2, base, zb, P.box));
    parts.push(block(side, wall, bd, bx + bw - side / 2, base, zb, P.box));
    parts.push(part('box', { size: [bw, lid, side] }, [bx + bw / 2, base + wall + (lid / 2) * Math.cos(tip), zb - bd / 2 - (lid / 2) * Math.sin(tip)], P.box, [-20, 0, 0]));

    const pitch = bw / (n + 0.4);

    ['glazed', 'chocolate', 'sprinkled'].slice(0, n).forEach((topping, i) => {
        parts.push(...donut(P, [bx + pitch * (i + 0.7), base + side + 2.4 * k, zb], k, topping));
    });

    // the cups: the first at the back right, the rest along the front
    [[w / 2 - 10 * k, -d / 2 + 6 * k], [w / 2 - 19 * k, d / 2 - 4 * k], [w / 2 - 28 * k, d / 2 - 4 * k]].slice(0, n).forEach(([x, z]) => {
        parts.push(...cup(P, x, base, z, k));
    });

    return { top: T, d, parts };
}

/**
 * A kit kind's DRESSING — what the theme stands on the kit's piece — in `palette`'s colours, or `null` for a kind it does
 * not dress: `top`, the height the kit's piece is fitted to; `d`, the depth it is fitted to; and `parts`, a part list in
 * the piece's frame (above), standing on that top.
 *
 * @param {object} palette the theme's `PALETTE`
 * @param {object} snack the theme's 2-D snack tables, by kind: `top`, the table top's height in picture px, and `n`
 * @param {string} kind a standing kind
 * @param {{w: number, h: number, px: number}} inputs the tile's width and height in world units, and a picture px's
 * @returns {{top: number, d: number, parts: list<object>}|null}
 */
export function dressings3d(palette, snack, kind, inputs) {
    return snack[kind] === undefined ? null : Object.freeze(snackDressing(palette['snack-table'], snack[kind], inputs));
}

/**
 * A standing kind's part list in `palette`'s colours, or `null` for a kind it does not draw.
 *
 * @param {object} palette the theme's `PALETTE`
 * @param {string} kind a standing kind (`../index.js`'s `KINDS`)
 * @param {{w: number, h: number}} inputs the tile's width and height, world units
 * @returns {list<object>|null}
 */
export function pieces3d(palette, kind, { w, h }) {
    const draw = PIECES[kind];

    return draw === undefined ? null : Object.freeze(draw(palette[kind], { w, h }));
}
