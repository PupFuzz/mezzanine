/**
 * The probe `TheHarnessFetchIsNoWiderThanThePagesTest` drives the SHIPPED page fetch through —
 * `server/public/js/wire/live-page.js`'s `livePage().fetch`, the one both pages hand every consumer
 * of a response — and the shipped tileset loader over it (`floor/tileset.js`), or a mutated copy of
 * either. `node`, no dependencies, no DOM: the browser globals `live-page.js` reads are shimmed here,
 * and the global `fetch` answers with a real WHATWG `Response`, which is what the browser's does.
 *
 * argv[2] — the floor module directory (the shipped one, or a mutated copy's); `live-page.js` is read
 *           from `../wire/` beside it, as the page's own import resolves it.
 * stdin   — `{ "tilesets": [ { "url": "/art/floor/…", "status": 200, "text": "<the file>" } ] }`
 * stdout  — `{ page_members, harness_members, held, direct, renders }`:
 *           · `page_members` — the members of the response `livePage().fetch` resolves to;
 *           · `harness_members` — the members of the response `Support/scripted-fetch.mjs` resolves
 *             to, the fake every client probe drives the same consumers through;
 *           · `held` — url → what the loader holds over the page fetch: `{kind, failure, images}`;
 *           · `direct` — url → the images `readTileset` reads from the same text with no fetch at all;
 *           · `renders` — how many renders the page ran after the loader's reads, with nothing else
 *             asking for one.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const dir = process.argv[2];
const payload = JSON.parse(readFileSync(0, 'utf8'));
const served = new Map(payload.tilesets.map((t) => [t.url, t]));

// The browser globals `live-page.js` reads, and nothing more: it schedules on `window`, subclasses
// `EventSource` (never opened here — no stream is started), and calls the global `fetch`.
globalThis.window = globalThis;
globalThis.EventSource = class { addEventListener() {} };
globalThis.fetch = async (url) => {
    const entry = served.get(url);

    return entry === undefined
        ? new Response('', { status: 404 })
        : new Response(entry.text, { status: entry.status });
};

const { livePage } = await import(pathToFileURL(join(dir, '..', 'wire', 'live-page.js')).href);
const { TilesetLoader, readTileset } = await import(pathToFileURL(join(dir, 'tileset.js')).href);
const here = dirname(fileURLToPath(import.meta.url));
const { scriptedFetch } = await import(pathToFileURL(join(here, '..', 'Support', 'scripted-fetch.mjs')).href);

let renders = 0;
const page = livePage(async () => {
    renders++;
});

const members = (response) => Object.keys(response).sort();

// One answered request through each fetch; the member sets are read off the objects themselves.
const pageMembers = members(await page.fetch(payload.tilesets[0].url));
const harnessMembers = members(await scriptedFetch({ '/x': [{ status: 200, body: {} }] }).fetch('/x'));

// Let every render those reads asked for run, then count only the loader's own.
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
await settle();
await settle();
renders = 0;

const loader = new TilesetLoader(page.fetch);
await loader.load(payload.tilesets.map((t) => t.url));
await settle();
await settle();

const held = {};

for (const [url, h] of loader.held) {
    held[url] = h.tileset !== undefined
        ? { kind: 'tileset', failure: null, images: [...h.tileset.images].sort() }
        : { kind: h.pending ? 'pending' : 'failure', failure: h.failure ?? null, images: [] };
}

const direct = {};

for (const { url, text } of payload.tilesets) {
    try {
        direct[url] = [...readTileset(text, url).images].sort();
    } catch (error) {
        direct[url] = `${error.name}: ${error.message}`;
    }
}

console.log(JSON.stringify({
    page_members: pageMembers,
    harness_members: harnessMembers,
    held,
    direct,
    renders,
}, null, 2));
