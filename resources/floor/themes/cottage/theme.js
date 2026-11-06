// THE SECOND THEME, `cottage` — `docs/design/FLOOR.md § 10.6` (card#12845): a garden cottage, for a floor that wants
// to look different from the house theme's studio (the operator, 2026-11-04: "create another floor design theme in
// both layout and style so that the sola floor and aimla floor look different"; and on card#11046, "I do want each
// floor to have a different design"). A floor's layout entry selects it by naming it (§ 10.6 item 5). It DRAWS, as
// code, every piece of a floor that carries no fact, and the same pieces the house theme draws: the band's wall, the
// window, elevator and clock surrounds, each grid's plane (its floor, its light, its accent region, its walls and the
// landing), the standing scenery, and a desk's furniture set (the chair's back, the desk's back, the monitor's frame,
// the desk props, the side seating — a slatted garden bench a row — and its seats).
//
// What it looks like, piece by piece, is § 10.6's *The second theme, `cottage`* table: powder-blue walls with a faint
// sprig wallpaper over white beadboard; white windows with cornflower shutters, a striped awning and a window box of
// flowers; a navy elevator trimmed in brass; a scalloped clock; limed sage floorboards running toward the viewer and a
// straw-board accent; chalk-white desks with a birch top; spindle-back chairs; a garden bench for the interns; and the
// scenery in painted wood, rattan and woven baskets.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// ⛔ PURE, TOTAL, NO I/O (§ 10.6 item 1). Every function returns one standalone SVG DOCUMENT for its inputs and never
// throws for an input the scene can hand it; nothing here fetches, and nothing here is assigned after load — every
// table below is frozen, and each document builds its own gradients in a `Pen` made for that call.
//
// ⛔ A THEME TAKES NO FACT AND WRITES NO TEXT (§ 10.6 item 3). The inputs are rects, seeds and the side table's seat
// count — never a seat's state — and no document carries `<text>`, `<tspan>`, `<foreignObject>`, `<image>`,
// `<script>`, a `data:` URI or a reference that leaves the document. No piece carries a power or status light, and no
// light says what time it is. AT-D3-25's theme half holds all of that (`tools/floor-themes/selftest.mjs`).
//
// ⛔ EVERY COLOUR IS `PALETTE`'s, keyed by the piece that paints it, and § 10.6's `cottage` table states the same
// colours — AT-D3-25's drift leg holds the two equal in both directions. The paint rules are the creatures' own
// (`RULES`, the house theme's three), so the room and the creatures share one light: from the top-left, a shade leans
// plum, a highlight leans cream, a line is its fill mixed halfway to the line colour.
//
// ⛔ EVERY SEEDED CHOICE IS DRAWN THROUGH THE CHARACTER TREE's `draw(key, field)` (§ 10.4's appearance bullet), under
// field names of its own (`theme:…`). The colour arithmetic, the seeded draws, the `Pen`, the document wrapper, the
// drawing primitives and where a wall's strips and free ends are, are the themes' shared ones (`../paint.js`).

import { brushes, doc, draw, f, finite, leafPath, mix, pick, rr, stream, wallRuns } from '../paint.js';

/** The paint rules (§ 10.6, the creatures' own): the shade, the highlight and the line colour. */
export const RULES = Object.freeze({ shade: '#3d2b3c', highlight: '#fff6e6', line: '#3a2420' });

/** EVERY COLOUR THIS THEME PAINTS WITH, keyed by the piece that paints it — § 10.6's `cottage` table, in the same rows. */
export const PALETTE = Object.freeze({
    wall: Object.freeze({ top: '#d7e6ee', bottom: '#c4d8e4', sprig: '#ffffff', bud: '#f2cf6b', rail: '#fbf8f1', wainscot: '#f2efe6', skirting_top: '#5f7f9f', skirting_bottom: '#4d6a87' }),
    window: Object.freeze({ frame: '#fbf9f3', edge: '#d5dde2', shutter: '#6f93b5', awning: '#e9877a', stripe: '#fbf8f1', box: '#c9a57a', petals: Object.freeze(['#f2a7b5', '#f2cf6b', '#ffffff', '#c9a2d9']), leaves: Object.freeze(['#7fa36a', '#6b9a5a']) }),
    elevator: Object.freeze({ frame: '#5f7f9f', trim: '#d9b46a', lamp: '#f6c560' }),
    clock: Object.freeze({ rim: '#5f7f9f', petal: '#f2efe6', face: '#fffdf6' }),
    picture: Object.freeze({ frame: '#5f7f9f', sky: '#eef4f2', sea: '#9cc3d9', sail: '#fffaf0', hull: '#e9877a' }),
    floor: Object.freeze({ boards: '#cbd3b3' }),
    light: Object.freeze({ lamplight: '#fff3d6' }),
    accent: Object.freeze({ top: '#e0cfa8', bottom: '#d3bf95', warmth: '#e0a43a' }),
    landing: Object.freeze({ mat: '#f6f1e6', stripe: '#6f93b5', rim: '#d9b46a', glow: '#fff4d6' }),
    'wall-run': Object.freeze({ wainscot: '#f2efe6', cap: '#5f7f9f' }),
    desk: Object.freeze({ paint: '#f1ece2', top: '#d9b98c' }),
    chair: Object.freeze({ colours: Object.freeze(['#e9877a', '#f2cf6b', '#8fc1b5', '#6f93b5', '#f4efe6', '#c9a2d9']) }),
    'monitor-frame': Object.freeze({ bezel: '#dfe7ec', arm: '#9fb3c4' }),
    'desk-props': Object.freeze({
        books: Object.freeze(['#e9877a', '#6f93b5', '#f2cf6b', '#8fc1b5']),
        cup: '#fbf8f1',
        pencils: Object.freeze(['#f2cf6b', '#e9877a', '#6f93b5']),
        pot: '#e3a27c',
        cactus: '#7fa36a',
        glass: '#dceff4',
        water: '#a9d3e3',
        fish: '#f19a4a',
        saucer: '#fffaf0',
        tea: '#c08a4e',
    }),
    'side-table': Object.freeze({
        bench: Object.freeze(['#f4efe6', '#8fc1b5', '#f2cf6b', '#6f93b5']),
        cushion: Object.freeze(['#e9877a', '#6f93b5', '#8fc1b5', '#c9a2d9']),
        stripe: '#fffaf0',
        pot: '#fbf8f1',
        blooms: Object.freeze(['#a7b8e8', '#c9a2d9', '#f2a7b5']),
        leaf: '#7fa36a',
    }),
    bookcase: Object.freeze({ paint: '#f2efe6', back: '#8fb0cc', books: Object.freeze(['#e9877a', '#6f93b5', '#f2cf6b', '#8fc1b5', '#c9a2d9', '#fbf8f1', '#7fa36a']), shell: '#f6d6c8' }),
    plant: Object.freeze({ baskets: Object.freeze(['#cfa874', '#b98d5b', '#e3c99b']), leaves: Object.freeze(['#6b9a5a', '#7fa36a', '#5d8a54']) }),
    'floor-lamp': Object.freeze({ wood: '#cfa874', shade: '#fbf3e4', stripe: '#e9877a' }),
    armchair: Object.freeze({ rattan: '#d4b07a', cushion: '#8fc1b5' }),
    cushion: Object.freeze({ colours: Object.freeze(['#f2cf6b', '#8fc1b5', '#e9877a']) }),
    'book-pile': Object.freeze({ books: Object.freeze(['#e9877a', '#6f93b5', '#f2cf6b', '#8fc1b5', '#c9a2d9', '#fbf8f1']) }),
    // card#12749's snack corner, as a tea table: both kinds, `snack-table` and `snack-table-low`, paint from this one piece.
    'snack-table': Object.freeze({
        paint: '#fbf8f1',
        cloth: '#f4efe6',
        check: '#8fb0cc',
        stand: '#fffaf0',
        sponge: '#f2d29b',
        liner: '#e9877a',
        frosting: Object.freeze(['#f7b6c8', '#fffaf0', '#c9a2d9']),
        cherry: '#d9534f',
        teapot: '#8fc1b5',
        cup: '#fffaf0',
        band: '#6f93b5',
        tea: '#c08a4e',
    }),
    // card#12786's restroom door: its frame, the transom over it and the sill, the dark recess the open doorway shows,
    // the sign plate and its pictogram, and the slider's rim. The leaf and the field are the painter's, never drawn here.
    'restroom-door': Object.freeze({ frame: '#f2efe6', transom: '#6f93b5', sill: '#c9a57a', recess: '#281c27', plate: '#fbf8f1', figure: '#4d6a87', rim: '#d9b46a' }),
    // card#12844's standing kinds, each its own piece.
    'water-cooler': Object.freeze({ cabinet: '#f2efe6', tap: '#d9b46a', glass: '#dceff4', water: '#a9d3e3' }),
    'filing-cabinet': Object.freeze({ paint: '#8fb0cc', handle: '#d9b46a', label: '#fbf8f1' }),
    copier: Object.freeze({ body: '#f4efe6', lid: '#6f93b5', paper: '#ffffff' }),
    'coat-rack': Object.freeze({ wood: '#cfa874', coat: '#e9877a', scarf: '#8fc1b5', stripe: '#fbf8f1', hat: '#e3c99b' }),
    whiteboard: Object.freeze({ wood: '#cfa874', board: '#4f6b62', chalk: Object.freeze(['#fbf8f1', '#b5d99c', '#f6d36b', '#a9d3e3']) }),
    aquarium: Object.freeze({ cabinet: '#f2efe6', water: '#a9d3e3', sand: '#e8d6ae', weed: '#6b9a5a', shell: '#f6d6c8', fish: Object.freeze(['#f19a4a', '#f2cf6b']), glass: '#dceff4' }),
    'bean-bag': Object.freeze({ colours: Object.freeze(['#e9877a', '#8fc1b5', '#f2cf6b', '#c9a2d9']), patch: '#fbf8f1', check: '#6f93b5' }),
    globe: Object.freeze({ wood: '#cfa874', sea: '#9cc3d9', land: '#a9c48e', brass: '#d9b46a' }),
});

/**
 * THE LIGHT ON THE FLOOR, as numbers — the plane draws with these and `surfaces()` composites with the same ones, so
 * the colours a desk stands on are computed and never transcribed (§ 10.6 item 1).
 */
const LIGHT = Object.freeze({
    tone: 0.06, // a board's seeded tone: within ±tone / 2 of the boards' colour
    tone_lift: 1.6, // a lighter board is tinted this many times its tone
    pool: 0.5, // the lamplight pool's opacity at its centre
    front: 0.14, // the darkening toward the front edge, at the edge
    side: 0.08, // the darkening toward a side edge, at the edge
    band: 0.14, // the band's shadow along a grid's back edge, at the edge
    warmth: 0.07, // the accent region's warmth over its boards
    accent_pool: 0.7, // a pool over the accent region is this share of the floor's
});

// ── the brushes: the shared paint primitives (`../paint.js`) under this theme's paint rules ─────────

const { shade, tint, line, Pen, rimmed, shadow } = brushes(RULES);

/** A five-petalled flower, its centre at (x, y), radius `r`. */
function flower(pen, x, y, r, petal, centre) {
    const out = [];

    for (let i = 0; i < 5; i++) {
        const a = (i / 5) * Math.PI * 2 - Math.PI / 2;

        out.push(`<circle cx="${f(x + Math.cos(a) * r * 0.62)}" cy="${f(y + Math.sin(a) * r * 0.62)}" r="${f(r * 0.48)}" fill="${pen.paint(petal, 0.3)}" stroke="${line(petal)}" stroke-width=".4"/>`);
    }

    out.push(`<circle cx="${f(x)}" cy="${f(y)}" r="${f(r * 0.3)}" fill="${centre}"/>`);

    return out.join('');
}

/** A row of books standing on a line, from `x` across `w`, each at most `maxH` tall — squat, pastel, a band on each. */
function bookRow(pen, x, y, w, colours, maxH, next) {
    const out = [];
    let cx = x + 2;

    while (cx < x + w - 6) {
        const bw = 5 + next() * 4;
        const bh = maxH * (0.58 + next() * 0.4);
        const c = colours[Math.floor(next() * colours.length)];

        if (cx + bw > x + w) {
            break;
        }

        out.push(`<path d="${rr(cx, y - bh, bw, bh, 1.6)}" fill="${pen.face(c, 0.22, 0.14)}" stroke="${line(c)}" stroke-width=".55"/>`);
        out.push(`<path d="M${f(cx + 0.8)} ${f(y - bh * 0.3)}h${f(bw - 1.6)}" stroke="${tint(c, 0.55)}" stroke-width="1.1" opacity=".8"/>`);
        cx += bw + 0.8;
    }

    return out.join('');
}

/** A woven basket planter, its foot at (x, y), `w` wide and `h` tall: a tapered basket with weave bands. */
function basket(pen, x, y, w, h, c) {
    const out = [`<path d="M${f(x - w / 2)} ${f(y - h)}H${f(x + w / 2)}L${f(x + w * 0.4)} ${f(y)}H${f(x - w * 0.4)}Z" fill="${pen.face(c, 0.22, 0.2)}" stroke="${line(c)}" stroke-width=".8"/>`];

    for (let i = 1; i < 4; i++) {
        const yy = y - h + (h * i) / 4;
        const half = w / 2 - (w * 0.1 * i) / 4;

        out.push(`<path d="M${f(x - half)} ${f(yy)}H${f(x + half)}" stroke="${shade(c, 0.22)}" stroke-width=".9" opacity=".6"/>`);
    }

    for (let i = 0; i < 5; i++) {
        const t = (i + 0.5) / 5;

        out.push(`<path d="M${f(x - w / 2 + w * t)} ${f(y - h + 1)}L${f(x - w * 0.4 + w * 0.8 * t)} ${f(y - 1)}" stroke="${tint(c, 0.3)}" stroke-width=".7" opacity=".55"/>`);
    }

    out.push(`<path d="${rr(x - w / 2 - 1.5, y - h - 2.5, w + 3, 4, 2)}" fill="${pen.face(tint(c, 0.1))}" stroke="${line(c)}" stroke-width=".7"/>`);

    return out.join('');
}

// ── the band ──────────────────────────────────────────────────────────────────────────────────

/**
 * THE BAND's WALL, at the band's rect, under every band element (§ 10.6 item 2): a powder-blue wall with a faint
 * wallpaper of white sprigs, each with a butter bud, from its top to the plate rail; a white rail; white beadboard
 * below it; a navy skirting; the light from the top-left over it; and a small round-framed sailboat print past the
 * last window's surround where the wall has room for it.
 *
 * @param {{w: number, h: number, zone: {x: number, w: number}, elevator: {x: number, y: number, w: number, h: number},
 *          clock: {x: number, y: number, w: number, h: number}, windows: list<{x: number, y: number, w: number, h: number}>}} input
 */
