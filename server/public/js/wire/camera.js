/**
 * THE CAMERA — `docs/design/FLOOR.md` Appendix B row 15's model: § 4.5's viewport, as a view
 * transform over a scene's space. Zoom and pan, the clamp that keeps what is framed in view, and the
 * fit that frames its whole extent — exposed as data, a frozen value every function below returns a
 * new copy of.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE MACHINERY AT TWO SCALES (card#7341's scope addition, operator 2026-08-26: "same one zoom/pan
 * machinery serves both scales"). Nothing here knows what a floor is. It frames a RECT in a scene's
 * own coordinates — the floor's scene extent on `/floor/{floor}` (row 15), every plate of the
 * building on `/` (row 16, `lobby/lobby-screen.js`) — so the second scale is a second caller, never a
 * second camera. What the second caller needed and the first did not — zooming to one rect inside
 * what is framed, a plate inside the building — is `focusOn()` here, not a function of its own there.
 *
 * ⛔ A MODEL AND NOT A PAGE, for the reason every renderer in Appendix B keeps the split: there is no
 * browser on the build host, so every decision about where the viewer is looking is made here and
 * read headlessly (`Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest`, AT-D3-21), and
 * the page only wires the wheel and the pointers to these functions and sets the drawing's `viewBox`.
 *
 * ⛔ NAVIGATION IS NEVER STATE (§ 4.5). Nothing here reads a seat, writes to the animation log or
 * starts anything through the animation set: this module imports nothing, and a camera value holds
 * the viewer's head and nothing about the fleet. A glide is the viewer's and not the fleet's, so it
 * takes no § 6.2 row, and under `prefers-reduced-motion` it is a cut (`glideMs`).
 *
 * ⛔ THE NUMBERS BELOW ARE THE DRAWING's (§ 10.4's last bullet): FLOOR.md publishes no zoom step, no
 * zoom ceiling and no glide duration, because they carry no fact. The ratified reference's
 * (`docs/design/floor-preview/floor-preview.html`) are the worked example the step, the ceiling and
 * the glide were taken from. The reference zooms a step per wheel event whatever its size — the
 * defect `zoom()` states and does not copy — and zooms on the plain wheel, which the operator's ruling
 * of 2026-10-01 (card#11045) moved to Ctrl+wheel and the pinch, the plain wheel panning (`pan()`). So
 * the notch's scroll, the line's height and the arrow key's pan are this module's own.
 *
 * The value: `{ surface: {width, height}, bounds: {x, y, w, h} | null, zoom, x, y, fitted, view }` —
 * `surface` the drawing's size in CSS px; `bounds` the framed rect in scene px, `null` while nothing
 * is framed; `zoom` CSS px per scene px; `x`, `y` the scene point at the surface's top-left corner;
 * `fitted` whether the viewer has moved since the last fit; `view` the scene rect the surface shows,
 * which is what a painter sets as its `viewBox`.
 */

/** One wheel notch's zoom factor — the reference's `1.18`. */
export const ZOOM_STEP = 1.18;

/**
 * How far one notch scrolls, in CSS px: a Ctrl+wheel event zooms by `ZOOM_STEP` raised to its scroll
 * (after `PINCH_GAIN`) over this, so a trackpad pinch's stream of small deltas zooms in proportion to the
 * distance it covers rather than by a step per event, and no event zooms past one step.
 */
export const NOTCH_PX = 100;

/**
 * The gain on a Ctrl+wheel's scroll — every event `zoom()` takes. A browser delivers a trackpad pinch
 * as a wheel event with `ctrlKey` set, and its deltas are far smaller than a scroll's, so a pinch at the
 * scroll's rate barely zooms. d3-zoom's `defaultWheelDelta` (d3/d3-zoom `src/zoom.js`, read 2026-09-26)
 * multiplies a `ctrlKey` wheel delta by 10 for this reason, and this is that figure. A mouse's
 * Ctrl+notch, already a notch's scroll, saturates at the one-notch bound and is one step.
 * ⚠ NOT VERIFIED ON A REAL TRACKPAD: synthetic Ctrl+wheel events in headless Chromium were seen to zoom
 * (card#11045 PR-B); how a real pinch feels at this gain — on any browser or OS — has not been seen.
 * Safari's trackpad pinch does not come through this gain: `wire/camera-gestures.js` takes it as Safari's own
 * `gesture*` events, each a `pinch()` by its scale's ratio, and prevents the Ctrl+wheel Safari would send after
 * them (card#11045 PR-C).
 */
export const PINCH_GAIN = 10;

/** A `WheelEvent.DOM_DELTA_LINE` line in CSS px — the conventional 16 (one line of body text). */
export const LINE_PX = 16;

/** How far one arrow key pans, in CSS px on the surface. */
export const PAN_STEP_PX = 48;

