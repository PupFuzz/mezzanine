/**
 * A PLATE'S LABEL, PAINTED — the one primitive that decides where every plate's label stands, how wide
 * it is, how many of its lines show, and what it is painted in. **The label CONTRACT is
 * `docs/design/FLOOR.md` § 4.1's alone; this module states only what its own code does.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE STRUCTURE THAT KEEPS THIS FROM GOING STALE: `showLabels()` below is the ONLY function that ever
 * writes a plate's label geometry or paint, called from `main.js`'s `view()` on EVERY camera the page
 * shows — so there is no plan computed ahead of the camera that will render it. `lobby/plate-row.js`
 * builds each plate's label ONCE, with no geometry or paint of its own baked in: every number and colour
 * it uses is a CSS custom property this module writes on `#lobby-floors` (which every plate's label
 * inherits, since every plate shares one storey height and one camera), so a plate's label reacts to
 * whatever camera `showLabels()` was last called with, including mid-glide, with no rebuild.
 *
 * ⭐ THE PROPERTIES WRITTEN, all on `#lobby-floors`: `--label-scale` (the counter-scale); `--label-left`
 * and `--label-width` (the label's position and size — `leftFor()` and `widthFor()` below state their
 * arithmetic and units; resolved here in JS rather than a live CSS `calc()`, for testability without a
 * CSS engine and to keep one writer, one mechanism); `--label-lines` (`linesFor()` — how many lines are
 * not clipped away); `--label-ink` (the link's colour); `--label-text-ink` (the summary, rooms and cue's
 * colour); `--label-backing`; `--label-halo`; `--label-align` (read by `plate-row.js` as each line's
 * `justify-content`, and nowhere else); and `data-label-side`, a TEST HOOK ONLY — nothing here reads it
 * back, and `plate-row.js` reads no attribute, only `var()`s.
 *
 * ⛔ ONE STOREY HEIGHT FOR EVERY PLATE: `building-scene.js`'s `buildingScene()` gives every plate
 * `rect.h = PLATE_H`, uniformly — there is no overflow strip in the building's own drawing (that concept
 * is the FLOOR page's, a different screen), and the ground lobby and roof are scenery with no label of
 * their own — so `--label-lines` is one number, shared, exactly like every other property this module
 * writes.
 */

import { framesNothing } from '../wire/camera.js';
import { INK, PLATE_H, PLATE_INSET, rgba, SKY_GROUND } from './building-scene.js';

/** The plate label's font — the page's own body size (FLOOR.md § 4.1). */
export const LABEL_FONT = '1rem';

/**
 * One line of the plate label's own body text, in CSS px — 1.25× `LABEL_FONT`'s `1rem` (16px), a typical
 * browser's own line-height for body text with none set of its own. An ESTIMATE (a figure of the
 * drawing's, carrying no fact), used both to derive `--label-lines` (below) and as the label's own
 * explicit `line-height` (`plate-row.js`), so the two stay the SAME number rather than one estimating the
 * other's actual rendered size.
 */
export const LABEL_LINE_PX = 20;

/**
 * The fixed real-px gap between a beside label's right edge and the SHELL's own left edge — small and
 * non-zero so the rendered pixels never touch regardless of rounding.
 */
export const LABEL_GAP_PX = 8;

/**
 * ⭐ THE THRESHOLD THAT PICKS A SIDE, AND ITS REAL REASON (never a WCAG reflow citation — that criterion
 * is about a VIEWPORT's own minimum width, unrelated to how wide one label box needs to be). The real
 * reason: the CUE is the line's one UNSHRINKABLE content (`flex: 0 0 auto` in `plate-row.js`) — *" — the
 * elevator is here"* — so a `--label-width` narrower than the cue's own measured width cannot show it
 * whole regardless of how far the name shrinks. `LABEL_SIDE_MIN_PX` is the cue's own measured width plus
 * the flex row's own gap plus room for a few characters of even the shortest name before it is worth
 * showing beside the building at all — pinned to that measured content width, never estimated.
 * `tools/design/lobby-label-contrast.browser.mjs`'s own `cueUnreachable()` check (with `PLANTS.cueWidth`,
 * which shrinks this constant below the cue's own measured width), run by hand, measures over its own
 * `RUNS` whether the cue has grown past it.
 *
 * ⭐ WHY A DESKTOP WINDOW ROUTINELY FALLS BACK TOO: the camera's own CLAMP (`wire/camera.js`'s `clamp()`)
 * keeps the framed extent fully in view — at a high enough zoom, the clamp pushes the shell's own left
 * edge to or past the surface's own left edge, so the room available beside it shrinks toward zero. This
 * is not a bug: it is the SAME clamp that keeps the building on screen at every zoom.
 */
