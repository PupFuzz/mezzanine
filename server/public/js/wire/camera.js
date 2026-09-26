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
 * building on `/` (row 16) — so the second scale is a second caller, never a second camera.
 *
 * ⛔ A MODEL AND NOT A PAGE, for the reason every renderer in Appendix B keeps the split: there is no
 * browser on the build host, so every decision about where the viewer is looking is made here and
 * read headlessly (`Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest`, AT-D3-21), and
 * the page only wires the wheel and the pointer to these functions and sets the drawing's `viewBox`.
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
 * defect `wheel()` states and does not copy — so the notch's scroll, the line's height and the arrow
 * key's pan are this module's own.
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
 * How far one notch scrolls, in CSS px: a wheel event zooms by `ZOOM_STEP` raised to its scroll over
 * this, so a mouse's one notch of 100 px is one step and a trackpad's or a pinch's stream of small
 * deltas zooms in proportion to the distance it covers rather than by a step per event.
 */
export const NOTCH_PX = 100;

/**
 * The gain on a pinch's scroll. A browser delivers a trackpad pinch as a wheel event with `ctrlKey`
 * set, and its deltas are far smaller than a scroll's, so a pinch at the scroll's rate barely zooms.
 * d3-zoom's `defaultWheelDelta` (d3/d3-zoom `src/zoom.js`, read 2026-09-26) multiplies a `ctrlKey`
 * wheel delta by 10 for this reason, and this is that figure.
 * ⚠ NOT BROWSER-VERIFIED: no browser runs on the build host, so how a pinch feels at this gain — on
 * any browser or OS — has not been seen; the harness holds only the arithmetic.
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
 * Nothing framed any more — the route has left the drawn floor (the capability floor's list view).
 * The next framing is a first framing again, so a floor that comes back comes back at fit.
 */
export function unframe(camera) {
    return settle({ ...camera, bounds: null, fitted: false });
}

/** The fit: the framed rect whole in the surface, centred, at the zoom that just holds it. */
export function fit(camera) {
    if (camera.bounds === null) {
        return camera;
    }

    const b = camera.bounds;
    const zoom = fitZoom(camera.surface, b);

    return settle({
        ...camera,
        zoom,
        x: b.x + b.w / 2 - camera.surface.width / zoom / 2,
        y: b.y + b.h / 2 - camera.surface.height / zoom / 2,
        fitted: true,
    });
}

/**
 * Zoom by `factor` about a point on the surface (CSS px from its top-left): the scene point under
 * that point stays under it, then the clamp.
 */
export function zoomAt(camera, point, factor) {
    if (camera.bounds === null) {
        return camera;
    }

    const [low, high] = zoomRange(camera);
    const zoom = Math.min(high, Math.max(low, camera.zoom * factor));
    const at = toScene(camera, point);

    return clamp({ ...camera, zoom, x: at.x - point.x / zoom, y: at.y - point.y / zoom, fitted: false });
}

/**
 * One wheel event at a point on the surface — a mouse's notch, a trackpad's scroll, or a pinch (which a
 * browser delivers as a wheel event with `ctrlKey` set, through this same path): a zoom about that point
 * by `ZOOM_STEP ** (-scroll / NOTCH_PX)`, where `scroll` is `deltaY` in CSS px after `deltaMode` is
 * normalised (pixels as they are, lines at `LINE_PX`, pages at the surface's height) and, on a pinch,
 * multiplied by `PINCH_GAIN`. A negative `deltaY` zooms in. One event scrolls at most one notch either
 * way, after the gain, so an accelerated wheel, a page-mode event or a fast pinch cannot leap past a
 * step.
 *
 * ⛔ THE ZOOM IS PROPORTIONAL TO THE SCROLL AND NEVER A STEP PER EVENT. A trackpad fires dozens of
 * events of a few px for one gesture; a step per event took the floor from its fit to the ceiling in
 * a fraction of one.
 *
 * @param {object} camera
 * @param {{x: number, y: number}} point the cursor, in CSS px from the surface's top-left
 * @param {{deltaY: number, deltaMode?: number, ctrlKey?: boolean}} delta the `WheelEvent`'s own
 *        members (a `WheelEvent` itself will do); `deltaMode` is 0 (pixels), 1 (lines) or 2 (pages),
 *        absent 0; `ctrlKey` true marks a pinch, absent false — so a caller that passes only `deltaY`
 *        and `deltaMode` (row 16's, say) zooms exactly as before
 */
export function wheel(camera, point, { deltaY, deltaMode = 0, ctrlKey = false }) {
    const unit = deltaMode === 2 ? camera.surface.height : deltaMode === 1 ? LINE_PX : 1;
    const gain = ctrlKey ? PINCH_GAIN : 1;
    const scroll = Math.min(NOTCH_PX, Math.max(-NOTCH_PX, deltaY * unit * gain));

    if (scroll === 0) {
        return camera;
    }

    return zoomAt(camera, point, ZOOM_STEP ** (-scroll / NOTCH_PX));
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
    const b = camera.bounds;
    const axis = (pos, span, start, length) => {
        const a = start;
        const c = start + length - span;

        return Math.min(Math.max(pos, Math.min(a, c)), Math.max(a, c));
    };

    return settle({
        ...camera,
        zoom,
        x: axis(camera.x, camera.surface.width / zoom, b.x, b.w),
        y: axis(camera.y, camera.surface.height / zoom, b.y, b.h),
    });
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
