// THE THEMES' SHARED PAINT PRIMITIVES — `docs/design/FLOOR.md § 10.6` item 1 (card#12845): the colour arithmetic,
// the seeded draws, one document's gradients, the document wrapper and the drawing primitives every theme paints
// with. Hoisted out of the house theme at its second caller (card#12845's second theme) rather than copied: a
// second copy of the board arithmetic or the cream rim is a second place for the two themes to drift apart in
// what they MEAN by a shade, a seeded choice or a well-formed document, which is not what a theme is for.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// ⛔ IT IS NOT A THEME AND HOLDS NO COLOUR. Every colour arrives as an argument — a theme's `PALETTE` entry or its
// paint rules (`RULES`, handed to `brushes()`) — so a theme's drift leg still reads every colour that theme paints
// in its own module, and this file writes no `#rrggbb` of its own (AT-D3-25's theme half holds that). It is not in
// the registry's `THEMES`, and it is not a directory, so the registry-against-its-tree leg does not read it as one.
//
// ⛔ PURE, TOTAL, NO I/O, NO STATE (§ 10.6 item 1). Nothing here fetches, reads a clock or a random source, or is
// assigned after load; a `Pen` is made per document. Every seeded choice is drawn through the character tree's
// `seed.js` `draw(key, field)`, the one seeded draw (§ 10.4's appearance bullet), by a relative import that resolves
// the same on disk and under the asset route: both trees sit under `/art/`.

import { draw } from '../../characters/seed.js';

// ── colour arithmetic ─────────────────────────────────────────────────────────────────────────