export const LABEL_SIDE_MIN_PX = 240;

/** Warm gold on the sky — the operator's ruling (2026-09-30, option A), `#ffcf7d`. */
export const LABEL_GOLD = '#ffcf7d';

/**
 * The light warm ink for the summary, the rooms line and the cue, standing beside the building: the
 * SAME cream `building-scene.js`'s `INK.wall` already names for the storeys' own walls, read rather than
 * copied — brighter than gold, so wherever gold clears WCAG 2.1 SC 1.4.3 against the sky, this ink clears
 * it by more (`tools/design/lobby-label-contrast.browser.mjs` measures both, on rendered pixels).
 */
export const LABEL_TEXT_INK = INK.wall;

/**
 * The dark ink for BOTH the link and the summary/rooms/cue, falling back onto the plate — a warm
 * near-black rather than pure black, in the drawing's own palette, chosen dark enough that the fallback's
 * own near-opaque backing (below) alone clears WCAG comfortably, measured on rendered pixels.
 */
export const LABEL_DARK_INK = '#241a10';

/**
 * The backing behind each of a plate label's visible lines, falling back onto the plate — the wall's
 * colour, very nearly opaque: what a glyph stands on is then the backing, whatever the drawing puts under
 * it. The opacity is MEASURED, not argued: `tools/design/lobby-label-contrast.browser.mjs` measures, over
 * its own `RUNS`, whether rendered pixels bleed under its `MIN_RATIO` at a hard colour boundary the
 * drawing produces (the shell's own drawn edge, a storey seam). The tool declares any residual it finds
 * by name and reason rather than exiting non-zero on a finding already understood and accepted; every
 * other failure reds it.
 */
export const LABEL_BACKING = rgba(INK.wall, 0.985);

/**
 * The thin dark halo standing BESIDE the building — `text-shadow` layers in the reference's OWN backdrop
 * colour (`building-scene.js`'s `SKY_GROUND`, read rather than copied — opaque, so the stack's density is
 * the blur's own falloff and not also an alpha composite): gold's own contrast against the sky already
 * clears WCAG comfortably, so this halo is not legibility's own load-bearing mechanism — it exists for
 * the one case contrast measurement excludes, a bright star pixel sitting directly behind or beside a
 * glyph. MEASURED, NOT ARGUED: `tools/design/lobby-label-contrast.browser.mjs` samples a ring of pixels
 * out from every glyph pixel, and the stack's own radii were widened until that ring's own sampling
 * distance stopped finding an under-threshold pixel behind a star, with any residual declared by name and
 * reason rather than chased to zero.
 */
export const LABEL_HALO_BESIDE = [1, 1, 2, 2, 3, 3, 4, 4].map((r) => `0 0 ${r}px ${SKY_GROUND}`).join(', ');

/**
 * The SHELL's own on-screen left edge (the extent's `x = 0`, never a plate's `rect.x`) in real CSS px —
 * negative, or past the surface's own width, once a pan or zoom has carried it there. This reads the
 * whole building's extent — `camera.bounds.x`, always `0` for the lobby's camera (`building-scene.js`'s
 * `buildingScene()` gives the extent `x: 0`), even while the ride's own `focusOn()` has zoomed the VIEW
 * to one plate: `focusOn()` narrows the view without changing what is framed, so `camera.bounds` is
 * always the whole building's, exactly what a label beside the building must clear.
 */
function shellScreenLeft(camera) {
    return (camera.bounds.x - camera.x) * camera.zoom;
}

/** A plate's own on-screen left edge (`PLATE_INSET` in from the shell) — the fallback's own measure. */
function plateScreenLeft(camera) {
    return (camera.bounds.x + PLATE_INSET - camera.x) * camera.zoom;
}

