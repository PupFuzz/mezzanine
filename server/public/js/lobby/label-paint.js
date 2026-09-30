/**
 * A PLATE'S LABEL, PAINTED — the one primitive that decides where every plate's label stands, how wide
 * it is, how many of its lines show, and what it is painted in. `docs/design/FLOOR.md § 4.1`, Appendix B
 * row 16 (card#7343 r4's fix round: replacing the r1–r3 mechanism, the operator's ruling 2026-09-30,
 * option A — the design review's SOUND WITH CHANGES verdict on `design-252-labels.md`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS MODULE EXISTS, AND WHY THE r3 MECHANISM IS REPLACED RATHER THAN PATCHED AGAIN. Three
 * review rounds of the beside-the-building label minted new label defects each time (MAJORs: 2, 1, 5 —
 * RISING, the canon #15/#19 non-convergence tripwire). The root cause both r3 reviewers named
 * independently: `lobby/main.js`'s old `labelPlan()` ran ONLY on a feed render (`paint()`), while
 * `view()` — called on every wheel, key, drag, glide step and resize — never re-planned; a `left` baked
 * from the PLAN's zoom went stale, and stale, wrong maths (rather than any one bug in it) is what kept
 * re-minting findings. ⭐ THE FIX IS STRUCTURAL, NOT ANOTHER PATCH: `showLabels()` below is the ONLY
 * function that ever writes a plate's label geometry or paint, and it is called from `view()` on EVERY
 * camera shown — so there is no "plan" to go stale, because nothing is ever planned ahead of the camera
 * that will render it. `lobby/plate-row.js` builds each plate's label ONCE, with no geometry or paint of
 * its own baked in: every number and colour it uses is a CSS custom property this module writes on
 * `#lobby-floors` (which every plate's label inherits), so a plate's label reacts to whatever camera
 * `showLabels()` was last called with, including mid-glide, with NO rebuild.
 *
 * ⭐ THE OPERATOR'S RULING (2026-09-30, option A): *"the lobby's floor labels move BESIDE the building,
 * as the ratified reference does"* — `docs/design/floor-preview/floor-preview.html`'s each-storey name,
 * drawn to the left of its storey, `text-anchor="end"`, warm gold, never over the drawing; *"On a screen
 * with no room beside the building (phone width), labels fall back to sitting over the plate."*
 *
 * ⭐ THE PROPERTIES WRITTEN, all on `#lobby-floors` (CSS custom properties inherit to every plate's
 * `<li>`, so one write serves every plate — every plate shares one storey height and one camera):
 *   `--label-scale`  the counter-scale, `1/zoom` — unchanged from the r1–r3 mechanism.
 *   `--label-left`   the label's `left`, in SCENE px (`plate-row.js`'s own docblock, at its `left`,
 *                    derives why this must be a SCENE quantity and not a real one). ⚠ NOT in the design
 *                    review's own P1 list, which asked for a live CSS `calc()` referencing only
 *                    `--label-width` and `--label-scale`. That calc cannot ALSO yield exactly `0` for the
 *                    fallback while `--label-width` serves as both sides' `width` (the algebra is
 *                    verified in this module's own tests) — so this module writes the RESOLVED offset
 *                    directly instead. It is still the ONE writer, still rewritten fresh on every
 *                    `view()`, so the staleness this whole round exists to remove is unaffected by the
 *                    change; what changes is only which layer (JS here, rather than the browser's CSS
 *                    engine) performs one division that was always going to happen every render either
 *                    way. This also makes the value independently testable by a `node` CI probe with no
 *                    CSS engine at all (P5's own ask) — a raw `calc()` string cannot be.
 *   `--label-width`  the label's `width`, in real px — the beside room (to `sideFor()`'s threshold) or
 *                    the fallback's own room on the plate (today's old `labelMax()`, unchanged reasoning).
 *   `--label-lines`  how many of the label's lines are not clipped away — `linesFor()`.
 *   `--label-ink`    the LINK's colour — gold beside, a dark warm ink falling back.
 *   `--label-text-ink` the SUMMARY/ROOMS/CUE's colour — a lighter warm ink beside (P3: "a light warm ink
 *                    measured ≥ 4.5:1"), the SAME dark ink as the link falling back. ⚠ Also not in the
 *                    review's literal list (which named one `--label-ink`) — P3's own prose needs two,
 *                    since it states the link's ink and the non-link text's ink as different colours.
 *   `--label-backing` the per-line background — `transparent` beside (P3: "no backing"), the wall's
 *                    near-opaque cream falling back (unchanged from the r1 mechanism).
 *   `--label-halo`   the label's `text-shadow` — a thin dark wash beside (P3: "a thin dark wash-coloured
 *                    halo against stars" — a bright star pixel behind a gold glyph is the one case the
 *                    ink's own ≥ 7:1 measured margin does not by itself cover), `none` falling back (the
 *                    near-opaque backing needs no halo under it, unlike the r1–r3 mechanism's over-the-
 *                    drawing case this halo used to guard). ⚠ Not in the review's literal list either —
 *                    P3 asks for it by name and there is nowhere else to put it.
 *   `--label-align`  `'right'` beside, `'left'` falling back — read by `plate-row.js` as BOTH `text-align`
 *                    (lines 2–3) and `justify-content` (line 1's flex row: modern CSS Box Alignment
 *                    accepts `left`/`right` there directly, so one value serves both, and the review's
 *                    `--label-name-ml` margin trick — needed only by the r1–r3 mechanism's single-block
 *                    name — is not needed here).
 *   `data-label-side` `'left'` or `'plate'`, on `#lobby-floors` — A TEST HOOK ONLY, per the design review;
 *                    nothing here reads it back, and `plate-row.js` reads no attribute, only `var()`s.
 *
 * ⛔ ONE STOREY HEIGHT FOR EVERY PLATE. The refute round asked whether a storey could be taller than
 * another (an overflow strip, a "lobby" storey) — it cannot: `building-scene.js`'s `buildingScene()`
 * gives every plate `rect.h = PLATE_H`, uniformly: there is no overflow strip in the building's own
 * drawing (that concept is the FLOOR page's, a different screen), and the ground lobby and roof are
 * scenery with no label of their own. So `--label-lines` is one number, shared, exactly like every other
 * property this module writes.
 */

