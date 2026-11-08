/**
 * THE ELEVATOR's CAB, DRAWN — one drawing for both pages (`docs/design/FLOOR.md` § 4.5's *One ride, one cab, one
 * module*; hoisted out of `floor/painter.js` at its second caller, the lobby, card#12113). The cab's room in depth
 * (`drawCabInterior()`, card#12051), the views its open doors look out on (`drawFloorView()`, a floor's stop's own floor,
 * card#12987, with its bare room `drawOfficeView()`, and `drawLobbyView()`, card#12113), the cab dialog's drawing (`paintCab()`), its colours (`cabTheme()`), the ride's leaf
 * motion (`rideLeaves()`, `cabLeaves()`), the classes all of them paint with (`CAB_STYLE`), the one URI a drawn document
 * is shown by (`svgUri`, which the floor's painter imports), the lobby's theme loader (`loadThemes()`) and the scene's themes
 * input (`themeInputs()`, which the floor's painter re-exports).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT HAS NO STATIC IMPORT, AND ITS ONLY DYNAMIC ONES ARE THE THEME's: `loadThemes()` loads the theme registry
 * and the theme modules it names (`resources/floor/themes`), the two the import-graph test follows. The floor's painter (`floor/painter.js`) draws the scene, the desks and the band, and loads the
 * floor's whole scene machinery to do it; the lobby page draws a room and a cab and no desk, so it loads this file and
 * not the painter — which is what keeps the lobby page's import graph on its one path to the animation set
 * (`Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`). The painter imports this file and
 * re-exports what it always exported from here, so a floor reader finds each name where it was.
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT THE RIDE. Which phase the leaves are in, how far into it and which view stands
 * behind the doors (`beyond`) are the ride frame's (`wire/ride.js`'s `sceneRide()`), and the floor behind them (`view`)
 * the model the screen resolved for the ride's `stop` (`floor/door-view.js`) — read here and never inferred from the page
 * this runs on.
 */

const SVG = 'http://www.w3.org/2000/svg';

/** The theme registry — `resources/floor/themes/index.js`, by the asset route (FLOOR.md § 10.6 item 1). */
export const THEME_REGISTRY = '/art/floor/themes/index.js';

/** A theme's module — `resources/floor/themes/<name>/theme.js`, by the asset route (§ 10.6 item 1). */
export const themeModule = (name) => `/art/floor/themes/${encodeURIComponent(name)}/theme.js`;

/**
 * THE THEMES, LOADED — the theme registry (`THEME_REGISTRY`) and each theme module `pick(registry)` names, answered as the
 * scene's `themes` input (`themeInputs()`), or `null` when the registry did not load. A module that did not load is held
 * as `null` under its name, as `floor/painter.js`'s `loadArt()` holds it. ⛔ THE ONLY DYNAMIC IMPORTS ON THE LOBBY PAGE,
 * and the one site they are written: the asset route's registry, and the theme modules it names — the two the lobby's
 * import-graph bound follows into `resources/floor/themes/` by name, every `THEMES` entry
 * (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`). The lobby loads the house theme's for its cab's palette
 * (`loadHousePalette()`) and every theme for the view behind the cab's doors (card#12987, `floor/door-view.js`).
 *
 * @param {function(object): list<string>} [pick] which of the registry's themes to load — every one by default
 * @returns {Promise<{asset: string, registry: {names: list<string>, house: string, kinds: list<string>}, modules: Object<string, object|null>}|null>}
 */
export async function loadThemes(pick = (registry) => registry.THEMES) {
    let registry;

    try {
        registry = await import(THEME_REGISTRY);
    } catch {
        return null;
    }

    const names = [...pick(registry)];
    const modules = await Promise.all(names.map(async (name) => {
        try {
            return await import(themeModule(name));
        } catch {
            return null;
        }
    }));

    return themeInputs(registry, Object.fromEntries(names.map((name, i) => [name, modules[i]])));
}

/**
 * THE HOUSE THEME's ELEVATOR PALETTE, LOADED — `{frame, lamp}`, its `PALETTE.elevator`, or `null` when the registry or
 * the house theme's module did not load (a cab then drawn in the sheet's door frame and lamp, `cabTheme()`'s
 * fallback). For a page that constructs no painter — the lobby, which has no desk to paint and so loads no other art
 * for its cab (`floor/painter.js`'s `loadArt()` loads the floor's, every theme included). Through `loadThemes()`, the
 * page's one loader.
 *
 * @returns {Promise<{frame: string, lamp: string}|null>}
 */
export async function loadHousePalette() {
    const themes = await loadThemes((registry) => [registry.HOUSE_THEME]);

    return themes?.modules[themes.registry.house]?.PALETTE?.elevator ?? null;
}

/**
 * The scene's `themes` input (FLOOR.md § 10.6 items 5 and 8) from a loaded registry and its modules: the registry's
 * asset id — what § 9 F14's strip names when its import was rejected — the registry's names, house theme and kinds, or
 * `null`, and each named theme's module, a rejected one `null`. The floor page (`floor/painter.js` re-exports it), the
 * harness and `loadThemes()` build it through this one function, so the floor scene and the cab's door view read one
 * shape.
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
 * A drawn document's URI: a character frame or a theme document is a standalone SVG DOCUMENT, and the client alone makes
 * it a URI — `data:image/svg+xml;charset=utf-8,` over `encodeURIComponent` (§ 10.2), so no file in the asset tree carries
 * one (§ 10.1 clause 2). Here rather than in the floor's painter, which imports it, so the cab's view can draw a theme's
 * pieces on a page that loads no painter.
 */
