// THE HOUSE THEME, `studio` — A2+C — `docs/design/FLOOR.md § 10.6` (card#11046, Appendix B row 22): the
// room the operator chose on 2026-10-07, ported from the seat's approved prototype and not redesigned.
// It DRAWS, as code, every piece of a floor that carries no fact: the band's wall, the window, elevator and
// clock surrounds, each grid's plane (its floor, its light, its accent region, its walls and the landing),
// the standing scenery, and a desk's furniture set (the chair's back, the desk's back, the monitor's frame,
// the desk props, the side table and its seats).
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
// every load and in every browser. The relative import resolves the same on disk and under the asset
// route: both trees sit under `/art/`.

import { draw } from '../../../characters/seed.js';

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
    chair: Object.freeze({ colours: Object.freeze(['#c8714f', '#d9a54a', '#8fa66b', '#c98b8f', '#6f9a9a', '#a77c5a']) }),
    'monitor-frame': Object.freeze({ bezel: '#efe2cc' }),
    'desk-props': Object.freeze({
        lamps: Object.freeze(['#c8714f', '#6f9a9a', '#d9a54a', '#8fa66b']),
        pots: Object.freeze(['#e9d8c4', '#c8553d', '#6f8fb0', '#e0a43a', '#7f9f6a', '#d97f8f']),
        leaf: '#79a85a',
        pool: '#fff3d6',
    }),
    'side-table': Object.freeze({
        wood: '#c48d5c', teapot: '#7f9f6a', lid: '#e0a43a',
        seats: Object.freeze(['#c98b8f', '#d9a54a', '#8fa66b', '#6f9a9a', '#c8714f']),
        pots: Object.freeze(['#e9d8c4', '#6f8fb0', '#c8714f', '#d9a54a']),
        leaves: Object.freeze(['#79a85a', '#6b9a52', '#8db55e']),
    }),
    bookcase: Object.freeze({ shelf: '#a8714a', books: Object.freeze(['#c8553d', '#e0a43a', '#6f8fb0', '#7f9f6a', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f']) }),
    plant: Object.freeze({ pots: Object.freeze(['#b9643f', '#d9a54a', '#e9d8c4', '#6f8fb0', '#c8714f']), leaf: '#6f9a52' }),
    'floor-lamp': Object.freeze({ metal: '#b08a4a', shade: '#f2dcae' }),
    armchair: Object.freeze({ cover: '#c8714f', legs: '#8a5a3a' }),
    cushion: Object.freeze({ colours: Object.freeze(['#c98b8f', '#d9a54a']) }),
    'book-pile': Object.freeze({ books: Object.freeze(['#c8553d', '#e0a43a', '#6f8fb0', '#7f9f6a', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f']) }),
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

// ── colour arithmetic ─────────────────────────────────────────────────────────────────────────

function rgb(hex) {
    const h = hex.slice(1);

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

function hex(c) {
    return `#${c.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')}`;
}

/** `a` mixed toward `b` by `t` — over sRGB, as the painter composites (and as the prototype mixed). */
export function mix(a, b, t) {
    const A = rgb(a);
    const B = rgb(b);

    return hex(A.map((v, i) => v + (B[i] - v) * t));
}

const shade = (c, t) => mix(c, RULES.shade, t);
const tint = (c, t) => mix(c, RULES.highlight, t);
const line = (c) => mix(c, RULES.line, 0.5);

/** Two decimals, the precision every coordinate is written at. */
const f = (v) => Number((+v).toFixed(2));

// ── seeded draws ──────────────────────────────────────────────────────────────────────────────

/** A seeded unit value in [0, 1), one named field of one key — through the tree's `draw()`. */
const unit = (key, field) => draw(key, field) / 4294967296;

/**
 * A run of seeded unit values for one field — the n-th value is its own named draw (`field:n`), so a run is
 * as stable as a single draw and adding a run disturbs no other field.
 */
function stream(key, field) {
    let n = 0;

    return () => unit(key, `${field}:${n++}`);
}

const pick = (key, field, list) => list[draw(key, field) % list.length];

// ── one document's gradients ──────────────────────────────────────────────────────────────────

/** The gradients ONE document defines, each made once and referenced by its fragment id. */
class Pen {
    constructor() {
        this.defs = [];
        this.made = new Map();
    }

    memo(key, make) {
        if (!this.made.has(key)) {
            const id = `g${this.made.size}`;

            this.defs.push(make(id));
            this.made.set(key, `url(#${id})`);
        }

        return this.made.get(key);
    }

    /** A rounded body's paint: lit from the top-left. */
    paint(c, k = 0.42) {
        return this.memo(`p${c}${k}`, (id) => `<radialGradient id="${id}" cx=".38" cy=".3" r=".85" fx=".3" fy=".2"><stop offset="0" stop-color="${tint(c, k)}"/><stop offset=".5" stop-color="${c}"/><stop offset="1" stop-color="${shade(c, 0.26)}"/></radialGradient>`);
    }

    /** A flat face's paint: lighter at its top-left, darker at its foot. */
    face(c, a = 0.18, b = 0.16) {
        return this.memo(`f${c}${a}${b}`, (id) => `<linearGradient id="${id}" x1="0" y1="0" x2=".35" y2="1"><stop offset="0" stop-color="${tint(c, a)}"/><stop offset=".55" stop-color="${c}"/><stop offset="1" stop-color="${shade(c, b)}"/></linearGradient>`);
    }

    /** A vertical gradient: `[offset, colour, opacity?]` stops, top to bottom. */
    v(stops) {
        return this.memo(`v${JSON.stringify(stops)}`, (id) => `<linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1">${stops.map(([o, c, op = 1]) => `<stop offset="${o}" stop-color="${c}" stop-opacity="${op}"/>`).join('')}</linearGradient>`);
    }

    /** A horizontal gradient, left to right. */
    h(stops) {
        return this.memo(`h${JSON.stringify(stops)}`, (id) => `<linearGradient id="${id}" x1="0" y1="0" x2="1" y2="0">${stops.map(([o, c, op = 1]) => `<stop offset="${o}" stop-color="${c}" stop-opacity="${op}"/>`).join('')}</linearGradient>`);
    }

    /** A soft round light or shadow: `op` at its centre, nothing at its rim. */
    soft(c, op) {
        return this.memo(`s${c}${op}`, (id) => `<radialGradient id="${id}"><stop offset="0" stop-color="${c}" stop-opacity="${op}"/><stop offset=".55" stop-color="${c}" stop-opacity="${f(op * 0.55)}"/><stop offset="1" stop-color="${c}" stop-opacity="0"/></radialGradient>`);
    }
}

/** One standalone SVG document of `w × h`, its gradients first. */
function doc(pen, w, h, body) {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${f(w)} ${f(h)}" width="${f(w)}" height="${f(h)}"><defs>${pen.defs.join('')}</defs>${body}</svg>`;
}

// ── drawing primitives (the prototype's, under the paint rules) ─────────────────────────────

/** A rounded rect's path. */
const rr = (x, y, w, h, r) => {
    const q = Math.max(0, Math.min(r, w / 2, h / 2));

    return `M${f(x + q)} ${f(y)}H${f(x + w - q)}Q${f(x + w)} ${f(y)} ${f(x + w)} ${f(y + q)}V${f(y + h - q)}Q${f(x + w)} ${f(y + h)} ${f(x + w - q)} ${f(y + h)}H${f(x + q)}Q${f(x)} ${f(y + h)} ${f(x)} ${f(y + h - q)}V${f(y + q)}Q${f(x)} ${f(y)} ${f(x + q)} ${f(y)}Z`;
};

/** A leaf's path, its stem at the origin, pointing up. */
const leafPath = (len, wid) => `M0 0C${f(wid)} ${f(-len * 0.22)} ${f(wid * 0.85)} ${f(-len * 0.78)} 0 ${f(-len)}C${f(-wid * 0.85)} ${f(-len * 0.78)} ${f(-wid)} ${f(-len * 0.22)} 0 0Z`;

/** The faint cream rim a standing thing carries, as the creatures carry it: the markup again, under it, in cream and a little wider. */
function rimmed(markup, w = 1.3, op = 0.5) {
    const rim = markup
        .replace(/ (fill|stroke)="(?!none")[^"]*"/g, ` $1="${RULES.highlight}"`)
        .replace(/ stroke-width="([\d.]+)"/g, (m, x) => ` stroke-width="${f(+x + w)}"`)
        .replace(/ (?:opacity|fill-opacity|stroke-opacity)="[^"]*"/g, '');

    return `<g opacity="${op}">${rim}</g>${markup}`;
}

/** A soft plum contact shadow at a foot. */
const shadow = (pen, cx, cy, rx, ry, op = 0.28) => `<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(rx)}" ry="${f(ry)}" fill="${pen.soft(RULES.shade, op)}"/>`;

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
 * the leaves, and the call buttons beside it. The leaves are the painter's, in the building's door colours.
 */
export function elevatorSurround() {
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
    // the call buttons
    m.push(`<path d="${rr(fw + 4, 58, 11, 24, 5)}" fill="${pen.face(P.frame)}" stroke="${line(P.frame)}" stroke-width=".7"/>`);
    m.push(`<circle cx="${fw + 9.5}" cy="65" r="2.6" fill="${P.lamp}"/><circle cx="${fw + 9.5}" cy="75" r="2.6" fill="${tint(P.frame, 0.4)}"/>`);

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

    // the walls: a soft rounded top strip with its shadow, a round cap at each free end (a doorway's jamb)
    if (walls.length > 0) {
        const c = PALETTE['wall-run'].strip;
        const cells = new Set();
        const at = (x, y) => `${Math.round(x / cell.w)},${Math.round(y / cell.h)}`;

        for (const r of walls) {
            for (let y = r.y; y < r.y + r.h - 0.01; y += cell.h) {
                for (let x = r.x; x < r.x + r.w - 0.01; x += cell.w) {
                    cells.add(at(x, y));
                }
            }
        }

        const has = (cx, cy) => cells.has(`${cx},${cy}`);
        const caps = [];

        for (const k of cells) {
            const [cx, cy] = k.split(',').map(Number);
            const n = [[1, 0], [-1, 0], [0, 1], [0, -1]].filter(([dx, dy]) => has(cx + dx, cy + dy));

            if (n.length !== 1) {
                continue;
            }

            // a free end: away from its one neighbour, and not at the grid's edge, where the wall runs on
            const [dx, dy] = n[0];
            const x = (cx - dx * 0.5 + 0.5) * cell.w;
            const y = (cy - dy * 0.5 + 0.5) * cell.h;

            if (x <= 0 || y <= 0 || x >= w || y >= h) {
                continue;
            }

            caps.push(`<circle cx="${f(x)}" cy="${f(y)}" r="${f(Math.min(cell.w, cell.h) * 0.69)}" fill="${pen.paint(c)}" stroke="${line(c)}" stroke-width=".8"/>`);
        }

        // Each straight stretch of wall is ONE strip, whatever runs the region pass handed over: the cells'
        // maximal runs along a row and down a column (a cell on neither, a strip of its own), so a wall
        // shows no seam and no bead at every cell.
        const strips = [];
        const listed = [...cells].map((k) => k.split(',').map(Number));

        for (const [cx, cy] of listed) {
            if (!has(cx - 1, cy) && has(cx + 1, cy)) {
                let n = 1;

                while (has(cx + n, cy)) {
                    n++;
                }

                strips.push({ x: cx * cell.w, y: cy * cell.h, w: n * cell.w, h: cell.h });
            }

            if (!has(cx, cy - 1) && has(cx, cy + 1)) {
                let n = 1;

                while (has(cx, cy + n)) {
                    n++;
                }

                strips.push({ x: cx * cell.w, y: cy * cell.h, w: cell.w, h: n * cell.h });
            }

            if (!has(cx - 1, cy) && !has(cx + 1, cy) && !has(cx, cy - 1) && !has(cx, cy + 1)) {
                strips.push({ x: cx * cell.w, y: cy * cell.h, w: cell.w, h: cell.h });
            }
        }

        strips.sort((a, b) => a.y - b.y || a.x - b.x || a.w - b.w);
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
 * sized by the cell: `bookcase`, `plant`, `floor-lamp`, `armchair`, `cushion` or `book-pile`. The floor
 * lamp's light is § 6.3's decorative glow, the painter's; the theme draws no glow of its own.
 *
 * @param {{kind: string, w: number, h: number, seed: string, index: number}} input the kind, the cell's size,
 *        the grid's seed and the cell's index
 */
export function scenery({ kind, w, h, seed, index }) {
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
    }

    return doc(pen, w, h, s.join(''));
}

/** How far each floor plant's shape reaches at unit scale, its pot and leaves together — what a cell fits it by. */
const PLANT_REACH = Object.freeze({
    tall: Object.freeze({ w: 60, h: 76 }),
    round: Object.freeze({ w: 54, h: 42 }),
    monstera: Object.freeze({ w: 88, h: 64 }),
});

// ── a desk's furniture set ────────────────────────────────────────────────────────────────────

const deskKey = (installId, seatId) => `${installId}/${seatId}`;

/** The chair's rect (§ 10.6's table): 54 × 64. */
const CHAIR = Object.freeze({ w: 54, h: 64 });

/**
 * THE CHAIR's BACK, behind the creature, at every desk with art — its colour seeded from the desk's key. With
 * nobody in it it is § 7.1's empty chair; the drawing itself carries no fact.
 *
 * @param {{install_id: string, seat_id: string}} input the desk's key
 */
export function chair({ install_id: installId, seat_id: seatId }) {
    const pen = new Pen();
    const c = pick(deskKey(installId, seatId), 'theme:chair', PALETTE.chair.colours);
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
 * @param {{install_id: string, seat_id: string}} input the desk's key
 */
export function deskProps({ install_id: installId, seat_id: seatId }) {
    const pen = new Pen();
    const key = deskKey(installId, seatId);
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
 * The side table's rect (228 × 42, its foot line its bottom edge) and the seats' row: § 12's seats, each
 * 20 wide on a 24 px pitch from 8 px in — the interns' row, which `desk-layout.js` lays the same way, so an
 * intern stands in front of its seat.
 */
const TABLE = Object.freeze({ w: 228, h: 42, seat_x: 8, seat_pitch: 24, seat_w: 20 });

/**
 * THE SIDE TABLE, at every desk with art: a low tea table with a teapot at its far end and cushion seats in
 * front of it, as many as it is handed — so an empty table shows its seats — and a plant beside it where
 * the table leaves room.
 *
 * @param {{install_id: string, seat_id: string, seats: number}} input the desk's key and the seat count
 */
export function sideTable({ install_id: installId, seat_id: seatId, seats }) {
    const pen = new Pen();
    const key = deskKey(installId, seatId);
    const P = PALETTE['side-table'];
    const foot = TABLE.h;
    const top = foot - 24;
    const x = 0;
    const tw = Math.max(64, seats * TABLE.seat_pitch + 20);
    const s = [];
    const m = [];

    s.push(shadow(pen, tw / 2 + 4, foot - 1, tw * 0.6, 4, 0.26));

    for (const lx of [x + 6, x + tw - 10]) {
        m.push(`<path d="M${f(lx)} ${top + 8}h4l-.6 ${foot - top - 11}h-2.8Z" fill="${pen.face(shade(P.wood, 0.1))}" stroke="${line(P.wood)}" stroke-width=".6"/>`);
    }

    m.push(`<path d="${rr(x, top, tw, 7, 3)}" fill="${pen.face(tint(P.wood, 0.3), 0.3, 0.05)}" stroke="${line(P.wood)}" stroke-width=".8"/>`);
    m.push(`<path d="${rr(x + 2, top + 6, tw - 4, 6, 2)}" fill="${pen.face(P.wood, 0.05, 0.2)}" stroke="${line(P.wood)}" stroke-width=".7"/>`);
    s.push(rimmed(m.join('')));

    // the teapot at the table's far end
    const tx = x + tw - 16;

    s.push(rimmed(`<g transform="translate(${f(tx)} ${top + 1})"><path d="M-8 0Q-10 -11 0 -12Q10 -11 8 0Z" fill="${pen.paint(P.teapot)}" stroke="${line(P.teapot)}" stroke-width=".7"/><path d="M7.5 -6q6 -2 7 -6" stroke="${line(P.teapot)}" stroke-width="1.8" fill="none" stroke-linecap="round"/><path d="M-8 -7q-5 1 -4 5" stroke="${line(P.teapot)}" stroke-width="1.6" fill="none"/><ellipse cx="0" cy="-12.3" rx="2.6" ry="1.5" fill="${pen.paint(P.lid)}"/></g>`));

    // a plant beside the table, where it leaves room
    if (tw + 40 <= TABLE.w) {
        const shape = ['tall', 'round', 'monstera'][draw(key, 'theme:table-plant') % 3];

        s.push(pottedPlant(pen, x + tw + 18, foot - 2, pick(key, 'theme:table-pot', P.pots), pick(key, 'theme:table-leaf', P.leaves), shape === 'monstera' ? 0.55 : 0.85, shape, stream(key, 'theme:table-plant-shape')));
    }

    // the cushion seats along the foot line, one under each intern's place
    for (let i = 0; i < seats; i++) {
        const c = P.seats[i % P.seats.length];
        const sx = TABLE.seat_x + i * TABLE.seat_pitch + TABLE.seat_w / 2;

        s.push(rimmed(`<ellipse cx="${sx}" cy="${foot - 3.5}" rx="9" ry="3.4" fill="${pen.paint(c, 0.35)}" stroke="${line(c)}" stroke-width=".7"/><path d="M${sx - 4} ${foot - 5}q4 -1.6 8 0" stroke="${tint(c, 0.5)}" stroke-width="1" fill="none" opacity=".7"/>`));
    }

    return doc(pen, TABLE.w, TABLE.h, s.join(''));
}
