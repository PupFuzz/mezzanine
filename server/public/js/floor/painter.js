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
 * bubbles and the coordination birds are drawn into one `<svg>` whose `viewBox` is the camera's view
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
 * ⛔ THE ELEVATOR IS A CONTROL (§ 4.5, card#11795): a `role="button"` group over its surround, wired as a
 * desk is — a click, Enter and Space each call the page's `ride` once — and `aria-disabled` while a ride is in
 * flight. Its leaves are the RIDE's while the scene carries `scene.ride` (`rideLeaves()`), each phase's door
 * motion delayed past its fade and placed at the phase's elapsed time so a re-paint neither restarts nor
 * reorders it; with no ride they are the walks' (`leafAnimation()`), as before. The cab's interior is
 * `paintCab()`'s (`floor/cab-paint.js` since card#12113, which both pages draw the cab with), into the cab dialog's own element: the room in depth `drawCabInterior()` draws (card#12051), and on
 * its back wall the door in the house theme's elevator palette, a plain dial, the view of the stop the doors open on
 * seen through it — the ride's `beyond`: the office (`drawOfficeView()`, card#12052) or the lobby (`drawLobbyView()`,
 * card#12113) — the two leaves in the building's door colours, and no text — the cab's words are
 * the page's (`floor/cab-panel.js`); beside the door, a decorative call plate that names, lights and does nothing
 * (card#12071). The band's leaves open onto the same room, seen from the landing, drawn behind them (card#12052).
 *
 * ⛔ THE ONE TIMER HERE IS A HELD LOOP's OWN FRAMES, at the interval the scene carries (§ 6.3's
 * second bullet: "except a state-held loop's own frames at the fixed rate"), and it runs only for a
 * character whose held render the animation set drew WITH motion, or while an A1 or A2 walker is in
 * flight — its stride and its place among the desks, an edge row's own bounded frames (card#12194).
 * Decorative motion is the stylesheet's, at the cycle the scene states.
 */

import { BUBBLE_PAD, FONT, FONT_NAME, FONT_SCREEN, STOOL_GLYPH, TYPE_ROLES, characterAsset } from './desk-layout.js';
import { SKY_PAINT } from './floor-layout.js';
import { ELEVATOR_DOOR } from './scene.js';
import { floorStackOrder, pointAt } from './route.js';
import { BIRD_LIFT, arcPath, birdShapes, birdhouseShapes, envelopeShapes, legShapes, ringShapes, standingLegShapes, stillNotes } from './bird.js';
import { RENDER_STATES } from '../lobby/render-state.js';
import { CAB_STYLE, LANDING, THEME_REGISTRY, cabTheme, clippedTo, drawCabInterior, node, paintCab, rideLeaves, themeModule } from './cab-paint.js';

// The cab's drawing is `cab-paint.js`'s since card#12113 (both pages draw it); its names stay readable from here.
export { CAB, CAB_STYLE, LANDING, LOBBY_VIEW, OFFICE, THEME_REGISTRY, cabLeaves, cabTheme, drawCabInterior, drawLobbyView, drawOfficeView, paintCab, rideLeaves, themeModule } from './cab-paint.js';

/** The furniture box — `resources/floor/furniture-box.js`, by the asset route. */
export const FURNITURE_MODULE = '/art/floor/furniture-box.js';

/** The character tree's entry — `resources/characters/index.js`, by the asset route. */
export const CHARACTER_TREE = '/art/characters/index.js';

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
 * card#12445: how small the portal bird is drawn as it vanishes into the birdhouse's hole, at the end of its hop in
 * (and the start of its hop out) — drawing only: the sheet's `hop` keyframes scale to it, and the hop's path ends
 * where, at this size, the bird's body is centred on the hole.
 */
const HOP_SCALE = 0.35;

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
.portal-hole{fill:var(--scene-glass)}
${Object.keys(SKY_PAINT).map((phase) => `.sky-${phase}{fill:url(#sky-${phase})}`).join('')}
.plane{fill:var(--scene-floor)}
.clock-face{fill:var(--scene-clock-face);stroke:var(--scene-trim);stroke-width:3}.clock.unset .clock-face{stroke-dasharray:6 4}
.clock.cased .clock-face{fill:none;stroke:none}.clock.cased.unset .clock-face{stroke:var(--scene-trim)}
.hand{stroke:var(--scene-ink);stroke-linecap:round}.hand.hour{stroke-width:4}.hand.minute{stroke-width:2}
.monitor{fill:var(--scene-monitor)}.monitor.lit-on{fill:var(--scene-monitor-on)}
.t-monitor-text.lit-on{fill:var(--state-ink)}
.placeholder{fill:var(--scene-placeholder);stroke:var(--scene-placeholder-edge);stroke-dasharray:4 3}
.stool{fill:var(--scene-stool)}.stool.untitled{fill:none;stroke:var(--scene-stool);stroke-dasharray:3 2}
.intern-edge{fill:none;stroke:var(--scene-stool);stroke-width:1.5;stroke-dasharray:3 2}
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
.strip-header{font-weight:bold}
.decor{fill:var(--lamp);opacity:.25}.decor-moving{animation:decor ease-in-out infinite alternate}
@keyframes decor{from{opacity:.12}to{opacity:.34}}
@keyframes ring-reach{from{transform:scale(0)}to{transform:scale(1)}}
@keyframes ring-fade{from{transform:scale(1);opacity:1}to{transform:scale(1);opacity:0}}
.fx-flash{fill:var(--scene-flash);opacity:0;animation-name:flash}
@keyframes flash{from{opacity:.8}to{opacity:0}}
.fx-walker{offset-rotate:0deg;animation-name:glide;animation-timing-function:linear;animation-fill-mode:both}.fx-walker-shown{opacity:0;pointer-events:none;animation-name:walker-shown;animation-fill-mode:none}
@keyframes glide{from{offset-distance:0%}to{offset-distance:100%}}
@keyframes walker-shown{from{opacity:1}to{opacity:1}}
${CAB_STYLE}
.fx-bird{offset-rotate:0deg;opacity:0;animation-name:fly;animation-timing-function:linear}
@keyframes fly{from{offset-distance:0%;opacity:1}to{offset-distance:100%;opacity:1}}
.wing-up{animation:flap-up steps(1) infinite}.wing-down{animation:flap-down steps(1) infinite}
@keyframes flap-up{0%{opacity:1}50%{opacity:0}}@keyframes flap-down{0%{opacity:0}50%{opacity:1}}
.fx-perched{opacity:0;animation-name:perched;animation-timing-function:linear}
@keyframes perched{from{opacity:1}to{opacity:1}}
.fx-hop{offset-rotate:0deg;opacity:0;animation-name:hop;animation-timing-function:ease-in-out}.fx-hop.hop-out{animation-direction:reverse}
@keyframes hop{from{offset-distance:0%;opacity:1;transform:scale(1)}70%{opacity:1}to{offset-distance:100%;opacity:0;transform:scale(${HOP_SCALE})}}
.fx-rest{opacity:0;animation-name:rest;animation-fill-mode:forwards}
@keyframes rest{from{opacity:1}88%{opacity:1}89%{opacity:.5}99%{opacity:.5}to{opacity:0}}
.fx-perch{animation-name:perch;animation-fill-mode:forwards}
@keyframes perch{from{opacity:1}99%{opacity:1}to{opacity:0}}
.fx-notes{transform-box:fill-box;transform-origin:center;transform:scale(0)}
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

/** The § 6.2 rows drawn as a walker (card#9566, card#12194). */
const isWalk = (fx) => fx.animation_id === 'A1' || fx.animation_id === 'A2';

/**
 * One continuous motion's identity across paints (card#12345, card#12194). A bird flight: its row, its post, its leg
 * and its two ends — what `scene.js`'s `travel()` drew it from, so two flights of one post (one per destination) stay
 * apart. A walk: its row, its seat and its causing row — one walk per seat per row, so a later walk of the same seat
 * never inherits a cancelled one's origin.
 */
function effectKey(fx) {
    if (isWalk(fx)) {
        return `${fx.animation_id} ${fx.install_id}/${fx.seat_id} ${fx.cause}`;
    }

    return `${fx.animation_id} ${fx.install_id} ${fx.cause} ${fx.leg ?? ''} ${fx.from.x},${fx.from.y}>${fx.to.x},${fx.to.y}`;
}

/**
 * The painter, over the floor view's own drawing element — `#floor-drawing`, which
 * `FloorPageWiringTest` holds to the view in both directions.
 *
 * @param {{characters: object|null, themes: Object<string, object|null>, failed: function(list<string>): void,
 *          select: function(string, string): void, ride: function(): void, house: string|null,
 *          clock: {now: function(): number}}} options `themes` the theme modules `loadArt()` held, by name; `ride`
 *          the elevator's press (§ 4.5); `house` the house theme's name, whose elevator palette the cab is drawn in;
 *          `clock` the page's clock, the one a bird's or a walker's `started_at` was read from (`pinEffects()`)
 */
export function createPainter({ characters, themes = {}, failed, select, ride = () => {}, house = null, clock = { now: () => Date.now() } }) {
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
    // THE ONE CACHE OF CHARACTER ART: asset and drawing → its frame URIs, made once each. The character tree
    // holds none (it is pure, `resources/characters/index.js`), so this Map is the whole of what a page
    // keeps. An intern's key is minted per dispatch, so an intern this paint no longer draws is dropped
    // from it (`interns` below) — that is what bounds the INTERNS' entries. A seat's entries are kept, as the
    // pixel tree's cache kept them: those are bounded by the distinct seats a page has drawn, each in at most
    // its walk and the drawings the tree has for poses (`poseFrames()`), not dropped.
    const frames = new Map();
    // The interns whose frames are held, and those this paint drew.
    const interns = new Set();
    let drawnInterns = new Set();
    // The characters a held loop or a walk steps this paint, each `[el, uris, loopFrom, offset, window]`: its frames,
    // where its held loop starts in them (`loopFrom`, § 10.4 item 4), the offset into that loop it steps from (the
    // seat's own for a held loop, 0 for a walk), and for a walker the window it strides in — `{ entry, from_ms, to_ms }`,
    // its walk leg's span in effect time against its pinned origin; a held loop has none and steps every tick — never a
    // copy of the URIs on the element: a frame is kilobytes, and a tick would parse every one of them.
    let stepping = [];
    let loop = null;
    let svg = null;
    // card#12345, card#12194: where on the document timeline each continuous motion in flight began — a bird's flight,
    // a walker's glide — keyed by the effect (`effectKey()`), so every rebuild of the drawing draws it from ONE origin
    // (`pinEffects()`). Bounded by the effects in flight: an effect this paint does not draw is dropped.
    let origins = new Map();
    // The continuous motions this paint drew, each `{ key, started_at, elapsed_ms, nodes, began }`: its start on the
    // page's clock, elapsed ms at this paint, its animated nodes, and its origin on the document timeline.
    let pinned = [];
    // This paint's origins as they are recorded, so two effects of one key in one paint share one.
    let starts = new Map();
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
     * A character's frames as image URIs with where its held loop starts in them, `{ uris, loopFrom }`, made once per
     * asset and drawing: a desk's frames for its pose — the tree's `poseFrames()`, `uris[0]` the pose's static form and
     * `uris[loopFrom ..]` its held loop (§ 10.4's frame contract: the sleeper's for `asleep` and the marker's pulse for a
     * pose with a marker, each its still ahead of the loop, the walk's for every other pose) — a walker's walk frames
     * (`at` null), or an intern's one chibi frame, the tree handed the intern key (`internKey()`) in the seat's place;
     * both of the last with `loopFrom` 0. `loopFrom`
     * is kept beside the URIs because a property on the list `.map()` returns is lost. The cache key is the asset with
     * the drawing it holds, so a desk asleep and its walker never share frames. A tree that failed to load, or a
     * generator that throws for this key, is § 9 F14 for this asset and never a throw out of the paint.
     *
     * ⛔ `species` IS THE BODY, AND IT IS IN THE CACHE KEY (card#12443). For a seat it is the seat object's delivered
     * `character.species`, handed to the tree's forced-species path; for an intern it is its seat's, which the tree
     * keeps the intern off. A frame cached under the asset alone would keep a desk in its old body after a snapshot
     * moved it. A species that is not a string — a seat object with no `character` — or one the tree does not hold
     * (a throw) is § 9 F14 for this asset: the placeholder, and never the key's own body.
     *
     * @param {{pose: string, glyph?: string, desk_top: number, clear?: object[]}|null} at a desk's pose, its § 7.1 glyph,
     *        its desk's top edge and the clear rects a sleeper's z's and a marker keep out of (`characterOverdraw()`), or
     *        `null`
     * @returns {{uris: string[], loopFrom: number}|null}
     */
    const characterFrames = (installId, seatId, asset, species, at = null, intern = false) => {
        if (characters === null || typeof species !== 'string') {
            report(asset);

            return null;
        }

        // `at.clear` is not in the key: a sleeper's and a marker's clear rects are `characterOverdraw()`'s of the one
        // furniture box and the character's size, the same for every desk, so the pose, its glyph and the desk's top edge
        // already name the drawing.
        // A layout whose clear rects differ by desk must add them here.
        const key = `${intern ? asset : at === null ? `${asset}#walk` : `${asset}#${at.pose}/${at.glyph ?? ''}@${at.desk_top}`}~${species}`;

        if (!frames.has(key)) {
            try {
                const drawn = intern ? { frames: [characters.chibiFrame(installId, seatId, species)], loopFrom: 0 }
                    : at === null ? { frames: characters.walkFrames(installId, seatId, species), loopFrom: 0 }
                        : characters.poseFrames(installId, seatId, { pose: at.pose, glyph: at.glyph ?? null, deskTop: at.desk_top, clear: at.clear, species });

                frames.set(key, { uris: drawn.frames.map(svgUri), loopFrom: drawn.loopFrom });
            } catch {
                report(asset);

                return null;
            }
        }

        return frames.get(key);
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

    /**
     * One element's text as painted — one text a line, each on its role's line below the last (a wrapped text, the
     * screen's, has more than one: card#12446) — in its class: the monitor's text carries its lit state, so its ink
     * is the screen's (§ 10.6). The paint and the 1 s refresh both draw through it.
     */
    const textLines = (parent, e, id) => {
        const cls = e.lit ? `t-${e.kind} lit-${e.lit}` : `t-${e.kind}`;

        return (e.lines ?? [e]).map((line, i) => text(parent, { ...e, id, y: e.y + i * TYPE_ROLES[e.role ?? FACT].line, text: line.text }, cls));
    };

    function paintDesk(layer, desk) {
        const group = node('g', {
            class: `desk lighting-${desk.lighting}${desk.placeholder ? ' placeholder' : ''}`,
            'data-key': desk.key,
            tabindex: 0,
            role: 'button',
            'aria-label': desk.seat_id,
        }, layer);

        group.addEventListener('click', () => select(desk.install_id, desk.seat_id));
        // A `role="button"` activates on Enter and on Space; Space's default would scroll the page.
        group.addEventListener('keydown', (event) => {
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

        // § 7.4's fade (card#12346): a run of elements the layout fades is drawn into ONE group at its opacity, so the
        // run is composited once — the faded character does not show the chair and the desk through it.
        let faded = null;

        desk.elements.forEach((e, i) => {
            const id = `${desk.key}:${i}`;

            if (e.opacity === undefined) {
                faded = null;
            } else if (faded === null) {
                faded = node('g', { class: 'fold-lag', opacity: e.opacity }, group);
            }

            const g = faded ?? group;

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
                    const drawn = characterFrames(desk.install_id, desk.seat_id, e.asset, e.species, { pose: e.pose, glyph: e.glyph, desk_top: e.desk_top, clear: e.clear });

                    if (drawn !== null) {
                        // § 6.2's per-seat loop phase (card#12187): a held render that MOVES starts at the seat's own
                        // offset into its LOOP — `uris[loopFrom ..]`, whose length is the loop's, never the list's — so
                        // two desks in one state never step in lockstep; one that does not move is `uris[0]`, the
                        // still, the canonical reduced-motion form, whatever its offset.
                        const { uris, loopFrom } = drawn;
                        const moves = e.animation?.motion === true && e.animation.frame_interval_ms !== null;
                        const offset = moves ? characters.loopOffset(desk.install_id, desk.seat_id, uris.length - loopFrom) : 0;
                        // § 10.4's frame contract (card#12187, card#12259): every character's art viewport is its
                        // overdraw, past the element's rect — a sleeper's z's stand above and beside its head, and a
                        // standing or walking body's tilted parts reach past the rect's side — never the rect itself.
                        const el = art(g, moves ? uris[loopFrom + offset] : uris[0], e.overdraw, e.asset, 'character');

                        if (moves) {
                            stepping.push([el, uris, loopFrom, offset]);
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
                        // line; static, because an intern takes no § 6.2 row. The viewport is the stool's
                        // overdraw (§ 10.4's frame contract item 3, card#12259): the rect widened to the row's
                        // pitch, so a tilted frame's ear or cap is not cut at the rect's side. An untitled
                        // intern is drawn dashed (§ 8): its sprite with a dashed edge at its own stool rect —
                        // rx 3, no fill, `--scene-stool` at 1.5 wide, dashed `3 2` — and its fallback glyph
                        // below is dashed `3 2` with no fill (the designer's rulings on card#11058 PR-C).
                        const drawn = characterFrames(e.install_id, e.key, e.asset, e.seat_species, null, true);

                        if (drawn !== null) {
                            interns.add(e.asset);
                            drawnInterns.add(e.asset);
                            art(g, drawn.uris[0], e.overdraw, e.asset, e.untitled ? 'intern untitled' : 'intern');

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
                        textLines(g, e, id);
                    }
            }
        });

        if (desk.bubble !== null) {
            const b = desk.bubble;

            // § 5.1 (card#11468): the thought trail, smallest first, then the cloud the scene traced round the rect.
            for (const c of b.trail) {
                node('circle', { cx: c.x, cy: c.y, r: c.r, class: 'bubble-trail' }, group);
            }

            node('path', { d: b.cloud.d, class: 'bubble' }, group);
            text(group, { x: b.x + BUBBLE_PAD, y: b.y + BUBBLE_PAD, text: b.text, id: `${desk.key}:bubble` }, 'bubble-text');

            if (b.second !== null) {
                text(group, { x: b.x + BUBBLE_PAD, y: b.y + BUBBLE_PAD + TYPE_ROLES[FACT].line, text: b.second, id: `${desk.key}:bubble2` }, 'bubble-source');
            }
        }

        return group;
    }

    /** card#12241's drawings, from `bird.js`'s shape lists — `[tag, attributes, children?]` — into `parent`. */
    function shapes(parent, list) {
        for (const [tag, attrs, children] of list) {
            const el = node(tag, attrs, parent);

            if (Array.isArray(children)) {
                shapes(el, children);
            }
        }

        return parent;
    }

    /**
     * The bird carrying its envelope, facing its heading (flipped when that points left) and drawn
     * `BIRD_LIFT` px above its flight's point. `flap` alternates the wing every loop frame at the floor's
     * rate; without it the wing is drawn still, down. `standing` (card#12445, the portal bird on the birdhouse's
     * perch): it stands on its longer legs, its feet on the perch, so it holds the envelope in its beak rather than under it.
     */
    function bird(parent, headingDeg, flapSeconds, beak = 'closed', standing = false) {
        const left = Math.abs(headingDeg) > 90;
        const g = node('g', { transform: `translate(0 ${-BIRD_LIFT})${left ? ' scale(-1 1)' : ''}` }, parent);

        shapes(node('g', { transform: standing ? 'translate(15 3) rotate(14) scale(0.8)' : 'translate(0 12)' }, g), envelopeShapes());
        shapes(g, standing ? standingLegShapes() : legShapes());

        if (flapSeconds === null) {
            shapes(g, birdShapes('down', beak));
        } else {
            shapes(node('g', { class: 'wing-up', style: `animation-duration:${flapSeconds}s` }, g), birdShapes('up', beak));
            shapes(node('g', { class: 'wing-down', style: `animation-duration:${flapSeconds}s` }, g), birdShapes('down', beak));
        }

        return g;
    }

    /**
     * card#12345, card#12194: a continuous motion drawn by a later paint continues from where the earlier paint drew it.
     * Every paint rebuilds the drawing, and a rebuilt CSS animation starts when the browser first styles it — a moment
     * that differs from paint to paint by however long each paint took — so a negative delay alone redraws the motion
     * from a new origin each time, and the bird or the walker jumps (the live floor measured several rebuilds of one
     * flight inside two seconds). So the first paint of an effect records, on the document timeline, the instant the
     * effect began (`origin()`), and every paint of it pins its nodes' animations to that instant (`pin()`): the motion
     * is one function of the timeline across every rebuild. Pinned once the drawing is in the document, where its
     * animations exist.
     *
     * That instant is the effect's own start (`started_at`, on the page's clock) carried onto the timeline by a LIVE
     * reading of both clocks — `performance.now()` is the document timeline's clock, read now. Never
     * `document.timeline.currentTime`: it holds the last frame's time for as long as a task runs, so a paint at the end
     * of a long task would date the effect up to that task's length early, before its row arrived, and the motion's
     * first drawn point would be that far along. The origin is computed whether or not the browser can pin, so the
     * walker's stride and its depth reconcile read one clock everywhere; a browser with no `getAnimations()`, or a
     * timeline with no time (an inactive document), pins nothing and keeps the negative delay alone (`canPin()`).
     */
    function origin(entry) {
        entry.began = origins.get(entry.key) ?? starts.get(entry.key) ?? performance.now() - (clock.now() - entry.started_at);
        starts.set(entry.key, entry.began);
        pinned.push(entry);

        return entry;
    }

    /** Whether this browser can pin an animation's start: `Element.getAnimations()` and a timeline with a time. */
    function canPin() {
        if (typeof host.getAnimations !== 'function' || !Number.isFinite(document.timeline?.currentTime)) {
            return false;
        }

        return true;
    }

    /**
     * One effect's nodes pinned to its origin. Each node's CSS delay already carries this paint's `elapsed_ms`, so the
     * start that makes the delayed animation read the timeline from `began` is `began + elapsed_ms`. Called for every
     * effect after the drawing is in the document (`pinEffects()`), and again for a walker whose node the depth
     * reconcile moved, because a re-inserted node's animations are new.
     */
    function pin(entry) {
        for (const el of entry.nodes) {
            for (const animation of el.getAnimations()) {
                animation.startTime = entry.began + entry.elapsed_ms;
            }
        }
    }

    function pinEffects() {
        origins = starts;

        if (!canPin()) {
            return;
        }

        for (const entry of pinned) {
            pin(entry);
        }
    }

    /**
     * Every § 6.2 effect this paint draws: the birds, the ring and the flashes into the `effects` layer, which has no
     * depth; each walker into `floor` — its floor element into the floor stack, its cab element into the band's cab.
     * Returns the walkers, for the stack's order and the loop's reconcile.
     */
    function paintEffects(layer, effects, floor) {
        const walkers = [];

        for (const fx of effects) {
            const seconds = (fx.frames * fx.frame_interval_ms) / 1000;
            // § 6.2's walk note item 8: an effect still in flight from an earlier render is drawn at the
            // frame it has reached — a negative delay — never restarted. A continuous motion — a bird's flight, a
            // walker's glide — is drawn at the instant it has reached instead (`elapsed_ms`, and `pinEffects()`).
            const ago = ((fx.elapsed_frames ?? 0) * fx.frame_interval_ms) / 1000;
            const style = fx.frames > 0 ? `animation-duration:${seconds}s;animation-timing-function:steps(${fx.frames});animation-delay:${-ago}s` : null;

            if (isWalk(fx)) {
                // A walk drawn without its motion (no route, no desk, no band — walk note item 7) has no legs and no frames.
                if (fx.frames > 0 && (fx.legs ?? []).length > 0) {
                    walkers.push(paintWalker(floor, fx));
                }

                continue;
            }

            if (fx.animation_id === 'A19' || fx.animation_id === 'A22') {
                // card#12241: the bird carries the envelope along its own arc, the wing alternating every
                // frame, and on touchdown sets the envelope down on the desk, where it rests and fades —
                // nothing persists. Reduced motion (§ 6.4): the still envelope at the desk for the moving
                // form's own duration, then gone (the operator: *"a still envelope that disappears after
                // some time"*). A22's portal bird is the same flight (`scene.js`'s `travel()`): to the
                // birdhouse's perch with no rest, where its reduced form is the still envelope at the
                // portal, or from the perch to the desk.
                const restAt = { x: fx.to.x + 14, y: fx.to.y - 4 };
                const rest = (n) => node('g', { transform: `translate(${restAt.x} ${restAt.y}) rotate(-8)`, ...n }, layer);

                if (fx.frames > 0) {
                    // card#12345 (the operator, 2026-10-25: *"It is a little too fast and is not smooth (the bird
                    // jumps locations)"*): the flight moves CONTINUOUSLY along its arc — `linear`, never `steps()` —
                    // so its last drawn position is the desk or the perch, and it is drawn at the exact instant it has
                    // reached (`elapsed_ms`), so a repaint mid-flight continues it rather than snapping it back to its
                    // frame's start. The bird is drawn only while it flies (`.fx-bird`'s opacity outside its run).
                    // Every part starts where the scene put it, from the effect's start, and `wait_frames` later.
                    const flown = fx.elapsed_ms / 1000;
                    const at = (frame) => ((fx.wait_frames + frame) * fx.frame_interval_ms) / 1000 - flown;
                    const flap = (2 * fx.frame_interval_ms) / 1000;
                    const g = node('g', {
                        class: 'fx-bird',
                        style: `animation-duration:${seconds}s;animation-delay:${at(fx.flight_start)}s;offset-path:path('${arcPath(fx.from, fx.to)}')`,
                    }, layer);

                    bird(g, fx.heading_deg, flap);

                    const animated = [g];

                    if (fx.perch !== null) {
                        // card#12445 (the operator, 2026-10-26): the portal bird STANDS on the birdhouse's perch for
                        // the ledge dwell — its feet on the peg (`perchPoint()`), the wing still — and hops between
                        // the perch and the hole: up into it, shrinking out of sight, going out; out of it onto the
                        // perch coming in (the same hop, reversed). The hop runs from where it stands to where, at
                        // `HOP_SCALE`, its body is centred on the hole. It faces the way it flies.
                        const p = fx.perch;
                        const frame = (n) => (n * fx.frame_interval_ms) / 1000;
                        const inside = { x: p.hole.x, y: p.hole.y + BIRD_LIFT * HOP_SCALE };
                        const ledge = node('g', { transform: `translate(${p.point.x} ${p.point.y})` }, layer);
                        const perched = node('g', { class: 'fx-perched', style: `animation-duration:${frame(p.dwell_frames)}s;animation-delay:${at(p.dwell_start)}s` }, ledge);
                        const hop = node('g', {
                            class: `fx-hop hop-${p.hop}`,
                            style: `animation-duration:${frame(p.hop_frames)}s;animation-delay:${at(p.hop_start)}s;offset-path:path('M ${p.point.x} ${p.point.y} L ${inside.x} ${inside.y}')`,
                        }, layer);

                        bird(perched, fx.heading_deg, null, 'closed', true);
                        bird(hop, fx.heading_deg, flap, 'closed', true);
                        animated.push(perched, hop);
                    }

                    if (fx.rest_frames > 0) {
                        const restS = (fx.rest_frames * fx.frame_interval_ms) / 1000;

                        animated.push(shapes(rest({ class: 'fx-rest', style: `animation-duration:${restS}s;animation-timing-function:steps(${fx.rest_frames});animation-delay:${at(fx.flight_start + fx.frames)}s` }), envelopeShapes()));
                    }

                    origin({ key: effectKey(fx), started_at: fx.started_at, elapsed_ms: fx.elapsed_ms, nodes: animated });
                } else {
                    shapes(rest({ class: 'fx-still' }), envelopeShapes());
                }
            } else if (fx.animation_id === 'A20') {
                // The operator's QA ruling, drawn as the scene counted it: the ring reaches the floor's
                // far corner over `reach_frames`, and only then fades over `fade_frames`. card#12241: the
                // bird perches at the sender's desk and SINGS — its beak open — and the ring is one ring of
                // notes. Reduced motion: the perched bird, beak open, with three still notes.
                const origin = fx.at;
                const perch = node('g', { transform: `translate(${origin.x} ${origin.y})` }, layer);

                if (fx.frames > 0) {
                    const reach = (fx.reach_frames * fx.frame_interval_ms) / 1000;
                    const fade = (fx.fade_frames * fx.frame_interval_ms) / 1000;

                    shapes(node('g', {
                        class: 'fx-notes',
                        style: `animation:ring-reach ${reach}s steps(${fx.reach_frames}) ${-ago}s forwards,ring-fade ${fade}s steps(${fx.fade_frames}) ${reach - ago}s forwards`,
                    }, perch), ringShapes(fx.radius));
                    bird(node('g', { class: 'fx-perch', style: `${style}` }, perch), 0, (2 * fx.frame_interval_ms) / 1000, 'open');
                } else {
                    // The class names the element and styles nothing: a stroke set here would be inherited by
                    // every bird shape that sets none of its own (the belly, eye, highlight and blush).
                    const still = bird(node('g', { class: 'fx-broadcast-marker' }, perch), 0, null, 'open');

                    shapes(still, stillNotes());
                }
            } else if (fx.at !== null && fx.at !== undefined && fx.frames > 0) {
                node('circle', { cx: fx.at.x, cy: fx.at.y, r: 12, class: `fx-flash fx-${fx.animation_id}`, style }, layer);
            }
        }

        return walkers;
    }

    /**
     * One A1 or A2 walker (§ 6.2's walk note items 3–5, card#12194): the seat's character, its feet on the scene's
     * routed path, as TWO elements — the scene decided every number, and `elapsed_ms` is how far into the walk this
     * paint lands. Neither is ever a pointer target (`.fx-walker-shown`'s `pointer-events:none`): the elevator under a
     * waiting walker and a desk it stands in front of keep every press.
     *
     * - THE FLOOR ELEMENT, in the floor stack: `g.fx-walker-shown` is drawn only through the walker's floor legs (its
     *   `walker-shown` run), and inside it `g.fx-walker` GLIDES the walk leg — `offset-distance` 0 → 100 % along the
     *   path, `linear`, `fill-mode: both`, so before the leg it stands at the path's first point and after it at its
     *   last, which is where every `stand` leg stands.
     * - THE CAB ELEMENT, in the band's cab behind the leaves, still at the threshold and drawn only through the `cab`
     *   leg — so the opening leaves reveal it and the closing ones cover it.
     *
     * The three animated groups are pinned together to one origin (`origin()`); the floor image strides only inside
     * the walk leg (its `stepping` window) and stands at its phase-0 frame outside it; the cab image never strides.
     */
    function paintWalker(floor, fx) {
        const I = fx.frame_interval_ms;
        const sec = (ms) => ms / 1000;
        const elapsed = fx.elapsed_ms ?? 0;
        const walk = fx.legs.find((l) => l.kind === 'walk');
        const onFloor = fx.legs.filter((l) => l.kind !== 'cab');
        const inCab = fx.legs.filter((l) => l.kind === 'cab');
        const span = (legs) => (legs.length === 0 ? null : {
            from: Math.min(...legs.map((l) => l.start)) * I,
            to: Math.max(...legs.map((l) => l.start + l.frames)) * I,
        });
        const shownStyle = (s) => `animation-duration:${sec(s.to - s.from)}s;animation-delay:${sec(s.from - elapsed)}s`;
        const floorSpan = span(onFloor);
        const cabSpan = span(inCab);
        const path = `M ${walk.points.map((p) => `${p.x} ${p.y}`).join(' L ')}`;
        const shown = node('g', { class: 'fx-walker-shown', style: shownStyle(floorSpan) }, floor.stack);
        const glide = node('g', {
            class: 'fx-walker',
            style: `offset-path:path('${path}');animation-duration:${sec(walk.frames * I)}s;animation-delay:${sec(walk.start * I - elapsed)}s`,
        }, shown);
        const cab = cabSpan === null || floor.cab === null ? null : node('g', {
            class: 'fx-walker-shown fx-walker-cab',
            style: shownStyle(cabSpan),
        }, floor.cab);
        const door = inCab[0]?.at ?? null;
        const inner = cab === null ? null : node('g', { transform: `translate(${door.x} ${door.y})` }, cab);
        const entry = origin({ key: effectKey(fx), started_at: fx.started_at, elapsed_ms: elapsed, nodes: cab === null ? [shown, glide] : [shown, glide, cab] });
        const asset = characterAsset({ install_id: fx.install_id, seat_id: fx.seat_id });
        const drawn = characterFrames(fx.install_id, fx.seat_id, asset, fx.species);

        if (drawn === null) {
            // § 9 F14: the art failed, so the walker is the placeholder rectangle at the character's size.
            for (const parent of [glide, inner].filter((p) => p !== null)) {
                node('rect', { x: -fx.size.w / 2, y: -fx.size.h, width: fx.size.w, height: fx.size.h, class: 'placeholder' }, parent);
            }
        } else {
            // § 10.4's frame contract (card#12259): the walker's image is the character's frame at its overdraw size,
            // centred on the feet — the standing frame is drawn in the widened frame, so the image is the same width
            // the desk's viewport is; `fx.size` stays the character's rect, the placeholder's size above.
            const at = (parent) => image(parent, drawn.uris[0], -fx.frame.w / 2, -fx.frame.h, fx.frame.w, fx.frame.h, asset, { class: 'character', preserveAspectRatio: MEET });
            const el = at(glide);
            const window = { entry, from_ms: walk.start * I, to_ms: (walk.start + walk.frames) * I };

            // A walk starts at its stand frame: A1's and A2's walks are the whole list, `loopFrom` 0, and take no loop
            // offset (§ 10.4 item 4); it strides only inside its walk leg. The cab's image is never stepped.
            stepping.push([el, drawn.uris, drawn.loopFrom, 0, window]);

            if (inner !== null) {
                at(inner);
            }
        }

        return { key: `${fx.install_id}/${fx.seat_id}`, fx, entry, node: shown };
    }

    /**
     * The elevator's leaves (§ 6.2's walk note item 10): open while any walk in flight is in its door
     * frames — one open-then-close per merged span the scene carries, in frames from this paint. Not while a
     * ride holds them (§ 4.5): then they are `rideLeaves()`'s, and a walk's span adds nothing.
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

        // The band's flat fallback fill, one gradient top to bottom — the stops' colours are tokens (the style
        // above), so the palette keeps its one home. It is drawn on every paint, under the theme's wall.
        const defs = node('defs', {}, svg);
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
        // The band's cab clip group, over the cab's room and under the leaves — where a walker in its `cab` legs is drawn.
        let cabLayer = null;

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
            // door colours — the ride's while a ride holds them (§ 4.5, card#11795), else opening only inside an
            // A1 or A2 walk (§ 4.2, card#9566). The elevator is a CONTROL: a button over its surround, which
            // rides (§ 4.5) — `aria-disabled` while a ride is in flight, when the screen refuses the press.
            const e = band.elevator;
            const riding = scene.ride !== null && scene.ride !== undefined;
            const leaves = riding
                ? rideLeaves(scene.ride)
                : (scene.doors ?? []).length === 0 ? null : `animation:${leafAnimation(scene.doors, 1000 / scene.loop_fps)}`;
            // The theme's surround first: the control — its leaves and its hit area — is drawn over it, so a press
            // anywhere on the surround reaches the control rather than the image under it.
            drawDoc(at(1 + band.windows.length));

            // ⛔ THE CAB BEHIND THE LEAVES (card#12052): `drawCabInterior()`'s room fitted to the doorway and clipped to it,
            // over the surround's recess and under the leaves, so whenever they open — a ride's or a walk's — the cab is
            // seen inside. It is scenery: drawn once with the band, still, and nothing in the scene says it is there. Its
            // colours are the dialog's cab's — the house theme's elevator palette (`cabPalette()`), the sheet's door frame
            // and lamp (`.band-cab`) with none.
            const palette = cabPalette();
            const inside = node('g', {
                class: 'band-cab',
                'aria-hidden': 'true',
                style: palette === null ? null : `--cab-frame:${palette.frame};--cab-lamp:${palette.lamp}`,
            }, g);
            const doorway = { x: e.x, y: e.y, w: e.w, h: e.h };

            cabLayer = clippedTo(inside, node('defs', {}, inside), doorway, 'band-cab');
            drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);

            const control = node('g', {
                class: 'elevator',
                'data-key': 'elevator',
                tabindex: 0,
                role: 'button',
                'aria-label': 'the elevator — ride to another floor',
                'aria-disabled': riding ? 'true' : null,
            }, g);

            control.addEventListener('click', () => ride());
            // A `role="button"` activates on Enter and on Space; Space's default would scroll the page.
            control.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    ride();
                }
            });

            // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.
            node('rect', { x: e.x, y: e.y, width: e.seam - e.x - 1, height: e.h, rx: 3, class: 'elevator-leaf left', style: leaves }, control);
            node('rect', { x: e.seam + 1, y: e.y, width: e.x + e.w - e.seam - 1, height: e.h, rx: 3, class: 'elevator-leaf right', style: leaves }, control);
            // The control's hit area is the surround — frame, header and call buttons — over everything in it.
            node('rect', { x: e.surround.x, y: e.surround.y, width: e.surround.w, height: e.surround.h, class: 'elevator-hit' }, control);

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

            // card#12241: THE BIRD PORTAL — the birdhouse on its bracket right of the clock, on every floor,
            // its hole painted with the windows' sky. Still: no idle motion, and nothing in the scene says
            // it is there but the frame (COORD-STORE.md § 6.1).
            if (band.portal !== undefined && band.portal !== null) {
                shapes(node('g', { class: 'portal', role: 'img', 'aria-label': 'the bird portal' }, g),
                    birdhouseShapes(band.portal, band.portal.sky));
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

        // ⛔ THE FLOOR STACK (§ 6.2's walk note item 9, card#12194): the desks in the scene's order — never re-sorted, so
        // § 10.4 item 3's draw order and the keyboard's tab order hold — with each walker's floor element placed among
        // them by its feet's depth (`floorStackOrder()`). Desk nodes are made here and never re-inserted afterwards.
        const desks = node('g', { class: 'desks' }, svg);

        drawnInterns = new Set();
        stepping = [];

        const deskNodes = new Map(scene.desks.map((desk) => [desk.key, paintDesk(desks, desk)]));

        for (const asset of interns) {
            if (!drawnInterns.has(asset)) {
                interns.delete(asset);

                // An intern's frames are held under its asset and the body it was kept off (`characterFrames()`).
                for (const key of [...frames.keys()].filter((k) => k.startsWith(`${asset}~`))) {
                    frames.delete(key);
                }
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

        pinned = [];
        starts = new Map();

        const effectsLayer = node('g', { class: 'effects' }, svg);
        const walkers = paintEffects(effectsLayer, scene.effects, { stack: desks, cab: cabLayer });
        const depths = scene.desks.map((desk) => ({ key: desk.key, depth_y: desk.depth_y }));
        const deskNode = (key) => deskNodes.get(key);

        // Each walker where its feet stand at this instant, before the drawing is in the document.
        stack(desks, depths, deskNode, walkers, performance.now(), false);

        host.replaceChildren(svg);
        pinEffects();

        // The desk the keyboard was on, rebuilt; a desk the render removed leaves focus on the drawing
        // itself, where the camera's keys still reach, rather than dropping it to the page's body.
        // Known limit: focus lands on a freshly drawn desk at every repaint, so a screen reader may
        // announce the focused desk again after each update (untested with a screen reader). The
        // upstream fix is keyed reuse of desk nodes here — reconcile desks by `data-key` instead of
        // rebuilding the <svg> — which keeps the focused node itself and makes this restore unneeded.
        if (focusedKey !== null) {
            (svg.querySelector(`[data-key="${CSS.escape(focusedKey)}"]`) ?? host).focus({ preventScroll: true });
        }

        // ⛔ ONE LOOP, ONE CLOCK (card#12194): § 6.2's held loops and the walkers' stride and depth, at the interval the
        // scene carries — one interval for the whole floor. Each character steps through its OWN held loop — a walker's
        // walk, a sleeper's pulse after its still — so a loop of one length never draws another drawing's frames, from the
        // offset it was pushed with: the seat's own for a held loop, 0 for a walk. The loop is `uris[loopFrom ..]`
        // (§ 10.4 item 4), so the still never steps. A walker strides only inside its walk leg and stands at its phase-0
        // frame outside it, its time `performance.now() − began` — the origin its pinned animations read.
        const moving = stepping;
        const walking = walkers.length > 0;
        const interval = scene.desks.find((d) => d.held?.motion)?.held.frame_interval_ms ?? (walking ? 1000 / scene.loop_fps : null);

        if ((moving.length > 0 || walking) && interval !== null) {
            let tick = 0;

            loop = setInterval(() => {
                tick += 1;

                const now = performance.now();

                for (const [el, uris, loopFrom, offset, window = null] of moving) {
                    if (window !== null && !strides(window, now)) {
                        el.setAttribute('href', uris[loopFrom]);

                        continue;
                    }

                    el.setAttribute('href', uris[loopFrom + ((tick + offset) % (uris.length - loopFrom))]);
                }

                // The depth reconcile: a walker whose place in the order changed is moved and re-pinned. A browser
                // that cannot pin moves none — a move would restart its glide with nothing to put it back.
                if (walking && canPin()) {
                    stack(desks, depths, deskNode, walkers, now, true);
                }
            }, interval);
        }
    }

    /** Whether a walker strides at `now`: inside its walk leg, on its pinned origin. */
    function strides(window, now) {
        const t = now - window.entry.began;

        return t >= window.from_ms && t < window.to_ms;
    }

    /**
     * Each walker's floor element placed in the floor stack where `floorStackOrder()` puts it at `now`: before the
     * desk or walker that follows it in that order. Only a walker whose next sibling is wrong is moved, from the back
     * of the order forward, so every walker ends immediately before its successor and the desks — never moved — keep
     * their order. A moved node is removed and re-inserted, which restarts its animations, so when the drawing is live
     * (`repin`) every move is followed by `pin()` (card#12194's design r3 MJ2).
     */
    function stack(layer, depths, deskNode, walkers, now, repin) {
        const order = floorStackOrder(depths, walkers.map((w) => ({ key: w.key, y: pointAt(w.fx, now - w.entry.began).y, walker: w })));
        const nodeOf = (o) => (o === undefined ? null : o.kind === 'desk' ? deskNode(o.key) : o.item.walker.node);

        for (let i = order.length - 1; i >= 0; i--) {
            if (order[i].kind !== 'walker') {
                continue;
            }

            const el = order[i].item.walker.node;
            const next = nodeOf(order[i + 1]);

            if (el.nextSibling !== next) {
                layer.insertBefore(el, next);

                if (repin) {
                    pin(order[i].item.walker.entry);
                }
            }
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

                const id = `${desk.key}:${i}`;
                const nodes = [...svg.querySelectorAll(`[data-id="${CSS.escape(id)}"]`)];
                const lines = e.lines ?? [e];

                if (nodes.length === 0) {
                    return;
                }

                if (nodes.length === lines.length) {
                    nodes.forEach((n, j) => {
                        n.textContent = lines[j].text;
                    });

                    return;
                }

                // A wrapped text whose line count moved with its words (an age ticking on the screen, card#12446):
                // its lines re-drawn where they stood.
                const parent = nodes[0].parentNode;
                const after = nodes[nodes.length - 1].nextSibling;

                for (const n of nodes) {
                    n.remove();
                }

                for (const n of textLines(parent, e, id)) {
                    parent.insertBefore(n, after);
                }
            });
        }
    }

    /** The house theme's elevator palette — `{frame, lamp}` — or `null` when that theme did not load. */
    function cabPalette() {
        return themes[house]?.PALETTE?.elevator ?? null;
    }

    return {
        paint,
        refresh,
        view,
        paintCab,
        // The page's dialog in this painter's house theme — `cabTheme()` with the palette this painter holds.
        cabTheme: (el) => cabTheme(el, cabPalette()),
    };
}