/** The closest the viewer may zoom, in CSS px per scene px — the reference's ceiling of 4. */
export const ZOOM_CEILING = 4;

/** A glide's length — the reference's `.85s` — and never the length under reduced motion. */
export const GLIDE_MS = 850;

/**
 * A camera over a drawing surface with nothing framed yet.
 *
 * @param {{width: number, height: number}} surface the drawing's size in CSS px
 */
export function createCamera(surface) {
    return settle({ surface: sizeOf(surface), bounds: null, zoom: 1, x: 0, y: 0, fitted: false });
}

/**
 * Frame `bounds` — a scene's extent, handed over on every render. The FIRST framing fits it (§ 6.5's
 * setting and not a move: the first render frames at fit, with no transition); every later one keeps
 * the viewer's zoom and pan exactly and only re-clamps them to the new extent, because a render is
 * never the viewer moving their head (row 15: "survives every re-render").
 */
export function frameOn(camera, bounds) {
    if (camera.bounds === null) {
        return fit({ ...camera, bounds: rectOf(bounds) });
    }

    return clamp({ ...camera, bounds: rectOf(bounds) });
}

/**
 * Nothing framed any more — the lobby with no building to draw (`lobby/lobby-screen.js`: no snapshot
 * yet, no layout held, or no plate). The next framing is a first framing again, so a building that
 * comes back comes back at fit.
 */
export function unframe(camera) {
    return settle({ ...camera, bounds: null, fitted: false });
}

/**
 * Whether the camera frames nothing — no scene extent handed over yet, or `unframe()`d since. The one
 * predicate a page's camera wire asks before it takes an event from the browser (card#7343 r4b, the
 * seat's ruling): `camera-gestures.js`'s and `camera-keys.js`'s every gate, and whether the keys and the
 * zoom buttons are offered at all (`camera-keys.js`'s `offerKeys()`).
 */
export function framesNothing(camera) {
    return camera.bounds === null;
}

/** The fit: the framed rect whole in the surface, centred, at the zoom that just holds it. */
export function fit(camera) {
    if (camera.bounds === null) {
        return camera;
    }

    return settle({ ...centredOn(camera, camera.bounds, fitZoom(camera.surface, camera.bounds)), fitted: true });
}

/**
 * Zoom to a rect INSIDE what is framed: the rect whole in the surface, centred, at the zoom that just
 * holds it — within the zoom range, then the clamp. What is framed does not change, so the viewer can
 * still zoom back out to the whole of it. Row 16's zoom-to-a-plate: the building stays framed and the
 * view goes to one plate of it (card#7343).
 */
export function focusOn(camera, rect) {
    if (camera.bounds === null) {
        return camera;
    }

    const r = rectOf(rect);
    const [low, high] = zoomRange(camera);

    return clamp({ ...centredOn(camera, r, Math.min(high, Math.max(low, fitZoom(camera.surface, r)))), fitted: false });
}

/** The camera at `zoom` with the centre of `rect` at the surface's centre — the fit's and the focus's one centring. */
function centredOn(camera, rect, zoom) {
    return {
        ...camera,
        zoom,
        x: rect.x + rect.w / 2 - camera.surface.width / zoom / 2,
        y: rect.y + rect.h / 2 - camera.surface.height / zoom / 2,
    };
}

/**
 * Zoom by `factor` about a point on the surface (CSS px from its top-left): the scene point under
 * that point stays under it, then the clamp.
 */
export function zoomAt(camera, point, factor) {
    return pinch(camera, point, point, factor);
}

/**
 * A touch screen's two-finger pinch, one step of it (§ 4.5, the operator's ruling of 2026-10-01 on
 * card#11045: *two-finger pinch on touch screens zooms*): zoom by `factor` — the fingers' spread over
 * their spread at the last step — and carry the scene point that was under their midpoint `from` to
 * where the midpoint is now, `to` (both CSS px from the surface's top-left), then the clamp. So the
 * fingers zoom about their midpoint and, moving together, pan; `zoomAt()` is the one where the
 * midpoint stays put.
 */
export function pinch(camera, from, to, factor) {
    if (camera.bounds === null) {
        return camera;
    }

    const [low, high] = zoomRange(camera);
    const zoom = Math.min(high, Math.max(low, camera.zoom * factor));
    const at = toScene(camera, from);

    return clamp({ ...camera, zoom, x: at.x - to.x / zoom, y: at.y - to.y / zoom, fitted: false });
}

