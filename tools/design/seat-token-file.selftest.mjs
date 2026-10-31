#!/usr/bin/env node
// THE SEAT TOKENS PAGE'S SAVED FILE, READ BY THE INSTALLER — card#12571, D6 § 4.2's R-srv-1 (the half that needs
// the installer's parser). The producer (`server/public/js/console/seat-token.js`, on the markup the view renders)
// and the consumer (`fleet-reporter/install.js`'s exported `parseTokenFile`) are in this repository, so this file
// holds the hop between them: the bytes the page's button builds must be a v2 file the installer takes.
//
//   node tools/design/seat-token-file.selftest.mjs
//
// Needs `php` and `server/`'s composer install (no database, no `.env`): the markup is the real view, rendered by
// `render-floor-view.php tokens-issued` with a FIXTURE token and the controller's own ingest-URL derivation.
// Stock `node`, no dependencies, no browser: the page's script runs in a `node:vm` stub DOM, and the blob is
// node's own `Blob`.
//
// One run, then its planted controls. The run:
//   render     the https issue response renders exactly one save button and the token element; an http one, none
//   save       a click builds an `application/octet-stream` blob, downloaded by a link named
//              `mezzanine-reporter-token` whose href is that blob's
//   parse      `parseTokenFile(<the blob's bytes>)` returns version 2 with the rendered token, the button's
//              ingest URL, install id and seat id — the parser refuses anything else, by name
//   restore    `pageshow` with `persisted` true blanks the token element and removes the button
// Each plant mutates the script or the markup and requires the named check to RED; a plant that does not change
// its input, or that the checks pass, fails the run. Whether a browser actually downloads the file, or restores the
// page from its back/forward cache, is not measured here (`seat-token.browser.mjs` walks a real browser, by hand).
//
// ⛔ The fixture token is not a credential; no other token is ever in play. Nothing is printed but check names.

import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SCRIPT = readFileSync(path.join(ROOT, 'server/public/js/console/seat-token.js'), 'utf8');
const { parseTokenFile } = createRequire(import.meta.url)(path.join(ROOT, 'fleet-reporter/install.js'));

function render(appUrl) {
  const r = spawnSync('php', [path.join(ROOT, 'tools/design/render-floor-view.php'), 'tokens-issued', appUrl], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`render-floor-view.php tokens-issued exited ${r.status}: ${(r.stderr || '').slice(0, 300)}`);
  return r.stdout;
}

const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

