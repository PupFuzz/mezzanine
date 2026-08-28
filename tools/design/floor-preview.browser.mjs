#!/usr/bin/env node
// The COMPOSED-LAYOUT gate for the floor-preview artifact. Node, no dependencies, no network — but
// a real browser, because that is the whole point of it.
//
//   node tools/design/floor-preview.browser.mjs
//   node tools/design/floor-preview.browser.mjs --html <path>      # judge another revision
//   node tools/design/floor-preview.browser.mjs --chrome <path>    # or $CHROME
//
// ⛔ WHY THIS FILE EXISTS, AND WHY IT IS NOT A SIXTH LAYER ON TOP OF THE FIFTH.
// `floor-preview.selftest.mjs` reads the artifact's emitted values in a stub DOM: no layout, no
// paint, no browser. Its § 8 measures SVG geometry by parsing SVG attributes. That is a real check
// and it stays. What it cannot see is that THIS ARTIFACT HAS TWO COORDINATE SYSTEMS:
//
//   * the floor, the desks and the band, drawn in SVG USER UNITS inside one `<svg>`; and
//   * the thought bubbles, nameplates, markers and hit targets, which are HTML `<div>`s positioned
//     as a PERCENTAGE of that SVG's box but SIZED IN CSS PIXELS.
//
// A user unit and a CSS pixel are not the same length and their ratio is not a constant, so nothing
// that reads SVG attributes can answer "does the bubble cover the header". Four review rounds of
// this card each moved SVG content correctly and each re-collided with the layer no gate measured:
// the overflow row landed on the tea bar, then below the floor with its bubble on the band's own
// header and its nameplate hanging out of the strip. Every static gate said PASS every time,
// because the overlay layer was in no denominator.
//
// ⭐ THE FIX IS NOT A BETTER MODEL OF THE TWO SYSTEMS — IT IS TO STOP HAVING TWO. In a composed
// page `getBoundingClientRect()` returns both layers in ONE space, so "does A cover B" is a rect
// intersection between things that were actually laid out. There is no transform parser here, no
// arc bounding, no stroke padding, no `NOT MEASURED` category and no per-layer bookkeeping: those
// were all machinery for measuring a picture without drawing it.
//
// WHAT IS ASSERTED, per (room width × framing):
//   1. THE MAP IS SOUND. The client↔user-unit map is taken from the `<svg>`'s own client rect and
//      is then CHECKED against a measured element whose user-unit box is known exactly — the band's
//      `.bandstrip` rect. Every region below is derived through a map that has been shown to agree
//      with the page to within a pixel, rather than assumed.
//   2. NOTHING COVERS THE BAND'S HEADER. No overlay rect intersects any `.bandhdr` rect. This is
//      the direct form of the defect: the header block is measured as the browser laid the text
//      out, glyph widths and all — which is exactly what a static bound refuses to claim.
//   3. EVERY OVERLAY IS INSIDE ITS STOREY, and the artifact's own `data-band` says which storey
//      that is. A band seat's overlays must be inside the strip; a floor seat's must be inside the
//      floor body. The declaration is CHECKED against where the rect actually landed rather than
//      believed — a `data-band` that lies is the same red as a plate that hangs out.
//   4. THE POPULATION REALLY CONTAINED THE THING. At least one band overlay and one header were
//      measured; a sweep over nothing is not a clean sweep.
//
// WHAT THE POPULATION IS, AND WHY IT IS WIDTHS AND NOT ZOOMS. `#world` carries
// `transform:scale(z)`, so a zoom scales the SVG and the overlays together: an overlay's size in
// USER UNITS is `css_px · BW/room`, a function of the ROOM'S WIDTH alone. § 3 below measures that
// invariance rather than assuming it — the same overlay is measured at two widths and at three
// framings, and the derived CSS height must come out the same every time. So the population is
// {two room widths} × {each floor's own fit-floor framing, plus the whole-building framing}, and
// the widths are the axis that can actually falsify anything.
//
// ⚠ WHAT THIS IS STILL NOT EVIDENCE ABOUT. It measures BOXES, not ink: `opacity`, `z-index`, fill
// colour and what is legible against what are outside it, and two rects that do not intersect can
// still look wrong. It is a check on geometry that a screenshot answers about appearance, and the
// card's definition of done keeps the screenshot.
//
// EVERY CHECK HERE CAN FAIL, AND THE CONTROLS PROVE IT. Each planted mutation is anchored, its
// anchor is asserted to occur exactly once, and the named check is REQUIRED to go red. Two of them
// are the two defects this round fixes, re-minted from their own pre-fix constants.

