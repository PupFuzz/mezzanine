/**
 * THE 3-D FLOOR's LOOK — every figure of the light rig, the toon ramp and the ground shade, in one place: `FLOOR.md`
 * § 12's *Floor 3-D light*, *Floor 3-D ramp*, *Floor 3-D ground shade* and *Floor 3-D pixel ratio* rows, derived in
 * `docs/design/plan-13033-stage2-3d-floor.md` § 14. Pure — no Three, no DOM — so `tests/Feature/Floor/look-probe.mjs`
 * reads it under node. `floor3d/floor-paint.js` lights the scene from it, `floor3d/toon.js` builds the ramp from it and
 * `floor3d/shade-rects.js` sizes the shade from it; nothing else in `floor3d/` writes one of these figures.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ NOTHING CLIPS, SO NO TONE CURVE: a toon face is its colour times `(sun × ramp + hemisphere) / π` (Three's toon
 * shading: the gradient map's value times the light, and the Lambert `1/π`), so the two intensities sum to π and a
 * white up-facing face is exactly its own colour. `upFace()` is that sum per channel, which the probe holds between 0.75
 * and 1 in every phase.
 *
 * ⛔ THE RAMP IS SAMPLED AS THE SHADER SAMPLES IT: `dot(N, L)` maps to `dot · 0.5 + 0.5` across the texels with nearest
 * filtering, so texel `i` covers `dot` from `-1 + i/8` (16 texels); `rampAt()` is that lookup.
 *
 * ⛔ NO PHASE IS NAMED HERE: which hour is which phase is `floor/floor-layout.js`'s `skyPhase()`, and whether a phase is
 * dark enough for a lamp's pool is its `SKY_PAINT` (`poolShows()`).
 */

import { SKY_PAINT } from '../floor/floor-layout.js';

const freeze = (o) => Object.freeze(o);

export const LOOK = freeze({
    /** The sun: its direction from its target (§ 10.4 item 5's top-left) and its intensity. */
    sun: freeze({ direction: freeze([-0.24, 0.8, 0.55]), intensity: 1.1 }),
    /** The hemisphere light: its intensity and its ground colour, every phase. */
    hemi: freeze({ intensity: 2.04, ground: '#c9b8c4' }),
    /** By A17's sky phase (the windows' own, `unset` when the room has none): the hemisphere's sky and the sun's colour. */
    phases: freeze({
        day: freeze({ sky: '#ffffff', sun: '#fff6e6' }),
        unset: freeze({ sky: '#ffffff', sun: '#fff6e6' }),
        dawn: freeze({ sky: '#fff0ea', sun: '#ffe6cf' }),
        dusk: freeze({ sky: '#f8ecfb', sun: '#ffe2c2' }),
        night: freeze({ sky: '#dfe4ff', sun: '#ffe9c4' }),
    }),
    /** The one toon ramp: 16 texels, `dot(N, L)` below 0 → 0, to 0.5 → 0.45, to 0.75 → 0.90, above → 1. */
    ramp: freeze([0, 0, 0, 0, 0, 0, 0, 0, 0.45, 0.45, 0.45, 0.45, 0.9, 0.9, 1, 1]),
    /** The device pixel ratio's cap. */
    pixelRatio: 2,
    /**
     * The ground shade: plum, and the warm pool's colour; each kind's half extents, corner rounding, fade (units, or a
     * share of a body's height `h`) and strength; and the decal's lift over what it lies on.
     */
    shade: freeze({
        plum: '#3d2b3c',
        warm: '#ffd9a0',
        lift: 0.003,
        body: freeze({ radius: 0.16, round: 1, soft: 0.14, strength: 0.3 }),
        furniture: freeze({ round: 0.35, soft: 0.12, strength: 0.26 }),
        wall: freeze({ round: 0, soft: 0.28, strength: 0.2 }),
        pool: freeze({ kind: 'floor-lamp', round: 1, soft: 1.1, strength: 0.16 }),
    }),
});

/** The phase a scene's windows show (`band.windows[].sky`, A17's value), `unset` when it has none. */
export function phaseOf(sceneIn) {
    const sky = sceneIn?.band?.windows?.[0]?.sky ?? null;

    return Object.hasOwn(LOOK.phases, sky ?? '') ? sky : 'unset';
}

/** Whether a floor lamp throws its pool in `phase`: when the sky's paint lights the city's windows (`city_lit`). */
export function poolShows(phase) {
    return SKY_PAINT[phase].city_lit > 0;
}

/** The ramp's value at `dot(N, L)`, as the shader's nearest lookup reads it. */
export function rampAt(dot) {
    const n = LOOK.ramp.length;

    return LOOK.ramp[Math.min(n - 1, Math.max(0, Math.floor(((dot + 1) / 2) * n)))];
}

/** The sun's direction, unit length. */
export function sunDirection() {
    const [x, y, z] = LOOK.sun.direction;
    const l = Math.hypot(x, y, z);

    return [x / l, y / l, z / l];
}

/** A `#rrggbb` colour's channels in linear light — the sRGB transfer the renderer's colour management applies. */
export function linear(hex) {
    return [1, 3, 5].map((i) => {
        const c = parseInt(hex.slice(i, i + 2), 16) / 255;

        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
}

/** An up-facing face's light in `phase`, per channel in linear light: a white face is drawn at this share of white. */
export function upFace(phase) {
    const { sky, sun } = LOOK.phases[phase];
    const top = rampAt(sunDirection()[1]);
    const s = linear(sun);
    const h = linear(sky);

    return [0, 1, 2].map((c) => (LOOK.sun.intensity * top * s[c] + LOOK.hemi.intensity * h[c]) / Math.PI);
}