import { framesNothing } from '../wire/camera.js';
import { INK, PLATE_H, PLATE_INSET, rgba, SKY_GROUND } from './building-scene.js';

/** The plate label's font — the page's own body size, unchanged from the r1–r3 mechanism. */
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
 * ⭐ THE THRESHOLD THAT PICKS A SIDE, AND ITS REAL REASON (design review, P4: give the threshold's real
 * reason, not a WCAG reflow citation — the r1–r3 mechanism's `LABEL_MIN_PX` cited WCAG 2.1's Reflow
 * criterion, which is about a VIEWPORT's own minimum width and has nothing to do with how wide one label
 * box needs to be). The real reason: the CUE is the line's one UNSHRINKABLE content (`flex: 0 0 auto` in
 * `plate-row.js`, design review P2: "never ellipsizes away") — *" — the elevator is here"* renders at
 * ≈189 real px at `LABEL_FONT` (measured, not estimated: a bare span at `1rem`, headless Chromium) — so
 * a `--label-width` narrower than the cue's own width cannot show it whole regardless of how far the name
 * shrinks, which is what the built check `test_green_showlabels_written_values_match_a_hand_computed_camera_at_every_side`
 * and the browser tool's own "cue stays reachable" measurement both hold. `LABEL_SIDE_MIN_PX` is the
 * cue's own measured width plus the flex row's `0.25em` gap plus room for a few characters of even the
 * shortest name before it is worth showing beside the building at all — rounded up for slack. A figure of
 * the drawing's, carrying no fact, and NOT an estimate the way the r1–r3 mechanism's minimum was: it is
 * pinned to the one content width that must fit, with a control that reds if the cue ever grows past it
 * unnoticed.
 *
 * ⭐ WHY A DESKTOP WINDOW ROUTINELY FALLS BACK TOO (design review P4: say honestly that zoomed desktop
 * views use the fallback, and why): the camera's own CLAMP (`wire/camera.js`'s `clamp()`) keeps the
 * framed extent fully in view — at a high enough zoom (a `zoomAt()` deep in, or the elevator ride's own
 * `focusOn()` on one plate), the clamp pushes the shell's own left edge to or past the surface's own left
 * edge, so the room available beside it shrinks toward zero. This is not a bug and not a WCAG citation:
 * it is the SAME clamp that keeps the building on screen at every zoom, applied honestly.
 */