export const svgUri = (doc) => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(doc)}`;

/** An SVG element named `name`, its attributes set (a `null` or `undefined` value left unset), under `parent`. */
export function node(name, attrs = {}, parent = null) {
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
 * EVERY RULE THAT MIXES ITS COLOUR (card#12051's cab, card#12113's lobby view, card#12987's floor view and its bare room), and the ONLY place `STYLE` may write
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
.dv-ceiling{fill:color-mix(in srgb,var(--scene-paper) 82%,var(--house-wall-2));stroke:color-mix(in srgb,var(--scene-paper) 82%,var(--house-wall-2));stroke-width:.5}
.dv-floor{fill:var(--dv-floor,color-mix(in srgb,var(--scene-floor) 80%,var(--scene-trim)));stroke:var(--dv-floor,color-mix(in srgb,var(--scene-floor) 80%,var(--scene-trim)));stroke-width:.5}
.dv-plank{stroke:color-mix(in srgb,var(--scene-trim) 60%,var(--scene-floor));stroke-width:.5;stroke-opacity:.5}
.dv-accent{fill:var(--dv-accent,color-mix(in srgb,var(--scene-floor) 60%,var(--scene-trim)));stroke:var(--dv-accent,color-mix(in srgb,var(--scene-floor) 60%,var(--scene-trim)));stroke-width:.5}
.dv-wall{fill:var(--dv-wall-top,color-mix(in srgb,var(--scene-paper) 62%,var(--house-wall-2)));stroke:var(--dv-wall-top,color-mix(in srgb,var(--scene-paper) 62%,var(--house-wall-2)));stroke-width:.5}
.dv-wall.right{fill:var(--dv-wall-bottom,color-mix(in srgb,var(--scene-paper) 52%,var(--house-wall-2)));stroke:var(--dv-wall-bottom,color-mix(in srgb,var(--scene-paper) 52%,var(--house-wall-2)))}
.dv-back{fill:var(--dv-wall-bottom,color-mix(in srgb,var(--scene-paper) 58%,var(--house-wall)));stroke:var(--dv-wall-bottom,color-mix(in srgb,var(--scene-paper) 58%,var(--house-wall)));stroke-width:.5}
.dv-light{fill:var(--dv-lamplight,var(--scene-paper));stroke:color-mix(in srgb,var(--lamp) 55%,var(--scene-paper));stroke-width:.4}
.dv-partition{fill:var(--dv-partition,color-mix(in srgb,var(--scene-paper) 70%,var(--house-wall-2)));stroke:var(--dv-partition,color-mix(in srgb,var(--scene-paper) 70%,var(--house-wall-2)));stroke-width:.5}
.dv-desk{fill:var(--dv-desk,color-mix(in srgb,var(--scene-stool) 62%,var(--scene-paper)));stroke:var(--dv-desk,color-mix(in srgb,var(--scene-stool) 62%,var(--scene-paper)));stroke-width:.5}
.dv-desk-edge{fill:var(--dv-desk-edge,color-mix(in srgb,var(--scene-stool) 70%,var(--scene-ink)));stroke:var(--dv-desk-edge,color-mix(in srgb,var(--scene-stool) 70%,var(--scene-ink)));stroke-width:.5}
.dv-leg{stroke:var(--dv-desk-edge,color-mix(in srgb,var(--scene-trim) 70%,var(--scene-ink)));stroke-width:.8}
.lobby-view-ceiling{fill:color-mix(in srgb,var(--scene-paper) 82%,var(--scene-floor))}.lobby-view-floor{fill:color-mix(in srgb,var(--scene-stool) 55%,var(--scene-floor))}.lobby-view-plank{stroke:color-mix(in srgb,var(--scene-stool) 80%,var(--scene-trim));stroke-width:.5;stroke-opacity:.6}
.lobby-view-wall{fill:color-mix(in srgb,var(--scene-paper) 75%,var(--scene-floor))}.lobby-view-back{fill:color-mix(in srgb,var(--scene-paper) 70%,var(--scene-floor))}.lobby-view-rail{stroke:color-mix(in srgb,var(--scene-stool) 55%,var(--scene-paper));stroke-width:1.2}
.lobby-view-light{fill:color-mix(in srgb,var(--scene-paper) 85%,var(--lamp));stroke:color-mix(in srgb,var(--lamp) 45%,var(--scene-floor));stroke-width:.3;stroke-linejoin:round}.lobby-view-arch{fill:color-mix(in srgb,var(--scene-stool) 60%,var(--scene-floor));stroke:var(--scene-trim);stroke-width:.3}
`;

/** `MIXED` with each `color-mix()` replaced by its larger share's token — the fallback a browser without it draws. */
const unmixed = (css) => css.replace(/color-mix\(in srgb,var\((--[a-z0-9-]+)\) (\d+)%,var\((--[a-z0-9-]+)\)\)/g, (all, a, share, b) => `var(${Number(share) >= 50 ? a : b})`);

/**
 * THE CLASSES THE ELEVATOR AND ITS CAB PAINT WITH — the leaves (the band's, the lobby's and the cab's), the elevator
 * control's focus and hit area, the cab's room, its call plate, and the office and the lobby seen through its doors, with
 * every mixed colour's fallback before its `@supports` block (`MIXED`). ⛔ NO COLOUR IS WRITTEN HERE, for `floor/painter.js`
 * `STYLE`'s reason: every one is a `var(--…)` token of `public/css/mezzanine.css`. The painter's `STYLE` carries this
 * text whole, and `paintCab()` writes it into the cab's own drawing.
 */
