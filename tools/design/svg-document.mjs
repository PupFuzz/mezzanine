// THE ONE READER OF A STANDALONE SVG DOCUMENT, for every generator that hands the painter one — the creature
// tree (`resources/characters/`, AT-D3-24's `tools/characters/selftest.mjs`) and each floor theme
// (`resources/floor/themes/`, AT-D3-25's `tools/floor-themes/selftest.mjs`). Node, no dependencies.
//
// `wellFormed(doc)` is a strict XML well-formedness reader — what the browser's SVG decoder needs, refused
// by name: every tag closed and nested, every `&` an entity, an `<svg>` root carrying
// `xmlns="http://www.w3.org/2000/svg"`. It answers `null` for a document that passes, else the defect.
// `vectorDefect(doc)` answers what keeps a document from being self-contained vector — an `<image>`, a
// `data:` URI, an `href` or a `url()` that leaves the document — or `null`.
// `inkBounds(doc)` is the box every drawn mark lands in, read from the geometry (its own comment, below).

const ENTITY = /^&(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);/;
export function wellFormed(doc) {
  const stack = [];
  let i = 0, root = null;
  while (i < doc.length) {
    const lt = doc.indexOf('<', i);
    const text = doc.slice(i, lt < 0 ? doc.length : lt);
    for (let j = text.indexOf('&'); j >= 0; j = text.indexOf('&', j + 1)) {
      if (!ENTITY.test(text.slice(j))) return `an unescaped "&" in text near ${JSON.stringify(text.slice(j, j + 16))}`;
    }
    if (lt < 0) break;
    const gt = doc.indexOf('>', lt);
    if (gt < 0) return 'an unterminated tag';
    const tag = doc.slice(lt + 1, gt);
    if (tag.startsWith('/')) {
      const name = tag.slice(1).trim();
      const open = stack.pop();
      if (open !== name) return open === undefined ? `a closing </${name}> that closes nothing open` : `an unclosed <${open}> before </${name}>`;
    } else {
      const self = tag.endsWith('/');
      const m = tag.replace(/\/$/, '').match(/^([A-Za-z][\w:-]*)((?:\s+[\w:-]+="[^"<]*")*)\s*$/);
      if (!m) return `a malformed tag <${tag.slice(0, 40)}>`;
      for (const [, v] of m[2].matchAll(/="([^"]*)"/g)) {
        for (let j = v.indexOf('&'); j >= 0; j = v.indexOf('&', j + 1)) {
          if (!ENTITY.test(v.slice(j))) return `an unescaped "&" in an attribute of <${m[1]}>`;
        }
      }
      if (root === null) root = { name: m[1], attrs: m[2] };
      if (!self) stack.push(m[1]);
    }
    i = gt + 1;
  }
  if (stack.length) return `unclosed <${stack.join('>, <')}>`;
  if (root === null || root.name !== 'svg') return 'the root is not <svg>';
  if (!/\sxmlns="http:\/\/www\.w3\.org\/2000\/svg"/.test(root.attrs)) return 'the root <svg> carries no xmlns="http://www.w3.org/2000/svg"';
  return null;
}
export const vectorDefect = (doc) => (/<image\b/.test(doc) ? 'an <image>' : /data:/.test(doc) ? 'a data: URI'
  : /href="(?!#)/.test(doc) ? 'an href that leaves the document' : /url\((?!#)/.test(doc) ? 'a url() that leaves the document' : null);

// `inkBounds(doc)` is the box every mark of a document lands in, in the document's user units — the geometry of
// each drawn shape under every transform above it, widened by half its stroke, and cut to the box of any clip
// path it names — or `null` when nothing is drawn. Curves and arcs are SAMPLED along their length (never bounded
// by their control points, which lie outside the ink), so the answer is tight to a sample step and never names a
// control point as ink. `<defs>` content is read only where a `<use>` or a `clip-path` reaches it. A path command
// this reader does not implement is REFUSED by name rather than skipped, so a document can never under-measure
// silently. `each(node, box, ancestors, span)`, when given, is called with every drawn shape, its own box and the
// elements above it (a `<use>` included, then the subtree it reaches), so a caller can name the part that reached
// somewhere; `span(y)` is that shape's ink where the document's row `y` crosses its outline, widened by its stroke and
// held to its clip — `[left, right]`, or null where the row misses it. It exists for AT-D3-24's frame-bounds leg
// (card#12212): a character's ink stays inside its frame; the ancestors for its laptop leg (card#12448), which reads a
// `data-part` group's ink; the span for the laptop's width (card#12502), which needs how wide a body is at a height,
// where a shape's box says only the widest it is anywhere.
const SAMPLES = 24;
const mul = (A, B) => [A[0] * B[0] + A[2] * B[1], A[1] * B[0] + A[3] * B[1], A[0] * B[2] + A[2] * B[3], A[1] * B[2] + A[3] * B[3], A[0] * B[4] + A[2] * B[5] + A[4], A[1] * B[4] + A[3] * B[5] + A[5]];
const apply = (M, [x, y]) => [M[0] * x + M[2] * y + M[4], M[1] * x + M[3] * y + M[5]];
const nums = (s) => (s.match(/-?(?:\d+\.?\d*|\.\d+)(?:e-?\d+)?/gi) ?? []).map(Number);
function transformOf(s) {
  let M = [1, 0, 0, 1, 0, 0];
  for (const [, fn, args] of (s ?? '').matchAll(/(\w+)\(([^)]*)\)/g)) {
    const a = nums(args);
    let T;
    if (fn === 'translate') T = [1, 0, 0, 1, a[0], a[1] ?? 0];
    else if (fn === 'scale') T = [a[0], 0, 0, a[1] ?? a[0], 0, 0];
    else if (fn === 'rotate') {
      const r = a[0] * Math.PI / 180, c = Math.cos(r), s_ = Math.sin(r);
      T = [c, s_, -s_, c, 0, 0];
      if (a.length > 1) T = mul(mul([1, 0, 0, 1, a[1], a[2]], T), [1, 0, 0, 1, -a[1], -a[2]]);
    } else if (fn === 'matrix') T = a;
    else throw new Error(`inkBounds: transform ${fn}() is not implemented`);
    M = mul(M, T);
  }
  return M;
}
function parseTree(doc) {
  const root = { name: '#', attrs: {}, children: [] }, stack = [root], byId = new Map();
  for (const m of doc.matchAll(/<(\/?)([A-Za-z][\w:-]*)((?:\s+[\w:-]+="[^"<]*")*)\s*(\/?)>/g)) {
    if (m[1]) { stack.pop(); continue; }
    const attrs = Object.fromEntries([...m[3].matchAll(/([\w:-]+)="([^"]*)"/g)].map(([, k, v]) => [k, v]));
    const node = { name: m[2], attrs, children: [] };
    stack[stack.length - 1].children.push(node);
    if (attrs.id) byId.set(attrs.id, node);
    if (!m[4]) stack.push(node);
  }
  return { root, byId };
}
function arcPoints(x1, y1, rx, ry, phi, fa, fs, x2, y2) {
  // SVG implementation notes F.6.5: endpoint to centre parameterisation, then sampled along the sweep
  rx = Math.abs(rx); ry = Math.abs(ry);
  if (rx === 0 || ry === 0) return [[x2, y2]];
  const p = phi * Math.PI / 180, cp = Math.cos(p), sp = Math.sin(p);
  const dx = (x1 - x2) / 2, dy = (y1 - y2) / 2, x1p = cp * dx + sp * dy, y1p = -sp * dx + cp * dy;
  const lam = (x1p * x1p) / (rx * rx) + (y1p * y1p) / (ry * ry);
  if (lam > 1) { rx *= Math.sqrt(lam); ry *= Math.sqrt(lam); }
  const num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p, den = rx * rx * y1p * y1p + ry * ry * x1p * x1p;
  const co = (fa === fs ? -1 : 1) * Math.sqrt(Math.max(0, num / den));
  const cxp = co * rx * y1p / ry, cyp = -co * ry * x1p / rx;
  const cx = cp * cxp - sp * cyp + (x1 + x2) / 2, cy = sp * cxp + cp * cyp + (y1 + y2) / 2;
  const ang = (ux, uy, vx, vy) => Math.atan2(ux * vy - uy * vx, ux * vx + uy * vy);
  const t1 = ang(1, 0, (x1p - cxp) / rx, (y1p - cyp) / ry);
  let dt = ang((x1p - cxp) / rx, (y1p - cyp) / ry, (-x1p - cxp) / rx, (-y1p - cyp) / ry);
  if (!fs && dt > 0) dt -= 2 * Math.PI; else if (fs && dt < 0) dt += 2 * Math.PI;
  const pts = [];
  for (let i = 1; i <= SAMPLES; i++) { const t = t1 + dt * i / SAMPLES, ex = rx * Math.cos(t), ey = ry * Math.sin(t); pts.push([cp * ex - sp * ey + cx, sp * ex + cp * ey + cy]); }
  return pts;
}
function pathPoints(d) {
  const pts = [];
  pts.starts = [];   // where each subpath begins in `pts` — `outlineOf()` reads it; the boxes never need it
  let x = 0, y = 0, sx = 0, sy = 0;
  const bez = (ctrl) => { for (let i = 1; i <= SAMPLES; i++) { const t = i / SAMPLES, u = 1 - t; const P = ctrl.length === 4
    ? [u * u * u * ctrl[0][0] + 3 * u * u * t * ctrl[1][0] + 3 * u * t * t * ctrl[2][0] + t * t * t * ctrl[3][0], u * u * u * ctrl[0][1] + 3 * u * u * t * ctrl[1][1] + 3 * u * t * t * ctrl[2][1] + t * t * t * ctrl[3][1]]
    : [u * u * ctrl[0][0] + 2 * u * t * ctrl[1][0] + t * t * ctrl[2][0], u * u * ctrl[0][1] + 2 * u * t * ctrl[1][1] + t * t * ctrl[2][1]]; pts.push(P); } };
  for (const m of d.matchAll(/([MLHVCQZAmlhvcqza])([^MLHVCQZASTmlhvcqzast]*)|([STst])/g)) {
    if (m[3]) throw new Error(`inkBounds: path command ${m[3]} is not implemented`);
    const cmd = m[1], a = nums(m[2]), rel = cmd === cmd.toLowerCase() && cmd !== 'Z' && cmd !== 'z';
    const ox = () => (rel ? x : 0), oy = () => (rel ? y : 0);
    let i = 0;
    const up = cmd.toUpperCase();
    if (up === 'Z') { x = sx; y = sy; pts.push([x, y]); continue; }
    do {
      if (up === 'M') { x = a[i] + ox(); y = a[i + 1] + oy(); sx = x; sy = y; if (i === 0) pts.starts.push(pts.length); pts.push([x, y]); i += 2; }
      else if (up === 'L') { x = a[i] + ox(); y = a[i + 1] + oy(); pts.push([x, y]); i += 2; }
      else if (up === 'H') { x = a[i] + ox(); pts.push([x, y]); i += 1; }
      else if (up === 'V') { y = a[i] + oy(); pts.push([x, y]); i += 1; }
      else if (up === 'C') { bez([[x, y], [a[i] + ox(), a[i + 1] + oy()], [a[i + 2] + ox(), a[i + 3] + oy()], [a[i + 4] + ox(), a[i + 5] + oy()]]); x = a[i + 4] + ox(); y = a[i + 5] + oy(); i += 6; }
      else if (up === 'Q') { bez([[x, y], [a[i] + ox(), a[i + 1] + oy()], [a[i + 2] + ox(), a[i + 3] + oy()]]); x = a[i + 2] + ox(); y = a[i + 3] + oy(); i += 4; }
      else if (up === 'A') { const ex = a[i + 5] + ox(), ey = a[i + 6] + oy(); pts.push(...arcPoints(x, y, a[i], a[i + 1], a[i + 2], a[i + 3], a[i + 4], ex, ey)); x = ex; y = ey; i += 7; }
    } while (i < a.length);
  }
  return pts;
}
function shapePoints(node) {
  const A = node.attrs, n = (k, d = 0) => (A[k] === undefined ? d : Number(A[k]));
  if (node.name === 'path') return pathPoints(A.d ?? '');
  if (node.name === 'rect') { const x = n('x'), y = n('y'), w = n('width'), h = n('height'); return [[x, y], [x + w, y], [x, y + h], [x + w, y + h]]; }
  if (node.name === 'circle' || node.name === 'ellipse') {
    const cx = n('cx'), cy = n('cy'), rx = node.name === 'circle' ? n('r') : n('rx'), ry = node.name === 'circle' ? n('r') : n('ry');
    return Array.from({ length: 48 }, (_, i) => { const t = i / 48 * 2 * Math.PI; return [cx + rx * Math.cos(t), cy + ry * Math.sin(t)]; });
  }
  if (node.name === 'line') return [[n('x1'), n('y1')], [n('x2'), n('y2')]];
  if (node.name === 'polygon' || node.name === 'polyline') { const a = nums(A.points ?? ''), p = []; for (let i = 0; i + 1 < a.length; i += 2) p.push([a[i], a[i + 1]]); return p; }
  return null;
}
/**
 * A shape's OUTLINE as polylines in its own units, each closed or open — what `inkBounds()`'s `span` cuts at a row. A
 * filled shape's subpaths are closed (SVG fills an open subpath as if closed); a stroke-only path's are open.
 */
function outlineOf(node, pts, filled) {
  if (node.name === 'rect') { const [a, b, c, d] = pts; return [{ poly: [a, b, d, c], closed: true }]; }
  if (node.name === 'circle' || node.name === 'ellipse' || node.name === 'polygon') return [{ poly: pts, closed: true }];
  if (node.name === 'line' || node.name === 'polyline') return [{ poly: pts, closed: node.name !== 'line' && node.name !== 'polyline' }];
  const starts = pts.starts?.length ? pts.starts : [0];
  return starts.map((st, k) => ({ poly: pts.slice(st, starts[k + 1] ?? pts.length), closed: filled }));
}
const SKIP = new Set(['defs', 'clipPath', 'radialGradient', 'linearGradient', 'pattern', 'stop', 'mask', 'symbol', 'style', 'title', 'desc']);
export function inkBounds(doc, each = null) {
  const { root, byId } = parseTree(doc);
  let box = null;
  const union = (b) => { if (!b) return; box = box ? [Math.min(box[0], b[0]), Math.min(box[1], b[1]), Math.max(box[2], b[2]), Math.max(box[3], b[3])] : b; };
  const boxOf = (pts, M, pad) => { let b = null; for (const p of pts) { const [x, y] = apply(M, p); b = b ? [Math.min(b[0], x), Math.min(b[1], y), Math.max(b[2], x), Math.max(b[3], y)] : [x, y, x, y]; } return b && [b[0] - pad, b[1] - pad, b[2] + pad, b[3] + pad]; };
  const cut = (b, c) => (b && c ? (b[0] > c[2] || b[2] < c[0] || b[1] > c[3] || b[3] < c[1] ? null : [Math.max(b[0], c[0]), Math.max(b[1], c[1]), Math.min(b[2], c[2]), Math.min(b[3], c[3])]) : b);
  const refOf = (v) => v?.match(/url\(#([^)]+)\)/)?.[1] ?? v?.match(/^#(.+)$/)?.[1];
  const geometryBox = (node, M) => { let b = null; for (const ch of node.children) { const p = shapePoints(ch); const Mc = mul(M, transformOf(ch.attrs.transform)); if (p) { const bb = boxOf(p, Mc, 0); b = b ? [Math.min(b[0], bb[0]), Math.min(b[1], bb[1]), Math.max(b[2], bb[2]), Math.max(b[3], bb[3])] : bb; } } return b; };
  const walk = (node, M, clip, up) => {
    if (SKIP.has(node.name)) return;
    const Mc = mul(M, transformOf(node.attrs.transform));
    let clipC = clip;
    const cp = refOf(node.attrs['clip-path']);
    if (cp) { const target = byId.get(cp); if (!target) throw new Error(`inkBounds: clip-path #${cp} names nothing`); clipC = cut(geometryBox(target, Mc) ?? [0, 0, 0, 0], clip) ?? [0, 0, 0, 0]; }
    if (node.name === 'use') {
      const target = byId.get(refOf(node.attrs.href ?? node.attrs['xlink:href']));
      if (!target) throw new Error('inkBounds: <use> names nothing');
      const Mu = mul(Mc, [1, 0, 0, 1, Number(node.attrs.x ?? 0), Number(node.attrs.y ?? 0)]);
      walk(target, Mu, clipC, [...up, node]);
      return;
    }
    const pts = shapePoints(node);
    if (pts) {
      const A = node.attrs, stroked = A.stroke !== undefined && A.stroke !== 'none', filled = A.fill !== 'none';
      if (!stroked && !filled) return;
      const scale = Math.sqrt(Math.abs(Mc[0] * Mc[3] - Mc[1] * Mc[2]));
      const pad = stroked ? (Number(A['stroke-width'] ?? 1) / 2) * scale : 0;
      const b = cut(boxOf(pts, Mc, pad), clipC);
      if (b && !(b[0] === 0 && b[1] === 0 && b[2] === 0 && b[3] === 0 && clipC && clipC[2] === 0)) {
        union(b);
        // `span(y)`: the shape's ink where the document's row `y` crosses it — its outline cut at the row, widened by its
        // stroke and held to its clip — as [left, right], or null where the row misses it (card#12502: how wide a body
        // is at the height a laptop covers, which its box, the widest the shape is anywhere, cannot say)
        const span = (y) => {
          if (clipC && (y < clipC[1] || y > clipC[3])) return null;
          let lo = Infinity, hi = -Infinity;
          for (const { poly, closed } of outlineOf(node, pts, filled)) {
            const P = poly.map((p) => apply(Mc, p)), n = P.length;
            for (let i = 0; i < (closed ? n : n - 1); i++) {
              const [ax, ay] = P[i], [bx, by] = P[(i + 1) % n];
              if (ay === by) { if (Math.abs(ay - y) <= pad) { lo = Math.min(lo, ax, bx); hi = Math.max(hi, ax, bx); } continue; }
              if ((ay - y) * (by - y) > 0) continue;
              const x = ax + (y - ay) * (bx - ax) / (by - ay);
              lo = Math.min(lo, x); hi = Math.max(hi, x);
            }
          }
          if (lo === Infinity) return null;
          lo -= pad; hi += pad;
          if (clipC) { lo = Math.max(lo, clipC[0]); hi = Math.min(hi, clipC[2]); }
          return lo <= hi ? [lo, hi] : null;
        };
        if (each) each(node, { x0: b[0], y0: b[1], x1: b[2], y1: b[3] }, up, span);
      }
      return;
    }
    for (const ch of node.children) walk(ch, Mc, clipC, [...up, node]);
  };
  walk(root, [1, 0, 0, 1, 0, 0], null, []);
  return box && { x0: box[0], y0: box[1], x1: box[2], y1: box[3] };
}