export function band({ w, h, zone, elevator, clock, windows }) {
    finite(w, h);

    const pen = new Pen();
    const P = PALETTE.wall;
    const s = [];
    const rail = h - 62;
    const skirting = h - 10;

    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.v([[0, P.top], [1, P.bottom]])}"/>`);

    // the sprig wallpaper, barely there: a little cross of petals and a bud, on a dropped lattice, to the rail
    for (let y = 18, row = 0; y < rail - 6; y += 30, row++) {
        for (let x = row % 2 === 0 ? 14 : 36; x < w; x += 44) {
            s.push(`<g opacity=".5"><path d="M${x} ${y - 3.2}v6.4M${f(x - 3.2)} ${y}h6.4" stroke="${P.sprig}" stroke-width="2.2" stroke-linecap="round"/><circle cx="${x}" cy="${y}" r="1.1" fill="${P.bud}"/></g>`);
        }
    }

    // light falling from the top-left
    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.h([[0, RULES.highlight, 0.32], [0.5, RULES.highlight, 0], [1, RULES.shade, 0.07]])}"/>`);

    // the white beadboard below the rail: boards 9 px wide, each with its groove and a lit edge
    s.push(`<rect x="0" y="${f(rail)}" width="${f(w)}" height="${f(skirting - rail + 2)}" fill="${pen.v([[0, tint(P.wainscot, 0.2)], [1, shade(P.wainscot, 0.06)]])}"/>`);

    for (let x = 4; x < w; x += 9) {
        s.push(`<path d="M${x} ${f(rail + 6)}V${f(skirting)}" stroke="${shade(P.wainscot, 0.16)}" stroke-width=".9" opacity=".55"/>`);
    }

    // the plate rail, with its ledge's shadow
    s.push(`<path d="${rr(-4, rail - 3, w + 8, 9, 2.5)}" fill="${pen.face(P.rail, 0.3, 0.12)}" stroke="${line(P.wainscot)}" stroke-width=".8"/>`);
    s.push(`<rect x="0" y="${f(rail + 6)}" width="${f(w)}" height="4" fill="${RULES.shade}" opacity=".08"/>`);
    s.push(`<rect x="0" y="${f(skirting)}" width="${f(w)}" height="10" fill="${pen.v([[0, P.skirting_top], [1, P.skirting_bottom]])}"/>`);
    s.push(`<path d="M0 ${f(skirting + 1.5)}H${f(w)}" stroke="${tint(P.skirting_top, 0.3)}" stroke-width="1" opacity=".6"/>`);

    // the elevator's soft shadow on the wall's foot, and the clock's on the wall
    s.push(`<ellipse cx="${f(elevator.x + elevator.w / 2)}" cy="${f(h - 4)}" rx="${f(elevator.w * 0.7)}" ry="6" fill="${pen.soft(RULES.shade, 0.3)}"/>`);
    s.push(`<circle cx="${f(clock.x + clock.w / 2 + 3)}" cy="${f(clock.y + clock.h / 2 + 4)}" r="${f(clock.w / 2 + 2)}" fill="${pen.soft(RULES.shade, 0.25)}"/>`);

    // each window's soft shadow on the wall, under its surround
    for (const g of windows) {
        s.push(`<path d="${rr(g.x - 30, g.y - 10, g.w + 66, g.h + 30, 8)}" fill="${RULES.shade}" opacity=".08"/>`);
    }

    // a round-framed sailboat print past the last window's surround, where the wall has room for it
    const last = windows.length === 0 ? zone.x + zone.w : windows[windows.length - 1].x + windows[windows.length - 1].w + 32;
    const px = Math.max(last + 24, zone.x + zone.w + 24);

    if (px + 52 <= w - 24 && rail >= 70) {
        const Q = PALETTE.picture;
        const cx = px + 26;
        const cy = 50;

        s.push(rimmed(`<circle cx="${cx}" cy="${cy}" r="26" fill="${pen.paint(Q.frame, 0.3)}" stroke="${line(Q.frame)}" stroke-width=".9"/>`
            + `<circle cx="${cx}" cy="${cy}" r="20" fill="${pen.v([[0, Q.sky], [0.55, Q.sky], [0.56, Q.sea], [1, shade(Q.sea, 0.12)]])}"/>`
            + `<path d="M${cx - 10} ${cy + 4}h20l-4 6h-12z" fill="${Q.hull}" stroke="${line(Q.hull)}" stroke-width=".6"/>`
            + `<path d="M${cx} ${cy + 3}V${cy - 14}L${cx + 9} ${cy + 1}Z" fill="${Q.sail}" stroke="${line(Q.sail)}" stroke-width=".6"/>`
            + `<path d="M${cx - 1.5} ${cy - 10}L${cx - 8} ${cy + 1}H${cx - 1.5}Z" fill="${Q.sail}" stroke="${line(Q.sail)}" stroke-width=".6"/>`
            + `<path d="M${cx - 16} ${cy + 13}q4 -2 8 0t8 0t8 0t8 0" stroke="${tint(Q.sea, 0.5)}" stroke-width="1" fill="none"/>`));
    }

    // the end posts: white pilasters
    s.push(`<rect x="0" y="0" width="8" height="${f(h)}" fill="${pen.face(P.wainscot, 0.2, 0.18)}"/><rect x="${f(w - 8)}" y="0" width="8" height="${f(h)}" fill="${pen.face(P.wainscot, 0.2, 0.18)}"/>`);

    return doc(pen, w, h, s.join(''));
}

/**
 * A WINDOW's SURROUND, at the glazing grown by § 12's surround margin — 32 px each side, 16 above, 18 below — drawn
 * OVER the glazing, which stays the painter's (A17's sky) and is left open: a white frame with a cross mullion; a
 * louvred cornflower shutter folded back at each side; a coral-and-cream striped awning over the top with a scalloped
 * hem, wholly above the glass; and under it a window box with a seeded row of small flowers.
 *
 * @param {{index: number, glazing: {w: number, h: number}}} input the window's index and its glazing's size
 */
export function windowSurround({ index, glazing }) {
    finite(glazing.w, glazing.h);

    const pen = new Pen();
    const P = PALETTE.window;
    const gw = glazing.w;
    const gh = glazing.h;
    const W = gw + 64;
    const H = gh + 34;
    const x = 32;
    const y = 16;
    const key = `window/${index}`;
    const next = stream(key, 'theme:box');
    const s = [];

    // the shutters, folded back either side: a panel with its louvres
    for (const sx of [3, x + gw + 4]) {
        const m = [`<path d="${rr(sx, y - 4, 25, gh + 8, 2)}" fill="${pen.face(P.shutter, 0.2, 0.16)}" stroke="${line(P.shutter)}" stroke-width=".8"/>`];

        for (let ly = y + 1; ly < y + gh + 1; ly += 5) {
            m.push(`<path d="M${sx + 3} ${f(ly)}h19" stroke="${shade(P.shutter, 0.25)}" stroke-width="1.2" opacity=".6"/><path d="M${sx + 3} ${f(ly + 1.2)}h19" stroke="${tint(P.shutter, 0.35)}" stroke-width=".7" opacity=".6"/>`);
        }

        s.push(rimmed(m.join('')));
    }

    // the frame round the glazing — its inside cut out, so the sky shows through — and the cross mullion
    s.push(rimmed(`<path d="${rr(x - 6, y - 5, gw + 12, gh + 11, 4)}${rr(x, y, gw, gh, 2)}" fill-rule="evenodd" fill="${pen.face(P.frame, 0.3, 0.1)}" stroke="${line(P.edge)}" stroke-width=".9"/>`));
    s.push(`<path d="M${f(x + gw / 2)} ${y}V${f(y + gh)}M${x} ${f(y + gh / 2)}H${f(x + gw)}" stroke="${P.frame}" stroke-width="3.5"/>`);
    s.push(`<path d="M${x + 8} ${y + 5}l22 0l-18 26z" fill="${RULES.highlight}" opacity=".16"/>`);

    // the striped awning over the top, its scalloped hem ending above the glass
    const ax = x - 12;
    const aw = gw + 24;
    const scallops = Math.max(3, Math.round(aw / 16));
    const sw = aw / scallops;
    let hem = `M${f(ax)} 1H${f(ax + aw)}V10`;

    for (let i = scallops - 1; i >= 0; i--) {
        hem += `Q${f(ax + (i + 0.5) * sw)} 16 ${f(ax + i * sw)} 10`;
    }

    hem += 'Z';

    const stripes = [];

    for (let i = 0; i * 8 < aw; i++) {
        if (i % 2 === 0) {
            stripes.push(`<rect x="${f(ax + i * 8)}" y="0" width="8" height="16" fill="${P.awning}"/>`);
        }
    }

    s.push(`<defs><clipPath id="awning"><path d="${hem}"/></clipPath></defs>`);
    s.push(rimmed(`<path d="${hem}" fill="${pen.v([[0, tint(P.stripe, 0.2)], [1, P.stripe]])}" stroke="${line(P.awning)}" stroke-width=".7"/>`));
    s.push(`<g clip-path="url(#awning)">${stripes.join('')}<rect x="${f(ax)}" y="0" width="${f(aw)}" height="16" fill="${pen.v([[0, RULES.highlight, 0.25], [1, RULES.shade, 0.12]])}"/></g>`);
    s.push(`<path d="M${f(ax - 1)} 1.5H${f(ax + aw + 1)}" stroke="${line(P.awning)}" stroke-width="2" stroke-linecap="round"/>`);

    // the window box under the glass, with its seeded flowers
    const by = y + gh + 7;
    const flowers = [];

    for (let fx = x + 12; fx < x + gw - 10; fx += 11 + next() * 6) {
        const c = P.petals[Math.floor(next() * P.petals.length)];
        const leaf = P.leaves[Math.floor(next() * P.leaves.length)];
        const fy = by - 3 - next() * 3;

        flowers.push(`<path transform="translate(${f(fx)} ${f(by + 1)}) rotate(${f((next() - 0.5) * 70)})" d="${leafPath(7, 3)}" fill="${pen.paint(leaf)}" stroke="${line(leaf)}" stroke-width=".5"/>`);
        flowers.push(flower(pen, fx, fy, 3.4, c, PALETTE.wall.bud));
    }

    s.push(rimmed(flowers.join('')));
    s.push(rimmed(`<path d="${rr(x + 4, by, gw - 8, 10, 2)}" fill="${pen.face(P.box, 0.2, 0.2)}" stroke="${line(P.box)}" stroke-width=".8"/>`
        + `<path d="M${x + 8} ${by + 5}H${f(x + gw - 8)}" stroke="${shade(P.box, 0.2)}" stroke-width=".8" opacity=".5"/>`));

    return doc(pen, W, H, s.join(''));
}

/** The elevator surround's size: its frame (the leaves' 84 × 144, a 6 px frame and a 14 px header) grown 18 px right for the call buttons. */
const ELEVATOR = Object.freeze({ w: 114, h: 158, frame_w: 96, leaves: Object.freeze({ x: 6, y: 14, w: 84, h: 144 }) });

/**
 * THE ELEVATOR's SURROUND, at its frame grown 18 px to the right: a navy-painted frame with a brass trim line, a brass
 * header plate with a plain fanlight dial — no numeral and no arrow, because this elevator indicates no floor — the
 * lamp, the dark recess behind the leaves, and at each of the scene's two call-plate rects (`calls`, card#12747) a
 * brass plate with two round buttons on its centre line, the upper lit. The leaves are the painter's, in the building's
 * door colours.
 *
 * @param {{calls: {main: {x: number, y: number, w: number, h: number}, low: {x: number, y: number, w: number, h: number}}}} input
 */
export function elevatorSurround({ calls }) {
    const pen = new Pen();
    const P = PALETTE.elevator;
    const L = ELEVATOR.leaves;
    const fw = ELEVATOR.frame_w;
    const m = [];

    m.push(`<path d="${rr(0, 0, fw, ELEVATOR.h, 4)}" fill="${pen.face(P.frame, 0.22, 0.2)}" stroke="${line(P.frame)}" stroke-width="1"/>`);
    m.push(`<path d="${rr(2.5, 2.5, fw - 5, ELEVATOR.h - 2.5, 3)}" fill="none" stroke="${P.trim}" stroke-width="1" opacity=".8"/>`);
    // the header plate, its fanlight dial, and the lamp at its hub
    m.push(`<path d="${rr(fw / 2 - 20, 1.5, 40, 11, 3)}" fill="${pen.face(P.trim, 0.3, 0.2)}" stroke="${line(P.trim)}" stroke-width=".7"/>`);
    m.push(`<path d="M${fw / 2 - 11} 11A11 8 0 0 1 ${fw / 2 + 11} 11Z" fill="${tint(P.trim, 0.45)}" stroke="${shade(P.trim, 0.25)}" stroke-width=".6"/>`);

    for (const a of [-60, -30, 0, 30, 60]) {
        const r = (a * Math.PI) / 180;

        m.push(`<path d="M${fw / 2} 11L${f(fw / 2 + Math.sin(r) * 10)} ${f(11 - Math.cos(r) * 7.5)}" stroke="${shade(P.trim, 0.25)}" stroke-width=".5"/>`);
    }

    m.push(`<circle cx="${fw / 2}" cy="11" r="1.8" fill="${P.lamp}"/>`);
    // the recess behind the leaves
    m.push(`<path d="${rr(L.x - 2, L.y - 2, L.w + 4, L.h + 2, 2)}" fill="${shade(P.frame, 0.5)}"/>`);
    // the call buttons: a brass plate per rect, its buttons scaled to its width and spaced on its height
    for (const r of [calls.main, calls.low]) {
        const cx = f(r.x + r.w / 2);
        const dy = (r.h * 5) / 24;
        const br = f((r.w * 26) / 110);

        m.push(`<path d="${rr(r.x, r.y, r.w, r.h, r.w / 3)}" fill="${pen.face(P.trim, 0.3, 0.2)}" stroke="${line(P.trim)}" stroke-width=".7"/>`);
        m.push(`<circle cx="${cx}" cy="${f(r.y + r.h / 2 - dy)}" r="${br}" fill="${P.lamp}"/><circle cx="${cx}" cy="${f(r.y + r.h / 2 + dy)}" r="${br}" fill="${tint(P.trim, 0.5)}"/>`);
    }

    return doc(pen, ELEVATOR.w, ELEVATOR.h, rimmed(m.join('')));
}

/** The clock's rect, a 64 px square (§ 4.2's clock). */
const CLOCK = 64;

/**
 * THE CLOCK's CASE, at the clock's own rect: a scalloped navy rim of twelve round petals, a cream face and twelve dots,
 * the quarters larger. The hands, and the unset treatment, are the painter's (A17).
 */
export function clockCase() {
    const pen = new Pen();
    const P = PALETTE.clock;
    const c = CLOCK / 2;
    const m = [];

    for (let i = 0; i < 12; i++) {
        const a = (i / 12) * Math.PI * 2;

        m.push(`<circle cx="${f(c + Math.sin(a) * 25.5)}" cy="${f(c - Math.cos(a) * 25.5)}" r="5.6" fill="${pen.paint(P.petal, 0.25)}" stroke="${line(P.petal)}" stroke-width=".6"/>`);
    }

    m.push(`<circle cx="${c}" cy="${c}" r="26.5" fill="${pen.paint(P.rim, 0.3)}" stroke="${line(P.rim)}" stroke-width=".9"/>`);
    m.push(`<circle cx="${c}" cy="${c}" r="22" fill="${pen.paint(P.face, 0.15)}" stroke="${shade(P.rim, 0.2)}" stroke-width=".8"/>`);

    for (let i = 0; i < 12; i++) {
        const a = (i / 12) * Math.PI * 2;

        m.push(`<circle cx="${f(c + Math.sin(a) * 18)}" cy="${f(c - Math.cos(a) * 18)}" r="${i % 3 === 0 ? 1.9 : 1.1}" fill="${P.rim}"/>`);
    }

    m.push(`<path d="M${c - 18} ${c - 6}A19 19 0 0 1 ${c - 6} ${c - 18}" stroke="${RULES.highlight}" stroke-width="2" fill="none" opacity=".6" stroke-linecap="round"/>`);

    return doc(pen, CLOCK, CLOCK, rimmed(m.join('')));
}