export const CAB_STYLE = `
.elevator-leaf{fill:var(--door);stroke:var(--door-edge);stroke-width:2}
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
.dv-skirting{fill:var(--dv-skirting,var(--house-trim));stroke:var(--dv-skirting,var(--house-trim));stroke-width:.5}.dv-shade-stop{stop-color:var(--scene-ink)}.dv-back-shade{fill:var(--scene-ink)}
.dv-partition-cap{fill:var(--dv-partition-cap,var(--house-trim));stroke:var(--dv-partition-cap,var(--house-trim));stroke-width:.5}
.dv-monitor{fill:var(--dv-monitor,var(--scene-paper));stroke:var(--dv-monitor,var(--scene-paper));stroke-width:.5}
.lobby-view-skirting{fill:var(--scene-stool)}.lobby-view-sky-stop{stop-color:var(--scene-glass)}.lobby-view-day-stop{stop-color:var(--scene-paper)}.lobby-view-shade-stop{stop-color:var(--scene-ink)}.lobby-view-back-shade{fill:var(--scene-ink)}
.lobby-view-sun{fill:var(--scene-paper);opacity:.3}.lobby-view-mat{fill:var(--lobby-mat)}.lobby-view-door-frame{fill:var(--door-edge)}.lobby-view-handle{fill:var(--door-edge)}.lobby-view-mullion{fill:none;stroke:var(--door-frame);stroke-width:.5}
${unmixed(MIXED)}@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}
`;

/**
 * ⛔ THE RIDE's DOORS MOVE CONTINUOUSLY — NEVER `steps()` (§ 12's *Ride door motion*, card#12038). The ride's leaf
 * motion is § 6.3's exception for the viewer's own ride, which is not claim-bearing, so no loop rate binds it; the
 * walks' leaves (`leafAnimation()`) are claim-bearing and stay quantised to the loop rate. How long each motion
 * takes is the phase's (`wire/ride.js`'s `RIDE_DOOR_MS`, through `ridePhase()`), never this file's.
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
 * (`paintCab()`'s viewBox). `back` is also the eye every view through the doors is seen from (`OFFICE.view`).
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
export function clippedTo(parent, defs, box, id) {
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
 * THE BARE ROOM BEHIND THE CAB's DOORS (§ 4.5's *cab's look*, card#12052; § 12's *Office view*), in centimetres: the room's
 * width, its depth beyond the doorway, its height, how far its left wall stands from the door's centre line, and the eye's
 * distance from the doorway — the rider's own eye in the cab (`CAB.back`, card#12071), so the room through the doors and
 * the cab around them are one view from one point. Its height is *Door-view scale*'s room height (the band's 160 px at
 * 200 / 144 cm per px), so a floor that arrives behind the doors replaces it under the same ceiling (card#12987).
 */
export const OFFICE = Object.freeze({ w: 520, d: 900, h: 222, left: 220, view: CAB.back });

/**
 * A room seen through a doorway (`box`, read as `CAB.door` — 110 × 200 cm — with the eye `CAB.eye` over the floor and `view`
 * cm behind the doorway, so its vanishing point is the cab's): `at(X, Y, Z)` — `X` across from the door's centre line, `Y` up
 * from the floor, `Z` back from the doorway — on the box, and `pts()` the polygon text of its points. The one projection
 * every view behind the cab's doors is drawn with.
 */
function throughDoor(box, view) {
    const kx = box.w / CAB.door.w;
    const ky = box.h / CAB.door.h;
    const at = (X, Y, Z) => {
        const s = view / (view + Z);

        return [box.x + box.w / 2 + X * s * kx, box.y + (CAB.door.h - CAB.eye + (CAB.eye - Y) * s) * ky];
    };
    const pts = (...ps) => ps.map((p) => p.map((v) => +v.toFixed(2)).join(',')).join(' ');

    return { at, pts, kx, ky };
}

/** The depth overlays a view's surfaces carry, deepening toward the back, as gradients under `defs` prefixed `id`. */
function viewShades(defs, id, stop, far) {
    for (const [name, x1, y1, x2, y2] of [['shade-left', 0, 0, 1, 0], ['shade-right', 1, 0, 0, 0], ['shade-floor', 0, 1, 0, 0], ['shade-ceiling', 0, 0, 0, 1]]) {
        const grad = node('linearGradient', { id: `${id}-${name}`, x1, y1, x2, y2 }, defs);
        node('stop', { offset: 0, class: stop, 'stop-opacity': 0 }, grad);
        node('stop', { offset: 1, class: stop, 'stop-opacity': far[name] }, grad);
    }
}

