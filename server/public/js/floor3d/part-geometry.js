/**
 * A PART LIST's GEOMETRY — one part (`{ shape, params, at, rotate, scale }`, the vocabulary of
 * `resources/characters/creatures-3d.js`'s part lists) built with Three's geometry classes and moved to where it stands.
 * Hoisted out of `floor3d/creature-paint.js` at its second caller, `floor3d/furniture.js`, which builds a theme's
 * `piece3d()` part lists the same way (`docs/design/plan-13033-stage2-3d-floor.md` § 3.4; card#13033 stage 2 slice 4).
 */

import {
    BoxGeometry,
    CatmullRomCurve3,
    ConeGeometry,
    CylinderGeometry,
    Euler,
    ExtrudeGeometry,
    LatheGeometry,
    Matrix4,
    Quaternion,
    Shape,
    SphereGeometry,
    TorusGeometry,
    TubeGeometry,
    Vector2,
    Vector3,
} from '../vendor/three/three.module.js';

const DEG = Math.PI / 180;

/**
 * THE FACETS A PART IS BUILT WITH, by the body it is on: a seat's at the counts the slice-3 sheet was read at, an
 * intern's coarser — a chibi stands a third of a seat's height on the floor, and at § 6's cap of eight interns a desk
 * its facets are most of the floor's triangles (`docs/design/plan-13033-stage2-3d-floor.md` § 6's triangle row; the
 * counts each draws are printed by `tools/characters/sheet3d.browser.mjs --calls`).
 */
export const DETAIL = Object.freeze({
    seat: Object.freeze({ lathe: 24, sphere: [16, 10], bead: [8, 6], tube: [2, 6], cone: 8, cylinder: 20, torus: [8, 24], curve: 4, bevel: 2 }),
    chibi: Object.freeze({ lathe: 12, sphere: [10, 7], bead: [6, 4], tube: [1, 4], cone: 6, cylinder: 10, torus: [5, 12], curve: 2, bevel: 1 }),
});

/** One part's geometry in frame units, moved to where the part stands, at `detail`'s facets. */
export function partGeometry(part, detail = DETAIL.seat) {
    const { shape, params } = part;
    let geo;

    if (shape === 'lathe') {
        // Three's lathe turns its points from the foot up; the profile is written from the crown down
        geo = new LatheGeometry([...params.points].reverse().map(([r, y]) => new Vector2(r, y)), detail.lathe);
    } else if (shape === 'extrude') {
        const outline = new Shape(params.points.map(([x, y]) => new Vector2(x, y)));
        geo = new ExtrudeGeometry(outline, {
            depth: params.depth, bevelEnabled: params.bevel > 0, bevelThickness: params.bevel, bevelSize: params.bevelSize, bevelOffset: -params.bevelSize, bevelSegments: detail.bevel, curveSegments: detail.curve,
        });
    } else if (shape === 'sphere') {
        // a small ball (a bump, a bead, a nose) needs few facets to read round; a head or a belly more
        const big = Math.max(...part.scale) >= 12;

        geo = new SphereGeometry(params.r, ...(big ? detail.sphere : detail.bead));
    } else if (shape === 'tube') {
        const curve = new CatmullRomCurve3(params.points.map(([x, y, z]) => new Vector3(x, y, z)));
        geo = new TubeGeometry(curve, Math.max(4, params.points.length * detail.tube[0]), params.radius, detail.tube[1], false);
    } else if (shape === 'cone') {
        geo = new ConeGeometry(params.radius, params.height, detail.cone);
        geo.translate(0, params.height / 2, 0);
        geo.applyQuaternion(new Quaternion().setFromUnitVectors(new Vector3(0, 1, 0), new Vector3(...params.dir).normalize()));
    } else if (shape === 'cylinder') {
        geo = new CylinderGeometry(params.radius, params.radius, params.height, detail.cylinder);
    } else if (shape === 'torus') {
        geo = new TorusGeometry(params.radius, params.tube, ...detail.torus);
    } else if (shape === 'box') {
        geo = new BoxGeometry(...params.size);
    } else {
        throw new Error(`no geometry for a part of shape "${shape}"`);
    }

    const [sx, sy, sz] = part.scale;
    const m = new Matrix4().compose(new Vector3(...part.at), new Quaternion().setFromEuler(new Euler(...part.rotate.map((d) => d * DEG))), new Vector3(sx, sy, sz));

    geo.applyMatrix4(m);

    return geo.index ? geo.toNonIndexed() : geo;
}
