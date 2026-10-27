// The creature generator — first-party, card#11046 (docs/design/FLOOR.md § 10.2, § 10.4).
//
// Every character is an original animal or vegetable creature, drawn as a STANDALONE SVG DOCUMENT per
// frame and selected from its key through seed.js's draw — the same key, the same document, on every
// engine and every reload. The one exception is the BODY of a seat on a floor: the species the server
// allocated it and delivers (`character.species`, card#12443), which the painter hands in as `force` /
// `species`; everything else is still the key's draw. The species of the operator-approved design sheet
// (2026-10-05) are ported and not redesigned; the seat's two additions to them are the faint light rim (`RIM`)
// and the hedgehog's softer, curved spines. The species marked `added` (card#12212) are the seat's own drawing to
// the same look, appended to the one list every key draws its species from (FLOOR.md § 10.4's growth bullet).
//
// ⛔ This module builds DOCUMENTS, never URIs: the painter makes the `data:` URI, so no text in the asset
// tree carries one (FLOOR.md § 10.1 clause 2). It makes no request of any kind, holds no cache and
// reads no clock or random source.
//
// Drawing space: SEAT_RECT (0 0 108 192, 2 units per floor pixel in the 54 x 96 character rect), drawn in SEAT_VIEWBOX;
// the foot line is y = 188, the centre line x = 54.

import { draw, pick, chance } from './seed.js';

// ---- colour ----
function hex2rgb(h) { h = h.replace('#', ''); return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); }
function rgb2hex(r) { return '#' + r.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join(''); }
function mix(a, b, t) { const A = hex2rgb(a), B = hex2rgb(b); return rgb2hex(A.map((v, i) => v + (B[i] - v) * t)); }
const shade = (c, t) => mix(c, '#3d2b3c', t);   // shadows lean cool plum, painterly
const tint = (c, t) => mix(c, '#fff6e6', t);    // highlights lean warm cream
const line = (c) => mix(c, '#3a2420', 0.58);    // a coloured line, never black
const EYE = '#2b1f24', MOUTH = '#4e2a2a', GLINT = '#fffaf0';
const f = (v) => +v.toFixed(2);

// ---- the pen: per-SVG defs with unique ids ----
class Pen {
  constructor(id) { this.id = id; this.n = 0; this.defs = []; this.cache = new Map(); }
  uid() { return `${this.id}_${this.n++}`; }
  paint(c) {            // body fill: light from the top-left, soft falloff to the bottom-right
    const k = 'p' + c; if (this.cache.has(k)) return this.cache.get(k);
    const id = this.uid();
    this.defs.push(`<radialGradient id="${id}" cx=".38" cy=".3" r=".82" fx=".3" fy=".2"><stop offset="0" stop-color="${tint(c, 0.42)}"/><stop offset=".45" stop-color="${c}"/><stop offset="1" stop-color="${shade(c, 0.3)}"/></radialGradient>`);
    const u = `url(#${id})`; this.cache.set(k, u); return u;
  }
  soft(c, op) {          // a soft round wash, opaque centre to clear edge (blush, shadow, fades)
    const k = 's' + c + op; if (this.cache.has(k)) return this.cache.get(k);
    const id = this.uid();
    this.defs.push(`<radialGradient id="${id}"><stop offset="0" stop-color="${c}" stop-opacity="${op}"/><stop offset=".6" stop-color="${c}" stop-opacity="${op * 0.55}"/><stop offset="1" stop-color="${c}" stop-opacity="0"/></radialGradient>`);
    const u = `url(#${id})`; this.cache.set(k, u); return u;
  }
  vfade(c, a, b) {       // vertical fade: c opaque at offset a, gone by offset b (bbox units)
    const id = this.uid();
    this.defs.push(`<linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="${a}" stop-color="${c}" stop-opacity=".97"/><stop offset="${b}" stop-color="${c}" stop-opacity="0"/></linearGradient>`);
    return `url(#${id})`;
  }
  clip(d) { const id = this.uid(); this.defs.push(`<clipPath id="${id}"><path d="${d}"/></clipPath>`); return `url(#${id})`; }
}

// closed Catmull-Rom through points -> smooth cubic path
function smooth(pts) {
  const n = pts.length; let d = `M${f(pts[0][0])} ${f(pts[0][1])}`;
  for (let i = 0; i < n; i++) {
    const p0 = pts[(i - 1 + n) % n], p1 = pts[i], p2 = pts[(i + 1) % n], p3 = pts[(i + 2) % n];
    d += `C${f(p1[0] + (p2[0] - p0[0]) / 6)} ${f(p1[1] + (p2[1] - p0[1]) / 6)} ${f(p2[0] - (p3[0] - p1[0]) / 6)} ${f(p2[1] - (p3[1] - p1[1]) / 6)} ${f(p2[0])} ${f(p2[1])}`;
  }
  return d + 'Z';
}

// a leaf pointing "up" (angle 0) from its base (x, y)
function leafD(len, wid) {
  return `M0 0C${f(wid)} ${f(-len * 0.22)} ${f(wid * 0.85)} ${f(-len * 0.78)} 0 ${f(-len)}C${f(-wid * 0.85)} ${f(-len * 0.78)} ${f(-wid)} ${f(-len * 0.22)} 0 0Z`;
}

// a scalloped closed outline: n soft bumps, each `bulge` deep, around the ellipse (cx, cy, rx, ry) — a fleece, a
// broccoli crown, a cabbage leaf (card#12212); one path, so one coloured line and one rim
function scallop(cx, cy, rx, ry, n, bulge, a0 = -Math.PI / 2) {
  const P = (i) => { const a = a0 + i * 2 * Math.PI / n; return [cx + Math.cos(a) * rx, cy + Math.sin(a) * ry]; };
  let d = `M${f(P(0)[0])} ${f(P(0)[1])}`;
  for (let i = 0; i < n; i++) {
    const a = a0 + (i + 0.5) * 2 * Math.PI / n, [x1, y1] = P(i + 1);
    d += `Q${f(cx + Math.cos(a) * (rx + bulge * 2))} ${f(cy + Math.sin(a) * (ry + bulge * 2))} ${f(x1)} ${f(y1)}`;
  }
  return d + 'Z';
}

// an open Catmull-Rom curve through points (a snail's spiral)
function curve(pts) {
  const n = pts.length; let d = `M${f(pts[0][0])} ${f(pts[0][1])}`;
  for (let i = 0; i < n - 1; i++) {
    const p0 = pts[Math.max(0, i - 1)], p1 = pts[i], p2 = pts[i + 1], p3 = pts[Math.min(n - 1, i + 2)];
    d += `C${f(p1[0] + (p2[0] - p0[0]) / 6)} ${f(p1[1] + (p2[1] - p0[1]) / 6)} ${f(p2[0] - (p3[0] - p1[0]) / 6)} ${f(p2[1] - (p3[1] - p1[1]) / 6)} ${f(p2[0])} ${f(p2[1])}`;
  }
  return d;
}

// ---- the drawing context handed to a species ----
function makeCtx(pen, key, P, st) {
  const c = {
    pen, key, P, st,
    side: chance(key, 'side', 1, 2) ? 1 : -1,
    layers: { back: [], body: [], front: [] },
    X: (dx) => f(54 + dx * st),
    back(s) { c.layers.back.push(s); }, body(s) { c.layers.body.push(s); }, front(s) { c.layers.front.push(s); },
    sym(profile) {
      const R = profile.map(([dx, y]) => [54 + dx * st, y]);
      const L = profile.slice(1, -1).reverse().map(([dx, y]) => [54 - dx * st, y]);
      return smooth([...R, ...L]);
    },
    shape(d, col, extra = '') { return `<path d="${d}" fill="${pen.paint(col)}" stroke="${line(col)}" stroke-width="1.6" stroke-linejoin="round" ${extra}/>`; },
    flat(d, col, extra = '') { return `<path d="${d}" fill="${col}" ${extra}/>`; },
    ell(x, y, rx, ry, col, extra = '') { return `<ellipse cx="${f(x)}" cy="${f(y)}" rx="${f(rx)}" ry="${f(ry)}" fill="${pen.paint(col)}" stroke="${line(col)}" stroke-width="1.5" ${extra}/>`; },
    leaf(x, y, ang, len, wid, col) {
      return `<g transform="translate(${f(x)} ${f(y)}) rotate(${f(ang)})"><path d="${leafD(len, wid)}" fill="${pen.paint(col)}" stroke="${line(col)}" stroke-width="1.4" stroke-linejoin="round"/><path d="M0 -2L0 ${f(-len * 0.8)}" stroke="${tint(col, 0.45)}" stroke-width="1.1" stroke-linecap="round" opacity=".7"/></g>`;
    },
  };
  return c;
}

export const GREENS = ['#79a85a', '#8db55e', '#6b9a52', '#9ab062'];