/**
 * THE BARE ROOM, REUSABLE (card#12052's office, reduced by card#12987 to its shell): a one-point perspective of the room of
 * `OFFICE` seen through a doorway, drawn into `parent` and clipped to `box` (`{x, y, w, h}`, the doorway, `throughDoor()`'s
 * lens) — the floor and its boards, the ceiling with its lights, two walls and the far wall with a skirting along each foot,
 * every surface shaded deeper toward the back — and NOTHING STANDING IN IT: no desk, wall cell, accent, piece, window or
 * daylight. It is the view behind the doors at a floor's stop whose data the client does not wholly hold
 * (`drawFloorView()`'s bare room, plan-12987 § 5).
 *
 * ⛔ IT IS SCENERY AND NOTHING ELSE (§ 6.3): no character, seat, name, badge or word, no datum — its only inputs are a box
 * and an id — and nothing in it moves. Its colours are its classes in `STYLE` — the floor view's `--dv-…` carriers where an
 * ancestor sets them (`drawFloorView()`'s root group, from the stop's theme), the sheet's tokens and their mixes where none
 * does — never an argument or an inline colour. `id` prefixes the ids it defines.
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} box
 * @param {string} [id]
 * @returns {{clip: {x: number, y: number, w: number, h: number}}} the rect it is clipped to
 */
export function drawOfficeView(parent, box, id = 'office') {
    const { at, pts } = throughDoor(box, OFFICE.view);
    const [l, r, H, D] = [-OFFICE.left, OFFICE.w - OFFICE.left, OFFICE.h, OFFICE.d];
    const g = node('g', { class: 'dv-room' }, parent);
    const defs = node('defs', {}, g);

    viewShades(defs, id, 'dv-shade-stop', { 'shade-left': 0.22, 'shade-right': 0.22, 'shade-floor': 0.2, 'shade-ceiling': 0.18 });

    const room = clippedTo(g, defs, box, id);
    const face = (cls, points, overlay) => {
        node('polygon', { points, class: cls }, room);
        if (overlay !== undefined) {
            node('polygon', { points, fill: `url(#${id}-${overlay})`, class: 'dv-shade' }, room);
        }
    };

    face('dv-ceiling', pts(at(l, H, 0), at(r, H, 0), at(r, H, D), at(l, H, D)), 'shade-ceiling');
    face('dv-floor', pts(at(l, 0, 0), at(r, 0, 0), at(r, 0, D), at(l, 0, D)), 'shade-floor');
    for (let X = l + 40; X < r; X += 40) {
        const [a, b] = [at(X, 0, 0), at(X, 0, D)];
        node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: 'dv-plank' }, room);
    }
    face('dv-wall left', pts(at(l, H, 0), at(l, H, D), at(l, 0, D), at(l, 0, 0)), 'shade-left');
    face('dv-wall right', pts(at(r, H, 0), at(r, H, D), at(r, 0, D), at(r, 0, 0)), 'shade-right');
    const [bx, by] = at(l, H, D);
    const [bx2, by2] = at(r, 0, D);
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'dv-back' }, room);
    // The far wall, all of it at the room's full depth: one even shade, as deep as the walls' far edges.
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'dv-shade dv-back-shade', 'fill-opacity': 0.22 }, room);
    for (const X of [l, r]) {
        face('dv-skirting', pts(at(X, 10, 0), at(X, 10, D), at(X, 0, D), at(X, 0, 0)));
    }
    face('dv-skirting', pts(at(l, 10, D), at(r, 10, D), at(r, 0, D), at(l, 0, D)));

    for (const Z of VIEW_LIGHTS.at(D)) {
        face('dv-light', pts(at(-VIEW_LIGHTS.w / 2, H, Z), at(VIEW_LIGHTS.w / 2, H, Z), at(VIEW_LIGHTS.w / 2, H, Z + VIEW_LIGHTS.d), at(-VIEW_LIGHTS.w / 2, H, Z + VIEW_LIGHTS.d)));
    }

    return { clip: box };
}

/**
 * The ceiling's lights in a view behind the cab's doors (card#12052's office's, kept by the floor view, plan-12987 § 3.3): a
 * panel `w` × `d` cm on the door's centre line every `pitch` cm from `first`, each wholly short of the depth `D` (`at(D)`).
 */
const VIEW_LIGHTS = Object.freeze({
    w: 90,
    d: 50,
    first: 240,
    pitch: 225,
    at(D) {
        return Array.from({ length: Math.max(0, Math.floor((D - this.d - this.first) / this.pitch) + 1) }, (_, i) => this.first + i * this.pitch);
    },
});

/**
 * THE FLOOR THE CAB's DOORS OPEN ONTO (§ 4.5's *Through the open doors at a FLOOR is that floor*, card#12987; the plan's
 * § 3.3): `view`, the stop's model (`floor/door-view.js`'s `doorView()`, which the screen resolved and the ride carries as
 * `scene.ride.view`), PROJECTED into `parent` and clipped to `box` with `throughDoor()`'s lens — the floor plane, the
 * ceiling at the room's height with the bare room's lights, the accent runs flat on the plane, then every body in the order
 * the model answers it. A body whose projection lies wholly outside the doorway is culled; of a body's faces only those that
 * face the eye are drawn — its near face, its top where it is lower than the eye, its end where it lies wholly to one side
 * of the door's centre line. A standing piece is its theme's own document, an `<image>` on its foot line (§ 10.6 item 2's
 * asset shape), facing the rider (§ 14 item 48's ruling 1). A bare model — or none — is the bare room
 * (`drawOfficeView()`), in the stop's palette where the model names one.
 *
 * ⛔ IT DECIDES NOTHING AND IS SCENERY (§ 6.3): no word, no seat, no character, no fact and no motion; no window and no
 * daylight (§ 10.6 item 3). ITS COLOURS ARE THE THEME's CARRIERS: the root group's `style` holds the model's palette as
 * `--dv-…` custom properties and nothing else, and every surface is painted by its `.dv-…` class in `STYLE`, whose `var()`
 * falls to the sheet's tokens when the root carries none (no theme module held) — never an inline colour. Each face's class
 * strokes its own fill, so adjacent faces leave no hairline where a shared edge lands on a fractional pixel (the plan's § 7).
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} box
 * @param {string} [id]
 * @param {object|null} [view] `doorView()`'s model
 * @returns {{clip: {x: number, y: number, w: number, h: number}}} the rect it is clipped to
 */
