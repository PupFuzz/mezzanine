#!/usr/bin/env node
// THE CHARACTER SHEET, AS A PNG — every species of `resources/characters/` in every frame the tree draws, rendered
// by headless Chromium from the real generator (card#12212; docs/design/FLOOR.md § 10.2's "the reference that
// survives is the generator itself and the sheet it draws"). `harness.html` is the sheet a person opens in a browser;
// this is the same drawing captured to a file an operator can be shown.
//
//   node tools/characters/sheet.browser.mjs --out sheet.png                      # every species, 1 px per frame unit
//   node tools/characters/sheet.browser.mjs --out animals.png --kind animal --unit 2 --variants 6
//   node tools/characters/sheet.browser.mjs --out new.png --new --unit 2         # only the species added after the approved sheet
//
// One row per species, in SPECIES_KEYS order, each species added after the approved sheet (its `added` label) marked NEW;
// one column per frame the frame contract
// names (FLOOR.md § 10.4): the standing frame (every pose but asleep draws it), the walk's step-left and step-right,
// the intern's chibi frame, and every document of the sleeper — the still, then the loop — in the sleeper's own raised, widened
// frame with the floor's clear rects, over a desk edge drawn at the floor's own share — every seat frame in the same
// widened, raised frame since card#12259
// (`--desk-top`, default 70/96, the share `tools/characters/selftest.mjs` draws at); then `--variants` more standing
// frames of other keys, so the colourways, hats, neckwear and extras show. Each frame is an <img> over the tree's SVG
// document as a data: URI under encodeURIComponent — exactly how `floor/painter.js` shows it.
//
// NO BUILD STEP, NO DEPENDENCY, NO NETWORK: a static server on 127.0.0.1 serves the repository root to the browser
// `tools/design/headless-chromium.mjs` launches (`--chrome <path>`, `$CHROME`, or the Playwright cache).
// It proves nothing and gates nothing — the selftest proves the documents; this is for looking at them.

import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from '../design/headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const argv = process.argv.slice(2);
const argOf = (flag, fallback = null) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : fallback; };

const out = argOf('--out');
if (!out) { console.error('usage: sheet.browser.mjs --out <png> [--kind animal|vegetable] [--new] [--unit px-per-frame-unit] [--variants N] [--desk-top share] [--chrome path]'); process.exit(2); }
const q = new URLSearchParams({
  kind: argOf('--kind', 'all'), new: argv.includes('--new') ? '1' : '', unit: argOf('--unit', '1'), variants: argOf('--variants', '4'), deskTop: argOf('--desk-top', String(70 / 96)),
});

