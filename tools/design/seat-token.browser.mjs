#!/usr/bin/env node
// THE SEAT TOKENS PAGE, WALKED IN A REAL BROWSER — card#12331. Hand-run against a LIVE server (a local
// `php artisan serve` over a private database, or the sandbox), never CI: it signs in as a real operator,
// second factor included, so it needs an account whose password and TOTP secret the runner holds.
//
//   SEAT_TOKEN_UAT_EMAIL=… SEAT_TOKEN_UAT_PASSWORD=… SEAT_TOKEN_UAT_TOTP_SECRET=<base32> \
//     node tools/design/seat-token.browser.mjs --base http://127.0.0.1:8123 [--http-site]
//   …and --chrome <path> or $CHROME, as every browser tool here. `--http-site` says the site's `app.url` is
//   `http:`, where the save button must not render; without it the walk expects an `https:` `app.url`. The
//   scheme `--base` is reached by is not the one that decides: the button follows `app.url`, not the request.
//
// The credentials come from the ENVIRONMENT, never argv (a process list shows argv). The walk issues a token
// for a fresh seat on the given site, so run it against a site where that is wanted.
//
// One sequence, each step a row that passes or fails on what the browser shows:
//   sign-in    the login form, then the two-factor challenge with a code computed from the secret
//   confirm    /admin/tokens asks for the password again first (`password.confirm`), then opens
//   pattern    the slug inputs' `pattern` attributes compile in the browser (a pattern that does not compile is
//              ignored silently): a non-slug is a `patternMismatch`, a slug is not
//   issue      the issue response shows exactly one `mzn_` token and its prefix, and is `Cache-Control: no-store`
//   save       card#12571, D6 § 4.2 (R-srv-1): the *Save token file* button is there, and a click builds a blob of
//              type `application/octet-stream` downloaded as `mezzanine-reporter-token`, holding exactly the v2
//              file — the four keys once each, LF only, no CR, no trailing space, the token the page shows, the
//              button's `https:` ingest URL ending `/api/ingest/events`, and the issued install and seat ids — with
//              no request sent by the click and the token in no URL the page was at. On an `http:` site
//              (`--http-site`) the row instead requires the button to be ABSENT
//   restore    the page's `pageshow` handler, fired with `persisted` true by the walk itself, blanks the token and
//              removes the save button. A synthetic event, because Chromium reports a POST's response as not restorable from
//              the back/forward cache (`HTTPMethodNotGET`), so `bfcache` below does not reach that page there
//   reload     reloading that response (the browser re-sends the POST) shows the "already used or out of date"
//              refusal, no token, and still one token row for the seat
//   back       navigating away and pressing Back shows no token
//   bfcache    whether that Back was a back/forward-cache restore (`pageshow.persisted`): when it was, the token
//              element must read as blanked and the save button must be gone; when it was not, the row reports
//              NOT MEASURED, never a pass
//   revoke     the row's revoke form, which takes no reason, revokes it and the list names the operator
//
// ⛔ THE TOKEN IS NEVER PRINTED. Rows print its prefix and its length; the plaintext stays inside the browser.

import { createHmac, randomBytes } from 'node:crypto';
import { browser, findChrome } from './headless-chromium.mjs';

const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const BASE = (argOf('--base') || '').replace(/\/$/, '');
const HTTP_SITE = argv.includes('--http-site');
const { SEAT_TOKEN_UAT_EMAIL: EMAIL, SEAT_TOKEN_UAT_PASSWORD: PASSWORD, SEAT_TOKEN_UAT_TOTP_SECRET: SECRET } = process.env;
if (!BASE || !EMAIL || !PASSWORD || !SECRET) {
  console.error('usage: SEAT_TOKEN_UAT_EMAIL=… SEAT_TOKEN_UAT_PASSWORD=… SEAT_TOKEN_UAT_TOTP_SECRET=… node seat-token.browser.mjs --base <url>');
  process.exit(2);
}
const chrome = findChrome();
if (!chrome) { console.error('no headless Chromium found (--chrome, $CHROME or ~/.cache/ms-playwright): FAILED, not skipped'); process.exit(1); }

/** RFC 6238 TOTP — SHA-1, 30 s, 6 digits — over a base32 secret, as the authenticator app computes it. */
function totp(base32, at = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of base32.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
  const h = createHmac('sha1', key).update(counter).digest();
  const o = h[h.length - 1] & 0xf;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
}