export function drawFloorView(parent, box, id = 'floor', view = null) {
    const palette = view?.palette ?? null;
    const g = node('g', {
        class: 'floor-view',
        style: palette === null ? null : Object.entries(palette).map(([property, colour]) => `${property}:${colour};`).join(''),
    }, parent);

    if (view === null || view.bare) {
        return drawOfficeView(g, box, id);
    }

    const { at, pts } = throughDoor(box, OFFICE.view);
    const defs = node('defs', {}, g);

    viewShades(defs, id, 'dv-shade-stop', { 'shade-left': 0.22, 'shade-right': 0.22, 'shade-floor': 0.2, 'shade-ceiling': 0.18 });

    const room = clippedTo(g, defs, box, id);
    const poly = (cls, ...corners) => node('polygon', { points: pts(...corners), class: cls }, room);
    const { x1, x2, z1, z2 } = view.floor;
    const H = view.height;

    poly('dv-ceiling', at(x1, H, z1), at(x2, H, z1), at(x2, H, z2), at(x1, H, z2));
    node('polygon', { points: pts(at(x1, H, z1), at(x2, H, z1), at(x2, H, z2), at(x1, H, z2)), fill: `url(#${id}-shade-ceiling)`, class: 'dv-shade' }, room);
    for (const Z of VIEW_LIGHTS.at(z2)) {
        poly('dv-light', at(-VIEW_LIGHTS.w / 2, H, Z), at(VIEW_LIGHTS.w / 2, H, Z), at(VIEW_LIGHTS.w / 2, H, Z + VIEW_LIGHTS.d), at(-VIEW_LIGHTS.w / 2, H, Z + VIEW_LIGHTS.d));
    }
    poly('dv-floor', at(x1, 0, z1), at(x2, 0, z1), at(x2, 0, z2), at(x1, 0, z2));
    node('polygon', { points: pts(at(x1, 0, z1), at(x2, 0, z1), at(x2, 0, z2), at(x1, 0, z2)), fill: `url(#${id}-shade-floor)`, class: 'dv-shade' }, room);
    for (const a of view.accents) {
        if (inDoorway(box, at, a.x1, a.x2, 0, 0, a.z1, a.z2)) {
            poly('dv-accent', at(a.x1, 0, a.z1), at(a.x2, 0, a.z1), at(a.x2, 0, a.z2), at(a.x1, 0, a.z2));
        }
    }

    for (const b of view.bodies) {
        if (inDoorway(box, at, b.x1, b.x2, b.y1, b.y2, b.z1, b.z2)) {
            drawBody(room, at, pts, b);
        }
    }

    return { clip: box };
}

/** Whether a body's projected bounding rect meets the doorway `box` — a body wholly outside it is culled. */
function inDoorway(box, at, x1, x2, y1, y2, z1, z2) {
    const corners = [x1, x2].flatMap((X) => [y1, y2].flatMap((Y) => [z1, z2].map((Z) => at(X, Y, Z))));
    const xs = corners.map((c) => c[0]);
    const ys = corners.map((c) => c[1]);

    return Math.max(...xs) > box.x && Math.min(...xs) < box.x + box.w && Math.max(...ys) > box.y && Math.min(...ys) < box.y + box.h;
}

/**
 * Each body kind's classes: its faces', and its top's where it is lower than the eye. A side-wall strip and a wall cell are
 * the room's wall; the far wall the bare room's back; a desk's top its desk colour over its edge, a monitor's back its own.
 */
const BODY_CLASS = Object.freeze({
    'far-wall': Object.freeze({ face: 'dv-back', top: 'dv-back' }),
    'side-wall': Object.freeze({ face: 'dv-wall', top: 'dv-wall' }),
    wall: Object.freeze({ face: 'dv-wall', top: 'dv-wall' }),
    partition: Object.freeze({ face: 'dv-partition', top: 'dv-partition-cap' }),
    'desk-top': Object.freeze({ face: 'dv-desk-edge', top: 'dv-desk' }),
    monitor: Object.freeze({ face: 'dv-monitor', top: 'dv-monitor' }),
});

