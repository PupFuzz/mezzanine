/**
 * THE FLOOR's PICTURE — `docs/design/FLOOR.md § 10.4`'s *A third view*: each floor drawn by the floor page's own painter
 * into a hidden host, its nameplates left off, its palette inlined, rasterised onto a canvas the building view's slab
 * shows as its top face. Appendix B row 47 (card#13033 slice 4; `docs/design/plan-13033-3d-view.md` § 3.2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE PAINTER, NOT A SECOND RENDERER. Every floor is painted by `floor/painter.js`'s `createPainter()` — the floor
 * page's — with the one option the building view needs, `omit: {'nameplate'}`: the name is the label's over the stage
 * (`building/labels.js`), and a name in the picture under it would be the same string twice. The camera the picture is
 * painted at is `wire/camera.js`'s own fit over a surface of the texture's size, so the slab shows the extent *Fit the
 * floor* frames.
 *
 * ⛔ THE PALETTE IS INLINED, BECAUSE AN SVG DRAWN AS AN IMAGE SEES NO PAGE TOKEN (measured, plan § 3.2): every
 * `var(--…)` the painter's style names is resolved once off the page's `:root` and written on the picture's root, a token
 * that resolves to nothing left out so the painter's own fallback stands. `tokenNames()` and `pictureSvg()` are pure and
 * driven under `node` (`tests/Feature/Building/picture-probe.mjs`); `ThePageChromeIsOneLinkedStylesheetTest` reads the
 * sheet's tokens through `tokenNames()` too, so the picture and the sheet test hold one extraction.
 *
 * ⛔ A FLOOR's PICTURE IS REPAINTED AT MOST ONCE PER `PICTURE_MIN_INTERVAL_MS` (the seat's ruling of 2026-11-10, card#13033;
 * plan § 10's paint-time trigger, which fired at 300–1,800 ms a render on the pinned shell). A render inside a floor's
 * interval keeps that floor's last picture and asks for a render when the interval ends, so nothing is dropped and every
 * floor ends on its current state — an age or a state reaching the 3-D view up to the interval late; the floor page is the
 * live view. One interval for every floor and every cause (a focus change's scale included), decided by `pictureGate()`.
 *
 * ⛔ A PICTURE IS RE-RASTERISED ONLY WHEN ITS (SVG, SCALE) PAIR CHANGED — a correctness guard, not a cost bound: a staffed
 * desk's ages change the SVG on nearly every render (plan § 3.2). Each canvas is kept for its texture's life in
 * `#building-pictures` with `data-floor`, beside its painter's host: it is the texture's image, what a lost context's
 * restore re-uploads from (§ 7), and the page's own pixels the browser leg reads.
 */

import { STYLE, createPainter } from '../floor/painter.js';
import { createCamera, fit, frameOn } from '../wire/camera.js';
import { textureSize } from './stack-model.js';

/**
 * The custom properties a stylesheet text reads — `var(--name)` and the fallback form `var(--name, …)` — each once, with
 * whether every read of it carries a fallback (a token read bare even once must be declared somewhere).
 *
 * @param {string} css
 * @returns {list<{name: string, hasFallback: boolean}>}
 */
export function tokenNames(css) {
    const names = new Map();

    for (const [, name, after] of css.matchAll(/var\(--([a-z0-9_-]+)\s*([),])/gi)) {
        names.set(name, (names.get(name) ?? true) && after === ',');
    }

    return [...names].map(([name, hasFallback]) => Object.freeze({ name, hasFallback }));
}

const attribute = (value) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

/**
 * The picture's SVG text: the painter's serialised drawing with its root sized `width × height` in place of the
 * painter's `100%`s and every token whose value is not empty written on the root's `style`.
 *
 * @param {string} serialised the painter's `<svg>`, serialised
 * @param {list<{name: string, value: string}>} tokens each token's resolved value
 */
export function pictureSvg(serialised, tokens, width, height) {
    const open = /^<svg\b[^>]*>/.exec(serialised);

    if (open === null) {
        throw new Error('the painted drawing does not open with its <svg> root');
    }

    const style = tokens.filter((t) => t.value !== '').map((t) => `--${t.name}:${t.value}`).join(';');
    const root = open[0]
        .replace(/\swidth="[^"]*"/, '')
        .replace(/\sheight="[^"]*"/, '')
        .replace(/^<svg\b/, `<svg width="${width}" height="${height}" style="${attribute(style)}"`);

    return root + serialised.slice(open[0].length);
}

/** The least time between two paints of one floor's picture (see the header). */
export const PICTURE_MIN_INTERVAL_MS = 2000;

/**
 * Which floors may be repainted now: a floor never painted, or last painted at least `PICTURE_MIN_INTERVAL_MS` ago.
 * Pure over the times it is handed, so `node` drives it under a stub clock (`tests/Feature/Building/picture-probe.mjs`).
 *
 * @returns {{due: function(string, number): boolean, painted: function(string, number): void,
 *            next: function(string): number, forget: function(string): void}}
 */
