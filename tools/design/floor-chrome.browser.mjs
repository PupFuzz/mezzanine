#!/usr/bin/env node
// THE FLOOR PAGE's CHROME, MEASURED ON A RENDERED LAYOUT — card#11045 PR-A, `docs/design/FLOOR.md` § 4.2's
// page chrome. Node, no dependencies — but a real browser, because where a flex column puts the room and
// where the sections below it land are a composition only a renderer produces; there is no browser on
// the build host, so this is run by hand, like `lobby-label-contrast.browser.mjs`.
//
//   node tools/design/floor-chrome.browser.mjs              # judge the shipped view and stylesheet
//   node tools/design/floor-chrome.browser.mjs --selftest   # …and see each check red on its own planted defect
//   node tools/design/floor-chrome.browser.mjs --chrome <path>   # or $CHROME
//
// ⛔ THE PAGE IS THE SERVED MARKUP: `render-floor-view.php` renders `floor.blade.php` through the layout
// (no request, session or database), and this file serves it with the shipped
// `server/public/css/mezzanine.css`. The page's module script is dropped: what is measured is the
// stylesheet's layout of the view, and the camera reads that same box (`main.js`'s `surface()`).
//
// ⛔ WHAT IS RENDERED — `RUNS` below, every one: viewport heights from a short phone to a tall portrait
// monitor, at a desktop and a phone width, each with no notice and with notices shown (the chrome above
// the room grows), so this sentence is never wider than `RUNS` covers.
//
// ⛔ WHAT IS MEASURED, PER RUN, and each is a defect when it fails:
//   **gap** — the first section's top against the drawing's bottom: they must touch, at every height (the
//   tall-viewport gap card#11045's review r1 found: a capped drawing left the leftover height empty).
//   **reveal** — while the drawing stands above its own `min-height`, the first summary's top sits exactly
//   `--reveal` above the window's bottom edge; at its floor, the drawing is exactly that floor and the
//   summary sits below the fold, which is the floor's purpose (a room too short to use is worse).
//   **panel** — the drill-down card, opened, starts at or below the camera row's bottom, so the strip and
//   the camera's controls stay in view while it is open (review r1 MINOR-2).
//   **fill** — the drawing spans the page's width.
//
// SELFTEST: each check is planted in a copy of the stylesheet (never in the shipped file) and must red;
// the shipped sheet must be green under the same runs.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, findChrome } from './headless-chromium.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const SHEET = join(HERE, '..', '..', 'server', 'public', 'css', 'mezzanine.css');

const RUNS = [
  ...[500, 800, 1000, 1341, 1800, 2400].map((h) => ({ w: 1400, h, notices: 0 })),
  ...[560, 700, 900, 1600].map((h) => ({ w: 390, h, notices: 0 })),
  { w: 1400, h: 1000, notices: 3 },
  { w: 1440, h: 2200, notices: 2 },
  { w: 390, h: 700, notices: 2 },
];

/** One plant per check: [anchor in the sheet, replacement]. */
const PLANTS = {
  gap: ['    min-height: 320px;\n', '    min-height: 320px;\n    max-height: 1200px;\n'],
  reveal: ['    min-height: calc(100dvh - var(--reveal));', '    min-height: calc(100dvh - 2 * var(--reveal));'],
  panel: ['.floor-stage > #floor-panel {\n    top: 12px;', '.floor-stage > #floor-panel {\n    top: -120px;'],
  fill: ['#floor-drawing {\n    flex: 1 1 0;', '#floor-drawing {\n    width: 60% !important;\n    flex: 1 1 0;'],
};

function page() {
  const html = execFileSync('php', [join(HERE, 'render-floor-view.php')], { encoding: 'utf8' });

  return html
    .replace(/<script type="module"[^>]*><\/script>/g, '')
    .replace(/href="[^"]*\/css\/mezzanine\.css\?v=\d+"/, 'href="/css/mezzanine.css"')
    .replace('</body>', '<script>document.title = "ready";</script></body>');
}