/** One body's faces that face the eye (`drawFloorView()`'s rule), or a standing piece's document on its foot line. */
function drawBody(room, at, pts, b) {
    if (b.kind === 'billboard') {
        const [left, head] = at(b.x1, b.y2, b.z1);
        const [right, foot] = at(b.x2, b.y1, b.z1);

        node('image', { href: b.href, x: +left.toFixed(2), y: +head.toFixed(2), width: +(right - left).toFixed(2), height: +(foot - head).toFixed(2), preserveAspectRatio: 'none', class: 'dv-piece' }, room);

        return;
    }

    const cls = BODY_CLASS[b.kind];

    // Its end, on the side of the door's centre line it lies wholly on — a zero-width strip is nothing but its end.
    if (b.z2 > b.z1 && (b.x1 > 0 || b.x2 < 0)) {
        const X = b.x1 > 0 ? b.x1 : b.x2;

        node('polygon', { points: pts(at(X, b.y2, b.z1), at(X, b.y2, b.z2), at(X, b.y1, b.z2), at(X, b.y1, b.z1)), class: cls.face }, room);
    }

    if (b.z2 > b.z1 && b.x2 > b.x1 && b.y2 < CAB.eye) {
        node('polygon', { points: pts(at(b.x1, b.y2, b.z1), at(b.x2, b.y2, b.z1), at(b.x2, b.y2, b.z2), at(b.x1, b.y2, b.z2)), class: cls.top }, room);
    }

    if (b.x2 > b.x1) {
        node('polygon', { points: pts(at(b.x1, b.y2, b.z1), at(b.x2, b.y2, b.z1), at(b.x2, b.y1, b.z1), at(b.x1, b.y1, b.z1)), class: cls.face }, room);
    }

    // A desk's legs, as marks on its top's near face.
    if (b.kind === 'desk-top') {
        for (const X of [b.x1 + 6, b.x2 - 6]) {
            const [p, q] = [at(X, b.y2, b.z1), at(X, b.y1, b.z1)];

            node('line', { x1: +p[0].toFixed(2), y1: +p[1].toFixed(2), x2: +q[0].toFixed(2), y2: +q[1].toFixed(2), class: 'dv-leg' }, room);
        }
    }
}

/**
 * THE LOBBY THE CAB's DOORS OPEN ONTO (§ 4.5's *Through the open doors at the LOBBY is the lobby*, card#12113; § 12's
 * *Lobby view*), in centimetres: the room's width, its depth beyond the doorway, its height; the building's front doors
 * on its far wall, centred on the cab's door line; the window either side of them; the eye's distance from the
 * doorway — the rider's own eye in the cab (`CAB.back`), so the lobby and the cab are one view from one point, as the
 * office is; and the ceiling's recessed panels, their size and their centres across and in depth.
 */
export const LOBBY_VIEW = Object.freeze({
    w: 1000,
    d: 1400,
    h: 320,
    doors: Object.freeze({ w: 240, h: 260 }),
    window: 160,
    view: CAB.back,
    panel: Object.freeze({ w: 120, d: 100, across: 250, deep: 200 }),
});

/**
 * THE LOBBY OUTSIDE, REUSABLE (card#12113): a one-point perspective of the room of `LOBBY_VIEW`, seen from the elevator
 * wall toward the street through a doorway, drawn into `parent` and clipped to `box` (`{x, y, w, h}`, the doorway, read
 * as `CAB.door` with the eye `CAB.eye` over the floor, so its vanishing point is the cab's). A boarded floor receding to
 * the far wall; the ceiling at `LOBBY_VIEW.h` carrying its even grid of recessed panels; the two side walls plain but
 * for the rail and the skirting; and on the far wall the building's front doors — two glass leaves under an arch —
 * with the welcome mat before them and one window either side, daylight in the glass and the light it lays on the
 * floor. The lobby room's furnishing — bench, picture, side window — is not drawn (§ 4.5 says why).
 *
 * ⛔ IT IS SCENERY UNDER THE OFFICE's RULE EXACTLY (§ 6.3): no word, no seat, no fact — its only inputs are a box and an
 * id — and no motion; THE WINDOWS' DAYLIGHT IS FIXED, never A17's sky. Its colours are its classes in `STYLE`, the
 * sheet's tokens and their mixes — the mat's the sheet's `--lobby-mat`, the lobby palette's own — never an argument or
 * an inline colour. `id` prefixes the ids it defines, so two views drawn into one document do not share one.
 *
 * @param {Element} parent
 * @param {{x: number, y: number, w: number, h: number}} box
 * @param {string} [id]
 * @returns {{clip: {x: number, y: number, w: number, h: number}}} the rect it is clipped to
 */