const rows = [];
const row = (id, ok, detail) => { rows.push({ id, ok, detail }); console.log(`${ok === null ? 'NOT MEASURED' : ok ? 'ok    ' : 'FAILED'}  ${id.padEnd(8)} ${detail}`); };

const b = await browser(chrome, 1200, 900, 'unused');
const { page, on } = b;
/** `evaluate`, retried while a navigation has the page's context torn down. */
const evaluate = async (expr) => {
  for (let i = 0; i < 100; i += 1) {
    try { return await b.evaluate(expr); } catch (e) { if (!/context|Cannot find|destroyed/i.test(e.message)) throw e; await sleep(50); }
  }
  throw new Error(`the page never settled for: ${expr}`);
};
const documents = [];
on('Network.responseReceived', (p) => { if (p.type === 'Document') documents.push({ url: p.response.url, status: p.response.status, cacheControl: p.response.headers['Cache-Control'] ?? p.response.headers['cache-control'] ?? '' }); });
const requests = [];
on('Network.requestWillBeSent', (p) => { requests.push(p.request.url); });
await page('Network.enable');
let restored = null;
on('Page.backForwardCacheNotUsed', (p) => { restored = { used: false, reasons: (p.notRestoredExplanations || []).map((x) => x.reason) }; });
// Every document records `pageshow.persisted` where the walk can read it after a Back.
await page('Page.addScriptToEvaluateOnNewDocument', { source: "addEventListener('pageshow', (e) => { window.__persisted = e.persisted; });" });
// Every document records the blob a save builds and the link that downloads it, and suppresses the download
// itself: the walk judges the bytes the page would write, inside the page, and never writes or prints them.
await page('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
  const create = URL.createObjectURL.bind(URL);
  URL.createObjectURL = (blob) => { const href = create(blob); window.__saved = { href, type: blob.type, text: null };
    blob.text().then((t) => { window.__saved.text = t; }); return href; };
  const click = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function () {
    if (this.href.startsWith('blob:')) { window.__savedLink = { href: this.href, download: this.download, attached: this.isConnected }; return; }
    return click.call(this);
  };
})();` });

/** Run `action` in the page and wait until a NEW document has loaded. */
async function navigates(action) {
  await evaluate('window.__walkMarker = 1');
  await evaluate(action);
  for (let i = 0; i < 200; i += 1) {
    await sleep(50);
    try { if (await b.evaluate('window.__walkMarker === undefined && document.readyState === "complete"')) return; } catch { /* mid-navigation */ }
  }
  throw new Error(`no navigation after: ${action}`);
}
const go = (url) => navigates(`location.assign(${JSON.stringify(url)})`);
const submit = (formSelector, values) => navigates(`(() => { const f = document.querySelector(${JSON.stringify(formSelector)});
  for (const [k, v] of Object.entries(${JSON.stringify(values)})) f.querySelector('[name="' + k + '"]').value = v;
  f.requestSubmit(); })()`);
const title = () => evaluate('document.querySelector("h1")?.textContent.trim() ?? ""');
const bodyTokens = () => evaluate('(document.body.innerText.match(/mzn_[A-Za-z0-9_-]{43}/g) || []).length');

try {
  await page('Page.navigate', { url: `${BASE}/login` });
  await sleep(500);
  await evaluate('document.readyState === "complete"');
  await submit('form[action$="/login"]', { email: EMAIL, password: PASSWORD });
  const challenged = await evaluate('!!document.querySelector("input[name=code]")');
  if (challenged) await submit('form:has(input[name=code])', { code: totp(SECRET) });
  const signedIn = !(await evaluate('location.pathname')).startsWith('/login') && !(await evaluate('!!document.querySelector("input[name=code]")'));
  row('sign-in', signedIn, `two-factor challenge ${challenged ? 'answered' : 'not shown'}; now at ${await evaluate('location.pathname')}`);
  if (!signedIn) throw new Error('not signed in');

  await go(`${BASE}/admin/tokens`);
  const asked = (await title()) === 'Confirm your password';
  if (asked) await submit('form:has(input[name=password])', { password: PASSWORD });
  row('confirm', asked && (await title()) === 'Seat tokens', `password asked first: ${asked}; then "${await title()}"`);

  const pattern = await evaluate(`(() => { const i = document.getElementById('install_id'), was = i.value;
    i.value = 'Not A Slug'; const bad = i.validity.patternMismatch; i.value = 'good-slug'; const good = i.validity.patternMismatch;
    i.value = was; return { bad, good, pattern: i.getAttribute('pattern') }; })()`);
  row('pattern', pattern.bad === true && pattern.good === false, `pattern ${pattern.pattern}: "Not A Slug" mismatch=${pattern.bad}, "good-slug" mismatch=${pattern.good}`);

  const seat = `uat-${randomBytes(3).toString('hex')}`;
  documents.length = 0;
  await submit('form[action$="/admin/tokens"]', { install_id: 'uat', seat_id: seat });
  const issuedDoc = documents.find((d) => d.url.endsWith('/admin/tokens'));
  const shown = await evaluate(`(() => { const el = document.getElementById('seat-token-plaintext'); const t = el ? el.textContent.trim() : '';
    return { length: t.length, shape: /^mzn_[A-Za-z0-9_-]{43}$/.test(t), prefix: t.slice(0, 12) }; })()`);
  const prefix = shown.prefix;
  row('issue', shown.shape && (await bodyTokens()) === 1 && /no-store/.test(issuedDoc?.cacheControl ?? ''),
    `uat / ${seat}: token of ${shown.length} chars shown once, prefix ${prefix}; response ${issuedDoc?.status} Cache-Control "${issuedDoc?.cacheControl}"`);

  // The save, judged inside the page: only booleans, the keys, the ingest URL and the ids come back out.
  const buttons = await evaluate('document.querySelectorAll("[data-seat-token-save]").length');
  if (HTTP_SITE) {
    row('save', buttons === 0, `--http-site: ${buttons} save button(s) on the issue response, none expected`);
  } else if (buttons !== 1) {
    row('save', false, `${buttons} save button(s) on the issue response, one expected on an https site (pass --http-site if app.url is http:)`);
  } else {
    requests.length = 0;
    await evaluate('document.querySelector("[data-seat-token-save]").click()');
    for (let i = 0; i < 40 && !(await evaluate('window.__saved?.text != null')); i += 1) await sleep(50);
    const saved = await evaluate(`(() => {
      const s = window.__saved, l = window.__savedLink, b = document.querySelector('[data-seat-token-save]');
      if (!s || s.text == null || !l) return { built: !!s, linked: !!l };
      const token = document.getElementById('seat-token-plaintext').textContent.trim();
      const text = s.text, lines = text.split('\\n');
      const body = lines.slice(0, -1).map((line) => { const at = line.indexOf(' '); return [line.slice(0, at), line.slice(at + 1)]; });
      const keys = body.map(([k]) => k), value = Object.fromEntries(body);
      return {
        built: true, linked: true, type: s.type, download: l.download, sameHref: l.href === s.href, keys,
        noCr: !text.includes('\\r'), endsLf: text.endsWith('\\n') && lines.at(-1) === '',
        noTrailingSpace: lines.every((line) => !/[ \\t]$/.test(line)), oneSpace: body.every(([k, v]) => k && v && !/\\s/.test(v)),
        tokenIsShown: value.token === token && /^mzn_[A-Za-z0-9_-]{43}$/.test(token),
        ingestUrl: value.ingest_url, buttonUrl: b.dataset.ingestUrl, installId: value.install_id, seatId: value.seat_id,
        tokenInUrl: location.href.includes(token.slice(4)),
      };
    })()`);
    const keysOnce = JSON.stringify(saved.keys) === JSON.stringify(['token', 'ingest_url', 'install_id', 'seat_id']);
    const urlOk = saved.ingestUrl === saved.buttonUrl && /^https:\/\/[^\s/]+(\/\S*)?\/api\/ingest\/events$/.test(saved.ingestUrl ?? '');
    const sent = requests.filter((u) => !u.startsWith('blob:') && !u.startsWith('data:'));
    const tokenInDocUrl = await evaluate(`[${documents.map((d) => JSON.stringify(d.url)).join(',')}].some((u) => u.includes(document.getElementById('seat-token-plaintext').textContent.trim().slice(4)))`);
    row('save', saved.built && saved.linked && saved.type === 'application/octet-stream' && saved.download === 'mezzanine-reporter-token'
      && saved.sameHref && keysOnce && saved.noCr && saved.endsLf && saved.noTrailingSpace && saved.oneSpace && saved.tokenIsShown
      && urlOk && saved.installId === 'uat' && saved.seatId === seat && sent.length === 0 && !saved.tokenInUrl && !tokenInDocUrl,
      `blob built ${saved.built}, linked ${saved.linked}; type "${saved.type}", download "${saved.download}"; keys ${JSON.stringify(saved.keys)}; `
      + `LF-only ${saved.noCr}, ends LF ${saved.endsLf}, no trailing space ${saved.noTrailingSpace}, one space per line ${saved.oneSpace}; `
      + `token = the shown one ${saved.tokenIsShown}; ingest_url ${saved.ingestUrl} (button ${saved.buttonUrl}); ids ${saved.installId} / ${saved.seatId}; `
      + `requests sent by the click ${sent.length}; token in a URL ${saved.tokenInUrl || tokenInDocUrl}`);
  }

  // The page's restore handler, driven by a SYNTHETIC `pageshow` with `persisted` true: the issue response is a
  // POST's, which Chromium reports as not restorable from the back/forward cache (`HTTPMethodNotGET`), so the `bfcache` row
  // below does not reach it there. This measures what the handler does, not whether a browser restores the page.
  const onRestore = await evaluate(`(() => { dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    const t = document.getElementById('seat-token-plaintext')?.textContent.trim() ?? null;
    return { blanked: t !== null && !/mzn_/.test(t), buttons: document.querySelectorAll('[data-seat-token-save]').length }; })()`);
  row('restore', onRestore.blanked && onRestore.buttons === 0,
    `synthetic pageshow(persisted): token element blanked ${onRestore.blanked}; save buttons left ${onRestore.buttons}`);

  const rowsFor = () => evaluate(`[...document.querySelectorAll('tbody tr')].filter((r) => r.textContent.includes('uat / ${seat}')).length`);
  documents.length = 0;
  await navigates('location.reload()');
  const reloadAlert = await evaluate('document.querySelector("[role=alert]")?.textContent.trim() ?? ""');
  row('reload', /already used or is out of date/.test(reloadAlert) && (await bodyTokens()) === 0 && (await rowsFor()) === 1,
    `documents ${documents.map((d) => `${d.status} ${new URL(d.url).pathname}`).join(' → ')}; alert "${reloadAlert.slice(0, 70)}…"; token rows for the seat: ${await rowsFor()}`);

  // Back to the issue response itself: issue again, leave, press Back.
  await go(`${BASE}/admin/tokens`);
  const seat2 = `${seat}-b`;
  await submit('form[action$="/admin/tokens"]', { install_id: 'uat', seat_id: seat2 });
  const second = await bodyTokens();
  await go(`${BASE}/admin/agents`);
  restored = null;
  documents.length = 0;
  await navigates('history.back()');
  const persisted = await evaluate('window.__persisted');
  const afterBack = await bodyTokens();
  const blanked = await evaluate('document.getElementById("seat-token-plaintext")?.textContent.trim() ?? null');
  const saveButtons = await evaluate('document.querySelectorAll("[data-seat-token-save]").length');
  row('back', second === 1 && afterBack === 0,
    `issue response showed ${second} token; after Back: ${afterBack} tokens, documents ${documents.map((d) => `${d.status} ${new URL(d.url).pathname}`).join(' → ') || 'none fetched'}, showing ${await evaluate('location.href')} "${(await evaluate('document.body.innerText')).replace(/\s+/g, ' ').trim().slice(0, 90)}"`);
  if (persisted === true) row('bfcache', blanked !== null && !/mzn_/.test(blanked) && saveButtons === 0, `restored from the back/forward cache; token element reads "${blanked}"; save buttons ${saveButtons}`);
  else row('bfcache', null, `pageshow.persisted=${persisted}; ${restored ? `not restored: ${restored.reasons.join(', ') || 'no reason given'}` : 'no restore reported'}`);

  await go(`${BASE}/admin/tokens`);
  await submit(`form[action$="/admin/tokens/${prefix}/revoke"]`, {});
  const status = await evaluate('document.querySelector("[role=status]")?.textContent.trim() ?? ""');
  const record = await evaluate(`[...document.querySelectorAll('tbody tr')].find((r) => r.textContent.includes('${prefix}'))?.textContent.replace(/\\s+/g, ' ').trim() ?? ''`);
  row('revoke', status.startsWith(`Revoked ${prefix}`) && record.includes(`by ${EMAIL}`),
    `status "${status}"; row "${record.slice(0, 160)}"`);
} catch (e) {
  row('walk', false, e.message);
} finally {
  b.close();
}

const failed = rows.filter((r) => r.ok === false);
console.log(failed.length ? `\n${failed.length} row(s) FAILED` : '\nevery measured row passed');
process.exit(failed.length ? 1 : 0);