export const LABEL_SIDE_MIN_PX = 240;

/** Warm gold on the sky — the operator's ruling (2026-09-30, option A), `#ffcf7d`. */
export const LABEL_GOLD = '#ffcf7d';

/**
 * The light warm ink for the summary, the rooms line and the cue, standing beside the building (P3): the
 * SAME cream `building-scene.js`'s `INK.wall` already names for the storeys' own walls, read rather than
 * copied — brighter than gold, so wherever gold clears WCAG 2.1 SC 1.4.3's 4.5:1 against the sky (the
 * design review measured ≥ 7.15:1 at every phase, excluding star pixels), this ink clears it by more.
 */
export const LABEL_TEXT_INK = INK.wall;

/**
 * The dark ink for BOTH the link and the summary/rooms/cue, falling back onto the plate (P3: "dark ink on
 * the cream backing") — a warm near-black rather than pure black, in the drawing's own palette. The
 * fallback's near-opaque backing (below) is what holds its contrast, measured on rendered pixels exactly
 * as the r1 mechanism's was; this ink is chosen dark enough that the backing alone clears WCAG comfortably.
 */
export const LABEL_DARK_INK = '#241a10';

/**
 * The backing behind each of a plate label's visible lines, falling back onto the plate — the wall's
 * colour, very nearly opaque: what a glyph stands on is then the backing, whatever the drawing puts
 * under it. ⚠ RAISED FROM card#7343 r1's `0.92` (MEASURED, not argued): at `0.92` a small but nonzero
 * fraction of whatever sits beneath still bleeds through, imperceptible over the drawing's OWN gentler
 * colour transitions but measurable at a HARD one — `tools/design/lobby-label-contrast.browser.mjs`,
 * run at phone width at night, caught pixels under 4.5:1 exactly where a fallback label crosses the
 * shell's own drawn edge (a sharp colour boundary the r1–r3 mechanism's own render matrix never
 * happened to put a label astride). `0.985` leaves the same 8% of visible bleed at under 2%, clean at
 * every boundary the tool now runs across.
 */
export const LABEL_BACKING = rgba(INK.wall, 0.985);

/**
 * The thin dark halo standing BESIDE the building (P3: "a thin dark wash-coloured halo against stars") —
 * `text-shadow` layers in the reference's OWN backdrop colour (`building-scene.js`'s `SKY_GROUND`, read
 * rather than copied — opaque, so the stack's density is the blur's own falloff and not also an alpha
 * composite): gold's own measured contrast against the sky (≥ 7.15:1 at every phase but a star pixel)
 * already clears WCAG comfortably, so this halo is not legibility's own load-bearing mechanism the way
 * the r1–r3 mechanism's wall-coloured stack was over the drawing — it exists for the one case that
 * measurement excludes, a bright star pixel sitting directly behind or beside a glyph. ⚠ MEASURED, NOT
 * ARGUED, exactly as the fallback's backing is, and measured TWICE: a single thin layer left isolated
 * glyph pixels over a star under 4.5:1; a second attempt at two radii (1px, 2px) STILL left pixels under
 * 4.5:1, because `tools/design/lobby-label-contrast.browser.mjs` samples a RING 2–3 px out from every
 * glyph pixel (the same ring the fallback's backing padding is sized against), and a 2px-radius halo does
 * not cover a 3px ring sample — the coverage has to reach as far as the ring does, not merely "thin and
 * present". This is `LABEL_HALO_RADII`-shaped: `[1, 1, 2, 2, 3, 3, 4, 4]`, the SAME stack the r1 wall
 * halo used (each radius twice, "two layers of one opacity compose denser than either"), reaching 4px —
 * clear of the ring's own 3px — which the tool now holds clean at every phase and every floor count it
 * runs, including the ring itself.
 */
