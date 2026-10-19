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
 * ⛔ THE ELEVATOR IS A CONTROL (§ 4.5, card#11795): a `role="button"` group over its surround, wired as a
 * desk is — a click, Enter and Space each call the page's `ride` once — and `aria-disabled` while a ride is in
 * flight. Its leaves are the RIDE's while the scene carries `scene.ride` (`rideLeaves()`), each phase's door
 * motion delayed past its fade and placed at the phase's elapsed time so a re-paint neither restarts nor
 * reorders it; with no ride they are the walks' (`leafAnimation()`), as before. The cab's interior is
 * `paintCab()`'s, into the cab dialog's own element: the room in depth `drawCabInterior()` draws (card#12051), and on
 * its back wall the door in the house theme's elevator palette, a plain dial, the office seen through it
 * (`drawOfficeView()`, card#12052), the two leaves in the building's door colours, and no text — the cab's words are
 * the page's (`floor/cab-panel.js`); beside the door, a decorative call plate that names, lights and does nothing
 * (card#12071). The band's leaves open onto the same room, seen from the landing, drawn behind them (card#12052).
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
 * EVERY RULE THAT MIXES ITS COLOUR (card#12051's cab, card#12052's office view), and the ONLY place `STYLE` may write
 * `color-mix()`. ⛔ A BROWSER WITHOUT `color-mix()` MUST NOT SEE THESE AS NOTHING: a declaration holding `var()` is
 * accepted when it is parsed and fails only when its value is computed, so a plain colour declared before it in the
 * same rule would be thrown away and the surface left unpainted (black). The fallback is therefore a RULE OF ITS OWN,
 * outside a `@supports` block that holds these: `unmixed()` derives it from this text — each mix replaced by its larger
 * share's token — so there is one copy to edit and the two cannot drift. `TheElevatorIsAControlOnTheDrawingTest` holds
 * every `color-mix()` in `STYLE` inside the block and every rule in it to a fallback before it that sets the same property
 * to one of the mix's own colours (`HoldsEveryColourMixToAFallback`, the check `mezzanine.css` is held to too: card#12070).
 */
const MIXED = `.cab-back{fill:color-mix(in srgb,var(--cab-frame) 78%,var(--door))}.cab-side{fill:color-mix(in srgb,var(--cab-frame) 88%,var(--door))}.cab-ceiling{fill:color-mix(in srgb,var(--cab-frame) 50%,var(--door))}.cab-floor{fill:color-mix(in srgb,var(--cab-frame) 55%,var(--scene-ink))}
.cab-tile{stroke:color-mix(in srgb,var(--cab-frame) 45%,var(--door-edge));stroke-width:.8;stroke-opacity:.45}.cab-seam{stroke:color-mix(in srgb,var(--cab-frame) 45%,var(--scene-ink));stroke-width:1;stroke-opacity:.6}
.cab-kick{fill:color-mix(in srgb,var(--door-edge) 60%,var(--cab-frame))}
.cab-light{fill:color-mix(in srgb,var(--cab-lamp) 35%,var(--door));stroke:color-mix(in srgb,var(--cab-lamp) 60%,var(--door));stroke-width:.8}.cab-rim{fill:none;stroke:color-mix(in srgb,var(--cab-frame) 50%,var(--scene-ink));stroke-width:4}
.cab-frame{fill:var(--cab-frame);stroke:color-mix(in srgb,var(--cab-frame) 65%,var(--scene-ink));stroke-width:.8}
.cab-plate-body{fill:color-mix(in srgb,var(--cab-frame) 62%,var(--door));stroke:color-mix(in srgb,var(--cab-frame) 55%,var(--scene-ink));stroke-width:.5}
.office-ceiling{fill:color-mix(in srgb,var(--scene-paper) 82%,var(--house-wall-2))}.office-floor{fill:color-mix(in srgb,var(--scene-floor) 80%,var(--scene-trim))}.office-plank{stroke:color-mix(in srgb,var(--scene-trim) 60%,var(--scene-floor));stroke-width:.5;stroke-opacity:.5}
.office-wall{fill:color-mix(in srgb,var(--scene-paper) 62%,var(--house-wall-2))}.office-wall.right{fill:color-mix(in srgb,var(--scene-paper) 52%,var(--house-wall-2))}.office-back{fill:color-mix(in srgb,var(--scene-paper) 58%,var(--house-wall))}
.office-light{fill:var(--scene-paper);stroke:color-mix(in srgb,var(--lamp) 55%,var(--scene-paper));stroke-width:.4}
.office-desk-top{fill:color-mix(in srgb,var(--scene-stool) 62%,var(--scene-paper))}.office-desk-side{fill:color-mix(in srgb,var(--scene-stool) 70%,var(--scene-ink))}.office-leg{fill:color-mix(in srgb,var(--scene-trim) 70%,var(--scene-ink))}
.office-chair{fill:color-mix(in srgb,var(--house-trim) 78%,var(--scene-paper))}.office-pot{fill:color-mix(in srgb,var(--scene-stool) 72%,var(--scene-ink))}.office-leaf{fill:color-mix(in srgb,var(--scene-gauge-fill) 70%,var(--house-wall))}
`;

/** `MIXED` with each `color-mix()` replaced by its larger share's token — the fallback a browser without it draws. */
const unmixed = (css) => css.replace(/color-mix\(in srgb,var\((--[a-z0-9-]+)\) (\d+)%,var\((--[a-z0-9-]+)\)\)/g, (all, a, share, b) => `var(${Number(share) >= 50 ? a : b})`);

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
.elevator-leaf{fill:var(--door);stroke:var(--door-edge);stroke-width:2}
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
.elevator{cursor:pointer}.elevator:focus{outline:none}.elevator-hit{fill:transparent}.elevator:focus-visible .elevator-hit{stroke:var(--scene-thread);stroke-width:3}
.elevator[aria-disabled="true"]{cursor:default}
.cab-shade-stop{stop-color:var(--scene-ink)}.cab-back-shade{fill:var(--scene-ink)}.cab-glow-stop{stop-color:var(--cab-lamp)}
.cab-rail{fill:var(--door-edge);stroke:var(--door-edge);stroke-width:.6;stroke-linejoin:round}.cab-rail-shine{stroke:var(--door);stroke-width:1;stroke-linecap:round;stroke-opacity:.8}.cab-rail-post{stroke:var(--door-edge);stroke-width:2;stroke-linecap:round}
.cab-light-glow{fill:var(--cab-lamp);opacity:.28}.cab-sill{fill:var(--door-edge)}.cab-recess{fill:var(--ground-deep)}.cab-dial{fill:none;stroke:var(--door-edge);stroke-width:1.2}.cab-lamp{fill:var(--cab-lamp)}
.band-cab{--cab-frame:var(--door-frame);--cab-lamp:var(--lamp)}
.cab-plate{pointer-events:none}.cab-plate-window{fill:var(--ground-deep)}.cab-plate-button{fill:var(--door);stroke:var(--door-edge);stroke-width:.5}.cab-plate-keyhole{fill:var(--scene-ink)}
.office-skirting{fill:var(--house-trim)}.office-sky-stop{stop-color:var(--scene-glass)}.office-day-stop{stop-color:var(--scene-paper)}.office-mullion{fill:none;stroke:var(--house-trim);stroke-width:.7}
.office-sun{fill:var(--scene-paper);opacity:.32}.office-shade-stop{stop-color:var(--scene-ink)}.office-back-shade{fill:var(--scene-ink)}.office-leaf.lit{fill:var(--scene-gauge-fill)}
${unmixed(MIXED)}@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}
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
 * ⛔ THE RIDE's DOORS MOVE CONTINUOUSLY — NEVER `steps()` (§ 12's *Ride door motion*, card#12038). The ride's leaf
 * motion is § 6.3's exception for the viewer's own ride, which is not claim-bearing, so no loop rate binds it; the
 * walks' leaves (`leafAnimation()`) are claim-bearing and stay quantised to the loop rate. How long each motion
 * takes is the phase's (`floor-screen.js`'s `RIDE_DOOR_MS`, through `ridePhase()`), never this file's.
 */
const RIDE_DOOR_EASING = 'ease-in-out';

/**
 * When a ride phase's leaves move, ms (FLOOR.md § 4.5): `delay` from this paint — the phase's door delay (its fade)
 * less the time already spent in the phase, so a re-paint lands mid-motion rather than restarting it — and
 * `motion`, the rest of the phase.
 */
function doorTiming(ride) {
    const doorDelay = ride.door_delay_ms ?? 0;

    return { delay: doorDelay - ride.elapsed_ms, motion: ride.ms - doorDelay };
}

/**
 * The ride's band leaves (§ 4.5, card#11795), as a `style` — or `null` when no ride holds them, and the walks'
 * door spans draw them. `opening` opens them; `entering` to `leaving` hold them open while the viewer is in the
 * cab; `staying` and `arriving` draw them open and close them once the phase's fade is over. Each motion is
 * placed at the phase's elapsed time — `animation-delay: (door_delay − elapsed)` — so a re-paint mid-phase
 * neither restarts nor reorders it. A phase of no length (`prefers-reduced-motion`) moves nothing.
 *
 * @param {{phase: string, elapsed_ms: number, ms: number, door_delay_ms: number|null}|null} ride `scene.ride`
 * @returns {string|null}
 */
export function rideLeaves(ride) {
    if (ride === null || ride === undefined) {
        return null;
    }

    const { delay, motion } = doorTiming(ride);

    switch (ride.phase) {
        case 'opening':
            return ride.ms === 0 ? 'transform:scaleX(.12)' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms forwards`;
        case 'staying':
        case 'arriving':
            return ride.ms === 0 ? null : `animation:leaf-close ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms both`;
        default:
            // `entering`, `inside`, `chosen`, `leaving`: the floor's leaves stand open behind the cab.
            return 'transform:scaleX(.12)';
    }
}

/**
 * The cab's leaves (§ 4.5, card#11795), as a `style`: they close as the viewer enters (`entering`) and open
 * on the choice (`chosen`), each once the phase's fade is over; they stand shut `inside` and in a stay's fade,
 * and open in `leaving`, which the departing page's snapshot carries across the navigation.
 */
export function cabLeaves(ride) {
    const { delay, motion } = doorTiming(ride);

    switch (ride.phase) {
        case 'entering':
            return ride.ms === 0 ? null : `animation:leaf-close ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms both`;
        case 'chosen':
            return ride.ms === 0 ? 'transform:scaleX(.12)' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms both`;
        case 'leaving':
            return 'transform:scaleX(.12)';
        default:
            return null;
    }
}

/**
 * THE CAB's ROOM (§ 4.5's *cab's look*, card#12051; wider, and seen from closer, card#12071; § 12's *Cab interior*), in
 * centimetres: a real passenger cab's width, depth and height, the door on the wall the rider faces, and the RIDER's eye —
 * its height over the floor (`eye`), its distance from the doors' wall (`back`), and the distance from it at which the
 * drawing's frame cuts the room (`view`, the lens: the frame spans the room's whole width and height there, so the doors'
 * wall is drawn at `view / back` of the frame's size). The interior is a ONE-POINT PERSPECTIVE of that room — the side
 * walls, the ceiling and the floor receding from the frame to the doors' wall at the eye's point — so the room is a box
 * taller than it is wide, never the window's shape. The cab dialog's drawing is the frame, one unit a centimetre
 * (`paintCab()`'s viewBox). `back` is also the eye the office through the doors is seen from (`OFFICE.view`).
 */
export const CAB = Object.freeze({ w: 200, d: 140, h: 230, eye: 155, back: 120, view: 94, door: Object.freeze({ w: 110, h: 200 }) });

/**
 * THE CAB SEEN FROM THE LANDING (card#12052's band cab; § 12's *Cab interior*): the eye `view` cm in front of the cab's open
 * front, at `CAB.eye`'s height, the frame that open front and the far wall the cab's rear one, `CAB.d` behind it — the lens
 * `drawCabInterior()` is handed for the room seen behind the floor's leaves.
 */
export const LANDING = Object.freeze({ view: 230, back: 230 + CAB.d });

/**
 * THE CAB's INTERIOR, REUSABLE (card#12051): the room of `CAB` drawn in one-point perspective into `parent`, fitted to
 * `box` (`{x, y, w, h}`, the frame — the room's cross-section where `lens` cuts it — in `parent`'s units) — the ceiling and
 * its light, the floor and its tiles, the two side walls and their handrails, and the far wall, each surface shaded so the
 * far end reads deeper. `lens` is the eye it is seen from (`{view, back}`: the eye's distance from the frame and from the
 * far wall): the rider's, `CAB`, by default, or the landing's (`LANDING`). Only what lies between the frame and the far
 * wall is drawn — a handrail or the ceiling's light behind the frame is out of view. It draws no door and no leaves, and
 * lays nothing out: the caller places those on the far wall it answers.
 *
 * ⛔ ITS COLOURS ARE THE ELEVATOR PALETTE's CARRIERS, NEVER AN ARGUMENT OR AN INLINE COLOUR. Every surface is painted by
 * its class in `STYLE`, from `--cab-frame` / `--cab-lamp` (which `cabTheme()` sets on an ancestor from the house
 * theme's `PALETTE.elevator`, the sheet's door tokens answering with no theme) mixed with the sheet's door and ink
 * tokens; its shading is a translucent overlay of those. `id` prefixes the gradients it defines, so two rooms drawn into
 * one document do not share an id.
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} box
 * @param {string} [id]
 * @param {{view: number, back: number}} [lens]
 * @returns {{back: {x: number, y: number, w: number, h: number}}} the far wall, in `parent`'s units
 */
export function drawCabInterior(parent, box, id = 'cab', lens = CAB) {
    const sx = box.w / CAB.w;
    const sy = box.h / CAB.h;
    // A point of the room — `X` across from its centre line, `Y` up from its floor, `Z` back from the frame — on the box.
    const at = (X, Y, Z) => {
        const s = lens.view / (lens.view + Z);

        return [box.x + (CAB.w / 2 + X * s) * sx, box.y + (CAB.h - CAB.eye + (CAB.eye - Y) * s) * sy];
    };
    const pts = (...ps) => ps.map((p) => p.map((v) => +v.toFixed(2)).join(',')).join(' ');
    // The depth in view, frame to far wall; `Zof(B)` is the depth of a point `B` in front of the far wall.
    const [l, r, top, D] = [-CAB.w / 2, CAB.w / 2, CAB.h, lens.back - lens.view];
    const Zof = (B) => D - B;
    const g = node('g', { class: 'cab-room' }, parent);
    const defs = node('defs', {}, g);
    // The depth the ceiling's shade reaches at the back wall, and the back wall's own even shade.
    const FAR = 0.3;
    // Each overlay deepens toward the back: clear at the near edge, the room's ink at the far one.
    const shade = (name, x1, y1, x2, y2, far) => {
        const grad = node('linearGradient', { id: `${id}-${name}`, x1, y1, x2, y2 }, defs);
        node('stop', { offset: 0, class: 'cab-shade-stop', 'stop-opacity': 0 }, grad);
        node('stop', { offset: 1, class: 'cab-shade-stop', 'stop-opacity': far }, grad);
    };
    shade('shade-left', 0, 0, 1, 0, 0.32);
    shade('shade-right', 1, 0, 0, 0, 0.32);
    shade('shade-floor', 0, 1, 0, 0, 0.4);
    shade('shade-ceiling', 0, 0, 0, 1, FAR);
    const glow = node('radialGradient', { id: `${id}-glow`, cx: 0.5, cy: 0, r: 0.75 }, defs);
    node('stop', { offset: 0, class: 'cab-glow-stop', 'stop-opacity': 0.55 }, glow);
    node('stop', { offset: 1, class: 'cab-glow-stop', 'stop-opacity': 0 }, glow);

    const face = (cls, points, overlay) => {
        node('polygon', { points, class: cls }, g);
        if (overlay !== undefined) {
            node('polygon', { points, fill: `url(#${id}-${overlay})`, class: 'cab-shade' }, g);
        }
    };

    face('cab-ceiling', pts(at(l, top, 0), at(r, top, 0), at(r, top, D), at(l, top, D)), 'shade-ceiling');
    face('cab-floor', pts(at(l, 0, 0), at(r, 0, 0), at(r, 0, D), at(l, 0, D)), 'shade-floor');
    face('cab-side left', pts(at(l, top, 0), at(l, top, D), at(l, 0, D), at(l, 0, 0)), 'shade-left');
    face('cab-side right', pts(at(r, top, 0), at(r, top, D), at(r, 0, D), at(r, 0, 0)), 'shade-right');

    const [bx, by] = at(l, top, D);
    const [bx2, by2] = at(r, 0, D);
    const back = { x: bx, y: by, w: bx2 - bx, h: by2 - by };

    node('rect', { x: back.x, y: back.y, width: back.w, height: back.h, class: 'cab-back' }, g);
    // The back wall is the far end, every point of it at the room's full depth: one even shade, as deep as the ceiling's
    // far edge where the two meet, so the corner is one tone and the wall reads as the deepest surface.
    node('rect', { x: back.x, y: back.y, width: back.w, height: back.h, class: 'cab-shade cab-back-shade', 'fill-opacity': FAR }, g);
    // The ceiling light's pool on the back wall's head.
    node('rect', { x: back.x, y: back.y, width: back.w, height: back.h * 0.6, fill: `url(#${id}-glow)`, class: 'cab-glow' }, g);

    // The floor's tiles, 50 cm across and 35 cm deep: seams running back to the eye's point, and across at each tile's
    // depth that lies in view.
    for (let i = 1; i < 4; i += 1) {
        const X = l + (i * CAB.w) / 4;
        const [a, b] = [at(X, 0, 0), at(X, 0, D)];
        node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: 'cab-tile' }, g);
    }
    for (const B of [CAB.d / 4, CAB.d / 2, (3 * CAB.d) / 4].filter((v) => Zof(v) > 0)) {
        const [a, b] = [at(l, 0, Zof(B)), at(r, 0, Zof(B))];
        node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: 'cab-tile' }, g);
    }

    // The corner seams, where each side wall meets the ceiling, the floor and the back wall.
    for (const [p, q] of [[at(l, top, 0), at(l, top, D)], [at(r, top, 0), at(r, top, D)], [at(l, 0, 0), at(l, 0, D)], [at(r, 0, 0), at(r, 0, D)]]) {
        node('line', { x1: p[0], y1: p[1], x2: q[0], y2: q[1], class: 'cab-seam' }, g);
    }

    // A kick plate along each side wall's foot, and a handrail at hip height running the wall's length but 10 cm at each
    // end, standing off the wall on a post at each end — the part of it in view, its bar thinning with depth as the room does.
    const [near, far] = [Math.max(0, Zof(CAB.d - 10)), Zof(10)];
    for (const side of [l, r]) {
        face('cab-kick', pts(at(side, 10, 0), at(side, 10, D), at(side, 0, D), at(side, 0, 0)), side < 0 ? 'shade-left' : 'shade-right');
        const rail = side - Math.sign(side) * 5;
        for (const Z of [Zof(CAB.d - 10), far].filter((v) => v >= 0)) {
            const [p, q] = [at(side, 95, Z), at(rail, 95, Z)];
            node('line', { x1: p[0], y1: p[1], x2: q[0], y2: q[1], class: 'cab-rail-post' }, g);
        }
        face('cab-rail', pts(at(rail, 97, near), at(rail, 97, far), at(rail, 93, far), at(rail, 93, near)));
        const [a, b] = [at(rail, 96.5, near), at(rail, 96.5, far)];
        node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: 'cab-rail-shine' }, g);
    }

    // The ceiling light: a lit panel in the middle of the ceiling, and its soft glow — when it lies in view.
    const [lightNear, lightFar] = [Zof(CAB.d / 2 + 32), Zof(CAB.d / 2 - 32)];
    if (lightFar > 0) {
        const z0 = Math.max(0, lightNear);
        const panel = pts(at(-34, top, z0), at(34, top, z0), at(34, top, lightFar), at(-34, top, lightFar));
        const [gx, gy] = at(0, top, Zof(CAB.d / 2));
        node('ellipse', { cx: gx, cy: gy + 2 * sy, rx: 46 * sx, ry: 14 * sy, class: 'cab-light-glow' }, g);
        node('polygon', { points: panel, class: 'cab-light' }, g);
    }

    // The open front's rim, so the room reads as a box in the dark around it.
    node('rect', { x: box.x, y: box.y, width: box.w, height: box.h, class: 'cab-rim' }, g);

    return { back };
}