// ---- the species: each draws its BODY and returns where the shared features go ----
// face {y, es (eye half-spacing), s (eye scale), my (mouth y), ms (mouth scale), beak}
// hat {x, y (top of head), w (half-width at the hat band)}  neck {y, w}  arms {y, dx}
// feet {dx, col}  shadow (half-width)
const APPROVED = {
  fox: {
    kind: 'animal', label: 'fox-ish',
    pals: [['#d9733a', '#fbf1e2'], ['#e3a85c', '#fcf4e4'], ['#a5704f', '#f3e6d2'], ['#c4beb6', '#fdfaf4'], ['#b9563b', '#f7e8d7']],
    hats: ['beret', 'flower', 'sprig', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X, t = c.side;
      const head = c.sym([[0, 62], [20, 64], [33, 75], [39, 94], [36, 108], [26, 121], [0, 127]]);
      const body = c.sym([[0, 112], [21, 117], [29, 138], [32, 162], [27, 182], [0, 185]]);
      const tail = `M${X(t * 18)} 178C${X(t * 46)} 184 ${X(t * 52)} 146 ${X(t * 45)} 116C${X(t * 38)} 136 ${X(t * 33)} 152 ${X(t * 20)} 154Z`;
      c.back(c.shape(tail, b));
      c.back(`<ellipse cx="${X(t * 45)}" cy="120" rx="9" ry="11" fill="${c.pen.paint(m)}" clip-path="${c.pen.clip(tail)}"/>`);
      for (const s of [-1, 1]) {
        c.back(c.shape(`M${X(s * 11)} 74Q${X(s * 15)} 36 ${X(s * 30)} 26Q${X(s * 42)} 48 ${X(s * 36)} 86Z`, b));
        c.back(c.flat(`M${X(s * 17)} 72Q${X(s * 20)} 46 ${X(s * 29.5)} 37Q${X(s * 36)} 54 ${X(s * 32)} 78Z`, tint(m, 0.1), 'opacity=".85"'));
        c.back(c.flat(`M${X(s * 26)} 31Q${X(s * 30)} 26 ${X(s * 30)} 26Q${X(s * 35)} 33 ${X(s * 36.5)} 40Q${X(s * 31)} 38 ${X(s * 26)} 31Z`, shade(b, 0.5)));
      }
      c.body(c.shape(body, b));
      c.body(`<ellipse cx="54" cy="148" rx="${f(15 * c.st)}" ry="21" fill="${m}" clip-path="${c.pen.clip(body)}"/>`);
      c.body(c.shape(head, b));
      c.body(`<path d="M${X(-41)} 97C${X(-34)} 122 ${X(-14)} 131 54 131C${X(14)} 131 ${X(34)} 122 ${X(41)} 97C${X(28)} 104 ${X(13)} 101 54 110C${X(-13)} 101 ${X(-28)} 104 ${X(-41)} 97Z" fill="${m}" clip-path="${c.pen.clip(head)}"/>`);
      c.body(`<ellipse cx="54" cy="111" rx="3.6" ry="2.7" fill="${EYE}"/><ellipse cx="53" cy="110.2" rx="1.2" ry=".7" fill="${GLINT}" opacity=".8"/>`);
      return { face: { y: 98, es: 14, s: 1, my: 116.5, ms: 0.9 }, hat: { x: 54, y: 64, w: 18 }, neck: { y: 121, w: 27 * c.st },
        arms: { y: 150, dx: 29 * c.st, col: b }, feet: { dx: 14 * c.st, col: shade(b, 0.55) }, shadow: 34 * c.st };
    },
  },
  bear: {
    kind: 'animal', label: 'bear-ish',
    pals: [['#c98d4e', '#f1dcb8'], ['#7d5539', '#dcc09b'], ['#e6cfaa', '#fbf2e2'], ['#ad683b', '#efd6b4'], ['#5d4b43', '#e2cfb4']],
    hats: ['beret', 'beanie', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      for (const s of [-1, 1]) {
        c.back(c.ell(X(s * 29), 66, 12.5, 12, b));
        c.back(`<ellipse cx="${X(s * 29)}" cy="67" rx="6.5" ry="6" fill="${c.pen.paint(m)}"/>`);
      }
      const body = c.sym([[0, 116], [26, 122], [36, 146], [37, 168], [29, 183], [0, 186]]);
      c.body(c.shape(body, b));
      if (chance(c.key, 'mark', 1, 3)) c.body(c.flat(`M${X(-16)} 134Q54 152 ${X(16)} 134Q54 145 ${X(-16)} 134Z`, m));
      c.body(c.shape(c.sym([[0, 58], [24, 60], [37, 74], [41, 95], [36, 115], [22, 128], [0, 131]]), b));
      c.body(`<ellipse cx="54" cy="113" rx="15" ry="11" fill="${c.pen.paint(m)}" stroke="${line(m)}" stroke-width=".9" opacity=".97"/>`);
      c.body(c.flat(`M48.5 105.5Q54 102.5 59.5 105.5Q57.5 111 54 111.5Q50.5 111 48.5 105.5Z`, EYE));
      c.body(`<ellipse cx="52.5" cy="105" rx="1.6" ry=".8" fill="${GLINT}" opacity=".7"/>`);
      return { face: { y: 97, es: 15, s: 1, my: 116.5, ms: 0.9 }, hat: { x: 54, y: 58, w: 31 }, neck: { y: 126, w: 31 * c.st },
        arms: { y: 150, dx: 34 * c.st, col: b }, feet: { dx: 16 * c.st, col: shade(b, 0.15) }, shadow: 38 * c.st };
    },
  },
  rabbit: {
    kind: 'animal', label: 'rabbit-ish',
    pals: [['#d8b48a', '#fbf3e6'], ['#8f6a52', '#efe1cf'], ['#efe2cb', '#fffaf1'], ['#c7a79c', '#f8ece6'], ['#c98f62', '#fbf0e0']],
    hats: ['flower', 'sprig', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    variant(key) { return chance(key, 'lop', 1, 3) ? 'lop' : 'upright'; },
    build(c) {
      const [b, m] = c.P, X = c.X, lop = c.variant === 'lop';
      const inner = '#efbcb6';
      if (!lop) {
        const flop = chance(c.key, 'flop', 1, 3) ? c.side : 0;
        for (const s of [-1, 1]) {
          const a = s === flop ? s * 34 : s * 9;
          c.back(`<g transform="rotate(${a} ${X(s * 13)} 70)">${c.ell(X(s * 13), s === flop ? 46 : 40, 9.5, s === flop ? 22 : 28, b)}<ellipse cx="${X(s * 13)}" cy="${s === flop ? 48 : 42}" rx="4.6" ry="${s === flop ? 15 : 20}" fill="${inner}" opacity=".9"/></g>`);
        }
      }
      c.body(c.shape(c.sym([[0, 118], [22, 123], [30, 146], [32, 168], [26, 183], [0, 186]]), b));
            c.body(c.shape(c.sym([[0, 64], [22, 66], [34, 80], [37, 98], [33, 115], [20, 126], [0, 129]]), b));
      if (lop) for (const s of [-1, 1]) c.body(`<g transform="rotate(${-s * 16} ${X(s * 30)} 74)">${c.ell(X(s * 34), 98, 10, 26, b)}<ellipse cx="${X(s * 34)}" cy="100" rx="4.6" ry="18" fill="${inner}" opacity=".75"/></g>`);
      for (const s of [-1, 1]) c.body(`<ellipse cx="${X(s * 5.5)}" cy="112.5" rx="6.6" ry="5.4" fill="${c.pen.paint(m)}"/>`);
      c.body(c.flat(`M50.8 106.6Q54 105.2 57.2 106.6Q55.6 110 54 110.4Q52.4 110 50.8 106.6Z`, '#e08c88'));
      return { face: { y: 97, es: 13, s: 1, my: 118, ms: 0.8 }, hat: { x: 54, y: 65, w: 29 }, neck: { y: 124, w: 27 * c.st },
        arms: { y: 150, dx: 28 * c.st, col: b }, feet: { dx: 13 * c.st, col: shade(b, 0.1), rx: 10 }, shadow: 32 * c.st,
        hats: lop ? ['beret', 'beanie', 'straw', 'flower', 'ladybug'].filter(keepHat) : null };
    },
  },
  owl: {
    kind: 'animal', label: 'owl-ish',
    pals: [['#b07a48', '#f0dfc4'], ['#d6a35a', '#fcf4e6'], ['#a65b3c', '#f0d8bf'], ['#8a8450', '#ece3c8'], ['#e9e2d4', '#fffaf2']],
    hats: ['beret', 'beanie', 'flower', 'ladybug', 'straw'], necks: ['scarf', 'neckerchief'], extras: ['glasses', 'satchel'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      for (const s of [-1, 1]) c.back(c.shape(`M${X(s * 18)} 60Q${X(s * 28)} 50 ${X(s * 31)} 42Q${X(s * 34)} 48 ${X(s * 33)} 52Q${X(s * 38)} 52 ${X(s * 39)} 70Z`, b));
      const body = c.sym([[0, 52], [26, 55], [40, 72], [44, 102], [44, 140], [38, 168], [24, 183], [0, 186]]);
      c.body(c.shape(body, b));
      const dots = [[-14, 134, 1.6], [9, 140, 1.3], [-4, 152, 1.8], [16, 158, 1.4], [-17, 164, 1.3], [4, 170, 1.5]];
      for (const [dx, y, r] of dots) c.body(`<circle cx="${X(dx)}" cy="${y}" r="${r}" fill="${shade(b, 0.3)}" opacity=".45"/>`);
      c.body(`<path d="${c.sym([[0, 79], [13, 73], [28, 77], [33, 95], [27, 111], [13, 118], [0, 115]])}" fill="${c.pen.paint(m)}" stroke="${line(m)}" stroke-width=".9" opacity=".97"/>`);
      for (const s of [-1, 1]) {
        c.front(c.shape(`M${X(s * 36)} 110C${X(s * 50)} 126 ${X(s * 49)} 162 ${X(s * 35)} 174C${X(s * 33)} 150 ${X(s * 30)} 128 ${X(s * 36)} 110Z`, shade(b, 0.12)));
        c.front(`<path d="M${X(s * 40)} 132Q${X(s * 43)} 146 ${X(s * 39)} 160M${X(s * 36)} 136Q${X(s * 38)} 150 ${X(s * 36)} 164" stroke="${shade(b, 0.4)}" stroke-width="1" fill="none" opacity=".5" stroke-linecap="round"/>`);
      }
      c.front(`<path d="M49.4 103Q54 100.4 58.6 103Q56 111 54 113Q52 111 49.4 103Z" fill="${c.pen.paint('#e3a447')}" stroke="#8a5a2a" stroke-width="1" stroke-linejoin="round"/>`);
      return { face: { y: 94, es: 13.5, s: 1.22, my: null, ms: 1, beak: true }, hat: { x: 54, y: 54, w: 28 }, neck: { y: 121, w: 40 * c.st },
        arms: null, feet: { dx: 13 * c.st, col: '#d99a45', talon: true }, shadow: 40 * c.st };
    },
  },
  frog: {
    kind: 'animal', label: 'frog-ish',
    pals: [['#83b46a', '#eef0c8'], ['#9aa35c', '#f1edc6'], ['#d46c48', '#f8e0c4'], ['#62a79c', '#e6f1dc'], ['#d6b24c', '#f8efcc']],
    hats: ['ladybug', 'flower', 'sprig'], necks: ['bow', 'neckerchief', 'scarf'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      const body = c.sym([[0, 80], [11, 77], [16, 66], [24, 62], [33, 66], [39, 80], [46, 102], [47, 130], [45, 156], [38, 176], [24, 185], [0, 186]]);
      c.body(c.shape(body, b));
      c.body(`<ellipse cx="54" cy="158" rx="${f(31 * c.st)}" ry="26" fill="${c.pen.paint(m)}" clip-path="${c.pen.clip(body)}" opacity=".95"/>`);
      if (chance(c.key, 'spots', 1, 2)) for (const [dx, y, r] of [[-37, 116, 4], [38, 122, 3.2], [-30, 136, 3], [33, 140, 3.6]]) c.body(`<circle cx="${X(dx)}" cy="${y}" r="${r}" fill="${shade(b, 0.18)}" opacity=".5"/>`);
      return { face: { y: 79, es: 24 * c.st, s: 1.05, my: 101, ms: 1.6 }, hat: { x: 54, y: 78, w: 10 }, neck: { y: 118, w: 44 * c.st },
        arms: { y: 157, dx: 40 * c.st, col: b }, feet: { dx: 24 * c.st, col: shade(b, 0.12), rx: 13 }, shadow: 44 * c.st, blushDx: 8 };
    },
  },
  mole: {
    kind: 'animal', label: 'mole-ish',
    pals: [['#5f5452', '#8d807c'], ['#715646', '#a08672'], ['#8f7d6c', '#b8a896'], ['#b8976b', '#d8c19c'], ['#5f6272', '#8f92a0']],
    hats: ['beanie', 'beret', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel'],
    build(c) {
      const [b, m] = c.P, X = c.X, pink = '#f0a69e';
      c.body(c.shape(c.sym([[0, 62], [20, 64], [32, 80], [38, 106], [42, 138], [40, 166], [28, 183], [0, 186]]), b));
      c.body(`<ellipse cx="54" cy="111" rx="16" ry="10.5" fill="${c.pen.paint(m)}" opacity=".95"/>`);
      for (const s of [-1, 1]) c.body(`<path d="M${X(s * 11)} 110L${X(s * 25)} 106M${X(s * 11)} 113L${X(s * 25)} 115" stroke="${tint(b, 0.55)}" stroke-width=".9" stroke-linecap="round"/>`);
      c.body(`<path d="M47.6 106.4Q54 103 60.4 106.4Q59 112.6 54 114.4Q49 112.6 47.6 106.4Z" fill="${c.pen.paint(pink)}" stroke="${line(pink)}" stroke-width="1" stroke-linejoin="round"/><ellipse cx="52" cy="106.6" rx="2" ry="1" fill="#fff" opacity=".6"/>`);
      for (const s of [-1, 1]) {
        c.front(`<g transform="rotate(${-s * 18} ${X(s * 37)} 138)">${c.ell(X(s * 37), 138, 10, 12, pink)}<path d="M${X(s * 37) - 5} 146L${X(s * 37) - 5.5} 152M${X(s * 37)} 148L${X(s * 37)} 154.5M${X(s * 37) + 5} 146L${X(s * 37) + 5.5} 152" stroke="#f6ece2" stroke-width="2" stroke-linecap="round"/></g>`);
      }
      return { face: { y: 98, es: 13, s: 0.82, my: 119.5, ms: 0.75 }, hat: { x: 54, y: 63, w: 26 }, neck: { y: 125, w: 37 * c.st },
        arms: null, feet: { dx: 16 * c.st, col: pink }, shadow: 40 * c.st };
    },
  },
  hedgehog: {
    kind: 'animal', label: 'hedgehog-ish',
    pals: [['#7c5a40', '#f1dcc0'], ['#b39572', '#f6e6cf'], ['#5b4636', '#eed8bb'], ['#cdb48c', '#fbf0de'], ['#6f6a66', '#efe2d0']],
    hats: ['flower', 'ladybug', 'beret', 'sprig'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X, cy = 112;
      // Softer, curved spines (card#11046): each spine a rounded tip swept a little toward the back,
      // valleys and tips joined by curves — never the straight saw-tooth a zigzag polyline reads as at 4x.
      const N = 15, a0 = -205, a1 = 25, at = (i) => (a0 + (a1 - a0) * i / (N * 2)) * Math.PI / 180;
      const pt = (a, r) => [54 + Math.cos(a) * r * c.st, cy + Math.sin(a) * r * 1.02];
      let spikes = `M${X(-30)} 168L${pt(at(0), 41).map(f).join(' ')}`;
      for (let i = 1; i < N * 2; i += 2) {
        const tip = pt(at(i) + 0.05, 50), v = pt(at(i + 1), 41.5);
        const c1 = pt(at(i - 0.55), 47.5), c2 = pt(at(i + 0.45), 47.5);
        spikes += `Q${c1.map(f).join(' ')} ${tip.map(f).join(' ')}Q${c2.map(f).join(' ')} ${v.map(f).join(' ')}`;
      }
      spikes += `L${X(30)} 168Z`;
      c.back(`<path d="${spikes}" fill="${c.pen.paint(b)}" stroke="${line(b)}" stroke-width="1.5" stroke-linejoin="round"/>`);
      for (let i = 1; i < N * 2; i += 2) {
        const p0 = pt(at(i), 36), p1 = pt(at(i) + 0.03, 41), p2 = pt(at(i) + 0.04, 45.5);
        c.back(`<path d="M${p0.map(f).join(' ')}Q${p1.map(f).join(' ')} ${p2.map(f).join(' ')}" stroke="${tint(b, 0.35)}" stroke-width="1.2" fill="none" stroke-linecap="round" opacity=".55"/>`);
      }
      for (const s of [-1, 1]) c.back(c.ell(X(s * 23), 73, 6.5, 6.5, m));
      c.body(c.shape(c.sym([[0, 68], [24, 71], [34, 86], [36, 110], [33, 140], [31, 166], [24, 183], [0, 186]]), m));
      c.body(`<ellipse cx="54" cy="111" rx="10" ry="7.5" fill="${c.pen.paint(tint(m, 0.4))}"/>`);
      c.body(`<circle cx="54" cy="108.5" r="3.7" fill="${EYE}"/><circle cx="52.8" cy="107.4" r="1.1" fill="${GLINT}" opacity=".8"/>`);
      return { face: { y: 98, es: 13, s: 1, my: 118.5, ms: 0.85 }, hat: { x: 54, y: 69, w: 22 }, neck: { y: 126, w: 31 * c.st },
        arms: { y: 150, dx: 31 * c.st, col: shade(m, 0.08) }, feet: { dx: 14 * c.st, col: b }, shadow: 36 * c.st };
    },
  },
  otter: {
    kind: 'animal', label: 'otter-ish',
    pals: [['#8a6448', '#ecd9bf'], ['#b08e6a', '#f4e6d2'], ['#5e4a3e', '#d9c4a6'], ['#7e7468', '#e6ddcf'], ['#9b5e3c', '#f0dcc2']],
    hats: ['beanie', 'beret', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      for (const s of [-1, 1]) { c.back(c.ell(X(s * 26), 63, 6.5, 6.5, b)); c.back(`<circle cx="${X(s * 26)}" cy="64" r="3" fill="${shade(b, 0.3)}"/>`); }
      const body = c.sym([[0, 52], [20, 54], [30, 66], [33, 88], [30, 112], [30, 140], [33, 164], [27, 183], [0, 186]]);
      c.body(c.shape(body, b));
      const clip = c.pen.clip(body);
      c.body(`<ellipse cx="54" cy="101" rx="${f(26 * c.st)}" ry="15.5" fill="${c.pen.paint(m)}" clip-path="${clip}"/>`);
      c.body(`<ellipse cx="54" cy="152" rx="${f(19 * c.st)}" ry="30" fill="${c.pen.paint(m)}" clip-path="${clip}" opacity=".92"/>`);
      for (const s of [-1, 1]) c.body(`<path d="M${X(s * 10)} 98L${X(s * 24)} 95M${X(s * 10)} 101L${X(s * 24)} 102" stroke="${shade(m, 0.35)}" stroke-width=".85" stroke-linecap="round" opacity=".7"/>`);
      c.body(c.flat(`M48.6 93.5Q54 91 59.4 93.5Q57.6 98.6 54 99Q50.4 98.6 48.6 93.5Z`, EYE));
      c.body(`<ellipse cx="52.4" cy="93.2" rx="1.6" ry=".7" fill="${GLINT}" opacity=".7"/>`);
      return { face: { y: 84, es: 13, s: 1, my: 103, ms: 0.85 }, hat: { x: 54, y: 53, w: 24 }, neck: { y: 113, w: 30 * c.st },
        arms: { y: 126, dx: 7, col: shade(b, 0.05), clasp: true }, feet: { dx: 14 * c.st, col: shade(b, 0.25) }, shadow: 34 * c.st };
    },
  },
  radish: {
    kind: 'vegetable', label: 'radish',
    pals: [['#d4485a'], ['#e57b97'], ['#8f4c8c'], ['#c8443c'], ['#e9d6d2']],
    hats: ['ladybug', 'flower'], necks: ['bow'], extras: ['glasses'],
    build(c) {
      const [b] = c.P, X = c.X, g = pick(c.key, 'green', GREENS), root = '#f4e8e2';
      const lf = [[-32, 46, 13], [3, 56, 14], [34, 46, 12]];
      if (chance(c.key, 'leaf4', 1, 2)) lf.push([-12, 40, 10]);
      for (const [a, l, w] of lf) c.back(c.leaf(54, 76, a, l, w, g));
      c.body(`<path d="M54 174Q55 186 63 187.5Q67 188 69 185" stroke="${root}" stroke-width="2.2" fill="none" stroke-linecap="round"/>`);
      const bulb = c.sym([[0, 72], [24, 74], [40, 88], [44, 108], [40, 132], [28, 154], [12, 170], [0, 176]]);
      c.body(c.shape(bulb, b));
      c.body(`<ellipse cx="54" cy="176" rx="34" ry="36" fill="${c.pen.soft(root, 0.95)}" clip-path="${c.pen.clip(bulb)}"/>`);
      return { face: { y: 104, es: 15, s: 1.05, my: 119.5, ms: 0.95 }, hat: { x: 54, y: 74, w: 22 }, neck: { y: 76, w: 9 },
        arms: { y: 128, dx: 42 * c.st, col: b, small: true }, feet: { dx: 9 * c.st, col: root, rx: 5 }, shadow: 30 * c.st };
    },
  },
  turnip: {
    kind: 'vegetable', label: 'turnip',
    pals: [['#9a5c9e', '#f4ede4'], ['#d7849a', '#f6efe6'], ['#d9b96a', '#f6efdd'], ['#c45a5a', '#f6ede6'], ['#7d6aa8', '#f3eef0']],
    hats: ['ladybug', 'flower'], necks: ['bow'], extras: ['glasses'],
    build(c) {
      const [b, m] = c.P, X = c.X, g = pick(c.key, 'green', GREENS);
      for (const [a, l, w] of [[-44, 42, 15], [-14, 54, 16], [14, 52, 16], [44, 42, 14]]) c.back(c.leaf(54, 80, a, l, w, shade(g, 0.08)));
      c.body(`<path d="M54 174Q53 185 45 187.5Q41 188 39 185" stroke="${m}" stroke-width="2.2" fill="none" stroke-linecap="round"/>`);
      const bulb = c.sym([[0, 76], [30, 78], [46, 94], [48, 116], [42, 140], [28, 160], [12, 172], [0, 176]]);
      c.body(c.shape(bulb, m));
      c.body(`<rect x="0" y="70" width="108" height="56" fill="${c.pen.vfade(b, 0.35, 1)}" clip-path="${c.pen.clip(bulb)}"/>`);
      c.body(`<path d="${bulb}" fill="none" stroke="${line(b)}" stroke-width="1.6" opacity=".35"/>`);
      return { face: { y: 108, es: 16, s: 1.05, my: 123, ms: 1 }, hat: { x: 54, y: 78, w: 22 }, neck: { y: 80, w: 9 },
        arms: { y: 132, dx: 45 * c.st, col: m, small: true }, feet: { dx: 9 * c.st, col: m, rx: 5 }, shadow: 32 * c.st };
    },
  },
  mushroom: {
    kind: 'vegetable', label: 'mushroom',
    pals: [['#9a5a36'], ['#c79a62'], ['#b5482f'], ['#6b4630'], ['#d9a441']],
    hats: ['ladybug', 'flower', 'sprig'], necks: ['scarf', 'bow', 'neckerchief'], extras: ['glasses', 'satchel', 'mug', 'book'],
    variant(key) { return pick(key, 'cap', ['dome', 'wide', 'bell']); },
    build(c) {
      const [b] = c.P, X = c.X, stem = '#f1e2c8';
      c.body(c.shape(c.sym([[0, 80], [22, 82], [27, 100], [29, 128], [33, 160], [30, 182], [0, 186]]), stem));
      c.body(`<ellipse cx="54" cy="92" rx="${f(36 * c.st)}" ry="7" fill="${shade(stem, 0.3)}"/>`);
      for (let i = -4; i <= 4; i++) c.body(`<path d="M${X(i * 3)} 88L${X(i * 8)} 97" stroke="${shade(stem, 0.45)}" stroke-width=".8" opacity=".6"/>`);
      const caps = {
        dome: { d: `M${X(-48)} 87C${X(-52)} 54 ${X(-30)} 30 54 30C${X(30)} 30 ${X(52)} 54 ${X(48)} 87C${X(30)} 95 ${X(-30)} 95 ${X(-48)} 87Z`, top: 31 },
        wide: { d: `M${X(-52)} 84C${X(-51)} 60 ${X(-28)} 44 54 44C${X(28)} 44 ${X(51)} 60 ${X(52)} 84C${X(32)} 93 ${X(-32)} 93 ${X(-52)} 84Z`, top: 45 },
        bell: { d: `M${X(-41)} 89C${X(-43)} 60 ${X(-22)} 22 54 20C${X(22)} 22 ${X(43)} 60 ${X(41)} 89C${X(26)} 96 ${X(-26)} 96 ${X(-41)} 89Z`, top: 21 },
      };
      const cap = caps[c.variant];
      c.body(c.shape(cap.d, b));
      if (b !== '#b5482f' && chance(c.key, 'speckle', 1, 2)) for (const [dx, y, r] of [[-26, 66, 3], [-6, 52, 2.4], [18, 60, 3.2], [32, 76, 2.2], [-38, 80, 2]]) c.body(`<circle cx="${X(dx)}" cy="${y + (cap.top - 30) * 0.6}" r="${r}" fill="${tint(b, 0.6)}" opacity=".75"/>`);
      return { face: { y: 110, es: 12.5, s: 0.98, my: 123.5, ms: 0.85 }, hat: { x: 54, y: cap.top, w: 26 }, neck: { y: 98, w: 27 * c.st },
        arms: { y: 140, dx: 30 * c.st, col: stem }, feet: { dx: 14 * c.st, col: shade(stem, 0.2) }, shadow: 34 * c.st };
    },
  },
  peapod: {
    kind: 'vegetable', label: 'pea-pod',
    pals: [['#8cbf5a', '#b8dc7a'], ['#5f9a48', '#9ccc6a'], ['#d9c45a', '#ece08a'], ['#8a5a96', '#9fc86c']],
    hats: ['ladybug', 'flower'], necks: ['scarf', 'bow', 'neckerchief'], extras: ['glasses', 'satchel'],
    build(c) {
      const [b, pea] = c.P, X = c.X, dk = shade(b, 0.18);
      c.back(`<path d="M54 40L55 28C57 18 67 18 65 27C63.5 33 56 31 59 23" stroke="${shade(GREENS[0], 0.15)}" stroke-width="1.8" fill="none" stroke-linecap="round"/>`);
      const pod = c.sym([[0, 36], [14, 40], [24, 58], [29, 84], [30, 112], [29, 140], [27, 162], [18, 180], [0, 186]]);
      c.body(c.shape(pod, b));
      const slit = `M54 128C${X(17)} 140 ${X(17)} 168 54 181C${X(-17)} 168 ${X(-17)} 140 54 128Z`;
      c.body(`<path d="${slit}" fill="${shade(b, 0.4)}"/>`);
      for (const y of [141, 156, 170]) c.body(`<circle cx="54" cy="${y}" r="${y === 170 ? 6 : 7}" fill="${c.pen.paint(pea)}" stroke="${line(pea)}" stroke-width="1" clip-path="${c.pen.clip(slit)}"/>`);
      c.body(`<path d="M${X(-1.5)} 44Q${X(-7)} 90 ${X(-2)} 126" stroke="${shade(b, 0.3)}" stroke-width="1.1" fill="none" opacity=".5"/>`);
      for (const a of [-75, -38, 0, 38, 75]) c.body(c.leaf(54, 40, a, 13, 5, GREENS[2]));
      return { face: { y: 88, es: 12, s: 0.98, my: 102, ms: 0.85 }, hat: { x: 54, y: 36, w: 14 }, neck: { y: 111, w: 30 * c.st },
        arms: { y: 118, dx: 30 * c.st, col: b, small: true }, feet: { dx: 11 * c.st, col: dk, rx: 6 }, shadow: 28 * c.st };
    },
  },
  carrot: {
    kind: 'vegetable', label: 'carrot',
    pals: [['#e8873a'], ['#8a4a7a'], ['#e8bf4a'], ['#d0573a'], ['#f0a64a']],
    hats: ['ladybug', 'flower'], necks: ['scarf', 'bow', 'neckerchief'], extras: ['glasses', 'satchel'],
    build(c) {
      const [b] = c.P, X = c.X, g = pick(c.key, 'green', GREENS);
      for (const [a, len] of [[-34, 44], [-8, 52], [18, 50], [40, 40]]) {
        let s = `<g transform="translate(54 60) rotate(${a})"><path d="M0 0Q2 ${-len * 0.5} 0 ${-len}" stroke="${shade(g, 0.15)}" stroke-width="2" fill="none" stroke-linecap="round"/>`;
        for (let k = 1; k <= 4; k++) { const y = -len * (0.25 + k * 0.17); s += `<ellipse cx="-4.5" cy="${f(y)}" rx="2.6" ry="5.4" transform="rotate(-38 -4.5 ${f(y)})" fill="${c.pen.paint(g)}"/><ellipse cx="4.5" cy="${f(y)}" rx="2.6" ry="5.4" transform="rotate(38 4.5 ${f(y)})" fill="${c.pen.paint(g)}"/>`; }
        c.back(s + `<ellipse cx="0" cy="${-len}" rx="2.6" ry="5" fill="${c.pen.paint(g)}"/></g>`);
      }
      const prof = [[0, 56], [24, 58], [36, 70], [38, 92], [33, 122], [25, 150], [15, 170], [0, 186]];
      c.body(c.shape(c.sym(prof), b));
      const half = (y) => { for (let i = 1; i < prof.length; i++) if (y <= prof[i][1]) { const [d0, y0] = prof[i - 1], [d1, y1] = prof[i]; return d0 + (d1 - d0) * (y - y0) / (y1 - y0); } return 0; };
      for (const [y, s] of [[96, -1], [116, 1], [136, -1], [154, 1], [128, -1]]) { const w = half(y) * c.st; c.body(`<path d="M${f(54 + s * (w - 0.5))} ${y}Q${f(54 + s * (w - 6))} ${y + 2.2} ${f(54 + s * (w - 11))} ${y + 1}" stroke="${shade(b, 0.3)}" stroke-width="1.2" fill="none" stroke-linecap="round" opacity=".6"/>`); }
      return { face: { y: 88, es: 14, s: 1, my: 103.5, ms: 0.9 }, hat: { x: 54, y: 57, w: 24 }, neck: { y: 109, w: 34 * c.st },
        arms: { y: 116, dx: 35 * c.st, col: b, small: true }, feet: { dx: 11 * c.st, col: shade(b, 0.2), rx: 6 }, shadow: 26 * c.st };
    },
  },
  eggplant: {
    kind: 'vegetable', label: 'eggplant',
    pals: [['#5e3b6e'], ['#7a4a8c'], ['#b593c4', 'stripe'], ['#efe8e0'], ['#86a85a']],
    hats: ['ladybug', 'flower'], necks: ['bow', 'scarf'], extras: ['glasses', 'satchel', 'book'],
    build(c) {
      const [b, stripe] = c.P, X = c.X, cal = '#6f9a4e';
      const body = c.sym([[0, 50], [14, 52], [22, 66], [28, 90], [38, 124], [43, 152], [37, 175], [21, 185], [0, 186]]);
      c.body(c.shape(body, b));
      if (stripe) for (const dx of [-26, -9, 9, 26]) c.body(`<path d="M${X(dx * 0.4)} 56Q${X(dx * 1.3)} 120 ${X(dx * 0.6)} 186" stroke="${tint(b, 0.7)}" stroke-width="4" fill="none" opacity=".65" clip-path="${c.pen.clip(body)}"/>`);
      c.body(`<path d="M${X(-26)} 108Q${X(-33)} 134 ${X(-27)} 160" stroke="#fff" stroke-width="3.6" fill="none" opacity=".3" stroke-linecap="round"/>`);
      c.body(`<path d="M54 52L56 35" stroke="${shade(cal, 0.25)}" stroke-width="5" stroke-linecap="round"/>`);
      for (const a of [128, 156, 180, 204, 232]) c.body(c.leaf(54, 50, a, a === 180 ? 20 : 17, 7.5, cal));
      c.body(c.ell(54, 50, 13, 5.5, cal));
      return { face: { y: 104, es: 15, s: 1.02, my: 119, ms: 0.95 }, hat: { x: 54, y: 45, w: 12 }, neck: { y: 76, w: 24 * c.st },
        arms: { y: 140, dx: 41 * c.st, col: b, small: true }, feet: { dx: 16 * c.st, col: shade(b, 0.2) }, shadow: 38 * c.st };
    },
  },
  pumpkin: {
    kind: 'vegetable', label: 'pumpkin',
    pals: [['#e08a3c'], ['#efe2c4'], ['#8fa0a3'], ['#d9a441', 'stripe'], ['#c8562e']],
    hats: ['ladybug', 'flower', 'sprig'], necks: [], extras: ['glasses', 'mug', 'book'],
    build(c) {
      const [b, stripe] = c.P, X = c.X;
      const body = c.sym([[0, 73], [10, 67], [24, 66], [38, 74], [46, 94], [48, 122], [45, 152], [36, 174], [20, 185], [0, 186]]);
      c.body(c.shape(body, b));
      for (const dx of [-32, -14, 14, 32]) c.body(`<path d="M${X(dx * 0.4)} 70C${X(dx * 1.45)} 88 ${X(dx * 1.45)} 166 ${X(dx * 0.5)} 185" stroke="${stripe ? tint(b, 0.6) : shade(b, 0.25)}" stroke-width="${stripe ? 3.2 : 1.4}" fill="none" opacity="${stripe ? 0.7 : 0.6}" stroke-linecap="round"/>`);
      c.body(c.shape(`M51 70C50 58 56 48 62 46L65 50.5C60 53 58 60 58 70Z`, '#7f6a3a'));
      c.body(`<path d="M61 56C68 54 72 60 68 63C65 65 63 61 66 60" stroke="#6b9a52" stroke-width="1.6" fill="none" stroke-linecap="round"/>`);
      if (chance(c.key, 'pleaf', 1, 2)) c.body(c.leaf(51, 66, -62, 18, 8, GREENS[1]));
      return { face: { y: 106, es: 16, s: 1.05, my: 122, ms: 1 }, hat: { x: 46, y: 64, w: 20 }, neck: null,
        arms: { y: 146, dx: 44 * c.st, col: b, small: true }, feet: { dx: 20 * c.st, col: shade(b, 0.25) }, shadow: 44 * c.st };
    },
  },
  potato: {
    kind: 'vegetable', label: 'potato',
    pals: [['#b4875a'], ['#d6b072'], ['#b8644e'], ['#7a5468'], ['#cfa774']],
    hats: ['beret', 'beanie', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'bow', 'neckerchief'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b] = c.P, N = 10, pts = [];
      for (let i = 0; i < N; i++) {
        const a = -Math.PI / 2 + i * 2 * Math.PI / N, k = 1 + ((draw(c.key, 'lump' + i) % 9) - 4) * 0.013;
        pts.push([54 + Math.cos(a) * 38 * c.st * k, Math.min(185, 124 + Math.sin(a) * 59 * (i === 0 ? 1 : k))]);
      }
      c.body(c.shape(smooth(pts), b));
      const spots = [[-26, 148], [22, 158], [28, 84], [-30, 96], [8, 170], [-14, 166]];
      for (let i = 0; i < 6; i++) if (draw(c.key, 'dimple' + i) % 2) { const [dx, y] = spots[i]; c.body(`<path d="M${c.X(dx) - 2.4} ${y}Q${c.X(dx)} ${y + 2.4} ${c.X(dx) + 2.4} ${y}" stroke="${shade(b, 0.35)}" stroke-width="1.1" fill="none" stroke-linecap="round" opacity=".7"/>`); }
      if (chance(c.key, 'sprout', 1, 3)) { c.back(`<path d="M62 70Q63 62 66 58" stroke="#8a6a8a" stroke-width="1.6" fill="none" stroke-linecap="round"/>`); c.back(c.leaf(66, 59, 40, 9, 4, '#9ab062')); c.back(c.leaf(65.5, 60, -30, 7, 3.5, '#9ab062')); }
      return { face: { y: 106, es: 15, s: 1, my: 121, ms: 0.95 }, hat: { x: 54, y: 66, w: 30 }, neck: { y: 128, w: 38 * c.st },
        arms: { y: 140, dx: 39 * c.st, col: b }, feet: { dx: 16 * c.st, col: shade(b, 0.2) }, shadow: 38 * c.st };
    },
  },
  // ---- card#12212: four more animals and four more vegetables, drawn to the approved look and appended to the list ----
  // `added` names the card that drew a species after the approved sheet. It is a provenance label the character sheet
  // reads for its NEW mark (`tools/characters/sheet.browser.mjs`); nothing in the draw reads it — every key draws its
  // species by the one `pick` over the whole list (the operator's ruling of 2026-10-22, FLOOR.md § 10.4's growth bullet).
  penguin: {
    kind: 'animal', label: 'penguin-ish', added: 'card#12212',
    pals: [['#3e4a5c', '#f6f1e6'], ['#2d3a4f', '#fbf5ea'], ['#55657a', '#f4f0e8'], ['#5a4a44', '#f5ecdf'], ['#3f6b72', '#eef4ee']],
    hats: ['beanie', 'beret', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X, beak = '#e8a04a', foot = '#e3a24f';
      const body = c.sym([[0, 50], [22, 53], [35, 72], [42, 104], [44, 140], [39, 170], [25, 184], [0, 186]]);
      c.body(c.shape(body, b));
      const clip = c.pen.clip(body);
      // the pale front, one tall oval from the chest to the feet, and the two face patches meeting over the beak
      c.body(`<ellipse cx="54" cy="144" rx="${f(29 * c.st)}" ry="42" fill="${c.pen.paint(m)}" clip-path="${clip}"/>`);
      for (const s of [-1, 1]) c.body(`<ellipse cx="${X(s * 14)}" cy="93" rx="${f(14 * c.st)}" ry="16" fill="${c.pen.paint(m)}" clip-path="${clip}"/>`);
      // a little tuft at the crown, and the beak pointing down between the eyes
      c.body(`<path d="M${X(-4)} 52Q${X(-1)} 42 ${X(3)} 45Q${X(5)} 38 ${X(8)} 46" stroke="${line(b)}" stroke-width="1.6" fill="none" stroke-linecap="round"/>`);
      c.front(`<path d="M${X(-5.5)} 104Q54 101.5 ${X(5.5)} 104Q${X(3)} 113 54 115Q${X(-3)} 113 ${X(-5.5)} 104Z" fill="${c.pen.paint(beak)}" stroke="${line(beak)}" stroke-width="1" stroke-linejoin="round"/><ellipse cx="52.4" cy="105.2" rx="1.6" ry=".7" fill="#fff" opacity=".55"/>`);
      return { face: { y: 94, es: 14, s: 1.05, my: null, ms: 1, beak: true }, hat: { x: 54, y: 51, w: 24 }, neck: { y: 118, w: 42 * c.st },
        arms: { y: 136, dx: 41 * c.st, col: shade(b, 0.08) }, feet: { dx: 15 * c.st, col: foot, rx: 10 }, shadow: 40 * c.st };
    },
  },
  snail: {
    kind: 'animal', label: 'snail-ish', added: 'card#12212',
    pals: [['#c98a5a', '#f3dfc4'], ['#9a6b8f', '#efe0e6'], ['#7f9f6a', '#e9efd6'], ['#d9a05a', '#f8ecd6'], ['#6f7fa6', '#e6e8f0']],
    hats: ['beret', 'flower', 'ladybug', 'straw'], necks: ['bow', 'neckerchief', 'scarf'], extras: ['glasses', 'book', 'mug'],
    build(c) {
      const [b, m] = c.P, X = c.X, t = -c.side;
      // the shell, behind and over one shoulder — the shoulder AWAY from the key's side, which is the side the sleeper
      // rolls toward, so the shell swings in and not out of the frame; a round shell with its spiral drawn in its own shadow
      const sx = X(t * 16), sy = 112, R = 24;
      c.back(`<ellipse cx="${f(sx)}" cy="${sy}" rx="${f(R * c.st)}" ry="${R}" fill="${c.pen.paint(b)}" stroke="${line(b)}" stroke-width="1.6"/>`);
      const turns = 2.3, pts = [];
      for (let i = 0; i <= 48; i++) { const a = i / 48 * turns * 2 * Math.PI, r = (R - 4) * (1 - i / 48) ** 0.8; pts.push([sx + Math.cos(a + 0.6) * r * c.st * t, sy + 2 + Math.sin(a + 0.6) * r]); }
      c.back(`<path d="${curve(pts)}" stroke="${shade(b, 0.45)}" stroke-width="2.4" fill="none" stroke-linecap="round" opacity=".8"/>`);
      c.back(`<ellipse cx="${f(sx - 7 * c.st)}" cy="${sy - 9}" rx="${f(5.5 * c.st)}" ry="4" fill="${tint(b, 0.55)}" opacity=".6" transform="rotate(-30 ${f(sx - 7 * c.st)} ${sy - 9})"/>`);
      // the feelers, two soft stalks from the crown, each with a bulb
      for (const s of [-1, 1]) c.back(`<path d="M${X(s * 8)} 92Q${X(s * 11)} 78 ${X(s * 14)} 68" stroke="${line(m)}" stroke-width="2.6" fill="none" stroke-linecap="round"/><circle cx="${X(s * 14.5)}" cy="66" r="4.2" fill="${c.pen.paint(m)}" stroke="${line(m)}" stroke-width="1.2"/>`);
      // the soft body, head and foot in one, a shade darker under the shell
      const body = c.sym([[0, 88], [18, 90], [24, 112], [26, 138], [32, 162], [40, 182], [0, 186]]);
      c.body(c.shape(body, m));
      c.body(`<ellipse cx="54" cy="168" rx="${f(38 * c.st)}" ry="14" fill="${shade(m, 0.16)}" clip-path="${c.pen.clip(body)}" opacity=".6"/>`);
      return { face: { y: 106, es: 12, s: 1, my: 119, ms: 0.85 }, hat: { x: 54, y: 89, w: 18 }, neck: { y: 130, w: 27 * c.st },
        arms: { y: 150, dx: 28 * c.st, col: m, small: true }, feet: null, shadow: 44 * c.st };
    },
  },
  sheep: {
    kind: 'animal', label: 'sheep-ish', added: 'card#12212',
    pals: [['#f4ecdc', '#7a5f55'], ['#fbf4e8', '#b07a4a'], ['#f2dfd6', '#8c6f78'], ['#e5dcea', '#6f7488'], ['#e9dfc8', '#5e4b45']],
    hats: ['beret', 'beanie', 'straw', 'flower', 'ladybug'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X, nose = '#d98c8c';
      // ears out to the sides, behind the face
      for (const s of [-1, 1]) c.back(`<g transform="rotate(${s * 22} ${X(s * 25)} 96)">${c.ell(X(s * 25), 96, 11, 6, m)}</g>`);
      // the fleece: one scalloped cloud, body and crown in one
      c.body(c.shape(scallop(54, 134, 37 * c.st, 47, 16, 6), b));
      // the face, an oval set into the fleece, with the fleece's forelock over the brow
      c.body(c.ell(54, 104, 19 * c.st, 23, m));
      c.body(c.shape(scallop(54, 80, 20 * c.st, 9, 7, 4.5), b));
      c.body(`<path d="M50.6 118Q54 116 57.4 118Q56 122.4 54 123Q52 122.4 50.6 118Z" fill="${c.pen.paint(nose)}" stroke="${line(nose)}" stroke-width=".9" stroke-linejoin="round"/>`);
      return { face: { y: 104, es: 11, s: 0.95, my: 123, ms: 0.75 }, hat: { x: 54, y: 72, w: 20 }, neck: { y: 128, w: 32 * c.st },
        arms: { y: 152, dx: 34 * c.st, col: m, small: true }, feet: { dx: 14 * c.st, col: m }, shadow: 42 * c.st };
    },
  },
  deer: {
    kind: 'animal', label: 'deer-ish', added: 'card#12212',
    pals: [['#c48a5a', '#f6e8d2'], ['#a86b48', '#f3e2cc'], ['#8f7a66', '#efe4d6'], ['#d9a86a', '#fbf1de'], ['#b89a86', '#f6ebe3']],
    hats: ['flower', 'ladybug', 'beret', 'straw'], necks: ['scarf', 'neckerchief', 'bow'], extras: ['glasses', 'satchel', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X, horn = '#b39a78';
      // young antlers — a beam and one fork each — and the ears, out and up
      for (const s of [-1, 1]) {
        const d = `M${X(s * 12)} 72C${X(s * 15)} 60 ${X(s * 17)} 50 ${X(s * 22)} 40M${X(s * 16)} 56C${X(s * 21)} 54 ${X(s * 26)} 50 ${X(s * 30)} 44M${X(s * 19)} 46C${X(s * 16)} 42 ${X(s * 15)} 38 ${X(s * 16)} 31`;
        c.back(`<path d="${d}" stroke="${line(horn)}" stroke-width="5.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="${d}" stroke="${horn}" stroke-width="3.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`);
        c.back(`<g transform="rotate(${-s * 40} ${X(s * 30)} 86)">${c.ell(X(s * 30), 86, 8, 15, b)}<ellipse cx="${X(s * 30)}" cy="88" rx="4" ry="10" fill="${m}" opacity=".85"/></g>`);
      }
      const body = c.sym([[0, 116], [20, 121], [28, 142], [31, 164], [26, 182], [0, 185]]);
      c.body(c.shape(body, b));
      c.body(`<ellipse cx="54" cy="152" rx="${f(14 * c.st)}" ry="22" fill="${m}" clip-path="${c.pen.clip(body)}"/>`);
      if (chance(c.key, 'spots', 1, 2)) for (const [dx, y, r] of [[-22, 130, 2.2], [24, 136, 2], [-25, 150, 1.8], [26, 156, 2.1], [-20, 168, 1.9], [22, 172, 1.7]]) c.body(`<circle cx="${X(dx)}" cy="${y}" r="${r}" fill="${tint(m, 0.3)}" opacity=".8"/>`);
      c.body(c.shape(c.sym([[0, 66], [18, 68], [29, 80], [33, 98], [30, 114], [20, 126], [0, 130]]), b));
      c.body(`<ellipse cx="54" cy="117" rx="${f(13 * c.st)}" ry="10" fill="${c.pen.paint(m)}" opacity=".95"/>`);
      c.body(`<path d="M50.2 112.4Q54 110.4 57.8 112.4Q56.4 117.4 54 118Q51.6 117.4 50.2 112.4Z" fill="${EYE}"/><ellipse cx="52.6" cy="112.4" rx="1.4" ry=".7" fill="${GLINT}" opacity=".7"/>`);
      return { face: { y: 98, es: 13, s: 1, my: 121.5, ms: 0.8 }, hat: { x: 54, y: 67, w: 18 }, neck: { y: 124, w: 26 * c.st },
        arms: { y: 150, dx: 27 * c.st, col: b }, feet: { dx: 13 * c.st, col: shade(b, 0.5), rx: 7 }, shadow: 32 * c.st };
    },
  },
  corn: {
    kind: 'vegetable', label: 'corn', added: 'card#12212',
    pals: [['#f0c84a', '#7fa65a'], ['#f6e7b8', '#8db55e'], ['#b8503a', '#6b9a52'], ['#6f6aa8', '#8aa06a'], ['#e89a3c', '#79a85a']],
    hats: ['ladybug', 'flower', 'straw'], necks: ['bow', 'neckerchief', 'scarf'], extras: ['glasses', 'satchel'],
    build(c) {
      const [b, g] = c.P, X = c.X;
      // the silk, a few pale wisps from the tip
      c.back(`<path d="M${X(-4)} 58Q${X(-10)} 46 ${X(-6)} 36M54 56Q${X(2)} 44 ${X(-2)} 33M${X(4)} 58Q${X(10)} 48 ${X(6)} 38" stroke="${tint(b, 0.45)}" stroke-width="1.7" fill="none" stroke-linecap="round" opacity=".9"/>`);
      const cob = c.sym([[0, 54], [16, 58], [25, 80], [28, 110], [27, 140], [23, 166], [14, 182], [0, 186]]);
      c.body(c.shape(cob, b));
      // the kernels: one pattern of staggered rounded squares, clipped to the cob
      const pid = c.pen.uid(), cw = f(8 * c.st), cl = shade(b, 0.3), hl = tint(b, 0.55);
      const kernel = (x, y) => `<rect x="${f(x - 3 * c.st)}" y="${f(y - 3.4)}" width="${f(6 * c.st)}" height="6.8" rx="2.2" fill="none" stroke="${cl}" stroke-width=".9" opacity=".5"/><ellipse cx="${f(x - 1 * c.st)}" cy="${f(y - 1.4)}" rx="${f(1.1 * c.st)}" ry=".9" fill="${hl}" opacity=".8"/>`;
      c.pen.defs.push(`<pattern id="${pid}" patternUnits="userSpaceOnUse" x="${f(54 - 4 * c.st)}" y="0" width="${cw}" height="10">${kernel(4 * c.st, 2.5)}${kernel(0, 7.5)}${kernel(8 * c.st, 7.5)}</pattern>`);
      c.body(`<path d="${cob}" fill="url(#${pid})" clip-path="${c.pen.clip(cob)}"/>`);
      // the husk: two leaves wrapping the lower cob, their tips peeled out and up
      for (const s of [-1, 1]) {
        c.body(c.shape(`M${X(s * 2)} 186C${X(s * 30)} 184 ${X(s * 38)} 150 ${X(s * 33)} 118C${X(s * 31)} 100 ${X(s * 38)} 94 ${X(s * 42)} 84C${X(s * 36)} 88 ${X(s * 29)} 98 ${X(s * 22)} 110C${X(s * 14)} 124 ${X(s * 10)} 156 ${X(s * 2)} 186Z`, g));
        c.body(`<path d="M${X(s * 8)} 180C${X(s * 20)} 150 ${X(s * 29)} 122 ${X(s * 38)} 92" stroke="${tint(g, 0.45)}" stroke-width="1.1" fill="none" stroke-linecap="round" opacity=".7"/>`);
      }
      return { face: { y: 98, es: 13, s: 1, my: 113, ms: 0.9 }, hat: { x: 54, y: 55, w: 16 }, neck: { y: 124, w: 26 * c.st },
        arms: { y: 142, dx: 34 * c.st, col: g, small: true }, feet: { dx: 10 * c.st, col: shade(g, 0.2), rx: 6 }, shadow: 28 * c.st };
    },
  },
  broccoli: {
    kind: 'vegetable', label: 'broccoli', added: 'card#12212',
    pals: [['#4f8a4a', '#cfe0b0'], ['#6aa05a', '#d8e6b8'], ['#7a5f9a', '#e2d8ea'], ['#a9c04e', '#e8ecc0'], ['#3f7f70', '#cfe3d8']],
    hats: ['beret', 'beanie', 'ladybug', 'flower'], necks: ['bow', 'scarf', 'neckerchief'], extras: ['glasses', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      // the stalk, flaring at its foot (its neck sits low on it, so no satchel: one would hang below the foot line)
      c.body(c.shape(c.sym([[0, 98], [18, 100], [21, 124], [22, 150], [28, 172], [31, 184], [0, 186]]), m));
      // the crown: a scalloped canopy with its floret clusters
      c.body(c.shape(scallop(54, 75, 40 * c.st, 34, 18, 6), b));
      for (const [dx, y, r] of [[-20, 65, 9], [6, 55, 10], [24, 73, 8], [-6, 85, 9], [18, 95, 6.5], [-27, 89, 6.5]]) c.body(`<path d="${scallop(X(dx), y, r * c.st, r * 0.85, 7, 2)}" fill="${shade(b, 0.22)}" opacity=".5"/>`);
      return { face: { y: 126, es: 11, s: 0.9, my: 139, ms: 0.8 }, hat: { x: 54, y: 42, w: 26 }, neck: { y: 150, w: 22 * c.st },
        arms: { y: 148, dx: 25 * c.st, col: m, small: true }, feet: { dx: 14 * c.st, col: shade(m, 0.2) }, shadow: 36 * c.st };
    },
  },
  pepper: {
    kind: 'vegetable', label: 'bell pepper', added: 'card#12212',
    pals: [['#d8453a'], ['#e9b63a'], ['#e7832f'], ['#6f9a3c'], ['#6a4a86']],
    hats: ['ladybug', 'flower', 'straw'], necks: [], extras: ['glasses', 'mug', 'book'],
    build(c) {
      const [b] = c.P, X = c.X, stem = '#6f9a4e';
      // three lobes: high shoulders with a dip for the stem, and a bumped foot
      const body = c.sym([[0, 74], [14, 66], [29, 66], [40, 76], [44, 104], [44, 140], [40, 168], [29, 183], [12, 180], [0, 183]]);
      c.body(c.shape(body, b));
      for (const dx of [-16, 16]) c.body(`<path d="M${X(dx * 0.9)} 70C${X(dx * 1.6)} 110 ${X(dx * 1.6)} 150 ${X(dx * 0.9)} 184" stroke="${shade(b, 0.3)}" stroke-width="1.6" fill="none" opacity=".5" stroke-linecap="round"/>`);
      c.body(`<path d="M${X(-30)} 92Q${X(-37)} 122 ${X(-31)} 152" stroke="#fff" stroke-width="4.2" fill="none" opacity=".32" stroke-linecap="round"/>`);
      // the stem, leaning aside, on its calyx
      c.body(`<path d="M${X(4)} 72C${X(4)} 62 ${X(7)} 54 ${X(12)} 48" stroke="${line(stem)}" stroke-width="7" fill="none" stroke-linecap="round"/><path d="M${X(4)} 72C${X(4)} 62 ${X(7)} 54 ${X(12)} 48" stroke="${stem}" stroke-width="4.6" fill="none" stroke-linecap="round"/>`);
      for (const a of [-60, -20, 20, 60]) c.body(c.leaf(X(4), 74, a, 11, 5, shade(stem, 0.08)));
      return { face: { y: 108, es: 15, s: 1.05, my: 124, ms: 1 }, hat: { x: X(-8), y: 66, w: 18 }, neck: null,
        arms: { y: 146, dx: 42 * c.st, col: b, small: true }, feet: { dx: 18 * c.st, col: shade(b, 0.25) }, shadow: 42 * c.st };
    },
  },
  cabbage: {
    kind: 'vegetable', label: 'cabbage', added: 'card#12212',
    pals: [['#bfd48a', '#6f9a52'], ['#9a6aa8', '#5e3b6e'], ['#d6dc8c', '#4f7f44'], ['#dfe8cf', '#8aa678'], ['#d9a6c8', '#8a5a96']],
    hats: ['ladybug', 'flower', 'beret'], necks: ['bow', 'neckerchief'], extras: ['glasses', 'mug', 'book'],
    build(c) {
      const [b, m] = c.P, X = c.X;
      // the outer leaves, behind: three scalloped leaves fanning left, right and up, each with a pale midrib
      for (const [dx, y, rx, ry, rot] of [[-19, 122, 19, 38, -20], [19, 122, 19, 38, 20], [0, 94, 32, 28, 0]]) {
        c.back(`<g transform="rotate(${rot} ${X(dx)} ${y})">${c.shape(scallop(X(dx), y, rx * c.st, ry, 12, 4), m)}<path d="M${X(dx)} ${y + ry * 0.8}L${X(dx)} ${y - ry * 0.7}" stroke="${tint(m, 0.45)}" stroke-width="1.4" stroke-linecap="round" opacity=".7"/></g>`);
      }
      const head = c.sym([[0, 72], [24, 74], [38, 90], [43, 118], [42, 148], [35, 174], [20, 185], [0, 186]]);
      c.body(c.shape(head, b));
      for (const a of [-40, -14, 14, 40]) c.body(`<path d="M54 185Q${X(a * 0.9)} 160 ${X(a * 1.1)} 138" stroke="${tint(b, 0.4)}" stroke-width="1.6" fill="none" opacity=".6" stroke-linecap="round"/>`);
      return { face: { y: 110, es: 15, s: 1.05, my: 126, ms: 1 }, hat: { x: 54, y: 72, w: 24 }, neck: { y: 150, w: 35 * c.st },
        arms: { y: 148, dx: 40 * c.st, col: b, small: true }, feet: { dx: 18 * c.st, col: shade(b, 0.25) }, shadow: 40 * c.st };
    },
  },
};
/**
 * WITHHELD — the one closed list a ruling on FLOOR.md § 14 item 33(1) edits. Each entry removes one
 * member from every list it appears in: `hat:<name>` from every species' hat list, `<species>:<hex>` one
 * colourway. The operator's ruling of 2026-10-06 ("flagged items: Use your recommendations", applied by
 * the seat as withholding all three) withholds the three members § 10.5 named for review: the `sprig`
 * hat, the radish's near-white colourway and the turnip's lavender one. Withdrawing a member moves every
 * key whose draw landed past it — that ruling's stated cost.
 */
export const WITHHELD = Object.freeze(['hat:sprig', 'radish:#e9d6d2', 'turnip:#7d6aa8']);

const withheld = new Set(WITHHELD);
const keepHat = (h) => !withheld.has(`hat:${h}`);

/** The species, each with its closed lists after WITHHELD — the generator's one home for them. */
export const SPECIES = Object.freeze(Object.fromEntries(Object.entries(APPROVED).map(([k, sp]) => [k, Object.freeze({
  ...sp,
  pals: sp.pals.filter((p) => !withheld.has(`${k}:${p[0]}`)),
  hats: sp.hats.filter(keepHat),
})])));

/**
 * The species list, in its one fixed order: the approved sheet's species in the order the salt was searched over,
 * then the species each later card added, appended in the order they were drawn.
 *
 * ⛔ ONE LITERAL LINE, AND THE SERVER READS IT (card#12443). `App\Characters\SpeciesList` parses exactly this line —
 * `export const SPECIES_KEYS = Object.freeze([ … ]);`, single-quoted ASCII keys of at most 16 bytes (the width of
 * `seats.species`) — to allocate each seat's body (docs/design/FLEET-STATE.md § 6.13), so the list has one source and
 * two readers, and `TheSpeciesListHasOneSourceTest` holds the PHP reading equal to what `node` imports. Keep it on one
 * line and written out. `tools/characters/selftest.mjs` holds it equal, in order, to `Object.keys(SPECIES)`.
 *
 * The list GROWS BY APPENDING and never shrinks under a seat: a seat's species is stored at its creation, so an
 * appended species moves no seat (FLOOR.md § 10.4's growth bullet); removing one is D2 § 6.13's process rule.
 */
export const SPECIES_KEYS = Object.freeze(['fox', 'bear', 'rabbit', 'owl', 'frog', 'mole', 'hedgehog', 'otter', 'radish', 'turnip', 'mushroom', 'peapod', 'carrot', 'eggplant', 'pumpkin', 'potato', 'penguin', 'snail', 'sheep', 'deer', 'corn', 'broccoli', 'pepper', 'cabbage']);

// ---- the shared dimensions (closed populations) ----
export const EYES = ['round', 'sparkle', 'happy', 'sleepy', 'bead'];
export const MOUTHS = ['smile', 'open', 'cat', 'tiny', 'grin'];
export const BLUSH = [0, 0.5, 0.8];
export const BROWS = ['none', 'none', 'soft', 'worried'];
export const SIZES = [0.84, 0.88, 0.92, 0.96, 1.0];
export const STOUT = [0.93, 1, 1.07];
export const TILTS = [-3, 0, 3];
export const ACCENTS = ['#c8553d', '#e0a43a', '#7f9f6a', '#6f8fb0', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f'];

/**
 * The draw field the species is selected under — the salt (FLOOR.md § 10.4's salt bullet): searched once per list and
 * fixed until the list next grows. `species:s1` was the first field keeping every room of the committed roster distinct
 * over the approved sixteen (card#11046); `species:s3` is the first doing so, for the roster and the control, over the
 * twenty-four the list grew to (card#12212, the operator's ruling of 2026-10-23). A re-pick is surfaced, never silent.
 */
export const SPECIES_FIELD = 'species:s3';

/**
 * The species a key draws — since card#12443 the server's PREFERENCE for a seat (D2 § 6.13 allocates the body it
 * delivers, starting from this draw) and the harness's own sheet; the painter never draws a seat from it. An
 * intern's key (`seat~<call_id>`, § 10.4) never draws its seat's body: `internSpeciesOf()` below, handed here the
 * seat key's own draw, which is what a sheet with nothing delivered shows.
 * @param {string} key @returns {string}
 */
export function speciesOf(key) {
  const own = pick(key, SPECIES_FIELD, SPECIES_KEYS);
  const cut = key.indexOf('~');

  if (cut < 0) {
    return own;
  }

  return internSpeciesOf(key, speciesOf(key.slice(0, cut)));
}

/**
 * The species an intern draws beside a seat whose body is `seatSpecies` — on a floor, the seat's DELIVERED species
 * (`character.species`, card#12443), never the seat key's own draw. Its own draw, and where that lands on the seat's
 * body, a re-draw under a field of its own over the other species: a pure function of the intern's key and that one
 * value (decision 54), reading no sibling.
 * @param {string} key the intern key `seat~<call_id>` @param {string} seatSpecies @returns {string}
 */
export function internSpeciesOf(key, seatSpecies) {
  const own = pick(key, SPECIES_FIELD, SPECIES_KEYS);

  if (own !== seatSpecies) {
    return own;
  }

  return pick(key, 'species-redraw', SPECIES_KEYS.filter((s) => s !== seatSpecies));
}

/**
 * A key's recipe: one independent draw per named field (seed.js's `draw`). `force` is the species to draw the body as —
 * a seat's DELIVERED species (`character.species`, card#12443) — and every other field is still the key's own draw.
 * A forced species this tree does not hold is a throw naming it, which the painter draws as FLOOR.md § 9 F14's
 * placeholder; it never falls back to the key's own body.
 */
export function recipe(key, force) {
  if (force !== undefined && force !== null && !Object.hasOwn(SPECIES, force)) {
    throw new RangeError(`no species "${force}" in this character tree (SPECIES_KEYS); the seat object names a body this tree cannot draw`);
  }
  const species = force || speciesOf(key);
  const sp = SPECIES[species];
  const variant = sp.variant ? sp.variant(key) : null;
  return {
    key, species, variant, pal: draw(key, 'pal') % sp.pals.length,
    stout: pick(key, 'stout', STOUT), size: pick(key, 'size', SIZES), tilt: pick(key, 'tilt', TILTS),
    eyes: pick(key, 'eyes', EYES), mouth: pick(key, 'mouth', MOUTHS), blush: pick(key, 'blush', BLUSH), brows: pick(key, 'brows', BROWS),
    hatDraw: draw(key, 'hat'), neckDraw: draw(key, 'neck'), extra: pick(key, 'extra', ['none', 'none', ...sp.extras]),
    acc1: pick(key, 'acc1', ACCENTS), acc2: pick(key, 'acc2', ACCENTS),
  };
}

// ---- shared feature painters ----
function eyesSvg(pen, F, style) {
  const s = F.s, out = [];
  for (const side of [-1, 1]) {
    const x = 54 + side * F.es, y = F.y;
    if (style === 'happy') out.push(`<path d="M${f(x - 4.2 * s)} ${f(y + 1.2 * s)}Q${f(x)} ${f(y - 4.8 * s)} ${f(x + 4.2 * s)} ${f(y + 1.2 * s)}" stroke="${EYE}" stroke-width="${f(2.2 * s)}" fill="none" stroke-linecap="round"/>`);
    else if (style === 'sleepy') out.push(`<path d="M${f(x - 4.2 * s)} ${f(y - 0.6 * s)}A${f(4.2 * s)} ${f(4.4 * s)} 0 0 0 ${f(x + 4.2 * s)} ${f(y - 0.6 * s)}Z" fill="${EYE}"/><path d="M${f(x - 4.8 * s)} ${f(y - 0.9 * s)}Q${f(x)} ${f(y - 2.2 * s)} ${f(x + 4.8 * s)} ${f(y - 0.9 * s)}" stroke="${EYE}" stroke-width="${f(1.5 * s)}" fill="none" stroke-linecap="round"/><circle cx="${f(x - 1.4 * s)}" cy="${f(y + 1.1 * s)}" r="${f(0.9 * s)}" fill="${GLINT}"/>`);
    else if (style === 'bead') out.push(`<circle cx="${f(x)}" cy="${f(y)}" r="${f(2.9 * s)}" fill="${EYE}"/><circle cx="${f(x - 1 * s)}" cy="${f(y - 1.1 * s)}" r="${f(1 * s)}" fill="${GLINT}"/>`);
    else if (style === 'sparkle') out.push(`<ellipse cx="${f(x)}" cy="${f(y)}" rx="${f(4.1 * s)}" ry="${f(5.3 * s)}" fill="${EYE}"/><ellipse cx="${f(x)}" cy="${f(y + 2.4 * s)}" rx="${f(2.9 * s)}" ry="${f(2.3 * s)}" fill="#6b4a3e" opacity=".75"/><circle cx="${f(x - 1.4 * s)}" cy="${f(y - 1.9 * s)}" r="${f(1.8 * s)}" fill="${GLINT}"/><circle cx="${f(x + 1.5 * s)}" cy="${f(y + 1.6 * s)}" r="${f(0.8 * s)}" fill="${GLINT}"/>`);
    else out.push(`<ellipse cx="${f(x)}" cy="${f(y)}" rx="${f(3.7 * s)}" ry="${f(4.4 * s)}" fill="${EYE}"/><circle cx="${f(x - 1.2 * s)}" cy="${f(y - 1.6 * s)}" r="${f(1.5 * s)}" fill="${GLINT}"/><circle cx="${f(x + 1.3 * s)}" cy="${f(y + 1.6 * s)}" r="${f(0.65 * s)}" fill="${GLINT}" opacity=".9"/>`);
  }
  return out.join('');
}
function browsSvg(F, style) {
  if (style === 'none') return '';
  const s = F.s, out = [];
  for (const side of [-1, 1]) {
    const x = 54 + side * F.es, y = F.y - 8.2 * s;
    const inner = style === 'worried' ? -1.6 : 0.4, outer = style === 'worried' ? 1 : 0.4;
    out.push(`<path d="M${f(x - side * 3.4 * s)} ${f(y + inner * s)}Q${f(x)} ${f(y - 1.6 * s)} ${f(x + side * 3.4 * s)} ${f(y + outer * s)}" stroke="${EYE}" stroke-width="${f(1.3 * s)}" fill="none" stroke-linecap="round" opacity=".8"/>`);
  }
  return out.join('');
}
function mouthSvg(F, style) {
  if (F.my == null) return '';
  const x = 54, y = F.my, k = F.ms;
  const P = (dx, dy) => `${f(x + dx * k)} ${f(y + dy * k)}`;
  switch (style) {
    case 'open': return `<path d="M${P(-3.6, 0)}Q${P(0, 0.8)} ${P(3.6, 0)}Q${P(2.8, 6.2)} ${P(0, 6.2)}Q${P(-2.8, 6.2)} ${P(-3.6, 0)}Z" fill="#7a3a3a"/><ellipse cx="${x}" cy="${f(y + 4.4 * k)}" rx="${f(2 * k)}" ry="${f(1.5 * k)}" fill="#e48c8c"/>`;
    case 'cat': return `<path d="M${P(-5, 0)}Q${P(-2.5, 3.2)} ${P(0, 0)}Q${P(2.5, 3.2)} ${P(5, 0)}" stroke="${MOUTH}" stroke-width="${f(1.6 * Math.max(k, 0.85))}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
    case 'tiny': return `<ellipse cx="${x}" cy="${f(y + 1.4 * k)}" rx="${f(1.7 * k)}" ry="${f(2 * k)}" fill="#6e3434"/>`;
    case 'grin': return `<path d="M${P(-5.6, 0)}Q${P(0, 1)} ${P(5.6, 0)}Q${P(0, 9)} ${P(-5.6, 0)}Z" fill="#7a3a3a"/><path d="M${P(-2.8, 4.8)}Q${P(0, 2.8)} ${P(2.8, 4.8)}Q${P(0, 6.4)} ${P(-2.8, 4.8)}Z" fill="#e48c8c"/>`;
    default: return `<path d="M${P(-4.2, 0)}Q${P(0, 4.2)} ${P(4.2, 0)}" stroke="${MOUTH}" stroke-width="${f(1.7 * Math.max(k, 0.85))}" fill="none" stroke-linecap="round"/>`;
  }
}
function blushSvg(pen, F, op, dxExtra = 0) {
  if (!op) return '';
  const s = F.s;
  return [-1, 1].map((side) => `<ellipse cx="${f(54 + side * (F.es + (5.5 + dxExtra) * s))}" cy="${f(F.y + 7 * s)}" rx="${f(5.4 * s)}" ry="${f(3.4 * s)}" fill="${pen.soft('#f07c6c', op)}"/>`).join('');
}

function hatSvg(pen, H, kind, acc, acc2) {
  const { x, y, w } = H;
  switch (kind) {
    case 'beret': return `<g transform="rotate(-9 ${x} ${y + 4})"><ellipse cx="${f(x + w * 0.12)}" cy="${f(y + 3)}" rx="${f(w * 0.98)}" ry="${f(w * 0.36)}" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.4"/><path d="M${f(x + w * 0.1)} ${f(y - w * 0.3)}l1.5 -5" stroke="${line(acc)}" stroke-width="2.4" stroke-linecap="round"/></g>`;
    case 'beanie': {
      const by = y + w * 0.62;
      return `<path d="M${f(x - w * 1.02)} ${f(by)}C${f(x - w * 1.05)} ${f(y - w * 0.4)} ${f(x + w * 1.05)} ${f(y - w * 0.4)} ${f(x + w * 1.02)} ${f(by)}Z" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.4"/>` +
        `<rect x="${f(x - w * 1.08)}" y="${f(by - 4)}" width="${f(w * 2.16)}" height="8" rx="4" fill="${pen.paint(shade(acc, 0.12))}" stroke="${line(acc)}" stroke-width="1.3"/>` +
        [-0.6, -0.2, 0.2, 0.6].map((k) => `<path d="M${f(x + w * k * 1.05)} ${f(by - 2.6)}v5.2" stroke="${line(acc)}" stroke-width=".8" opacity=".5"/>`).join('') +
        `<circle cx="${x}" cy="${f(y - w * 0.12)}" r="5.5" fill="${pen.paint(acc2)}" stroke="${line(acc2)}" stroke-width="1.2"/>`;
    }
    case 'straw': {
      const straw = '#e8c57e', by = y + w * 0.38;
      return `<path d="M${f(x - w * 0.62)} ${f(by)}C${f(x - w * 0.66)} ${f(y - w * 0.5)} ${f(x + w * 0.66)} ${f(y - w * 0.5)} ${f(x + w * 0.62)} ${f(by)}Z" fill="${pen.paint(straw)}" stroke="${line(straw)}" stroke-width="1.3"/>` +
        `<path d="M${f(x - w * 0.63)} ${f(by - 4)}Q${x} ${f(by - 1)} ${f(x + w * 0.63)} ${f(by - 4)}L${f(x + w * 0.62)} ${f(by)}Q${x} ${f(by + 3)} ${f(x - w * 0.62)} ${f(by)}Z" fill="${acc}"/>` +
        `<ellipse cx="${x}" cy="${f(by + 1)}" rx="${f(w * 1.32)}" ry="${f(w * 0.24)}" fill="${pen.paint(straw)}" stroke="${line(straw)}" stroke-width="1.3"/>`;
    }
    case 'flower': {
      const cx = x + w * 0.78, cy = y + w * 0.22, col = [acc, '#fbf3ea', '#f2a7b6', '#a9c4e8'][Math.floor(acc.charCodeAt(2)) % 4];
      let s = '';
      for (let i = 0; i < 5; i++) s += `<ellipse cx="${f(cx)}" cy="${f(cy - 4.2)}" rx="3.1" ry="4.4" transform="rotate(${i * 72 + 10} ${f(cx)} ${f(cy)})" fill="${pen.paint(col)}" stroke="${line(col)}" stroke-width=".9"/>`;
      return s + `<circle cx="${f(cx)}" cy="${f(cy)}" r="2.6" fill="#f2c44a" stroke="#b98a2a" stroke-width=".8"/>`;
    }
    // Unreachable while `hat:sprig` is in WITHHELD (FLOOR.md § 14 item 33(1)) — kept, not dead code to delete:
    // a reversal of that ruling is one line out of WITHHELD and redraws the hat the operator approved.
    case 'sprig': {
      const cx = x + w * 0.8, cy = y + w * 0.25, g = '#7fa65a';
      return `<g transform="translate(${f(cx)} ${f(cy)})"><path d="M0 0L5 -9" stroke="${line(g)}" stroke-width="1.3" stroke-linecap="round"/><path d="${leafD(11, 4.2)}" transform="rotate(18)" fill="${pen.paint(g)}" stroke="${line(g)}" stroke-width="1"/><path d="${leafD(10, 4)}" transform="rotate(58)" fill="${pen.paint(tint(g, 0.15))}" stroke="${line(g)}" stroke-width="1"/><circle cx="5" cy="-10" r="2" fill="#d65a4a"/></g>`;
    }
    case 'ladybug': {
      const cx = x - w * 0.32, cy = y + 1.4, r = '#d8493a';
      return `<g transform="rotate(-14 ${f(cx)} ${f(cy)})"><ellipse cx="${f(cx - 5.4)}" cy="${f(cy + 0.6)}" rx="2.6" ry="2.3" fill="#2e2428"/><path d="M${f(cx - 5)} ${f(cy + 1.4)}A5.8 4.8 0 0 1 ${f(cx + 6)} ${f(cy + 1.4)}Z" fill="${pen.paint(r)}" stroke="#6a2420" stroke-width="1"/><path d="M${f(cx + 0.5)} ${f(cy - 3.3)}V${f(cy + 1.4)}" stroke="#2e2428" stroke-width=".8"/><circle cx="${f(cx - 2)}" cy="${f(cy - 0.8)}" r="1.1" fill="#2e2428"/><circle cx="${f(cx + 3)}" cy="${f(cy - 0.6)}" r="1.1" fill="#2e2428"/><circle cx="${f(cx - 1.2)}" cy="${f(cy - 2.6)}" r=".7" fill="#fff" opacity=".7"/></g>`;
    }
  }
  return '';
}
function neckSvg(pen, N, kind, acc, acc2, side) {
  const { y, w } = N, x = 54;
  switch (kind) {
    case 'scarf': {
      const tx = x + side * w * 0.45;
      let s = `<path d="M${f(tx - 5)} ${f(y + 4)}L${f(tx + side * 2 - 5.5)} ${f(y + 26)}L${f(tx + side * 2 + 5.5)} ${f(y + 26)}L${f(tx + 5)} ${f(y + 4)}Z" fill="${pen.paint(shade(acc, 0.06))}" stroke="${line(acc)}" stroke-width="1.3" stroke-linejoin="round"/>`;
      s += `<path d="M${f(tx + side * 2 - 5)} ${f(y + 20)}H${f(tx + side * 2 + 5)}" stroke="${acc2}" stroke-width="2.4"/>`;
      for (let i = -2; i <= 2; i++) s += `<path d="M${f(tx + side * 2 + i * 2.2)} ${f(y + 26)}v3" stroke="${line(acc)}" stroke-width="1.1" stroke-linecap="round"/>`;
      s += `<path d="M${f(x - w * 1.02)} ${f(y - 4)}Q${x} ${f(y + 6)} ${f(x + w * 1.02)} ${f(y - 4)}L${f(x + w * 0.98)} ${f(y + 4)}Q${x} ${f(y + 14)} ${f(x - w * 0.98)} ${f(y + 4)}Z" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.3" stroke-linejoin="round"/>`;
      s += `<path d="M${f(x - w * 0.95)} ${f(y)}Q${x} ${f(y + 10)} ${f(x + w * 0.95)} ${f(y)}" stroke="${acc2}" stroke-width="2.2" fill="none" opacity=".85"/>`;
      return s;
    }
    case 'neckerchief':
      return `<path d="M${f(x - w * 0.82)} ${f(y - 1)}Q${x} ${f(y + 5)} ${f(x + w * 0.82)} ${f(y - 1)}L${x} ${f(y + 18)}Z" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.3" stroke-linejoin="round"/>` +
        [[-6, 6], [5, 8], [0, 12], [-1, 3]].map(([dx, dy]) => `<circle cx="${x + dx}" cy="${f(y + dy)}" r="1.1" fill="${tint(acc, 0.75)}"/>`).join('');
    case 'bow': {
      const by = y + 3;
      return `<path d="M${x} ${f(by)}C${x - 4} ${f(by - 7)} ${x - 11} ${f(by - 6)} ${x - 10} ${f(by)}C${x - 11} ${f(by + 6)} ${x - 4} ${f(by + 7)} ${x} ${f(by)}ZM${x} ${f(by)}C${x + 4} ${f(by - 7)} ${x + 11} ${f(by - 6)} ${x + 10} ${f(by)}C${x + 11} ${f(by + 6)} ${x + 4} ${f(by + 7)} ${x} ${f(by)}Z" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.2" stroke-linejoin="round"/>` +
        `<path d="M${x - 1.5} ${f(by + 2)}L${x - 5} ${f(by + 10)}M${x + 1.5} ${f(by + 2)}L${x + 5} ${f(by + 10)}" stroke="${line(acc)}" stroke-width="2.4" stroke-linecap="round"/><path d="M${x - 1.5} ${f(by + 2)}L${x - 5} ${f(by + 10)}M${x + 1.5} ${f(by + 2)}L${x + 5} ${f(by + 10)}" stroke="${acc}" stroke-width="1.2" stroke-linecap="round"/>` +
        `<ellipse cx="${x}" cy="${f(by)}" rx="2.8" ry="3.2" fill="${pen.paint(shade(acc, 0.1))}" stroke="${line(acc)}" stroke-width="1"/>`;
    }
  }
  return '';
}
function glassesSvg(F) {
  const s = F.s, r = 6.8 * s, col = '#5a3a2a';
  return [-1, 1].map((side) => `<circle cx="${f(54 + side * F.es)}" cy="${f(F.y)}" r="${f(r)}" fill="#fff" fill-opacity=".14" stroke="${col}" stroke-width="1.5"/>`).join('') +
    `<path d="M${f(54 - F.es + r)} ${f(F.y - 0.8)}Q54 ${f(F.y - 3)} ${f(54 + F.es - r)} ${f(F.y - 0.8)}" stroke="${col}" stroke-width="1.4" fill="none"/>`;
}
function satchelSvg(pen, N, acc, side) {
  const leather = '#9a6a42', bx = 54 + side * (N.w * 0.72), by = N.y + 34;
  return `<path d="M${f(54 - side * N.w * 0.78)} ${f(N.y + 1)}L${f(bx)} ${f(by)}" stroke="${line(leather)}" stroke-width="4" stroke-linecap="round"/><path d="M${f(54 - side * N.w * 0.78)} ${f(N.y + 1)}L${f(bx)} ${f(by)}" stroke="${leather}" stroke-width="2.4" stroke-linecap="round"/>` +
    `<rect x="${f(bx - 11)}" y="${f(by - 4)}" width="22" height="17" rx="4" fill="${pen.paint(leather)}" stroke="${line(leather)}" stroke-width="1.3"/><path d="M${f(bx - 11)} ${f(by + 1)}Q${f(bx)} ${f(by + 8)} ${f(bx + 11)} ${f(by + 1)}V${f(by - 1)}Q${f(bx + 11)} ${f(by - 4)} ${f(bx + 7)} ${f(by - 4)}H${f(bx - 7)}Q${f(bx - 11)} ${f(by - 4)} ${f(bx - 11)} ${f(by - 1)}Z" fill="${pen.paint(shade(leather, 0.1))}" stroke="${line(leather)}" stroke-width="1.1"/><circle cx="${f(bx)}" cy="${f(by + 4.5)}" r="1.6" fill="${acc}"/>`;
}
function heldSvg(pen, kind, y, acc, paw) {
  const x = 54;
  let item = '';
  if (kind === 'mug') {
    const mug = '#f3ead8';
    item = `<path d="M${x + 7} ${y - 3}C${x + 13} ${y - 3} ${x + 13} ${y + 6} ${x + 7} ${y + 6}" stroke="${line(mug)}" stroke-width="2.6" fill="none"/><rect x="${x - 8}" y="${y - 7}" width="16" height="16" rx="3.5" fill="${pen.paint(mug)}" stroke="${line(mug)}" stroke-width="1.3"/><path d="M${x - 8} ${y - 1}H${x + 8}" stroke="${acc}" stroke-width="3"/><path d="M${x - 2} ${y - 10}q-2 -3 0 -6M${x + 3} ${y - 10}q-2 -3 0 -6" stroke="#fff" stroke-width="1.2" fill="none" stroke-linecap="round" opacity=".55"/>`;
  } else {
    item = `<rect x="${x - 11}" y="${y - 7}" width="22" height="16" rx="2" fill="${pen.paint(acc)}" stroke="${line(acc)}" stroke-width="1.3"/><path d="M${x - 11} ${y + 6}H${x + 11}" stroke="#f6eedc" stroke-width="2"/><path d="M${x - 7} ${y - 3}H${x + 5}" stroke="${tint(acc, 0.6)}" stroke-width="1.2"/>`;
  }
  const paws = [-1, 1].map((s) => `<ellipse cx="${x + s * 10}" cy="${y + 3}" rx="5.2" ry="4.8" fill="${pen.paint(paw)}" stroke="${line(paw)}" stroke-width="1.2"/>`).join('');
  return item + paws;
}

// ---- the rim (card#11046) ----
// A faint light rim just outside every silhouette, so a dark colourway does not sink into a dark floor.
// It is the character's own drawing re-stroked in cream and widened, behind the drawing, at low opacity:
// every outlined shape gains RIM_W units of cream outside its coloured line. Eye-judged (FLOOR.md § 10.4).
export const RIM = '#fff6e6';
export const RIM_W = 2.4;
const RIM_OPACITY = 0.45;

function rimOf(markup) {
  return markup
    .replace(/ (fill|stroke)="(?!none")[^"]*"/g, ` $1="${RIM}"`)
    .replace(/ stroke-width="([\d.]+)"/g, (m, w) => ` stroke-width="${f(+w + RIM_W)}"`)
    .replace(/ (?:opacity|fill-opacity|stroke-opacity)="[^"]*"/g, '');
}

// ---- the frames ----
/**
 * THE CHARACTER's RECT, in frame units: a seat's 108 × 192 — two units per floor pixel of its 54 × 96 px rect
 * (`floor/desk-layout.js`'s `FRAME_UNITS_PER_PX`) — the space every body is drawn into, x 54 its centre and the
 * foot line at its bottom. Since card#12259 it is NO document's viewBox: every seat document is drawn in
 * `SEAT_VIEWBOX` (below, beside the sleeper's z's), which is this rect with a margin on three sides, because the
 * frame's own composition — the key's tilt, the walk's lean, and the widest girth at the largest size — carries
 * approved bodies past this rect's side by up to a dozen units, and the painter clips at the frame's edge
 * (FLOOR.md § 10.4's frame contract; § 14 item 33(6)). What is held to this rect is the floor's element rect,
 * which every overlap rule reads, and the foot line.
 */
export const SEAT_RECT = Object.freeze({ x: 0, y: 0, w: 108, h: 192 });
/**
 * THE INTERN's CHIBI FRAME: the 20 × 32 px intern rect's 108 × 172.8 units (the rect's aspect, the last 172.8 units
 * above the foot) widened to the stool PITCH the floor lays interns at — 24 px for a 20 px rect, so the frame is
 * `108 × 24 / 20` = 129.6 units wide, 10.8 each side past the rect — the margin the chibi frame's tilt needs and all
 * the room a row of interns has (two frames meet at the pitch and never overlap). The floor draws it into the intern's
 * overdraw rect (`floor/desk-layout.js`'s `internOverdraw()`); the two are held equal by `tools/characters/selftest.mjs` § 7b.
 */
export const CHIBI_VIEWBOX = Object.freeze({ x: -10.8, y: 19.2, w: 129.6, h: 172.8 });
const FOOT = 188;

/** The chibi frame's least head share — FLOOR.md § 12's *Intern head share* row. */
export const CHIBI_HEAD_SHARE = 0.5;
/** The share the chibi frame is drawn to, above the least so a rounding never lands it under. */
const CHIBI_TARGET = 0.6;

/**
 * Where a species' head is, in its own drawing: its top (the hat anchor) and its chin — the neck line
 * for a creature with a head of its own, and just below the mouth (or the owl's beak) for a creature
 * whose face sits on its one body.
 */
function headOf(A) {
  const F = A.face;
  const below = F.my == null ? F.y + 22 : F.my + 12;
  const chin = A.neck && A.neck.y > F.y ? Math.min(A.neck.y, below + 10) : below;
  return { top: A.hat.y, chin };
}

/** The chibi geometry for a built species: how far the body below the chin is squashed. */
function chibiOf(A) {
  const { top, chin } = headOf(A);
  const head = chin - top, body = FOOT - chin;
  const k = Math.min(0.75, (head / body) * (1 / CHIBI_TARGET - 1));
  return { top, chin, k, share: head / (head + body * k) };
}

function build(key, force) {
  const r = recipe(key, force);
  const sp = SPECIES[r.species];
  const pen = new Pen('c');
  const P = sp.pals[r.pal];
  const c = makeCtx(pen, key, P, r.stout);
  c.variant = r.variant;
  const A = sp.build(c);
  return { r, sp, pen, c, A };
}

/**
 * What a built key wears — its hat, its neckwear and its extra — from its recipe's draws over the species'
 * lists. The one home of the selection, which every frame and `describe()` read.
 */
function worn(r, sp, A) {
  const hats = A.hats || sp.hats;
  const necks = A.neck ? sp.necks : [];
  const extra = r.extra === 'satchel' && !A.neck ? 'none' : r.extra;
  return {
    hat: ['none', 'none', 'none', ...hats][r.hatDraw % (hats.length + 3)],
    neck: ['none', 'none', ...necks][r.neckDraw % (necks.length + 2)],
    extra,
  };
}

/**
 * One frame of one key, as a standalone SVG document.
 * @param {string} key the seat key, or an intern key `seat~<call_id>`
 * @param {{ phase?: 0|1|2, chibi?: boolean, species?: string }} [opt] phase 0 is the standing frame
 * @returns {string}
 */
export function frameDocument(key, opt = {}) {
  const phase = opt.phase ?? 0;
  if (![0, 1, 2].includes(phase)) throw new RangeError(`phase must be 0, 1 or 2, got ${phase}`);
  const { r, sp, pen, c, A } = build(key, opt.species);
  const { hat, neck, extra } = worn(r, sp, A);
  const F = A.face;
  // The walk: phase 1 lifts the left foot, phase 2 the right; arms swing against the stepping foot.
  const lifted = (s) => (phase === 1 && s === -1) || (phase === 2 && s === 1);
  const swing = phase === 0 ? 0 : (phase === 1 ? 9 : -9);

  const parts = [];
  parts.push(...c.layers.back);
  if (A.feet) {
    for (const s of [-1, 1]) {
      const up = lifted(s) ? 5 : 0, fx = 54 + s * A.feet.dx + (lifted(s) ? s * 1.5 : 0);
      if (A.feet.talon) for (const k of [-3, 0, 3]) parts.push(`<ellipse cx="${f(fx + k)}" cy="${185 - up}" rx="1.9" ry="3.2" fill="${pen.paint(A.feet.col)}" stroke="${line(A.feet.col)}" stroke-width=".8"/>`);
      else parts.push(`<ellipse cx="${f(fx)}" cy="${184 - up}" rx="${A.feet.rx || 8.5}" ry="4.6" fill="${pen.paint(A.feet.col)}" stroke="${line(A.feet.col)}" stroke-width="1.3"/>`);
    }
  }
  parts.push(...c.layers.body);
  const holding = (extra === 'mug' || extra === 'book') && A.arms;
  if (A.arms && !holding) {
    const ar = A.arms;
    if (ar.clasp) for (const s of [-1, 1]) parts.push(`<ellipse cx="${54 + s * ar.dx}" cy="${ar.y}" rx="6.4" ry="5.4" fill="${pen.paint(ar.col)}" stroke="${line(ar.col)}" stroke-width="1.3"/>`);
    else for (const s of [-1, 1]) {
      const ax = 54 + s * ar.dx, rx = ar.small ? 4.6 : 6.2, ry = ar.small ? 6.6 : 9;
      parts.push(`<ellipse cx="${f(ax)}" cy="${ar.y}" rx="${rx}" ry="${ry}" transform="rotate(${s * -22 + swing} ${f(ax)} ${ar.y})" fill="${pen.paint(ar.col)}" stroke="${line(ar.col)}" stroke-width="1.3"/>`);
    }
  }
  if (neck !== 'none' && extra === 'satchel') parts.push(satchelSvg(pen, A.neck, r.acc2, -c.side));
  parts.push(...c.layers.front);
  parts.push(blushSvg(pen, F, r.blush, A.blushDx || 0));
  parts.push(eyesSvg(pen, F, r.eyes));
  parts.push(browsSvg(F, r.brows));
  parts.push(mouthSvg(F, F.beak ? null : r.mouth));
  if (neck !== 'none') parts.push(neckSvg(pen, A.neck, neck, r.acc1, r.acc2, c.side));
  if (neck === 'none' && extra === 'satchel') parts.push(satchelSvg(pen, A.neck, r.acc2, -c.side));
  if (extra === 'glasses') parts.push(glassesSvg(F));
  if (holding) parts.push(heldSvg(pen, extra, A.arms.y - 4, r.acc2, A.arms.col));
  if (hat !== 'none') parts.push(hatSvg(pen, A.hat, hat, r.acc1, r.acc2));

  const markup = parts.join('');
  const drawing = `<g opacity="${RIM_OPACITY}">${rimOf(markup)}</g>${markup}`;
  const s = r.size;   // girth lives in the profile (st), so the scale stays uniform
  const lean = phase === 0 ? 0 : (phase === 1 ? -2 : 2);
  const tf = `translate(54 ${FOOT}) scale(${s} ${s}) rotate(${r.tilt + lean}) translate(-54 -${FOOT})`;
  // The shadow ends ON the foot line (187.5 + 4.5 = 192, the rect's bottom): at ry 4.6 it reached a tenth of a unit
  // under it in every frame, the one bottom-edge overshoot the frame-bounds leg found (card#12259).
  const shadow = `<ellipse cx="54" cy="187.5" rx="${f(A.shadow * s)}" ry="4.5" fill="${pen.soft('#1c1424', 0.42)}"/>`;
  let inner = drawing, vb = SEAT_VIEWBOX;

  if (opt.chibi) {
    // Re-proportioned, not shrunk: the head is drawn as it is, the body below the chin is squashed
    // toward the foot, and the head comes down to sit on it. The two bands meet at the chin line, so
    // the outline is continuous there.
    const g = chibiOf(A);
    const shift = (FOOT - g.chin) * (1 - g.k);
    // The drawing is held once in <defs> and placed twice by a same-document <use>, so the frame
    // carries one copy of it.
    pen.defs.push(`<g id="c_drawing">${drawing}</g>`);
    pen.defs.push(`<clipPath id="c_head"><rect x="-200" y="-200" width="508" height="${f(g.chin + 200)}"/></clipPath>`);
    pen.defs.push(`<clipPath id="c_body"><rect x="-200" y="${f(g.chin)}" width="508" height="400"/></clipPath>`);
    inner = `<g transform="translate(0 ${f(shift)})" clip-path="url(#c_head)"><use href="#c_drawing"/></g>` +
      `<g transform="translate(0 ${f(shift + g.chin)}) scale(1 ${f(g.k)}) translate(0 -${f(g.chin)})" clip-path="url(#c_body)"><use href="#c_drawing"/></g>`;
    vb = CHIBI_VIEWBOX;
  }

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${vb.x} ${vb.y} ${vb.w} ${vb.h}" width="${vb.w}" height="${vb.h}" preserveAspectRatio="xMidYMax meet"><defs>${pen.defs.join('')}</defs>${shadow}<g transform="${tf}">${inner}</g></svg>`;
}

// ---- the sleeper (FLOOR.md § 6.2 A6, § 10.4's frame contract item 4; card#12159, card#12187) ----
/**
 * THE SLEEPER's PULSE — A6's 4 fps loop is this many LOOP FRAMES (FLOOR.md § 12's *Sleeper z pulse*). The tree draws a
 * sleeper as `SLEEP_LOOP + 1` DOCUMENTS: document 0 is the STILL — every z at full brightness, the reduced-motion form,
 * the same for every seat — and document n is loop frame n − 1. Only the z's brightness changes between documents: the
 * sleeper and its z's stand still in every one (§ 10.4 item 4's *The documents are a still and then the loop*).
 */
export const SLEEP_LOOP = 8;
/** The z's a sleeper draws: three, fixed above its head (the operator's ruling of 2026-10-22, card#12187). */
export const SLEEP_ZS = 3;
/** The largest a seat's `L` may be, in frame units; each seat's own is `zLargeFor()`'s. */
export const Z_LARGE = 36;
/** The least `L` the search goes down to; a seat whose room holds no `L` this large is refused, never drawn clamped. */
const Z_LEAST = 10;
/** The path's two ends, in frame units off the head's centre AWAY from the side it rolled to (§ 10.4 item 4). */
export const Z_A0 = 10;
export const Z_A1 = 108;
/** The path's last z is `Z_RATIO` of its first's size: it shrinks from `L` to `Z_RATIO · L` along the path. */
export const Z_RATIO = 0.5;
/** Where z1 stands along the path; z3 stands at `1 − Z_T0` and z2 halfway. */
export const Z_T0 = 0.17;
/** The gap between the path's start — its imaginary z's box and glow — and the crest line (or the props' line), in frame units. */
export const Z_GAP = 2;
/** The raised frame's top, in frame units: the path's end stands its imaginary z's box and glow a unit under it. */
export const Z_TOP = -42;
/**
 * THE SEAT's FRAME (FLOOR.md § 12's *Character frame*; § 10.4's frame contract) — the viewBox of EVERY seat document:
 * the character's 108 × 192 rect (`SEAT_RECT`) widened by half its width each side and raised 42 units, 216 × 234
 * from (−54, −42). card#12187 widened and raised it for the sleeper, so the z's have room above and beside the
 * crest; card#12259 draws the standing and walking frames in the same frame, so the composition's tilt and lean,
 * and the widest girth at the largest size, never carry an approved body past the edge the painter clips at (the
 * margin they need is measured by `tools/characters/selftest.mjs` § 8b, never written down; the frame's is 54 a
 * side). The floor draws every seat document into the character's overdraw rect (`floor/desk-layout.js`'s
 * `characterOverdraw()`), whose aspect is this frame's; the sleeper's z-stand still reads the character's own
 * 0–108 units as the body's column (`zStandKeeps()`).
 */
export const SEAT_VIEWBOX = Object.freeze({ x: -54, y: Z_TOP, w: 216, h: 234 });
/**
 * THE BRIGHTNESS LEVELS 0 (dim) to 3 (full), FLOOR.md § 12's *Sleeper z brightness*: the core is the pale fill mixed
 * toward the ink by `Z_GLOW[level]`, and behind the z stands a halo of the palette's `--lamp` (`Z_LAMP`), `Z_HALO[level]`
 * units wider than the outline each side at `Z_HALO_OPACITY[level]`. The outline is the ink at every level.
 */
export const Z_GLOW = Object.freeze([0.5, 0.3, 0.12, 0]);
export const Z_HALO = Object.freeze([0, 1.5, 3, 5]);
export const Z_HALO_OPACITY = Object.freeze([0, 0.5, 0.8, 1]);
const Z_LAMP = '#ffcf7d';
/** How far the sleeper's head rolls onto its arms, in degrees, toward the key's own side. */
const SLEEP_ROLL = 14;
const Z_INK = '#4f4380', Z_FILL = '#ece8ff';

/**
 * THE CREST TABLE (FLOOR.md § 10.4 item 4): the topmost ink of a sleeper, in frame units from the character frame's
 * top, per species and per variant where it has them — `<species>` or `<species>/<variant>`. The recipe keeps no
 * bounding box of an ear, a leaf, a cap or a hat, so the table states "above the head" for the law below, and
 * `tools/design/sleeper-zs.browser.mjs` holds every scanned seat's drawing at or below it.
 */
export const CREST_TOP = Object.freeze({
  bear: 53, carrot: 31, eggplant: 39, fox: 34, frog: 73, hedgehog: 67, mole: 60,
  'mushroom/bell': 15, 'mushroom/dome': 25, 'mushroom/wide': 38, otter: 63, owl: 49, peapod: 44, potato: 56,
  pumpkin: 47, 'rabbit/lop': 62, 'rabbit/upright': 20, radish: 25, turnip: 27,
  // card#12212's species: each the floor of the topmost ink `tools/design/svg-document.mjs`'s inkBounds reads over the
  // sleeper's own documents (without their z's) across the selftest's keys — tools/characters/selftest.mjs § 8b holds
  // every entry at or above that ink on every run, beside the tool's raster scan
  penguin: 56, snail: 64, sheep: 68, deer: 37, corn: 45, broccoli: 15, pepper: 42, cabbage: 47,
});

/** Closed eyes: a soft downward arc per eye, with a lash at its outer end. */
function sleepingEyesSvg(F) {
  const s = F.s, out = [];
  for (const side of [-1, 1]) {
    const x = 54 + side * F.es, y = F.y + 0.6 * s;
    out.push(`<path d="M${f(x - 4.4 * s)} ${f(y)}Q${f(x)} ${f(y + 4.2 * s)} ${f(x + 4.4 * s)} ${f(y)}" stroke="${EYE}" stroke-width="${f(2 * s)}" fill="none" stroke-linecap="round"/>`);
    out.push(`<path d="M${f(x + side * 4.2 * s)} ${f(y + 0.2 * s)}l${f(side * 1.8 * s)} ${f(-1.2 * s)}" stroke="${EYE}" stroke-width="${f(1.3 * s)}" fill="none" stroke-linecap="round"/>`);
  }
  return `<g data-part="eyes-closed">${out.join('')}</g>`;
}

/** A z `h` tall's two stroke widths: its pale core's and its dark outline's. */
function zStrokes(h) {
  const core = Math.max(1.4, h * 0.16);
  return { core, outline: core + 2.6 };
}

/** A z `h` tall's half extent across and down, its outline's half-width included — its body box about its centre. */
function zHalf(h) {
  const o = zStrokes(h).outline / 2;
  return { w: h * 0.4 + o, h: h / 2 + o };
}

/** A z's Z stroke, centred at (x, y), `h` tall. */
function zPath(x, y, h) {
  const w = h * 0.8;
  return `M${f(x - w / 2)} ${f(y - h / 2)}H${f(x + w / 2)}L${f(x - w / 2)} ${f(y + h / 2)}H${f(x + w / 2)}`;
}

/** One fixed z at brightness `level`: the dark outline, and the core mixed from the pale fill toward the ink by the level. */
function zSvg(z, level) {
  const sw = zStrokes(z.h), d = zPath(z.x, z.y, z.h);
  const stroke = (col, width) => `<path d="${d}" stroke="${col}" stroke-width="${f(width)}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
  return `<g data-part="z" data-k="${z.k}" data-level="${level}" data-x="${f(z.x)}" data-y="${f(z.y)}" data-h="${f(z.h)}">${stroke(Z_INK, sw.outline)}${stroke(mix(Z_FILL, Z_INK, Z_GLOW[level]), sw.core)}</g>`;
}

/** A fixed z's halo at `level`: none at level 0, else a wider stroke of the lamp colour, translucent by level. */
function haloSvg(z, level) {
  if (level === 0) return '';
  return `<path data-part="halo" data-k="${z.k}" data-level="${level}" d="${zPath(z.x, z.y, z.h)}" stroke="${Z_LAMP}" stroke-width="${f(zStrokes(z.h).outline + 2 * Z_HALO[level])}" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="${f(Z_HALO_OPACITY[level])}"/>`;
}

/**
 * THE PULSE (FLOOR.md § 10.4 item 4, § 12's *Sleeper z pulse*): the levels of z1, z2, z3 at loop frame `f` — a bubble of
 * brightness reaches z`k+1` at loop frame `2k`, peaks there at 3 and glows 2 and 1 in the loop frames behind it, and z1
 * is 1 in the loop frame before the bubble arrives: `300 200 130 020 013 002 001 100`.
 * @param {number} f a loop frame, 0..SLEEP_LOOP-1 @returns {number[]} a level 0–3 per z, z1 first
 */
export function zLevels(f) {
  return Array.from({ length: SLEEP_ZS }, (_, k) => {
    const d = (((f - 2 * k) % SLEEP_LOOP) + SLEEP_LOOP) % SLEEP_LOOP;
    return d === 0 ? 3 : d === 1 ? 2 : d === 2 ? 1 : d === SLEEP_LOOP - 1 && k === 0 ? 1 : 0;
  });
}

/**
 * WHERE THE THREE z's STAND for a crest top `crown`, a roll `dir` (+1 when the head rolled toward +x) and a size `L` —
 * FLOOR.md § 10.4 item 4's law, exactly as `tools/design/sleeper-zs.browser.mjs`'s `above3Fixed()` computes it. The path
 * runs from an imaginary z of size `L` whose box and brightest glow stand `Z_GAP` above the crest line (or the props'
 * line, where that is higher) to one of size `Z_RATIO · L` whose box and glow end a unit under the raised frame's top;
 * z `k` stands at `t_k` along it, `x_k = 54 − dir·(Z_A0 + (Z_A1 − Z_A0)·t_k)`, never clamped.
 * @param {number} crown @param {number} dir @param {number} L @param {{name: string, y: number}[]} clear the floor's clear rects
 * @returns {{k: number, x: number, y: number, h: number}[]} z1 first, the lowest
 */
export function zStand(crown, dir, L, clear) {
  const props = clear.find((c) => c.name === 'props');
  const S = L * Z_RATIO, G = Z_HALO[3];
  const y0 = Math.min(crown - Z_GAP, props.y - Z_GAP) - zHalf(L).h - G;
  const y1 = Math.max(Z_TOP, SEAT_VIEWBOX.y) + zHalf(S).h + G + 1;
  return Array.from({ length: SLEEP_ZS }, (_, k) => {
    const t = Z_T0 + (1 - 2 * Z_T0) * k / (SLEEP_ZS - 1);
    return { k, x: 54 - dir * (Z_A0 + (Z_A1 - Z_A0) * t), y: y0 + (y1 - y0) * t, h: L + (S - L) * t };
  });
}

/**
 * Whether a stand of size `L` keeps § 10.4 item 4's four rules — `above3Why()`'s, rule by rule: (1) the brightest glow
 * inside the frame on all four sides; (2) no two bodies' boxes meeting, a unit apart; (3) no body's box meeting a clear
 * rect, a unit apart; (4) no z, its glow included, below the crest line within the character's own 108 units.
 */
function zStandKeeps(crown, dir, L, clear) {
  const zs = zStand(crown, dir, L, clear).map((z) => ({ ...z, hb: zHalf(z.h) }));
  const G = Z_HALO[3], V = SEAT_VIEWBOX;
  for (const z of zs) if (z.y - z.hb.h - G < V.y - 0.01 || z.y + z.hb.h + G > V.y + V.h) return false;
  for (const z of zs) if (z.x - z.hb.w - G < V.x - 0.01 || z.x + z.hb.w + G > V.x + V.w + 0.01) return false;
  for (let a = 0; a < zs.length; a += 1) for (let b = a + 1; b < zs.length; b += 1) {
    const A = zs[a], B = zs[b];
    if (Math.abs(A.x - B.x) < A.hb.w + B.hb.w + 1 && Math.abs(A.y - B.y) < A.hb.h + B.hb.h + 1) return false;
  }
  for (const z of zs) {
    for (const c of clear) if (z.x + z.hb.w > c.x - 1 && z.x - z.hb.w < c.x + c.w + 1 && z.y + z.hb.h > c.y - 1 && z.y - z.hb.h < c.y + c.h + 1) return false;
    if (z.x + z.hb.w + G > 0 && z.x - z.hb.w - G < 108 && z.y + z.hb.h + G > crown - Z_GAP + 0.01) return false;
  }
  return true;
}

/**
 * THE SEAT's OWN SIZE `L` (FLOOR.md § 10.4 item 4, § 12's *Sleeper's z's*): the largest, in half-unit steps from `Z_LARGE`
 * down to 10, whose stand keeps all four rules — `above3Plan()`'s `L`. A seat with no room for even the least is refused
 * (a RangeError the painter reports as § 9 F14), never drawn with z's clamped into the furniture.
 * @param {number} crown @param {number} dir @param {object[]} clear @returns {number}
 */
export function zLargeFor(crown, dir, clear) {
  for (let L = Z_LARGE; L >= Z_LEAST; L -= 0.5) if (zStandKeeps(crown, dir, L, clear)) return L;
  throw new RangeError(`no three z's of size ${Z_LEAST} or more fit above crest ${crown}`);
}

/**
 * The SLEEPER — one document of a key slumped asleep at its desk, as a standalone SVG document (A6).
 *
 * The creature is its own recipe — body, colourway, hat, neckwear, glasses — with its eyes closed and its
 * brows and held thing put away, its head rolled onto its forearms, which lie along the desk's top edge. The
 * edge is the CALLER's (`deskTop`, a share of the character frame's height, which the floor derives from its desk
 * rects): the frame has no desk of its own, and everything below the edge is drawn behind the desk the floor
 * lays over it. Above the head stand three z's (`zStand()`), each lit at its level for the document — every z at 3 in
 * document 0, the still, and `zLevels(n − 1)` in document n — all halos first, behind every z, and the z's in front of
 * the sleeper. `clear` is the floor's furniture in frame units (`floor/desk-layout.js`'s `characterOverdraw()`), which
 * no z's body enters.
 *
 * @param {string} key the seat key
 * @param {{ frame?: number, deskTop: number, clear: object[], species?: string }} opt `frame` the document, 0..SLEEP_LOOP
 * @returns {string}
 */
export function sleepDocument(key, opt) {
  const frame = opt.frame ?? 0;
  if (!Number.isInteger(frame) || frame < 0 || frame > SLEEP_LOOP) throw new RangeError(`frame must be 0..${SLEEP_LOOP}, got ${frame}`);
  if (!(opt.deskTop > 0 && opt.deskTop < 1)) throw new RangeError(`deskTop must be a share of the frame's height in (0, 1), got ${opt.deskTop}`);
  if (!Array.isArray(opt.clear) || !opt.clear.some((c) => c.name === 'props')) throw new RangeError("clear must be the floor's clear rects, the desk's props among them");
  const { r, sp, pen, c, A } = build(key, opt.species);
  const { hat, neck, extra } = worn(r, sp, A);
  const F = A.face;
  const s = r.size;
  const edge = opt.deskTop * SEAT_RECT.h;
  const dir = c.side;
  const crown = CREST_TOP[r.variant === null ? r.species : `${r.species}/${r.variant}`];
  if (crown === undefined) throw new RangeError(`CREST_TOP declares no crest for ${r.species}${r.variant === null ? '' : `/${r.variant}`}`);

  // The head and the body under it, rolled about the chin, the chin resting just above the edge.
  const parts = [...c.layers.back, ...c.layers.body];
  if (neck !== 'none' && extra === 'satchel') parts.push(satchelSvg(pen, A.neck, r.acc2, -c.side));
  parts.push(...c.layers.front);
  parts.push(blushSvg(pen, F, r.blush, A.blushDx || 0));
  parts.push(sleepingEyesSvg(F));
  parts.push(mouthSvg(F, F.beak ? null : 'tiny'));
  if (neck !== 'none') parts.push(neckSvg(pen, A.neck, neck, r.acc1, r.acc2, c.side));
  if (neck === 'none' && extra === 'satchel') parts.push(satchelSvg(pen, A.neck, r.acc2, -c.side));
  if (extra === 'glasses') parts.push(glassesSvg(F));
  if (hat !== 'none') parts.push(hatSvg(pen, A.hat, hat, r.acc1, r.acc2));
  // The roll swings the head's crown toward the key's side; the head slides back by half that swing, so its
  // middle stays on the frame's centre line and a wide head (a mushroom's cap) is not cut by the frame's side.
  const { top, chin } = headOf(A);
  const rest = edge - 4 * s;
  const roll = dir * SLEEP_ROLL;
  const rad = roll * Math.PI / 180;
  const lift = (chin - top) * s;
  const slide = -Math.sin(rad) * lift / 2;
  const head = parts.join('');
  const headTf = `translate(${f(54 + slide)} ${f(rest)}) rotate(${roll}) scale(${s} ${s}) translate(-54 -${f(chin)})`;

  // The forearms, folded along the edge in front of the chin, a paw at each outer end.
  const col = A.arms ? A.arms.col : c.P[0];
  const reach = 22 * s, fy = edge - 5 * s;
  const arms = [-1, 1].map((side) => {
    const ax = 54 + side * reach;
    return `<ellipse cx="${f(ax)}" cy="${f(fy)}" rx="${f(19 * s)}" ry="${f(6.6 * s)}" fill="${pen.paint(col)}" stroke="${line(col)}" stroke-width="1.4"/>` +
      `<ellipse cx="${f(54 + side * (reach + 15 * s))}" cy="${f(fy - 0.6 * s)}" rx="${f(6 * s)}" ry="${f(5.4 * s)}" fill="${pen.paint(tint(col, 0.08))}" stroke="${line(col)}" stroke-width="1.2"/>`;
  }).join('');

  const markup = `<g data-part="head" transform="${headTf}">${head}</g><g data-part="arms">${arms}</g>`;
  const drawing = `<g opacity="${RIM_OPACITY}">${rimOf(markup)}</g>${markup}`;
  // Nothing is drawn below the edge: the desk stands there, and a frame drawn without one stays a sleeper.
  pen.defs.push(`<clipPath id="c_edge"><rect x="-200" y="-200" width="508" height="${f(edge + 200)}"/></clipPath>`);

  // The closed eyes' line, rolled with the head — stated on the document for the measuring tool's seat picks.
  const eyes = rest - Math.cos(rad) * (chin - F.y) * s - (Math.abs(Math.sin(rad)) * F.es + 5 * F.s) * s;
  // The three z's, fixed above the head on the side AWAY from its roll, lit at the document's levels.
  const zs = zStand(crown, dir, zLargeFor(crown, dir, opt.clear), opt.clear);
  const levels = frame === 0 ? zs.map(() => 3) : zLevels(frame - 1);
  const halos = zs.map((z) => haloSvg(z, levels[z.k])).join('');
  const bodies = zs.map((z) => zSvg(z, levels[z.k])).join('');
  const V = SEAT_VIEWBOX;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${V.x} ${V.y} ${V.w} ${V.h}" width="${V.w}" height="${V.h}" preserveAspectRatio="xMidYMax meet"><defs>${pen.defs.join('')}</defs><g data-pose="asleep" data-eyes-y="${f(eyes)}" data-edge="${f(edge)}" data-crown="${crown}" clip-path="url(#c_edge)">${drawing}</g><g data-part="zs"><g data-part="glow">${halos}</g>${bodies}</g></svg>`;
}

/**
 * The chibi geometry the generator draws a key's intern frame to — its head's top and chin, the squash
 * applied below the chin and the head's resulting share of the drawn height (FLOOR.md § 10.4).
 */
export function chibiGeometry(key, force) {
  return chibiOf(build(key, force).A);
}

/** What a key resolves to: the recipe plus the worn things, for the sheet and the selftest. */
export function describe(key, force) {
  const { r, sp, A } = build(key, force);
  return { ...r, label: sp.label, kind: sp.kind, ...worn(r, sp, A) };
}