export const LABEL_HALO_BESIDE = [1, 1, 2, 2, 3, 3, 4, 4].map((r) => `0 0 ${r}px ${SKY_GROUND}`).join(', ');

/**
 * The SHELL's own on-screen left edge (the extent's `x = 0`, never a plate's `rect.x`) in real CSS px —
 * negative, or past the surface's own width, once a pan or zoom has carried it there. ⛔ THE r3 MECHANISM
 * MEASURED FROM THE PLATE'S OWN EDGE (`+ PLATE_INSET`), NOT THE SHELL'S — both r3 reviewers' independent
 * MAJOR (the plate stands `PLATE_INSET` IN from the shell, so a label placed by the plate's own edge runs
 * `PLATE_INSET` scene px too far right, onto the shell's own wall, at any zoom past the one point where
 * `PLATE_INSET · zoom` happens to equal the label's own width). This reads the whole building's extent —
 * `camera.bounds.x`, always `0` for the lobby's camera (`building-scene.js`'s `buildingScene()` gives the
 * extent `x: 0`), even while the ride's own `focusOn()` has zoomed the VIEW to one plate: `focusOn()`
 * narrows the view without changing what is framed (`wire/camera.js`'s own docblock: "What is framed does
 * not change"), so `camera.bounds` is always the whole building's, exactly what a label beside the
 * building must clear.
 */
function shellScreenLeft(camera) {
    return (camera.bounds.x - camera.x) * camera.zoom;
}

/** A plate's own on-screen left edge (`PLATE_INSET` in from the shell) — the fallback's own measure, unchanged from the r1 mechanism. */
function plateScreenLeft(camera) {
    return (camera.bounds.x + PLATE_INSET - camera.x) * camera.zoom;
}

/**
 * Whether there is room to stand a plate's label BESIDE the building (`'left'`) or whether it falls back
 * to standing ON the plate (`'plate'`) — `LABEL_SIDE_MIN_PX`'s own docblock states the threshold and its
 * real reason. Re-evaluated on every call (design review P4): `showLabels()` calls this on every `view()`,
 * so a ride's glide, a wheel, a resize or a drag can all flip the side mid-motion, live, with no rebuild —
 * this is what closes the r3 mechanism's stale-plan defect at its root rather than patching around it.
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
 * The label's own `left`, in SCENE px — `plate-row.js`'s own docblock, at its `left`, derives the full
 * arithmetic; in short, `-PLATE_INSET` (the plate's own inset from the shell, subtracted so the SHELL is
 * what `width` + `LABEL_GAP_PX` is measured clear of) minus `(width + LABEL_GAP_PX)` turned back into
 * scene px by dividing by the SAME zoom the ancestor `#lobby-floors` is about to multiply it by. `0`
 * falling back: the label's left edge stands AT the plate's own top-left, as it always has.
 */
function leftFor(camera, side, width) {
    return side === 'left' ? -PLATE_INSET - (width + LABEL_GAP_PX) / camera.zoom : 0;
}

/**
 * How many of a plate's label's (up to three) lines are not clipped away: the storey's own on-screen
 * height (`PLATE_H` at the camera's zoom) divided by one line's own height (`LABEL_LINE_PX`), floored at
 * `1` (design review P2: "The name + cue line always shows") — never `0`, so line 1 is never itself
 * clipped by the line-count budget (though `plate-row.js`'s own `max-height` can still clip PART of it on
 * a storey shorter than one line — see that module's own docblock, and FLOOR.md § 4.1's stated bound).
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