// ── the plane ─────────────────────────────────────────────────────────────────────────────────

/** A board's colour at seeded tone `t` in [−tone / 2, tone / 2] — the plane's arithmetic and `surfaces()`'s. */
function boardColour(base, t) {
    return t > 0 ? tint(base, t * LIGHT.tone_lift) : shade(base, -t);
}

/** `c` under a layer of `over` at opacity `a` — sRGB source-over, as the browser composites the plane. */
const over = (c, layer, a) => mix(c, layer, a);

/**
 * A GRID's PLANE (§ 10.6 items 2, 6 and 7), at the grid's origin and its size: limed sage boards RUNNING TOWARD THE
 * VIEWER — columns of seeded widths, each board a seeded length with its own seeded tone, a faint grain and its butt
 * joints — seeded per grid; straw boards laid ACROSS over the `accent` runs; a pool of lamplight under every `desks`
 * object, reserved or not; a gentle darkening toward the front and the side walls; the band's soft shadow along the
 * back edge where that edge is the band's foot; the `wall` runs as a low white beadboard wall with a navy cap rail and
 * a square newel post at each free end; and, where the elevator's threshold lies over this grid, the landing — a
 * striped half-moon mat with a warm glow. No daylight patch under a window (§ 10.6 item 3).
 *
 * @param {{w: number, h: number, seed: string, desks: list<{x: number, y: number, w: number, h: number}>,
 *          walls: list<{x: number, y: number, w: number, h: number}>, accents: list<{x: number, y: number, w: number, h: number}>,
 *          cell: {w: number, h: number}, band_foot: boolean, threshold: {x: number, y: number}|null}} input
 */
export function plane({ w, h, seed, desks, walls, accents, cell, band_foot: bandFoot, threshold }) {
    finite(w, h, cell.w, cell.h);

    if (cell.w === 0 || cell.h === 0) {
        throw new RangeError('a plane asked for over a grid of empty cells');
    }

    const pen = new Pen();
    const base = PALETTE.floor.boards;
    const s = [];

    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${base}"/>`);

    // the boards, running toward the viewer: columns of seeded widths, each cut into boards of seeded lengths
    for (let x = 0, col = 0; x < w; col++) {
        const next = stream(seed, `theme:planks:${col}`);
        const bw = 34 + Math.floor(next() * 4) * 6;

        for (let y = -next() * 260; y < h;) {
            const len = 220 + next() * 280;
            const c = boardColour(base, (next() - 0.5) * LIGHT.tone);
            const top = Math.max(0, y);
            const bottom = Math.min(h, y + len);

            s.push(`<rect x="${f(x)}" y="${f(top)}" width="${f(Math.min(bw, w - x))}" height="${f(bottom - top)}" fill="${c}"/>`);

            const gx = x + 6 + next() * Math.max(0, bw - 12);

            s.push(`<path d="M${f(gx)} ${f(top + 6)}C${f(gx - 3)} ${f(top + (bottom - top) * 0.35)} ${f(gx + 3)} ${f(top + (bottom - top) * 0.65)} ${f(gx)} ${f(bottom - 6)}" stroke="${shade(base, 0.25)}" stroke-width="1" fill="none" opacity=".1"/>`);

            if (y > 0) {
                s.push(`<path d="M${f(x + 2)} ${f(y)}H${f(x + bw - 2)}" stroke="${shade(base, 0.25)}" stroke-width="1" opacity=".24" stroke-linecap="round"/>`);
            }

            y += len;
        }

        x += bw;

        if (x < w) {
            s.push(`<path d="M${f(x)} 0V${f(h)}" stroke="${shade(base, 0.2)}" stroke-width="1" opacity=".2"/><path d="M${f(x + 1)} 0V${f(h)}" stroke="${RULES.highlight}" stroke-width=".8" opacity=".18"/>`);
        }
    }

    // the accent region: straw boards laid across, a touch warmer
    if (accents.length > 0) {
        const A = PALETTE.accent;
        const clip = `<clipPath id="accent">${accents.map((r) => `<rect x="${f(r.x)}" y="${f(r.y)}" width="${f(r.w)}" height="${f(r.h)}"/>`).join('')}</clipPath>`;
        const top = Math.min(...accents.map((r) => r.y));
        const bottom = Math.max(...accents.map((r) => r.y + r.h));
        const left = Math.min(...accents.map((r) => r.x));
        const right = Math.max(...accents.map((r) => r.x + r.w));
        const next = stream(seed, 'theme:straw');
        const o = [];

        o.push(`<rect x="${f(left)}" y="${f(top)}" width="${f(right - left)}" height="${f(bottom - top)}" fill="${pen.v([[0, A.top], [1, A.bottom]])}"/>`);

        for (let y = top; y < bottom; y += 30) {
            if (y > top) {
                o.push(`<path d="M${f(left)} ${f(y)}H${f(right)}" stroke="${shade(A.bottom, 0.3)}" stroke-width="1" opacity=".18"/>`);
            }

            for (let x = left + next() * 160; x < right; x += 180 + next() * 160) {
                o.push(`<path d="M${f(x)} ${f(y + 2)}v26" stroke="${shade(A.bottom, 0.3)}" stroke-width="1" opacity=".16"/>`);
            }
        }

        o.push(`<rect x="${f(left)}" y="${f(top)}" width="${f(right - left)}" height="${f(bottom - top)}" fill="${A.warmth}" opacity="${LIGHT.warmth}"/>`);
        s.push(`<defs>${clip}</defs><g clip-path="url(#accent)">${o.join('')}</g>`);
    }

    // a pool of lamplight under every desks object, held or not, reserved or not
    const accentAt = (x, y) => accents.some((r) => x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h);

    for (const d of desks) {
        const cx = d.x + d.w * 0.43;
        const cy = d.y + d.h * 0.66;
        const op = accentAt(cx, cy) ? LIGHT.pool * LIGHT.accent_pool : LIGHT.pool;

        s.push(`<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(d.w * 0.57)}" ry="${f(d.h * 0.46)}" fill="${pen.soft(PALETTE.light.lamplight, op)}"/>`);
    }

    // the darkening toward the front and the side walls
    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.v([[0, RULES.shade, 0], [0.6, RULES.shade, 0], [1, RULES.shade, LIGHT.front]])}"/>`);
    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.h([[0, RULES.shade, LIGHT.side], [0.06, RULES.shade, 0], [0.94, RULES.shade, 0], [1, RULES.shade, LIGHT.side]])}"/>`);

    // the band's soft shadow along the back edge
    if (bandFoot) {
        s.push(`<rect x="0" y="0" width="${f(w)}" height="14" fill="${pen.v([[0, RULES.shade, LIGHT.band], [1, RULES.shade, 0]])}"/>`);
    }

    // the walls: a low white beadboard wall, a navy cap rail along its top, a square newel post at each free end
    if (walls.length > 0) {
        const C = PALETTE['wall-run'];
        const { strips, ends } = wallRuns(walls, cell, w, h);
        const post = Math.min(cell.w, cell.h) * 1.3;

        s.push(strips.map((r) => `<rect x="${f(r.x + 2)}" y="${f(r.y + 3)}" width="${f(r.w)}" height="${f(r.h)}" fill="${RULES.shade}" opacity=".14"/>`).join(''));
        s.push(strips.map((r) => `<rect x="${f(r.x)}" y="${f(r.y)}" width="${f(r.w)}" height="${f(r.h)}" fill="${pen.face(C.wainscot, 0.2, 0.12)}" stroke="${line(C.wainscot)}" stroke-width=".7"/>`).join(''));
        s.push(strips.map((r) => (r.w >= r.h
            ? `<path d="M${f(r.x)} ${f(r.y + 1)}H${f(r.x + r.w)}" stroke="${C.cap}" stroke-width="2.2"/>`
            : `<path d="M${f(r.x + 1)} ${f(r.y)}V${f(r.y + r.h)}" stroke="${C.cap}" stroke-width="2.2"/>`)).join(''));
        s.push(ends.map(({ x, y }) => `<path d="${rr(x - post / 2, y - post / 2, post, post, 1.5)}" fill="${pen.face(C.wainscot, 0.3, 0.15)}" stroke="${line(C.wainscot)}" stroke-width=".7"/><path d="${rr(x - post / 2 - 0.8, y - post / 2 - 0.8, post + 1.6, 3, 1)}" fill="${C.cap}"/>`).join(''));
    }

    // the landing: a striped half-moon mat at the elevator's threshold, with its warm glow
    if (threshold !== null && threshold !== undefined) {
        const L = PALETTE.landing;
        const { x, y } = threshold;
        const mat = `M${f(x - 68)} ${f(y)}A68 24 0 0 0 ${f(x + 68)} ${f(y)}Z`;

        s.push(`<defs><clipPath id="mat"><path d="${mat}"/></clipPath></defs>`);
        s.push(`<path d="${mat}" fill="${L.mat}" stroke="${line(L.mat)}" stroke-width=".8"/>`);
        s.push(`<g clip-path="url(#mat)">${[1, 2].map((i) => `<path d="M${f(x - 68 + i * 9)} ${f(y)}A${f(68 - i * 9)} ${f(24 - i * 7)} 0 0 0 ${f(x + 68 - i * 9)} ${f(y)}" stroke="${L.stripe}" stroke-width="3" fill="none" opacity=".75"/>`).join('')}</g>`);
        s.push(`<path d="M${f(x - 62)} ${f(y)}A62 21 0 0 0 ${f(x + 62)} ${f(y)}" fill="none" stroke="${L.rim}" stroke-width="1.2" opacity=".5"/>`);
        s.push(`<ellipse cx="${f(x)}" cy="${f(y + 6)}" rx="110" ry="46" fill="${pen.soft(L.glow, 0.5)}"/>`);
    }

    return doc(pen, w, h, s.join(''));
}

/**
 * THE COLOURS A DESK GROUP CAN STAND ON (§ 10.6 item 1) — what its chip and its bare text are composited over: the
 * boards at both extremes of their tone, the lamplight pool's composite at its centre, the darkening's composite at
 * its darkest, and the accent floor at both ends of its gradient, under its pool and at the darkening's darkest.
 * Computed by the plane's own arithmetic, never transcribed. The band, `wall` runs and standing scenery are excluded —
 * no `desks` object may stand on them — and so are the landing and its glow, which § 10.6 states as an exclusion
 * rather than claims safe.
 *
 * @returns {Object<string, string>} `name → #rrggbb`
 */
export function surfaces() {
    const base = PALETTE.floor.boards;
    const A = PALETTE.accent;
    const light = boardColour(base, LIGHT.tone / 2);
    const dark = boardColour(base, -LIGHT.tone / 2);
    // the darkest corner: the front darkening and a side's, each at its edge, over the darkest board
    const darkest = over(over(dark, RULES.shade, LIGHT.front), RULES.shade, LIGHT.side);
    const warm = (c) => over(c, A.warmth, LIGHT.warmth);

    return Object.freeze({
        'boards-light': light,
        'boards-dark': dark,
        pool: over(base, PALETTE.light.lamplight, LIGHT.pool),
        darkest,
        'accent-top': warm(A.top),
        'accent-bottom': warm(A.bottom),
        'accent-pool': over(warm(A.bottom), PALETTE.light.lamplight, LIGHT.pool * LIGHT.accent_pool),
        'accent-darkest': over(over(warm(A.bottom), RULES.shade, LIGHT.front), RULES.shade, LIGHT.side),
    });
}

// ── the scenery ───────────────────────────────────────────────────────────────────────────────

/**
 * A STANDING SCENERY PIECE (§ 10.6 item 6), at its tile's cell rect, its foot on the cell's floor line, sized by the
 * cell: a painted bookcase, a plant in a woven basket, a tripod floor lamp, a rattan armchair, a knitted pouf, a pile
 * of books, the snack corner's two tea tables (`teaTable()`), the restroom's door (`restroomDoor()`), which the scene
 * hands its `sign` and `plate` rects as well, or one of card#12844's kinds (`moreScenery()`). A kind it does not know
 * draws nothing, as the house theme's does. The floor lamp's light is § 6.3's decorative glow, the painter's; the
 * theme draws no glow of its own.
 *
 * @param {{kind: string, w: number, h: number, seed: string, index: number}} input the kind, the cell's size,
 *        the grid's seed and the cell's index
 */