/**
 * `box` as a clip (`<clipPath id="${id}-clip">`, in `defs`), and the group under `parent` it clips — what a drawing seen
 * through a doorway is drawn into, so nothing of it shows past the opening.
 */
function clippedTo(parent, defs, box, id) {
    const clip = node('clipPath', { id: `${id}-clip` }, defs);

    node('rect', { x: box.x, y: box.y, width: box.w, height: box.h }, clip);

    return node('g', { 'clip-path': `url(#${id}-clip)` }, parent);
}

/**
 * THE CALL PLATE BESIDE THE CAB's DOORS (card#12071; § 4.5's *cab's look*): a DECORATIVE button plate on the doors' wall,
 * between the door's frame (whose right edge is `frameRight`, in the drawing's units) and the right side wall — a brushed
 * plate in the elevator palette, a small dark indicator window, round buttons and a keyhole — drawn into `parent` on `back`,
 * the doors' wall as `drawCabInterior()` answered it, at its own size in the room (centimetres, scaled as that wall is).
 *
 * ⛔ IT IS SCENERY, NOT THE CAB's BUTTON PANEL (§ 6.3, card#12071): the real panel is the popup (`floor/cab-panel.js`),
 * the only place a stop is named, chosen or lit. This plate draws no word, number or floor, no lit button and no lit
 * window, carries no datum attribute, role, tab stop or handler, takes no pointer (`aria-hidden`, `.cab-plate`'s
 * `pointer-events:none`), reads nothing — its only inputs are the wall and the frame's edge — and never moves. It is
 * `paintCab()`'s only: from the landing the doors' wall is the one looked through, so the band's cab has none.
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} back
 * @param {number} frameRight
 */