export function drawLobbyView(parent, box, id = 'lobby') {
    const V = LOBBY_VIEW;
    const { at, pts, ky } = throughDoor(box, V.view);
    const [l, r, H, D] = [-V.w / 2, V.w / 2, V.h, V.d];
    const g = node('g', { class: 'lobby-view' }, parent);
    const defs = node('defs', {}, g);
    const shade = (name, x1, y1, x2, y2, far) => {
        const grad = node('linearGradient', { id: `${id}-${name}`, x1, y1, x2, y2 }, defs);
        node('stop', { offset: 0, class: 'lobby-view-shade-stop', 'stop-opacity': 0 }, grad);
        node('stop', { offset: 1, class: 'lobby-view-shade-stop', 'stop-opacity': far }, grad);
    };
    shade('shade-left', 0, 0, 1, 0, 0.2);
    shade('shade-right', 1, 0, 0, 0, 0.2);
    shade('shade-floor', 0, 1, 0, 0, 0.18);
    shade('shade-ceiling', 0, 0, 0, 1, 0.18);
    // Daylight in the glass, fixed: the sky's glass at the head, paler toward the sill.
    const glass = node('linearGradient', { id: `${id}-glass`, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
    node('stop', { offset: 0, class: 'lobby-view-sky-stop' }, glass);
    node('stop', { offset: 1, class: 'lobby-view-day-stop' }, glass);

    const room = clippedTo(g, defs, box, id);
    const face = (cls, points, overlay) => {
        node('polygon', { points, class: cls }, room);
        if (overlay !== undefined) {
            node('polygon', { points, fill: `url(#${id}-${overlay})`, class: 'lobby-view-shade' }, room);
        }
    };
    const line = (cls, a, b) => node('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: cls }, room);

    // The room: the ceiling, the floor and its boards, the two side walls, the street wall at the room's full depth.
    face('lobby-view-ceiling', pts(at(l, H, 0), at(r, H, 0), at(r, H, D), at(l, H, D)), 'shade-ceiling');
    face('lobby-view-floor', pts(at(l, 0, 0), at(r, 0, 0), at(r, 0, D), at(l, 0, D)), 'shade-floor');
    for (let X = l + 100; X < r; X += 100) {
        line('lobby-view-plank', at(X, 0, 0), at(X, 0, D));
    }
    face('lobby-view-wall left', pts(at(l, H, 0), at(l, H, D), at(l, 0, D), at(l, 0, 0)), 'shade-left');
    face('lobby-view-wall right', pts(at(r, H, 0), at(r, H, D), at(r, 0, D), at(r, 0, 0)), 'shade-right');
    const [bx, by] = at(l, H, D);
    const [bx2, by2] = at(r, 0, D);
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'lobby-view-back' }, room);
    // The far wall, all of it at the room's full depth: one even shade.
    node('rect', { x: bx, y: by, width: bx2 - bx, height: by2 - by, class: 'lobby-view-shade lobby-view-back-shade', 'fill-opacity': 0.12 }, room);

    // The skirting along the three walls' feet, and the rail along the two side walls at 95 cm.
    for (const X of [l, r]) {
        face('lobby-view-skirting', pts(at(X, 10, 0), at(X, 10, D), at(X, 0, D), at(X, 0, 0)));
        line('lobby-view-rail', at(X, 95, 0), at(X, 95, D));
    }
    face('lobby-view-skirting', pts(at(l, 10, D), at(r, 10, D), at(r, 0, D), at(l, 0, D)));

    // The ceiling's recessed panels (the operator's request of 2026-10-20): an even grid, `panel.across` centres across
    // the room's width and `panel.deep` centres in depth, each `panel.w` across and `panel.d` deep, drawn in the view's
    // perspective — the doorway's clip leaves the rows above its head, and the columns past its jambs, out of view.
    const { w: pw, d: pd, across, deep } = V.panel;
    for (let X = l + across / 2; X < r; X += across) {
        for (let Z = deep / 2; Z < D; Z += deep) {
            face('lobby-view-light', pts(at(X - pw / 2, H, Z - pd / 2), at(X + pw / 2, H, Z - pd / 2), at(X + pw / 2, H, Z + pd / 2), at(X - pw / 2, H, Z + pd / 2)));
        }
    }

    // The street wall's openings, as their corners on the far wall: the doors centred on the door line, a window
    // either side of them centred 300 cm out, its sill at 90 cm and its head at 250.
    const [dw, dh] = [V.doors.w, V.doors.h];
    const windows = [-300, 300].map((cx) => [cx - V.window / 2, cx + V.window / 2]);
    const sill = 90;
    const head = 250;

    // The daylight the doors and the windows lay on the floor, reaching into the room from the street wall.
    node('polygon', { points: pts(at(-dw / 2, 0, D), at(dw / 2, 0, D), at(dw / 2 + 60, 0, D - 300), at(-dw / 2 - 60, 0, D - 300)), class: 'lobby-view-sun' }, room);
    for (const [x1, x2] of windows) {
        node('polygon', { points: pts(at(x1, 0, D), at(x2, 0, D), at(x2 + (x2 > 0 ? 60 : -60), 0, D - 300), at(x1 + (x1 > 0 ? 60 : -60), 0, D - 300)), class: 'lobby-view-sun' }, room);
    }

    // The welcome mat before the doors: 200 cm across and 100 cm deep, against the street wall.
    face('lobby-view-mat', pts(at(-100, 0, D - 100), at(100, 0, D - 100), at(100, 0, D), at(-100, 0, D)));

    // The front doors: the arch over them, the frame, two glass leaves meeting on the door line, a handle on each.
    const [ax, ay] = at(-dw / 2, dh, D);
    const [ax2] = at(dw / 2, dh, D);
    const archRise = 40 * (V.view / (V.view + D)) * ky;
    node('path', { d: `M${+ax.toFixed(2)} ${+ay.toFixed(2)}A${+((ax2 - ax) / 2).toFixed(2)} ${+archRise.toFixed(2)} 0 0 1 ${+ax2.toFixed(2)} ${+ay.toFixed(2)}Z`, class: 'lobby-view-arch' }, room);
    face('lobby-view-door-frame', pts(at(-dw / 2, dh, D), at(dw / 2, dh, D), at(dw / 2, 0, D), at(-dw / 2, 0, D)));
    for (const [x1, x2] of [[-dw / 2 + 6, -3], [3, dw / 2 - 6]]) {
        node('polygon', { points: pts(at(x1, dh - 6, D), at(x2, dh - 6, D), at(x2, 0, D), at(x1, 0, D)), fill: `url(#${id}-glass)`, class: 'lobby-view-glass' }, room);
    }
    for (const X of [-30, 20]) {
        face('lobby-view-handle', pts(at(X, 110, D), at(X + 10, 110, D), at(X + 10, 104, D), at(X, 104, D)));
    }

    // A window, given its four corners as drawn: its glass in daylight and its frame, an outline and a cross of mullions.
    for (const [x1, x2] of windows) {
        const [a, b, c, d] = [at(x1, head, D), at(x2, head, D), at(x2, sill, D), at(x1, sill, D)];
        node('polygon', { points: pts(a, b, c, d), fill: `url(#${id}-glass)`, class: 'lobby-view-glass' }, room);
        const mid = (p, q) => [(p[0] + q[0]) / 2, (p[1] + q[1]) / 2];
        node('path', { d: `M${pts(a, b, c, d, a).replace(/ /g, 'L')}M${pts(mid(a, b), mid(d, c)).replace(' ', 'L')}M${pts(mid(a, d), mid(b, c)).replace(' ', 'L')}`, class: 'lobby-view-mullion' }, room);
    }

    return { clip: box };
}

/**
 * THE CAB's COLOURS (§ 4.5, card#12039) onto `el`, the cab dialog: the house theme's elevator palette — its
 * `PALETTE.elevator` frame and lamp, handed in as data (`{frame, lamp}`, or `null` when that theme did not load) — as
 * `--cab-frame` and `--cab-lamp`, which the cab's drawing (`paintCab()`) and its button popup (the sheet's `.floor-cab*`
 * rules) both read. The palette's one home stays the theme module; these properties carry it, and are not a copy.
 * `data-themed` marks the dialog as carrying it, which is what makes the popup's body the frame and its words the room's
 * ink (card#12047). No palette removes all three, and the sheet's own defaults on `.floor-cab` answer instead: the door
 * frame's and the lamp's tokens for the drawing, and the page's own panel and ink for the popup, so its words stay
 * legible. A module function (card#12113), so a page that constructs no painter — the lobby, which has no desk to
 * paint — calls it with the palette it loaded; the painter's own `cabTheme(el)` hands it the palette it holds.
 *
 * @param {HTMLElement} el
 * @param {{frame: string, lamp: string}|null} palette
 */
export function cabTheme(el, palette) {
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
 * What the open doors look out on, by `scene.ride`'s `beyond` (§ 4.5): at a floor's stop that floor (the ride's `view`, the
 * model the screen resolved for its `stop`), at the lobby's the lobby.
 */
const BEYOND = Object.freeze({ office: drawFloorView, lobby: drawLobbyView });

/**
 * THE CAB's INTERIOR (§ 4.5, card#11795; in depth, card#12051) into `host`, the cab dialog's own drawing element:
 * the room `drawCabInterior()` draws from the rider's eye (card#12071) — one cab, the frame its lens cuts the drawing's
 * whole space — and on its back wall the door: its frame in the house theme's elevator palette (`--cab-frame`,
 * `cabTheme()`'s, set on the dialog), a plain dial and its lamp (`--cab-lamp`) — no numeral and no arrow — the dark
 * doorway, the layer behind the leaves (`.cab-beyond`: what the open doors look out on — the ride's `beyond`, read here
 * and never inferred from the page this runs on: `drawFloorView()`'s projection of the ride's `view` for a floor's stop,
 * card#12987, and `drawLobbyView()`'s lobby for the lobby's, card#12113), and the two leaves in the building's door colours, moving as
 * `cabLeaves()` says, the seam between them being their own edges; beside the door's frame, under the layer behind the
 * leaves, the decorative call plate (`drawCallPlate()`, card#12071). The cab's space is fitted WHOLE into `host`
 * (`meet`, card#12039) and nothing is drawn past it (`overflow: hidden`, card#12051): the room is a box taller than it
 * is wide in the dialog's dark, its door's head and lamp in view at any window shape. No `<text>`: every word in the
 * cab is the panel's. Painted once per phase entry; `null` empties it. A module function (card#12113): both pages'
 * cabs are this one, and the painter hands its own on.
 *
 * @param {Element} host
 * @param {{phase: string, elapsed_ms: number, ms: number, door_delay_ms: number|null, beyond: 'office'|'lobby', view: object|null}|null} ride
 *        `scene.ride` — `view` the floor model behind the doors at a floor's stop
 */
export function paintCab(host, ride) {
    if (ride === null || ride === undefined) {
        host.replaceChildren();

        return;
    }

    const cab = node('svg', {
        viewBox: `0 0 ${CAB.w} ${CAB.h}`, width: '100%', height: '100%', preserveAspectRatio: 'xMidYMid meet', overflow: 'hidden', class: 'cab-scene', 'aria-hidden': 'true',
    });

    // The cab's own shape, for the layout that sets the drawing's box beside the popup (the sheet's `.floor-cab-doors`).
    host.style.setProperty('--cab-aspect', `${CAB.w} / ${CAB.h}`);
    node('style', {}, cab).textContent = CAB_STYLE;

    const { back } = drawCabInterior(cab, { x: 0, y: 0, w: CAB.w, h: CAB.h });
    // The door on the back wall: the leaves' size in the room, centred, standing on the back wall's foot.
    const w = (CAB.door.w * back.w) / CAB.w;
    const h = (CAB.door.h * back.h) / CAB.h;
    const L = { x: back.x + (back.w - w) / 2, y: back.y + back.h - h, w, h };
    // The door's trim — frame, recess, dial, sill — keeps the proportions it was drawn at for leaves 84 units wide
    // (the floor's own elevator's, `floor-layout.js`'s `ELEVATOR`): `f` is this door's scale against that width. A look, not a fact.
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
    // open doors look out on, the stop the ride's `beyond` names (card#12052, card#12113), clipped to the doorway and
    // still: the leaves reveal it.
    BEYOND[ride.beyond](node('g', { class: 'cab-beyond' }, cab), L, `cab-${ride.beyond}`, ride.view);

    const style = cabLeaves(ride);

    // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.
    node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);
    node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);

    host.replaceChildren(cab);
}