function serve(html, sheet) {
  const server = createServer((req, res) => {
    const path = new URL(req.url, 'http://x').pathname;
    if (path === '/') { res.writeHead(200, { 'content-type': 'text/html' }); res.end(html); return; }
    if (path === '/css/mezzanine.css') { res.writeHead(200, { 'content-type': 'text/css' }); res.end(sheet); return; }
    res.writeHead(404); res.end();
  });

  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// In the page: show `notices` notices, open the drill-down, and read every box the checks judge.
const MEASURE = (notices) => `(() => {
  const list = document.getElementById('floor-notices');
  list.replaceChildren(...Array.from({ length: ${notices} }, (_, i) => Object.assign(document.createElement('li'), { textContent: 'notice ' + i })));
  list.hidden = ${notices} === 0;
  document.getElementById('floor-camera').hidden = false;
  const panel = document.getElementById('floor-panel');
  panel.hidden = false;
  const box = (el) => { const r = el.getBoundingClientRect(); return { top: r.top + scrollY, bottom: r.bottom + scrollY, left: r.left, right: r.right, width: r.width, height: r.height }; };
  const drawing = document.getElementById('floor-drawing');
  return {
    vh: innerHeight,
    vw: document.documentElement.clientWidth,
    reveal: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--reveal')),
    min: parseFloat(getComputedStyle(drawing).minHeight),
    drawing: box(drawing),
    section: box(document.querySelector('.floor-section')),
    camera: box(document.querySelector('.floor-camera-row')),
    panel: box(panel),
  };
})()`;

function defects(m, run) {
  const out = [];
  const at = `${run.w}x${run.h}${run.notices ? ` +${run.notices} notices` : ''}`;
  const near = (a, b) => Math.abs(a - b) < 0.5;

  if (!near(m.section.top, m.drawing.bottom)) {
    out.push(['gap', `${at}: ${(m.section.top - m.drawing.bottom).toFixed(1)} px between the drawing's bottom and the first section`]);
  }

  if (m.drawing.height > m.min + 0.5 && !near(m.section.top, m.vh - m.reveal)) {
    out.push(['reveal', `${at}: the first section starts ${(m.vh - m.section.top).toFixed(1)} px above the window's bottom, not --reveal (${m.reveal})`]);
  }

  if (m.drawing.height < m.min - 0.5) {
    out.push(['reveal', `${at}: the drawing is ${m.drawing.height} px, under its own floor ${m.min}`]);
  }

  if (m.panel.top < m.camera.bottom - 0.5) {
    out.push(['panel', `${at}: the drill-down starts ${(m.camera.bottom - m.panel.top).toFixed(1)} px above the camera row's bottom, over the strip and the controls`]);
  }

  if (!near(m.drawing.width, m.vw)) {
    out.push(['fill', `${at}: the drawing is ${m.drawing.width} px wide in a ${m.vw} px page`]);
  }

  return out;
}

async function judge(chrome, html, sheet) {
  const server = await serve(html, sheet);
  const url = `http://127.0.0.1:${server.address().port}/`;
  const found = [];

  try {
    for (const run of RUNS) {
      const b = await browser(chrome, run.w, run.h, 'ready');

      try {
        await b.load(url);
        const m = await b.evaluate(MEASURE(run.notices));
        found.push(...defects(m, run));
      } finally {
        b.close();
      }
    }
  } finally {
    server.close();
  }

  return found;
}

const chrome = findChrome();

if (chrome === null) {
  console.error('FAIL — no Chromium: pass --chrome <path>, set $CHROME, or install one under ~/.cache/ms-playwright. A missing browser is a failure, never a skip.');
  process.exit(1);
}

const html = page();
const shipped = readFileSync(SHEET, 'utf8');
const shippedDefects = await judge(chrome, html, shipped);

for (const [, line] of shippedDefects) console.log(`DEFECT ${line}`);

let failed = shippedDefects.length > 0;

if (process.argv.includes('--selftest')) {
  for (const [check, [anchor, replacement]] of Object.entries(PLANTS)) {
    if (shipped.split(anchor).length !== 2) {
      console.log(`SELFTEST ${check}: the plant's anchor is not in the sheet exactly once — it would plant nothing`);
      failed = true;
      continue;
    }

    const caught = (await judge(chrome, html, shipped.replace(anchor, replacement))).filter(([c]) => c === check);
    console.log(`SELFTEST ${check}: ${caught.length > 0 ? `caught (${caught[0][1]})` : 'NOT CAUGHT'}`);
    failed ||= caught.length === 0;
  }
}

console.log(failed ? 'FAIL' : `PASS — ${RUNS.length} runs${process.argv.includes('--selftest') ? `, ${Object.keys(PLANTS).length} plants caught` : ''}`);
process.exit(failed ? 1 : 0);