export function scenery({ kind, w, h, seed, index, sign = null, plate = null }) {
    finite(w, h);

    const pen = new Pen();
    const key = `${seed}#${index}`;
    const next = stream(key, `theme:${kind}`);
    const cx = w / 2;
    const foot = h - 1;
    const s = [];

    if (kind === 'bookcase') {
        const P = PALETTE.bookcase;
        const bw = Math.max(8, w - 8);
        const x = (w - bw) / 2;
        const bh = Math.max(12, h - 6);
        const crown = Math.min(8, bh * 0.08);
        const m = [];

        s.push(shadow(pen, cx + 4, foot, bw * 0.6, 5, 0.3));
        // the painted case, a scalloped crown over it, bun feet under it
        m.push(`<path d="${rr(x, foot - bh + crown, bw, bh - crown - 3, 3)}" fill="${pen.face(P.paint, 0.18, 0.16)}" stroke="${line(P.paint)}" stroke-width=".9"/>`);

        const bumps = Math.max(2, Math.round(bw / 16));
        let top = `M${f(x - 2)} ${f(foot - bh + crown + 2)}`;

        for (let i = 0; i < bumps; i++) {
            top += `Q${f(x - 2 + ((i + 0.5) * (bw + 4)) / bumps)} ${f(foot - bh - crown * 0.4)} ${f(x - 2 + ((i + 1) * (bw + 4)) / bumps)} ${f(foot - bh + crown + 2)}`;
        }

        m.push(`<path d="${top}Z" fill="${pen.face(P.paint, 0.3, 0.1)}" stroke="${line(P.paint)}" stroke-width=".8"/>`);

        for (const fx of [x + 5, x + bw - 5]) {
            m.push(`<ellipse cx="${f(fx)}" cy="${f(foot - 1.6)}" rx="3.6" ry="2" fill="${pen.paint(shade(P.paint, 0.2))}"/>`);
        }

        const rows = Math.max(1, Math.round((bh - crown) / 32));
        const ih = (bh - crown - 12) / rows;

        for (let i = 0; i < rows; i++) {
            const ry = foot - bh + crown + 4 + i * ih;

            m.push(`<path d="${rr(x + 5, ry, bw - 10, ih - 4, 2)}" fill="${pen.v([[0, shade(P.back, 0.25)], [1, P.back]])}"/>`);
            m.push(bookRow(pen, x + 6, ry + ih - 4, (bw - 12) * (next() < 0.4 ? 0.7 : 1), P.books, ih - 9, next));

            if (next() < 0.45) {
                // a seashell at the end of the shelf
                const sx = x + bw - 13;
                const sy = ry + ih - 5;

                m.push(`<path d="M${f(sx - 5)} ${f(sy)}Q${f(sx)} ${f(sy - 11)} ${f(sx + 5)} ${f(sy)}Z" fill="${pen.paint(P.shell, 0.3)}" stroke="${line(P.shell)}" stroke-width=".5"/><path d="M${f(sx)} ${f(sy)}V${f(sy - 7)}M${f(sx - 2.6)} ${f(sy)}L${f(sx - 1)} ${f(sy - 6)}M${f(sx + 2.6)} ${f(sy)}L${f(sx + 1)} ${f(sy - 6)}" stroke="${shade(P.shell, 0.25)}" stroke-width=".5"/>`);
            }
        }

        s.push(rimmed(m.join('')));
    } else if (kind === 'plant') {
        s.push(floorPlant(pen, key, w, h, cx, foot, next));
    } else if (kind === 'floor-lamp') {
        const P = PALETTE['floor-lamp'];
        const k = Math.min(1, h / 124, w / 34);
        const top = foot - 116 * k;
        const m = [];

        s.push(shadow(pen, cx + 3, foot, 15 * k, 3.5 * k, 0.32));
        // the tripod's three legs and its pole
        for (const dx of [-11, 0, 11]) {
            m.push(`<path d="M${f(cx)} ${f(foot - 34 * k)}L${f(cx + dx * k)} ${f(foot)}" stroke="${line(P.wood)}" stroke-width="${f(2.6 * k)}" stroke-linecap="round"/><path d="M${f(cx)} ${f(foot - 34 * k)}L${f(cx + dx * k)} ${f(foot)}" stroke="${tint(P.wood, 0.15)}" stroke-width="${f(1.3 * k)}" stroke-linecap="round"/>`);
        }

        m.push(`<path d="M${f(cx)} ${f(foot - 34 * k)}V${f(top + 28 * k)}" stroke="${line(P.wood)}" stroke-width="${f(2.8 * k)}" stroke-linecap="round"/><path d="M${f(cx)} ${f(foot - 34 * k)}V${f(top + 28 * k)}" stroke="${tint(P.wood, 0.2)}" stroke-width="${f(1.4 * k)}" stroke-linecap="round"/>`);
        // the drum shade, a coral band at its top and foot
        m.push(`<path d="M${f(cx - 12 * k)} ${f(top)}H${f(cx + 12 * k)}L${f(cx + 15 * k)} ${f(top + 28 * k)}H${f(cx - 15 * k)}Z" fill="${pen.face(P.shade, 0.2, 0.12)}" stroke="${line(P.shade)}" stroke-width=".9"/>`);
        m.push(`<path d="M${f(cx - 12 * k)} ${f(top + 2 * k)}H${f(cx + 12 * k)}M${f(cx - 14.6 * k)} ${f(top + 26 * k)}H${f(cx + 14.6 * k)}" stroke="${P.stripe}" stroke-width="${f(2.4 * k)}"/>`);
        m.push(`<ellipse cx="${f(cx)}" cy="${f(top + 28 * k)}" rx="${f(13 * k)}" ry="${f(2.4 * k)}" fill="${RULES.highlight}" opacity=".85"/>`);
        s.push(rimmed(m.join('')));
    } else if (kind === 'armchair') {
        const P = PALETTE.armchair;
        const k = Math.min(1, w / 72, h / 60);
        const c = P.rattan;
        const m = [];

        // the round rattan back, its weave, the arms, the seat cushion and the little legs
        m.push(`<path d="M${f(cx - 31 * k)} ${f(foot - 18 * k)}C${f(cx - 34 * k)} ${f(foot - 62 * k)} ${f(cx + 34 * k)} ${f(foot - 62 * k)} ${f(cx + 31 * k)} ${f(foot - 18 * k)}Z" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".9"/>`);

        for (let i = 1; i < 5; i++) {
            m.push(`<path d="M${f(cx - (31 - i * 2) * k)} ${f(foot - (18 + i * 7) * k)}Q${f(cx)} ${f(foot - (30 + i * 7) * k)} ${f(cx + (31 - i * 2) * k)} ${f(foot - (18 + i * 7) * k)}" stroke="${shade(c, 0.22)}" stroke-width=".8" fill="none" opacity=".55"/>`);
        }

        m.push(`<path d="${rr(cx - 25 * k, foot - 26 * k, 50 * k, 14 * k, 6 * k)}" fill="${pen.paint(P.cushion, 0.3)}" stroke="${line(P.cushion)}" stroke-width=".8"/>`);

        for (const side of [-1, 1]) {
            m.push(`<path d="${rr(cx + side * 28 * k - 7 * k, foot - 34 * k, 14 * k, 24 * k, 7 * k)}" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".9"/>`);
            m.push(`<path d="M${f(cx + side * 22 * k)} ${f(foot - 11 * k)}l${f(side * 3 * k)} ${f(11 * k)}" stroke="${line(c)}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);
        }

        s.push(shadow(pen, cx + 3, foot, 36 * k, 5 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'cushion') {
        const c = pick(key, 'theme:pouf', PALETTE.cushion.colours);
        const k = Math.min(1, w / 22, h / 13);
        const ribs = [];

        for (let i = -2; i <= 2; i++) {
            ribs.push(`<path d="M${f(cx + i * 3.4 * k)} ${f(foot - 10 * k)}Q${f(cx + i * 4.2 * k)} ${f(foot - 5 * k)} ${f(cx + i * 3.4 * k)} ${f(foot - 0.8 * k)}" stroke="${shade(c, 0.2)}" stroke-width=".7" fill="none" opacity=".6"/>`);
        }

        s.push(shadow(pen, cx + 1, foot, 10 * k, 2.6 * k, 0.3) + rimmed(`<path d="${rr(cx - 9.5 * k, foot - 10.5 * k, 19 * k, 10.5 * k, 4.5 * k)}" fill="${pen.paint(c, 0.32)}" stroke="${line(c)}" stroke-width=".7"/>${ribs.join('')}`));
    } else if (kind === 'book-pile') {
        const colours = PALETTE['book-pile'].books;
        const m = [];
        let y = foot;
        const layers = Math.max(1, Math.min(5, Math.floor(h / 6)));

        for (let i = 0; i < layers && y > 4; i++) {
            const bw = w * (0.66 + next() * 0.26) - 4;
            const bh = 4.5 + next() * 1.5;
            const bx = cx - bw / 2 + (next() - 0.5) * 5;
            const c = colours[Math.floor(next() * colours.length)];

            m.push(`<path d="${rr(bx, y - bh, bw, bh, 1.6)}" fill="${pen.face(c, 0.22, 0.14)}" stroke="${line(c)}" stroke-width=".55"/>`);
            m.push(`<path d="M${f(bx + bw - 3)} ${f(y - bh + 1)}v${f(bh - 2)}" stroke="${tint(c, 0.6)}" stroke-width="1.2" opacity=".7"/>`);
            y -= bh;
        }

        s.push(shadow(pen, cx + 2, foot, w * 0.4, 2.5, 0.28) + rimmed(m.join('')));
    } else if (kind === 'snack-table' || kind === 'snack-table-low') {
        s.push(teaTable(pen, SNACK[kind], w, foot));
    } else if (kind === 'restroom-door') {
        s.push(restroomDoor(pen, w, foot, sign, plate));
    } else {
        s.push(moreScenery(pen, kind, key, w, h, cx, foot, next));
    }

    return doc(pen, w, h, s.join(''));
}

/** How far each floor plant's shape reaches at unit scale, its basket and leaves together — what a cell fits it by. */
const PLANT_REACH = Object.freeze({
    snake: Object.freeze({ w: 40, h: 78 }),
    palm: Object.freeze({ w: 86, h: 80 }),
    fiddle: Object.freeze({ w: 56, h: 90 }),
});

/**
 * A FLOOR PLANT in a woven basket, sized by its cell: a snake plant in a narrow cell, else a snake plant, a palm or a
 * fiddle-leaf fig, seeded per cell, its basket's colour and its green seeded too. Drawn at unit scale and fitted to the
 * cell — never more than 1.6 times taller than wide.
 */
function floorPlant(pen, key, w, h, cx, foot, next) {
    const P = PALETTE.plant;
    const shape = w * 2 < h ? 'snake' : ['snake', 'palm', 'fiddle'][draw(key, 'theme:plant-shape') % 3];
    const reach = PLANT_REACH[shape];
    const sx = (w * 0.94) / reach.w;
    const sy = Math.min((h * 0.96) / reach.h, sx * 1.6);
    const green = pick(key, 'theme:plant-leaf', P.leaves);
    const bc = pick(key, 'theme:plant-basket', P.baskets);
    const bh = 20;
    const leaves = [];

    if (shape === 'snake') {
        for (let i = 0; i < 7; i++) {
            const x = (i - 3) * 4.2 + (next() - 0.5) * 2;
            const len = 34 + next() * 24 - Math.abs(i - 3) * 5;
            const lean = (i - 3) * 3.5;
            const c = i % 2 ? green : tint(green, 0.12);

            leaves.push(`<path d="M${f(x - 3)} ${-bh + 2}Q${f(x - 3 + lean * 0.3)} ${f(-bh - len * 0.6)} ${f(x + lean)} ${f(-bh - len)}Q${f(x + 3 + lean * 0.3)} ${f(-bh - len * 0.6)} ${f(x + 3)} ${-bh + 2}Z" fill="${pen.face(c, 0.2, 0.15)}" stroke="${line(c)}" stroke-width=".7"/>`);
            leaves.push(`<path d="M${f(x)} ${-bh}Q${f(x + lean * 0.3)} ${f(-bh - len * 0.5)} ${f(x + lean * 0.9)} ${f(-bh - len * 0.92)}" stroke="${tint(c, 0.4)}" stroke-width=".7" fill="none" opacity=".6"/>`);
        }
    } else if (shape === 'palm') {
        // broad paddle fronds on long stems, fanned, each with its midrib — a parlour palm read at a glance
        for (let i = 0; i < 6; i++) {
            const a = -66 + (132 * i) / 5 + (next() - 0.5) * 8;
            const len = 30 + next() * 10;
            const r = (a * Math.PI) / 180;
            const sx = Math.sin(r) * len * 0.45;
            const sy = -bh - Math.cos(r) * len * 0.45;
            const c = i % 2 ? green : tint(green, 0.14);

            leaves.push(`<path d="M0 ${-bh}L${f(sx)} ${f(sy)}" stroke="${shade(green, 0.2)}" stroke-width="1.4" stroke-linecap="round"/>`);
            leaves.push(`<path transform="translate(${f(sx)} ${f(sy)}) rotate(${f(a)})" d="${leafPath(len * 0.75, 8)}" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".7"/>`);
            leaves.push(`<path transform="translate(${f(sx)} ${f(sy)}) rotate(${f(a)})" d="M0 -2V${f(-len * 0.68)}" stroke="${tint(c, 0.4)}" stroke-width=".8" opacity=".7"/>`);
        }
    } else {
        leaves.push(`<path d="M0 ${-bh}C-2 ${-bh - 30} 3 ${-bh - 50} 0 ${-bh - 66}" stroke="${shade(green, 0.3)}" stroke-width="2.2" fill="none"/>`);

        for (let i = 0; i < 9; i++) {
            const t = 0.25 + (i / 8) * 0.75;
            const side = i % 2 ? 1 : -1;
            const y = -bh - 66 * t;
            const c = i % 3 === 0 ? tint(green, 0.12) : i % 3 === 1 ? green : shade(green, 0.08);

            leaves.push(`<ellipse cx="${f(side * 10)}" cy="${f(y)}" rx="11" ry="8" transform="rotate(${f(side * 30 + (next() - 0.5) * 16)} ${f(side * 10)} ${f(y)})" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".7"/>`);
            leaves.push(`<path d="M${side * 3} ${f(y + 2)}L${side * 16} ${f(y - 2)}" stroke="${tint(c, 0.4)}" stroke-width=".7" opacity=".6"/>`);
        }
    }

    return `<g transform="translate(${f(cx)} ${f(foot)}) scale(${f(Math.min(sx, sy * 1.6))} ${f(sy)})">${shadow(pen, 2, 0, 16, 3, 0.3)}${rimmed(leaves.join('') + basket(pen, 0, 0, 24, bh, bc))}</g>`;
}

/**
 * THE RESTROOM DOOR (card#12786, FLOOR.md § 6.7 item 2), its foot on the cell's floor line: a white-painted door FRAME
 * face-on round the same opening the house frame leaves — 6 px jambs, a 10 px head, a 4 px sill — with a navy transom
 * panel carrying a little fanlight across the head, and inside it the dark RECESS of an open doorway; above it the SIGN
 * PLATE at `plate`, a cream plate in a navy border carrying the restroom pictogram — two round-headed figures side by
 * side, one in a triangle skirt — and at `sign` the slider plate's brass RIM. No leaf: the doorway is drawn open and
 * empty, and the painter draws the leaf over it and the vacant / occupied field inside the rim. No text, no light,
 * nothing that moves. `sign` and `plate` are the scene's (§ 12's *Restroom plates*), in the cell's px; a rect not handed
 * is not drawn.
 */
function restroomDoor(pen, w, foot, sign, plate) {
    const P = PALETTE['restroom-door'];
    const top = 22;
    const out = [shadow(pen, w / 2 + 2, foot, w * 0.5, 3, 0.28)];

    out.push(rimmed(`<path d="${rr(0, top - 3, w, foot - top + 3, 1.5)}" fill="${pen.face(P.frame, 0.2, 0.16)}" stroke="${line(P.frame)}" stroke-width=".9"/>`
        + `<path d="${rr(6, top + 10, w - 12, foot - top - 14, 1)}" fill="${pen.v([[0, P.recess], [1, shade(P.recess, 0.3)]])}"/>`
        + `<path d="${rr(5, top, w - 10, 9, 1)}" fill="${pen.face(P.transom, 0.2, 0.15)}" stroke="${line(P.transom)}" stroke-width=".6"/>`
        + `<path d="M${f(w / 2 - 9)} ${top + 8}A9 6 0 0 1 ${f(w / 2 + 9)} ${top + 8}Z" fill="${tint(P.transom, 0.45)}"/>`
        + `<path d="M${f(w / 2)} ${top + 8}V${top + 2.5}M${f(w / 2 - 5)} ${top + 8}L${f(w / 2 - 7)} ${top + 4}M${f(w / 2 + 5)} ${top + 8}L${f(w / 2 + 7)} ${top + 4}" stroke="${P.transom}" stroke-width=".7"/>`
        + `<path d="${rr(-1, foot - 4, w + 2, 4, 1)}" fill="${pen.face(P.sill, 0.2, 0.15)}" stroke="${line(P.sill)}" stroke-width=".6"/>`));

    if (plate !== null) {
        const { x, y, w: pw, h: ph } = plate;
        const ink = P.figure;
        const fig = (fx, skirt) => `<circle cx="${f(fx)}" cy="${f(y + ph * 0.3)}" r="${f(ph * 0.14)}" fill="${ink}"/>`
            + (skirt
                ? `<path d="M${f(fx)} ${f(y + ph * 0.46)}L${f(fx + ph * 0.24)} ${f(y + ph * 0.86)}H${f(fx - ph * 0.24)}Z" fill="${ink}"/>`
                : `<path d="${rr(fx - ph * 0.12, y + ph * 0.46, ph * 0.24, ph * 0.4, ph * 0.08)}" fill="${ink}"/>`);

        out.push(rimmed(`<path d="${rr(x, y, pw, ph, ph / 2)}" fill="${pen.face(P.plate, 0.12, 0.18)}" stroke="${ink}" stroke-width="1.4"/>`
            + fig(x + pw * 0.36, false) + fig(x + pw * 0.64, true)));
    }

    if (sign !== null) {
        out.push(`<path d="${rr(sign.x - 1.5, sign.y - 1.5, sign.w + 3, sign.h + 3, 3)}" fill="none" stroke="${P.rim}" stroke-width="2"/>`
            + `<path d="${rr(sign.x - 2.5, sign.y - 2.5, sign.w + 5, sign.h + 5, 3.5)}" fill="none" stroke="${line(P.rim)}" stroke-width=".6"/>`);
    }

    return out.join('');
}

/**
 * THE SNACK CORNER's TWO TABLES (card#12749, FLOOR.md § 6.6 item 2), as a TEA TABLE: `top` is the table top's height
 * above the foot — waist-high to the 120 px character for `snack-table`, to the 56 px intern for `snack-table-low` — and
 * `n` the cupcakes and the cups it carries. What stands on the top is drawn at the scale the cell's width sets against
 * the tall table's 72 px, so the low set is the same things, smaller.
 */
const SNACK = Object.freeze({
    'snack-table': Object.freeze({ top: 60, n: 3 }),
    'snack-table-low': Object.freeze({ top: 28, n: 2 }),
});

/** One cupcake standing at (x, y): a striped liner, a swirl of frosting, a cherry on the first. */
function cupcake(pen, x, y, k, frosting, cherry, P) {
    const lw = 6 * k;
    const lh = 4.4 * k;
    const out = [`<path d="M${f(x - lw / 2)} ${f(y - lh)}H${f(x + lw / 2)}L${f(x + lw * 0.38)} ${f(y)}H${f(x - lw * 0.38)}Z" fill="${pen.face(P.liner, 0.2, 0.15)}" stroke="${line(P.liner)}" stroke-width=".5"/>`];

    out.push(`<path d="M${f(x - lw * 0.18)} ${f(y - lh + 0.4)}V${f(y - 0.4)}M${f(x + lw * 0.18)} ${f(y - lh + 0.4)}V${f(y - 0.4)}" stroke="${tint(P.liner, 0.5)}" stroke-width="${f(0.6 * k)}"/>`);
    out.push(`<ellipse cx="${f(x)}" cy="${f(y - lh)}" rx="${f(lw * 0.54)}" ry="${f(1.2 * k)}" fill="${pen.paint(P.sponge, 0.3)}" stroke="${line(P.sponge)}" stroke-width=".4"/>`);
    out.push(`<path d="M${f(x - lw * 0.56)} ${f(y - lh)}Q${f(x - lw * 0.6)} ${f(y - lh - 3.2 * k)} ${f(x)} ${f(y - lh - 4.6 * k)}Q${f(x + lw * 0.6)} ${f(y - lh - 3.2 * k)} ${f(x + lw * 0.56)} ${f(y - lh)}Z" fill="${pen.paint(frosting, 0.3)}" stroke="${line(frosting)}" stroke-width=".5"/>`);
    out.push(`<path d="M${f(x - lw * 0.3)} ${f(y - lh - 1.6 * k)}q${f(lw * 0.3)} ${f(-1.2 * k)} ${f(lw * 0.6)} 0" stroke="${tint(frosting, 0.5)}" stroke-width="${f(0.6 * k)}" fill="none"/>`);

    if (cherry) {
        out.push(`<circle cx="${f(x)}" cy="${f(y - lh - 5.2 * k)}" r="${f(1.3 * k)}" fill="${pen.paint(P.cherry, 0.4)}"/>`);
    }

    return out.join('');
}

/** One teacup on its saucer at (x, y): its handle to the right, a band round it, tea at its brim. */
function teacup(pen, x, y, k, P) {
    const w = 6 * k;
    const h = 4.6 * k;

    return `<ellipse cx="${f(x)}" cy="${f(y - 0.6 * k)}" rx="${f(w * 0.78)}" ry="${f(1.1 * k)}" fill="${pen.face(P.cup, 0.2, 0.2)}" stroke="${line(P.cup)}" stroke-width=".5"/>`
        + `<path d="M${f(x + w / 2 - 0.4 * k)} ${f(y - h * 0.9)}q${f(2.4 * k)} ${f(0.2 * k)} ${f(2 * k)} ${f(1.8 * k)}q${f(-0.3 * k)} ${f(1.4 * k)} ${f(-2 * k)} ${f(1.3 * k)}" fill="none" stroke="${line(P.cup)}" stroke-width="${f(0.9 * k)}"/>`
        + `<path d="M${f(x - w / 2)} ${f(y - h - 1 * k)}H${f(x + w / 2)}Q${f(x + w * 0.46)} ${f(y - 1.1 * k)} ${f(x)} ${f(y - 1.1 * k)}Q${f(x - w * 0.46)} ${f(y - 1.1 * k)} ${f(x - w / 2)} ${f(y - h - 1 * k)}Z" fill="${pen.face(P.cup, 0.2, 0.22)}" stroke="${line(P.cup)}" stroke-width=".5"/>`
        + `<path d="M${f(x - w * 0.44)} ${f(y - h * 0.6)}H${f(x + w * 0.44)}" stroke="${P.band}" stroke-width="${f(0.9 * k)}"/>`
        + `<ellipse cx="${f(x)}" cy="${f(y - h - 1 * k)}" rx="${f(w / 2)}" ry="${f(0.9 * k)}" fill="${P.tea}" stroke="${line(P.cup)}" stroke-width=".4"/>`;
}

/**
 * A TEA TABLE (§ 6.6 item 2): a contact shadow, a white-painted table on four turned legs under a gingham cloth that
 * falls straight with a scalloped hem, and on it a round teapot, a tiered cake stand of `n` cupcakes and `n` teacups on
 * their saucers, the first with a drawn curl of steam. Nothing on it moves and it writes no text, and it carries no
 * light of any kind.
 */
function teaTable(pen, { top, n }, w, foot) {
    const P = PALETTE['snack-table'];
    const k = w / 72;
    const x0 = 2 * k;
    const x1 = w - 2 * k;
    const yt = foot - top;
    const legs = [];
    const out = [shadow(pen, w / 2 + 2 * k, foot, (x1 - x0) * 0.52, 3.2 * k, 0.3)];

    for (const lx of [x0 + 6 * k, x1 - 8 * k]) {
        legs.push(`<path d="${rr(lx, yt + 2, 2.2 * k, top - 5 * k, 1)}" fill="${shade(P.paint, 0.2)}" stroke="${line(P.paint)}" stroke-width=".5"/>`);
    }

    for (const lx of [x0 + 3 * k, x1 - 6 * k]) {
        legs.push(`<path d="M${f(lx)} ${f(yt + 4)}h${f(3.2 * k)}l${f(-0.4 * k)} ${f(top - 4)}h${f(-2.4 * k)}Z" fill="${pen.face(P.paint, 0.2, 0.2)}" stroke="${line(P.paint)}" stroke-width=".6"/>`);
        legs.push(`<ellipse cx="${f(lx + 1.6 * k)}" cy="${f(yt + top * 0.55)}" rx="${f(2.4 * k)}" ry="${f(1.2 * k)}" fill="${pen.paint(P.paint)}" stroke="${line(P.paint)}" stroke-width=".4"/>`);
    }

    out.push(rimmed(legs.join('')));

    // the top under a gingham cloth: a checked drop, its hem in scallops
    const depth = 7 * k;
    const drop = 9 * k;
    const scallops = Math.max(3, Math.round((x1 - x0) / (7 * k)));
    const sw = (x1 - x0 + 2) / scallops;
    let hem = `M${f(x0 - 1)} ${f(yt - 0.6 * k)}V${f(yt + drop)}`;

    for (let i = 0; i < scallops; i++) {
        hem += `Q${f(x0 - 1 + (i + 0.5) * sw)} ${f(yt + drop + 3 * k)} ${f(x0 - 1 + (i + 1) * sw)} ${f(yt + drop)}`;
    }

    hem += `V${f(yt - 0.6 * k)}Z`;

    const checks = [];

    for (let cx = x0; cx < x1; cx += 4 * k) {
        checks.push(`<rect x="${f(cx)}" y="${f(yt - depth)}" width="${f(2 * k)}" height="${f(depth + drop + 3 * k)}" fill="${P.check}" opacity=".45"/>`);
    }

    for (let cy = yt - depth + k; cy < yt + drop + 3 * k; cy += 4 * k) {
        checks.push(`<rect x="${f(x0 - 1)}" y="${f(cy)}" width="${f(x1 - x0 + 2)}" height="${f(2 * k)}" fill="${P.check}" opacity=".35"/>`);
    }

    out.push(`<defs><clipPath id="cloth"><path d="${hem}"/><path d="${rr(x0 - 1, yt - depth, x1 - x0 + 2, depth + 1, 3 * k)}"/></clipPath></defs>`);
    out.push(rimmed(`<path d="${rr(x0 - 1, yt - depth, x1 - x0 + 2, depth + 1, 3 * k)}" fill="${pen.v([[0, tint(P.cloth, 0.3)], [1, P.cloth]])}" stroke="${line(P.cloth)}" stroke-width=".6"/>`
        + `<path d="${hem}" fill="${pen.face(P.cloth, 0.15, 0.16)}" stroke="${line(P.cloth)}" stroke-width=".6"/>`));
    out.push(`<g clip-path="url(#cloth)">${checks.join('')}</g>`);

    const base = yt - 2 * k;
    const back = [];

    // the round teapot at the left: its body, its lid and knob, its spout and handle
    const tx = x0 + 13 * k;
    const tr = 8 * k;
    const ty = base - tr;

    back.push(`<path d="M${f(tx - tr - 5 * k)} ${f(ty - 3 * k)}Q${f(tx - tr - 2 * k)} ${f(ty + 4 * k)} ${f(tx - tr + 1 * k)} ${f(ty + 3 * k)}" fill="none" stroke="${line(P.teapot)}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);
    back.push(`<path d="M${f(tx + tr - 1 * k)} ${f(ty - 4 * k)}q${f(5 * k)} ${f(0)} ${f(4 * k)} ${f(4.6 * k)}q${f(-0.6 * k)} ${f(3.6 * k)} ${f(-4.4 * k)} ${f(3.8 * k)}" fill="none" stroke="${line(P.teapot)}" stroke-width="${f(1.6 * k)}"/>`);
    back.push(`<ellipse cx="${f(tx)}" cy="${f(ty)}" rx="${f(tr)}" ry="${f(tr * 0.92)}" fill="${pen.paint(P.teapot, 0.36)}" stroke="${line(P.teapot)}" stroke-width=".7"/>`);
    back.push(`<path d="M${f(tx - tr * 0.86)} ${f(ty + 1 * k)}H${f(tx + tr * 0.86)}" stroke="${P.stand}" stroke-width="${f(1.4 * k)}" opacity=".85"/>`);
    back.push(`<ellipse cx="${f(tx)}" cy="${f(ty - tr * 0.82)}" rx="${f(tr * 0.5)}" ry="${f(1.6 * k)}" fill="${pen.paint(tint(P.teapot, 0.2))}" stroke="${line(P.teapot)}" stroke-width=".5"/>`);
    back.push(`<circle cx="${f(tx)}" cy="${f(ty - tr * 0.82 - 2 * k)}" r="${f(1.4 * k)}" fill="${pen.paint(P.stand)}" stroke="${line(P.stand)}" stroke-width=".4"/>`);

    // the cake stand: a plate on a stem, cupcakes on it, a smaller plate over them with one more on the top
    const sx = x0 + 37 * k;
    const plateY = base - 6 * k;

    back.push(`<path d="M${f(sx - 1.2 * k)} ${f(base)}V${f(plateY)}M${f(sx + 1.2 * k)} ${f(base)}V${f(plateY)}" stroke="${line(P.stand)}" stroke-width="${f(0.7 * k)}"/>`);
    back.push(`<ellipse cx="${f(sx)}" cy="${f(base - 0.6 * k)}" rx="${f(5 * k)}" ry="${f(1.2 * k)}" fill="${pen.paint(P.stand)}" stroke="${line(P.stand)}" stroke-width=".5"/>`);
    back.push(`<ellipse cx="${f(sx)}" cy="${f(plateY)}" rx="${f(13 * k)}" ry="${f(2 * k)}" fill="${pen.face(P.stand, 0.2, 0.2)}" stroke="${line(P.stand)}" stroke-width=".6"/>`);
    out.push(rimmed(back.join(''), 1.1, 0.45));

    const cakes = [];
    const lower = n === 3 ? [-7, 0, 7] : [-4.5, 4.5];

    lower.slice(0, Math.max(1, n - 1)).forEach((dx, i) => {
        cakes.push(cupcake(pen, sx + dx * k, plateY - 0.4 * k, k, P.frosting[i % P.frosting.length], false, P));
    });

    const upperY = plateY - 13 * k;

    cakes.push(`<path d="M${f(sx)} ${f(plateY - 1 * k)}V${f(upperY)}" stroke="${line(P.stand)}" stroke-width="${f(0.8 * k)}"/>`);
    cakes.push(`<ellipse cx="${f(sx)}" cy="${f(upperY)}" rx="${f(7 * k)}" ry="${f(1.4 * k)}" fill="${pen.face(P.stand, 0.2, 0.2)}" stroke="${line(P.stand)}" stroke-width=".5"/>`);
    cakes.push(cupcake(pen, sx, upperY - 0.3 * k, k, P.frosting[(n - 1) % P.frosting.length], true, P));
    out.push(rimmed(cakes.join(''), 1, 0.45));

    // the teacups: the first at the back right with its curl of steam, drawn once and still; the rest in front
    const cups = [[x1 - 8 * k, base], [x1 - 17 * k, base + 2.4 * k], [x1 - 9 * k, base + 3 * k]].slice(0, n);
    const [cx0, cy0] = cups[0];
    const steam = `M${f(cx0 - 0.6 * k)} ${f(cy0 - 6.6 * k)}c${f(-2.4 * k)} ${f(-2.2 * k)} ${f(2.4 * k)} ${f(-3.4 * k)} ${f(0.2 * k)} ${f(-5.8 * k)}`
        + `c${f(-2 * k)} ${f(-2 * k)} ${f(2 * k)} ${f(-3 * k)} ${f(0.6 * k)} ${f(-5.2 * k)}`;

    out.push(`<path d="${steam}" fill="none" stroke="${shade(RULES.highlight, 0.3)}" stroke-width="${f(1.8 * k)}" stroke-linecap="round" opacity=".35"/>`
        + `<path d="${steam}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(1.05 * k)}" stroke-linecap="round"/>`);

    for (const [ux, uy] of cups) {
        out.push(rimmed(teacup(pen, ux, uy, k, P), 1, 0.45));
    }

    return out.join('');
}

// ── a desk's furniture set ────────────────────────────────────────────────────────────────────

const deskKey = (installId, seatId) => `${installId}/${seatId}`;

/** The chair's rect (§ 10.6's table): 54 × 64. */
const CHAIR = Object.freeze({ w: 54, h: 64 });

/**
 * THE CHAIR's BACK, behind the creature, at every desk with art: a spindle-back — a curved top rail over five turned
 * spindles and a seat rail — its paint seeded from the desk's key. With nobody in it it is § 7.1's empty chair; the
 * drawing itself carries no fact.
 *
 * @param {{install_id: string, seat_id: string}} input the desk's key
 */
export function chair({ install_id: installId, seat_id: seatId }) {
    const pen = new Pen();
    const c = pick(deskKey(installId, seatId), 'theme:chair', PALETTE.chair.colours);
    const cx = CHAIR.w / 2;
    const m = [];

    // the spindles, then the seat rail, then the curved top rail over them
    for (let i = -2; i <= 2; i++) {
        const x = cx + i * 8.5;

        m.push(`<path d="${rr(x - 1.8, 9, 3.6, 36, 1.8)}" fill="${pen.face(c, 0.22, 0.16)}" stroke="${line(c)}" stroke-width=".6"/>`);
        m.push(`<ellipse cx="${f(x)}" cy="27" rx="2.4" ry="2.2" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".5"/>`);
    }

    m.push(`<path d="${rr(cx - 24, 42, 48, 7, 3.5)}" fill="${pen.face(c, 0.25, 0.15)}" stroke="${line(c)}" stroke-width=".8"/>`);
    m.push(`<path d="M${cx - 25} 13Q${cx} -1 ${cx + 25} 13L${cx + 24} 18Q${cx} 6 ${cx - 24} 18Z" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".9"/>`);
    m.push(`<path d="M${cx - 16} 9Q${cx} 2 ${cx + 16} 9" stroke="${tint(c, 0.5)}" stroke-width="1.4" fill="none" stroke-linecap="round" opacity=".7"/>`);

    for (const x of [cx - 24, cx + 24]) {
        m.push(`<path d="${rr(x - 2.6, 13, 5.2, 37, 2.6)}" fill="${pen.face(c, 0.2, 0.18)}" stroke="${line(c)}" stroke-width=".7"/>`);
    }

    return doc(pen, CHAIR.w, CHAIR.h, rimmed(m.join('')));
}

/** The desk's rect: 180 × 60. */
const DESK = Object.freeze({ w: 180, h: 60 });

/**
 * THE DESK, SEEN FROM ITS FAR SIDE (§ 10.4's projection, item 2): a birch top sliver over a chalk-white painted back —
 * one modesty panel of beadboard between two slim corner posts, a moulding under the top, little bun feet — no
 * drawers, no handles, no knee-hole.
 *
 * Handed the desk's key like every piece of the set; the back is the same at every desk, so it draws nothing from it.
 */
export function desk() {
    const pen = new Pen();
    const P = PALETTE.desk;
    const { w, h } = DESK;
    const top = 12;
    const body = h - top - 7;
    const s = [];

    s.push(shadow(pen, w / 2 + 6, h - 1, w * 0.56, 4, 0.34));
    // the bun feet, under the body
    for (const fx of [12, w - 12]) {
        s.push(`<ellipse cx="${fx}" cy="${h - 4}" rx="5" ry="3.4" fill="${pen.paint(P.top, 0.3)}" stroke="${line(P.top)}" stroke-width=".6"/>`);
    }

    s.push(`<path d="${rr(5, top, w - 10, body, 2)}" fill="${pen.face(P.paint, 0.1, 0.16)}" stroke="${line(P.paint)}" stroke-width=".9"/>`);
    // the beadboard back panel: boards with their grooves, under a moulding
    for (let x = 18; x < w - 16; x += 8) {
        s.push(`<path d="M${x} ${top + 7}V${top + body - 4}" stroke="${shade(P.paint, 0.18)}" stroke-width=".9" opacity=".6"/><path d="M${x + 1} ${top + 7}V${top + body - 4}" stroke="${RULES.highlight}" stroke-width=".7" opacity=".7"/>`);
    }

    s.push(`<path d="M8 ${top + 4.5}H${w - 8}" stroke="${shade(P.paint, 0.2)}" stroke-width="1.6" opacity=".55"/>`);
    s.push(`<path d="M8 ${top + body - 3}H${w - 8}" stroke="${shade(P.paint, 0.15)}" stroke-width="1.2" opacity=".5"/>`);
    // the corner posts
    for (const px of [5, w - 12]) {
        s.push(`<path d="${rr(px, top, 7, body, 2)}" fill="${pen.face(P.paint, 0.22, 0.2)}" stroke="${line(P.paint)}" stroke-width=".7"/>`);
    }

    // the birch top, proud of the body, with its lit edge
    s.push(`<path d="${rr(0, 0, w, top + 3, 3.5)}" fill="${pen.face(P.top, 0.28, 0.08)}" stroke="${line(P.top)}" stroke-width=".9"/>`);
    s.push(`<path d="M4 ${top + 1.5}H${w - 4}" stroke="${shade(P.top, 0.3)}" stroke-width="1.2" opacity=".5"/>`);

    return doc(pen, w, h, rimmed(s.join('')));
}

/** The monitor frame's rect: 96 × 46 — the screen inside it, inset 4 px and 31 px tall, is the painter's. */
const MONITOR = Object.freeze({ w: 96, h: 46, bezel: 40 });

/**
 * THE MONITOR's FRAME, facing the viewer (the projection's ruled exception): a pale blue-grey bezel with rounded
 * corners round the screen, on a curved arm rising from an oval foot, all inside its rect. No power light: the desk's
 * lit state is the screen's to say (§ 10.6 item 3).
 *
 * Handed the desk's key like every piece of the set, and draws nothing from it.
 */
export function monitorFrame() {
    const pen = new Pen();
    const P = PALETTE['monitor-frame'];
    const { w, h, bezel } = MONITOR;
    const c = w / 2;
    const s = [];

    s.push(`<path d="M${c + 6} ${h - 3}Q${c + 14} ${h - 8} ${c + 4} ${bezel - 3}" fill="none" stroke="${line(P.arm)}" stroke-width="4.2" stroke-linecap="round"/><path d="M${c + 6} ${h - 3}Q${c + 14} ${h - 8} ${c + 4} ${bezel - 3}" fill="none" stroke="${tint(P.arm, 0.25)}" stroke-width="2.4" stroke-linecap="round"/>`);
    s.push(`<ellipse cx="${c + 4}" cy="${h - 2.4}" rx="15" ry="2.2" fill="${pen.paint(P.arm)}" stroke="${line(P.arm)}" stroke-width=".7"/>`);
    s.push(`<path d="${rr(0.5, 0.5, w - 1, bezel - 1, 5)}" fill="${pen.paint(P.bezel, 0.35)}" stroke="${line(P.bezel)}" stroke-width=".9"/>`);
    s.push(`<path d="M6 ${bezel - 2.2}H${w - 6}" stroke="${shade(P.bezel, 0.12)}" stroke-width="1" opacity=".6"/>`);

    return doc(pen, w, h, rimmed(s.join('')));
}

/** The desk props' rect: 68 × 50, the desk's left third; the desk's top lies 42 px down it. */
const PROPS = Object.freeze({ w: 68, h: 50, top: 42 });

/**
 * THE DESK PROPS, on the desk's left third: a short stack of books with a cup of pencils on it, and beside them one of a
 * goldfish bowl, a little cactus in a clay pot or a teacup on its saucer — the choice and the colours seeded per desk.
 * Nothing on them moves and nothing lights.
 *
 * @param {{install_id: string, seat_id: string}} input the desk's key
 */
export function deskProps({ install_id: installId, seat_id: seatId }) {
    const pen = new Pen();
    const key = deskKey(installId, seatId);
    const P = PALETTE['desk-props'];
    const next = stream(key, 'theme:props');
    const y = PROPS.top;
    const s = [];
    const m = [];

    // the stack of books, and the pencil cup on it
    let by = y;
    const layers = 2 + (draw(key, 'theme:book-stack') % 2);

    for (let i = 0; i < layers; i++) {
        const c = P.books[(draw(key, `theme:book:${i}`)) % P.books.length];
        const bw = 26 - i * 2 + next() * 3;
        const bx = 6 + (next() - 0.5) * 3;

        m.push(`<path d="${rr(bx, by - 5, bw, 5, 1.4)}" fill="${pen.face(c, 0.22, 0.14)}" stroke="${line(c)}" stroke-width=".55"/>`);
        m.push(`<path d="M${f(bx + bw - 3)} ${f(by - 4)}v3" stroke="${tint(c, 0.6)}" stroke-width="1.1"/>`);
        by -= 5;
    }

    const px = 17;

    P.pencils.forEach((c, i) => {
        const a = (i - 1) * 12;

        m.push(`<path transform="rotate(${a} ${px} ${f(by - 4)})" d="M${f(px - 0.9)} ${f(by - 4)}V${f(by - 17)}l.9 -2.2l.9 2.2V${f(by - 4)}Z" fill="${c}" stroke="${line(c)}" stroke-width=".4"/>`);
    });

    m.push(`<path d="M${px - 5} ${f(by - 10)}H${px + 5}L${px + 4.2} ${f(by)}H${px - 4.2}Z" fill="${pen.face(P.cup, 0.2, 0.2)}" stroke="${line(P.cup)}" stroke-width=".6"/>`);
    s.push(rimmed(m.join('')));

    const what = draw(key, 'theme:desk-prop') % 3;
    const ox = 46;

    if (what === 0) {
        // a goldfish bowl: the glass, the water, one still fish, a pebble floor
        s.push(rimmed(`<circle cx="${ox}" cy="${y - 9}" r="9" fill="${P.glass}" fill-opacity=".85" stroke="${line(P.glass)}" stroke-width=".7"/>`
            + `<path d="M${ox - 8.4} ${y - 8}A8.6 8.6 0 0 0 ${ox + 8.4} ${y - 8}Z" fill="${P.water}" opacity=".8"/>`
            + `<ellipse cx="${ox + 1}" cy="${y - 7}" rx="3" ry="1.9" fill="${pen.paint(P.fish, 0.35)}"/><path d="M${ox - 2} ${y - 7}l-2.4 -1.8v3.6z" fill="${P.fish}"/>`
            + `<path d="${rr(ox - 5.5, y - 18.6, 11, 2.4, 1.2)}" fill="${P.glass}" stroke="${line(P.glass)}" stroke-width=".5"/>`)
            + `<path d="M${ox - 5} ${y - 14}q-1.6 3 -.6 6" stroke="${RULES.highlight}" stroke-width="1.2" fill="none" opacity=".8" stroke-linecap="round"/>`);
    } else if (what === 1) {
        // a little cactus in a clay pot
        s.push(rimmed(`<path d="${rr(ox - 3.6, y - 20, 7.2, 14, 3.6)}" fill="${pen.paint(P.cactus, 0.3)}" stroke="${line(P.cactus)}" stroke-width=".6"/>`
            + `<path d="${rr(ox - 9, y - 15, 5, 8, 2.5)}" fill="${pen.paint(P.cactus, 0.3)}" stroke="${line(P.cactus)}" stroke-width=".5"/>`
            + `<path d="M${ox} ${y - 18}v10" stroke="${tint(P.cactus, 0.4)}" stroke-width=".6" opacity=".7"/>`
            + `<path d="M${ox - 6} ${y - 7}H${ox + 6}L${ox + 4.6} ${y}H${ox - 4.6}Z" fill="${pen.face(P.pot, 0.2, 0.2)}" stroke="${line(P.pot)}" stroke-width=".6"/>`
            + `<path d="${rr(ox - 6.8, y - 8.4, 13.6, 2.6, 1.2)}" fill="${pen.face(tint(P.pot, 0.1))}" stroke="${line(P.pot)}" stroke-width=".5"/>`));
    } else {
        // a teacup on its saucer, a curl of steam over it drawn once
        s.push(rimmed(`<ellipse cx="${ox}" cy="${y - 0.8}" rx="8" ry="1.8" fill="${pen.face(P.saucer, 0.2, 0.2)}" stroke="${line(P.saucer)}" stroke-width=".5"/>`
            + `<path d="M${ox + 4.6} ${y - 7}q3.4 .2 3 2.8t-3 2" fill="none" stroke="${line(P.saucer)}" stroke-width="1.1"/>`
            + `<path d="M${ox - 5.6} ${y - 8}H${ox + 5.6}Q${ox + 5} ${y - 1.6} ${ox} ${y - 1.6}Q${ox - 5} ${y - 1.6} ${ox - 5.6} ${y - 8}Z" fill="${pen.face(P.saucer, 0.2, 0.22)}" stroke="${line(P.saucer)}" stroke-width=".55"/>`
            + `<ellipse cx="${ox}" cy="${y - 8}" rx="5.6" ry="1.3" fill="${P.tea}"/>`)
            + `<path d="M${ox - 1} ${y - 11}q-2 -3 .6 -5.4t0 -5" stroke="${RULES.highlight}" stroke-width="1.1" fill="none" opacity=".6" stroke-linecap="round"/>`);
    }

    return doc(pen, PROPS.w, PROPS.h, s.join(''));
}

/**
 * The side seating's rect (its foot line its bottom edge) and the seats' rows: § 12's seats, one under each intern's
 * place on the interns' rows `desk-layout.js` lays (`stoolSlots()`), stated here because a theme is handed the seat
 * count and nothing else (FLOOR.md § 10.6 item 2); `tools/floor-themes/selftest.mjs` holds every theme's row to the
 * layout's own slots and its `foot_lift` to the chibi frame's foot line, so an intern sits ON its cushion.
 */
const TABLE = Object.freeze({ w: 228, h: 98, seat_x: 4, seat_pitch: 41, seat_w: 35, per_row: 4, row_dy: 56, foot_lift: 1.3 });

/**
 * THE SIDE SEATING, at every desk with art — a slatted GARDEN BENCH per row of interns: under and behind each row, as
 * long as that row's places, with a low slatted back rising to under the interns' chins, a striped seat cushion a place
 * whose top ends at the interns' feet, a turned end post with a round finial at each end and plain legs; the back row's
 * first, so the front bench is drawn over it. As many places as it is handed — so an empty desk shows its front bench —
 * and a potted hydrangea beside the front bench where the rect leaves room.
 *
 * @param {{install_id: string, seat_id: string, seats: number}} input the desk's key and the seat count
 */
export function sideTable({ install_id: installId, seat_id: seatId, seats }) {
    finite(seats);

    const pen = new Pen();
    const key = deskKey(installId, seatId);
    const P = PALETTE['side-table'];
    const c = pick(key, 'theme:bench', P.bench);
    const cushion = pick(key, 'theme:bench-cushion', P.cushion);
    const rows = Math.ceil(seats / TABLE.per_row);
    const gap = TABLE.seat_pitch - TABLE.seat_w;
    const s = [];
    let frontEnd = 0;

    for (let row = rows - 1; row >= 0; row--) {
        const n = Math.min(TABLE.per_row, seats - row * TABLE.per_row);
        const foot = TABLE.h - row * TABLE.row_dy;
        const seat = foot - TABLE.foot_lift;
        const x0 = TABLE.seat_x - 1;
        const w = (n - 1) * TABLE.seat_pitch + TABLE.seat_w + 2;
        const m = [];

        // the slatted back, low, behind the interns: a top rail over upright slats
        for (let sx = x0 + 5; sx < x0 + w - 6; sx += 7) {
            m.push(`<path d="${rr(sx, foot - 23, 4, 16, 1.2)}" fill="${pen.face(c, 0.2, 0.2)}" stroke="${line(c)}" stroke-width=".55"/>`);
        }

        m.push(`<path d="${rr(x0 + 1, foot - 26, w - 2, 5, 2.5)}" fill="${pen.face(c, 0.28, 0.14)}" stroke="${line(c)}" stroke-width=".7"/>`);

        // the legs
        for (const lx of [x0 + 3, x0 + w - 6]) {
            m.push(`<path d="${rr(lx, foot - 5, 3, 5, 1)}" fill="${pen.face(shade(c, 0.12))}" stroke="${line(c)}" stroke-width=".5"/>`);
        }

        // one striped cushion a place, its top ending at the interns' feet and its front at the foot line
        for (let i = 0; i < n; i++) {
            const left = i === 0 ? x0 + 1 : TABLE.seat_x + i * TABLE.seat_pitch - gap / 2;
            const right = i === n - 1 ? x0 + w - 1 : TABLE.seat_x + i * TABLE.seat_pitch + TABLE.seat_w + gap / 2;
            const stripes = [];

            for (let sx = left + 3; sx < right - 2; sx += 6) {
                stripes.push(`<path d="M${f(sx)} ${f(seat - 7.5)}v6.5" stroke="${P.stripe}" stroke-width="1.6" opacity=".7"/>`);
            }

            m.push(`<path d="${rr(left, seat - 8.5, right - left, 8.5, 3)}" fill="${pen.face(cushion, 0.2, 0.12)}" stroke="${line(cushion)}" stroke-width=".7"/>${stripes.join('')}`);
            m.push(`<path d="${rr(left + 0.5, seat - 1.5, right - left - 1, foot - seat + 1.5, 1)}" fill="${pen.face(shade(c, 0.1))}"/>`);
        }

        // the arms at each end: a post under a flat, rounded arm rest
        for (const ax of [x0 - 3, x0 + w - 5]) {
            m.push(`<path d="${rr(ax + 1.5, foot - 16, 5, 14, 1.5)}" fill="${pen.face(c, 0.22, 0.2)}" stroke="${line(c)}" stroke-width=".7"/>`);
            m.push(`<path d="${rr(ax - 1, foot - 19, 10, 4.5, 2.2)}" fill="${pen.face(c, 0.3, 0.14)}" stroke="${line(c)}" stroke-width=".7"/>`);
        }

        s.push(shadow(pen, x0 + w / 2, foot - 0.5, w * 0.52, 3, 0.24) + rimmed(m.join('')));

        if (row === 0) {
            frontEnd = x0 + w;
        }
    }

    // a potted hydrangea beside the front bench, where the rect leaves room
    if (frontEnd + 30 <= TABLE.w) {
        const x = frontEnd + 16;
        const y = TABLE.h - 2;
        const bloom = pick(key, 'theme:hydrangea', P.blooms);
        const next = stream(key, 'theme:hydrangea-heads');
        const m = [];

        for (const [dx, dy] of [[-7, -2], [7, -4], [0, -8]]) {
            m.push(`<path transform="translate(${f(x + dx * 0.6)} ${f(y - 14)}) rotate(${f(dx * 7)})" d="${leafPath(12, 5)}" fill="${pen.paint(P.leaf)}" stroke="${line(P.leaf)}" stroke-width=".5"/>`);
            const hx = x + dx;
            const hy = y - 22 + dy;

            for (let i = 0; i < 7; i++) {
                const a = (i / 7) * Math.PI * 2;

                m.push(`<circle cx="${f(hx + Math.cos(a) * 4.4 + (next() - 0.5))}" cy="${f(hy + Math.sin(a) * 3.8)}" r="2.6" fill="${pen.paint(i % 2 ? bloom : tint(bloom, 0.2), 0.3)}" stroke="${line(bloom)}" stroke-width=".4"/>`);
            }

            m.push(`<circle cx="${f(hx)}" cy="${f(hy)}" r="2.6" fill="${pen.paint(tint(bloom, 0.3), 0.3)}"/>`);
        }

        m.push(`<path d="M${x - 8} ${y - 13}H${x + 8}L${x + 6.4} ${y}H${x - 6.4}Z" fill="${pen.face(P.pot, 0.2, 0.2)}" stroke="${line(P.pot)}" stroke-width=".7"/>`);
        m.push(`<path d="${rr(x - 9, y - 15, 18, 3.6, 1.6)}" fill="${pen.face(P.pot)}" stroke="${line(P.pot)}" stroke-width=".6"/>`);
        s.push(shadow(pen, x + 2, y, 12, 2.6, 0.28) + rimmed(m.join('')));
    }

    return doc(pen, TABLE.w, TABLE.h, s.join(''));
}

/**
 * card#12844's STANDING KINDS, in the cottage's paint: each at its tile's cell rect, its foot on the cell's floor line,
 * sized by the cell. None carries a light, a display, a word or a numeral, and nothing on them moves.
 *
 * - `water-cooler`: a big glass jug of water upside down on a painted cabinet, a brass tap on its front;
 * - `filing-cabinet`: a painted chest of filing drawers, as many as its cell is tall for, each with a brass cup handle
 *   and an empty label frame;
 * - `copier`: a cream copier with a cornflower lid and a paper tray holding one blank sheet;
 * - `coat-rack`: a wooden peg stand on three feet, a straw sun hat, a striped scarf and a coral raincoat on its pegs;
 * - `whiteboard`: a framed chalkboard on a wooden easel, a chalk doodle of a flower, a sun and a wave on it, a chalk
 *   ledge with a stick of chalk;
 * - `aquarium`: a glass tank on a painted cabinet, its water, sand and pebbles, two strands of weed, a shell and two
 *   still fish;
 * - `bean-bag`: a slouched bean bag, its colour seeded per cell, with a gingham patch;
 * - `globe`: a globe of soft blue seas and sage lands in a brass meridian on a turned wooden stand.
 */
function moreScenery(pen, kind, key, w, h, cx, foot, next) {
    const s = [];

    if (kind === 'water-cooler') {
        const P = PALETTE['water-cooler'];
        const k = Math.min(w / 40, h / 120);
        const m = [];

        m.push(`<path d="${rr(cx - 15 * k, foot - 64 * k, 30 * k, 64 * k, 3 * k)}" fill="${pen.face(P.cabinet, 0.2, 0.18)}" stroke="${line(P.cabinet)}" stroke-width=".8"/>`);
        m.push(`<path d="${rr(cx - 11 * k, foot - 44 * k, 22 * k, 36 * k, 2 * k)}" fill="none" stroke="${shade(P.cabinet, 0.18)}" stroke-width=".8" opacity=".6"/>`);
        m.push(`<path d="M${f(cx - 2 * k)} ${f(foot - 56 * k)}h${f(4 * k)}v${f(5 * k)}h${f(-4 * k)}Z" fill="${pen.face(P.tap, 0.3, 0.2)}" stroke="${line(P.tap)}" stroke-width=".5"/>`);
        // the jug: neck down into the cabinet, the body above
        m.push(`<path d="M${f(cx - 4 * k)} ${f(foot - 64 * k)}V${f(foot - 70 * k)}Q${f(cx - 16 * k)} ${f(foot - 72 * k)} ${f(cx - 16 * k)} ${f(foot - 84 * k)}V${f(foot - 108 * k)}Q${f(cx - 16 * k)} ${f(foot - 116 * k)} ${f(cx)} ${f(foot - 116 * k)}Q${f(cx + 16 * k)} ${f(foot - 116 * k)} ${f(cx + 16 * k)} ${f(foot - 108 * k)}V${f(foot - 84 * k)}Q${f(cx + 16 * k)} ${f(foot - 72 * k)} ${f(cx + 4 * k)} ${f(foot - 70 * k)}V${f(foot - 64 * k)}Z" fill="${P.glass}" fill-opacity=".9" stroke="${line(P.glass)}" stroke-width=".8"/>`);
        m.push(`<path d="M${f(cx - 15 * k)} ${f(foot - 98 * k)}H${f(cx + 15 * k)}V${f(foot - 84 * k)}Q${f(cx + 15 * k)} ${f(foot - 73 * k)} ${f(cx + 4 * k)} ${f(foot - 71 * k)}V${f(foot - 65 * k)}H${f(cx - 4 * k)}V${f(foot - 71 * k)}Q${f(cx - 15 * k)} ${f(foot - 73 * k)} ${f(cx - 15 * k)} ${f(foot - 84 * k)}Z" fill="${pen.v([[0, tint(P.water, 0.2)], [1, P.water]])}" opacity=".85"/>`);
        m.push(`<path d="M${f(cx - 16 * k)} ${f(foot - 96 * k)}H${f(cx + 16 * k)}M${f(cx - 16 * k)} ${f(foot - 88 * k)}H${f(cx + 16 * k)}" stroke="${shade(P.glass, 0.15)}" stroke-width=".7" opacity=".5"/>`);
        m.push(`<path d="M${f(cx - 10 * k)} ${f(foot - 108 * k)}V${f(foot - 86 * k)}" stroke="${RULES.highlight}" stroke-width="${f(2 * k)}" stroke-linecap="round" opacity=".7"/>`);
        s.push(shadow(pen, cx + 2, foot, 18 * k, 3 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'filing-cabinet') {
        const P = PALETTE['filing-cabinet'];
        const bw = Math.max(8, w - 6);
        const x = (w - bw) / 2;
        const bh = Math.max(12, h - 3);
        const drawers = Math.max(1, Math.round((bh - 10) / 26));
        const dh = (bh - 10) / drawers;
        const m = [`<path d="${rr(x, foot - bh, bw, bh, 3)}" fill="${pen.face(P.paint, 0.2, 0.2)}" stroke="${line(P.paint)}" stroke-width=".9"/>`];

        m.push(`<path d="${rr(x - 1.5, foot - bh - 1, bw + 3, 5, 2)}" fill="${pen.face(tint(P.paint, 0.15), 0.3, 0.1)}" stroke="${line(P.paint)}" stroke-width=".7"/>`);

        for (let i = 0; i < drawers; i++) {
            const dy = foot - bh + 6 + i * dh;

            m.push(`<path d="${rr(x + 4, dy, bw - 8, dh - 3, 2)}" fill="${pen.face(P.paint, 0.25, 0.14)}" stroke="${line(P.paint)}" stroke-width=".7"/>`);
            m.push(`<path d="${rr(cx - 7, dy + 4, 14, 6, 1)}" fill="${P.label}" stroke="${line(P.handle)}" stroke-width=".7"/>`);
            m.push(`<path d="M${f(cx - 5)} ${f(dy + dh - 9)}a5 3 0 0 0 10 0Z" fill="${pen.face(P.handle, 0.35, 0.2)}" stroke="${line(P.handle)}" stroke-width=".6"/>`);
        }

        s.push(shadow(pen, cx + 3, foot, bw * 0.6, 4, 0.3) + rimmed(m.join('')));
    } else if (kind === 'copier') {
        const P = PALETTE.copier;
        const k = Math.min(w / 80, h / 96);
        const bw = 72 * k;
        const x = cx - bw / 2;
        const m = [];

        m.push(`<path d="${rr(x, foot - 62 * k, bw, 62 * k, 5 * k)}" fill="${pen.face(P.body, 0.22, 0.18)}" stroke="${line(P.body)}" stroke-width=".9"/>`);
        m.push(`<path d="${rr(x + 5 * k, foot - 36 * k, bw - 10 * k, 12 * k, 2 * k)}" fill="none" stroke="${shade(P.body, 0.18)}" stroke-width=".8" opacity=".6"/>`);
        m.push(`<path d="${rr(x + 5 * k, foot - 20 * k, bw - 10 * k, 12 * k, 2 * k)}" fill="none" stroke="${shade(P.body, 0.18)}" stroke-width=".8" opacity=".6"/>`);
        m.push(`<path d="M${f(cx - 8 * k)} ${f(foot - 30 * k)}h${f(16 * k)}M${f(cx - 8 * k)} ${f(foot - 14 * k)}h${f(16 * k)}" stroke="${line(P.body)}" stroke-width="${f(1.6 * k)}" stroke-linecap="round"/>`);
        // the paper tray at its side, one blank sheet in it
        m.push(`<path d="M${f(x + bw - 2 * k)} ${f(foot - 52 * k)}l${f(9 * k)} ${f(-3 * k)}v${f(6 * k)}l${f(-9 * k)} ${f(2 * k)}Z" fill="${pen.face(P.lid, 0.2, 0.2)}" stroke="${line(P.lid)}" stroke-width=".6"/>`);
        m.push(`<path d="M${f(x + bw)} ${f(foot - 54 * k)}l${f(7 * k)} ${f(-3 * k)}v${f(3 * k)}l${f(-7 * k)} ${f(2.4 * k)}Z" fill="${P.paper}" stroke="${line(P.paper)}" stroke-width=".4"/>`);
        // the cornflower lid over the glass
        m.push(`<path d="${rr(x - 2 * k, foot - 70 * k, bw + 4 * k, 10 * k, 3 * k)}" fill="${pen.face(P.lid, 0.28, 0.14)}" stroke="${line(P.lid)}" stroke-width=".8"/>`);
        m.push(`<path d="M${f(x + 6 * k)} ${f(foot - 66 * k)}H${f(x + bw - 6 * k)}" stroke="${tint(P.lid, 0.45)}" stroke-width="${f(1.2 * k)}" stroke-linecap="round" opacity=".7"/>`);
        s.push(shadow(pen, cx + 3, foot, bw * 0.6, 4 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'coat-rack') {
        const P = PALETTE['coat-rack'];
        const k = Math.min(w / 40, h / 136);
        const top = foot - 128 * k;
        const m = [];

        for (const dx of [-13, 0, 13]) {
            m.push(`<path d="M${f(cx)} ${f(foot - 16 * k)}L${f(cx + dx * k)} ${f(foot)}" stroke="${line(P.wood)}" stroke-width="${f(3 * k)}" stroke-linecap="round"/>`);
        }

        m.push(`<path d="${rr(cx - 2.2 * k, top, 4.4 * k, 128 * k - 14 * k, 2 * k)}" fill="${pen.face(P.wood, 0.25, 0.2)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
        m.push(`<circle cx="${f(cx)}" cy="${f(top - 1.5 * k)}" r="${f(3.4 * k)}" fill="${pen.paint(P.wood, 0.3)}" stroke="${line(P.wood)}" stroke-width=".6"/>`);

        for (const side of [-1, 1]) {
            m.push(`<path d="M${f(cx)} ${f(top + 10 * k)}l${f(side * 9 * k)} ${f(-5 * k)}" stroke="${line(P.wood)}" stroke-width="${f(2.2 * k)}" stroke-linecap="round"/>`);
        }

        // the raincoat hung from the left peg
        m.push(`<path d="M${f(cx - 8 * k)} ${f(top + 6 * k)}Q${f(cx - 15 * k)} ${f(top + 14 * k)} ${f(cx - 16 * k)} ${f(top + 46 * k)}H${f(cx - 1 * k)}Q${f(cx - 2 * k)} ${f(top + 16 * k)} ${f(cx - 8 * k)} ${f(top + 6 * k)}Z" fill="${pen.face(P.coat, 0.25, 0.18)}" stroke="${line(P.coat)}" stroke-width=".7"/>`);
        m.push(`<path d="M${f(cx - 8 * k)} ${f(top + 14 * k)}V${f(top + 44 * k)}" stroke="${shade(P.coat, 0.2)}" stroke-width=".7" opacity=".7"/>`);
        // the striped scarf from the right peg
        m.push(`<path d="${rr(cx + 6 * k, top + 6 * k, 5 * k, 34 * k, 2 * k)}" fill="${pen.face(P.scarf, 0.2, 0.16)}" stroke="${line(P.scarf)}" stroke-width=".6"/>`);

        for (let i = 1; i < 5; i++) {
            m.push(`<path d="M${f(cx + 6 * k)} ${f(top + (6 + i * 7) * k)}h${f(5 * k)}" stroke="${P.stripe}" stroke-width="${f(1.6 * k)}"/>`);
        }

        // the straw sun hat on the top knob
        m.push(`<ellipse cx="${f(cx)}" cy="${f(top - 1 * k)}" rx="${f(14 * k)}" ry="${f(3 * k)}" fill="${pen.paint(P.hat, 0.3)}" stroke="${line(P.hat)}" stroke-width=".6"/>`);
        m.push(`<path d="M${f(cx - 7 * k)} ${f(top - 1 * k)}Q${f(cx - 7 * k)} ${f(top - 10 * k)} ${f(cx)} ${f(top - 10 * k)}Q${f(cx + 7 * k)} ${f(top - 10 * k)} ${f(cx + 7 * k)} ${f(top - 1 * k)}Z" fill="${pen.paint(P.hat, 0.3)}" stroke="${line(P.hat)}" stroke-width=".6"/>`);
        m.push(`<path d="M${f(cx - 7 * k)} ${f(top - 3 * k)}H${f(cx + 7 * k)}" stroke="${P.coat}" stroke-width="${f(1.8 * k)}"/>`);
        s.push(shadow(pen, cx + 2, foot, 15 * k, 3 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'whiteboard') {
        const P = PALETTE.whiteboard;
        const k = Math.min(w / 80, h / 128);
        const bw = 70 * k;
        const bx = cx - bw / 2;
        const by = foot - 120 * k;
        const bh = 56 * k;
        const m = [];

        // the easel's legs, the back one first
        m.push(`<path d="M${f(cx)} ${f(by - 4 * k)}L${f(cx)} ${f(foot - 2 * k)}" stroke="${shade(P.wood, 0.2)}" stroke-width="${f(3 * k)}" stroke-linecap="round"/>`);

        for (const side of [-1, 1]) {
            m.push(`<path d="M${f(cx + side * 6 * k)} ${f(by - 6 * k)}L${f(cx + side * 30 * k)} ${f(foot)}" stroke="${line(P.wood)}" stroke-width="${f(3.6 * k)}" stroke-linecap="round"/><path d="M${f(cx + side * 6 * k)} ${f(by - 6 * k)}L${f(cx + side * 30 * k)} ${f(foot)}" stroke="${tint(P.wood, 0.2)}" stroke-width="${f(1.8 * k)}" stroke-linecap="round"/>`);
        }

        // the framed board, its doodle, the ledge and its chalk
        m.push(`<path d="${rr(bx, by, bw, bh, 3 * k)}" fill="${pen.face(P.wood, 0.25, 0.2)}" stroke="${line(P.wood)}" stroke-width=".8"/>`);
        m.push(`<path d="${rr(bx + 4 * k, by + 4 * k, bw - 8 * k, bh - 8 * k, 1.5 * k)}" fill="${pen.v([[0, tint(P.board, 0.08)], [1, P.board]])}"/>`);

        const chalk = (c, d) => `<path d="${d}" fill="none" stroke="${c}" stroke-width="${f(1.3 * k)}" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>`;
        const fx = bx + 18 * k;
        const fy = by + 22 * k;

        m.push(chalk(P.chalk[1], `M${f(fx)} ${f(fy + 4 * k)}V${f(fy + 22 * k)}`));

        for (let i = 0; i < 5; i++) {
            const a = (i / 5) * Math.PI * 2;

            m.push(chalk(P.chalk[0], `M${f(fx)} ${f(fy)}m${f(Math.cos(a) * 2 * k)} ${f(Math.sin(a) * 2 * k)}a${f(3 * k)} ${f(3 * k)} 0 1 1 ${f(Math.cos(a + 0.6) * 3 * k)} ${f(Math.sin(a + 0.6) * 3 * k)}`));
        }

        const sx = bx + bw - 18 * k;
        const sy = by + 16 * k;

        m.push(chalk(P.chalk[2], `M${f(sx + 5 * k)} ${f(sy)}a${f(5 * k)} ${f(5 * k)} 0 1 1 ${f(-10 * k)} 0a${f(5 * k)} ${f(5 * k)} 0 1 1 ${f(10 * k)} 0`));

        for (let i = 0; i < 8; i++) {
            const a = (i / 8) * Math.PI * 2;

            m.push(chalk(P.chalk[2], `M${f(sx + Math.cos(a) * 7.5 * k)} ${f(sy + Math.sin(a) * 7.5 * k)}l${f(Math.cos(a) * 3 * k)} ${f(Math.sin(a) * 3 * k)}`));
        }

        m.push(chalk(P.chalk[3], `M${f(bx + 34 * k)} ${f(by + 40 * k)}q${f(4 * k)} ${f(-4 * k)} ${f(8 * k)} 0t${f(8 * k)} 0t${f(8 * k)} 0`));
        m.push(`<path d="${rr(bx + 2 * k, by + bh - 1 * k, bw - 4 * k, 4 * k, 1.5 * k)}" fill="${pen.face(P.wood, 0.3, 0.15)}" stroke="${line(P.wood)}" stroke-width=".6"/>`);
        m.push(`<path d="${rr(bx + bw - 22 * k, by + bh - 3 * k, 8 * k, 2.4 * k, 1.2 * k)}" fill="${P.chalk[0]}"/>`);
        s.push(shadow(pen, cx + 2, foot, 30 * k, 3.4 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'aquarium') {
        const P = PALETTE.aquarium;
        const k = Math.min(w / 80, h / 104);
        const bw = 74 * k;
        const x = cx - bw / 2;
        const cab = 44 * k;
        const tank = 50 * k;
        const ty = foot - cab - tank;
        const m = [];

        m.push(`<path d="${rr(x + 2 * k, foot - cab, bw - 4 * k, cab, 3 * k)}" fill="${pen.face(P.cabinet, 0.2, 0.2)}" stroke="${line(P.cabinet)}" stroke-width=".8"/>`);

        for (const dx of [0, (bw - 4 * k) / 2]) {
            m.push(`<path d="${rr(x + 6 * k + dx, foot - cab + 6 * k, (bw - 4 * k) / 2 - 8 * k, cab - 12 * k, 2 * k)}" fill="none" stroke="${shade(P.cabinet, 0.18)}" stroke-width=".8" opacity=".6"/>`);
        }

        // the tank: its water, its sand, the weed, a shell, two still fish, the glass and its rim
        m.push(`<path d="${rr(x, ty, bw, tank, 2 * k)}" fill="${pen.v([[0, tint(P.water, 0.25)], [1, P.water]])}"/>`);
        m.push(`<path d="M${f(x)} ${f(ty + tank - 7 * k)}Q${f(cx)} ${f(ty + tank - 11 * k)} ${f(x + bw)} ${f(ty + tank - 6 * k)}V${f(ty + tank)}H${f(x)}Z" fill="${pen.face(P.sand, 0.2, 0.15)}"/>`);

        for (const [dx, len] of [[-24, 30], [-18, 22], [22, 34]]) {
            m.push(`<path d="M${f(cx + dx * k)} ${f(ty + tank - 8 * k)}q${f(-4 * k)} ${f(-len * 0.3 * k)} 0 ${f(-len * 0.5 * k)}t0 ${f(-len * 0.5 * k)}" fill="none" stroke="${P.weed}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);
        }

        m.push(`<path d="M${f(cx + 6 * k)} ${f(ty + tank - 8 * k)}q${f(4 * k)} ${f(-8 * k)} ${f(8 * k)} 0Z" fill="${pen.paint(P.shell, 0.3)}" stroke="${line(P.shell)}" stroke-width=".5"/>`);

        for (const [fx, fy, c, dir] of [[cx - 6 * k, ty + 18 * k, P.fish[0], 1], [cx + 12 * k, ty + 30 * k, P.fish[1], -1]]) {
            m.push(`<ellipse cx="${f(fx)}" cy="${f(fy)}" rx="${f(5 * k)}" ry="${f(3.2 * k)}" fill="${pen.paint(c, 0.35)}" stroke="${line(c)}" stroke-width=".5"/>`);
            m.push(`<path d="M${f(fx - dir * 4.6 * k)} ${f(fy)}l${f(-dir * 4 * k)} ${f(-3 * k)}v${f(6 * k)}Z" fill="${c}" stroke="${line(c)}" stroke-width=".5"/>`);
            m.push(`<circle cx="${f(fx + dir * 2.4 * k)}" cy="${f(fy - 0.8 * k)}" r="${f(0.8 * k)}" fill="${RULES.line}"/>`);
        }

        m.push(`<path d="${rr(x, ty, bw, tank, 2 * k)}" fill="none" stroke="${line(P.glass)}" stroke-width="1.1"/>`);
        m.push(`<path d="M${f(x + 5 * k)} ${f(ty + 5 * k)}V${f(ty + tank - 14 * k)}" stroke="${RULES.highlight}" stroke-width="${f(2 * k)}" stroke-linecap="round" opacity=".6"/>`);
        m.push(`<path d="${rr(x - 1.5 * k, ty - 3 * k, bw + 3 * k, 5 * k, 2 * k)}" fill="${pen.face(P.cabinet, 0.3, 0.12)}" stroke="${line(P.cabinet)}" stroke-width=".7"/>`);
        s.push(shadow(pen, cx + 3, foot, bw * 0.6, 4 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'bean-bag') {
        const P = PALETTE['bean-bag'];
        const c = pick(key, 'theme:bean-bag', P.colours);
        const k = Math.min(w / 56, h / 40);
        const m = [];

        m.push(`<path d="M${f(cx - 26 * k)} ${f(foot - 2 * k)}Q${f(cx - 30 * k)} ${f(foot - 22 * k)} ${f(cx - 14 * k)} ${f(foot - 34 * k)}Q${f(cx + 2 * k)} ${f(foot - 42 * k)} ${f(cx + 16 * k)} ${f(foot - 30 * k)}Q${f(cx + 30 * k)} ${f(foot - 18 * k)} ${f(cx + 26 * k)} ${f(foot - 2 * k)}Q${f(cx)} ${f(foot + 2 * k)} ${f(cx - 26 * k)} ${f(foot - 2 * k)}Z" fill="${pen.paint(c, 0.32)}" stroke="${line(c)}" stroke-width=".9"/>`);
        m.push(`<path d="M${f(cx - 16 * k)} ${f(foot - 14 * k)}Q${f(cx)} ${f(foot - 22 * k)} ${f(cx + 16 * k)} ${f(foot - 13 * k)}" stroke="${shade(c, 0.22)}" stroke-width="1.1" fill="none" opacity=".6"/>`);

        const gx = cx + 6 * k;
        const gy = foot - 12 * k;
        const patch = [`<path d="${rr(gx, gy, 10 * k, 8 * k, 1.5 * k)}" fill="${P.patch}" stroke="${line(P.check)}" stroke-width=".6"/>`];

        for (let i = 0; i < 3; i++) {
            patch.push(`<rect x="${f(gx + (1 + i * 3.2) * k)}" y="${f(gy)}" width="${f(1.6 * k)}" height="${f(8 * k)}" fill="${P.check}" opacity=".5"/><rect x="${f(gx)}" y="${f(gy + (1 + i * 2.4) * k)}" width="${f(10 * k)}" height="${f(1.2 * k)}" fill="${P.check}" opacity=".4"/>`);
        }

        m.push(patch.join(''));
        s.push(shadow(pen, cx + 2, foot, 26 * k, 3.4 * k, 0.3) + rimmed(m.join('')));
    } else if (kind === 'globe') {
        const P = PALETTE.globe;
        const k = Math.min(w / 40, h / 88);
        const gy = foot - 58 * k;
        const r = 15 * k;
        const m = [];

        m.push(`<ellipse cx="${f(cx)}" cy="${f(foot - 2 * k)}" rx="${f(12 * k)}" ry="${f(3 * k)}" fill="${pen.paint(P.wood, 0.3)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
        m.push(`<path d="M${f(cx - 2.4 * k)} ${f(foot - 4 * k)}Q${f(cx - 5 * k)} ${f(foot - 18 * k)} ${f(cx - 2 * k)} ${f(gy + r + 6 * k)}H${f(cx + 2 * k)}Q${f(cx + 5 * k)} ${f(foot - 18 * k)} ${f(cx + 2.4 * k)} ${f(foot - 4 * k)}Z" fill="${pen.face(P.wood, 0.25, 0.2)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
        m.push(`<circle cx="${f(cx)}" cy="${f(gy)}" r="${f(r)}" fill="${pen.paint(P.sea, 0.35)}" stroke="${line(P.sea)}" stroke-width=".8"/>`);
        s.push(shadow(pen, cx + 2, foot, 13 * k, 3 * k, 0.3) + rimmed(m.join('')));

        // the lands, seeded per cell, clipped to the sphere — outside the rim, so its clip is defined once — then the
        // brass meridian and the glint over them
        const lands = [];

        for (let i = 0; i < 4; i++) {
            const lx = cx + (next() - 0.5) * r * 1.4;
            const ly = gy + (next() - 0.5) * r * 1.4;

            lands.push(`<ellipse cx="${f(lx)}" cy="${f(ly)}" rx="${f((3 + next() * 4) * k)}" ry="${f((2 + next() * 4) * k)}" transform="rotate(${f(next() * 60 - 30)} ${f(lx)} ${f(ly)})" fill="${P.land}"/>`);
        }

        s.push(`<defs><clipPath id="sphere"><circle cx="${f(cx)}" cy="${f(gy)}" r="${f(r)}"/></clipPath></defs><g clip-path="url(#sphere)">${lands.join('')}</g>`);
        s.push(`<path d="M${f(cx - r - 3 * k)} ${f(gy + 5 * k)}A${f(r + 3 * k)} ${f(r + 3 * k)} 0 1 1 ${f(cx + 4 * k)} ${f(gy + r + 3 * k)}" fill="none" stroke="${P.brass}" stroke-width="${f(1.8 * k)}"/>`);
        s.push(`<path d="M${f(cx - r * 0.5)} ${f(gy - r * 0.55)}a${f(r * 0.6)} ${f(r * 0.6)} 0 0 1 ${f(r * 0.5)} ${f(-r * 0.2)}" stroke="${RULES.highlight}" stroke-width="${f(1.8 * k)}" fill="none" stroke-linecap="round" opacity=".7"/>`);
    }

    return s.join('');
}
