/**
 * THE 3-D FLOOR's GROUND SHADE — `floor3d/shade-rects.js`'s rects drawn as ONE instanced decal on the `fixed` term: a
 * soft plum shade under the still bodies, the furniture and the walls' feet, and after dark a warm pool round each floor
 * lamp (`FLOOR.md` § 12's *Floor 3-D ground shade* row; `docs/design/plan-13033-stage2-3d-floor.md` § 14.3 (d)). One of
 * the renderer's parts, composed by `floor3d/floor-paint.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE DRAW, TWO BLENDS: the material blends premultiplied (`ONE, ONE_MINUS_SRC_ALPHA`), so a shade writes
 * `(plum · a, a)` and darkens what is under it, and a pool writes `(warm · a, 0)` and adds to it. The colour is brought
 * to the output space before it is multiplied by `a` — the sRGB transfer of a premultiplied colour would lighten the
 * shade. `a = strength · (1 − smoothstep(−soft / 2, soft, d))`, `d` the rounded box's signed distance.
 *
 * ⛔ IT STAYS ON THE FLOOR: a fragment outside the planes' union box is discarded, so a side wall's or the band's shade
 * never hangs past the floor's edge. The quad is scaled in the vertex stage, so the mesh is never frustum-culled by the
 * unit quad's bounds.
 *
 * ⛔ IT REBUILDS ONLY WHEN THE RECTS CHANGE (their JSON, as `room-paint.js` keys the room), and nothing in it moves.
 */

import {
    Color,
    InstancedBufferAttribute,
    InstancedMesh,
    Matrix4,
    PlaneGeometry,
    ShaderMaterial,
    Vector4,
} from '../vendor/three/three.module.js';
import { LOOK } from './look.js';
import { onTerm } from './terms.js';

const VERTEX = `
attribute vec3 extent;
attribute vec3 falloff;
varying vec2 vLocal;
varying vec2 vFloor;
varying vec3 vExtent;
varying vec3 vFalloff;

void main() {
    vec3 local = vec3(position.x * (extent.x + falloff.x), 0.0, position.z * (extent.y + falloff.x));
    vec4 world = modelMatrix * instanceMatrix * vec4(local, 1.0);

    vLocal = local.xz;
    vFloor = world.xz;
    vExtent = extent;
    vFalloff = falloff;
    gl_Position = projectionMatrix * viewMatrix * world;
}`;

const FRAGMENT = `
uniform vec3 plum;
uniform vec3 warm;
uniform vec4 floorBox;
varying vec2 vLocal;
varying vec2 vFloor;
varying vec3 vExtent;
varying vec3 vFalloff;

void main() {
    if (vFloor.x < floorBox.x || vFloor.y < floorBox.y || vFloor.x > floorBox.z || vFloor.y > floorBox.w) discard;

    float r = vExtent.z * min(vExtent.x, vExtent.y);
    vec2 q = abs(vLocal) - (vExtent.xy - r);
    float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r;
    float a = vFalloff.y * (1.0 - smoothstep(-0.5 * vFalloff.x, vFalloff.x, d));
    bool glow = vFalloff.z > 0.5;

    gl_FragColor = vec4(glow ? warm : plum, 1.0);
    #include <colorspace_fragment>
    gl_FragColor = vec4(gl_FragColor.rgb * a, glow ? 0.0 : a);
}`;

/**
 * The ground shade in `group` (the room's, on the `fixed` term).
 *
 * @returns {{stand: function(list<object>, list<object>): void, inspect: function(): object}}
 */
export function groundShade({ group }) {
    const quad = new PlaneGeometry(2, 2).rotateX(-Math.PI / 2);
    const material = new ShaderMaterial({
        vertexShader: VERTEX,
        fragmentShader: FRAGMENT,
        uniforms: {
            plum: { value: new Color(LOOK.shade.plum) },
            warm: { value: new Color(LOOK.shade.warm) },
            floorBox: { value: new Vector4() },
        },
        transparent: true,
        depthWrite: false,
        premultipliedAlpha: true,
    });
    let mesh = null;
    let key = null;
    let drawn = [];

    return {
        /** The decal over `rects`, kept within the union box of `planes` (`world.planes`); nothing when neither changed. */
        stand(rects, planes) {
            const next = JSON.stringify([rects, planes.map((p) => [p.at.x, p.at.z, p.w, p.d])]);

            if (next === key) {
                return;
            }

            key = next;
            drawn = rects;

            if (mesh !== null) {
                group.remove(mesh);
                mesh.geometry.dispose();
                mesh.dispose();
            }

            material.uniforms.floorBox.value.set(
                Math.min(...planes.map((p) => p.at.x)),
                Math.min(...planes.map((p) => p.at.z)),
                Math.max(...planes.map((p) => p.at.x + p.w)),
                Math.max(...planes.map((p) => p.at.z + p.d)),
            );

            // An instanced mesh holds at least one instance; `count` draws the rects there are.
            const geometry = quad.clone();
            const n = Math.max(1, rects.length);
            const extent = new Float32Array(3 * n);
            const falloff = new Float32Array(3 * n);

            rects.forEach((r, i) => {
                extent.set([r.hw, r.hd, r.round], 3 * i);
                falloff.set([r.soft, r.strength, r.glow ? 1 : 0], 3 * i);
            });
            geometry.setAttribute('extent', new InstancedBufferAttribute(extent, 3));
            geometry.setAttribute('falloff', new InstancedBufferAttribute(falloff, 3));
            mesh = onTerm(new InstancedMesh(geometry, material, n), 'fixed');
            mesh.count = rects.length;
            mesh.frustumCulled = false;
            rects.forEach((r, i) => {
                mesh.setMatrixAt(i, new Matrix4().makeTranslation(r.x, r.y + LOOK.shade.lift, r.z).multiply(new Matrix4().makeRotationY(r.turn)));
            });
            mesh.instanceMatrix.needsUpdate = true;
            group.add(mesh);
        },

        /** The rects drawn, counted by kind — what the browser leg reads against the world's own counts. */
        inspect() {
            const by = { body: 0, furniture: 0, wall: 0, band: 0, pool: 0 };

            for (const r of drawn) {
                by[r.kind]++;
            }

            return { ...by, drawn: mesh?.count ?? 0, wallArea: drawn.filter((r) => r.kind === 'wall').reduce((s, r) => s + 4 * r.hw * r.hd, 0) };
        },
    };
}
