// The creature generator's 3-D half — first-party, card#13033 stage 2 slice 3 (docs/design/plan-13033-stage2-3d-floor.md
// § 3.3; docs/design/FLOOR.md § 10.2, § 10.4).
//
// A creature in 3-D is the SAME RECIPE as its 2-D frames, built as parts: `bodyParts()` reads the species' `shape` — the
// one data set `creatures.js`'s `build()` draws the 2-D frame from (M7) — and the key's recipe (colourway, girth, size,
// tilt, side, variant, hat, neckwear, extra), and answers a frozen PART LIST at the rest pose. No second recipe table:
// a species' form is stated once, in `creatures.js`, and this file only says how each kind of shape is turned into a
// solid. Pure, total, no I/O, no Three — the renderer (`server/public/js/floor3d/creature-paint.js`) builds each part
// with the library's geometry classes, and the selftest (`tools/characters/selftest.mjs` § 8c) measures the list.
//
// UNITS AND FRAME. Frame units, as the 2-D frame: a seat's body stands in `SEAT_RECT`'s 192 units, an intern's in the
// chibi frame's `CHIBI_VIEWBOX.h`; `x` across from the centre line (the viewer's right positive, the creature facing
// +z, toward the 2-D viewer), `y` UP from the foot line (FOOT − the frame's y), `z` toward the viewer. A part's `bone`
// is one of `BONES`, and the renderer binds every vertex of it to that bone at full weight — a toy's rigid joints.
//
// ⛔ This module draws nothing and makes nothing: what it answers is data, the same for the same key on every engine.

import {
  FOOT, SEAT_RECT, CHIBI_VIEWBOX, SPECIES, chibiGeometry, describe, faceBoxOf, laptopOf, leafCurves, recipe, shade, sideOf, smoothSegments, toneOf,
  INTERN_SWAY_DEG, LAPTOP_TOP, LAPTOP_BOTTOM, LAPTOP_W,
} from './creatures.js';

/**
 * THE BONES — one fixed hierarchy for every species, so one pose table animates all of them. `L` is the side at −x (the
 * viewer's left, as the creature faces the viewer), `R` at +x; `featureL` / `featureR` carry the paired parts on the
 * head (ears, tufts, antlers), `tail` the one-sided part behind.
 */
export const BONES = Object.freeze(['root', 'body', 'head', 'armL', 'armR', 'legL', 'legR', 'featureL', 'featureR', 'tail']);
/** Each bone's parent — `root` stands on the foot line; the legs hang from it, everything else from the body. */
export const BONE_PARENT = Object.freeze({ root: null, body: 'root', head: 'body', armL: 'body', armR: 'body', legL: 'root', legR: 'root', featureL: 'head', featureR: 'head', tail: 'body' });

/**
 * THE POSES — each a map bone → `{ rotate: [x, y, z] degrees, at: [x, y, z] frame units }` over the rest pose; a bone a
 * pose does not name stays at rest. The six of `desk/desk-poses.js`'s `DESK` that draw a body (`empty-chair` draws none),
 * the walk's three phases, and the held loops' frames (A3's typing bob, A4's lean, A23's sway).
 */
const SWAY = INTERN_SWAY_DEG;
export const POSES = Object.freeze({
  stand: Object.freeze({}),
  'step-left': Object.freeze({ legL: { at: [0, 5, 2] }, armL: { rotate: [-24, 0, 0] }, armR: { rotate: [24, 0, 0] }, body: { rotate: [0, 0, -2] } }),
  'step-right': Object.freeze({ legR: { at: [0, 5, 2] }, armR: { rotate: [-24, 0, 0] }, armL: { rotate: [24, 0, 0] }, body: { rotate: [0, 0, 2] } }),
  'at-keyboard': Object.freeze({ armL: { rotate: [-62, 0, 0] }, armR: { rotate: [-62, 0, 0] }, head: { rotate: [8, 0, 0] } }),
  asleep: Object.freeze({ body: { rotate: [34, 0, 0] }, head: { rotate: [22, 0, 14] }, armL: { rotate: [-84, 0, 0] }, armR: { rotate: [-84, 0, 0] } }),
  'raised-hand': Object.freeze({ armL: { rotate: [0, 0, -150] }, head: { rotate: [-6, 0, 4] } }),
  'head-in-hands': Object.freeze({ armL: { rotate: [-128, 0, 18] }, armR: { rotate: [-128, 0, -18] }, head: { rotate: [12, 0, 0] } }),
  present: Object.freeze({}),
  'type-a': Object.freeze({ armL: { rotate: [-56, 0, 0] }, armR: { rotate: [-68, 0, 0] }, head: { rotate: [8, 0, 0] } }),
  'type-b': Object.freeze({ armL: { rotate: [-68, 0, 0] }, armR: { rotate: [-56, 0, 0] }, head: { rotate: [8, 0, 0] } }),
  'lean-back': Object.freeze({ body: { rotate: [-9, 0, 0] }, head: { rotate: [-14, 0, 0] } }),
  'sway-l': Object.freeze({ root: { rotate: [0, 0, SWAY] } }),
  'sway-r': Object.freeze({ root: { rotate: [0, 0, -SWAY] } }),
});