function drawCallPlate(parent, back, frameRight) {
    const k = back.w / CAB.w;
    const wall = back.x + back.w;
    // The plate's size in the room: 16 × 60 cm, centred in the wall between the frame and the side wall, its head 150 cm up.
    const [w, h] = [16 * k, 60 * k];
    const x = (frameRight + wall) / 2 - w / 2;
    const y = back.y + (CAB.h - 150) * k;
    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true' }, parent);

    node('rect', { x, y, width: w, height: h, rx: 2 * k, class: 'cab-plate-body' }, g);
    node('rect', { x: x + 3 * k, y: y + 4 * k, width: w - 6 * k, height: 6 * k, rx: k, class: 'cab-plate-window' }, g);
    for (let row = 0; row < 4; row += 1) {
        for (const col of [-1, 1]) {
            node('circle', { cx: x + w / 2 + col * 3.6 * k, cy: y + (17 + row * 8.5) * k, r: 2.3 * k, class: 'cab-plate-button' }, g);
        }
    }
    node('circle', { cx: x + w / 2, cy: y + 52 * k, r: 1.6 * k, class: 'cab-plate-keyhole' }, g);
    node('rect', { x: x + w / 2 - 0.5 * k, y: y + 52 * k, width: k, height: 3 * k, class: 'cab-plate-keyhole' }, g);
}