import { readFileSync, writeFileSync, mkdtempSync, existsSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';

const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const HTML_PATH = argOf('--html')
  || join(REPO, 'docs', 'design', 'floor-preview', 'floor-preview.html');

let failures = 0;
let checks = 0;
/** @param {boolean} cond @param {string} what */
function check(cond, what) {
  checks++;
  if (cond) { console.log(`  ok   ${what}`); } else { console.log(`  FAIL ${what}`); failures++; }
}
/** @param {string} s */
function section(s) { console.log(`\n${s}`); }
let controls = 0;
/** A planted mutation's anchor, asserted present exactly once — the same discipline, and the same
 *  reason, as `floor-preview.selftest.mjs`: a control that no longer bites is not a control, and
 *  how many this file carries is COUNTED at run time rather than written into a header that
 *  drifts. @param {number} hits @param {string} what @returns {boolean} */
function control(hits, what) {
  controls++;
  check(hits === 1, `control ${controls} is anchored exactly once (${hits}) — ${what}`);
  return hits === 1;
}
const hitsOf = (haystack, anchor) => haystack.split(anchor).length - 1;

// ---------------------------------------------------------------------------------------------
// The browser
// ---------------------------------------------------------------------------------------------
/**
 * ⛔ NO BROWSER IS A FAILURE, NEVER A SKIP. A gate that quietly reports nothing when its
 * instrument is missing is the false-clean shape this whole card is about: the run would be green
 * and the layer would be unmeasured, which is the state that shipped four times.
 */
function findChrome() {
  const named = argOf('--chrome') || process.env.CHROME;
  if (named) return existsSync(named) ? named : null;
  const cache = join(process.env.HOME || '', '.cache', 'ms-playwright');
  if (!existsSync(cache)) return null;
  for (const d of readdirSync(cache).filter((n) => n.startsWith('chromium'))) {
    for (const sub of ['chrome-headless-shell-linux64/chrome-headless-shell', 'chrome-linux/chrome']) {
      const p = join(cache, d, sub);
      if (existsSync(p)) return p;
    }
  }
  return null;
}

// The probe. It runs INSIDE the page, after `load`, so every number it reports is the browser's
// own composed geometry. It reads the artifact's constants out of the page's scope rather than
// restating them here — a second copy of `FH` in this file would be a figure that drifts from the
// artifact it is judging, and would drift SILENTLY, since both sides would still agree with
// themselves.
const probeFor = (framing) => `
<script>
window.addEventListener("load",function(){
  var out={framing:${JSON.stringify(framing)},err:null};
  try{
    if(${JSON.stringify(framing)}==="building"){ z=zAll(); clampPan(); applyView(); }
    else focusFloor(${JSON.stringify(framing)},false);
    var R=function(e){var r=e.getBoundingClientRect();
      return {l:+r.left.toFixed(3),t:+r.top.toFixed(3),r:+r.right.toFixed(3),b:+r.bottom.toFixed(3)};};
    var svg=document.querySelector("#world svg");
    out.svg=R(svg); out.viewBox=svg.getAttribute("viewBox");
    out.room=R(document.getElementById("room"));
    out.consts={FW:FW,FH:FH,BW:BW,BH:BH(),OX:OX,BAND_H:BAND_H,BAND_DESK_Y:BAND_DESK_Y,
      FLOORS:FLOORS.slice(),OY:FLOORS.map(function(_,i){return OY(i);}),
      floorH:FLOORS.map(function(i){return floorH(i);})};
    out.hdr=[].map.call(document.querySelectorAll("#world .bandhdr"),
      function(e){return Object.assign(R(e),{txt:(e.textContent||"").slice(0,40)});});
    out.strip=[].map.call(document.querySelectorAll("#world .bandstrip"),R);
    out.overlays=[];out.untagged=[];
    [].forEach.call(document.getElementById("world").children,function(e){
      if(e.tagName.toLowerCase()==="svg")return;
      var kind=String(e.className).split(" ")[0];
      if(e.dataset.band===undefined){out.untagged.push(kind);return;}
      out.overlays.push(Object.assign(R(e),{kind:kind,
        inst:e.dataset.inst,seat:e.dataset.seat,band:e.dataset.band,
        txt:(e.textContent||"").slice(0,34)}));});
  }catch(err){ out.err=String(err&&err.message||err); }
  var s=document.createElement("script");s.type="application/json";s.id="__geom";
  s.textContent=JSON.stringify(out);document.documentElement.appendChild(s);
});
</script>`;

const TMP = mkdtempSync(join(tmpdir(), 'floorprev-'));
let renders = 0;
/**
 * Compose the artifact with the probe, render it, and read the geometry back.
 * The channel is `--dump-dom`: the probe writes its JSON into a `<script type="application/json">`
 * and the dump carries it out. `null` is returned only when the browser produced no probe block at
 * all, and every caller treats that as a failure rather than as an empty measurement.
 * @returns {object|null}
 */
function measure(chrome, source, framing, windowW) {
  const file = join(TMP, `r${renders++}.html`);
  writeFileSync(file, source + probeFor(framing));
  let dom;
  try {
    dom = execFileSync(chrome, ['--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
      `--window-size=${windowW},1100`, '--virtual-time-budget=6000', '--dump-dom', `file://${file}`],
    { maxBuffer: 1 << 28, stdio: ['ignore', 'pipe', 'ignore'], timeout: 120000 }).toString();
  } catch (e) { return null; }
  const m = dom.match(/<script type="application\/json" id="__geom">([\s\S]*?)<\/script>/);
  if (!m) return null;
  try { return JSON.parse(m[1]); } catch (e) { return null; }
}

// ---------------------------------------------------------------------------------------------
// The geometry
// ---------------------------------------------------------------------------------------------
const inter = (a, b) => a.l < b.r && b.l < a.r && a.t < b.b && b.t < a.b;
const inside = (a, box, slack = 0.5) => a.l >= box.l - slack && a.r <= box.r + slack
  && a.t >= box.t - slack && a.b <= box.b + slack;
const fmt = (r) => `[${r.l.toFixed(1)},${r.t.toFixed(1)}–${r.r.toFixed(1)},${r.b.toFixed(1)}]`;

/**
 * The client↔user-unit map, from the `<svg>`'s own rect. The element carries
 * `width:100%;height:auto` over a `0 0 BW BH` viewBox, so the map is a uniform scale with no
 * letterboxing — which is asserted, not assumed, by `mapIsSound` below.
 */
function mapOf(g) {
  const sx = g.svg.r - g.svg.l, sy = g.svg.b - g.svg.t;
  const k = sx / g.consts.BW;
  return { k, ky: sy / g.consts.BH, x: (u) => g.svg.l + u * k, y: (u) => g.svg.t + u * k };
}
/** A user-unit box, in client pixels. */
const boxOf = (M, x0, y0, x1, y1) => ({ l: M.x(x0), t: M.y(y0), r: M.x(x1), b: M.y(y1) });

/**
 * THE WHOLE LAYER, reusable so the controls below can re-run it against a planted defect.
 * @param {object} g the probe's geometry @param {(c:boolean,w:string)=>void} say
 * @returns {string[]} the messages that FAILED
 */
function judge(g, label, say) {
  const failed = [];
  const record = (cond, what) => { if (!cond) failed.push(`${label}: ${what}`); say(cond, `${label}: ${what}`); };
  if (!g) { record(false, 'the browser produced a measurement at all'); return failed; }
  if (g.err) { record(false, `the probe ran without throwing — ${g.err}`); return failed; }
  const C = g.consts;
  // ⛔ The map must be READ from the page. `u * undefined` is `NaN`, and every comparison against a
  // NaN is `false`, so a missing constant would make "no intersection" true of everything and this
  // layer would pass over any picture at all — the vacuous-bounds shape the selftest caught in
  // itself. So the inputs are asserted finite BEFORE they are used.
  const nums = [C.FW, C.FH, C.BW, C.BH, C.OX, C.BAND_H, C.BAND_DESK_Y, ...C.OY, ...C.floorH];
  record(nums.every((v) => Number.isFinite(v)) && C.FLOORS.length > 0,
    `the artifact's own geometry was read out of the page — FH ${C.FH}, band ${C.BAND_H}, desks at ${C.BAND_DESK_Y}, floors ${JSON.stringify(C.FLOORS)}`);
  if (!nums.every((v) => Number.isFinite(v))) return failed;
  const M = mapOf(g);

  // 1. THE MAP, CHECKED. The strip's user-unit box is known exactly: it is the band's own
  // background rect, `y = FH … FH+BAND_H` inside its storey, full floor width at `OX`. If the map
  // predicts the rect the browser actually laid out, every region derived from it below is sound;
  // if it does not, nothing after this means anything, so it is a hard gate rather than a note.
  const stripOf = (fi) => boxOf(M, C.OX, C.OY[fi] + C.FH, C.OX + C.FW, C.OY[fi] + C.FH + C.BAND_H);
  const bodyOf = (fi) => boxOf(M, C.OX, C.OY[fi], C.OX + C.FW, C.OY[fi] + C.FH);
  record(Math.abs(M.k - M.ky) / M.k < 1e-4,
    `the viewBox maps uniformly — x scale ${M.k.toFixed(6)} against y ${M.ky.toFixed(6)}, so one factor carries both axes`);
  // ⛔ THE OVERLAY POPULATION IS DECLARED AND CHECKED, not filtered by a selector list here. Every
  // child of `#world` either carries `data-band` — and is judged below — or is one of the preview's
  // animation affordances, named here with the reason. A NEW overlay class added to the artifact
  // and not tagged would otherwise be silently outside every check on this page, which is the
  // shape of every miss this file exists for.
  const AFFORDANCES = ['env', 'envnote', 'star', 'folder', 'floorclip'];
  const strays = [...new Set(g.untagged || [])].filter((c) => !AFFORDANCES.includes(c));
  record(strays.length === 0,
    `every child of #world is either judged below or a declared animation affordance — ${g.overlays.length} judged, ${(g.untagged || []).length} affordances${strays.length ? `, UNDECLARED: ${strays.join(', ')}` : ''}`);
  const banded = C.FLOORS.map((_, i) => i).filter((i) => C.floorH[i] > C.FH);
  record(g.strip.length === banded.length,
    `every floor whose canvas was EXTENDED drew a strip — ${g.strip.length} measured against ${banded.length} extended (${banded.map((i) => C.FLOORS[i]).join(', ') || 'none'})`);
  for (const fi of banded) {
    const want = stripOf(fi), got = g.strip.find((s) => Math.abs(s.t - want.t) < 4);
    record(!!got && Math.max(Math.abs(got.l - want.l), Math.abs(got.t - want.t),
      Math.abs(got.r - want.r), Math.abs(got.b - want.b)) < 1,
    `${C.FLOORS[fi]}: the map agrees with the page to within a pixel — strip predicted ${fmt(want)}, measured ${got ? fmt(got) : 'NOT FOUND'}`);
  }

  // 2. NOTHING COVERS THE HEADER.
  record(g.hdr.length >= 2, `the band's header block was measured — ${g.hdr.length} line(s): ${g.hdr.map((h) => JSON.stringify(h.txt.slice(0, 18))).join(', ')}`);
  const covering = [];
  for (const o of g.overlays) {
    for (const h of g.hdr) {
      if (inter(o, h)) {
        covering.push(`${o.kind} ${JSON.stringify(o.txt.slice(0, 24))} ${fmt(o)} over ${JSON.stringify(h.txt.slice(0, 24))} ${fmt(h)}`);
      }
    }
  }
  record(covering.length === 0,
    `no overlay covers any line of the band's header — ${g.overlays.length} overlays against ${g.hdr.length} header lines${covering.length ? ` — ${covering[0]}` : ''}`);

  // 3. EVERY OVERLAY IS INSIDE THE STOREY IT SAYS IT IS IN.
  const outside = [];
  let bandOverlays = 0;
  for (const o of g.overlays) {
    const fi = C.FLOORS.indexOf(o.inst);
    if (fi < 0) { outside.push(`${o.kind} declares install ${JSON.stringify(o.inst)}, which is not a floor`); continue; }
    if (o.band !== '0' && o.band !== '1') { outside.push(`${o.kind} on ${o.seat} declares data-band ${JSON.stringify(o.band)}`); continue; }
    if (o.band === '1') bandOverlays++;
    const region = o.band === '1' ? stripOf(fi) : bodyOf(fi);
    if (!inside(o, region)) {
      outside.push(`${o.kind} on ${o.seat} says ${o.band === '1' ? 'band' : 'floor'} and is at ${fmt(o)}, outside ${fmt(region)}`);
    }
  }
  record(outside.length === 0,
    `every overlay is inside the storey region its own data-band declares — ${g.overlays.length} judged${outside.length ? ` — ${outside[0]}` : ''}`);

  // 4. …AND THE SWEEP REALLY CONTAINED THE THING IT EXISTS FOR.
  record(bandOverlays > 0 && g.hdr.length > 0,
    `the population really reached the band — ${bandOverlays} band overlay(s), ${g.hdr.length} header line(s); an empty sweep is not a clean one`);
  return failed;
}

// ---------------------------------------------------------------------------------------------
// The run
// ---------------------------------------------------------------------------------------------
const HTML = readFileSync(HTML_PATH, 'utf8');
section(`0. the instrument — a real browser, or nothing is measured`);
const CHROME = findChrome();
check(!!CHROME, `a headless browser was found — ${CHROME || 'NONE: pass --chrome <path> or set $CHROME. Nothing below ran.'}`);
if (!CHROME) {
  console.log(`\nFAIL  ${checks} checks, ${failures} failed  (${HTML_PATH})`);
  process.exit(1);
}

// ⛔ THE HOOKS THIS GATE MEASURES THROUGH, checked in the SOURCE before a browser is started. The
// regions it judges are named on the elements that ARE those regions (`.bandhdr`, `.bandstrip`) and
// each overlay declares its own (`data-band`); a revision carrying none of them is not a clean
// revision, it is one this gate CANNOT JUDGE — and the difference has to be legible, because
// without this the run below reds twenty times over "0 measured" and reads like a broken artifact
// rather than an unjudgeable one. Pointed at a pre-round-5 revision with `--html`, this is the line
// that says so.
section('0b. the artifact carries the hooks this gate measures through');
for (const [hook, what] of [
  ['class="bandhdr"', "the band's header lines, so the occlusion test has a target"],
  ['class="bandstrip"', 'the strip, so the map can be checked against a known box'],
  ['dataset.band', 'each overlay declaring which storey region it belongs to'],
]) {
  check(HTML.includes(hook), `the artifact names ${hook} — ${what}${HTML.includes(hook) ? '' : ' — THIS REVISION CANNOT BE JUDGED BY THIS GATE, and every failure below says only that'}`);
}

// The declared population. WIDTHS are window widths; the room is `min(1240, window - 28)` — the
// `.wrap`'s max-width and the body's padding — so these are a 1240 px room and a 972 px one, the
// widest the page ever gets and a narrow-laptop case.
const WINDOWS = [1400, 1000];
section('1. every floor, at its own fit-floor framing, at each declared width');
/** @type {Map<string,object>} */
const geom = new Map();
for (const w of WINDOWS) {
  for (const framing of ['aimla', 'sola', 'building']) {
    const g = measure(CHROME, HTML, framing, w);
    geom.set(`${w}:${framing}`, g);
    judge(g, `${w}px ${framing}`, check);
  }
}

// ---------------------------------------------------------------------------------------------
section('2. the same overlay at every framing — the population is WIDTHS, and this is why');
// The claim the population above rests on: a zoom scales the SVG and the overlays together, so an
// overlay's size in USER UNITS depends on the room's width alone. If that is false the population
// is missing an axis, so it is measured rather than argued: the nameplate's derived CSS height must
// come out the same at every framing and every width. A figure that moved with the framing would
// mean the three framings are three different pictures and only one of them was ever judged.
{
  const heights = [];
  for (const [key, g] of geom) {
    if (!g || g.err) { heights.push([key, null]); continue; }
    const M = mapOf(g);
    const p = g.overlays.find((o) => o.kind === 'plate' && o.band === '1');
    // client px → user units (÷ the map) → back to the CSS px the element is authored in, which is
    // user units × room/BW, since a CSS pixel is worth `BW/room` user units.
    heights.push([key, p ? ((p.b - p.t) / M.k) * ((g.room.r - g.room.l) / g.consts.BW) : null]);
  }
  check(heights.every(([, h]) => Number.isFinite(h)),
    `the band nameplate was measured in all ${heights.length} cases — ${heights.filter(([, h]) => !Number.isFinite(h)).map(([k]) => k).join(', ') || 'none missing'}`);
  const vals = heights.map(([, h]) => h).filter(Number.isFinite);
  const spread = Math.max(...vals) - Math.min(...vals);
  check(vals.length === heights.length && spread < 0.5,
    `…and it is the same ${vals.length ? vals[0].toFixed(2) : '?'} CSS px at every framing and width (spread ${spread.toFixed(3)} px) — so zoom is not an axis of this population, width is`);
  // …and the width the artifact's own comment claims the budget carries down to, CHECKED against
  // that measurement rather than restated. Printed, never stored: the moment it is written into a
  // comment it stops being re-derived.
  const g0 = geom.get(`${WINDOWS[0]}:sola`);
  if (g0 && !g0.err && vals.length) {
    const C = g0.consts;
    const narrowest = vals[0] * C.BW / (C.BAND_H - (C.BAND_DESK_Y - C.FH) - 64);
    check(Number.isFinite(narrowest) && narrowest > 0,
      `the strip's budget carries down to a room of ${narrowest.toFixed(0)} px — ${C.BAND_H} less the desks' ${C.BAND_DESK_Y - C.FH} and the plate's 64, against ${vals[0].toFixed(1)} CSS px of nameplate`);
    check(WINDOWS.every((w) => Math.min(1240, w - 28) > narrowest),
      `…and every width this gate declares is above it — rooms ${WINDOWS.map((w) => Math.min(1240, w - 28)).join(', ')} against ${narrowest.toFixed(0)}`);
  }
}

// ---------------------------------------------------------------------------------------------
section('3. the controls: each check re-run against the defect it exists to catch');
// The first two are the two defects of this round, re-minted from their own pre-fix constants. They
// are the reason this file exists: at both of these values every static gate in the repo was green.
for (const [plant, what, wants] of [
  // ⭐ HEAD `fe482eb`, RE-MINTED WHOLE — both constants at the values that shipped, so this is not
  // an analogue of the round's defects, it is the round's defects. Both must go red, and they go
  // red on different checks: the desks 70 user units higher put the thought bubble's opaque body on
  // the band's arithmetic line (A), and the 340-tall strip leaves the nameplate's chip and command
  // line below the dashed border, over the lobby (B). Every static gate in this repo was green on
  // exactly this state — which is the one sentence this whole file is here to make false.
  [[['const BAND_DESK_Y=FH+300;', 'const BAND_DESK_Y=FH+230;'], ['const BAND_H=520;', 'const BAND_H=340;']],
    'head fe482eb re-minted — the bubble on the header (A) and the nameplate out of the strip (B)',
    ['no overlay covers any line', 'inside the storey region']],
  // …and the containment leg on the FLOOR side, so it is not a check that only ever looks at the
  // band: a floor desk moved down until its own overlays cross `FH`.
  [[['a2:{x:600,y:720,w:230,lampx:0.85}', 'a2:{x:600,y:960,w:230,lampx:0.85}']],
    'a FLOOR desk pushed down until its overlays leave the floor', ['inside the storey region']],
  // …and the declaration itself. `data-band` is the artifact's own claim about which region an
  // overlay belongs to; a claim nothing checks is a comment. Every overlay declaring `floor` puts
  // the band seat's plate against the floor's region, where it is not.
  [[['el.dataset.band=slot?"0":"1";', 'el.dataset.band="0";']],
    'every overlay declaring itself a FLOOR overlay — the data-band claim is CHECKED, not believed',
    ['inside the storey region']],
  // …and the overlay population's own guard: a new overlay class that nothing tagged. It is not
  // judged by anything above, so the only thing that can catch it is the declaration check.
  [[['eb.dataset.inst=inst;eb.dataset.seat="(elevator)";eb.dataset.band="0";', '']],
  'an overlay nobody tagged — outside every check above, so only the declaration can catch it',
  ['declared animation affordance']],
]) {
  if (!plant.every(([a]) => control(hitsOf(HTML, a), `${what} — ${JSON.stringify(a.slice(0, 40))}`))) continue;
  const mutated = plant.reduce((s, [a, p]) => s.replace(a, p), HTML);
  const failed = judge(measure(CHROME, mutated, 'sola', WINDOWS[0]), 'planted', () => { });
  for (const want of wants) {
    const hit = failed.find((f) => f.includes(want));
    check(!!hit, `${what} goes RED on "${want}" — ${JSON.stringify(hit || null)}`);
  }
}
// The measurement's own discriminating controls: a predicate that has only ever answered one way is
// indistinguishable from one that cannot answer the other.
check(inter({ l: 0, t: 0, r: 10, b: 10 }, { l: 9, t: 9, r: 20, b: 20 })
  && !inter({ l: 0, t: 0, r: 10, b: 10 }, { l: 10, t: 10, r: 20, b: 20 }),
'the intersection test separates a one-pixel overlap from a shared edge');
check(inside({ l: 1, t: 1, r: 9, b: 9 }, { l: 0, t: 0, r: 10, b: 10 })
  && !inside({ l: 1, t: 1, r: 11, b: 9 }, { l: 0, t: 0, r: 10, b: 10 }),
'…and the containment test rejects a box that hangs over one edge');
// ⛔ AND THE HARNESS ITSELF. `measure` returning null is the only way this file can report nothing,
// and "nothing" must be a failure — a browser that did not start would otherwise sweep zero
// overlays, find zero collisions and print a clean run.
check(judge(null, 'no-browser', () => { }).length > 0,
  'a run that produced NO measurement is a FAILURE, not an empty clean sweep');
check(judge({ err: 'boom', consts: {} }, 'threw', () => { }).length > 0,
  '…and so is a probe that threw inside the page');

// ---------------------------------------------------------------------------------------------
console.log(`\n${failures ? 'FAIL' : 'PASS'}  ${checks} checks, ${controls} planted controls`
  + `, ${renders} browser renders  (${HTML_PATH})`);
process.exit(failures ? 1 : 0);