/**
 * A plain wheel event — a mouse's notch, or a trackpad's two-finger scroll — as a PAN (§ 4.5, the
 * operator's ruling of 2026-10-01 on card#11045: *plain mouse wheel / two-finger trackpad scroll pans the
 * view in any direction*). The view moves the way the page would scroll: `deltaY` down moves it down the
 * scene and `deltaX` right moves it right, each in CSS px after `deltaMode` is normalised — pixels as
 * they are, lines at `LINE_PX`, pages at the surface's width (for `deltaX`) or height (for `deltaY`) —
 * then the clamp. Ctrl+wheel is `zoom()`'s, never this.
 *
 * Returns the camera it leaves and whether the act CONSUMED the event — took it from the page's own
 * scroll. ⛔ THE EDGE RELEASES THE WHEEL TO THE PAGE (card#11045 Q3, the operator's ruling of 2026-10-01):
 * the wheel is consumed while the view can still move the wheel's way on an axis its delta moves along,
 * and released — `consumed: false`, the camera left as it is — once it can move that way on none, so the
 * page scrolls on past the drawing. Decided here, from where the clamp lets the view stand
 * (`panRange()`), and never inferred by a caller from an unchanged view. A camera that frames nothing
 * consumes nothing, and neither does a wheel that moves along no axis.
 *
 * @param {object} camera
 * @param {{deltaX?: number, deltaY?: number, deltaMode?: number}} delta the `WheelEvent`'s own members
 *        (a `WheelEvent` itself will do); each absent 0
 * @returns {{camera: object, consumed: boolean}}
 */
export function pan(camera, { deltaX = 0, deltaY = 0, deltaMode = 0 }) {
    if (camera.bounds === null) {
        return { camera, consumed: false };
    }

    // The edge (Q3): an axis takes the wheel only while the view can still move that axis's way.
    const [xs, ys] = panRange(camera);
    const room = (delta, pos, [low, high]) => (delta > 0 && pos < high) || (delta < 0 && pos > low);
    const consumed = room(deltaX, camera.x, xs) || room(deltaY, camera.y, ys);

    if (!consumed) {
        return { camera, consumed };
    }

    const ux = deltaMode === 2 ? camera.surface.width : deltaMode === 1 ? LINE_PX : 1;
    const uy = deltaMode === 2 ? camera.surface.height : deltaMode === 1 ? LINE_PX : 1;

    return { camera: panBy(camera, -deltaX * ux, -deltaY * uy), consumed };
}

/**
 * A Ctrl+wheel event at a point on the surface — a mouse's Ctrl+notch, or a trackpad's pinch, which a
 * browser delivers as a wheel event with `ctrlKey` set (§ 4.5, the operator's ruling of 2026-10-01 on
 * card#11045) — as a ZOOM about that point by `ZOOM_STEP ** (-scroll / NOTCH_PX)`, where `scroll` is
 * `deltaY` in CSS px after `deltaMode` is normalised (pixels as they are, lines at `LINE_PX`, pages at
 * the surface's height), multiplied by `PINCH_GAIN`. A negative `deltaY` zooms in. One event scrolls at
 * most one notch either way, after the gain, so a mouse's Ctrl+notch is one step and an accelerated
 * wheel, a page-mode event or a fast pinch cannot leap past one.
 *
 * ⛔ THE ZOOM IS PROPORTIONAL TO THE SCROLL AND NEVER A STEP PER EVENT. A trackpad fires dozens of
 * events of a few px for one pinch; a step per event took the floor from its fit to the ceiling in
 * a fraction of one.
 *
 * Returns the camera it leaves and whether the act consumed the event: a camera that frames something
 * takes every Ctrl+wheel from the browser's own page zoom.
 *
 * @param {object} camera
 * @param {{x: number, y: number}} point the cursor, in CSS px from the surface's top-left
 * @param {{deltaY: number, deltaMode?: number}} delta the `WheelEvent`'s own members; `deltaMode` 0
 *        (pixels), 1 (lines) or 2 (pages), absent 0
 * @returns {{camera: object, consumed: boolean}}
 */
export function zoom(camera, point, { deltaY, deltaMode = 0 }) {
    if (camera.bounds === null) {
        return { camera, consumed: false };
    }

    const unit = deltaMode === 2 ? camera.surface.height : deltaMode === 1 ? LINE_PX : 1;
    const scroll = Math.min(NOTCH_PX, Math.max(-NOTCH_PX, deltaY * unit * PINCH_GAIN));

    if (scroll === 0) {
        return { camera, consumed: true };
    }

    return { camera: zoomAt(camera, point, ZOOM_STEP ** (-scroll / NOTCH_PX)), consumed: true };
}

/**
 * `notches` wheel notches about the surface's centre — the keyboard's zoom and the zoom buttons',
 * which have no cursor to hold: positive in, negative out.
 */
export function zoomStep(camera, notches) {
    return zoomAt(camera, { x: camera.surface.width / 2, y: camera.surface.height / 2 }, ZOOM_STEP ** notches);
}