/**
 * THE OFFICE THE CAB's DOORS OPEN ONTO (§ 4.5's *cab's look*, card#12052; § 12's *Office view*), in centimetres: the
 * room's width, its depth beyond the doorway, its height, how far its left wall stands from the door's centre line, and
 * the eye's distance from the doorway — the rider's own eye in the cab (`CAB.back`, card#12071), so the office through the
 * doors and the cab around them are one view from one point.
 */
export const OFFICE = Object.freeze({ w: 520, d: 900, h: 280, left: 220, view: CAB.back });

/**
 * THE OFFICE OUTSIDE, REUSABLE (card#12052): a one-point perspective of the room of `OFFICE` seen through a doorway,
 * drawn into `parent` and clipped to `box` (`{x, y, w, h}`, the doorway, read as `CAB.door` — 110 × 200 cm — with the
 * eye `CAB.eye` over the floor, so its vanishing point is the cab's). The floor, the ceiling with its lights, two walls
 * and the far wall, windows of daylight on the left wall and the far one with the light they cast on the floor, two
 * empty desks with a chair at each, and a plant, every surface shaded deeper toward the back.
 *
 * ⛔ IT IS SCENERY AND NOTHING ELSE (§ 6.3, card#12052): it draws no character, no seat, no desk slot, no name, no badge
 * and no word, reads no fleet or floor datum — its only inputs are a box and an id — and nothing in it moves. Its
 * colours are its classes in `STYLE`, the sheet's tokens and their mixes, never an argument or an inline colour. `id`
 * prefixes the ids it defines, so two views drawn into one document do not share one.
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} box
 * @param {string} [id]
 * @returns {{clip: {x: number, y: number, w: number, h: number}}} the rect it is clipped to
 */
