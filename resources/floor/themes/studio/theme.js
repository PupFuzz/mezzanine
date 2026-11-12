// THE HOUSE THEME, `studio` — A2+C — `docs/design/FLOOR.md § 10.6` (card#11046, Appendix B row 22): the
// room the operator chose on 2026-10-07, ported from the seat's approved prototype and not redesigned.
// It DRAWS, as code, every piece of a floor that carries no fact: the band's wall, the window, elevator and
// clock surrounds, each grid's plane (its floor, its light, its accent region, its walls and the landing),
// the standing scenery, and a desk's furniture set (the chair's back, the desk's back, the monitor's frame,
// the desk props, the side seating — a low couch a row — and its seats).
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// ⛔ PURE, TOTAL, NO I/O (§ 10.6 item 1). Every function returns one standalone SVG DOCUMENT for its inputs
// and never throws for an input the scene can hand it; nothing here fetches, and nothing here is assigned
// after load — every table below is frozen, and each document builds its own gradients in a `Pen` made
// for that call. The painter makes the `data:` URI and caches it, keyed on the document's inputs
// (`floor/painter.js`); this module keeps no cache.
//
// ⛔ A THEME TAKES NO FACT AND WRITES NO TEXT (§ 10.6 item 3). The inputs are rects, seeds and the side
// table's seat count — never a seat's state — and no document carries `<text>`, `<tspan>`,
// `<foreignObject>`, `<image>`, `<script>`, a `data:` URI or a reference that leaves the document.
// AT-D3-25's theme half holds all of that (`tools/floor-themes/selftest.mjs`).
//
// ⛔ EVERY COLOUR IS `PALETTE`'s, keyed by the piece that paints it, and § 10.6's house table states the
// same colours — AT-D3-25's drift leg holds the two equal in both directions. The paint rules' three
// colours (`RULES`) mix every shade, highlight and line from them: light from the top-left, a shade leans
// plum, a highlight leans cream, a line is its fill mixed halfway to the line colour.
//
// ⛔ EVERY SEEDED CHOICE IS DRAWN THROUGH THE CHARACTER TREE's `draw(key, field)` (§ 10.4's appearance
// bullet), under field names of its own (`theme:…`), so a room's boards and a desk's chair are the same on
// every load and in every browser. The colour arithmetic, the seeded draws, the `Pen`, the document wrapper and
// the drawing primitives are the themes' shared ones (`../paint.js`, card#12845), handed this theme's paint rules.

import { brushes, doc, draw, f, finite, leafPath, mix, pick, rr, stream, wallRuns } from '../paint.js';
import { pieces3d } from './pieces-3d.js';

/** The paint rules (§ 10.6, the creatures' own): the shade, the highlight and the line colour. */
export const RULES = Object.freeze({ shade: '#3d2b3c', highlight: '#fff6e6', line: '#3a2420' });

/**
 * EVERY COLOUR THIS THEME PAINTS WITH, keyed by the piece that paints it — § 10.6's house table, in the
 * same rows. Every value was taken from the approved prototype.
 */
