/**
 * THE 3-D FLOOR's TOON MATERIAL — the one constructor of a `MeshToonMaterial` in `floor3d/`, so every lit thing on the
 * floor (the room, the walls, the kit, the creatures, the birds) shades on the one ramp `FLOOR.md` § 12's *Floor 3-D
 * ramp* row gives (`floor3d/look.js`'s `LOOK.ramp`; `docs/design/plan-13033-stage2-3d-floor.md` § 14.3 (c)).
 * `Floor3dPageWiringTest` holds `new MeshToonMaterial(` to this file alone.
 *
 * The ramp is a `RedFormat` data texture of one row, nearest-filtered both ways and never mipmapped, so each texel is a
 * hard step: the shader reads it at `dot(N, L) · 0.5 + 0.5`.
 */

import { DataTexture, MeshToonMaterial, NearestFilter, RedFormat } from '../vendor/three/three.module.js';
import { LOOK } from './look.js';

const ramp = new DataTexture(Uint8Array.from(LOOK.ramp, (v) => Math.round(v * 255)), LOOK.ramp.length, 1, RedFormat);

ramp.minFilter = NearestFilter;
ramp.magFilter = NearestFilter;
ramp.generateMipmaps = false;
ramp.needsUpdate = true;

/** A toon material of `params`, on the floor's one ramp. */
export function toon(params = {}) {
    return new MeshToonMaterial({ ...params, gradientMap: ramp });
}