export function drawOfficeView(parent, box, id = 'office') {
    const kx = box.w / CAB.door.w;
    const ky = box.h / CAB.door.h;
    // A point of the office — `X` across from the door's centre line, `Y` up from the floor, `Z` back from the doorway.
    const at = (X, Y, Z) => {
        const s = OFFICE.view / (OFFICE.view + Z);

        return [box.x + box.w / 2 + X * s * kx, box.y + (CAB.door.h - CAB.eye + (CAB.eye - Y) * s) * ky];
    };
    const pts = (...ps) => ps.map((p) => p.map((v) => +v.toFixed(2)).join(',')).join(' ');
    const [l, r, H, D] = [-OFFICE.left, OFFICE.w - OFFICE.left, OFFICE.h, OFFICE.d];
    const g = node('g', { class: 'office-view' }, parent);
    const defs = node('defs', {}, g);
    const shade = (name, x1, y1, x2, y2, far) => {
        const grad = node('linearGradient', { id: `${id}-${name}`, x1, y1, x2, y2 }, defs);
        node('stop', { offset: 0, class: 'office-shade-stop', 'stop-opacity': 0 }, grad);
        node('stop', { offset: 1, class: 'office-shade-stop', 'stop-opacity': far }, grad);
    };
    shade('shade-left', 0, 0, 1, 0, 0.22);
    shade('shade-right', 1, 0, 0, 0, 0.22);
    shade('shade-floor', 0, 1, 0, 0, 0.2);
    shade('shade-ceiling', 0, 0, 0, 1, 0.18);
    // Daylight in the glass: the sky's glass at the head, paler toward the sill.
    const glass = node('linearGradient', { id: `${id}-glass`, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
    node('stop', { offset: 0, class: 'office-sky-stop' }, glass);
    node('stop', { offset: 1, class: 'office-day-stop' }, glass);

    const room = clippedTo(g, defs, box, id);
    const face = (cls, points, overlay) => {
        node('polygon', { points, class: cls }, room);
        if (overlay !== undefined) {
            node('polygon', { points, fill: `url(#${id}-${overlay})`, class: 'office-shade' }, room);
        }
    };
    const line = (cls, a, b) => node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: cls }, room);

    // The room: the ceiling, the floor and its boards, the two walls, the far wall, a skirting along each wall's foot.
    face('office-ceiling', pts(at(l, H, 0), at(r, H, 0), at(r, H, D), at(l, H, D)), 'shade-ceiling');
    face('office-floor', pts(at(l, 0, 0), at(r, 0, 0), at(r, 0, D), at(l, 0, D)), 'shade-floor');
    for (let X = l + 40; X < r; X += 40) {
        line('office-plank', at(X, 0, 0), at(X, 0, D));
    }
    face('office-wall left', pts(at(l, H, 0), at(l, H, D), at(l, 0, D), at(l, 0, 0)), 'shade-left');
    face('office-wall right', pts(at(r, H, 0), at(r, H, D), at(r, 0, D), at(r, 0, 0)), 'shade-right');
    const [bx, by] = at(l, H, D);
    const [bx2, by2] = at(r, 0, D);
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'office-back' }, room);
    // The far wall, all of it at the room's full depth: one even shade, as deep as the walls' far edges.
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'office-shade office-back-shade', 'fill-opacity': 0.22 }, room);
    for (const X of [l, r]) {
        face('office-skirting', pts(at(X, 10, 0), at(X, 10, D), at(X, 0, D), at(X, 0, 0)));
    }
    face('office-skirting', pts(at(l, 10, D), at(r, 10, D), at(r, 0, D), at(l, 0, D)));

    // A window, given its four corners as drawn: its glass in daylight and its frame, an outline and a cross of mullions.
    const window_ = (corners) => {
        const [a, b, c, d] = corners;
        node('polygon', { points: pts(a, b, c, d), fill: `url(#${id}-glass)`, class: 'office-glass' }, room);
        const mid = (p, q) => [(p[0] + q[0]) / 2, (p[1] + q[1]) / 2];
        node('path', { d: `M${pts(a, b, c, d, a).replace(/ /g, 'L')}M${pts(mid(a, b), mid(d, c)).replace(' ', 'L')}M${pts(mid(a, d), mid(b, c)).replace(' ', 'L')}`, class: 'office-mullion' }, room);
    };
    const windows = [[380, 520], [580, 720], [770, 870]];
    for (const [z1, z2] of windows) {
        window_([at(l, 235, z1), at(l, 235, z2), at(l, 85, z2), at(l, 85, z1)]);
    }
    window_([at(l + 70, 235, D), at(r - 70, 235, D), at(r - 70, 85, D), at(l + 70, 85, D)]);
    // The daylight each side window lays on the floor, slanting in toward the room.
    for (const [z1, z2] of windows) {
        node('polygon', { points: pts(at(l, 0, z1 + 20), at(l, 0, z2 + 20), at(l + 160, 0, Math.min(D, z2 + 90)), at(l + 160, 0, Math.min(D, z1 + 90))), class: 'office-sun' }, room);
    }

    // The ceiling's lights.
    for (const Z of [240, 470, 690]) {
        face('office-light', pts(at(-45, H, Z), at(45, H, Z), at(45, H, Z + 50), at(-45, H, Z + 50)));
    }

    // A plant: a pot and its leaves, smaller with depth.
    const plant = (X, Z) => {
        face('office-pot', pts(at(X - 16, 0, Z), at(X + 16, 0, Z), at(X + 20, 38, Z), at(X - 20, 38, Z)));
        const s = OFFICE.view / (OFFICE.view + Z);
        for (const [dx, Y, rx, ry, lit] of [[-16, 62, 16, 22, false], [16, 66, 15, 24, false], [0, 92, 18, 26, false], [-8, 76, 12, 16, true], [10, 104, 11, 15, true]]) {
            const [cx, cy] = at(X + dx, Y, Z);
            node('ellipse', { cx, cy, rx: rx * s * kx, ry: ry * s * ky, class: lit ? 'office-leaf lit' : 'office-leaf' }, room);
        }
    };
    // An empty desk from `x1` to `x2`, `z1` to `z2`: two back legs and a modesty panel, two front legs, its top and the
    // top's front edge; and its chair, pushed in toward the desk from the near side, its back to the viewer.
    const desk = (x1, x2, z1, z2) => {
        for (const X of [x1 + 3, x2 - 7]) {
            face('office-leg', pts(at(X, 72, z2 - 4), at(X + 4, 72, z2 - 4), at(X + 4, 0, z2 - 4), at(X, 0, z2 - 4)));
        }
        face('office-desk-side', pts(at(x1 + 7, 72, z2 - 6), at(x2 - 7, 72, z2 - 6), at(x2 - 7, 30, z2 - 6), at(x1 + 7, 30, z2 - 6)));
        for (const X of [x1 + 3, x2 - 7]) {
            face('office-leg', pts(at(X, 72, z1 + 4), at(X + 4, 72, z1 + 4), at(X + 4, 0, z1 + 4), at(X, 0, z1 + 4)));
        }
        face('office-desk-top', pts(at(x1, 76, z1), at(x2, 76, z1), at(x2, 76, z2), at(x1, 76, z2)));
        face('office-desk-side', pts(at(x1, 76, z1), at(x2, 76, z1), at(x2, 72, z1), at(x1, 72, z1)));
        const cx = (x1 + x2) / 2;
        const cz = z1 - 22;
        face('office-chair', pts(at(cx - 20, 4, cz - 4), at(cx + 20, 4, cz - 4), at(cx + 20, 0, cz - 4), at(cx - 20, 0, cz - 4)));
        face('office-leg', pts(at(cx - 2, 44, cz), at(cx + 2, 44, cz), at(cx + 2, 4, cz), at(cx - 2, 4, cz)));
        face('office-chair', pts(at(cx - 22, 46, cz - 20), at(cx + 22, 46, cz - 20), at(cx + 22, 46, cz + 20), at(cx - 22, 46, cz + 20)));
        face('office-chair', pts(at(cx - 21, 98, cz - 22), at(cx + 21, 98, cz - 22), at(cx + 21, 52, cz - 22), at(cx - 21, 52, cz - 22)));
    };
    desk(-175, -55, 540, 610);
    plant(l + 35, 420);
    desk(-10, 130, 240, 320);

    return { clip: box };
}

