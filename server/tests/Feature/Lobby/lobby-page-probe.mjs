/**
 * THE LOBBY PAGE's OWN SCRIPT, STARTED — `lobby/main.js`, the module the page loads, run as the page runs it, over a
 * stand-in document carrying every element the page declares. `node`, no dependencies. Driven by
 * `Tests\Feature\Lobby\TheLobbyStartsBeforeItsRoomIsDrawnTest` (card#12319).
 *
 * argv: the lobby's module directory (the shipped `public/js/lobby`, or a mutated copy's). stdin: JSON
 * `{ "ids": [<every id the page declares>], "hidden": [<each id it ships hidden>], "surface": { "width": n, "height": n } }` — `surface` is what
 * `#lobby-building` measures WHILE THE ROOM's STYLE IS ON IT (`building-scene.js`'s `surfaceStyle()` sets an
 * `aspectRatio`). stdout: JSON — what the script threw at startup, the streams it opened and `#lobby-building`'s
 * `surfaceStyle()` members as each opened (card#12319 review F1: the un-drawn style put back after the startup's
 * measure, read before any render could re-write it), the reads it made, and the text the page shows after it.
 *
 * ⛔ THE ONE LAYOUT FACT THIS STAND-IN MODELS IS THE ONE card#12319 TURNED ON, AS MEASURED IN CHROMIUM: un-drawn,
 * `#lobby-building` holds only the empty camera's layer and has no height of its own, so it measures ZERO high at its
 * full width (1384 × 0 CSS px in a 1,400 × 1,000 window on the sandbox, read at `DOMContentLoaded` and again after
 * `load`). With the room's style on it, it measures `surface`. Every other element measures nothing, which no startup
 * path reads. ⚠ It is a model of the browser's layout, not the browser: `tools/design/floor-ride.browser.mjs`'s lobby
 * rows load the served page in headless Chromium and are the real-surface check.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleDir = process.argv[2];
const payload = JSON.parse(readFileSync(0, 'utf8') || '{}');
const surface = payload.surface ?? { width: 1384, height: 692 };

class FakeNode {
    constructor(tag, id = null) {
        this.tagName = tag.toUpperCase();
        this.id = id;
        this.children = [];
        this.parentElement = null;
        this.attrs = {};
        this.dataset = {};
        this.hidden = false;
        this.textContent = '';
        this.listeners = {};
        this.scrollTop = 0;
        this.scrollLeft = 0;
        const props = {};
        this.style = new Proxy(props, {
            get: (t, k) => (k === 'setProperty' ? (n, v) => { t[n] = v; } : k === 'removeProperty' ? (n) => { delete t[n]; } : t[k] ?? ''),
            set: (t, k, v) => { t[k] = v; return true; },
        });
        this.classList = { add() {}, remove() {}, toggle() {}, replace() {}, contains: () => false };
    }

    get clientWidth() {
        return this.id === 'lobby-building' ? surface.width : 0;
    }

    // The layout fact (see the header): the surface's height is the room style's, and nothing without it.
    get clientHeight() {
        return this.id === 'lobby-building' && this.style.aspectRatio !== '' ? surface.height : 0;
    }

    get firstElementChild() { return this.children[0] ?? null; }

    getBoundingClientRect() { return { left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0, x: 0, y: 0 }; }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    getAttribute(k) { return this.attrs[k] ?? null; }

    removeAttribute(k) { delete this.attrs[k]; }

    hasAttribute(k) { return Object.hasOwn(this.attrs, k); }

    toggleAttribute(k, on) { if (on) { this.attrs[k] = ''; } else { delete this.attrs[k]; } return on; }

    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }

    removeEventListener() {}

    #detach(node) {
        const p = node.parentElement;

        if (p !== null) {
            p.children.splice(p.children.indexOf(node), 1);
        }

        node.parentElement = this;
    }

    append(...nodes) { for (const n of nodes) { if (typeof n === 'object') { this.#detach(n); this.children.push(n); } } }

    appendChild(n) { this.append(n); return n; }

    prepend(...nodes) { for (const n of nodes.reverse()) { this.#detach(n); this.children.unshift(n); } }

    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }

    after(n) { const p = this.parentElement ?? root; p.#detach(n); p.children.splice(p.children.indexOf(this) + 1, 0, n); }

    remove() { this.parentElement?.children.splice(this.parentElement.children.indexOf(this), 1); this.parentElement = null; }

    matches() { return false; }

    closest() { return null; }

    querySelector() { return null; }

    querySelectorAll() { return []; }

    focus() {}

    showModal() {}

    close() {}
}

const root = new FakeNode('main', 'page');
const byId = new Map();

for (const id of payload.ids ?? []) {
    const node = new FakeNode('div', id);

    node.hidden = (payload.hidden ?? []).includes(id);
    root.append(node);
    byId.set(id, node);
}

// The document is flat but for what the startup path walks: each of the two boxes holds its face as its first child
// (`.lobby-face`, which `directory.js`'s `dockingEntry()` styles), as the page's markup does.
for (const box of ['lobby-directory', 'lobby-readings']) {
    byId.get(box)?.append(new FakeNode('div'));
}

// The page's `#lobby` carries the lobby's own route, as the Blade page writes it.
byId.get('lobby')?.dataset && (byId.get('lobby').dataset.lobbyHref = '/dashboard');

const html = new FakeNode('html');
const listeners = {};
const streams = [];
const reads = [];

globalThis.document = {
    documentElement: html,
    body: root,
    getElementById: (id) => byId.get(id) ?? null,
    createElement: (tag) => new FakeNode(tag),
    createElementNS: (ns, tag) => new FakeNode(tag),
    createTextNode: (text) => Object.assign(new FakeNode('#text'), { textContent: text }),
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener: () => {},
};

globalThis.window = globalThis;
globalThis.addEventListener = (type, fn) => { (listeners[type] ??= []).push(fn); };
globalThis.removeEventListener = () => {};
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });
globalThis.innerWidth = 1400;
globalThis.innerHeight = 1000;
globalThis.ResizeObserver = class { observe() {} disconnect() {} };
/** The surface's style members `building-scene.js`'s `surfaceStyle()` writes — the room's, or none. */
const SURFACE_MEMBERS = ['aspectRatio', 'maxHeight', 'overflow', 'position', 'cursor', 'backgroundColor'];
const surfaceStyleNow = () => Object.fromEntries(SURFACE_MEMBERS.map((k) => [k, byId.get('lobby-building')?.style[k] ?? null]));
/** `#lobby-building`'s style as each stream opened: after the startup's `surface()` reads, before any render. */
const styleAtStream = [];

globalThis.EventSource = class {
    constructor(url) { streams.push(String(url)); styleAtStream.push(surfaceStyleNow()); this.readyState = 0; }

    addEventListener() {}

    close() {}
};
// Every read stays pending: the run is about what the page OPENS at startup, not what it does with an answer.
globalThis.fetch = (path) => { reads.push(String(path)); return new Promise(() => {}); };

let threw = null;

try {
    await import(pathToFileURL(join(moduleDir, 'main.js')).href);
} catch (error) {
    threw = error instanceof Error ? error.message : String(error);
}

// The page's first render and every timer the startup set at 0 ms.
await new Promise((resolve) => setTimeout(resolve, 50));

const shown = (id) => {
    const node = byId.get(id);

    return node === undefined ? null : { text: node.textContent, hidden: node.hidden };
};

process.stdout.write(JSON.stringify({
    threw,
    streams,
    style_at_stream: styleAtStream,
    reads,
    start_failure: shown('lobby-start-failure'),
    feed: shown('lobby-feed'),
    totals: shown('lobby-totals'),
    floors: (byId.get('lobby-floors')?.children ?? []).map((li) => li.textContent),
}));

process.exit(0);