/**
 * THE CLIPS — each § 6.2 form as the sequence of poses it steps through, one pose a loop frame (`LOOP_FPS`, the
 * animation set's): never a blend of two. The walk is the 2-D walk's stand, step-left, stand, step-right; the intern's
 * typing sway its four loop frames — centre, left, centre, right (`INTERN_LOOP`); a still pose is a one-entry clip.
 */
export const CLIPS = Object.freeze({
  walk: Object.freeze(['stand', 'step-left', 'stand', 'step-right']),
  work: Object.freeze(['type-a', 'type-b']),
  think: Object.freeze(['lean-back']),
  'intern-type': Object.freeze(['stand', 'sway-l', 'stand', 'sway-r']),
  ...Object.fromEntries(['stand', 'at-keyboard', 'asleep', 'raised-hand', 'head-in-hands', 'present'].map((p) => [p, Object.freeze([p])])),
});

/** How deep a body of revolution is, front to back, as a share of its width — a creature a little flatter than round. */
export const DEPTH = 0.86;
/** How thick an extruded body is over all, as a share of the lesser of its width and height (its bevel included). */
const SLAB = 0.62;
/** How far a face patch, a muzzle or a belly mark stands proud of the surface it lies on, in frame units. */
const PROUD = 2.4;

// ---- reading the shape data ----
const up = (y) => FOOT - y;
/** A path's commands sampled to points ([x, y] frame units, y up), each `dx` mapped by `x` — `creatures.js`'s `pathD()` read as geometry. */
function samplePath(cmds, x, steps = 10) {
  const out = [], P = ([dx, y]) => [x(dx), up(y)];
  let cur = null, start = null;
  for (const [op, ...pts] of cmds) {
    if (op === 'M') { cur = P(pts[0]); start = cur; out.push({ move: true, p: cur }); continue; }
    if (op === 'Z') { if (start) out.push({ p: start }); cur = start; continue; }
    const ctl = pts.map(P), from = cur;
    for (let i = 1; i <= steps; i++) {
      const t = i / steps, u = 1 - t;
      let q;
      if (op === 'L') { q = [from[0] + (ctl[0][0] - from[0]) * t, from[1] + (ctl[0][1] - from[1]) * t]; if (i < steps) continue; }
      else if (op === 'Q') q = [0, 1].map((k) => u * u * from[k] + 2 * u * t * ctl[0][k] + t * t * ctl[1][k]);
      else q = [0, 1].map((k) => u * u * u * from[k] + 3 * u * u * t * ctl[0][k] + 3 * u * t * t * ctl[1][k] + t * t * t * ctl[2][k]);
      out.push({ p: q });
    }
    cur = ctl[ctl.length - 1];
  }
  // one polyline per sub-path (a stroke may have several: the antler's beam and forks)
  const lines = [];
  for (const { move, p } of out) { if (move || lines.length === 0) lines.push([]); lines[lines.length - 1].push(p.map((v) => +v.toFixed(3))); }
  return lines.filter((l) => l.length > 1);
}

/** A cubic's point at `t`. */
const cubic = ([p0, p1, p2, p3], t) => { const u = 1 - t; return [0, 1].map((k) => +(u * u * u * p0[k] + 3 * u * u * t * p1[k] + 3 * u * t * t * p2[k] + t * t * t * p3[k]).toFixed(3)); };

/** `creatures.js`'s closed Catmull-Rom (`smoothSegments()`, what its `smooth()` draws) sampled to points. */
function smoothPoints(pts, steps = 6) {
  const out = [];
  let from = pts[0];
  for (const [c1, c2, p2] of smoothSegments(pts)) { for (let s = 0; s < steps; s++) out.push(cubic([from, c1, c2, p2], s / steps)); from = p2; }
  return out;
}

/** A leaf's outline (`creatures.js`'s `leafCurves()`, what its `leafD()` draws), its base at the origin pointing up (+y). */
function leafPoints(len, wid, steps = 8) {
  const out = [];
  for (const q of leafCurves(len, wid)) for (let i = 0; i < steps; i++) out.push(cubic(q, i / steps).map((v, k) => (k === 1 ? -v || 0 : v)));
  return out;
}

const round3 = (v) => +v.toFixed(3);
/**
 * An extrusion's depth and its rounded edge: the bevel `bevel` deep on each face and `bevelSize` across, started that far
 * INSIDE the outline, so the extruded part's silhouette from the front is its 2-D outline and no wider.
 */
const bevelled = (depth, bevel) => ({ depth, bevel, bevelSize: round3(bevel * 0.6) });
const freezeDeep = (o) => { if (o && typeof o === 'object' && !Object.isFrozen(o)) { Object.freeze(o); for (const v of Object.values(o)) freezeDeep(v); } return o; };

