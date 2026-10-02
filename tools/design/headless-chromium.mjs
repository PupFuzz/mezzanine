// HEADLESS CHROMIUM OVER THE DEVTOOLS PROTOCOL — the one launcher the hand-run browser tools in this
// directory share (`floor-preview.browser.mjs` its `findChrome()`; `lobby-label-contrast.browser.mjs` and `floor-chrome.browser.mjs` both). Node, no
// dependencies: Chromium is spawned with a DevTools port and driven over the platform's own WebSocket.
// Hoisted at its second caller (card#11045) rather than copied.

import { spawn } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

/** `--chrome <path>`, else `$CHROME`, else a Playwright-cached Chromium, else `null`. */
export function findChrome() {
  const i = process.argv.indexOf('--chrome');
  const named = (i === -1 ? null : process.argv[i + 1]) || process.env.CHROME;
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

/**
 * The page is ready when its `document.title` reads `ready` — each tool's page sets the title it waits on.
 */
export /** Headless Chromium over the DevTools protocol, one page, at a given VIEWPORT (never the surface's own size). */
async function browser(chrome, width, height, ready = 'drawn') {
  const proc = spawn(chrome, ['--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    '--remote-debugging-port=0', 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
  const wsUrl = await new Promise((resolve, reject) => {
    let err = '';
    const timer = setTimeout(() => reject(new Error('Chromium printed no DevTools endpoint')), 30000);
    proc.stderr.on('data', (d) => {
      err += d;
      const m = err.match(/DevTools listening on (ws:\/\/\S+)/);
      if (m) { clearTimeout(timer); resolve(m[1]); }
    });
    proc.on('exit', () => reject(new Error('Chromium exited before listening')));
  });
  const ws = new WebSocket(wsUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let seq = 0;
  const pending = new Map();
  ws.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    if (msg.id !== undefined && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(new Error(`${msg.error.message}`)); else resolve(msg.result);
    }
  };
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const page = (method, params) => send(method, params, sessionId);
  await page('Page.enable');
  await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
  const evaluate = async (expression) => {
    const r = await page('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(`in page: ${r.exceptionDetails.exception?.description ?? r.exceptionDetails.text}`);
    return r.result.value;
  };
  return {
    async load(url) {
      await page('Page.navigate', { url });
      for (let i = 0; i < 200; i += 1) {
        if ((await evaluate('document.title')) === ready) return;
        await new Promise((r) => setTimeout(r, 50));
      }
      throw new Error(`the page never drew: ${url}`);
    },
    evaluate,
    shot: async () => `data:image/png;base64,${(await page('Page.captureScreenshot', { format: 'png' })).data}`,
    close() { ws.close(); proc.kill(); },
  };
}