/**
 * The painter, over the floor view's own drawing element — `#floor-drawing`, which
 * `FloorPageWiringTest` holds to the view in both directions.
 *
 * @param {{characters: object|null, themes: Object<string, object|null>, failed: function(list<string>): void,
 *          select: function(string, string): void, ride: function(): void, house: string|null}} options `themes`
 *          the theme modules `loadArt()` held, by name; `ride` the elevator's press (§ 4.5); `house` the house
 *          theme's name, whose elevator palette the cab is drawn in
 */
export function createPainter({ characters, themes = {}, failed, select, ride = () => {}, house = null }) {
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

            drawCabInterior(clippedTo(inside, node('defs', {}, inside), doorway, 'band-cab'), doorway, 'band-cab', LANDING);

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

    /** The house theme's elevator palette — `{frame, lamp}` — or `null` when that theme did not load. */
    function cabPalette() {
        return themes[house]?.PALETTE?.elevator ?? null;
    }

    /**
     * THE CAB's COLOURS (§ 4.5, card#12039) onto `el`, the cab dialog: the house theme's elevator palette — its
     * `PALETTE.elevator` frame and lamp — as `--cab-frame` and `--cab-lamp`, which the cab's drawing (`paintCab()`)
     * and its button popup (the sheet's `.floor-cab*` rules) both read. The palette's one home stays the theme
     * module; these properties carry it, and are not a copy. `data-themed` marks the dialog as carrying it, which
     * is what makes the popup's body the frame and its words the room's ink (card#12047). A theme that did not load
     * removes all three, and the sheet's own defaults on `.floor-cab` answer instead: the door frame's and the lamp's
     * tokens for the drawing, and the page's own panel and ink for the popup, so its words stay legible.
     *
     * @param {HTMLElement} el
     */
    function cabTheme(el) {
        const palette = cabPalette();

        el.toggleAttribute('data-themed', palette !== null);

        if (palette === null) {
            el.style.removeProperty('--cab-frame');
            el.style.removeProperty('--cab-lamp');

            return;
        }

        el.style.setProperty('--cab-frame', palette.frame);
        el.style.setProperty('--cab-lamp', palette.lamp);
    }

    /**
     * THE CAB's INTERIOR (§ 4.5, card#11795; in depth, card#12051) into `host`, the cab dialog's own drawing element:
     * the room `drawCabInterior()` draws from the rider's eye (card#12071) — one cab, the frame its lens cuts the drawing's
     * whole space — and on its back wall the door: its frame in the house theme's elevator palette (`--cab-frame`, `cabTheme()`'s), a plain dial and its lamp
     * (`--cab-lamp`) — no numeral and no arrow — the dark doorway, the layer behind the leaves (`.cab-beyond`: the office
     * the open doors look out on, `drawOfficeView()`'s, card#12052), and the two leaves in the building's door colours,
     * moving as `cabLeaves()` says, the seam between them being their own edges; beside the door's frame, under the layer
     * behind the leaves, the decorative call plate (`drawCallPlate()`, card#12071). The cab's space is fitted WHOLE into
     * `host` (`meet`, card#12039) and nothing is drawn past it (`overflow: hidden`, card#12051): the room is a box taller
     * than it is wide in the dialog's dark, its door's head and lamp in view at any window shape. No `<text>`: every word in the cab is the panel's. Painted once per phase entry; `null`
     * empties it.
     *
     * @param {Element} host
     * @param {{phase: string, elapsed_ms: number, ms: number, door_delay_ms: number|null}|null} ride `scene.ride`
     */
    function paintCab(host, ride) {
        if (ride === null || ride === undefined) {
            host.replaceChildren();

            return;
        }

        const cab = node('svg', {
            viewBox: `0 0 ${CAB.w} ${CAB.h}`, width: '100%', height: '100%', preserveAspectRatio: 'xMidYMid meet', overflow: 'hidden', class: 'cab-scene', 'aria-hidden': 'true',
        });

        // The cab's own shape, for the layout that sets the drawing's box beside the popup (the sheet's `.floor-cab-doors`).
        host.style.setProperty('--cab-aspect', `${CAB.w} / ${CAB.h}`);
        node('style', {}, cab).textContent = STYLE;

        const { back } = drawCabInterior(cab, { x: 0, y: 0, w: CAB.w, h: CAB.h });
        // The door on the back wall: the leaves' size in the room, centred, standing on the back wall's foot.
        const w = (CAB.door.w * back.w) / CAB.w;
        const h = (CAB.door.h * back.h) / CAB.h;
        const L = { x: back.x + (back.w - w) / 2, y: back.y + back.h - h, w, h };
        // The door's trim — frame, recess, dial, sill — keeps the proportions it was drawn at for leaves 84 units wide
        // (the floor's own elevator's, `scene.js`'s `ELEVATOR`): `f` is this door's scale against that width. A look, not a fact.
        const f = L.w / 84;
        const seam = L.x + L.w / 2;
        const foot = L.y + L.h;

        node('rect', { x: L.x - 8 * f, y: L.y - 18 * f, width: L.w + 16 * f, height: L.h + 18 * f, rx: 6 * f, class: 'cab-frame' }, cab);
        node('rect', { x: L.x - 2 * f, y: L.y - 2 * f, width: L.w + 4 * f, height: L.h + 2 * f, rx: 3 * f, class: 'cab-recess' }, cab);
        node('path', { d: `M${seam - 12 * f} ${L.y - 7 * f}A${12 * f} ${7 * f} 0 0 1 ${seam + 12 * f} ${L.y - 7 * f}`, class: 'cab-dial' }, cab);
        node('circle', { cx: seam, cy: L.y - 7 * f, r: 1.8 * f, class: 'cab-lamp' }, cab);
        node('rect', { x: L.x - 10 * f, y: foot - f, width: L.w + 20 * f, height: 2 * f, rx: f, class: 'cab-sill' }, cab);
        drawCallPlate(cab, back, L.x + L.w + 8 * f);
        // ⛔ THE LAYER BEHIND THE LEAVES: the doorway's own group, under the leaves and over the dark recess — what the
        // open doors look out on, the office (card#12052), clipped to the doorway and still: the leaves reveal it.
        drawOfficeView(node('g', { class: 'cab-beyond' }, cab), L, 'cab-office');

        const style = cabLeaves(ride);

        // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.
        node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);
        node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);

        host.replaceChildren(cab);
    }

    return { paint, refresh, view, paintCab, cabTheme };
}
