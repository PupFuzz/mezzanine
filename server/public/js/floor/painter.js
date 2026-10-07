/**
 * THE PAINTER — `docs/design/FLOOR.md` Appendix B row 14's DOM half: it turns `floor/scene.js`'s
 * scene into one SVG drawing surface and reports back which assets failed to load.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Where every tile, desk element, bubble, line and § 6.2 form lands is
 * the scene's, read headlessly by AT-D3-19 and AT-D3-20; there is no browser on the build host, so
 * nothing here has been laid out or rasterised by a check. What IS checked is that the element it
 * addresses exists on the floor view (`FloorPageWiringTest`), that the asset route serves the
 * two modules it imports (`TheArtRouteServesOnlyWhatTheGatesJudgedTest`, which reads the specifiers
 * below out of this file), and — over a fake DOM — the nodes it writes: each desk's at its layout
 * element (`TheNewDeskKeepsEveryLeafTest`, `painter-probe.mjs`), and each tile as itself, in order,
 * identical neighbours as one primitive (`TheFloorsTilesDrawWithoutSeamsTest`, `tile-seams-probe.mjs`).
 * A rule found here that is not in the scene is in the wrong file.
 *
 * ⛔ ONE SPACE: SVG (§ 13 row 15 leaves the surface to the builder). Tiles, desks, nameplates,
 * bubbles and the thread line are drawn into one `<svg>` whose `viewBox` is the camera's view
 * (`wire/camera.js`, Appendix B row 15) over the scene's space, so the camera scales them together — the lesson `docs/design/floor-preview/README.md`
 * records of a bubble no gate measured. SVG is resolution-independent, which is § 4.5's property;
 * the bridge tileset's raster tiles resample, and that is their residue (§ 10.3), not the layer's. The
 * characters do not: each frame is a standalone SVG document behind an `<image>` (§ 10.2, card#11046),
 * which the browser draws as vector at every zoom — measured, not assumed (§ 10.2).
 *
 * ⛔ THE TWO ART MODULES ARE IMPORTED BY THE ASSET ROUTE's ABSOLUTE URLS, DYNAMICALLY. A relative
 * specifier from `server/public/js/` to `resources/` resolves on disk and 404s in a browser (row 14's
 * ⚠), and a STATIC import that failed would take the whole floor page down with it — the text desk
 * list included — which is the empty office § 9 exists against. A failed import is reported as the
 * failed asset it is (§ 9 F14) and the page keeps every fact.
 *
 * ⛔ THE ONE TIMER HERE IS A HELD LOOP's OWN FRAMES, at the interval the scene carries (§ 6.3's
 * second bullet: "except a state-held loop's own frames at the fixed rate"), and it runs only for a
 * character whose held render the animation set drew WITH motion. Decorative motion is the
 * stylesheet's, at the cycle the scene states.
 */

import { BUBBLE_PAD, FONT, FONT_NAME, FONT_SCREEN, STOOL_GLYPH, TYPE_ROLES, characterAsset } from './desk-layout.js';
import { SKY_PAINT } from './floor-layout.js';
import { ELEVATOR_DOOR } from './scene.js';
import { RENDER_STATES } from '../lobby/render-state.js';

/** The furniture box — `resources/floor/furniture-box.js`, by the asset route. */
export const FURNITURE_MODULE = '/art/floor/furniture-box.js';

/** The character tree's entry — `resources/characters/index.js`, by the asset route. */
export const CHARACTER_TREE = '/art/characters/index.js';

/** The theme registry — `resources/floor/themes/index.js`, by the asset route (FLOOR.md § 10.6 item 1). */
export const THEME_REGISTRY = '/art/floor/themes/index.js';

/** A theme's module — `resources/floor/themes/<name>/theme.js`, by the asset route (§ 10.6 item 1). */
export const themeModule = (name) => `/art/floor/themes/${encodeURIComponent(name)}/theme.js`;

const SVG = 'http://www.w3.org/2000/svg';

/** § 10.4's art contract: a desk's art is drawn in proportion, centred, on its rect's floor line. */
const MEET = 'xMidYMax meet';

/** A text's type role when its element names none — `fit()`'s own default (`desk-layout.js`). */
const FACT = 'fact';

/**
 * A character frame's URI: the character tree hands back a standalone SVG DOCUMENT and the painter alone
 * makes it a URI — `data:image/svg+xml;charset=utf-8,` over `encodeURIComponent` (§ 10.2), so no file in
 * the asset tree carries one (§ 10.1 clause 2).
 */