export const PALETTE = Object.freeze({
    wall: Object.freeze({ top: '#eef0e0', bottom: '#dfe4cc', stripe: '#ffffff', rail: '#f4f1ea', lower_top: '#b7c6a0', lower_bottom: '#a5b58e', panel: '#93a57c', skirting_top: '#8d9b74', skirting_bottom: '#77845f', post: '#9aa982' }),
    window: Object.freeze({ frame: '#f6ecdb', edge: '#e2cfb0', curtain: '#ecc9a2', rod: '#b08a4a', knob: '#d4a34f', pots: Object.freeze(['#e9d8c4', '#6f8fb0', '#d9a54a', '#b9643f']), leaves: Object.freeze(['#7f9f6a', '#6f9a52', '#79a85a']) }),
    elevator: Object.freeze({ frame: '#c9a873', lamp: '#f2b84b' }),
    clock: Object.freeze({ rim: '#b07a4f', face: '#fffaf0' }),
    picture: Object.freeze({ frame: '#b07a4f', sky_top: '#f3d9a8', sky_bottom: '#e7b98f', hill: '#8fa66b' }),
    floor: Object.freeze({ boards: '#d9c7a5' }),
    light: Object.freeze({ lamplight: '#fff3d6' }),
    accent: Object.freeze({ top: '#dcc196', bottom: '#d1b285', warmth: '#e9a35a' }),
    landing: Object.freeze({ top: '#f7f0e0', bottom: '#ebdfc6', rim: '#d9a54a', glow: '#fff4d6' }),
    'wall-run': Object.freeze({ strip: '#9aa982' }),
    desk: Object.freeze({ wood: '#b5794c' }),
    // card#12987: the floor seen through the cab's open doors — the empty desks' tops and edges, the monitors' backs and the
    // partitions, which the cab's painter reads as `--dv-…` custom properties (`floor/door-view.js`). Every theme carries
    // the house theme's keys (the drift leg).
    view: Object.freeze({ desk: '#b5794c', desk_edge: '#8a5a3a', monitor: '#efe2cc', partition: '#b7c6a0', partition_cap: '#8d9b74' }),
    chair: Object.freeze({ colours: Object.freeze(['#c8714f', '#d9a54a', '#8fa66b', '#c98b8f', '#6f9a9a', '#a77c5a']) }),
    'monitor-frame': Object.freeze({ bezel: '#efe2cc' }),
    'desk-props': Object.freeze({
        lamps: Object.freeze(['#c8714f', '#6f9a9a', '#d9a54a', '#8fa66b']),
        pots: Object.freeze(['#e9d8c4', '#c8553d', '#6f8fb0', '#e0a43a', '#7f9f6a', '#d97f8f']),
        leaf: '#79a85a',
        pool: '#fff3d6',
    }),
    'side-table': Object.freeze({
        wood: '#c48d5c',
        couch: Object.freeze(['#c98b8f', '#d9a54a', '#8fa66b', '#6f9a9a', '#c8714f']),
        pots: Object.freeze(['#e9d8c4', '#6f8fb0', '#c8714f', '#d9a54a']),
        leaves: Object.freeze(['#79a85a', '#6b9a52', '#8db55e']),
    }),
    bookcase: Object.freeze({ shelf: '#a8714a', books: Object.freeze(['#c8553d', '#e0a43a', '#6f8fb0', '#7f9f6a', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f']) }),
    plant: Object.freeze({ pots: Object.freeze(['#b9643f', '#d9a54a', '#e9d8c4', '#6f8fb0', '#c8714f']), leaf: '#6f9a52' }),
    'floor-lamp': Object.freeze({ metal: '#b08a4a', shade: '#f2dcae' }),
    armchair: Object.freeze({ cover: '#c8714f', legs: '#8a5a3a' }),
    cushion: Object.freeze({ colours: Object.freeze(['#c98b8f', '#d9a54a']) }),
    'book-pile': Object.freeze({ books: Object.freeze(['#c8553d', '#e0a43a', '#6f8fb0', '#7f9f6a', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f']) }),
    // card#12749: the snack corner — both kinds, `snack-table` and `snack-table-low`, paint from this one piece.
    'snack-table': Object.freeze({
        wood: '#c48d5c',
        cloth: '#f6e7d2',
        box: '#f4a7b9',
        dough: '#d9a066',
        glaze: '#f2d29b',
        chocolate: '#7a4a32',
        icing: '#f7b6c8',
        sprinkles: Object.freeze(['#6f8fb0', '#7f9f6a', '#e0a43a', '#c8553d']),
        maker: '#6f9a9a',
        carafe: '#e7f0ef',
        coffee: '#6b4226',
        lamp: '#f2b84b',
        cup: '#fffaf0',
        band: '#e0a43a',
    }),
    // card#12786: the restroom door — its frame, the pediment and sill, the dark recess the open doorway shows, the sign
    // plate and the slider's rim. The leaf and the field are the painter's, never drawn here.
    'restroom-door': Object.freeze({
        frame: '#c48d5c',
        pediment: '#f6e7d2',
        sill: '#a8714a',
        recess: '#281c27',
        plate: '#fffaf0',
        rim: '#f6e7d2',
    }),
    // card#12844: the office furniture an author can place on any floor — each piece its own row, none with a light.
    'water-cooler': Object.freeze({ body: '#f4ede0', water: '#a9d3e3', cap: '#6f8fb0', tap: '#9aa7ad', recess: '#5b5560', cup: '#fffaf0' }),
    'filing-cabinet': Object.freeze({ body: '#9cc3b2', handle: '#b08a4a', label: '#fffaf0', papers: '#f6e7d2', folder: '#e0a43a', pot: '#d97f8f', leaf: '#79a85a' }),
    copier: Object.freeze({ body: '#e9e4dc', lid: '#d3cdc4', trim: '#6f8fb0', keys: '#a9a39b', paper: '#fffaf0', feet: '#5b5560' }),
    'coat-rack': Object.freeze({ wood: '#a8714a', coats: Object.freeze(['#c8714f', '#6f9a9a', '#d9a54a', '#8fa66b']), scarf: Object.freeze(['#c98b8f', '#f2e6cf', '#94627e']), hat: '#94627e' }),
    whiteboard: Object.freeze({ wood: '#b07a4f', frame: '#c9c3bb', board: '#fbfaf6', ink: Object.freeze(['#6f8fb0', '#c8553d', '#7f9f6a']), notes: Object.freeze(['#f2d29b', '#f7b6c8', '#b7d7c8']) }),
    aquarium: Object.freeze({ stand: '#a8714a', lid: '#5b6b70', water: '#7fc1cc', sand: '#e8d3a6', weed: '#6f9a52', fish: Object.freeze(['#e98a4f', '#f2c14e', '#d97f8f']), pebbles: Object.freeze(['#c98b8f', '#a5b58e', '#e9d8c4']) }),
    'bean-bag': Object.freeze({ colours: Object.freeze(['#c98b8f', '#6f9a9a', '#d9a54a', '#8fa66b']) }),
    globe: Object.freeze({ sea: '#6f9fc4', land: '#8fb36b', meridian: '#d4a34f', wood: '#8a5a3a' }),
});

/**
 * THE LIGHT ON THE FLOOR, as numbers — the plane draws with these and `surfaces()` composites with the
 * same ones, so the colours a desk stands on are computed and never transcribed (§ 10.6 item 1).
 */
const LIGHT = Object.freeze({
    tone: 0.06, // a board's seeded tone: within ±tone / 2 of the boards' colour
    tone_lift: 1.6, // a lighter board is tinted this many times its tone (the prototype's arithmetic)
    pool: 0.55, // the lamplight pool's opacity at its centre
    front: 0.14, // the darkening toward the front edge, at the edge
    side: 0.08, // the darkening toward a side edge, at the edge
    band: 0.14, // the band's shadow along a grid's back edge, at the edge
    warmth: 0.07, // the accent region's warmth over its boards
    accent_pool: 0.7, // a pool over the accent region is this share of the floor's
});

// ── the brushes: the shared paint primitives (`../paint.js`) under this theme's paint rules ─────────

const { shade, tint, line, Pen, rimmed, shadow } = brushes(RULES);

/** A small potted plant, its foot at (x, y); `k` scales it. */
function pottedPlant(pen, x, y, pot, green, k, kind, next) {
    const leaves = [];
    const pw = 16 * k;
    const ph = 14 * k;

    if (kind === 'round') {
        for (let i = 0; i < 7; i++) {
            const a = -70 + (140 * i) / 6 + (next() - 0.5) * 12;
            const len = (16 + next() * 8) * k;
            const c = i % 2 ? green : mix(green, RULES.highlight, 0.12);

            leaves.push(`<path transform="translate(${f(x)} ${f(y - ph + 1)}) rotate(${f(a)})" d="${leafPath(len, len * 0.42)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".7"/>`);
        }
    } else if (kind === 'tall') {
        for (let i = 0; i < 9; i++) {
            const a = -40 + 80 * (i / 8) + (next() - 0.5) * 14;
            const len = (34 + next() * 26) * k * (1 - Math.abs(a) / 120);
            const c = i % 3 === 0 ? mix(green, RULES.highlight, 0.15) : i % 3 === 1 ? green : shade(green, 0.08);

            leaves.push(`<path transform="translate(${f(x)} ${f(y - ph + 1)}) rotate(${f(a)})" d="${leafPath(len, len * 0.2)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".7"/>`);
        }
    } else if (kind === 'monstera') {
        for (let i = 0; i < 6; i++) {
            const a = -60 + 120 * (i / 5) + (next() - 0.5) * 10;
            const len = (34 + next() * 14) * k;
            const sx = x + Math.sin((a * Math.PI) / 180) * len * 0.75;
            const sy = y - ph - Math.cos((a * Math.PI) / 180) * len * 0.75;
            const c = i % 2 ? green : mix(green, RULES.highlight, 0.12);

            leaves.push(`<path d="M${f(x)} ${f(y - ph + 2)}Q${f((x + sx) / 2)} ${f(sy + len * 0.1)} ${f(sx)} ${f(sy)}" stroke="${shade(green, 0.15)}" stroke-width="1.2" fill="none"/>`);
            leaves.push(`<ellipse cx="${f(sx)}" cy="${f(sy)}" rx="${f(11 * k)}" ry="${f(8 * k)}" transform="rotate(${f(a * 0.6)} ${f(sx)} ${f(sy)})" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".7"/>`);
            leaves.push(`<path d="M${f(sx - 7 * k)} ${f(sy)}h${f(14 * k)}" transform="rotate(${f(a * 0.6 + 20)} ${f(sx)} ${f(sy)})" stroke="${tint(c, 0.4)}" stroke-width=".8" opacity=".6"/>`);
        }
    } else {
        // 'trail': a trailing plant spilling over the pot's rim
        for (const side of [-1, 1]) {
            let px = x + side * pw * 0.4;
            let py = y - ph + 2;

            for (let i = 0; i < 5; i++) {
                px += side * (3 + next() * 2) * k;
                py += (5 + next() * 2) * k;

                const c = i % 2 ? green : mix(green, RULES.highlight, 0.15);

                leaves.push(`<path transform="translate(${f(px)} ${f(py)}) rotate(${f(side * (120 + next() * 30))})" d="${leafPath(9 * k, 4.6 * k)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".6"/>`);
            }
        }

        for (let i = 0; i < 5; i++) {
            const c = i % 2 ? green : mix(green, RULES.highlight, 0.12);

            leaves.push(`<path transform="translate(${f(x)} ${f(y - ph + 1)}) rotate(${-50 + 25 * i})" d="${leafPath(12 * k, 6 * k)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".6"/>`);
        }
    }

    const potPath = `M${f(x - pw / 2)} ${f(y - ph)}H${f(x + pw / 2)}L${f(x + pw * 0.38)} ${f(y)}H${f(x - pw * 0.38)}Z`;
    const potMarkup = `<path d="${potPath}" fill="${pen.face(pot, 0.2, 0.2)}" stroke="${line(pot)}" stroke-width=".8"/>`
        + `<path d="${rr(x - pw / 2 - 1.5 * k, y - ph - 2 * k, pw + 3 * k, 4.5 * k, 1.8 * k)}" fill="${pen.face(tint(pot, 0.08))}" stroke="${line(pot)}" stroke-width=".7"/>`;

    return shadow(pen, x + 2, y, pw * 0.75, 3 * k, 0.3)
        + rimmed(kind === 'trail' ? potMarkup + leaves.join('') : leaves.join('') + potMarkup);
}

/** A row of books standing on a line, from `x` across `w`, each at most `maxH` tall. */
function books(pen, x, y, w, colours, maxH, next) {
    const out = [];
    let cx = x + 2;

    while (cx < x + w - 6) {
        if (next() < 0.12) {
            cx += 6 + next() * 6;
            continue;
        }

        const bw = 4 + next() * 4;
        const bh = maxH * (0.62 + next() * 0.36);
        const c = colours[Math.floor(next() * colours.length)];
        const lean = next() < 0.08 ? 8 : 0;

        if (cx + bw > x + w) {
            break;
        }

        out.push(`<path d="${rr(cx, y - bh, bw, bh, 1.2)}" transform="rotate(${lean} ${f(cx)} ${f(y)})" fill="${pen.face(c, 0.2, 0.15)}" stroke="${line(c)}" stroke-width=".55"/>`);
        out.push(`<path d="M${f(cx + 1)} ${f(y - bh * 0.75)}h${f(bw - 2)}" stroke="${tint(c, 0.5)}" stroke-width=".9" opacity=".7"/>`);
        cx += bw + 0.6 + lean / 4;
    }

    return out.join('');
}

// ── the band ──────────────────────────────────────────────────────────────────────────────────

/**
 * THE BAND's WALL, at the band's rect, under every band element (§ 10.6 item 2): the wall with its faint
 * wide stripes, a rail, the sage lower wall with soft inset panels, the skirting, an end post at each end,
 * the light from the top-left, and a framed picture past the last window's surround where the wall has
 * room for it.
 *
 * @param {{w: number, h: number, zone: {x: number, w: number}, elevator: {x: number, y: number, w: number, h: number},
 *          clock: {x: number, y: number, w: number, h: number}, windows: list<{x: number, y: number, w: number, h: number}>}} input
 *        band-relative: the band's size, its reserved zone, the elevator's frame and the clock's rect, and every window's glazing
 */
export function band({ w, h, zone, elevator, clock, windows }) {
    finite(w, h);

    const pen = new Pen();
    const P = PALETTE.wall;
    const s = [];
    const rail = h - 54;
    const lower = h - 48;
    const skirting = h - 8;

    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.v([[0, P.top], [1, P.bottom]])}"/>`);

    // the wide wallpaper stripes, barely there, from the top to the rail
    for (let x = 0; x < w; x += 56) {
        s.push(`<rect x="${x}" y="0" width="22" height="${f(rail)}" fill="${P.stripe}" opacity=".28"/>`);
    }

    // light falling from the top-left
    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${pen.h([[0, RULES.highlight, 0.35], [0.5, RULES.highlight, 0], [1, RULES.shade, 0.08]])}"/>`);

    // the sage lower wall and its soft inset panels
    s.push(`<rect x="0" y="${f(lower)}" width="${f(w)}" height="${f(skirting - lower + 2)}" fill="${pen.v([[0, P.lower_top], [1, P.lower_bottom]])}"/>`);

    for (let x = 14; x < w - 40; x += 92) {
        s.push(`<path d="${rr(x, lower + 8, 80, 26, 5)}" fill="none" stroke="${tint(P.panel, 0.35)}" stroke-width="1.2" opacity=".55"/><path d="${rr(x + 1, lower + 9, 80, 26, 5)}" fill="none" stroke="${shade(P.panel, 0.3)}" stroke-width="1" opacity=".35"/>`);
    }

    s.push(`<path d="${rr(-4, rail, w + 8, 8, 3)}" fill="${pen.face(P.rail, 0.3, 0.15)}" stroke="${line(P.panel)}" stroke-width=".8"/>`);
    s.push(`<rect x="0" y="${f(skirting)}" width="${f(w)}" height="8" fill="${pen.v([[0, P.skirting_top], [1, P.skirting_bottom]])}"/>`);

    // the elevator's soft shadow on the wall's foot, and the clock's on the wall
    s.push(`<ellipse cx="${f(elevator.x + elevator.w / 2)}" cy="${f(h - 4)}" rx="${f(elevator.w * 0.7)}" ry="6" fill="${pen.soft(RULES.shade, 0.3)}"/>`);
    s.push(`<circle cx="${f(clock.x + clock.w / 2 + 3)}" cy="${f(clock.y + clock.h / 2 + 4)}" r="${f(clock.w / 2 + 2)}" fill="${pen.soft(RULES.shade, 0.25)}"/>`);

    // each window's soft shadow on the wall, under its surround
    for (const g of windows) {
        s.push(`<path d="${rr(g.x - 4, g.y - 1, g.w + 16, g.h + 14, 10)}" fill="${RULES.shade}" opacity=".12"/>`);
    }

    // a framed landscape past the last window's surround, where the wall has room for it
    const last = windows.length === 0 ? zone.x + zone.w : windows[windows.length - 1].x + windows[windows.length - 1].w + 32;
    const px = Math.max(last + 24, zone.x + zone.w + 24);

    if (px + 48 <= w - 24 && rail >= 70) {
        const Q = PALETTE.picture;
        const py = 28;

        s.push(rimmed(`<path d="${rr(px, py, 48, 60, 4)}" fill="${pen.face(Q.frame, 0.2, 0.2)}" stroke="${line(Q.frame)}" stroke-width=".9"/>`
            + `<path d="${rr(px + 6, py + 6, 36, 48, 2)}" fill="${pen.v([[0, Q.sky_top], [1, Q.sky_bottom]])}"/>`
            + `<circle cx="${px + 16}" cy="${py + 22}" r="6" fill="${RULES.highlight}" opacity=".8"/>`
            + `<path d="M${px + 6} ${py + 54}L${px + 20} ${py + 36}L${px + 30} ${py + 46}L${px + 36} ${py + 40}L${px + 42} ${py + 48}V${py + 54}Z" fill="${Q.hill}"/>`));
    }

    // the end posts
    s.push(`<rect x="0" y="0" width="8" height="${f(h)}" fill="${pen.face(P.post, 0.2, 0.2)}"/><rect x="${f(w - 8)}" y="0" width="8" height="${f(h)}" fill="${pen.face(P.post, 0.2, 0.2)}"/>`);

    return doc(pen, w, h, s.join(''));
}

/**
 * A WINDOW's SURROUND, at the glazing grown by § 12's surround margin — 32 px each side, 16 above, 18 below —
 * drawn OVER the glazing, which stays the painter's (A17's sky): a cream frame round the glazing with a cross
 * mullion, tied-back curtains on a rod with two knobs, and a sill, with a seeded small plant or a short row of
 * books on some sills. The glazing itself is left open.
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
    const next = stream(key, 'theme:sill');
    const s = [];

    // the frame round the glazing — its inside cut out, so the sky shows through
    s.push(rimmed(`<path d="${rr(x - 8, y - 6, gw + 16, gh + 14, 10)}${rr(x, y, gw, gh, 6)}" fill-rule="evenodd" fill="${pen.face(P.frame, 0.3, 0.12)}" stroke="${line(P.edge)}" stroke-width=".9"/>`));
    // the cross mullion, and a glint on the glass
    s.push(`<path d="M${f(x + gw / 2)} ${y}V${f(y + gh)}M${x} ${f(y + gh * 0.46)}H${f(x + gw)}" stroke="${P.frame}" stroke-width="4"/>`);
    s.push(`<path d="M${x + 10} ${y + 6}l30 0l-24 34z" fill="${RULES.highlight}" opacity=".18"/>`);

    // the curtains, tied back either side
    for (const side of [-1, 1]) {
        const cx = side < 0 ? x - 10 : x + gw + 10;
        const c = P.curtain;

        s.push(rimmed(`<path d="M${f(cx - side * 2)} ${y - 10}C${f(cx + side * 22)} ${f(y + gh * 0.37)} ${f(cx + side * 2)} ${f(y + gh * 0.67)} ${f(cx + side * 16)} ${f(y + gh + 10)}L${f(cx - side * 14)} ${f(y + gh + 12)}C${f(cx - side * 12)} ${f(y + gh * 0.62)} ${f(cx - side * 18)} ${f(y + gh * 0.25)} ${f(cx - side * 16)} ${y - 10}Z" fill="${pen.face(c, 0.25, 0.2)}" stroke="${line(c)}" stroke-width=".8"/>`
            + `<path d="M${f(cx - side * 9)} ${f(y + gh * 0.67)}q${side * 10} 4 ${side * 20} -2" stroke="${shade(c, 0.3)}" stroke-width="2.2" fill="none" stroke-linecap="round"/>`));
    }

    // the rod and its knobs
    s.push(`<path d="M${x - 26} ${y - 12}H${f(x + gw + 26)}" stroke="${line(P.rod)}" stroke-width="3" stroke-linecap="round"/><circle cx="${x - 27}" cy="${y - 12}" r="3.2" fill="${pen.paint(P.knob)}"/><circle cx="${f(x + gw + 27)}" cy="${y - 12}" r="3.2" fill="${pen.paint(P.knob)}"/>`);

    // the sill
    const sill = y + gh + 6;

    s.push(rimmed(`<path d="${rr(x - 14, sill, gw + 28, 7, 3)}" fill="${pen.face(P.frame, 0.3, 0.15)}" stroke="${line(P.edge)}" stroke-width=".8"/>`));

    // what stands on some sills: a small plant, a short row of books, or nothing
    const what = draw(key, 'theme:sill-kind') % 3;
    const at = x + 24 + next() * Math.max(0, gw - 60);

    if (what === 0) {
        s.push(pottedPlant(pen, at, sill + 1, pick(key, 'theme:sill-pot', P.pots), pick(key, 'theme:sill-leaf', P.leaves), 0.75, ['round', 'tall', 'trail'][draw(key, 'theme:sill-plant') % 3], next));
    } else if (what === 1 && gw >= 96) {
        s.push(books(pen, at, sill + 1, 34, PALETTE.bookcase.books, 14, next));
    }

    return doc(pen, W, H, s.join(''));
}

/** The elevator surround's size: its frame (the leaves' 84 × 144, a 6 px frame and a 14 px header) grown 18 px right for the call buttons. */
const ELEVATOR = Object.freeze({ w: 114, h: 158, frame_w: 96, leaves: Object.freeze({ x: 6, y: 14, w: 84, h: 144 }) });

/**
 * THE ELEVATOR's SURROUND, at its frame grown 18 px to the right: a honey frame with a header plate and a plain
 * dial — no numeral and no arrow, because this elevator indicates no floor — the lamp, the dark recess behind
 * the leaves, and the call buttons beside it: two plates, each at the rect the scene hands it in the surround's own px
 * (`calls`, card#12747) — `main` at a seat's reach and a smaller `low` under it at an intern's — each a rounded plate
 * with two round buttons on its centre line, the upper lit. The leaves are the painter's, in the building's door colours.
 *
 * @param {{calls: {main: {x: number, y: number, w: number, h: number}, low: {x: number, y: number, w: number, h: number}}}} input
 */
export function elevatorSurround({ calls }) {
    const pen = new Pen();
    const P = PALETTE.elevator;
    const L = ELEVATOR.leaves;
    const fw = ELEVATOR.frame_w;
    const m = [];

    m.push(`<path d="${rr(0, 0, fw, ELEVATOR.h, 10)}" fill="${pen.face(P.frame, 0.2, 0.2)}" stroke="${line(P.frame)}" stroke-width="1"/>`);
    // the header plate and its plain dial, and the lamp over it
    m.push(`<path d="${rr(fw / 2 - 22, 1.5, 44, 11, 5)}" fill="${pen.face(shade(P.frame, 0.25))}" stroke="${line(P.frame)}" stroke-width=".7"/>`);
    m.push(`<path d="M${fw / 2 - 12} 10.5A12 7 0 0 1 ${fw / 2 + 12} 10.5" stroke="${tint(P.frame, 0.5)}" stroke-width="1" fill="none"/>`);
    m.push(`<circle cx="${fw / 2}" cy="10.5" r="1.8" fill="${P.lamp}"/>`);
    // the recess behind the leaves
    m.push(`<path d="${rr(L.x - 2, L.y - 2, L.w + 4, L.h + 2, 4)}" fill="${shade(P.frame, 0.45)}"/>`);
    // the call buttons: one plate per rect, its buttons scaled to its width and spaced on its height
    for (const r of [calls.main, calls.low]) {
        const cx = f(r.x + r.w / 2);
        const dy = (r.h * 5) / 24;
        const br = f((r.w * 26) / 110);

        m.push(`<path d="${rr(r.x, r.y, r.w, r.h, r.w / 2.2)}" fill="${pen.face(P.frame)}" stroke="${line(P.frame)}" stroke-width=".7"/>`);
        m.push(`<circle cx="${cx}" cy="${f(r.y + r.h / 2 - dy)}" r="${br}" fill="${P.lamp}"/><circle cx="${cx}" cy="${f(r.y + r.h / 2 + dy)}" r="${br}" fill="${tint(P.frame, 0.4)}"/>`);
    }

    return doc(pen, ELEVATOR.w, ELEVATOR.h, rimmed(m.join('')));
}

/** The clock's rect, a 64 px square (§ 4.2's clock). */
const CLOCK = 64;

/**
 * THE CLOCK's CASE, at the clock's own rect: a wooden rim, a cream face and twelve ticks. The hands, and the
 * unset treatment, are the painter's (A17).
 */
export function clockCase() {
    const pen = new Pen();
    const P = PALETTE.clock;
    const c = CLOCK / 2;
    const r = c - 1.5;
    const m = [];

    m.push(`<circle cx="${c}" cy="${c}" r="${r}" fill="${pen.paint(P.rim)}" stroke="${line(P.rim)}" stroke-width="1"/>`);
    m.push(`<circle cx="${c}" cy="${c}" r="${r - 5}" fill="${pen.paint(P.face, 0.2)}" stroke="${shade(P.rim, 0.2)}" stroke-width=".8"/>`);

    for (let i = 0; i < 12; i++) {
        const a = (i / 12) * Math.PI * 2;
        const r1 = r - 8;
        const r2 = i % 3 === 0 ? r - 12.5 : r - 10;

        m.push(`<path d="M${f(c + Math.sin(a) * r1)} ${f(c - Math.cos(a) * r1)}L${f(c + Math.sin(a) * r2)} ${f(c - Math.cos(a) * r2)}" stroke="${line(P.rim)}" stroke-width="${i % 3 === 0 ? 2 : 1.1}" stroke-linecap="round"/>`);
    }

    m.push(`<path d="M${c - r + 8} ${c - 6}A${r - 8} ${r - 8} 0 0 1 ${c - 6} ${c - r + 8}" stroke="${RULES.highlight}" stroke-width="2.2" fill="none" opacity=".55" stroke-linecap="round"/>`);

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
 * A GRID's PLANE (§ 10.6 items 2, 6 and 7), at the grid's origin and its size: pale oat boards in 40 px
 * courses, seeded per grid; the PM's oak over the `accent` runs; a pool of lamplight under every `desks`
 * object, reserved or not; a gentle darkening toward the front and the side walls; the band's soft shadow
 * along the back edge where that edge is the band's foot; the `wall` runs as a soft rounded top strip with its
 * shadow, a round cap at each free end; and, where the elevator's threshold lies over this grid, the lit
 * landing. No daylight patch under a window (§ 10.6 item 3).
 *
 * @param {{w: number, h: number, seed: string, desks: list<{x: number, y: number, w: number, h: number}>,
 *          walls: list<{x: number, y: number, w: number, h: number}>, accents: list<{x: number, y: number, w: number, h: number}>,
 *          cell: {w: number, h: number}, band_foot: boolean, threshold: {x: number, y: number}|null}} input
 *        grid-relative: the grid's size and seed, its `desks` objects as rects, its `wall` and `accent` cells as
 *        merged runs, the cell they are runs of, whether its top edge is the band's foot, and the threshold
 */
export function plane({ w, h, seed, desks, walls, accents, cell, band_foot: bandFoot, threshold }) {
    finite(w, h, cell.w, cell.h);

    if (cell.w === 0 || cell.h === 0) {
        throw new RangeError('a plane asked for over a grid of empty cells');
    }

    const pen = new Pen();
    const base = PALETTE.floor.boards;
    const s = [];
    const course = 40;

    s.push(`<rect x="0" y="0" width="${f(w)}" height="${f(h)}" fill="${base}"/>`);

    // the boards: courses 40 px deep, boards of seeded lengths, each its own seeded tone, faint grain and seams
    for (let y = 0, row = 0; y < h; y += course, row++) {
        const next = stream(seed, `theme:boards:${row}`);
        let x = -next() * 300;

        while (x < w) {
            const len = 300 + next() * 320;
            const c = boardColour(base, (next() - 0.5) * LIGHT.tone);
            const bh = Math.min(course, h - y);

            s.push(`<rect x="${f(x)}" y="${y}" width="${f(len)}" height="${f(bh)}" fill="${c}"/>`);

            for (let g = 0; g < 2; g++) {
                const gy = y + 8 + next() * Math.max(0, bh - 16);

                s.push(`<path d="M${f(x + 10)} ${f(gy)}C${f(x + len * 0.3)} ${f(gy - 3)} ${f(x + len * 0.6)} ${f(gy + 3)} ${f(x + len - 10)} ${f(gy)}" stroke="${shade(base, 0.25)}" stroke-width="1" fill="none" opacity=".12"/>`);
            }

            s.push(`<path d="M${f(x)} ${y + 2}V${f(y + bh - 2)}" stroke="${shade(base, 0.25)}" stroke-width="1" opacity=".26" stroke-linecap="round"/>`);
            x += len;
        }

        if (y > 0) {
            s.push(`<path d="M0 ${y}H${f(w)}" stroke="${shade(base, 0.2)}" stroke-width="1" opacity=".2"/>`);
        }
    }

    // the accent region: oak boards, a touch warmer
    if (accents.length > 0) {
        const A = PALETTE.accent;
        const clip = `<clipPath id="accent">${accents.map((r) => `<rect x="${f(r.x)}" y="${f(r.y)}" width="${f(r.w)}" height="${f(r.h)}"/>`).join('')}</clipPath>`;
        const top = Math.min(...accents.map((r) => r.y));
        const bottom = Math.max(...accents.map((r) => r.y + r.h));
        const left = Math.min(...accents.map((r) => r.x));
        const right = Math.max(...accents.map((r) => r.x + r.w));
        const next = stream(seed, 'theme:oak');
        const o = [];

        o.push(`<rect x="${f(left)}" y="${f(top)}" width="${f(right - left)}" height="${f(bottom - top)}" fill="${pen.v([[0, A.top], [1, A.bottom]])}"/>`);

        for (let y = top; y < bottom; y += 38) {
            if (y > top) {
                o.push(`<path d="M${f(left)} ${f(y)}H${f(right)}" stroke="${shade(A.bottom, 0.3)}" stroke-width="1" opacity=".16"/>`);
            }

            for (let x = left + next() * 200; x < right; x += 260 + next() * 200) {
                o.push(`<path d="M${f(x)} ${f(y + 2)}v34" stroke="${shade(A.bottom, 0.3)}" stroke-width="1" opacity=".14"/>`);
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

    // the walls: a soft rounded top strip with its shadow, a round cap at each free end (a doorway's jamb) — each
    // straight stretch ONE strip, and the free ends where `wallRuns()` finds them (the themes' one answer)
    if (walls.length > 0) {
        const c = PALETTE['wall-run'].strip;
        const { strips, ends } = wallRuns(walls, cell, w, h);
        // the caps' markup is made before the strips', so the gradients keep the ids they were first given
        const caps = ends.map(({ x, y }) => `<circle cx="${f(x)}" cy="${f(y)}" r="${f(Math.min(cell.w, cell.h) * 0.69)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".8"/>`);

        s.push(strips.map((r) => `<path d="${rr(r.x + 2, r.y + 3, r.w, r.h, 3)}" fill="${RULES.shade}" opacity=".14"/>`).join(''));
        s.push(strips.map((r) => `<path d="${rr(r.x, r.y, r.w, r.h, 3)}" fill="${pen.face(c, 0.25, 0.1)}" stroke="${line(c)}" stroke-width=".8"/>`).join(''));
        s.push(caps.join(''));
    }

    // the landing: a lit half-moon doorstep at the elevator's threshold, with its warm glow
    if (threshold !== null && threshold !== undefined) {
        const L = PALETTE.landing;
        const { x, y } = threshold;

        s.push(`<path d="M${f(x - 72)} ${f(y)}A72 26 0 0 0 ${f(x + 72)} ${f(y)}Z" fill="${pen.v([[0, L.top], [1, L.bottom]])}" stroke="${line(L.bottom)}" stroke-width=".8"/>`);
        s.push(`<path d="M${f(x - 60)} ${f(y)}A60 19 0 0 0 ${f(x + 60)} ${f(y)}" fill="none" stroke="${L.rim}" stroke-width="1.4" opacity=".6"/>`);
        s.push(`<ellipse cx="${f(x)}" cy="${f(y + 6)}" rx="110" ry="46" fill="${pen.soft(L.glow, 0.5)}"/>`);
    }

    return doc(pen, w, h, s.join(''));
}

/**
 * THE COLOURS A DESK GROUP CAN STAND ON (§ 10.6 item 1) — what its chip and its bare text are composited
 * over: the boards at both extremes of their tone, the lamplight pool's composite at its centre, the
 * darkening's composite at its darkest, and the accent floor at both ends of its gradient and under its
 * pool. Computed by the plane's own arithmetic, never transcribed. The band, `wall` runs and standing
 * scenery are excluded — no `desks` object may stand on them — and so are the landing and its glow, which
 * § 10.6 states as an exclusion rather than claims safe.
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
 * A STANDING SCENERY PIECE (§ 10.6 item 6), at its tile's cell rect, its foot on the cell's floor line,
 * sized by the cell: `bookcase`, `plant`, `floor-lamp`, `armchair`, `cushion`, `book-pile`, or one of the snack
 * corner's two tables, `snack-table` and `snack-table-low` (card#12749, `snackTable()`), or the restroom's door,
 * `restroom-door` (card#12786, `restroomDoor()`), which the scene hands its `sign` and `plate` rects as well, or one of the
 * office pieces (card#12844, `OFFICE`): `water-cooler`, `filing-cabinet`, `copier`, `coat-rack`, `whiteboard`, `aquarium`,
 * `bean-bag` and `globe`. The floor
 * lamp's light is § 6.3's decorative glow, the painter's; the theme draws no glow of its own.
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
        const bh = Math.max(8, h - 4);
        const m = [];

        s.push(shadow(pen, cx + 4, foot, bw * 0.6, 5, 0.3));
        m.push(`<path d="${rr(x, foot - bh, bw, bh, 5)}" fill="${pen.face(P.shelf, 0.15, 0.2)}" stroke="${line(P.shelf)}" stroke-width=".9"/>`);

        const rows = Math.max(1, Math.round(bh / 34));
        const ih = (bh - 10) / rows;

        for (let i = 0; i < rows; i++) {
            const ry = foot - bh + 5 + i * ih;

            m.push(`<path d="${rr(x + 5, ry, bw - 10, ih - 4, 3)}" fill="${pen.v([[0, shade(P.shelf, 0.5)], [1, shade(P.shelf, 0.28)]])}"/>`);
            m.push(books(pen, x + 6, ry + ih - 4, bw - 12, P.books, ih - 9, next));
        }

        s.push(rimmed(m.join('')));
    } else if (kind === 'plant') {
        const P = PALETTE.plant;
        // a narrow cell stands a tall plant; any other, one of the three, seeded per cell
        const shape = w * 2 < h ? 'tall' : ['tall', 'round', 'monstera'][draw(key, 'theme:plant-shape') % 3];
        const reach = PLANT_REACH[shape];
        // sized by the cell: drawn at unit scale, then fitted to it — never more than 1.6 times taller than wide
        const sx = (w * 0.94) / reach.w;
        const sy = Math.min((h * 0.96) / reach.h, sx * 1.6);

        s.push(`<g transform="translate(${f(cx)} ${f(foot)}) scale(${f(Math.min(sx, sy * 1.6))} ${f(sy)})">${pottedPlant(pen, 0, 0, pick(key, 'theme:plant-pot', P.pots), P.leaf, 1, shape, next)}</g>`);
    } else if (kind === 'floor-lamp') {
        const P = PALETTE['floor-lamp'];
        const k = Math.min(1, h / 124, w / 34);
        const top = foot - 118 * k;

        s.push(shadow(pen, cx + 3, foot, 14 * k, 3.5 * k, 0.35));
        s.push(rimmed(`<ellipse cx="${f(cx)}" cy="${f(foot - 2 * k)}" rx="${f(11 * k)}" ry="${f(3.4 * k)}" fill="${pen.paint(P.metal)}" stroke="${line(P.metal)}" stroke-width=".8"/>`
            + `<path d="M${f(cx)} ${f(foot - 4 * k)}V${f(top + 26 * k)}" stroke="${line(P.metal)}" stroke-width="${f(3.2 * k)}" stroke-linecap="round"/><path d="M${f(cx)} ${f(foot - 4 * k)}V${f(top + 26 * k)}" stroke="${tint(P.metal, 0.2)}" stroke-width="${f(1.6 * k)}" stroke-linecap="round"/>`
            + `<path d="M${f(cx - 11 * k)} ${f(top + 30 * k)}Q${f(cx - 14 * k)} ${f(top + 26 * k)} ${f(cx - 16 * k)} ${f(top + 22 * k)}L${f(cx - 9 * k)} ${f(top)}Q${f(cx)} ${f(top - 3 * k)} ${f(cx + 9 * k)} ${f(top)}L${f(cx + 16 * k)} ${f(top + 22 * k)}Q${f(cx + 14 * k)} ${f(top + 26 * k)} ${f(cx + 11 * k)} ${f(top + 30 * k)}Q${f(cx)} ${f(top + 33 * k)} ${f(cx - 11 * k)} ${f(top + 30 * k)}Z" fill="${pen.paint(P.shade, 0.5)}" stroke="${line(P.shade)}" stroke-width=".9"/>`
            + `<ellipse cx="${f(cx)}" cy="${f(top + 29 * k)}" rx="${f(10 * k)}" ry="${f(2.4 * k)}" fill="${RULES.highlight}" opacity=".9"/>`));
    } else if (kind === 'armchair') {
        const P = PALETTE.armchair;
        const k = Math.min(1, w / 72, h / 60);
        const c = P.cover;
        const m = [];

        m.push(`<path d="${rr(cx - 30 * k, foot - 56 * k, 60 * k, 40 * k, 16 * k)}" fill="${pen.paint(c, 0.32)}" stroke="${line(c)}" stroke-width=".9"/>`);
        m.push(`<path d="${rr(cx - 26 * k, foot - 26 * k, 52 * k, 16 * k, 6 * k)}" fill="${pen.face(tint(c, 0.1), 0.2, 0.1)}" stroke="${line(c)}" stroke-width=".8"/>`);

        for (const side of [-1, 1]) {
            m.push(`<path d="${rr(cx + side * 29 * k - 9 * k, foot - 36 * k, 18 * k, 30 * k, 8 * k)}" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".9"/>`);
            m.push(`<path d="M${f(cx + side * 22 * k)} ${f(foot - 7 * k)}l${f(side * 2 * k)} ${f(7 * k)}h${f(-side * 4 * k)}Z" fill="${P.legs}" stroke="${line(P.legs)}" stroke-width=".6"/>`);
        }

        s.push(shadow(pen, cx + 3, foot, 36 * k, 5 * k, 0.32) + rimmed(m.join('')));
    } else if (kind === 'cushion') {
        const c = pick(key, 'theme:cushion', PALETTE.cushion.colours);
        const k = Math.min(1, w / 22, h / 12);

        s.push(shadow(pen, cx + 1, foot, 10 * k, 2.6 * k, 0.3) + rimmed(`<ellipse cx="${f(cx)}" cy="${f(foot - 5 * k)}" rx="${f(9 * k)}" ry="${f(5.4 * k)}" fill="${pen.paint(c, 0.35)}" stroke="${line(c)}" stroke-width=".7"/>`));
    } else if (kind === 'book-pile') {
        const colours = PALETTE['book-pile'].books;
        const m = [];
        let y = foot;
        const layers = Math.max(1, Math.min(5, Math.floor(h / 6)));

        for (let i = 0; i < layers && y > 4; i++) {
            const bw = w * (0.7 + next() * 0.25) - 4;
            const bh = 4 + next() * 2;
            const bx = cx - bw / 2 + (next() - 0.5) * 6;
            const c = colours[Math.floor(next() * colours.length)];

            m.push(`<path d="${rr(bx, y - bh, bw, bh, 1.2)}" fill="${pen.face(c, 0.2, 0.15)}" stroke="${line(c)}" stroke-width=".55"/>`);
            m.push(`<path d="M${f(bx + 2)} ${f(y - bh / 2)}h${f(bw - 4)}" stroke="${tint(c, 0.5)}" stroke-width=".7" opacity=".6"/>`);
            y -= bh;
        }

        s.push(shadow(pen, cx + 2, foot, w * 0.4, 2.5, 0.28) + rimmed(m.join('')));
    } else if (kind === 'snack-table' || kind === 'snack-table-low') {
        s.push(snackTable(pen, SNACK[kind], w, foot));
    } else if (kind === 'restroom-door') {
        s.push(restroomDoor(pen, w, foot, sign, plate));
    } else if (OFFICE[kind] !== undefined) {
        s.push(OFFICE[kind](pen, { w, h, cx, foot, key, next }));
    }

    return doc(pen, w, h, s.join(''));
}

// ── the office pieces (card#12844) ───────────────────────────────────────────────────────────────
// Each is drawn at the cell's size and its foot on the cell's floor line, at the scale the cell sets against the
// piece's own tile; nothing on any of them moves, writes text or carries a light (§ 10.6 item 3).

/** A WATER COOLER: a cream cabinet with a dark tap alcove, two plain metal taps over a drip tray and a paper cup on it, and on top a big blue bottle of water with two still bubbles. */
function waterCooler(pen, { w, h, cx, foot }) {
    const P = PALETTE['water-cooler'];
    const k = Math.min(w / 40, h / 120);
    const bw = 28 * k;
    const bodyTop = foot - 74 * k;
    const m = [];

    m.push(`<path d="${rr(cx - bw / 2, bodyTop, bw, 74 * k, 4 * k)}" fill="${pen.face(P.body, 0.2, 0.22)}" stroke="${line(P.body)}" stroke-width=".8"/>`);
    m.push(`<path d="${rr(cx - bw / 2 + 4 * k, bodyTop + 8 * k, bw - 8 * k, 22 * k, 3 * k)}" fill="${pen.v([[0, P.recess], [1, tint(P.recess, 0.2)]])}"/>`);

    for (const dx of [-5, 5]) {
        m.push(`<path d="${rr(cx + dx * k - 2.4 * k, bodyTop + 9 * k, 4.8 * k, 7 * k, 1.4 * k)}" fill="${pen.face(P.tap, 0.3, 0.2)}" stroke="${line(P.tap)}" stroke-width=".6"/>`);
        m.push(`<path d="M${f(cx + dx * k)} ${f(bodyTop + 16 * k)}v${f(2.4 * k)}" stroke="${line(P.tap)}" stroke-width="${f(1.6 * k)}" stroke-linecap="round"/>`);
    }

    m.push(`<path d="${rr(cx - bw / 2 + 5 * k, bodyTop + 27 * k, bw - 10 * k, 3 * k, 1 * k)}" fill="${pen.face(P.tap, 0.2, 0.2)}" stroke="${line(P.tap)}" stroke-width=".5"/>`);
    m.push(`<path d="M${f(cx + 2 * k)} ${f(bodyTop + 20 * k)}h${f(6 * k)}l${f(-1 * k)} ${f(7 * k)}h${f(-4 * k)}Z" fill="${pen.face(P.cup, 0.1, 0.2)}" stroke="${line(P.cup)}" stroke-width=".5"/>`);
    m.push(`<path d="M${f(cx - bw / 2 + 5 * k)} ${f(foot - 10 * k)}h${f(bw - 10 * k)}" stroke="${shade(P.body, 0.18)}" stroke-width="${f(1.2 * k)}" stroke-linecap="round"/>`);

    // the bottle: a neck into the cabinet's top, its round shoulders, the water a little below its top, two bubbles
    const btw = 26 * k;
    const btTop = bodyTop - 40 * k;
    const bottle = `M${f(cx - 4 * k)} ${f(bodyTop + 1)}V${f(bodyTop - 4 * k)}Q${f(cx - btw / 2)} ${f(bodyTop - 5 * k)} ${f(cx - btw / 2)} ${f(bodyTop - 12 * k)}V${f(btTop + 8 * k)}Q${f(cx - btw / 2)} ${f(btTop)} ${f(cx)} ${f(btTop)}Q${f(cx + btw / 2)} ${f(btTop)} ${f(cx + btw / 2)} ${f(btTop + 8 * k)}V${f(bodyTop - 12 * k)}Q${f(cx + btw / 2)} ${f(bodyTop - 5 * k)} ${f(cx + 4 * k)} ${f(bodyTop - 4 * k)}V${f(bodyTop + 1)}Z`;

    m.push(`<path d="${bottle}" fill="${pen.h([[0, tint(P.water, 0.25)], [0.6, P.water], [1, shade(P.water, 0.15)]])}" stroke="${line(P.cap)}" stroke-width=".8"/>`);
    m.push(`<path d="M${f(cx - btw / 2 + 1)} ${f(btTop + 9 * k)}Q${f(cx)} ${f(btTop + 6 * k)} ${f(cx + btw / 2 - 1)} ${f(btTop + 9 * k)}" fill="none" stroke="${tint(P.water, 0.5)}" stroke-width="${f(1.2 * k)}"/>`);
    m.push(`<path d="M${f(cx - btw / 2 + 1)} ${f(btTop + 20 * k)}H${f(cx + btw / 2 - 1)}" stroke="${shade(P.water, 0.2)}" stroke-width="${f(0.8 * k)}" opacity=".6"/>`);
    m.push(`<path d="M${f(cx - btw / 2 + 4 * k)} ${f(btTop + 6 * k)}v${f(22 * k)}" stroke="${RULES.highlight}" stroke-width="${f(2.2 * k)}" stroke-linecap="round" opacity=".7"/>`);
    m.push(`<circle cx="${f(cx + 4 * k)}" cy="${f(btTop + 26 * k)}" r="${f(1.8 * k)}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(0.7 * k)}"/>`);
    m.push(`<circle cx="${f(cx + 6.5 * k)}" cy="${f(btTop + 19 * k)}" r="${f(1.2 * k)}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(0.6 * k)}"/>`);
    m.push(`<path d="${rr(cx - 5 * k, bodyTop - 5 * k, 10 * k, 5 * k, 1.5 * k)}" fill="${pen.face(P.cap, 0.25, 0.2)}" stroke="${line(P.cap)}" stroke-width=".6"/>`);

    return shadow(pen, cx + 2, foot, bw * 0.7, 3.4 * k, 0.3) + rimmed(m.join(''));
}

/** A FILING CABINET: as many drawers as its cell is tall for, each with a blank cream label holder and a bar handle, and on top a seeded small plant or a stack of papers and a folder. */
function filingCabinet(pen, { w, h, cx, foot, key, next }) {
    const P = PALETTE['filing-cabinet'];
    const k = Math.min(1, w / 48);
    const bw = w - 6 * k;
    const x = (w - bw) / 2;
    const topRoom = Math.min(24 * k, h * 0.3);
    const bh = Math.max(8, h - 2 - topRoom);
    const top = foot - bh;
    const drawers = Math.max(1, Math.round((bh - 6 * k) / 26));
    const dh = (bh - 6 * k) / drawers;
    const m = [`<path d="${rr(x, top, bw, bh, 3 * k)}" fill="${pen.face(P.body, 0.22, 0.22)}" stroke="${line(P.body)}" stroke-width=".9"/>`];

    for (let i = 0; i < drawers; i++) {
        const dy = top + 3 * k + i * dh;

        m.push(`<path d="${rr(x + 3 * k, dy, bw - 6 * k, dh - 3 * k, 2 * k)}" fill="${pen.face(tint(P.body, 0.08), 0.2, 0.15)}" stroke="${line(P.body)}" stroke-width=".7"/>`);
        m.push(`<path d="${rr(cx - 6 * k, dy + 4 * k, 12 * k, 5 * k, 1 * k)}" fill="${P.label}" stroke="${line(P.handle)}" stroke-width=".6"/>`);
        m.push(`<path d="${rr(cx - 8 * k, dy + 12 * k, 16 * k, 3.2 * k, 1.6 * k)}" fill="${pen.face(P.handle, 0.35, 0.2)}" stroke="${line(P.handle)}" stroke-width=".6"/>`);
    }

    m.push(`<path d="M${f(x + 2 * k)} ${f(top + 1.5)}H${f(x + bw - 2 * k)}" stroke="${tint(P.body, 0.45)}" stroke-width="1.2" stroke-linecap="round" opacity=".8"/>`);

    const out = [shadow(pen, cx + 3, foot, bw * 0.62, 4 * k, 0.3), rimmed(m.join(''))];

    if (draw(key, 'theme:filing-top') % 2 === 0) {
        out.push(pottedPlant(pen, cx + bw * 0.18, top, P.pot, P.leaf, 0.62 * k, 'round', next));
    } else {
        const papers = [];

        for (let i = 0; i < 3; i++) {
            papers.push(`<path d="${rr(x + 5 * k + (next() - 0.5) * 2 * k, top - (i + 1) * 2.2 * k, bw * 0.55, 2.2 * k, 0.6)}" fill="${pen.face(P.papers, 0.1, 0.2)}" stroke="${line(P.papers)}" stroke-width=".45"/>`);
        }

        papers.push(`<path d="${rr(x + bw * 0.55, top - 9 * k, 12 * k, 9 * k, 1.2 * k)}" transform="rotate(-8 ${f(x + bw * 0.55)} ${f(top)})" fill="${pen.face(P.folder, 0.2, 0.2)}" stroke="${line(P.folder)}" stroke-width=".6"/>`);
        out.push(rimmed(papers.join(''), 1, 0.45));
    }

    return out.join('');
}

/** A COPIER: a rounded cream body on a cabinet of two paper drawers, a grey lid, a sloped panel of plain keys with no lamp and no screen, a sheet in the out tray, and a blue trim line. */
function copier(pen, { w, h, cx, foot }) {
    const P = PALETTE.copier;
    const k = Math.min(w / 80, h / 96);
    const bw = 70 * k;
    const x = cx - bw / 2;
    const m = [];
    const cabTop = foot - 44 * k;
    const bodyTop = foot - 74 * k;

    // the cabinet and its two drawers, on little dark feet
    for (const fx of [x + 6 * k, x + bw - 10 * k]) {
        m.push(`<path d="${rr(fx, foot - 4 * k, 4 * k, 4 * k, 1 * k)}" fill="${P.feet}"/>`);
    }

    m.push(`<path d="${rr(x + 2 * k, cabTop, bw - 4 * k, 41 * k, 3 * k)}" fill="${pen.face(shade(P.body, 0.05), 0.15, 0.2)}" stroke="${line(P.body)}" stroke-width=".8"/>`);

    for (let i = 0; i < 2; i++) {
        const dy = cabTop + 3 * k + i * 19 * k;

        m.push(`<path d="${rr(x + 6 * k, dy, bw - 12 * k, 16 * k, 2 * k)}" fill="${pen.face(P.body, 0.2, 0.15)}" stroke="${line(P.body)}" stroke-width=".6"/>`);
        m.push(`<path d="${rr(cx - 9 * k, dy + 5 * k, 18 * k, 3 * k, 1.5 * k)}" fill="${shade(P.body, 0.3)}"/>`);
    }

    // the body, its trim line, the lid, and the panel of keys at the right
    m.push(`<path d="${rr(x, bodyTop, bw, 31 * k, 5 * k)}" fill="${pen.face(P.body, 0.25, 0.18)}" stroke="${line(P.body)}" stroke-width=".9"/>`);
    m.push(`<path d="M${f(x + 3 * k)} ${f(bodyTop + 22 * k)}H${f(x + bw - 3 * k)}" stroke="${P.trim}" stroke-width="${f(2 * k)}" stroke-linecap="round"/>`);
    m.push(`<path d="${rr(x + 3 * k, bodyTop + 8 * k, bw * 0.45, 9 * k, 2 * k)}" fill="${shade(P.body, 0.32)}"/>`);
    m.push(`<path d="${rr(x - 1 * k, bodyTop - 5 * k, bw * 0.72, 7 * k, 3 * k)}" fill="${pen.face(P.lid, 0.25, 0.2)}" stroke="${line(P.lid)}" stroke-width=".8"/>`);

    const px = x + bw * 0.7;
    const panel = `M${f(px)} ${f(bodyTop + 2 * k)}L${f(px + 4 * k)} ${f(bodyTop - 7 * k)}H${f(x + bw + 1 * k)}L${f(x + bw - 1 * k)} ${f(bodyTop + 2 * k)}Z`;

    m.push(`<path d="${panel}" fill="${pen.face(P.lid, 0.3, 0.15)}" stroke="${line(P.lid)}" stroke-width=".7"/>`);

    for (let i = 0; i < 3; i++) {
        m.push(`<path d="${rr(px + 6 * k + i * 4.6 * k, bodyTop - 4.6 * k, 3.4 * k, 2.4 * k, 0.8 * k)}" fill="${P.keys}" stroke="${line(P.keys)}" stroke-width=".4"/>`);
    }

    // the out tray at the left: a sheet lying in it, its corner curled up
    m.push(`<path d="M${f(x - 4 * k)} ${f(bodyTop + 12 * k)}L${f(x + 2 * k)} ${f(bodyTop + 10 * k)}V${f(bodyTop + 15 * k)}L${f(x - 4 * k)} ${f(bodyTop + 15 * k)}Z" fill="${pen.face(P.lid, 0.2, 0.2)}" stroke="${line(P.lid)}" stroke-width=".6"/>`);
    m.push(`<path d="M${f(x - 3 * k)} ${f(bodyTop + 11.6 * k)}L${f(x + 8 * k)} ${f(bodyTop + 9 * k)}L${f(x + 8 * k)} ${f(bodyTop + 11 * k)}Q${f(x - 1 * k)} ${f(bodyTop + 12 * k)} ${f(x - 3 * k)} ${f(bodyTop + 9.6 * k)}Z" fill="${P.paper}" stroke="${line(P.paper)}" stroke-width=".5"/>`);

    return shadow(pen, cx + 3, foot, bw * 0.58, 4 * k, 0.3) + rimmed(m.join(''));
}

/** A COAT RACK: a turned wooden pole on three splayed feet with a round knob, pegs near its top, a seeded coat hanging from one, a striped scarf from another and a soft hat on the knob. */
function coatRack(pen, { w, h, cx, foot, key }) {
    const P = PALETTE['coat-rack'];
    const k = Math.min(w / 40, h / 136);
    const top = foot - 128 * k;
    const coat = pick(key, 'theme:coat', P.coats);
    const scarf = pick(key, 'theme:scarf', P.scarf);
    const m = [];

    for (const dx of [-14, 0, 14]) {
        m.push(`<path d="M${f(cx)} ${f(foot - 14 * k)}Q${f(cx + dx * 0.6 * k)} ${f(foot - 6 * k)} ${f(cx + dx * k)} ${f(foot - 1)}" fill="none" stroke="${line(P.wood)}" stroke-width="${f(3.4 * k)}" stroke-linecap="round"/>`);
    }

    m.push(`<path d="M${f(cx)} ${f(foot - 8 * k)}V${f(top + 6 * k)}" stroke="${line(P.wood)}" stroke-width="${f(4.4 * k)}" stroke-linecap="round"/>`);
    m.push(`<path d="M${f(cx)} ${f(foot - 8 * k)}V${f(top + 6 * k)}" stroke="${tint(P.wood, 0.15)}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);

    for (const side of [-1, 1]) {
        m.push(`<path d="M${f(cx)} ${f(top + 16 * k)}q${f(side * 6 * k)} ${f(-1 * k)} ${f(side * 9 * k)} ${f(-6 * k)}" fill="none" stroke="${line(P.wood)}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);
    }

    m.push(`<circle cx="${f(cx)}" cy="${f(top + 5 * k)}" r="${f(3.6 * k)}" fill="${pen.paint(P.wood)}" stroke="${line(P.wood)}" stroke-width=".6"/>`);

    // the coat, hung from the left peg: shoulders, a body that widens to its hem, a collar and two buttons
    const ct = top + 12 * k;
    const coatPath = `M${f(cx - 8 * k)} ${f(ct)}Q${f(cx - 15 * k)} ${f(ct + 2 * k)} ${f(cx - 16 * k)} ${f(ct + 12 * k)}L${f(cx - 18 * k)} ${f(ct + 54 * k)}Q${f(cx - 8 * k)} ${f(ct + 58 * k)} ${f(cx + 1 * k)} ${f(ct + 54 * k)}L${f(cx - 1 * k)} ${f(ct + 10 * k)}Q${f(cx - 2 * k)} ${f(ct + 1 * k)} ${f(cx - 8 * k)} ${f(ct)}Z`;

    m.push(`<path d="${coatPath}" fill="${pen.face(coat, 0.25, 0.2)}" stroke="${line(coat)}" stroke-width=".8"/>`);
    m.push(`<path d="M${f(cx - 10 * k)} ${f(ct + 1 * k)}L${f(cx - 6 * k)} ${f(ct + 10 * k)}L${f(cx - 3 * k)} ${f(ct + 2 * k)}" fill="${shade(coat, 0.15)}" stroke="${line(coat)}" stroke-width=".6"/>`);

    for (const by of [22, 32, 42]) {
        m.push(`<circle cx="${f(cx - 4 * k)}" cy="${f(ct + by * k)}" r="${f(1.1 * k)}" fill="${tint(coat, 0.5)}"/>`);
    }

    // the scarf, from the right peg: two hanging tails with a stripe across each
    for (const [dx, len] of [[5, 34], [9, 28]]) {
        m.push(`<path d="${rr(cx + dx * k - 2.6 * k, top + 12 * k, 5.2 * k, len * k, 2 * k)}" fill="${pen.face(scarf, 0.2, 0.15)}" stroke="${line(scarf)}" stroke-width=".6"/>`);
        m.push(`<path d="M${f(cx + dx * k - 2.4 * k)} ${f(top + (8 + len) * k)}h${f(4.8 * k)}" stroke="${shade(scarf, 0.25)}" stroke-width="${f(1.4 * k)}"/>`);
    }

    // the hat on the knob: a soft dome with a brim
    m.push(`<path d="M${f(cx - 9 * k)} ${f(top + 4 * k)}Q${f(cx - 8 * k)} ${f(top - 7 * k)} ${f(cx)} ${f(top - 7 * k)}Q${f(cx + 8 * k)} ${f(top - 7 * k)} ${f(cx + 9 * k)} ${f(top + 4 * k)}Z" fill="${pen.paint(P.hat)}" stroke="${line(P.hat)}" stroke-width=".7"/>`);
    m.push(`<path d="${rr(cx - 12 * k, top + 2.4 * k, 24 * k, 3.2 * k, 1.6 * k)}" fill="${pen.face(shade(P.hat, 0.08), 0.2, 0.2)}" stroke="${line(P.hat)}" stroke-width=".6"/>`);

    return shadow(pen, cx + 2, foot, 16 * k, 3 * k, 0.3) + rimmed(m.join(''));
}

/** A WHITEBOARD ON AN EASEL: three wooden legs, a framed white board with a marker tray and three markers, and on the board doodles that say nothing — boxes joined by an arrow, a smiling sun, a star — and two seeded sticky notes. */
function whiteboard(pen, { w, h, cx, foot, key }) {
    const P = PALETTE.whiteboard;
    const k = Math.min(w / 80, h / 128);
    const bw = 72 * k;
    const bh = 54 * k;
    const bx = cx - bw / 2;
    const by = foot - 122 * k;
    const [blue, red, green] = P.ink;
    const m = [];

    // the legs: the back one first, then the two front ones splayed
    m.push(`<path d="M${f(cx)} ${f(by + 6 * k)}L${f(cx + 4 * k)} ${f(foot - 6 * k)}" stroke="${shade(P.wood, 0.25)}" stroke-width="${f(3 * k)}" stroke-linecap="round"/>`);

    for (const side of [-1, 1]) {
        m.push(`<path d="M${f(cx + side * 10 * k)} ${f(by - 2 * k)}L${f(cx + side * 26 * k)} ${f(foot - 1)}" stroke="${line(P.wood)}" stroke-width="${f(4 * k)}" stroke-linecap="round"/>`);
        m.push(`<path d="M${f(cx + side * 10 * k)} ${f(by - 2 * k)}L${f(cx + side * 26 * k)} ${f(foot - 1)}" stroke="${tint(P.wood, 0.15)}" stroke-width="${f(2 * k)}" stroke-linecap="round"/>`);
    }

    // the board and its frame, and the tray with its markers
    m.push(`<path d="${rr(bx, by, bw, bh, 3 * k)}" fill="${pen.face(P.frame, 0.25, 0.2)}" stroke="${line(P.frame)}" stroke-width=".9"/>`);
    m.push(`<path d="${rr(bx + 3 * k, by + 3 * k, bw - 6 * k, bh - 6 * k, 1.5 * k)}" fill="${pen.face(P.board, 0.1, 0.06)}"/>`);
    m.push(`<path d="${rr(bx + 2 * k, by + bh - 1 * k, bw - 4 * k, 4 * k, 1.5 * k)}" fill="${pen.face(P.wood, 0.2, 0.2)}" stroke="${line(P.wood)}" stroke-width=".6"/>`);
    [blue, red, green].forEach((c, i) => {
        m.push(`<path d="${rr(bx + 10 * k + i * 8 * k, by + bh - 2.6 * k, 6.4 * k, 2.2 * k, 1 * k)}" fill="${c}" stroke="${line(c)}" stroke-width=".4"/>`);
    });

    const ink = (d, c, sw = 1.3) => `<path d="${d}" fill="none" stroke="${c}" stroke-width="${f(sw * k)}" stroke-linecap="round" stroke-linejoin="round"/>`;
    const ox = bx + 7 * k;
    const oy = by + 9 * k;

    // two boxes and an arrow between them, a third under the arrow's head
    m.push(ink(rr(ox, oy, 14 * k, 9 * k, 2 * k), blue));
    m.push(ink(rr(ox + 26 * k, oy, 14 * k, 9 * k, 2 * k), blue));
    m.push(ink(`M${f(ox + 15 * k)} ${f(oy + 4.5 * k)}H${f(ox + 24.5 * k)}M${f(ox + 21.5 * k)} ${f(oy + 2.4 * k)}L${f(ox + 24.5 * k)} ${f(oy + 4.5 * k)}L${f(ox + 21.5 * k)} ${f(oy + 6.6 * k)}`, red));
    m.push(ink(`M${f(ox + 33 * k)} ${f(oy + 10 * k)}V${f(oy + 17 * k)}M${f(ox + 31 * k)} ${f(oy + 15 * k)}L${f(ox + 33 * k)} ${f(oy + 17.4 * k)}L${f(ox + 35 * k)} ${f(oy + 15 * k)}`, red));
    m.push(ink(`M${f(ox + 26 * k)} ${f(oy + 19 * k)}h${f(14 * k)}v${f(9 * k)}h${f(-14 * k)}Z`, green));

    // a smiling sun at the lower left, and a star beside the first box
    const sx = ox + 7 * k;
    const sy = oy + 26 * k;
    const rays = [];

    for (let i = 0; i < 8; i++) {
        const a = (i * Math.PI) / 4;

        rays.push(`M${f(sx + Math.cos(a) * 6.4 * k)} ${f(sy + Math.sin(a) * 6.4 * k)}L${f(sx + Math.cos(a) * 8.8 * k)} ${f(sy + Math.sin(a) * 8.8 * k)}`);
    }

    m.push(ink(rays.join(''), PALETTE.whiteboard.notes[0], 1.4));
    m.push(`<circle cx="${f(sx)}" cy="${f(sy)}" r="${f(4.8 * k)}" fill="none" stroke="${red}" stroke-width="${f(1.2 * k)}"/>`);
    m.push(`<circle cx="${f(sx - 1.6 * k)}" cy="${f(sy - 1 * k)}" r="${f(0.6 * k)}" fill="${blue}"/><circle cx="${f(sx + 1.6 * k)}" cy="${f(sy - 1 * k)}" r="${f(0.6 * k)}" fill="${blue}"/>`);
    m.push(ink(`M${f(sx - 2 * k)} ${f(sy + 1.2 * k)}Q${f(sx)} ${f(sy + 3.2 * k)} ${f(sx + 2 * k)} ${f(sy + 1.2 * k)}`, blue, 0.9));

    const star = [];

    for (let i = 0; i < 10; i++) {
        const a = -Math.PI / 2 + (i * Math.PI) / 5;
        const r = (i % 2 ? 1.6 : 3.8) * k;

        star.push(`${i ? 'L' : 'M'}${f(ox + 19 * k + Math.cos(a) * r)} ${f(oy + 17 * k + Math.sin(a) * r)}`);
    }

    m.push(ink(`${star.join('')}Z`, green, 1));

    // two sticky notes at the right, seeded in colour, each a little askew with its fold
    for (let i = 0; i < 2; i++) {
        const c = pick(key, `theme:note:${i}`, P.notes);
        const nx = bx + bw - 19 * k + i * 6 * k;
        const ny = by + 22 * k + i * 11 * k;
        const tilt = i ? 6 : -5;

        m.push(`<g transform="rotate(${tilt} ${f(nx + 5 * k)} ${f(ny + 5 * k)})"><path d="M${f(nx)} ${f(ny)}h${f(10 * k)}v${f(7.4 * k)}l${f(-2.6 * k)} ${f(2.6 * k)}h${f(-7.4 * k)}Z" fill="${pen.face(c, 0.15, 0.12)}" stroke="${line(c)}" stroke-width=".4"/><path d="M${f(nx + 10 * k)} ${f(ny + 7.4 * k)}h${f(-2.6 * k)}v${f(2.6 * k)}" fill="${shade(c, 0.15)}"/></g>`);
    }

    return shadow(pen, cx + 2, foot, 30 * k, 3.4 * k, 0.28) + rimmed(m.join(''), 1.2, 0.45);
}

/** An AQUARIUM: a wooden stand with two doors, and on it a glass tank under a dark lid with no lamp in it — teal water, sand and pebbles, swaying-shaped weed drawn still, three small round fish and a few bubbles, none of which move. */
function aquarium(pen, { w, h, cx, foot, key, next }) {
    const P = PALETTE.aquarium;
    const k = Math.min(w / 80, h / 104);
    const tw = 72 * k;
    const tx = cx - tw / 2;
    const standTop = foot - 40 * k;
    const th = 54 * k;
    const ty = standTop - th;
    const m = [];

    // the stand: its body, two doors with round knobs, a plinth
    m.push(`<path d="${rr(tx + 2 * k, standTop, tw - 4 * k, 39 * k, 3 * k)}" fill="${pen.face(P.stand, 0.2, 0.2)}" stroke="${line(P.stand)}" stroke-width=".9"/>`);

    for (const side of [0, 1]) {
        const dx = tx + 6 * k + side * (tw / 2 - 4 * k);

        m.push(`<path d="${rr(dx, standTop + 5 * k, tw / 2 - 8 * k, 27 * k, 2 * k)}" fill="${pen.face(tint(P.stand, 0.08), 0.2, 0.15)}" stroke="${line(P.stand)}" stroke-width=".6"/>`);
        m.push(`<circle cx="${f(side ? dx + 4 * k : dx + tw / 2 - 12 * k)}" cy="${f(standTop + 18 * k)}" r="${f(1.6 * k)}" fill="${pen.paint(P.sand)}" stroke="${line(P.stand)}" stroke-width=".4"/>`);
    }

    const stand = m.join('');
    const tank = [];
    const water = ty + 7 * k;

    // the tank: its water, the sand and pebbles, the weed, the fish, the bubbles, the glass's shine, the frame and lid
    tank.push(`<path d="${rr(tx, ty, tw, th, 3 * k)}" fill="${pen.v([[0, tint(P.water, 0.35)], [1, shade(P.water, 0.12)]])}" stroke="${line(P.lid)}" stroke-width=".9"/>`);
    tank.push(`<path d="M${f(tx + 1)} ${f(water)}H${f(tx + tw - 1)}V${f(ty + th - 1)}H${f(tx + 1)}Z" fill="${pen.v([[0, P.water], [1, shade(P.water, 0.22)]])}"/>`);
    tank.push(`<path d="M${f(tx + 1)} ${f(water)}H${f(tx + tw - 1)}" stroke="${tint(P.water, 0.55)}" stroke-width="${f(1.2 * k)}"/>`);
    tank.push(`<path d="M${f(tx + 1)} ${f(ty + th - 1)}V${f(ty + th - 8 * k)}Q${f(tx + tw * 0.3)} ${f(ty + th - 11 * k)} ${f(tx + tw * 0.55)} ${f(ty + th - 8 * k)}Q${f(tx + tw * 0.8)} ${f(ty + th - 5 * k)} ${f(tx + tw - 1)} ${f(ty + th - 9 * k)}V${f(ty + th - 1)}Z" fill="${pen.v([[0, tint(P.sand, 0.15)], [1, shade(P.sand, 0.12)]])}"/>`);

    for (let i = 0; i < 6; i++) {
        const c = P.pebbles[i % P.pebbles.length];

        tank.push(`<ellipse cx="${f(tx + 6 * k + next() * (tw - 12 * k))}" cy="${f(ty + th - 3.4 * k)}" rx="${f(2.2 * k)}" ry="${f(1.4 * k)}" fill="${pen.paint(c)}"/>`);
    }

    for (const [wx, hh] of [[0.14, 30], [0.2, 22], [0.82, 26], [0.88, 34]]) {
        const bx0 = tx + tw * wx;
        const top = ty + th - 6 * k - hh * k;

        tank.push(`<path d="M${f(bx0)} ${f(ty + th - 6 * k)}Q${f(bx0 - 4 * k)} ${f(top + hh * 0.66 * k)} ${f(bx0)} ${f(top + hh * 0.4 * k)}Q${f(bx0 + 4 * k)} ${f(top + hh * 0.2 * k)} ${f(bx0)} ${f(top)}" fill="none" stroke="${P.weed}" stroke-width="${f(2.6 * k)}" stroke-linecap="round"/>`);
    }

    const fish = (x, y, c, dir) => `<g transform="translate(${f(x)} ${f(y)}) scale(${f(dir * k)} ${f(k)})">`
        + `<path d="M4 0L9 -3.6V3.6Z" fill="${shade(c, 0.1)}" stroke="${line(c)}" stroke-width=".5"/>`
        + `<ellipse cx="0" cy="0" rx="5.4" ry="3.8" fill="${pen.paint(c, 0.4)}" stroke="${line(c)}" stroke-width=".5"/>`
        + `<circle cx="-2.6" cy="-0.8" r="1.1" fill="${RULES.highlight}"/><circle cx="-2.8" cy="-0.8" r=".55" fill="${RULES.line}"/></g>`;

    P.fish.forEach((c, i) => {
        const fx = tx + tw * (0.3 + 0.2 * i) + (next() - 0.5) * 6 * k;
        const fy = water + (8 + ((i * 11) % 24)) * k;

        tank.push(fish(fx, fy, c, i % 2 ? -1 : 1));
    });

    for (const [bx0, by0, r] of [[0.66, 0.5, 1.6], [0.68, 0.36, 1.2], [0.65, 0.24, 0.9]]) {
        tank.push(`<circle cx="${f(tx + tw * bx0)}" cy="${f(ty + th * by0)}" r="${f(r * k)}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(0.6 * k)}"/>`);
    }

    tank.push(`<path d="M${f(tx + 5 * k)} ${f(ty + 10 * k)}l${f(8 * k)} ${f(-3 * k)}M${f(tx + 5 * k)} ${f(ty + 15 * k)}l${f(14 * k)} ${f(-5 * k)}" stroke="${RULES.highlight}" stroke-width="${f(1.4 * k)}" stroke-linecap="round" opacity=".55"/>`);
    tank.push(`<path d="${rr(tx - 1.5 * k, ty - 4 * k, tw + 3 * k, 5.5 * k, 2 * k)}" fill="${pen.face(P.lid, 0.25, 0.2)}" stroke="${line(P.lid)}" stroke-width=".7"/>`);
    tank.push(`<path d="${rr(tx - 1 * k, ty + th - 2 * k, tw + 2 * k, 3.5 * k, 1.4 * k)}" fill="${pen.face(P.lid, 0.2, 0.2)}" stroke="${line(P.lid)}" stroke-width=".6"/>`);

    return shadow(pen, cx + 3, foot, tw * 0.56, 4 * k, 0.3) + rimmed(stand) + rimmed(tank.join(''), 1.1, 0.45);
}

/** A BEAN BAG: a soft slouched pear of fabric, a seeded colour, a dent where someone last sat and a seam over its top. */
function beanBag(pen, { w, h, cx, foot, key }) {
    const c = pick(key, 'theme:bean-bag', PALETTE['bean-bag'].colours);
    const k = Math.min(w / 56, h / 40);
    const body = `M${f(cx - 25 * k)} ${f(foot - 6 * k)}Q${f(cx - 28 * k)} ${f(foot - 22 * k)} ${f(cx - 14 * k)} ${f(foot - 31 * k)}Q${f(cx - 2 * k)} ${f(foot - 38 * k)} ${f(cx + 10 * k)} ${f(foot - 33 * k)}Q${f(cx + 26 * k)} ${f(foot - 25 * k)} ${f(cx + 26 * k)} ${f(foot - 9 * k)}Q${f(cx + 24 * k)} ${f(foot)} ${f(cx)} ${f(foot - 0.5 * k)}Q${f(cx - 22 * k)} ${f(foot)} ${f(cx - 25 * k)} ${f(foot - 6 * k)}Z`;
    const m = `<path d="${body}" fill="${pen.paint(c, 0.34)}" stroke="${line(c)}" stroke-width=".9"/>`
        + `<path d="M${f(cx - 12 * k)} ${f(foot - 20 * k)}Q${f(cx)} ${f(foot - 13 * k)} ${f(cx + 13 * k)} ${f(foot - 19 * k)}Q${f(cx + 2 * k)} ${f(foot - 25 * k)} ${f(cx - 12 * k)} ${f(foot - 20 * k)}Z" fill="${shade(c, 0.16)}" opacity=".7"/>`
        + `<path d="M${f(cx - 6 * k)} ${f(foot - 34 * k)}Q${f(cx + 6 * k)} ${f(foot - 30 * k)} ${f(cx + 18 * k)} ${f(foot - 27 * k)}" fill="none" stroke="${line(c)}" stroke-width=".7" stroke-dasharray="${f(1.6 * k)} ${f(1.2 * k)}" opacity=".8"/>`
        + `<path d="M${f(cx - 18 * k)} ${f(foot - 24 * k)}q${f(3 * k)} ${f(-5 * k)} ${f(9 * k)} ${f(-7 * k)}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(1.6 * k)}" stroke-linecap="round" opacity=".6"/>`;

    return shadow(pen, cx + 2, foot, 26 * k, 3.6 * k, 0.32) + rimmed(m);
}

/** A GLOBE ON A STAND: a wooden pedestal on a round foot, a brass half-meridian, and a tilted blue globe with soft made-up green lands — no map anyone could read. */
function globe(pen, { w, h, cx, foot, key }) {
    const P = PALETTE.globe;
    const k = Math.min(w / 40, h / 88);
    const r = 14 * k;
    const gy = foot - 62 * k;
    const tilt = 23;
    const turn = (draw(key, 'theme:globe-turn') % 5) * 4 - 8;
    const m = [];

    m.push(`<ellipse cx="${f(cx)}" cy="${f(foot - 3 * k)}" rx="${f(12 * k)}" ry="${f(3.4 * k)}" fill="${pen.paint(P.wood)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
    m.push(`<path d="M${f(cx - 2.4 * k)} ${f(foot - 5 * k)}Q${f(cx - 4 * k)} ${f(foot - 20 * k)} ${f(cx - 1.6 * k)} ${f(gy + r + 7 * k)}H${f(cx + 1.6 * k)}Q${f(cx + 4 * k)} ${f(foot - 20 * k)} ${f(cx + 2.4 * k)} ${f(foot - 5 * k)}Z" fill="${pen.face(P.wood, 0.25, 0.2)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
    m.push(`<ellipse cx="${f(cx)}" cy="${f(foot - 24 * k)}" rx="${f(4 * k)}" ry="${f(1.6 * k)}" fill="${pen.paint(P.wood)}" stroke="${line(P.wood)}" stroke-width=".5"/>`);

    const lands = [
        `M${f(-8 * k + turn * k * 0.4)} ${f(-7 * k)}q${f(5 * k)} ${f(-4 * k)} ${f(9 * k)} ${f(-1 * k)}q${f(2 * k)} ${f(4 * k)} ${f(-3 * k)} ${f(6 * k)}q${f(-3 * k)} ${f(5 * k)} ${f(-6 * k)} ${f(2 * k)}q${f(-3 * k)} ${f(-3 * k)} ${f(0)} ${f(-7 * k)}Z`,
        `M${f(2 * k + turn * k * 0.4)} ${f(3 * k)}q${f(6 * k)} ${f(-2 * k)} ${f(8 * k)} ${f(2 * k)}q${f(0)} ${f(5 * k)} ${f(-5 * k)} ${f(6 * k)}q${f(-4 * k)} ${f(0)} ${f(-3 * k)} ${f(-8 * k)}Z`,
        `M${f(-10 * k + turn * k * 0.4)} ${f(6 * k)}q${f(3 * k)} ${f(-1 * k)} ${f(4 * k)} ${f(2 * k)}q${f(-1 * k)} ${f(3 * k)} ${f(-4 * k)} ${f(1 * k)}Z`,
    ];
    const clip = pen.memo(`clip${r}`, (id) => `<clipPath id="${id}"><circle cx="0" cy="0" r="${f(r)}"/></clipPath>`);

    // the half-meridian from pole to pole round the globe's right, and the brass bracket from its foot to the pedestal
    const R = r + 3 * k;
    const ring = `M0 ${f(-R)}A${f(R)} ${f(R)} 0 0 1 0 ${f(R)}`;
    const t = (tilt * Math.PI) / 180;

    m.push(`<path d="M${f(cx - R * Math.sin(t))} ${f(gy + R * Math.cos(t))}Q${f(cx - 1 * k)} ${f(gy + R + 2 * k)} ${f(cx)} ${f(gy + r + 7.5 * k)}" fill="none" stroke="${line(P.meridian)}" stroke-width="${f(2.4 * k)}" stroke-linecap="round"/>`);
    m.push(`<g transform="translate(${f(cx)} ${f(gy)}) rotate(${tilt})">`
        + `<circle cx="0" cy="0" r="${f(r)}" fill="${pen.paint(P.sea, 0.45)}" stroke="${line(P.sea)}" stroke-width=".8"/>`
        + `<g clip-path="${clip}">${lands.map((d) => `<path d="${d}" fill="${pen.paint(P.land, 0.35)}" stroke="${line(P.land)}" stroke-width=".5"/>`).join('')}`
        + `<ellipse cx="0" cy="0" rx="${f(r)}" ry="${f(r * 0.28)}" fill="none" stroke="${tint(P.sea, 0.4)}" stroke-width=".6" opacity=".7"/></g>`
        + `<path d="${ring}" fill="none" stroke="${line(P.meridian)}" stroke-width="${f(2.6 * k)}"/>`
        + `<path d="${ring}" fill="none" stroke="${P.meridian}" stroke-width="${f(1.4 * k)}"/>`
        + `<circle cx="0" cy="${f(-R)}" r="${f(1.4 * k)}" fill="${P.meridian}"/><circle cx="0" cy="${f(R)}" r="${f(1.4 * k)}" fill="${P.meridian}"/></g>`);
    m.push(`<ellipse cx="${f(cx - 5 * k)}" cy="${f(gy - 6 * k)}" rx="${f(3.6 * k)}" ry="${f(2.2 * k)}" fill="${RULES.highlight}" opacity=".55"/>`);

    return shadow(pen, cx + 2, foot, 14 * k, 3 * k, 0.3) + rimmed(m.join(''));
}

/** The office pieces by kind — `scenery()` draws a kind it finds here with the cell's size, foot and seeded draws. */
const OFFICE = Object.freeze({
    'water-cooler': waterCooler,
    'filing-cabinet': filingCabinet,
    copier,
    'coat-rack': coatRack,
    whiteboard,
    aquarium,
    'bean-bag': beanBag,
    globe,
});

/**
 * THE RESTROOM DOOR (card#12786, FLOOR.md § 6.7 item 2), its foot on the cell's floor line: a wooden door FRAME face-on —
 * 6 px jambs, a 10 px head under a small cream pediment, a 4 px sill — and inside it the dark RECESS of an open doorway;
 * above it the SIGN PLATE at `plate`, a cream rounded plate carrying the restroom pictogram — two round-headed figures side
 * by side, one in a triangle skirt, in the line colour — and at `sign` the slider plate's RIM. No leaf: the doorway is drawn
 * open and empty, and the painter draws the leaf over it and the vacant / occupied field inside the rim. No text, no
 * light, nothing that moves. `sign` and `plate` are the scene's (§ 12's *Restroom plates*), in the cell's px; a rect not
 * handed is not drawn.
 */
function restroomDoor(pen, w, foot, sign, plate) {
    const P = PALETTE['restroom-door'];
    const top = 22;
    const out = [shadow(pen, w / 2 + 2, foot, w * 0.5, 3, 0.28)];

    // the frame: the head and the jambs round the recess, the pediment over the head, the sill at the foot
    out.push(rimmed(`<path d="${rr(0, top, w, foot - top, 2)}" fill="${pen.face(P.frame, 0.2, 0.18)}" stroke="${line(P.frame)}" stroke-width=".9"/>`
        + `<path d="${rr(6, top + 10, w - 12, foot - top - 14, 1)}" fill="${pen.v([[0, P.recess], [1, shade(P.recess, 0.3)]])}"/>`
        + `<path d="${rr(-1, top - 4, w + 2, 6, 2)}" fill="${pen.face(P.pediment, 0.15, 0.2)}" stroke="${line(P.pediment)}" stroke-width=".7"/>`
        + `<path d="${rr(-1, foot - 4, w + 2, 4, 1)}" fill="${pen.face(P.sill, 0.2, 0.15)}" stroke="${line(P.sill)}" stroke-width=".6"/>`));

    if (plate !== null) {
        const { x, y, w: pw, h: ph } = plate;
        const ink = RULES.line;
        const fig = (cx, skirt) => `<circle cx="${f(cx)}" cy="${f(y + ph * 0.3)}" r="${f(ph * 0.14)}" fill="${ink}"/>`
            + (skirt
                ? `<path d="M${f(cx)} ${f(y + ph * 0.46)}L${f(cx + ph * 0.24)} ${f(y + ph * 0.86)}H${f(cx - ph * 0.24)}Z" fill="${ink}"/>`
                : `<path d="${rr(cx - ph * 0.12, y + ph * 0.46, ph * 0.24, ph * 0.4, ph * 0.08)}" fill="${ink}"/>`);

        out.push(rimmed(`<path d="${rr(x, y, pw, ph, 4)}" fill="${pen.face(P.plate, 0.12, 0.2)}" stroke="${line(P.plate)}" stroke-width=".7"/>`
            + fig(x + pw * 0.36, false) + fig(x + pw * 0.64, true)));
    }

    if (sign !== null) {
        out.push(`<path d="${rr(sign.x - 1.5, sign.y - 1.5, sign.w + 3, sign.h + 3, 3)}" fill="none" stroke="${P.rim}" stroke-width="2"/>`
            + `<path d="${rr(sign.x - 2.5, sign.y - 2.5, sign.w + 5, sign.h + 5, 3.5)}" fill="none" stroke="${line(P.rim)}" stroke-width=".6"/>`);
    }

    return out.join('');
}

/**
 * THE SNACK CORNER's TWO TABLES (card#12749, FLOOR.md § 6.6 item 2): `top` is the table top's height above the foot —
 * waist-high to the 120 px character for `snack-table`, to the 56 px intern for `snack-table-low` — and `n` the donuts
 * and the cups it carries. What stands on the top is drawn at the scale the cell's width sets against the tall table's
 * 72 px, so the low set is the same things, smaller.
 */
const SNACK = Object.freeze({
    'snack-table': Object.freeze({ top: 60, n: 3 }),
    'snack-table-low': Object.freeze({ top: 28, n: 2 }),
});

/** One donut seen from the front and above, its centre at (x, y): a dough ring, its topping, its hole, sprinkles on one. */
function donut(pen, x, y, k, topping, P) {
    const rx = 5.4 * k;
    const ry = 3.4 * k;
    const icing = topping === 'glazed' ? P.glaze : topping === 'chocolate' ? P.chocolate : P.icing;
    const drip = `M${f(x - rx * 0.92)} ${f(y)}Q${f(x - rx * 0.7)} ${f(y - ry * 1.05)} ${f(x)} ${f(y - ry * 0.98)}`
        + `Q${f(x + rx * 0.7)} ${f(y - ry * 1.05)} ${f(x + rx * 0.92)} ${f(y)}Q${f(x + rx * 0.55)} ${f(y + ry * 0.55)} ${f(x + rx * 0.3)} ${f(y + ry * 0.2)}`
        + `Q${f(x)} ${f(y + ry * 0.75)} ${f(x - rx * 0.35)} ${f(y + ry * 0.3)}Q${f(x - rx * 0.65)} ${f(y + ry * 0.6)} ${f(x - rx * 0.92)} ${f(y)}Z`;
    const m = [
        `<ellipse cx="${f(x)}" cy="${f(y + 0.8 * k)}" rx="${f(rx)}" ry="${f(ry)}" fill="${pen.paint(P.dough, 0.3)}" stroke="${line(P.dough)}" stroke-width=".6"/>`,
        `<path d="${drip}" fill="${pen.paint(icing, 0.36)}" stroke="${line(icing)}" stroke-width=".5"/>`,
        `<ellipse cx="${f(x)}" cy="${f(y - 0.2 * k)}" rx="${f(1.7 * k)}" ry="${f(0.95 * k)}" fill="${shade(P.dough, 0.45)}"/>`,
        `<ellipse cx="${f(x - rx * 0.4)}" cy="${f(y - ry * 0.55)}" rx="${f(1.5 * k)}" ry="${f(0.6 * k)}" fill="${RULES.highlight}" opacity=".7"/>`,
    ];

    if (topping === 'sprinkled') {
        [[-3.2, -1.4, 30], [-1, -2.4, -40], [2.2, -1.9, 70], [3.4, -0.4, -20], [-2.4, 0.4, 80], [1, 0.9, 10]].forEach(([dx, dy, a], i) => {
            const px = x + dx * k;
            const py = y + dy * k;

            m.push(`<path d="M${f(px - 0.7 * k)} ${f(py)}h${f(1.4 * k)}" transform="rotate(${a} ${f(px)} ${f(py)})" stroke="${P.sprinkles[i % P.sprinkles.length]}" stroke-width="${f(0.7 * k)}" stroke-linecap="round"/>`);
        });
    }

    return m.join('');
}

/** One cream cup standing at (x, y): its handle to the right, a band of gold round it, coffee at its brim. */
function cup(pen, x, y, k, P) {
    const w = 5.6 * k;
    const h = 6.2 * k;

    return `<path d="M${f(x + w / 2 - 0.4 * k)} ${f(y - h * 0.78)}q${f(2.8 * k)} ${f(0.2 * k)} ${f(2.4 * k)} ${f(2.4 * k)}q${f(-0.3 * k)} ${f(1.7 * k)} ${f(-2.4 * k)} ${f(1.6 * k)}" fill="none" stroke="${line(P.cup)}" stroke-width="${f(1.1 * k)}"/>`
        + `<path d="M${f(x - w / 2)} ${f(y - h)}H${f(x + w / 2)}L${f(x + w * 0.4)} ${f(y - 0.6 * k)}Q${f(x + w * 0.38)} ${f(y)} ${f(x + w * 0.2)} ${f(y)}H${f(x - w * 0.2)}Q${f(x - w * 0.38)} ${f(y)} ${f(x - w * 0.4)} ${f(y - 0.6 * k)}Z" fill="${pen.face(P.cup, 0.2, 0.22)}" stroke="${line(P.cup)}" stroke-width=".6"/>`
        + `<path d="M${f(x - w * 0.44)} ${f(y - h * 0.55)}H${f(x + w * 0.44)}" stroke="${P.band}" stroke-width="${f(1.1 * k)}"/>`
        + `<ellipse cx="${f(x)}" cy="${f(y - h)}" rx="${f(w / 2)}" ry="${f(k)}" fill="${shade(P.coffee, 0.1)}" stroke="${line(P.cup)}" stroke-width=".5"/>`;
}

/**
 * A SNACK TABLE (§ 6.6 item 2): a contact shadow, four wooden legs, a cloth over the top whose front hangs in scallops,
 * and on it a coffee maker with its carafe on the hotplate — its lamp a fixed warm dot, never a status light — an open
 * pink donut box with `n` donuts (a glazed, a chocolate, a sprinkled), and `n` cream cups, the first with a drawn curl of
 * steam. Nothing on it moves and it writes no text.
 */
function snackTable(pen, { top, n }, w, foot) {
    const P = PALETTE['snack-table'];
    const k = w / 72;
    const x0 = 2 * k;
    const x1 = w - 2 * k;
    const yt = foot - top;
    const legs = [];
    const out = [shadow(pen, w / 2 + 2 * k, foot, (x1 - x0) * 0.52, 3.2 * k, 0.3)];

    // the back legs, shorter and in shade, then the front ones, tapering to the floor
    for (const lx of [x0 + 7 * k, x1 - 9 * k]) {
        legs.push(`<path d="${rr(lx, yt + 2, 2.4 * k, top - 5 * k, 1)}" fill="${shade(P.wood, 0.25)}" stroke="${line(P.wood)}" stroke-width=".5"/>`);
    }

    for (const lx of [x0 + 3 * k, x1 - 6.5 * k]) {
        legs.push(`<path d="M${f(lx)} ${f(yt + 4)}h${f(3.5 * k)}l${f(-0.5 * k)} ${f(top - 4)}h${f(-2.5 * k)}Z" fill="${pen.face(P.wood, 0.2, 0.2)}" stroke="${line(P.wood)}" stroke-width=".6"/>`);
    }

    out.push(rimmed(legs.join('')));

    // the top: its wooden slab seen from above, a cloth over it, the cloth's front hanging in scallops
    const depth = 7 * k;
    const scallops = Math.max(3, Math.round((x1 - x0) / (8 * k)));
    const sw = (x1 - x0) / scallops;
    let hem = `M${f(x0)} ${f(yt - 0.6 * k)}V${f(yt)}`;

    for (let i = 0; i < scallops; i++) {
        hem += `Q${f(x0 + (i + 0.5) * sw)} ${f(yt + 6 * k)} ${f(x0 + (i + 1) * sw)} ${f(yt)}`;
    }

    out.push(rimmed(`<path d="${rr(x0 - 1, yt - depth, x1 - x0 + 2, depth + 3 * k, 3 * k)}" fill="${pen.face(P.wood, 0.25, 0.15)}" stroke="${line(P.wood)}" stroke-width=".8"/>`
        + `<path d="M${f(x0 + 1)} ${f(yt - depth + 1.2 * k)}H${f(x1 - 1)}V${f(yt - 0.5 * k)}H${f(x0 + 1)}Z" fill="${pen.v([[0, tint(P.cloth, 0.3)], [1, P.cloth]])}"/>`
        + `<path d="${hem}V${f(yt - 0.6 * k)}Z" fill="${pen.face(P.cloth, 0.15, 0.16)}" stroke="${line(P.cloth)}" stroke-width=".6"/>`));

    const base = yt - 2 * k;
    const back = [];

    // the coffee maker, at the left: its tower, its hood, its foot with the hotplate, the carafe on it, the lamp a dot
    const mx = x0 + 3 * k;
    const mw = 19 * k;
    const mh = 31 * k;
    const cxk = mx + 6.5 * k;
    const cw = 9 * k;
    const ch = 13 * k;
    const ct = base - 3.4 * k - ch;

    back.push(`<path d="${rr(mx + mw - 7 * k, base - mh, 7 * k, mh, 2.2 * k)}" fill="${pen.face(P.maker, 0.25, 0.25)}" stroke="${line(P.maker)}" stroke-width=".7"/>`);
    back.push(`<path d="${rr(mx, base - mh, mw, 8 * k, 2.6 * k)}" fill="${pen.paint(P.maker, 0.34)}" stroke="${line(P.maker)}" stroke-width=".7"/>`);
    back.push(`<path d="${rr(mx - 0.5 * k, base - 3.4 * k, mw + k, 3.4 * k, 1.4 * k)}" fill="${pen.face(shade(P.maker, 0.1), 0.2, 0.2)}" stroke="${line(P.maker)}" stroke-width=".6"/>`);
    back.push(`<path d="M${f(cxk - cw / 2 + k)} ${f(ct)}H${f(cxk + cw / 2 - k)}L${f(cxk + cw / 2)} ${f(ct + ch * 0.45)}Q${f(cxk + cw / 2 + 0.5 * k)} ${f(ct + ch)} ${f(cxk)} ${f(ct + ch)}Q${f(cxk - cw / 2 - 0.5 * k)} ${f(ct + ch)} ${f(cxk - cw / 2)} ${f(ct + ch * 0.45)}Z" fill="${P.carafe}" stroke="${line(P.carafe)}" stroke-width=".6"/>`);
    back.push(`<path d="M${f(cxk - cw / 2 + 0.2 * k)} ${f(ct + ch * 0.5)}H${f(cxk + cw / 2 - 0.2 * k)}Q${f(cxk + cw / 2)} ${f(ct + ch - 0.4 * k)} ${f(cxk)} ${f(ct + ch - 0.4 * k)}Q${f(cxk - cw / 2)} ${f(ct + ch - 0.4 * k)} ${f(cxk - cw / 2 + 0.2 * k)} ${f(ct + ch * 0.5)}Z" fill="${pen.paint(P.coffee, 0.3)}"/>`);
    back.push(`<path d="M${f(cxk - cw / 2 + 1.4 * k)} ${f(ct + 1.6 * k)}v${f(ch * 0.4)}" stroke="${RULES.highlight}" stroke-width="${f(k)}" stroke-linecap="round" opacity=".8"/>`);
    back.push(`<path d="M${f(cxk - cw / 2 - 0.2 * k)} ${f(ct + 2 * k)}q${f(-3 * k)} ${f(0.5 * k)} ${f(-2.6 * k)} ${f(3.4 * k)}q${f(0.3 * k)} ${f(2 * k)} ${f(2.4 * k)} ${f(2.2 * k)}" fill="none" stroke="${line(P.maker)}" stroke-width="${f(1.1 * k)}"/>`);
    back.push(`<circle cx="${f(mx + mw - 3.5 * k)}" cy="${f(base - 6 * k)}" r="${f(1.3 * k)}" fill="${P.lamp}" stroke="${line(P.lamp)}" stroke-width=".4"/>`);

    // the donut box, open: its lid tipped back with a cream ribbon, the inside's shade at its back edge
    const bx = x0 + 25 * k;
    const bw = 28 * k;
    const bh = 9 * k;

    back.push(`<path d="M${f(bx + k)} ${f(base - bh)}L${f(bx + 3 * k)} ${f(base - bh - 15 * k)}H${f(bx + bw - 3 * k)}L${f(bx + bw - k)} ${f(base - bh)}Z" fill="${pen.face(tint(P.box, 0.18), 0.2, 0.1)}" stroke="${line(P.box)}" stroke-width=".7"/>`);
    back.push(`<path d="M${f(bx + 4.5 * k)} ${f(base - bh - 4 * k)}Q${f(bx + bw / 2)} ${f(base - bh - 11 * k)} ${f(bx + bw - 4.5 * k)} ${f(base - bh - 4 * k)}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(1.4 * k)}" stroke-linecap="round" opacity=".75"/>`);
    back.push(`<path d="M${f(bx)} ${f(base - bh)}H${f(bx + bw)}V${f(base - bh + 2 * k)}H${f(bx)}Z" fill="${shade(P.box, 0.3)}"/>`);
    out.push(rimmed(back.join(''), 1.1, 0.45));

    // the donuts, showing over the box's front, then the front
    const pitch = bw / (n + 0.4);

    ['glazed', 'chocolate', 'sprinkled'].slice(0, n).forEach((t, i) => {
        out.push(donut(pen, bx + pitch * (i + 0.7), base - bh - 2.2 * k, k, t, P));
    });
    out.push(rimmed(`<path d="${rr(bx, base - bh + 1.4 * k, bw, bh - 1.4 * k, 1.6 * k)}" fill="${pen.face(P.box, 0.22, 0.2)}" stroke="${line(P.box)}" stroke-width=".7"/>`
        + `<path d="M${f(bx + 3 * k)} ${f(base - bh / 2 + 0.8 * k)}h${f(bw - 6 * k)}" stroke="${RULES.highlight}" stroke-width="${f(1.2 * k)}" stroke-dasharray="${f(2 * k)} ${f(1.6 * k)}" opacity=".85"/>`));

    // the cups: the first at the back right with its curl of steam, drawn once and still; the rest in front of the box
    const cups = [[x1 - 8 * k, base], [x1 - 17 * k, base + 2.4 * k], [x1 - 26 * k, base + 2.6 * k]].slice(0, n);
    const [sx, sy] = cups[0];
    const steam = `M${f(sx - 0.6 * k)} ${f(sy - 7.4 * k)}c${f(-2.6 * k)} ${f(-2.4 * k)} ${f(2.6 * k)} ${f(-3.6 * k)} ${f(0.2 * k)} ${f(-6.2 * k)}`
        + `c${f(-2.2 * k)} ${f(-2.2 * k)} ${f(2.2 * k)} ${f(-3.2 * k)} ${f(0.6 * k)} ${f(-5.6 * k)}`;

    out.push(`<path d="${steam}" fill="none" stroke="${shade(RULES.highlight, 0.3)}" stroke-width="${f(1.9 * k)}" stroke-linecap="round" opacity=".35"/>`
        + `<path d="${steam}" fill="none" stroke="${RULES.highlight}" stroke-width="${f(1.1 * k)}" stroke-linecap="round"/>`);

    for (const [ux, uy] of cups) {
        out.push(rimmed(cup(pen, ux, uy, k, P), 1, 0.45));
    }

    return out.join('');
}

/** How far each floor plant's shape reaches at unit scale, its pot and leaves together — what a cell fits it by. */
const PLANT_REACH = Object.freeze({
    tall: Object.freeze({ w: 60, h: 76 }),
    round: Object.freeze({ w: 54, h: 42 }),
    monstera: Object.freeze({ w: 88, h: 64 }),
});

// ── a desk's furniture set ────────────────────────────────────────────────────────────────────

/** The chair's rect (§ 10.6's table): 54 × 64. */
const CHAIR = Object.freeze({ w: 54, h: 64 });

/**
 * THE CHAIR's BACK, behind the creature, at every desk with art — its colour seeded from the desk's key. With
 * nobody in it it is § 7.1's empty chair; the drawing itself carries no fact.
 *
 * @param {{seat_id: string}} input the desk's key
 */
export function chair({ seat_id: seatId }) {
    const pen = new Pen();
    const c = pick(seatId, 'theme:chair', PALETTE.chair.colours);
    const cx = CHAIR.w / 2;
    const w = 52;
    const y = 2;

    return doc(pen, CHAIR.w, CHAIR.h, rimmed(`<path d="${rr(cx - w / 2, y, w, 46, 16)}" fill="${pen.paint(c, 0.3)}" stroke="${line(c)}" stroke-width=".9"/>`
        + `<path d="M${f(cx - w / 2 + 9)} ${y + 8}Q${f(cx)} ${y + 2} ${f(cx + w / 2 - 9)} ${y + 8}" stroke="${tint(c, 0.5)}" stroke-width="2" fill="none" stroke-linecap="round" opacity=".7"/>`
        + `<path d="M${f(cx - 16)} ${y + 16}v12M${f(cx)} ${y + 14}v14M${f(cx + 16)} ${y + 16}v12" stroke="${shade(c, 0.2)}" stroke-width="1.2" stroke-linecap="round" opacity=".35"/>`));
}

/** The desk's rect: 180 × 60. */
const DESK = Object.freeze({ w: 180, h: 60 });

/**
 * THE DESK, SEEN FROM ITS FAR SIDE (§ 10.4's projection, item 2): a top sliver, one modesty panel between two
 * proud pedestal ends, one raised inset panel, a plinth — no drawers, no handles, no knee-hole.
 *
 * Handed the desk's key like every piece of the set; the back is the same at every desk, so it draws nothing
 * from it.
 */
export function desk() {
    const pen = new Pen();
    const wood = PALETTE.desk.wood;
    const { w, h } = DESK;
    const top = 11;
    const fy = top;
    const fh = h - top - 2;
    const s = [];

    s.push(shadow(pen, w / 2 + 6, h - 1, w * 0.56, 4, 0.34));
    s.push(`<path d="${rr(0, 0, w, top + 4, 4)}" fill="${pen.face(tint(wood, 0.2), 0.25, 0.05)}" stroke="${line(wood)}" stroke-width=".9"/>`);
    s.push(`<path d="${rr(4, fy, w - 8, fh, 3)}" fill="${pen.face(wood, 0.08, 0.22)}" stroke="${line(wood)}" stroke-width=".9"/>`);
    s.push(`<path d="M4 ${fy + 0.5}H${w - 4}" stroke="${shade(wood, 0.35)}" stroke-width="1.4" opacity=".5"/>`);
    // the modesty panel, and the raised inset panel on it — the back's one ornament
    s.push(`<path d="${rr(8, fy + 3, w - 16, fh - 9, 3)}" fill="${pen.face(shade(wood, 0.04), 0.1, 0.18)}" stroke="${line(wood)}" stroke-width=".6" opacity=".95"/>`);
    s.push(`<path d="${rr(18, fy + 8, w - 36, fh - 20, 4)}" fill="none" stroke="${tint(wood, 0.35)}" stroke-width="1.1" opacity=".55"/>`);
    s.push(`<path d="${rr(19, fy + 9, w - 36, fh - 20, 4)}" fill="none" stroke="${shade(wood, 0.3)}" stroke-width=".9" opacity=".35"/>`);
    // the two proud pedestal ends
    for (const px of [4, w - 12]) {
        s.push(`<path d="${rr(px, fy, 8, fh, 2.5)}" fill="${pen.face(wood, 0.16, 0.2)}" stroke="${line(wood)}" stroke-width=".7"/>`);
    }
    // the plinth
    s.push(`<path d="${rr(6, h - 9, w - 12, 7, 2)}" fill="${pen.face(shade(wood, 0.18), 0.05, 0.15)}" stroke="${line(wood)}" stroke-width=".6"/>`);

    return doc(pen, w, h, rimmed(s.join('')));
}

/** The monitor frame's rect: 96 × 46 — the screen inside it, inset 4 px and 31 px tall, is the painter's. */
const MONITOR = Object.freeze({ w: 96, h: 46, bezel: 40 });

/**
 * THE MONITOR's FRAME, facing the viewer (the projection's ruled exception): a cream bezel round the screen,
 * a stand and a foot on the desk, all inside its rect. No power light: the desk's lit state is the screen's
 * to say (§ 10.6 item 3).
 *
 * Handed the desk's key like every piece of the set, and draws nothing from it.
 */
export function monitorFrame() {
    const pen = new Pen();
    const b = PALETTE['monitor-frame'].bezel;
    const { w, h, bezel } = MONITOR;
    const c = w / 2;
    const s = [];

    s.push(`<path d="M${c - 5} ${bezel - 2}h10l2.4 ${h - bezel - 0.4}h-14.8Z" fill="${pen.face(b, 0.1, 0.25)}" stroke="${line(b)}" stroke-width=".7"/>`);
    s.push(`<ellipse cx="${c}" cy="${h - 2.2}" rx="16" ry="2" fill="${pen.paint(b)}" stroke="${line(b)}" stroke-width=".7"/>`);
    s.push(`<path d="${rr(0.5, 0.5, w - 1, bezel - 1, 7)}" fill="${pen.paint(b, 0.35)}" stroke="${line(b)}" stroke-width=".9"/>`);

    return doc(pen, w, h, rimmed(s.join('')));
}

/** The desk props' rect: 68 × 50, the desk's left third; the desk's top lies 34 px down it. */
const PROPS = Object.freeze({ w: 68, h: 50, top: 42 });

/**
 * THE DESK PROPS, on the desk's left third: a desk lamp leaning in, with its small pool of light on the desk
 * top, and beside it a mug or a small plant — the choice and the colours seeded per desk.
 *
 * @param {{seat_id: string}} input the desk's key
 */
export function deskProps({ seat_id: seatId }) {
    const pen = new Pen();
    const key = seatId;
    const P = PALETTE['desk-props'];
    const lamp = pick(key, 'theme:lamp', P.lamps);
    const pot = pick(key, 'theme:mug', P.pots);
    const y = PROPS.top;
    const x = 20;
    const s = [];
    const m = [];

    s.push(`<ellipse cx="${x + 14}" cy="${y - 2}" rx="34" ry="9" fill="${pen.soft(P.pool, 0.55)}"/>`);
    m.push(`<ellipse cx="${x}" cy="${y - 1.5}" rx="9" ry="3" fill="${pen.paint(lamp)}" stroke="${line(lamp)}" stroke-width=".7"/>`);
    m.push(`<path d="M${x} ${y - 3}L${x - 4} ${y - 22}L${x + 10} ${y - 34}" fill="none" stroke="${line(lamp)}" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>`);
    m.push(`<path d="M${x} ${y - 3}L${x - 4} ${y - 22}L${x + 10} ${y - 34}" fill="none" stroke="${tint(lamp, 0.15)}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>`);
    m.push(`<path d="M${x + 4} ${y - 38}q8 -6 16 2l3 9q-11 4 -21 -1z" fill="${pen.paint(lamp)}" stroke="${line(lamp)}" stroke-width=".8"/>`);
    m.push(`<ellipse cx="${x + 13}" cy="${y - 28}" rx="6" ry="2" fill="${P.pool}"/>`);
    s.push(rimmed(m.join('')));

    if (draw(key, 'theme:prop') % 2 === 0) {
        const mx = x + 24;
        const my = y + 1;

        s.push(rimmed(`<path d="M${mx + 7} ${my - 9}q5 0 5 4t-5 4" fill="none" stroke="${line(pot)}" stroke-width="1.6"/>`
            + `<path d="${rr(mx - 6, my - 12, 13, 12, 3)}" fill="${pen.paint(pot)}" stroke="${line(pot)}" stroke-width=".8"/>`
            + `<ellipse cx="${mx + 0.5}" cy="${my - 11.5}" rx="5.6" ry="1.5" fill="${shade(pot, 0.6)}"/>`)
            + `<path d="M${mx - 1} ${my - 15}q-2 -4 1 -7t0 -6" stroke="${RULES.highlight}" stroke-width="1.2" fill="none" opacity=".5" stroke-linecap="round"/>`);
    } else {
        s.push(pottedPlant(pen, x + 24, y + 1, pot, P.leaf, 0.45, 'round', stream(key, 'theme:prop-plant')));
    }

    return doc(pen, PROPS.w, PROPS.h, s.join(''));
}

/**
 * The side seating's rect (its foot line its bottom edge) and the seats' rows: § 12's seats, one under each intern's
 * place on the interns' rows `desk-layout.js` lays (`stoolSlots()`: `per_row` to a row at `seat_pitch` from the rect's
 * inset, each row behind the front one `row_dy` higher). A theme is handed the seat count and nothing else (FLOOR.md
 * § 10.6 item 2), so the rows are stated here, and `tools/floor-themes/selftest.mjs` holds them to the layout's own slots
 * — a row that drifts from the layout's reds. `foot_lift` is how far above a row's foot line an intern's feet stand —
 * the chibi frame's foot line (`resources/characters`' `FOOT`) under its 108 units over `seat_w` px, which the same leg
 * holds — so the cushion's top ends where the feet are and an intern sits ON its cushion.
 */
const TABLE = Object.freeze({ w: 228, h: 98, seat_x: 4, seat_pitch: 41, seat_w: 35, per_row: 4, row_dy: 56, foot_lift: 1.3 });

/**
 * THE SIDE SEATING, at every desk with art — a LOW COUCH per row of interns (the operator's ruling of 2026-10-27 on
 * card#12444, *"Couch A"*, in place of the tea table, which *"doesn't make sense … behind the subagents"*): one cushioned
 * sofa under and behind each row's interns, as long as that row's places (`seat_pitch` apart from `seat_x`, so it
 * shortens or lengthens with the pitch), with a low back rising to under their chins, one seat cushion per place whose
 * top ends at the interns' feet, round arms at its ends and little wooden feet; the back row's first, so the front couch
 * is drawn over it. As many places as it is handed — so an empty desk shows its front couch — and a plant beside the front
 * couch where the rect leaves room. The table's teapot went with the table.
 *
 * @param {{seat_id: string, seats: number}} input the desk's key and the seat count
 */
export function sideTable({ seat_id: seatId, seats }) {
    finite(seats);

    const pen = new Pen();
    const key = seatId;
    const P = PALETTE['side-table'];
    const c = pick(key, 'theme:couch', P.couch);
    const rows = Math.ceil(seats / TABLE.per_row);
    const gap = TABLE.seat_pitch - TABLE.seat_w;
    const s = [];
    let frontEnd = 0;

    for (let row = rows - 1; row >= 0; row--) {
        const n = Math.min(TABLE.per_row, seats - row * TABLE.per_row);
        const foot = TABLE.h - row * TABLE.row_dy;
        const seat = foot - TABLE.foot_lift;
        // the couch spans its places: from the first's left edge to the last's right edge, the arms just outside
        const x0 = TABLE.seat_x - 1;
        const w = (n - 1) * TABLE.seat_pitch + TABLE.seat_w + 2;
        const m = [];

        // the back, low, rising behind the interns to under their chins, buttoned once a place
        m.push(`<path d="${rr(x0 + 2, foot - 24, w - 4, 16, 7)}" fill="${pen.paint(c, 0.28)}" stroke="${line(c)}" stroke-width=".9"/>`);

        for (let i = 0; i < n; i++) {
            m.push(`<circle cx="${f(TABLE.seat_x + i * TABLE.seat_pitch + TABLE.seat_w / 2)}" cy="${f(foot - 18)}" r="1" fill="${line(c)}" opacity=".55"/>`);
        }

        // one seat cushion a place, its top ending at the interns' feet and its front at the foot line
        for (let i = 0; i < n; i++) {
            const left = i === 0 ? x0 + 1 : TABLE.seat_x + i * TABLE.seat_pitch - gap / 2;
            const right = i === n - 1 ? x0 + w - 1 : TABLE.seat_x + i * TABLE.seat_pitch + TABLE.seat_w + gap / 2;

            m.push(`<path d="${rr(left, seat - 9, right - left, 9, 3.5)}" fill="${pen.face(tint(c, 0.14), 0.2, 0.1)}" stroke="${line(c)}" stroke-width=".7"/>`);
            m.push(`<path d="${rr(left + 0.5, seat - 1.5, right - left - 1, foot - seat + 1.5, 1)}" fill="${pen.face(shade(c, 0.12))}"/>`);
        }

        // the arms, round and low, at each end
        for (const ax of [x0 - 3, x0 + w - 5]) {
            m.push(`<path d="${rr(ax, foot - 18, 8, 15, 4)}" fill="${pen.paint(c, 0.32)}" stroke="${line(c)}" stroke-width=".9"/>`);
        }

        // little wooden feet
        for (const lx of [x0 + 2, x0 + w - 4]) {
            m.push(`<path d="M${f(lx)} ${f(foot - 3)}h2.4l-.4 3h-1.6Z" fill="${pen.face(shade(P.wood, 0.1))}" stroke="${line(P.wood)}" stroke-width=".5"/>`);
        }

        s.push(shadow(pen, x0 + w / 2, foot - 0.5, w * 0.52, 3, 0.24) + rimmed(m.join('')));

        if (row === 0) {
            frontEnd = x0 + w;
        }
    }

    // a plant beside the front couch, where the rect leaves room
    if (frontEnd + 30 <= TABLE.w) {
        const shape = ['tall', 'round', 'monstera'][draw(key, 'theme:table-plant') % 3];

        s.push(pottedPlant(pen, frontEnd + 16, TABLE.h - 2, pick(key, 'theme:table-pot', P.pots), pick(key, 'theme:table-leaf', P.leaves), shape === 'monstera' ? 0.55 : 0.85, shape, stream(key, 'theme:table-plant-shape')));
    }

    return doc(pen, TABLE.w, TABLE.h, s.join(''));
}

/**
 * § 10.6 item 1's optional `piece3d` (card#13033 stage 2): a standing kind the furniture kit carries no piece for, as the
 * part list the 3-D floor builds — `./pieces-3d.js`'s, in this theme's `PALETTE` — or `null` for a kind it does not draw.
 */
export function piece3d(kind, inputs) {
    return pieces3d(PALETTE, kind, inputs);
}