const TYPES = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.html': 'text/html; charset=utf-8', '.json': 'application/json', '.css': 'text/css' };
const PAGE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>drawing</title>
<style>
  body { margin: 0; background: #f3ece2; color: #3b2f2a; font: 13px/1.3 ui-sans-serif, system-ui, sans-serif; }
  table { border-collapse: collapse; margin: 12px; }
  th { font-weight: 600; padding: 4px 6px; text-align: center; vertical-align: bottom; border-bottom: 2px solid #c9b8a4; white-space: nowrap; }
  td { padding: 4px 6px; vertical-align: bottom; text-align: center; border-bottom: 1px solid #e0d3c2; }
  td.name { text-align: left; white-space: nowrap; vertical-align: middle; }
  td.name b { font-size: 15px; display: block; }
  td.name small { color: #7a6a5e; }
  tr.new td { background: #fbf4d8; }
  tr.new td.name b::after { content: " NEW"; color: #b4542c; font-size: 11px; letter-spacing: .08em; }
  .cell { position: relative; display: inline-block; }
  .cell img { display: block; }
  .cell .desk { position: absolute; left: 0; right: 0; background: #b07a44; border-top: 3px solid #d9a66a; opacity: .92; }
  caption { caption-side: top; text-align: left; padding: 8px 0; font-size: 14px; }
  caption small { display: block; color: #7a6a5e; }
</style></head><body><table id="t"></table>
<script type="module">
  import * as C from '/resources/characters/index.js';
  import { characterOverdraw } from '/server/public/js/floor/desk-layout.js';
  import { FURNITURE_BOX } from '/resources/floor/furniture-box.js';
  const { clear } = characterOverdraw(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
  const SV = C.SEAT_VIEWBOX, CV = C.CHIBI_VIEWBOX;
  const q = new URLSearchParams(location.search);
  const U = Number(q.get('unit')), V = Number(q.get('variants')), deskTop = Number(q.get('deskTop'));
  // every seat frame is SEAT_VIEWBOX (the sleeper's widened, raised frame, card#12259); the chibi frame CHIBI_VIEWBOX
  const W = SV.w * U, H = SV.h * U, CW = CV.w * U, CH = CV.h * U;
  const kind = q.get('kind'), onlyNew = q.get('new') === '1';
  const keys = C.SPECIES_KEYS.filter((k) => (kind === 'all' || C.SPECIES[k].kind === kind) && (!onlyNew || C.SPECIES[k].added));
  const uri = (doc) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(doc);
  const img = (doc, w, h, extra = '') => '<span class="cell"><img width="' + w + '" height="' + h + '" src="' + uri(doc) + '" alt="">' + extra + '</span>';
  const head = ['species', 'standing / working', 'walk: step-left', 'walk: step-right', 'intern (chibi)'];
  head.push('asleep: the still'); for (let i = 1; i <= C.SLEEP_LOOP; i++) head.push('asleep loop ' + i);
  for (let i = 0; i < V; i++) head.push('variant ' + (i + 1));
  const total = C.SPECIES_KEYS.length, added = C.SPECIES_KEYS.filter((k) => C.SPECIES[k].added).length;
  let html = '<caption>Mezzanine — the character sheet: ' + keys.length + ' of ' + total + ' species (' + (total - added) + ' of the approved sheet + ' + added + ' added since), every frame the tree draws'
    + '<small>Rendered by tools/characters/sheet.browser.mjs from resources/characters/ at ' + U + ' px per frame unit (a seat\\'s rect is 54 × 96 floor px = 108 × 192 units; its frame is drawn ' + SV.w + ' × ' + SV.h + ' units from (' + SV.x + ', ' + SV.y + '), the intern\\'s ' + CV.w + ' × ' + CV.h + '). Sleeper frames over a desk edge at ' + deskTop.toFixed(3) + ' of the rect. Variant columns are other keys of the same species, so colourway, hat, neckwear and extra vary.</small></caption>';
  html += '<tr>' + head.map((h) => '<th>' + h + '</th>').join('') + '</tr>';
  for (const sp of keys) {
    const S = C.SPECIES[sp], key = 'sheet/' + sp;
    const d = C.describe(key, sp);
    html += '<tr class="' + (S.added ? 'new' : '') + '"><td class="name"><b>' + S.label + '</b><small>' + sp + ' · ' + S.kind + (S.added ? ' · added by ' + S.added : '') + '<br>' + S.pals.length + ' colourways · hats ' + S.hats.join(', ') + '<br>necks ' + (S.necks.join(', ') || '—') + ' · extras ' + S.extras.join(', ') + '</small></td>';
    for (const phase of [0, 1, 2]) html += '<td>' + img(C.frameDocument(key, { species: sp, phase }), W, H) + '</td>';
    html += '<td>' + img(C.frameDocument(key + '~intern', { species: sp, chibi: true }), CW, CH) + '</td>';
    // the sleeper's documents, in the same frame, with the desk edge drawn where the character rect's share puts it
    for (let fr = 0; fr <= C.SLEEP_LOOP; fr++) html += '<td>' + img(C.sleepDocument(key, { species: sp, frame: fr, deskTop, clear }), SV.w * U, SV.h * U, '<span class="desk" style="top:' + ((deskTop * 192 - SV.y) * U).toFixed(1) + 'px;bottom:0"></span>') + '</td>';
    // variants: other keys, chosen so each shows a colourway not yet shown in the row where the species has one left
    const shown = new Set([d.pal]);
    let n = 0, i = 0;
    while (n < V && i < 400) {
      const k = 'sheet/' + sp + '/' + i++;
      const r = C.describe(k, sp);
      if (shown.size < S.pals.length && shown.has(r.pal)) continue;
      shown.add(r.pal); n++;
      html += '<td>' + img(C.frameDocument(k, { species: sp }), W, H) + '</td>';
    }
    html += '</tr>';
  }
  document.getElementById('t').innerHTML = html;
  await Promise.all([...document.images].map((im) => im.decode().catch(() => {})));
  document.title = 'ready';
</script></body></html>`;

const server = createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/__sheet.html') { res.writeHead(200, { 'content-type': TYPES['.html'] }); res.end(PAGE); return; }
  const file = normalize(join(REPO, path));
  if (!file.startsWith(REPO)) { res.writeHead(403); res.end(); return; }
  try { const body = await readFile(file); res.writeHead(200, { 'content-type': TYPES[extname(file)] ?? 'application/octet-stream' }); res.end(body); } catch { res.writeHead(404); res.end(); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));

const chrome = findChrome();
if (!chrome) { console.error('no Chromium found: pass --chrome <path> or set $CHROME'); process.exit(1); }
// First pass at a small viewport to measure the sheet, then the shot at the sheet's own size.
const url = `http://127.0.0.1:${server.address().port}/__sheet.html?${q}`;
let b = await browser(chrome, 1200, 800, 'ready');
await b.load(url);
const [w, h] = await b.evaluate('[document.documentElement.scrollWidth, document.documentElement.scrollHeight]');
b.close();
b = await browser(chrome, w, h, 'ready');
await b.load(url);
const png = await b.shot();
b.close();
server.close();
await writeFile(out, Buffer.from(png.split(',')[1], 'base64'));
console.log(`${out}: ${w} x ${h} px, ${q.get('kind')}${q.get('new') ? ' / new only' : ''}, ${q.get('unit')} px per unit, ${q.get('variants')} variants`);
