// THE THEMES' SHARED 3-D PART VOCABULARY — the builders each theme's `pieces-3d.js` writes its part lists with
// (`docs/design/FLOOR.md § 10.6` item 1's `piece3d` and `dressing3d`; card#13033), hoisted at its second caller, the
// `cottage` theme's tea table. A part is the character tree's vocabulary (`resources/characters/creatures-3d.js`):
// `{ shape, params, at, rotate, scale, colour }`, which `server/public/js/floor3d/part-geometry.js` builds.
//
// ⛔ PURE, NO I/O, NO COLOUR OF ITS OWN: every colour is the caller's, from its theme's `PALETTE`.

/** A part: a shape and its parameters, where it stands, its turn in degrees, its scale and its colour. */
export function part(shape, params, at, colour, rotate = [0, 0, 0], scale = [1, 1, 1]) {
    return Object.freeze({ shape, params: Object.freeze(params), at: Object.freeze(at), rotate: Object.freeze(rotate), scale: Object.freeze(scale), colour });
}

/** A box `w × h × d` whose foot centre is at `(x, y, z)`. */
export function block(w, h, d, x, y, z, colour) {
    return part('box', { size: [w, h, d] }, [x, y + h / 2, z], colour);
}

/** An upright cylinder of radius `r`, `h` tall, its foot centre at `(x, y, z)`. */
export function post(r, h, x, y, z, colour) {
    return part('cylinder', { radius: r, height: h }, [x, y + h / 2, z], colour);
}

/** `p` turned `deg` degrees about `x` — where a point of a tilted part's own plane stands. */
export function tilted([x, y, z], deg) {
    const a = deg * Math.PI / 180;

    return [x, y * Math.cos(a) - z * Math.sin(a), y * Math.sin(a) + z * Math.cos(a)];
}

/**
 * A CLOTH OVER A TABLE's TOP, as both themes' snack tables are drawn: a sheet `thick` deep lying on the top at height
 * `top`, `w × d` with an overhang of `over` all round, and its front falling `drop` below the sheet to a hem of
 * scallops, one about every `pitch` across the table's `span`. Every part in `colour`.
 */
export function tableCloth({ w, d, top, thick, over, drop, span, pitch }, colour) {
    const front = (d + over) / 2 + thick / 4;
    const scallops = Math.max(3, Math.round(span / pitch));
    const sw = span / scallops;
    const hem = top + thick - drop;
    const parts = [
        block(w + over, thick, d + over, 0, top, 0, colour),
        block(w + over, drop, thick / 2, 0, hem, front, colour),
    ];

    for (let i = 0; i < scallops; i++) {
        parts.push(part('sphere', { r: 0.5 }, [-span / 2 + (i + 0.5) * sw, hem, front], colour, [0, 0, 0], [sw, pitch * 0.6, thick / 2]));
    }

    return parts;
}