function rgb(hex) {
    const h = hex.slice(1);

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

function hex(c) {
    return `#${c.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')}`;
}

/** `a` mixed toward `b` by `t` — over sRGB, as the painter composites. */
export function mix(a, b, t) {
    const A = rgb(a);
    const B = rgb(b);

    return hex(A.map((v, i) => v + (B[i] - v) * t));
}

/**
 * A size a theme draws over, REFUSED when it is not a finite number: no scene hands one (every size comes from a
 * validated, byte-capped map or the scene's own constants), and a loop over an infinite or `NaN` width would never
 * end or would write `NaN` into a document. The throw is the painter's unit failure (§ 9 F14).
 */
export function finite(...sizes) {
    if (!sizes.every((v) => Number.isFinite(v) && v >= 0)) {
        throw new RangeError(`a theme document asked for over a size that is not a finite number: ${sizes.join(', ')}`);
    }
}

/** Two decimals, the precision every coordinate is written at. */
export const f = (v) => Number((+v).toFixed(2));

// ── seeded draws ──────────────────────────────────────────────────────────────────────────────

export { draw };

/** A seeded unit value in [0, 1), one named field of one key — through the tree's `draw()`. */
export const unit = (key, field) => draw(key, field) / 4294967296;

/**
 * A run of seeded unit values for one field — the n-th value is its own named draw (`field:n`), so a run is as
 * stable as a single draw and adding a run disturbs no other field.
 */
export function stream(key, field) {
    let n = 0;

    return () => unit(key, `${field}:${n++}`);
}

/** One seeded member of `list`. */
export const pick = (key, field, list) => list[draw(key, field) % list.length];

// ── one standalone document ───────────────────────────────────────────────────────────────────

/** One standalone SVG document of `w × h`, its gradients first. */
export function doc(pen, w, h, body) {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${f(w)} ${f(h)}" width="${f(w)}" height="${f(h)}"><defs>${pen.defs.join('')}</defs>${body}</svg>`;
}

// ── drawing primitives ────────────────────────────────────────────────────────────────────────

/** A rounded rect's path. */
export const rr = (x, y, w, h, r) => {
    const q = Math.max(0, Math.min(r, w / 2, h / 2));

    return `M${f(x + q)} ${f(y)}H${f(x + w - q)}Q${f(x + w)} ${f(y)} ${f(x + w)} ${f(y + q)}V${f(y + h - q)}Q${f(x + w)} ${f(y + h)} ${f(x + w - q)} ${f(y + h)}H${f(x + q)}Q${f(x)} ${f(y + h)} ${f(x)} ${f(y + h - q)}V${f(y + q)}Q${f(x)} ${f(y)} ${f(x + q)} ${f(y)}Z`;
};

/** A leaf's path, its stem at the origin, pointing up. */
export const leafPath = (len, wid) => `M0 0C${f(wid)} ${f(-len * 0.22)} ${f(wid * 0.85)} ${f(-len * 0.78)} 0 ${f(-len)}C${f(-wid * 0.85)} ${f(-len * 0.78)} ${f(-wid)} ${f(-len * 0.22)} 0 0Z`;

/**
 * A GRID's WALL RUNS AS STRAIGHT STRETCHES (§ 10.6 item 6's `wall` kind): the cells the region pass's runs cover,
 * re-cut into each straight stretch's ONE strip — the cells' maximal runs along a row and down a column, a cell on
 * neither a strip of its own — so a wall shows no seam and no bead at every cell whatever runs the pass handed
 * over; and each FREE END, a cell with exactly one neighbour, at the point half a cell beyond it away from that
 * neighbour — a doorway's jamb — and none at the grid's edge, where the wall runs on. What a theme draws on a strip
 * and at an end is its own; WHERE they are is this, one answer for every theme.
 *
 * @returns {{strips: list<{x: number, y: number, w: number, h: number}>, ends: list<{x: number, y: number}>}}
 */
export function wallRuns(walls, cell, w, h) {
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
    const ends = [];

    for (const k of cells) {
        const [cx, cy] = k.split(',').map(Number);
        const n = [[1, 0], [-1, 0], [0, 1], [0, -1]].filter(([dx, dy]) => has(cx + dx, cy + dy));

        if (n.length !== 1) {
            continue;
        }

        const [dx, dy] = n[0];
        const x = (cx - dx * 0.5 + 0.5) * cell.w;
        const y = (cy - dy * 0.5 + 0.5) * cell.h;

        if (x <= 0 || y <= 0 || x >= w || y >= h) {
            continue;
        }

        ends.push({ x, y });
    }

    const strips = [];

    for (const [cx, cy] of [...cells].map((k) => k.split(',').map(Number))) {
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

    return { strips, ends };
}

/**
 * A THEME's BRUSHES, under its paint rules `{shade, highlight, line}` (§ 10.6's paint rules, the creatures' own): a
 * shade leans toward `shade`, a highlight toward `highlight`, and a line is its fill mixed halfway to `line`; the
 * `Pen` that holds one document's gradients, lit from the top-left; the faint rim a standing thing carries, in the
 * highlight; and the soft contact shadow at a foot, in the shade.
 */
export function brushes(rules) {
    const shade = (c, t) => mix(c, rules.shade, t);
    const tint = (c, t) => mix(c, rules.highlight, t);
    const line = (c) => mix(c, rules.line, 0.5);

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

    /** The faint rim a standing thing carries, as the creatures carry it: the markup again, under it, in the highlight and a little wider. */
    function rimmed(markup, w = 1.3, op = 0.5) {
        const rim = markup
            .replace(/ (fill|stroke)="(?!none")[^"]*"/g, ` $1="${rules.highlight}"`)
            .replace(/ stroke-width="([\d.]+)"/g, (m, x) => ` stroke-width="${f(+x + w)}"`)
            .replace(/ (?:opacity|fill-opacity|stroke-opacity)="[^"]*"/g, '');

        return `<g opacity="${op}">${rim}</g>${markup}`;
    }

    /** A soft contact shadow at a foot, in the shade. */
    const shadow = (pen, cx, cy, rx, ry, op = 0.28) => `<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(rx)}" ry="${f(ry)}" fill="${pen.soft(rules.shade, op)}"/>`;

    return Object.freeze({ shade, tint, line, Pen, rimmed, shadow });
}