export const svgUri = (doc) => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(doc)}`;

/**
 * The drawing's own stylesheet — which palette token each class paints with, and the § 6.2 forms'
 * keyframes, placed inside the SVG so the drawing carries it wherever the camera takes it. ⛔ NO COLOUR
 * IS WRITTEN HERE: every one is a `var(--…)` token whose value is `public/css/mezzanine.css`'s `:root`,
 * the palette's one home (card#11045; `ThePageChromeIsOneLinkedStylesheetTest` reds on a token that
 * sheet does not declare, and on a hex colour here). Presentation only: every duration, reach and
 * step count is the scene's, set per element; nothing here decides WHETHER anything moves.
 */
export const STYLE = `
text{font:${FONT};fill:var(--scene-ink)}text.role-name{font:${FONT_NAME}}text.role-screen{font:${FONT_SCREEN}}
.wall{fill:url(#house-wall)}.wall-top{stop-color:var(--house-wall)}.wall-bottom{stop-color:var(--house-wall-2)}
.slab{fill:var(--house-trim)}
.window{fill:var(--scene-glass)}
${Object.keys(SKY_PAINT).map((phase) => `.sky-${phase}{fill:url(#sky-${phase})}`).join('')}
.elevator-leaf{fill:var(--door);stroke:var(--door-edge);stroke-width:2}.elevator-seam{stroke:var(--door-frame);stroke-width:2}
.plane{fill:var(--scene-floor)}
.clock-face{fill:var(--scene-clock-face);stroke:var(--scene-trim);stroke-width:3}.clock.unset .clock-face{stroke-dasharray:6 4}
.clock.cased .clock-face{fill:none;stroke:none}.clock.cased.unset .clock-face{stroke:var(--scene-trim)}
.hand{stroke:var(--scene-ink);stroke-linecap:round}.hand.hour{stroke-width:4}.hand.minute{stroke-width:2}
.monitor{fill:var(--scene-monitor)}.monitor.lit-on{fill:var(--scene-monitor-on)}.monitor.lit-dimmed{fill:var(--scene-monitor-dim)}
.t-monitor-text.lit-on{fill:var(--state-ink)}.t-monitor-text.lit-dimmed{fill:var(--scene-screen-ink-dim)}
.placeholder{fill:var(--scene-placeholder);stroke:var(--scene-placeholder-edge);stroke-dasharray:4 3}
.stool{fill:var(--scene-stool)}.stool.untitled{fill:none;stroke:var(--scene-stool);stroke-dasharray:3 2}
.intern-edge{fill:none;stroke:var(--scene-stool);stroke-width:1.5;stroke-dasharray:3 2}
.lag-overlay{fill:url(#hatch);opacity:.6}.hatch{fill:var(--scene-trim)}
.badge{fill:var(--scene-badge);stroke:var(--scene-badge-edge)}
${RENDER_STATES.map((state) => `.chip.state-${state}{fill:var(--state-${state});stroke:var(--state-${state})}`).join('')}
.chip.unconfirmed{fill:var(--scene-paper);stroke-width:1.5;stroke-dasharray:3 2}.chip.unrecognised{fill:var(--scene-paper);stroke:var(--scene-unrecognised);stroke-width:1.5;stroke-dasharray:3 2}
.t-chip{fill:var(--state-ink)}.t-chip.unconfirmed{fill:var(--state-ink-unconfirmed)}.t-chip.unrecognised{fill:var(--scene-unrecognised)}
.flag{fill:var(--scene-flag);stroke:var(--scene-flag-edge)}.t-flag{fill:var(--scene-flag-ink)}
.plate{fill:var(--scene-plate);stroke:var(--scene-plate-edge)}
.facts-plate{fill:var(--scene-plate);stroke:var(--scene-plate-edge);stroke-opacity:.35}
.gauge-track{fill:var(--scene-gauge-track)}.gauge-fill{fill:var(--scene-gauge-fill)}
.bubble{fill:var(--scene-paper);stroke:var(--scene-trim)}.bubble-trail{fill:var(--scene-paper);stroke:var(--scene-trim)}.bubble-source{fill:var(--scene-bubble-source)}
.lighting-dimmed{opacity:.72}.lighting-dark{opacity:.45}.lighting-desaturated{filter:saturate(.3)}
.thread{fill:none;stroke:var(--scene-thread);stroke-width:3}.thread.ended{stroke-dasharray:8 6;opacity:.6}
.thread.thread-moving{stroke-dasharray:4 8;stroke-linecap:round;animation-name:thread-flow;animation-iteration-count:infinite}
@keyframes thread-flow{from{stroke-dashoffset:12}to{stroke-dashoffset:0}}
.strip-header{font-weight:bold}
.decor{fill:var(--lamp);opacity:.25}.decor-moving{animation:decor ease-in-out infinite alternate}
@keyframes decor{from{opacity:.12}to{opacity:.34}}
.fx-ring{fill:none;stroke:var(--scene-thread);stroke-width:4;transform-box:fill-box;transform-origin:center;transform:scale(0)}
@keyframes ring-reach{from{transform:scale(0)}to{transform:scale(1)}}
@keyframes ring-fade{from{transform:scale(1);opacity:1}to{transform:scale(1);opacity:0}}
.fx-envelope{animation-name:travel;animation-fill-mode:forwards}
@keyframes travel{from{transform:translate(var(--from-x),var(--from-y)) rotate(var(--heading))}to{transform:translate(var(--to-x),var(--to-y)) rotate(var(--heading))}}
.fx-bead{transform:translate(var(--to-x),var(--to-y))}.envelope{fill:var(--scene-paper);stroke:var(--scene-thread)}
.fx-flash{fill:var(--scene-flash);opacity:0;animation-name:flash}
@keyframes flash{from{opacity:.8}to{opacity:0}}
.fx-walker{animation-name:walk;animation-fill-mode:both}.fx-walker-shown{opacity:0;animation-name:walker-shown;animation-fill-mode:none}
@keyframes walk{from{transform:translate(var(--from-x),var(--from-y))}to{transform:translate(var(--to-x),var(--to-y))}}
@keyframes walker-shown{from{opacity:1}to{opacity:1}}
.elevator-leaf.left{transform-box:fill-box;transform-origin:left center}.elevator-leaf.right{transform-box:fill-box;transform-origin:right center}
@keyframes leaf-open{from{transform:scaleX(1)}to{transform:scaleX(.12)}}
@keyframes leaf-close{from{transform:scaleX(.12)}to{transform:scaleX(1)}}
.fx-broadcast-marker{fill:none;stroke:var(--scene-thread);stroke-width:3}
`;

/**
 * The art modules, each or `null` with the failed asset id beside it: the furniture box, the character tree,
 * the theme registry — and every theme the registry names (FLOOR.md § 10.6 item 1), each under its own asset
 * id `theme:<name>`, so a theme whose import is rejected is § 9 F14's for the floors drawn in it (item 8).
 *
 * @returns {Promise<{furniture: object|null, characters: object|null, registry: object|null,
 *          themes: Object<string, object|null>, failed: list<string>}>}
 */
export async function loadArt() {
    const failed = [];
    const load = async (specifier, asset = specifier) => {
        try {
            return await import(specifier);
        } catch {
            failed.push(asset);

            return null;
        }
    };

    const [furniture, characters, registry] = await Promise.all([load(FURNITURE_MODULE), load(CHARACTER_TREE), load(THEME_REGISTRY)]);
    const names = registry === null ? [] : [...registry.THEMES];
    const modules = await Promise.all(names.map((name) => load(themeModule(name), `theme:${name}`)));

    return { furniture, characters, registry, themes: Object.fromEntries(names.map((name, i) => [name, modules[i]])), failed };
}

/**
 * The scene's `themes` input (FLOOR.md § 10.6 items 5 and 8) from what `loadArt()` held: the registry's
 * asset id — what § 9 F14's strip names when its import was rejected — the registry's names, house theme and
 * kinds, or `null`, and each named theme's module, a rejected one `null`. The page and the harness build it
 * through this one function.
 *
 * @param {object|null} registry the registry module, or `null`
 * @param {Object<string, object|null>} modules the theme modules by name
 */
export function themeInputs(registry, modules) {
    return Object.freeze({
        asset: THEME_REGISTRY,
        registry: registry === null ? null : Object.freeze({ names: registry.THEMES, house: registry.HOUSE_THEME, kinds: registry.KINDS }),
        modules: Object.freeze({ ...modules }),
    });
}

/**
 * The page's own text measurer — § 5.1 rule 4's measured box — answering in the TYPE ROLE it is asked
 * for (`desk-layout.js`'s `TYPE_ROLES`: the fact role, and the nameplate's name role, Q2), one canvas
 * font per role. A role the table does not declare is refused: a width measured in a guessed font is
 * the guess rule 4 forbids.
 */
export function measurer() {
    const contexts = new Map(Object.entries(TYPE_ROLES).map(([role, type]) => {
        const context = document.createElement('canvas').getContext('2d');

        context.font = type.font;

        return [role, { context, line: type.line }];
    }));

    return (text, role) => {
        const type = contexts.get(role);

        if (type === undefined) {
            throw new TypeError(`the measurer has no type role ${JSON.stringify(role)} — TYPE_ROLES declares ${[...contexts.keys()].join(', ')}`);
        }

        return { w: type.context.measureText(String(text)).width, h: type.line };
    };
}

function node(name, attrs = {}, parent = null) {
    const el = document.createElementNS(SVG, name);

    for (const [k, v] of Object.entries(attrs)) {
        if (v !== null && v !== undefined) {
            el.setAttribute(k, String(v));
        }
    }

    parent?.append(el);

    return el;
}

/**
 * The painter, over the floor view's own drawing element — `#floor-drawing`, which
 * `FloorPageWiringTest` holds to the view in both directions.
 *
 * @param {{characters: object|null, themes: Object<string, object|null>, failed: function(list<string>): void,
 *          select: function(string, string): void}} options `themes` the theme modules `loadArt()` held, by name
 */
export function createPainter({ characters, themes = {}, failed, select }) {
    const host = document.getElementById('floor-drawing');

    if (host === null) {
        throw new Error('the floor page has no #floor-drawing');
    }

    const reported = new Set();
    const report = (id) => {
        if (id !== null && !reported.has(id)) {
            reported.add(id);
            failed([id]);
        }
    };
    // THE ONE CACHE OF CHARACTER ART: asset → its frame URIs, made once per asset. The character tree
    // holds none (it is pure, `resources/characters/index.js`), so this Map is the whole of what a page
    // keeps. An intern's key is minted per dispatch, so an intern this paint no longer draws is dropped
    // from it (`interns` below) — that is what bounds the INTERNS' entries. A seat's entry is kept, as the
    // pixel tree's cache kept it: those are bounded by the distinct seats a page has drawn, not dropped.
    const frames = new Map();
    // The interns whose frames are held, and those this paint drew.
    const interns = new Set();
    let drawnInterns = new Set();
    // The characters a held loop or a walk steps this paint, each with its frames (never a copy of the
    // URIs on the element: a frame is kilobytes, and a tick would parse every one of them).
    let stepping = [];
    let loop = null;
    let svg = null;
    // THE ONE CACHE OF THEME DOCUMENTS (FLOOR.md § 10.6 item 2): a document's URI, KEYED ON ITS INPUTS — the
    // theme, the function and every input — and never on its asset id, because a map re-save changes a
    // plane's grid, slots and runs under the same asset id, and a cache keyed on the id would draw the old
    // room. An entry this paint did not draw is dropped after it, so the cache holds one floor's documents.
    const documents = new Map();
    let drawnDocuments = new Set();

    /**
     * Theme documents as image URIs, made ALL OR NONE (§ 10.6 item 8): every document of one failure unit —
     * a desk's furniture set, the band — is generated before any is drawn, so one throw yields that unit's
     * fallback and never a half-drawn set; the unit is reported under `asset`. A theme module the page does
     * not hold is that unit's failure too.
     *
     * @param {string} asset the unit's asset id
     * @param {string} theme the theme's name
     * @param {list<{fn: string, input: object}>} docs
     * @returns {list<string>|null} the URIs, in order, or `null` when the unit failed
     */
    const themeUris = (asset, theme, docs) => {
        const module = themes[theme] ?? null;
        const keys = docs.map((d) => JSON.stringify([theme, d.fn, d.input]));

        try {
            const uris = keys.map((key, i) => {
                if (!documents.has(key)) {
                    if (module === null || typeof module[docs[i].fn] !== 'function') {
                        throw new Error(`the theme ${theme} draws no ${docs[i].fn}`);
                    }

                    documents.set(key, svgUri(module[docs[i].fn](docs[i].input)));
                }

                return documents.get(key);
            });

            for (const key of keys) {
                drawnDocuments.add(key);
            }

            return uris;
        } catch {
            report(asset);

            return null;
        }
    };

    /**
     * A character's frames as image URIs, made once per asset: a seat's walk frames — stand, step-left,
     * step-right, the first being the standing frame every pose draws (§ 10.4's frame contract) — or an
     * intern's one chibi frame, the tree handed the intern key (`internKey()`) in the seat's place. A
     * tree that failed to load, or a generator that throws for this key, is § 9 F14 for this asset and
     * never a throw out of the paint.
     */
    const characterFrames = (installId, seatId, asset, intern = false) => {
        if (characters === null) {
            report(asset);

            return null;
        }

        if (!frames.has(asset)) {
            try {
                const docs = intern ? [characters.chibiFrame(installId, seatId)] : characters.walkFrames(installId, seatId);

                frames.set(asset, docs.map(svgUri));
            } catch {
                report(asset);

                return null;
            }
        }

        return frames.get(asset);
    };

    /** An image in its rect. Tiles stretch to their cell (`none`); the desk's art is `art()`'s. */
    const image = (parent, href, x, y, w, h, asset, extra = {}) => {
        const el = node('image', { href, x, y, width: w, height: h, preserveAspectRatio: 'none', ...extra }, parent);

        el.addEventListener('error', () => report(asset ?? href), { once: true });

        return el;
    };

    /**
     * A desk's art in its rect (§ 10.4's art contract): a CLIPPING VIEWPORT — a nested `<svg>` at the
     * element's rect, `overflow: hidden` — holding the image drawn `xMidYMax meet`, kept in proportion,
     * centred and standing on the rect's floor line. Whatever the art file draws past its own frame is
     * clipped at the rect the layout gave it, so no art reaches a neighbouring element or the box's edge.
     */
    const art = (parent, href, e, asset, cls) => {
        const port = node('svg', { x: e.x, y: e.y, width: e.w, height: e.h, viewBox: `0 0 ${e.w} ${e.h}`, overflow: 'hidden', class: 'art' }, parent);

        return image(port, href, 0, 0, e.w, e.h, asset, { class: cls, preserveAspectRatio: MEET });
    };

    /** A text at its type role's baseline, in its role's font (`TYPE_ROLES`; no role is the fact role). */
    const text = (parent, e, cls) => {
        const role = e.role ?? FACT;
        const el = node('text', {
            x: e.x,
            y: e.y + TYPE_ROLES[role].baseline,
            class: role === FACT ? cls : `${cls} role-${role}`,
            'data-id': e.id ?? null,
        }, parent);

        el.textContent = e.text;

        return el;
    };

    function paintDesk(layer, desk) {
        const g = node('g', {
            class: `desk lighting-${desk.lighting}${desk.placeholder ? ' placeholder' : ''}`,
            'data-key': desk.key,
            tabindex: 0,
            role: 'button',
            'aria-label': desk.seat_id,
        }, layer);

        g.addEventListener('click', () => select(desk.install_id, desk.seat_id));
        // A `role="button"` activates on Enter and on Space; Space's default would scroll the page.
        g.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                select(desk.install_id, desk.seat_id);
            }
        });

        // § 10.6 item 8: the desk's furniture set, generated whole before any of it is drawn; a throw leaves
        // the set undrawn and reported, and the next render draws the desk's placeholder.
        const furniture = desk.elements.filter((e) => e.doc !== undefined);
        const uris = furniture.length === 0 ? [] : themeUris(furniture[0].doc.set, furniture[0].doc.theme, furniture.map((e) => e.doc));
        const docOf = new Map(uris === null ? [] : furniture.map((e, i) => [e, uris[i]]));

        desk.elements.forEach((e, i) => {
            const id = `${desk.key}:${i}`;

            if (e.doc !== undefined) {
                // The chair's back, the desk's back, the monitor's frame, the desk props and the side table
                // with its seats: each its theme's document, at its rect, in a clipping viewport.
                if (docOf.has(e)) {
                    art(g, docOf.get(e), e, e.doc.set, e.kind);
                }

                return;
            }

            switch (e.kind) {
                case 'character': {
                    const urls = characterFrames(desk.install_id, desk.seat_id, e.asset);

                    if (urls !== null) {
                        const el = art(g, urls[0], e, e.asset, 'character');

                        if (e.animation?.motion === true && e.animation.frame_interval_ms !== null) {
                            stepping.push([el, urls]);
                        }
                    }

                    break;
                }
                case 'bar':
                case 'gauge-bar':
                    node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, class: 'gauge-track' }, g);
                    node('rect', { x: e.x, y: e.y, width: e.w * (e.pct / 100), height: e.h, class: 'gauge-fill' }, g);
                    break;
                case 'badge':
                case 'flag':
                    node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, rx: 3, class: e.kind }, g);
                    text(g, { ...e, x: e.x + e.text_dx, id }, e.kind === 'badge' ? 'badge-text' : 't-flag');
                    break;
                case 'chip': {
                    // The state's colour (§ 7.1, Q4 a) by its RECOGNISED member only: an unrecognised
                    // state's raw string is never a class on the desk (Q0); its chip is the hollow red one.
                    const look = [
                        e.unrecognised ? 'unrecognised' : `state-${e.render_state}`,
                        e.unconfirmed ? 'unconfirmed' : null,
                    ].filter(Boolean).join(' ');

                    node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, rx: 7, class: `chip ${look}` }, g);
                    text(g, { ...e, x: e.x + (e.w - e.text_w) / 2, id }, `t-chip ${look}`);
                    break;
                }
                case 'stool':
                    if (e.art) {
                        // § 8 / Q3: the intern's sprite in its clipping viewport — the tree's chibi frame
                        // under its own key, face-on, `xMidYMax meet`, foot on the rect's floor
                        // line; static, because an intern takes no § 6.2 row. An untitled intern is drawn
                        // dashed (§ 8): its sprite inside a dashed edge at its own 20 × 32 rect — rx 3, no
                        // fill, `--scene-stool` at 1.5 wide, dashed `3 2` — and its fallback glyph below is
                        // dashed `3 2` with no fill (the designer's rulings on card#11058 PR-C).
                        const urls = characterFrames(e.install_id, e.key, e.asset, true);

                        if (urls !== null) {
                            interns.add(e.asset);
                            drawnInterns.add(e.asset);
                            art(g, urls[0], e, e.asset, e.untitled ? 'intern untitled' : 'intern');

                            if (e.untitled) {
                                node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, rx: 3, class: 'intern-edge' }, g);
                            }
                        }
                    } else {
                        // § 9 F14's per-stool fallback: the glyph inside its rect, that stool alone.
                        node('rect', {
                            x: e.x + (e.w - STOOL_GLYPH) / 2,
                            y: e.y + e.h - STOOL_GLYPH,
                            width: STOOL_GLYPH,
                            height: STOOL_GLYPH,
                            class: e.untitled ? 'stool untitled' : 'stool',
                        }, g);
                    }
                    break;
                case 'facts-plate':
                    // § 10.6 (card#11468): the facts' one backdrop, painted after the art and before the facts on it.
                    node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, rx: e.rx, class: 'facts-plate' }, g);
                    break;
                case 'monitor':
                case 'placeholder':
                case 'plate':
                case 'lag-overlay':
                    node('rect', {
                        x: e.x,
                        y: e.y,
                        width: e.w,
                        height: e.h,
                        class: [e.kind, e.lit ? `lit-${e.lit}` : null, e.untitled ? 'untitled' : null].filter(Boolean).join(' '),
                    }, g);
                    break;
                default:
                    if (typeof e.text === 'string') {
                        // The monitor's text carries its lit state, so its ink is the screen's (§ 10.6).
                        text(g, { ...e, id }, e.lit ? `t-${e.kind} lit-${e.lit}` : `t-${e.kind}`);
                    }
            }
        });

        if (desk.bubble !== null) {
            const b = desk.bubble;

            // § 5.1 (card#11468): the thought trail, smallest first, then the cloud the scene traced round the rect.
            for (const c of b.trail) {
                node('circle', { cx: c.x, cy: c.y, r: c.r, class: 'bubble-trail' }, g);
            }

            node('path', { d: b.cloud.d, class: 'bubble' }, g);
            text(g, { x: b.x + BUBBLE_PAD, y: b.y + BUBBLE_PAD, text: b.text, id: `${desk.key}:bubble` }, 'bubble-text');

            if (b.second !== null) {
                text(g, { x: b.x + BUBBLE_PAD, y: b.y + BUBBLE_PAD + TYPE_ROLES[FACT].line, text: b.second, id: `${desk.key}:bubble2` }, 'bubble-source');
            }
        }
    }

    function paintEffects(layer, effects) {
        for (const fx of effects) {
            const seconds = (fx.frames * fx.frame_interval_ms) / 1000;
            // § 6.2's walk note item 8: an effect still in flight from an earlier render is drawn at the
            // frame it has reached — a negative delay — never restarted.
            const ago = ((fx.elapsed_frames ?? 0) * fx.frame_interval_ms) / 1000;
            const style = fx.frames > 0 ? `animation-duration:${seconds}s;animation-timing-function:steps(${fx.frames});animation-delay:${-ago}s` : null;

            if ((fx.animation_id === 'A1' || fx.animation_id === 'A2') && fx.frames > 0) {
                paintWalker(layer, fx, ago);

                continue;
            }

            if (fx.animation_id === 'A19') {
                const g = node('g', { class: fx.frames > 0 ? 'fx-envelope' : 'fx-bead', style }, layer);

                g.style.setProperty('--from-x', `${fx.from.x}px`);
                g.style.setProperty('--from-y', `${fx.from.y}px`);
                g.style.setProperty('--to-x', `${fx.to.x}px`);
                g.style.setProperty('--to-y', `${fx.to.y}px`);
                g.style.setProperty('--heading', `${fx.heading_deg}deg`);
                node('path', { d: 'M -8 -5 L 8 0 L -8 5 Z', class: 'envelope' }, g);
            } else if (fx.animation_id === 'A20') {
                // The operator's QA ruling, drawn as the scene counted it: the ring reaches the floor's
                // far corner over `reach_frames`, and only then fades over `fade_frames`.
                const reach = (fx.reach_frames * fx.frame_interval_ms) / 1000;
                const fade = (fx.fade_frames * fx.frame_interval_ms) / 1000;

                node('circle', {
                    cx: fx.at.x,
                    cy: fx.at.y,
                    r: fx.frames > 0 ? fx.radius : 10,
                    class: fx.frames > 0 ? 'fx-ring' : 'fx-broadcast-marker',
                    style: fx.frames > 0
                        ? `animation:ring-reach ${reach}s steps(${fx.reach_frames}) ${-ago}s forwards,ring-fade ${fade}s steps(${fx.fade_frames}) ${reach - ago}s forwards`
                        : null,
                }, layer);
            } else if (fx.at !== null && fx.at !== undefined && fx.frames > 0) {
                node('circle', { cx: fx.at.x, cy: fx.at.y, r: 12, class: `fx-flash fx-${fx.animation_id}`, style }, layer);
            }
        }
    }

    /**
     * One A1 or A2 walker (§ 6.2's walk note items 3–5): the seat's character in its walk frames, moving
     * along the scene's segment in the walk's frames and drawn only in its shown frames — the scene
     * decided every number, and `ago` is how far into the walk this paint lands.
     */
    function paintWalker(layer, fx, ago) {
        const sec = (frames) => (frames * fx.frame_interval_ms) / 1000;
        const shown = node('g', {
            class: 'fx-walker-shown',
            style: `animation-duration:${sec(fx.shown.frames)}s;animation-delay:${sec(fx.shown.start) - ago}s`,
        }, layer);
        const g = node('g', {
            class: 'fx-walker',
            style: `animation-duration:${sec(fx.walk.frames)}s;animation-timing-function:steps(${fx.walk.frames});animation-delay:${sec(fx.walk.start) - ago}s`,
        }, shown);

        g.style.setProperty('--from-x', `${fx.from.x}px`);
        g.style.setProperty('--from-y', `${fx.from.y}px`);
        g.style.setProperty('--to-x', `${fx.to.x}px`);
        g.style.setProperty('--to-y', `${fx.to.y}px`);

        const asset = characterAsset({ install_id: fx.install_id, seat_id: fx.seat_id });
        const urls = characterFrames(fx.install_id, fx.seat_id, asset);

        if (urls === null) {
            // § 9 F14: the art failed, so the walker is the placeholder rectangle at the character's size.
            node('rect', { x: -fx.size.w / 2, y: -fx.size.h, width: fx.size.w, height: fx.size.h, class: 'placeholder' }, g);

            return;
        }

        const el = image(g, urls[0], -fx.size.w / 2, -fx.size.h, fx.size.w, fx.size.h, asset, { class: 'character', preserveAspectRatio: MEET });

        stepping.push([el, urls]);
    }

    /**
     * The elevator's leaves (§ 6.2's walk note item 10): open while any walk in flight is in its door
     * frames — one open-then-close per merged span the scene carries, in frames from this paint.
     */
    function leafAnimation(doors, interval) {
        const sec = (frames) => (frames * interval) / 1000;

        return doors.flatMap((d) => [
            `leaf-open ${sec(ELEVATOR_DOOR.open)}s steps(${ELEVATOR_DOOR.open}) ${sec(d.from)}s forwards`,
            `leaf-close ${sec(ELEVATOR_DOOR.close)}s steps(${ELEVATOR_DOOR.close}) ${sec(d.to - ELEVATOR_DOOR.close)}s forwards`,
        ]).join(',');
    }

    /**
     * The camera's view onto the drawing — a navigation act re-sets the `viewBox` and re-draws
     * nothing, because a camera move is the viewer's head and never a fact (§ 4.5).
     */
    function view(camera) {
        if (svg !== null && camera.bounds !== null) {
            const v = camera.view;

            svg.setAttribute('viewBox', `${v.x} ${v.y} ${v.w} ${v.h}`);
        }
    }

    function paint(scene, camera) {
        if (loop !== null) {
            clearInterval(loop);
            loop = null;
        }

        if (scene === null) {
            host.replaceChildren();
            svg = null;

            return;
        }

        // Every paint rebuilds the drawing, so the desk the keyboard was on is replaced under it: note
        // its key while focus is inside the drawing, and put focus back on the rebuilt desk below.
        const focusedKey = host.contains(document.activeElement)
            ? document.activeElement.closest('[data-key]')?.getAttribute('data-key') ?? null
            : null;

        // A group and never an image: an image's children are presentational, and the desks inside are
        // buttons a screen reader and the keyboard must reach (§ 4.3's drill-down opens from one).
        svg = node('svg', { width: '100%', height: '100%', class: 'floor-scene', role: 'group', 'aria-label': 'the floor' });
        view(camera);
        node('style', {}, svg).textContent = STYLE;

        const pattern = node('pattern', { id: 'hatch', width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, node('defs', {}, svg));

        node('rect', { width: 3, height: 6, class: 'hatch' }, pattern);

        // The band's flat fallback fill, one gradient top to bottom — the stops' colours are tokens (the style
        // above), so the palette keeps its one home. It is drawn on every paint, under the theme's wall.
        const defs = pattern.parentNode;
        const gradient = node('linearGradient', { id: 'house-wall', x1: 0, y1: 0, x2: 0, y2: 1 }, defs);

        node('stop', { offset: 0, class: 'wall-top' }, gradient);
        node('stop', { offset: 1, class: 'wall-bottom' }, gradient);

        // The windows' sky, one gradient per phase — `floor-layout.js`'s `SKY_PAINT`, the one phase→paint
        // table the lobby's windows and backdrop read too (card#7343 r1): the reference's window gradient,
        // `top` to `bot`. The window's class names the phase (`sky-*` above), so the scene decides nothing new.
        for (const [phase, paint] of Object.entries(SKY_PAINT)) {
            const sky = node('linearGradient', { id: `sky-${phase}`, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);

            node('stop', { offset: 0, 'stop-color': paint.top }, sky);
            node('stop', { offset: 1, 'stop-color': paint.bot }, sky);
        }

        drawnDocuments = new Set();

        const band = scene.band;

        if (band !== null) {
            const g = node('g', { class: 'band' }, svg);
            // § 10.6 item 8: the band's documents — the wall, each window's surround, the elevator's surround
            // and the clock's case — are one unit, generated whole or not drawn at all.
            const docs = scene.band_docs === null ? null : themeUris(scene.band_docs.asset, scene.band_docs.theme, scene.band_docs.docs);
            const at = (i) => (docs === null ? null : { href: docs[i], rect: scene.band_docs.docs[i].rect, cls: scene.band_docs.docs[i].fn });
            const drawDoc = (d) => {
                if (d !== null) {
                    art(g, d.href, d.rect, scene.band_docs.asset, d.cls);
                }
            };

            node('rect', { x: band.x, y: band.y, width: band.w, height: band.h, class: 'wall' }, g);
            drawDoc(at(0));

            // The glazing and its sky (A17) are the painter's on every theme; each window's surround is drawn
            // over its glazing.
            band.windows.forEach((w, i) => {
                node('rect', { x: w.x, y: w.y, width: w.w, height: w.h, rx: 4, class: `window sky-${w.sky ?? 'unset'}` }, g);
                drawDoc(at(1 + i));
            });

            // The two-door elevator: its surround is the theme's, its leaves the painter's, in the building's
            // door colours, opening only inside an A1 or A2 walk (§ 4.2, card#9566).
            const e = band.elevator;
            const leaves = (scene.doors ?? []).length === 0 ? null : `animation:${leafAnimation(scene.doors, 1000 / scene.loop_fps)}`;

            drawDoc(at(1 + band.windows.length));
            node('rect', { x: e.x, y: e.y, width: e.seam - e.x - 1, height: e.h, rx: 3, class: 'elevator-leaf left', style: leaves }, g);
            node('rect', { x: e.seam + 1, y: e.y, width: e.x + e.w - e.seam - 1, height: e.h, rx: 3, class: 'elevator-leaf right', style: leaves }, g);
            node('line', { x1: e.seam, y1: e.y, x2: e.seam, y2: e.y + e.h, class: 'elevator-seam' }, g);

            // The clock: its case is the theme's; its hands and its unset treatment are the painter's (A17),
            // over a plain face that is what is left when the case is not drawn.
            const c = band.clock;
            const cx = c.x + c.w / 2;
            const cy = c.y + c.h / 2;
            const cased = docs !== null;

            drawDoc(at(2 + band.windows.length));

            const face = node('g', {
                class: ['clock', c.set ? null : 'unset', cased ? 'cased' : null].filter(Boolean).join(' '),
                role: 'img',
                'aria-label': c.set ? c.text : 'clock not set',
            }, g);

            node('circle', { cx, cy, r: c.w / 2, class: 'clock-face' }, face);

            if (c.set) {
                node('line', { x1: cx, y1: cy, x2: cx, y2: cy - c.w * 0.25, class: 'hand hour', transform: `rotate(${c.hour_angle_deg} ${cx} ${cy})` }, face);
                node('line', { x1: cx, y1: cy, x2: cx, y2: cy - c.w * 0.4, class: 'hand minute', transform: `rotate(${c.minute_angle_deg} ${cx} ${cy})` }, face);
            }
        }

        if (scene.slab !== null) {
            node('rect', { x: scene.slab.x, y: scene.slab.y, width: scene.slab.w, height: scene.slab.h, class: 'slab' }, svg);
        }

        // ⛔ THE FLOOR NEVER DRAWS A TILE's IMAGE (FLOOR.md § 10.6 item 6). Each grid in the scene's order — the
        // hallway's, then each room's — is its flat `--scene-floor` fill, its theme's plane over it (the floor,
        // the light, the accent region, the walls and the landing), then the standing pieces placed on it, each
        // its theme's document at its tile's cell rect, in tile order.
        const tiles = node('g', { class: 'tiles' }, svg);

        for (const plane of scene.planes) {
            node('rect', { x: plane.x, y: plane.y, width: plane.w, height: plane.h, class: 'plane' }, tiles);

            if (plane.doc !== null) {
                const uris = themeUris(plane.doc.asset, plane.doc.theme, [plane.doc]);

                if (uris !== null) {
                    art(tiles, uris[0], plane, plane.doc.asset, 'theme-plane');
                }
            }

            for (const piece of scene.scenery.filter((p) => p.room === plane.install_id)) {
                const uris = themeUris(piece.doc.asset, piece.doc.theme, [piece.doc]);

                if (uris !== null) {
                    art(tiles, uris[0], piece, piece.doc.asset, `scenery ${piece.kind}`);
                }
            }
        }

        for (const d of scene.decorative) {
            node('ellipse', {
                cx: d.glow.cx,
                cy: d.glow.cy,
                rx: d.glow.rx,
                ry: d.glow.ry,
                class: d.motion ? `decor decor-${d.decoration} decor-moving` : `decor decor-${d.decoration}`,
                style: d.motion ? `animation-duration:${d.cycle_ms / 1000}s` : null,
            }, tiles);
        }

        const lines = node('g', { class: 'coord' }, svg);

        for (const line of scene.lines) {
            node('polyline', {
                points: line.ends.map((p) => `${p.x},${p.y}`).join(' '),
                class: [line.ended ? 'thread ended' : 'thread', line.motion ? 'thread-moving' : null].filter(Boolean).join(' '),
                style: line.frames > 0
                    ? `animation-duration:${(line.frames * line.frame_interval_ms) / 1000}s;animation-timing-function:steps(${line.frames})`
                    : null,
            }, lines);

            if (line.label !== null) {
                text(lines, { x: line.label.x, y: line.label.y, text: line.label.text }, 'thread-label');
            }
        }

        const desks = node('g', { class: 'desks' }, svg);

        drawnInterns = new Set();
        stepping = [];

        for (const desk of scene.desks) {
            paintDesk(desks, desk);
        }

        for (const asset of interns) {
            if (!drawnInterns.has(asset)) {
                interns.delete(asset);
                frames.delete(asset);
            }
        }

        for (const key of documents.keys()) {
            if (!drawnDocuments.has(key)) {
                documents.delete(key);
            }
        }

        if (scene.strip !== null) {
            text(svg, scene.strip.header, 'strip-header');
        }

        paintEffects(node('g', { class: 'effects' }, svg), scene.effects);

        host.replaceChildren(svg);

        // The desk the keyboard was on, rebuilt; a desk the render removed leaves focus on the drawing
        // itself, where the camera's keys still reach, rather than dropping it to the page's body.
        // Known limit: focus lands on a freshly drawn desk at every repaint, so a screen reader may
        // announce the focused desk again after each update (untested with a screen reader). The
        // upstream fix is keyed reuse of desk nodes here — reconcile desks by `data-key` instead of
        // rebuilding the <svg> — which keeps the focused node itself and makes this restore unneeded.
        if (focusedKey !== null) {
            (svg.querySelector(`[data-key="${CSS.escape(focusedKey)}"]`) ?? host).focus({ preventScroll: true });
        }

        // § 6.2's held loops, at the interval the scene carries — one interval for the whole floor,
        // stepping every character whose render the set drew with motion.
        const moving = stepping;
        const walking = scene.effects.some((fx) => (fx.animation_id === 'A1' || fx.animation_id === 'A2') && fx.frames > 0);
        const interval = scene.desks.find((d) => d.held?.motion)?.held.frame_interval_ms ?? (walking ? 1000 / scene.loop_fps : null);

        if (moving.length > 0 && interval !== null) {
            let tick = 0;

            loop = setInterval(() => {
                tick = (tick + 1) % 3;

                for (const [el, urls] of moving) {
                    el.setAttribute('href', urls[tick]);
                }
            }, interval);
        }
    }

    /** The 1 s tick: the desks' text re-read, nothing else re-drawn (§ 2.5). */
    function refresh(scene) {
        if (svg === null || scene === null) {
            return;
        }

        for (const desk of scene.desks) {
            desk.elements.forEach((e, i) => {
                if (typeof e.text !== 'string') {
                    return;
                }

                const el = svg.querySelector(`[data-id="${CSS.escape(`${desk.key}:${i}`)}"]`);

                if (el !== null) {
                    el.textContent = e.text;
                }
            });
        }
    }

    return { paint, refresh, view };
}