/**
 * A key's creature in 3-D: its part list at the rest pose, its joints and its face patch.
 * @param {string} key the seat key, or an intern key `seat~<call_id>`
 * @param {{species?: string, chibi?: boolean}} [opt] `species` the delivered body (`frameDocument()`'s); `chibi` the
 *        intern's re-proportioned body, its head at least `CHIBI_HEAD_SHARE` of its height
 * @returns {{species: string, chibi: boolean, tall: number, size: number, tilt: number, parts: object[],
 *            joints: Object<string, number[]>, face: object, top: number, chin: number}}
 *        `tall` the frame units its frame rect stands (the renderer scales that to the character's world height);
 *        `size` and `tilt` the root's uniform scale about the foot and its lean (the 2-D frame's own, an intern upright);
 *        `parts` each `{ name, shape, params, at, rotate, scale, colour, bone }`; `face` the face patch
 *        (`faceBox()`'s rectangle on the head's front, its `radius` the surface it curves round)
 */
export function bodyParts(key, { species, chibi = false } = {}) {
  const r = recipe(key, species);
  const sp = SPECIES[r.species], S = sp.shape, P = sp.pals[r.pal], st = r.stout, side = sideOf(key);
  const worn = describe(key, r.species);
  const parts = [];
  const colourOf = (tone) => toneOf(key, P, tone);
  // every part of the creature itself carries the body's paint (`paint`); its paws, its feet, what it wears and its laptop
  // are their own colour
  let painted = true;
  const add = (part) => parts.push({ rotate: [0, 0, 0], scale: [1, 1, 1], paint: painted, ...part });

  // ---- the solids: every profile a lathe (body, head) or a patch on the front (a disc, a cut face) ----
  const solids = [];
  for (const [name, profile] of Object.entries(S.profiles ?? {})) {
    const points = profile.map(([dx, y]) => [Math.max(0, dx) * st, up(y)]);
    if (name === 'body' || name === 'head') {
      solids.push({ kind: 'lathe', points });
      add({ name, shape: 'lathe', params: { points }, at: [0, 0, 0], scale: [1, 1, DEPTH], colour: colourOf(S.tones?.[name]), bone: name });
    }
  }
  /** A mark on the front — a belly, a muzzle, a disc — as a flattened ball whose rim sinks into the surface it lies on. */
  const decal = (name, x, y, rx, ry, colour, s, nose = false) => {
    const flat = Math.min(rx, ry) * (nose ? 0.8 : 0.55);
    add({ name, shape: 'sphere', params: { r: 1 }, at: [round3(x), round3(y), round3(surfaceZ(y) + (nose ? 1.4 : 0.8) * PROUD - flat)], scale: [round3(rx), round3(ry), round3(flat)], colour, bone: featureBone(y, s) });
  };
  /** How far in front of the centre plane the creature's surface is at height `y` (frame units, y up), over its solids. */
  const surfaceZ = (y) => {
    let z = 0;
    for (const s of solids) {
      if (s.kind === 'lathe') {
        for (let i = 1; i < s.points.length; i++) {
          const [r0, y0] = s.points[i - 1], [r1, y1] = s.points[i];
          if ((y - y0) * (y - y1) <= 0 && y0 !== y1) z = Math.max(z, (r0 + (r1 - r0) * (y - y0) / (y1 - y0)) * DEPTH);
        }
      } else if (s.kind === 'ellipsoid' && Math.abs(y - s.cy) < s.ry) z = Math.max(z, s.rz * Math.sqrt(1 - ((y - s.cy) / s.ry) ** 2));
      else if (s.kind === 'slab' && y >= s.y0 && y <= s.y1) z = Math.max(z, s.depth / 2);
    }
    return z;
  };

  const xOf = (f, s) => (dx) => (Array.isArray(dx) ? dx[0] + side * dx[1] : (f.fixed ? dx : s * dx * st));
  const sidesOf = (f) => (f.side === 'pair' ? [-1, 1] : f.side === 'key' ? [side] : f.side === 'away' ? [-side] : [1]);
  const FRONT = new Set(['belly', 'muzzle', 'nose', 'mask', 'patch', 'face', 'pea', 'pit', 'flesh']);
  const featureBone = (y, s) => (y >= headY ? (s < 0 ? 'featureL' : 'featureR') : 'body');
  const g = chibiGeometry(key, r.species);
  const headY = up(g.chin);
  for (const [name, profile] of Object.entries(S.profiles ?? {})) {
    if (name === 'body' || name === 'head') continue;
    const xs = profile.map(([dx]) => dx * st), ys = profile.map(([, y]) => up(y));
    decal(name, 0, (Math.min(...ys) + Math.max(...ys)) / 2, Math.max(...xs), (Math.max(...ys) - Math.min(...ys)) / 2, colourOf(S.tones?.[name]), 1);
  }

  // body solids that are features: lumps, scallop bodies, stars, outlines, extruded bodies, lobes
  for (const f of S.features) {
    if (f.name === 'body' || (f.name === 'head' && f.kind === 'scallop') || f.kind === 'lobes') {
      if (f.kind === 'lumps' || (f.kind === 'scallop' && S.solid !== 'extrude')) {
        const cy = up(f.at[1]), rx = f.r[0] * st, ry = f.r[1], rz = rx * DEPTH;
        solids.push({ kind: 'ellipsoid', cy, ry, rz });
        add({ name: f.name, shape: 'sphere', params: { r: 1 }, at: [0, cy, 0], scale: [rx, ry, rz], colour: colourOf(f.tone), bone: f.name === 'head' ? 'head' : 'body' });
        if (f.kind === 'scallop') addBumps(f, cy, rx, ry, rz, colourOf(f.tone), f.name === 'head' ? 'head' : 'body');
      } else if (f.kind === 'lobes') {
        const R = Math.max(...f.lobes.map(([dx]) => Math.abs(dx))) * st;
        for (const [dx, y, rx, ry, dark] of f.lobes) {
          const a = Math.asin(Math.max(-1, Math.min(1, (dx * st) / (R + 1))));
          for (const back of [false, true]) {
            const ang = back ? Math.PI - a : a;
            add({ name: 'lobe', shape: 'sphere', params: { r: 1 }, at: [round3(Math.sin(ang) * R * 0.92), up(y), round3(Math.cos(ang) * R * 0.92)], scale: [rx * st, ry, rx * st], colour: shade(P[0], dark), bone: 'body' });
          }
        }
        solids.push({ kind: 'ellipsoid', cy: up(f.lobes[0][1]), ry: Math.max(...f.lobes.map((l) => l[3])), rz: R + 18 });
      } else {
        // an extruded body: its outline, as thick over all as `SLAB` of the lesser of its width and height, half of that
        // its rounded bevel, so a slice or a cut half reads as a soft slab and not a block
        const outline = outlineOf(f);
        const xs = outline.map((p) => p[0]), ys = outline.map((p) => p[1]);
        const thick = Math.min(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)) * SLAB;
        const depth = round3(thick / 2), bevel = round3(thick / 4);
        solids.push({ kind: 'slab', y0: Math.min(...ys), y1: Math.max(...ys), depth: thick });
        add({ name: f.name, shape: 'extrude', params: { points: outline, ...bevelled(depth, bevel) }, at: [0, 0, round3(-depth / 2)], colour: colourOf(f.tone), bone: 'body' });
      }
    }
  }

  function outlineOf(f) {
    if (f.kind === 'outline') return smoothPoints(f.pts.map(([dx, y]) => [(f.side === 'key' ? side : 1) * dx * st, up(y)]));
    if (f.kind === 'star') {
      const pts = [];
      for (let i = 0; i < f.n * 2; i++) { const a = -Math.PI / 2 + i * Math.PI / f.n, rr = i % 2 ? f.r[1] : f.r[0]; pts.push([Math.cos(a) * rr * st, up(f.at[1] + Math.sin(a) * rr)]); }
      return smoothPoints(pts);
    }
    if (f.kind === 'scallop') {
      const pts = [];
      for (let i = 0; i < f.n * 3; i++) { const a = -Math.PI / 2 + i * 2 * Math.PI / (f.n * 3), bump = 1 + (i % 3 === 1 ? f.bulge / f.r[1] : 0); pts.push([Math.cos(a) * f.r[0] * st * bump, up(f.at[1] + Math.sin(a) * f.r[1] * bump)].map(round3)); }
      return pts;
    }
    return samplePath(f.d, xOf(f, 1))[0];
  }

  function addBumps(f, cy, rx, ry, rz, colour, bone) {
    // a scalloped outline's bumps, as small balls round its rim and over its front, so the fleece or the mane reads
    // round from every side
    const n = f.n, br = Math.max(2.5, f.bulge * 1.6);
    for (let i = 0; i < n; i++) {
      const a = (i / n) * 2 * Math.PI;
      add({ name: `${f.name}-bump`, shape: 'sphere', params: { r: 1 }, at: [round3(Math.cos(a) * rx), round3(cy + Math.sin(a) * ry), 0], scale: [br, br, br], colour, bone });
      add({ name: `${f.name}-bump`, shape: 'sphere', params: { r: 1 }, at: [round3(Math.cos(a) * rx * 0.72), round3(cy + Math.sin(a) * ry * 0.72), round3(rz * 0.7 * (i % 2 ? 1 : -1))], scale: [br, br, br], colour, bone });
    }
  }

  // ---- the features ----
  for (const f of S.features) {
    if (f.variant !== undefined && f.variant !== r.variant) continue;
    if (f.name === 'body' || (f.name === 'head' && f.kind === 'scallop') || f.kind === 'lobes') continue;
    // face paint is drawn into the face texture (`faceDocument()`), not built
    if (f.face) continue;
    const colour = colourOf(f.tone);
    for (const s of sidesOf(f)) {
      const X = xOf(f, s);
      if (f.kind === 'ell' || f.kind === 'rect') {
        const [ax, ay] = f.kind === 'rect' ? [f.at[0] + f.size[0] / 2, f.at[1] + f.size[1] / 2] : f.at;
        const [rx0, ry] = f.kind === 'rect' ? [f.size[0] / 2, f.size[1] / 2] : f.r;
        const rx = rx0 * (f.fixed ? 1 : st);
        // a part turned about a pivot (the rabbit's ear about its root) stands where the turn carries its centre
        let [x, y] = [X(ax), up(ay)];
        if (f.pivot) {
          const [px, py] = [X(f.pivot[0]), up(f.pivot[1])], a = (-s * f.tilt * Math.PI) / 180;
          [x, y] = [px + (x - px) * Math.cos(a) - (y - py) * Math.sin(a), py + (x - px) * Math.sin(a) + (y - py) * Math.cos(a)];
        }
        if (FRONT.has(f.name) || f.kind === 'rect') { decal(f.name, x, y, rx, ry, colour, s, f.name === 'nose'); continue; }
        const shell = f.name === 'shell';
        const z = shell ? -surfaceZ(y) * 0.55 : f.name === 'claw' || f.name === 'haunch' ? surfaceZ(y) * 0.45 : 0;
        add({ name: f.name, shape: 'sphere', params: { r: 1 }, at: [round3(x), round3(y), round3(z)], rotate: [0, 0, -s * (f.tilt ?? 0)], scale: [round3(rx), ry, round3(shell ? rx * 0.7 : Math.min(rx, ry) * 0.75)], colour, bone: shell ? 'body' : featureBone(y, s) });
      } else if (f.kind === 'path') {
        const outline = samplePath(f.d, X)[0];
        const ys = outline.map((p) => p[1]), cy = (Math.min(...ys) + Math.max(...ys)) / 2;
        if (f.side === 'centre' && (f.name === 'cap' || f.name === 'shell')) {
          // a symmetric cap or dome turned about the centre line: its right half's outline from the crown round to the rim
          const half = outline.filter((p) => p[0] >= -0.01).map(([px, py]) => [Math.max(0, px), py]).sort((a, b) => b[1] - a[1]);
          add({ name: f.name, shape: 'lathe', params: { points: [[0, half[0][1]], ...half.slice(1)].map((p) => p.map(round3)) }, at: [0, 0, f.name === 'shell' ? round3(-surfaceZ(cy) * 0.5) : 0], scale: [1, 1, f.name === 'shell' ? 0.62 : DEPTH], colour, bone: f.name === 'cap' ? 'head' : 'body' });
        } else {
          const depth = f.name === 'beak' || f.name === 'trunk' || f.name === 'nose' ? 7 : f.name === 'tail' ? 9 : f.name === 'husk' ? 5 : 5;
          const z = ['beak', 'trunk', 'nose'].includes(f.name) ? surfaceZ(cy) + depth * 0.2 : f.name === 'tail' ? -surfaceZ(cy) * 0.6 : f.name === 'husk' || f.name === 'wing' ? surfaceZ(cy) * 0.35 : 0;
          add({ name: f.name, shape: 'extrude', params: { points: outline, ...bevelled(depth, round3(depth * 0.35)) }, at: [0, 0, round3(z - depth / 2)], colour, bone: f.name === 'tail' ? 'tail' : ['beak', 'trunk', 'nose'].includes(f.name) ? (cy >= headY ? 'head' : 'body') : featureBone(cy, s) });
        }
      } else if (f.kind === 'stroke') {
        for (const line of samplePath(f.d, X)) {
          const cy = line.reduce((a, p) => a + p[1], 0) / line.length;
          const z = f.name === 'tail' ? -surfaceZ(cy) * 0.6 : f.name === 'arm' ? surfaceZ(cy) * 0.75 : f.name === 'root' ? 4 : 0;
          add({ name: f.name, shape: 'tube', params: { points: line.map(([px, py]) => [px, py, round3(z)]), radius: round3(Math.max(f.width / 2, 1.6)) }, at: [0, 0, 0], colour, bone: f.name === 'tail' ? 'tail' : featureBone(cy, s) });
        }
      } else if (f.kind === 'leaf' || f.kind === 'frond') {
        const base = [round3(X(f.at[0])), up(f.at[1])];
        f.fan.forEach(([a, len, wid = 9], i) => {
          // a fan's leaves spread round the stem as well as across it, so a crown reads from above and the side
          const yaw = f.fan.length >= 3 ? ((i % 3) - 1) * 38 : 0;
          add({ name: f.name, shape: 'extrude', params: { points: leafPoints(len, f.kind === 'frond' ? 7 : wid), ...bevelled(1.6, 0.6) }, at: [base[0], base[1], 0], rotate: [0, yaw, -(s * a)], colour, bone: featureBone(base[1], s) });
        });
      } else if (f.kind === 'scallop') {
        const y = up(f.at[1]), x = X(f.at[0]), rx = f.r[0] * st, ry = f.r[1];
        const z = f.name === 'mane' ? -surfaceZ(y) * 0.25 : f.name === 'forelock' ? surfaceZ(y) * 0.7 : 0;
        const rz = f.name === 'mane' ? rx * 0.6 : f.name === 'ear' || f.name === 'leaf' ? Math.min(rx, ry) * 0.35 : Math.min(rx, ry) * 0.6;
        add({ name: f.name, shape: 'sphere', params: { r: 1 }, at: [round3(x), y, round3(z)], rotate: [0, 0, -s * (f.tilt ?? 0)], scale: [round3(rx), ry, round3(rz)], colour, bone: featureBone(y, s) });
        if (f.name === 'mane' || f.name === 'crown') addBumps(f, y, rx, ry, rz, colour, featureBone(y, s));
      } else if (f.kind === 'spines') {
        // the spines: rows of cones out of the back and the crown, from `r` out to `len` (`crest` over the crown)
        const cy = up(f.at[1]);
        for (let i = 0; i < f.n; i++) {
          const a = (f.arc[0] + (f.arc[1] - f.arc[0]) * (i + 0.5) / f.n) * Math.PI / 180;
          const lift = Math.max(0, Math.cos(a + Math.PI / 2) * 1.4 - 0.4), len = f.len + (f.crest - f.len) * lift;
          for (const phi of [-0.95, -0.45, 0.05]) {
            const dir = [Math.cos(a) * Math.cos(phi) * st, -Math.sin(a) * Math.cos(phi), Math.sin(phi) * DEPTH];
            const n = Math.hypot(...dir), d = dir.map((v) => v / n);
            add({ name: 'spine', shape: 'cone', params: { radius: 6.5, height: round3(len - f.r + 6), dir: d.map(round3) }, at: d.map((v, k) => round3([0, cy, 0][k] + v * f.r * 0.92)), colour, bone: 'body' });
          }
        }
      }
    }
  }

  // ---- arms and feet, from the anchors ----
  painted = false;
  const A = { arms: S.arms, feet: S.feet };
  const armCol = A.arms && colourOf(A.arms.tone), footCol = A.feet && colourOf(A.feet.tone);
  const joints = { root: [0, 0, 0], body: [0, 10, 0], head: [0, round3(headY), 0], tail: [round3(side * 14 * st), 30, round3(-surfaceZ(30) * 0.6)] };
  if (A.arms) {
    const y = up(A.arms.y), small = A.arms.small, rx = small ? 4.6 : 6.2, ry = small ? 6.6 : 9;
    for (const s of [-1, 1]) {
      const x = s * (A.arms.fixed ? A.arms.dx : A.arms.dx * st), bone = s < 0 ? 'armL' : 'armR';
      joints[bone] = [round3(x), round3(y + ry * 0.7), round3(surfaceZ(y) * 0.5)];
      add({ name: 'arm', shape: 'sphere', params: { r: 1 }, at: [round3(x), y, round3(surfaceZ(y) * 0.5)], rotate: [0, 0, A.arms.clasp ? 0 : -s * -22], scale: [rx, ry, rx], colour: armCol, bone });
    }
  }
  if (A.feet) {
    for (const s of [-1, 1]) {
      const x = s * A.feet.dx * st, bone = s < 0 ? 'legL' : 'legR', rx = A.feet.rx ?? 8.5;
      joints[bone] = [round3(x), 8, 0];
      if (A.feet.talon) for (const k of [-3, 0, 3]) add({ name: 'foot', shape: 'sphere', params: { r: 1 }, at: [round3(x + k), 3.2, 4], scale: [1.9, 3.2, 3], colour: footCol, bone });
      else add({ name: 'foot', shape: 'sphere', params: { r: 1 }, at: [round3(x), 4, 4], scale: [rx, 4.6, rx * 1.15], colour: footCol, bone });
    }
  }
  for (const [bone, ft] of [['featureL', -1], ['featureR', 1]]) joints[bone] = [round3(ft * 12 * st), round3(up(S.hat.y)), 0];
  for (const b of BONES) joints[b] ??= joints.body;

  // ---- what it wears ----
  const F = S.face;
  const face = faceBoxOf(key, r.species);
  const faceY = up(face.y + face.h / 2);
  let faceZ = 8;
  for (let i = 0; i <= 8; i++) faceZ = Math.max(faceZ, surfaceZ(up(face.y + (face.h * i) / 8)));
  addWorn(worn, faceZ);

  function addWorn({ hat, neck, extra }, fz) {
    const acc1 = r.acc1, acc2 = r.acc2;
    const hx = S.hat.x !== undefined ? S.hat.x - 54 : (S.hat.sided ? side : 1) * S.hat.dx * st, hy = up(S.hat.y), w = S.hat.w;
    const hz = surfaceZ(hy - 4) * 0.2;
    if (hat === 'beret') add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(hx + w * 0.12), round3(hy + 1), round3(hz)], rotate: [0, 0, 9], scale: [round3(w * 0.98), round3(w * 0.36), round3(w * 0.9)], colour: acc1, bone: 'head' });
    if (hat === 'beanie') {
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(hx), round3(hy - w * 0.5), round3(hz)], scale: [round3(w * 1.04), round3(w * 0.92), round3(w * 1.0)], colour: acc1, bone: 'head' });
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(hx), round3(hy + w * 0.42), round3(hz)], scale: [5.5, 5.5, 5.5], colour: acc2, bone: 'head' });
    }
    if (hat === 'straw') {
      add({ name: 'hat', shape: 'cylinder', params: { radius: round3(w * 1.32), height: 2.4 }, at: [round3(hx), round3(hy - w * 0.38), round3(hz)], colour: '#e8c57e', bone: 'head' });
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(hx), round3(hy - w * 0.38), round3(hz)], scale: [round3(w * 0.64), round3(w * 0.6), round3(w * 0.64)], colour: '#e8c57e', bone: 'head' });
      add({ name: 'hat', shape: 'cylinder', params: { radius: round3(w * 0.65), height: 3.2 }, at: [round3(hx), round3(hy - w * 0.3), round3(hz)], colour: acc1, bone: 'head' });
    }
    if (hat === 'flower') {
      const cx = hx + w * 0.78, cy = hy - w * 0.22, col = [acc1, '#fbf3ea', '#f2a7b6', '#a9c4e8'][Math.floor(acc1.charCodeAt(2)) % 4];
      for (let i = 0; i < 5; i++) { const a = (i * 72 + 10) * Math.PI / 180; add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(cx + Math.sin(a) * 4.2), round3(cy + Math.cos(a) * 4.2), round3(hz + 2)], scale: [3.1, 4.4, 1.6], rotate: [0, 0, -(i * 72 + 10)], colour: col, bone: 'head' }); }
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(cx), round3(cy), round3(hz + 3)], scale: [2.6, 2.6, 2], colour: '#f2c44a', bone: 'head' });
    }
    if (hat === 'ladybug') {
      const cx = hx - w * 0.32, cy = hy - 1.4;
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(cx), round3(cy), round3(hz)], scale: [5.8, 4.8, 5.4], colour: '#d8493a', bone: 'head' });
      add({ name: 'hat', shape: 'sphere', params: { r: 1 }, at: [round3(cx - 5.4), round3(cy - 0.6), round3(hz + 1)], scale: [2.6, 2.3, 2.4], colour: '#2e2428', bone: 'head' });
    }
    if (neck !== 'none' && S.neck) {
      const ny = up(S.neck.y), nw = S.neck.fixed ? S.neck.w : S.neck.w * st, nz = surfaceZ(ny);
      if (neck === 'scarf') {
        add({ name: 'neck', shape: 'torus', params: { radius: round3(Math.max(nw, nz) * 0.98), tube: 4.6 }, at: [0, round3(ny - 1), 0], rotate: [90, 0, 0], scale: [1, round3(Math.max(0.5, nz / Math.max(nw, 1))), 1], colour: acc1, bone: 'body' });
        add({ name: 'neck', shape: 'box', params: { size: [10, 22, 3] }, at: [round3(side * nw * 0.45), round3(ny - 15), round3(nz + 2)], colour: acc1, bone: 'body' });
      }
      if (neck === 'neckerchief') add({ name: 'neck', shape: 'cone', params: { radius: round3(nw * 0.7), height: 18, dir: [0, -1, 0.35] }, at: [0, round3(ny - 8), round3(nz * 0.92)], scale: [1, 1, 0.3], colour: acc1, bone: 'body' });
      if (neck === 'bow') for (const s of [-1, 1]) add({ name: 'neck', shape: 'sphere', params: { r: 1 }, at: [round3(s * 6), round3(ny - 3), round3(nz + 2)], scale: [6, 4.6, 2.6], rotate: [0, 0, s * 12], colour: acc1, bone: 'body' });
    }
    if (extra === 'glasses') {
      const gr = 6.8 * F.s * 1.5 * 0.84;
      for (const s of [-1, 1]) add({ name: 'glasses', shape: 'torus', params: { radius: round3(gr), tube: 0.9 }, at: [round3(s * F.es), up(F.y), round3(fz + PROUD + 1.2)], colour: '#5a3a2a', bone: 'head' });
    }
    if ((extra === 'mug' || extra === 'book') && S.arms) {
      const y = up(S.arms.y - 4), z = surfaceZ(y) + 6;
      if (extra === 'mug') add({ name: 'held', shape: 'cylinder', params: { radius: 8, height: 16 }, at: [0, round3(y), round3(z)], colour: '#f3ead8', bone: 'body' });
      else add({ name: 'held', shape: 'box', params: { size: [22, 16, 5] }, at: [0, round3(y), round3(z)], colour: acc2, bone: 'body' });
    }
    if (extra === 'satchel' && S.neck) {
      const y = up(S.neck.y + 34), x = -side * S.neck.w * st * 0.72;
      add({ name: 'satchel', shape: 'box', params: { size: [22, 17, 7] }, at: [round3(x), round3(y), round3(surfaceZ(y) * 0.7)], colour: '#9a6a42', bone: 'body' });
    }
  }

  // ---- the intern: re-proportioned, not shrunk — the body below the chin squashed toward the foot by `k`, the head
  // coming down to sit on it (`chibiGeometry()`'s figures, the 2-D chibi frame's own) ----
  // the frame-unit rectangle the painted parts cover at rest, a margin round it: what `paintDocument()` draws in and the
  // renderer projects from
  const paintBox = (() => {
    let x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
    const take = (x, y, r = 0) => { x0 = Math.min(x0, x - r); x1 = Math.max(x1, x + r); y0 = Math.min(y0, y - r); y1 = Math.max(y1, y + r); };
    for (const p of parts.filter((q) => q.paint)) {
      if (p.params.points) {
        const a = (p.rotate[2] * Math.PI) / 180, reach = p.params.radius ?? 0;
        for (const [x, y] of p.params.points) take(p.at[0] + x * Math.cos(a) - y * Math.sin(a), p.at[1] + x * Math.sin(a) + y * Math.cos(a), reach);
      } else if (p.shape === 'cone') { take(p.at[0], p.at[1], p.params.radius); take(p.at[0] + p.params.dir[0] * p.params.height, p.at[1] + p.params.dir[1] * p.params.height); }
      else take(p.at[0], p.at[1], Math.max(...p.scale));
    }
    const m = 4;
    return { x: round3(54 + x0 - m), y: round3(FOOT - y1 - m), w: round3(x1 - x0 + 2 * m), h: round3(y1 - y0 + 2 * m) };
  })();
  let top = up(g.top), chin = headY;
  if (chibi) {
    // one continuous map of heights: below the chin squashed by k toward the foot, above it moved down by the squash —
    // the two meet at the chin, so the outline is continuous there, as the 2-D chibi frame's two bands are
    const shift = (FOOT - g.chin) * (1 - g.k);
    const m = (y) => round3(y < headY ? y * g.k : y - shift);
    for (const p of parts) {
      if (p.params.points) p.params = { ...p.params, points: p.params.points.map(([a, b, ...rest]) => [a, m(b), ...rest]) };
      else {
        // a ball's foot and crown each mapped, so one that spans the chin is squashed below it and moved above it
        const lo = m(p.at[1] - p.scale[1]), hi = m(p.at[1] + p.scale[1]);
        p.at = [p.at[0], round3((lo + hi) / 2), p.at[2]];
        p.scale = [p.scale[0], round3((hi - lo) / 2), p.scale[2]];
      }
    }
    for (const b of Object.keys(joints)) joints[b] = [joints[b][0], m(joints[b][1]), joints[b][2]];
    top = m(top); chin = m(headY);
    // the laptop on the lap (card#12448): the standard lid, the intern's own shell, the lid's back toward the viewer and
    // its screen toward the intern (the operator's Q4 answer, 2026-11-11) — the second material, drawn by the renderer
    const lap = laptopOf(key);
    const lidH = LAPTOP_BOTTOM - LAPTOP_TOP, lz = Math.max(surfaceZ(up(LAPTOP_TOP)), 14) + 10;
    add({ name: 'laptop-lid', shape: 'box', params: { size: [LAPTOP_W, lidH, 1.8] }, at: [0, round3(up(LAPTOP_TOP + lidH / 2)), round3(lz)], rotate: [-12, 0, 0], colour: lap.shell, bone: 'root', laptop: true });
    add({ name: 'laptop-deck', shape: 'box', params: { size: [LAPTOP_W, 1.6, 34] }, at: [0, round3(up(LAPTOP_BOTTOM)), round3(lz - 17)], colour: lap.shell, bone: 'root', laptop: true });
  }

  return freezeDeep({
    species: r.species, chibi, tall: chibi ? CHIBI_VIEWBOX.h : SEAT_RECT.h, size: r.size, tilt: chibi ? 0 : r.tilt,
    top: round3(top), chin: round3(chin), parts, joints,
    // the paint's projection: its rectangle, and — for an intern — the squash to undo, so a vertex samples the paint where
    // its rest point stood (a height under `chin` divided by `k`, one over it raised by `shift`)
    paint: chibi ? { box: paintBox, k: g.k, chin: round3(headY * g.k), shift: round3((FOOT - g.chin) * (1 - g.k)) } : { box: paintBox, k: 1, chin: 0, shift: 0 },
    face: { box: face, at: [0, round3(chibi ? (faceY < headY ? faceY * g.k : faceY - (FOOT - g.chin) * (1 - g.k)) : faceY), 0], radius: round3(faceZ + PROUD), flat: S.solid === 'extrude', bone: faceY >= headY ? 'head' : 'body' },
  });
}

/** The intern's body: `bodyParts()` with the chibi proportions — the seat's delivered species is the one it never wears. */
export function chibiParts(key, { species } = {}) {
  return bodyParts(key, { species, chibi: true });
}
