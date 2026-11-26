// THE `cottage` THEME's 3-D PIECES — what it stands on the kit's snack tables in 3-D, its TEA TABLE (`docs/design/FLOOR.md
// § 10.6` item 1's optional `dressing3d`; card#13033). `theme.js` exports `dressing3d(kind, inputs)` over this module
// with its own `PALETTE` and `SNACK`. It exports no `piece3d`, so the kinds the kit lacks are the house theme's.
//
// ⛔ PURE, TOTAL, NO I/O, NO THREE, NO COLOUR OF ITS OWN — the theme's rules. The frame is the house theme's
// `pieces-3d.js`'s: world units, the piece's foot centre at the origin, `y` up, its front toward `+z`.

import { block, part, post, tableCloth } from '../parts-3d.js';

/** One cupcake standing at `(x, y, z)`: a liner, the sponge's top, a swirl of `frosting`, a cherry on the top one. */
function cupcake(P, x, y, z, k, frosting, cherry) {
    const lh = 4.4 * k;
    const parts = [
        part('lathe', { points: [[3 * k, lh], [2.3 * k, 0], [0, 0]] }, [x, y, z], P.liner),
        post(3.2 * k, 0.8 * k, x, y + lh - 0.4 * k, z, P.sponge),
        part('cone', { radius: 3.36 * k, height: 4.6 * k, dir: [0, 1, 0] }, [x, y + lh + 0.4 * k, z], frosting),
    ];

    if (cherry) {
        parts.push(part('sphere', { r: 1.3 * k }, [x, y + lh + 5.6 * k, z], P.cherry));
    }

    return parts;
}

/** One teacup on its saucer at `(x, y, z)`: a banded cup, tea at its brim, its handle to the right. */
function teacup(P, x, y, z, k) {
    const s = 0.6 * k;

    return [
        post(4.7 * k, s, x, y, z, P.cup),
        part('lathe', { points: [[3 * k, 5 * k], [2.8 * k, 2.2 * k], [1.6 * k, 0]] }, [x, y + s, z], P.cup),
        post(2.95 * k, 0.9 * k, x, y + s + 2.8 * k, z, P.band),
        post(2.9 * k, 0.2 * k, x, y + s + 4.6 * k, z, P.tea),
        part('torus', { radius: k, tube: 0.4 * k }, [x + 3.4 * k, y + s + 3 * k, z], P.cup),
    ];
}

/**
 * THE TEA TABLE's DRESSING — what `theme.js`'s `teaTable()` draws on the table: a gingham cloth over the top falling
 * straight at the front to a scalloped hem, the round teapot at the back left (its band, lid and knob, its spout to the
 * left and handle to the right), the cake stand in the middle (a plate on a stem with `n − 1` cupcakes before its stem
 * — one on the low table — and a smaller plate over them with one more, its cherry on top) and `n` teacups on their
 * saucers, the first at the back right. The 2-D steam curl is a drawn line and has no body here.
 *
 * Laid out as the 2-D picture lays them, at its scale: `k` is a 2-D picture px at the tall table's 72 px across, the
 * table's top at the 2-D table's `top` (its picture px, `px` units each), its depth half its width.
 */
function teaDressing(P, { top, n }, { w, px }) {
    const k = w / 72;
    const T = top * px;
    const d = w / 2;
    const thick = 0.8 * k;
    const base = T + thick;
    const parts = [];

    // the gingham cloth: its drop falls straight 9 px before its scallops, checked across the top and down the front
    parts.push(...tableCloth({ w, d, top: T, thick, over: 2 * k, drop: 9 * k, span: w, pitch: 7 * k }, P.cloth));

    const front = (d + 2 * k) / 2 + thick / 4;

    for (let x = -w / 2 + 2 * k; x < w / 2 - k; x += 4 * k) {
        parts.push(block(2 * k, 0.1 * k, d + 2 * k, x + k, base, 0, P.check));
        parts.push(block(2 * k, 9 * k, 0.1 * k, x + k, base - 9 * k, front + thick / 4, P.check));
    }

    // the round teapot at the back left
    const tx = -w / 2 + 13 * k, tr = 8 * k, tz = -d / 2 + 9 * k, ty = base + tr * 0.92;

    parts.push(part('sphere', { r: tr }, [tx, ty, tz], P.teapot, [0, 0, 0], [1, 0.92, 1]));
    parts.push(part('torus', { radius: tr * 0.9, tube: 0.7 * k }, [tx, ty - k, tz], P.stand, [90, 0, 0]));
    parts.push(post(tr * 0.5, 1.6 * k, tx, ty + tr * 0.8, tz, P.teapot));
    parts.push(part('sphere', { r: 1.4 * k }, [tx, ty + tr * 0.92 + 1.6 * k, tz], P.stand));
    parts.push(part('tube', { points: [[tx - tr + k, ty - 2 * k, tz], [tx - tr - 2 * k, ty, tz], [tx - tr - 5 * k, ty + 4 * k, tz]], radius: 1.2 * k }, [0, 0, 0], P.teapot));
    parts.push(part('tube', { points: [[tx + tr - k, ty + 4 * k, tz], [tx + tr + 3.5 * k, ty + 1 * k, tz], [tx + tr - k, ty - 3.5 * k, tz]], radius: 0.8 * k }, [0, 0, 0], P.teapot));

    // the cake stand in the middle: a foot, a stem, the plate and its cupcakes, a smaller plate over them with one more
    const sx = -w / 2 + 37 * k, plate = base + 6 * k, upper = plate + 13 * k;

    parts.push(post(5 * k, 0.6 * k, sx, base, 0, P.stand));
    parts.push(post(1.2 * k, plate - base, sx, base, 0, P.stand));
    parts.push(post(13 * k, 0.8 * k, sx, plate, 0, P.stand));
    parts.push(post(0.8 * k, upper - plate, sx, plate, 0, P.stand));
    parts.push(post(7 * k, 0.6 * k, sx, upper, 0, P.stand));

    const lower = n === 3 ? [-7, 0] : [-4.5];

    lower.forEach((dx, i) => {
        parts.push(...cupcake(P, sx + dx * k, plate + 0.8 * k, 6 * k, k, P.frosting[i % P.frosting.length], false));
    });
    parts.push(...cupcake(P, sx, upper + 0.6 * k, 0, k, P.frosting[(n - 1) % P.frosting.length], true));

    // the teacups: the first at the back right, the rest in front
    [[w / 2 - 10 * k, -d / 2 + 6 * k], [w / 2 - 19 * k, 10 * k], [w / 2 - 11 * k, d / 2 - 6 * k]].slice(0, n).forEach(([x, z]) => {
        parts.push(...teacup(P, x, base, z, k));
    });

    return { top: T, d, parts };
}

/**
 * A kit kind's dressing in `palette`'s colours, or `null` for a kind this theme does not dress — the house theme's
 * `dressings3d()`'s contract.
 *
 * @param {object} palette the theme's `PALETTE`
 * @param {object} snack the theme's 2-D snack tables, by kind: `top`, the table top's height in picture px, and `n`
 * @param {string} kind a standing kind
 * @param {{w: number, h: number, px: number}} inputs the tile's width and height in world units, and a picture px's
 * @returns {{top: number, d: number, parts: list<object>}|null}
 */
export function dressings3d(palette, snack, kind, inputs) {
    return snack[kind] === undefined ? null : Object.freeze(teaDressing(palette['snack-table'], snack[kind], inputs));
}
