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