/** The page's two elements the script reads, out of the rendered markup. */
function elementsOf(html) {
  const buttons = [...html.matchAll(/<button\b([^>]*\bdata-seat-token-save\b[^>]*)>/g)].map((m) => {
    const attrs = {};
    for (const a of m[1].matchAll(/([a-z-]+)(?:="([^"]*)")?/g)) attrs[a[1]] = decode(a[2] ?? '');
    return attrs;
  });
  const token = /<code\b[^>]*\bdata-seat-token\b[^>]*>([^<]*)<\/code>/.exec(html);
  return { buttons, token: token ? decode(token[1]) : null };
}

/** Runs the page's script over a stub DOM holding those elements; returns the handles a check drives. */
function runScript(source, { buttons, token }) {
  const listeners = {};
  const tokenEl = { textContent: token };
  const buttonEls = buttons.map((attrs) => {
    const dataset = {};
    for (const [k, v] of Object.entries(attrs)) if (k.startsWith('data-')) dataset[k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = v;
    const el = { dataset, removed: false, handlers: [], addEventListener(type, fn) { if (type === 'click') this.handlers.push(fn); }, remove() { this.removed = true; } };
    return el;
  });
  const live = () => buttonEls.filter((b) => !b.removed);
  const saved = { blobs: [], links: [] };
  const document = {
    querySelectorAll(sel) { return sel === '[data-seat-token-save]' ? live() : sel === '[data-seat-token]' ? [tokenEl] : []; },
    querySelector(sel) { return this.querySelectorAll(sel)[0] ?? null; },
    createElement(tag) {
      if (tag !== 'a') throw new Error(`the script created a <${tag}>`);
      const a = { href: '', download: '', attached: false, click() { saved.links.push({ href: this.href, download: this.download, attached: this.attached }); }, remove() { this.attached = false; } };
      return a;
    },
    body: { append(el) { el.attached = true; } },
  };
  const URL_ = { createObjectURL(blob) { saved.blobs.push(blob); return `blob:fixture/${saved.blobs.length}`; }, revokeObjectURL() {} };
  const window = { addEventListener(type, fn) { (listeners[type] ||= []).push(fn); } };
  vm.runInNewContext(source, { document, window, URL: URL_, Blob, setTimeout: () => 0 });
  return { tokenEl, buttonEls, live, saved, fire: (type, event) => (listeners[type] || []).forEach((fn) => fn(event)) };
}

/** The whole check over one script and one markup; returns the failed check names (empty = clean). */
async function check(source, html, httpHtml) {
  const failed = [];
  const el = elementsOf(html);
  if (el.buttons.length !== 1 || !el.token || elementsOf(httpHtml).buttons.length !== 0) { failed.push('render'); return failed; }
  const page = runScript(source, el);
  for (const b of page.buttonEls) b.handlers.forEach((fn) => fn());
  const blob = page.saved.blobs[0], link = page.saved.links[0];
  if (!blob || !link || blob.type !== 'application/octet-stream' || link.download !== 'mezzanine-reporter-token'
      || link.href !== 'blob:fixture/1' || !link.attached) failed.push('save');
  let parsed = null;
  try { parsed = blob ? parseTokenFile(Buffer.from(await blob.arrayBuffer())) : null; } catch (e) { parsed = null; }
  const b = el.buttons[0];
  if (!parsed || parsed.version !== 2 || parsed.token !== el.token.trim() || parsed.ingest_url !== b['data-ingest-url']
      || parsed.install_id !== b['data-install-id'] || parsed.seat_id !== b['data-seat-id']) failed.push('parse');
  page.fire('pageshow', { persisted: true });
  if (/mzn_/.test(page.tokenEl.textContent) || page.live().length !== 0) failed.push('restore');
  return failed;
}

const HTML = render('https://mezzanine.example');
const HTTP_HTML = render('http://mezzanine.example');
const want = (s, from, to) => { if (!s.includes(from)) throw new Error(`plant: "${from}" is not in its input, so the plant would change nothing`); return s.replace(from, to); };

const PLANTS = [
  ['a carriage return in the file', 'parse', () => [want(SCRIPT, '\\nseat_id ', '\\r\\nseat_id '), HTML, HTTP_HTML]],
  ['a key the parser does not know', 'parse', () => [want(SCRIPT, '\\ningest_url ', '\\ningest '), HTML, HTTP_HTML]],
  ['no line feed between two lines', 'parse', () => [want(SCRIPT, '\\ninstall_id ', ' install_id '), HTML, HTTP_HTML]],
  ['an http ingest URL in the markup', 'parse', () => [SCRIPT, want(HTML, 'data-ingest-url="https://', 'data-ingest-url="http://'), HTTP_HTML]],
  ['a token one character short in the markup', 'parse', () => [SCRIPT, want(HTML, 'data-seat-token>mzn_FIXTUREx', 'data-seat-token>mzn_FIXTURE'), HTTP_HTML]],
  ['the blob typed text/plain', 'save', () => [want(SCRIPT, "'application/octet-stream'", "'text/plain'"), HTML, HTTP_HTML]],
  ['the download named with an extension', 'save', () => [want(SCRIPT, "'mezzanine-reporter-token';", "'mezzanine-reporter-token.txt';"), HTML, HTTP_HTML]],
  ['the restore leaving the button', 'restore', () => [want(SCRIPT, 'el.remove();', 'void el;'), HTML, HTTP_HTML]],
  ['a save button on the http page', 'render', () => [SCRIPT, HTML, HTTP_HTML.replace('</main>', '<button type="button" data-seat-token-save>Save token file</button></main>')]],
];

let bad = 0;
const clean = await check(SCRIPT, HTML, HTTP_HTML);
const CHECKS = ['render', 'save', 'parse', 'restore'];
for (const name of CHECKS) {
  const ok = !clean.includes(name);
  if (!ok) bad += 1;
  console.log(`${ok ? 'ok    ' : 'FAILED'}  ${name}`);
}
for (const [label, expect, make] of PLANTS) {
  let failed;
  try { failed = await check(...make()); } catch (e) { failed = null; console.log(`FAILED  plant "${label}": ${e.message}`); bad += 1; continue; }
  const caught = failed.includes(expect);
  if (!caught) bad += 1;
  console.log(`${caught ? 'ok    ' : 'FAILED'}  plant "${label}" ${caught ? 'reds' : 'did NOT red'} ${expect}${failed.length ? ` (red: ${failed.join(', ')})` : ''}`);
}
console.log(bad ? `\n${bad} FAILED` : `\nPASS  ${CHECKS.length} checks, ${PLANTS.length} planted controls, every plant caught`);
process.exit(bad ? 1 : 0);
