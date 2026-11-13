/**
 * The floor picture's two pure functions (`building/floor-picture.js`), under `node` — plan § 8 item 3's *the rest*:
 * `tokenNames()` finds every token the painter's style reads, in both forms, and `pictureSvg()` writes each resolved one
 * once on the picture's root, leaves an empty one out, and sizes the root in place of the painter's percentages.
 * Driven by `Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest` over the shipped tree or a mutated copy
 * (argv[2], the module tree's `building/` directory); prints `{ legs: { picture: [defects], interval: [defects], isolation: [defects] }, printed }`
 * — `interval` the per-floor minimum interval's gate (`pictureGate()`), under a stub clock, and `isolation` one floor's
 * raster failing inside `floorPictures()`'s loop, over stand-ins for the document, the painter and the rasteriser.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that the browser draws the picture with the palette it carries — the browser leg reads the
 * page's own canvas for that (`tools/design/building-view.browser.mjs`).
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const P = await import(pathToFileURL(join(dir, 'floor-picture.js')).href);
const { STYLE } = await import(pathToFileURL(join(dir, '..', 'floor', 'painter.js')).href);

const defects = [];
const check = (what, ok) => {
    if (!ok) {
        defects.push(what);
    }
};

// tokenNames(): both forms, each name once, and a name read bare even once is not a fallback-form token.
const names = P.tokenNames('a{fill:var(--ink)}b{fill:var(--paper, #fff)}c{stroke:var( --x)}d{fill:var(--ink)}e{fill:var(--mixed,red)}f{fill:var(--mixed)}');
const byName = new Map(names.map((t) => [t.name, t.hasFallback]));

check(`tokenNames() over the control sheet answered ${JSON.stringify(names)}`, names.length === 3 && byName.get('ink') === false && byName.get('paper') === true && byName.get('mixed') === false);

// Over the painter's own style: the bare tokens the sheet test holds to `:root`, and the cab's fallback-form carriers.
const style = P.tokenNames(STYLE);
const bare = new Set([...STYLE.matchAll(/var\(--([a-z0-9_-]+)\)/g)].map((m) => m[1]));
const fallback = new Set([...STYLE.matchAll(/var\(--([a-z0-9_-]+),/g)].map((m) => m[1]));

check('tokenNames() over the painter\'s style finds no token', style.length > 0);
check('CONTROL — the painter\'s style reads no fallback-form token, so the fallback branch reads nothing', fallback.size > 0);

for (const name of bare) {
    check(`tokenNames() misses the painter's bare token --${name}, or marks it a fallback`, style.some((t) => t.name === name && t.hasFallback === false));
}

for (const name of [...fallback].filter((n) => !bare.has(n))) {
    check(`tokenNames() misses the painter's fallback-form token --${name}`, style.some((t) => t.name === name && t.hasFallback === true));
}

check('tokenNames() names a token twice', new Set(style.map((t) => t.name)).size === style.length);

// pictureSvg(): the root sized, each non-empty token once on its style, an empty one left out, the body untouched.
const painted = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" class="floor-scene" viewBox="0 0 10 20"><rect class="chip" width="100%"/></svg>';
const out = P.pictureSvg(painted, [{ name: 'ink', value: '#3b2f2a' }, { name: 'gone', value: '' }, { name: 'quote', value: 'a"b' }], 1784, 864);
const root = /^<svg\b[^>]*>/.exec(out)?.[0] ?? '';

check(`pictureSvg()'s root is not sized ${'1784 × 864'}: ${root}`, /\swidth="1784"/.test(root) && /\sheight="864"/.test(root) && !root.includes('100%'));
check(`pictureSvg()'s root does not carry each token once: ${root}`, (root.match(/--ink:#3b2f2a/g) ?? []).length === 1 && (root.match(/--quote:a&quot;b/g) ?? []).length === 1);
check(`pictureSvg() writes an empty token: ${root}`, !root.includes('--gone'));
check('pictureSvg() changed the drawing under its root', out.endsWith('<rect class="chip" width="100%"/></svg>'));

// A card's title carrying characters XML does not allow — the browser serialises them bare — leaves the picture well-formed:
// each removed, and the characters XML does allow (a tab, a newline, an astral one) kept.
const titled = P.pictureSvg('<svg width="100%" height="100%"><text>a\u000bb\u0000c\u001bd\ufffee\ud800f\tg\nh\u{1F600}i</text></svg>', [], 10, 10);

check(`pictureSvg() leaves a character XML does not allow in the picture: ${JSON.stringify(titled)}`, titled.endsWith('<text>abcdef\tg\nh\u{1F600}i</text></svg>'));

// The per-floor minimum interval (the seat's ruling of 2026-11-10): renders every 100 ms over a window, under a stub
// clock, paint each floor at most once per interval — and a render inside an interval leaves the floor due when it ends.
const interval = [];
const WINDOW_MS = 10000;
const gate = P.pictureGate();
const paints = new Map([['f0', 0], ['f1', 0]]);

for (let t = 0; t <= WINDOW_MS; t += 100) {
    for (const key of paints.keys()) {
        if (gate.due(key, t)) {
            gate.painted(key, t);
            paints.set(key, paints.get(key) + 1);
        }
    }
}

const allowed = Math.floor(WINDOW_MS / P.PICTURE_MIN_INTERVAL_MS) + 1;

for (const [key, n] of paints) {
    if (n > allowed) {
        interval.push(`${key} painted ${n} times in a ${WINDOW_MS}-ms window of renders every 100 ms — more than the ${P.PICTURE_MIN_INTERVAL_MS}-ms interval allows (${allowed})`);
    } else if (n < 2) {
        interval.push(`CONTROL — ${key} painted ${n} time(s) in the window, so the count measured no interval`);
    }
}

const later = P.pictureGate();

later.painted('f0', 0);

if (later.due('f0', 500)) {
    interval.push('a render 500 ms after a paint repainted the floor inside its interval');
}

if (later.next('f0') !== P.PICTURE_MIN_INTERVAL_MS || !later.due('f0', later.next('f0'))) {
    interval.push(`a render inside the interval leaves the floor due at ${later.next('f0')}, and due there: ${later.due('f0', later.next('f0'))} — its latest state would never be painted`);
}

// One floor's raster failing (the review's G1): `floorPictures()`'s loop over a stand-in document, painter and serialiser,
// with a rasteriser that rejects for one floor on its FIRST paint and for another on a LATER one. The other floors are still
// painted, nothing escapes `paint()`, a render inside the interval does not throw, and each failure is one recorded line.
const isolation = [];
let clock = 0;

Object.defineProperty(performance, 'now', { value: () => clock, configurable: true });

const element = () => {
    const node = { dataset: {}, hidden: false, children: [], width: 0, height: 0 };

    node.append = (...nodes) => node.children.push(...nodes);
    node.remove = () => {};
    node.querySelector = () => node.svg ?? null;

    return node;
};
const document = { createElement: element, defaultView: { getComputedStyle: () => ({ getPropertyValue: () => '' }) }, documentElement: {} };
const failing = new Set();
const rastered = [];
const records = [];
const pictures = P.floorPictures(document, element(), {
    characters: null, themes: {}, house: null, clock: { now: () => 0 }, failed: () => {}, wake: () => {}, record: (line) => records.push(line),
}, {
    paintWith: ({ host }) => ({ paint: (scene) => { host.svg = scene === null ? null : { version: scene.version }; } }),
    serialise: (svg) => `<svg>${svg.version}</svg>`,
    rasterise: async (canvas, svg, width, height) => {
        const floor = canvas.dataset.floor;

        if (failing.has(floor)) {
            throw new Error(`planted: ${floor}'s raster fails`);
        }

        canvas.width = width;
        canvas.height = height;
        rastered.push(floor);
    },
});
const drawn = (version) => ['f0', 'f1', 'f2'].map((floor) => ({ row: { floor }, scene: { version, extent: { x: 0, y: 0, w: 100, h: 50 } } }));
const round = async (what, version) => {
    rastered.length = 0;

    try {
        return await pictures.paint(drawn(version), () => 1, 4096);
    } catch (error) {
        isolation.push(`${what}: paint() threw — ${error.message}`);

        return null;
    }
};

// The first paint: f1's raster fails. f0 and f2 are still rasterised, and f1 has no picture.
failing.add('f1');

const first = await round('a first paint whose f1 raster fails', 1);

if (first !== null) {
    if (JSON.stringify(rastered) !== '["f0","f2"]') {
        isolation.push(`a first paint whose f1 raster fails rasterised ${JSON.stringify(rastered)}, not f0 and f2`);
    }

    if (first.get('f1')?.canvas !== null) {
        isolation.push('a floor whose first raster failed answers a picture');
    }
}

// Inside the interval: no floor is repainted and nothing throws — f1 answers its (absent) last picture.
clock += 100;
await round('a render inside the interval after a failed first paint', 2);

// A later paint: f1 recovers and f2's raster now fails — f2 keeps its last picture, f0 and f1 are painted.
failing.clear();
failing.add('f2');
clock += P.PICTURE_MIN_INTERVAL_MS;

const afterwards = await round('a later paint whose f2 raster fails', 3);

if (afterwards !== null) {
    if (JSON.stringify(rastered) !== '["f0","f1"]') {
        isolation.push(`a later paint whose f2 raster fails rasterised ${JSON.stringify(rastered)}, not f0 and f1`);
    }

    if (afterwards.get('f2')?.canvas === null || afterwards.get('f2')?.changed !== false) {
        isolation.push('a floor whose later raster failed does not keep its last picture, unchanged');
    }
}

// The failed floor retries at its next due render, its drawing unchanged: the failure left `last` as it was.
failing.clear();
clock += P.PICTURE_MIN_INTERVAL_MS;
await round('the next due render', 3);

if (!rastered.includes('f2')) {
    isolation.push('the floor whose raster failed was not rasterised again at its next due render');
}

if (records.length !== 2) {
    isolation.push(`the two failures recorded ${records.length} line(s), not one each: ${JSON.stringify(records)}`);
}

process.stdout.write(JSON.stringify({ legs: { picture: defects, interval, isolation }, printed: { painter_tokens: style.length, fallback_form: style.filter((t) => t.hasFallback).length, paints: Object.fromEntries(paints) } }));