/**
 * Whether there is room to stand a plate's label BESIDE the building (`'left'`) or whether it falls back
 * to standing ON the plate (`'plate'`) — `LABEL_SIDE_MIN_PX`'s own docblock states the threshold and its
 * real reason. Re-evaluated on every call: `showLabels()` calls this on every `view()`, so a ride's
 * glide, a wheel, a resize or a drag can all flip the side mid-motion, live, with no rebuild.
 */
function sideFor(camera) {
    return Math.max(0, shellScreenLeft(camera)) >= LABEL_SIDE_MIN_PX ? 'left' : 'plate';
}

/**
 * The label's own `width`, in real px, for `side` — the room to the shell's left, less the fixed gap,
 * beside (never clamped to a minimum: `sideFor()` already decided this much room is worth using); the
 * fallback's own room on the plate (`plateScreenLeft()`, today's old `labelMax()`, unchanged: clamped to
 * at least `LABEL_SIDE_MIN_PX` and at most the surface's own width).
 */
function widthFor(camera, side) {
    if (side === 'left') {
        return Math.max(0, shellScreenLeft(camera) - LABEL_GAP_PX);
    }

    const surfaceWidth = camera.surface.width;

    return Math.min(surfaceWidth, Math.max(LABEL_SIDE_MIN_PX, surfaceWidth - plateScreenLeft(camera)));
}

/**
 * The label's own `left`, in SCENE px (the units `plate-row.js`'s row stands in): beside, `-PLATE_INSET`
 * (the plate's own inset from the shell, subtracted so the SHELL is what `width` + `LABEL_GAP_PX` is
 * measured clear of) minus `(width + LABEL_GAP_PX)` turned back into scene px by dividing by the SAME
 * zoom the ancestor `#lobby-floors` is about to multiply it by. `0` falling back: the label's left edge
 * stands AT the plate's own top-left.
 */
function leftFor(camera, side, width) {
    return side === 'left' ? -PLATE_INSET - (width + LABEL_GAP_PX) / camera.zoom : 0;
}

/**
 * How many of a plate's label's (up to three) lines are not clipped away: the storey's own on-screen
 * height (`PLATE_H` at the camera's zoom) divided by one line's own height (`LABEL_LINE_PX`), floored at
 * `1`, never `0`, so line 1 (the name and the cab's own cue) is never itself clipped by the line-count
 * budget — FLOOR.md § 4.1 states the bound this still leaves.
 */
function linesFor(camera) {
    return Math.max(1, Math.floor((PLATE_H * camera.zoom) / LABEL_LINE_PX));
}

/**
 * ⭐ THE ONE PRIMITIVE — every plate's label geometry and paint, written on `#lobby-floors` for every
 * plate to inherit, called by `lobby/main.js`'s `view()` on EVERY camera it shows (fit, wheel, key, drag,
 * every glide step, resize). `renderBuilding()`/`paint()` call this NEVER — they write no label geometry
 * at all, so a plate's label can never go stale between a render and the camera moves that follow it.
 *
 * @param {HTMLElement} floorsEl `#lobby-floors` — or the harness's stand-in, the same shape `style` needs
 * @param {object} camera a `wire/camera.js` camera — the one the plates are shown under
 */
export function showLabels(floorsEl, camera) {
    if (framesNothing(camera)) {
        return;
    }

    const side = sideFor(camera);
    const width = widthFor(camera, side);
    const beside = side === 'left';
    const style = floorsEl.style;

    style.setProperty('--label-scale', String(1 / camera.zoom));
    style.setProperty('--label-left', `${leftFor(camera, side, width)}px`);
    style.setProperty('--label-width', `${width}px`);
    style.setProperty('--label-lines', String(linesFor(camera)));
    style.setProperty('--label-ink', beside ? LABEL_GOLD : LABEL_DARK_INK);
    style.setProperty('--label-text-ink', beside ? LABEL_TEXT_INK : LABEL_DARK_INK);
    style.setProperty('--label-backing', beside ? 'transparent' : LABEL_BACKING);
    style.setProperty('--label-halo', beside ? LABEL_HALO_BESIDE : 'none');
    style.setProperty('--label-align', beside ? 'right' : 'left');
    floorsEl.dataset.labelSide = side;
}