export function pictureGate() {
    const last = new Map();

    return {
        due: (key, t) => !last.has(key) || t - last.get(key) >= PICTURE_MIN_INTERVAL_MS,
        painted: (key, t) => {
            last.set(key, t);
        },
        next: (key) => last.get(key) + PICTURE_MIN_INTERVAL_MS,
        forget: (key) => {
            last.delete(key);
        },
    };
}

/** A canvas holding the SVG text drawn at `width × height`, through an image of it. */
async function rasterise(document, canvas, svg, width, height) {
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));

    try {
        const image = new Image();

        image.src = url;
        await image.decode();

        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');

        context.clearRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);
    } finally {
        URL.revokeObjectURL(url);
    }
}

/**
 * The floors' pictures over `#building-pictures`: one hidden painter host and one canvas per floor, made as floors arrive.
 *
 * @param {Document} document
 * @param {HTMLElement} host `#building-pictures`
 * @param {{characters: object|null, themes: Object<string, object|null>, house: string|null, clock: object,
 *          failed: function(list<string>): void, wake: function(): void}} art what `floor/main.js` hands its own painter,
 *        but `ride` and `select`; and `wake`, the page's render request, asked when a floor's interval ends
 */
export function floorPictures(document, host, art) {
    // The page's palette, read once: the sheet keys no token on a media query (plan § 3.2 step 2).
    const page = document.defaultView.getComputedStyle(document.documentElement);
    const tokens = tokenNames(STYLE).map(({ name }) => ({ name, value: page.getPropertyValue(`--${name}`).trim() }));

    /** key → `{ painted, painter, canvas, last, answer, wake }` */
    const floors = new Map();
    const gate = pictureGate();

    const holder = (key) => {
        if (!floors.has(key)) {
            const painted = document.createElement('div');
            const canvas = document.createElement('canvas');

            painted.dataset.floorHost = key;
            canvas.dataset.floor = key;
            host.append(painted, canvas);
            floors.set(key, {
                painted,
                canvas,
                last: null,
                // The floor's last answer, kept inside its interval; the timer that ends the interval, or `null`.
                answer: null,
                wake: null,
                painter: createPainter({
                    host: painted,
                    omit: new Set(['nameplate']),
                    characters: art.characters,
                    themes: art.themes,
                    failed: art.failed,
                    select: () => {},
                    house: art.house,
                    clock: art.clock,
                }),
            });
        }

        return floors.get(key);
    };

    return {
        /**
         * Every floor painted and each whose (SVG, scale) changed rasterised — awaited, so the pictures are this render's.
         *
         * @param {list<{row: object, scene: object|null}>} drawn the building frame's floors
         * @param {function(string): number} scaleOf a floor's raster scale (`stack-model.js`'s `textureScale()`)
         * @param {number} maxTexture the renderer's `MAX_TEXTURE_SIZE`
         * @returns {Promise<Map<string, {canvas: HTMLCanvasElement|null, changed: boolean}>>} every floor, by key
         */
        async paint(drawn, scaleOf, maxTexture) {
            const out = new Map();
            const listed = new Set(drawn.map(({ row }) => row.floor));

            // A floor the layout no longer lists: its painter's host and its canvas go with it.
            for (const [key, floor] of [...floors]) {
                if (!listed.has(key)) {
                    clearTimeout(floor.wake);
                    gate.forget(key);
                    floor.painter.paint(null, null);
                    floor.painted.remove();
                    floor.canvas.remove();
                    floors.delete(key);
                }
            }

            for (const { row, scene } of drawn) {
                const floor = holder(row.floor);
                const now = performance.now();

                if (!gate.due(row.floor, now)) {
                    // Inside its interval: the last picture stands, and the floor is painted once the interval ends.
                    if (floor.wake === null) {
                        floor.wake = setTimeout(() => {
                            floor.wake = null;
                            art.wake();
                        }, gate.next(row.floor) - now);
                    }

                    out.set(row.floor, { canvas: floor.answer.canvas, changed: false });

                    continue;
                }

                gate.painted(row.floor, now);

                if (scene === null || scene.extent === null) {
                    floor.painter.paint(null, null);
                    floor.canvas.hidden = true;
                    floor.last = null;
                    floor.answer = { canvas: null, changed: false };
                    out.set(row.floor, floor.answer);

                    continue;
                }

                const scale = scaleOf(row.floor);
                const size = textureSize(scene.extent, scale, maxTexture);

                floor.painter.paint(scene, fit(frameOn(createCamera(size), scene.extent)));

                const serialised = new XMLSerializer().serializeToString(floor.painted.querySelector('svg'));
                const key = `${size.width}×${size.height} ${serialised}`;
                const changed = key !== floor.last;

                if (changed) {
                    await rasterise(document, floor.canvas, pictureSvg(serialised, tokens, size.width, size.height), size.width, size.height);
                    floor.last = key;
                }

                floor.canvas.hidden = false;
                floor.answer = { canvas: floor.canvas, changed };
                out.set(row.floor, floor.answer);
            }

            return out;
        },
    };
}
