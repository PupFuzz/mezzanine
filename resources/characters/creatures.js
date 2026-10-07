// The creature generator — first-party, card#11046 (docs/design/FLOOR.md § 10.2, § 10.4).
//
// Every character is an original animal or vegetable creature, drawn as a STANDALONE SVG DOCUMENT per
// frame and selected from its key alone through seed.js's draw — the same key, the same document, on
// every engine and every reload, with nothing stored. Ported from the operator-approved design sheet
// (2026-10-05) and not redesigned; the seat's two additions are the faint light rim (`RIM`) and the
// hedgehog's softer, curved spines.
//
// ⛔ This module builds DOCUMENTS, never URIs: the painter makes the `data:` URI, so no text in the asset
// tree carries one (FLOOR.md § 10.1 clause 2). It makes no request of any kind, holds no cache and
// reads no clock or random source.
//
// Drawing space: viewBox 0 0 108 192 for a seat (2 units per floor pixel in the 54 x 96 character rect);
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

/** The species list, in its one fixed order: the salt was searched over THIS order. */
export const SPECIES_KEYS = Object.freeze(Object.keys(SPECIES));

// ---- the shared dimensions (closed populations) ----
export const EYES = ['round', 'sparkle', 'happy', 'sleepy', 'bead'];
export const MOUTHS = ['smile', 'open', 'cat', 'tiny', 'grin'];
export const BLUSH = [0, 0.5, 0.8];
export const BROWS = ['none', 'none', 'soft', 'worried'];
export const SIZES = [0.84, 0.88, 0.92, 0.96, 1.0];
export const STOUT = [0.93, 1, 1.07];
export const TILTS = [-3, 0, 3];
export const ACCENTS = ['#c8553d', '#e0a43a', '#7f9f6a', '#6f8fb0', '#94627e', '#f2e6cf', '#4f8a87', '#d97f8f'];

/** The draw field the species is selected under — the salt (FLOOR.md § 10.4): picked once, fixed forever. */
export const SPECIES_FIELD = 'species:s1';

/**
 * The species a key draws. An intern's key (`seat~<call_id>`, § 10.4) never draws its seat's: where its
 * own draw lands there, it is re-drawn under a field of its own over the other species — a pure function
 * of the intern's key, because that key carries its seat's (decision 54).
 * @param {string} key @returns {string}
 */
export function speciesOf(key) {
  const own = pick(key, SPECIES_FIELD, SPECIES_KEYS);
  const cut = key.indexOf('~');

  if (cut < 0) {
    return own;
  }

  const seat = speciesOf(key.slice(0, cut));

  if (own !== seat) {
    return own;
  }

  return pick(key, 'species-redraw', SPECIES_KEYS.filter((s) => s !== seat));
}

/** A key's recipe: one independent draw per named field (seed.js's `draw`). */
export function recipe(key, force) {
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
/** A frame's drawing space: a seat's 108 x 192, an intern's 108 x 172.8 (its 20 x 32 rect's aspect). */
export const SEAT_VIEWBOX = Object.freeze({ x: 0, y: 0, w: 108, h: 192 });
export const CHIBI_VIEWBOX = Object.freeze({ x: 0, y: 19.2, w: 108, h: 172.8 });
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
 * One frame of one key, as a standalone SVG document.
 * @param {string} key the seat key, or an intern key `seat~<call_id>`
 * @param {{ phase?: 0|1|2, chibi?: boolean, species?: string }} [opt] phase 0 is the standing frame
 * @returns {string}
 */
export function frameDocument(key, opt = {}) {
  const phase = opt.phase ?? 0;
  if (![0, 1, 2].includes(phase)) throw new RangeError(`phase must be 0, 1 or 2, got ${phase}`);
  const { r, sp, pen, c, A } = build(key, opt.species);
  const hats = A.hats || sp.hats;
  const hat = ['none', 'none', 'none', ...hats][r.hatDraw % (hats.length + 3)];
  const necks = A.neck ? sp.necks : [];
  const neck = ['none', 'none', ...necks][r.neckDraw % (necks.length + 2)];
  let extra = r.extra;
  if (extra === 'satchel' && !A.neck) extra = 'none';
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
  const shadow = `<ellipse cx="54" cy="187.5" rx="${f(A.shadow * s)}" ry="4.6" fill="${pen.soft('#1c1424', 0.42)}"/>`;
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
  const hats = A.hats || sp.hats;
  const necks = A.neck ? sp.necks : [];
  let extra = r.extra;
  if (extra === 'satchel' && !A.neck) extra = 'none';
  return {
    ...r, label: sp.label, kind: sp.kind,
    hat: ['none', 'none', 'none', ...hats][r.hatDraw % (hats.length + 3)],
    neck: ['none', 'none', ...necks][r.neckDraw % (necks.length + 2)],
    extra,
  };
}