/** A drag by `dx`, `dy` CSS px: the scene moves with the pointer, then the clamp. */
export function panBy(camera, dx, dy) {
    if (camera.bounds === null) {
        return camera;
    }

    return clamp({ ...camera, x: camera.x - dx / camera.zoom, y: camera.y - dy / camera.zoom, fitted: false });
}

/**
 * The surface changed size. A camera still at fit stays at fit; one the viewer moved keeps its zoom
 * and the scene point at the surface's centre, then the clamp.
 */
export function resize(camera, surface) {
    const size = sizeOf(surface);

    if (camera.bounds === null) {
        return settle({ ...camera, surface: size });
    }

    if (camera.fitted) {
        return fit({ ...camera, surface: size });
    }

    const centre = toScene(camera, { x: camera.surface.width / 2, y: camera.surface.height / 2 });

    return clamp({
        ...camera,
        surface: size,
        x: centre.x - size.width / camera.zoom / 2,
        y: centre.y - size.height / camera.zoom / 2,
    });
}

/** The scene point under a point on the surface. */
export function toScene(camera, point) {
    return { x: camera.x + point.x / camera.zoom, y: camera.y + point.y / camera.zoom };
}

/** The zooms the viewer may reach: from the fit (the whole of what is framed) to the ceiling. */
export function zoomRange(camera) {
    const low = camera.bounds === null ? camera.zoom : fitZoom(camera.surface, camera.bounds);

    return [low, Math.max(low, ZOOM_CEILING)];
}

/** How long a glide to a new camera takes — none at all under `prefers-reduced-motion` (a cut). */
export function glideMs(reduce) {
    return reduce === true ? 0 : GLIDE_MS;
}

/**
 * A glide's camera at `t` in [0, 1] from `from` to `to`: the zoom geometrically (so a zoom-out and
 * a zoom-in of one factor take the same time), the centre linearly.
 */
export function between(from, to, t) {
    if (t >= 1 || from.bounds === null || to.bounds === null) {
        return to;
    }

    const zoom = from.zoom * (to.zoom / from.zoom) ** t;
    const centre = (c) => ({ x: c.x + c.surface.width / c.zoom / 2, y: c.y + c.surface.height / c.zoom / 2 });
    const a = centre(from);
    const b = centre(to);
    const cx = a.x + (b.x - a.x) * t;
    const cy = a.y + (b.y - a.y) * t;

    return settle({ ...to, zoom, x: cx - to.surface.width / zoom / 2, y: cy - to.surface.height / zoom / 2 });
}

/**
 * The clamp that keeps the framed rect in view, per axis: where the view is smaller than the rect it
 * stays on the rect, and where it is larger the rect stays wholly inside it — never a view showing
 * none of it, whatever the viewer drags.
 */
function clamp(camera) {
    const [low, high] = zoomRange(camera);
    const zoom = Math.min(high, Math.max(low, camera.zoom));
    const [xs, ys] = panRange({ ...camera, zoom });

    return settle({
        ...camera,
        zoom,
        x: Math.min(Math.max(camera.x, xs[0]), xs[1]),
        y: Math.min(Math.max(camera.y, ys[0]), ys[1]),
    });
}

/**
 * Where the clamp lets the view's top-left stand at the camera's zoom, per axis, as `[low, high]`: where
 * the view is smaller than the framed rect it stays on the rect, and where it is larger the rect stays
 * wholly inside it. The clamp's one statement, and `pan()`'s edge: a camera at `high` on an axis can pan
 * no further that way.
 */
function panRange(camera) {
    const b = camera.bounds;
    const axis = (span, start, length) => {
        const a = start;
        const c = start + length - span;

        return [Math.min(a, c), Math.max(a, c)];
    };

    return [axis(camera.surface.width / camera.zoom, b.x, b.w), axis(camera.surface.height / camera.zoom, b.y, b.h)];
}

function fitZoom(surface, bounds) {
    return Math.min(surface.width / bounds.w, surface.height / bounds.h);
}

/** The value, frozen, with the scene rect it shows. */
function settle(camera) {
    return Object.freeze({
        ...camera,
        view: Object.freeze({
            x: camera.x,
            y: camera.y,
            w: camera.surface.width / camera.zoom,
            h: camera.surface.height / camera.zoom,
        }),
    });
}

/**
 * A size in CSS px — a drawing surface, or the viewport a page supplies — refused rather than guessed
 * when it is missing or not positive, and frozen.
 */
export function sizeOf(size) {
    if (!(size?.width > 0) || !(size?.height > 0)) {
        throw new Error('a surface or viewport needs a positive width and height in CSS px');
    }

    return Object.freeze({ width: size.width, height: size.height });
}

function rectOf(bounds) {
    if (!(bounds?.w > 0) || !(bounds?.h > 0)) {
        throw new Error('the camera frames a rect with a positive width and height');
    }

    return Object.freeze({ x: bounds.x, y: bounds.y, w: bounds.w, h: bounds.h });
}
