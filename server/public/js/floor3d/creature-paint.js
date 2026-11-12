/**
 * THE 3-D CREATURES — a key's part list (`resources/characters/creatures-3d.js`'s `bodyParts()`, through the character
 * tree's `index.js`) built with Three's geometry classes into ONE skinned mesh over ONE skeleton: the one file that
 * imports Three for characters. `docs/design/plan-13033-stage2-3d-floor.md` § 3.3 (card#13033 stage 2 slice 3).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A SEAT's CREATURE IS ONE DRAW CALL AND AN INTERN's TWO (§ 6's budget, its `S + 2 I` term; § 12 item 4): every part is
 * merged into one geometry, every vertex bound to its part's bone at full weight — a toy's rigid joints, not a skin's —
 * and the body and the face share ONE material: the face is drawn into an atlas beside a white block, the body's
 * texture coordinates sample the white, and the parts' colours ride on the vertices, multiplying through. An intern's
 * laptop is the second material group, its own opacity (A24's fade is per intern). `tools/characters/sheet3d.browser.mjs
 * --calls` reads `renderer.info` over one seat and one intern and reds past one and two.
 *
 * ⛔ THE FACE IS THE 2-D GENERATOR's FACE: `faceDocument()` rasterised (`floor3d/svg-texture.js`) into the atlas, open and
 * shut — two atlases, the blink a swap of the material's map — and mapped onto a patch curved round the head's front
 * over `faceBox()`'s rectangle, its transparent ground cut by the material's alpha test so the body shows through.
 *
 * ⛔ THE BODY WEARS ITS 2-D PAINT (the operator's Q6 answer A, 2026-11-12): `paintDocument()` — the frame's body layers
 * alone, the panda's band, the fox's mask, the stripes and the spots — rasterised once and projected onto every part of
 * the creature from the front, each vertex sampling it where its rest point stands, a little inside the outline
 * (`PAINT_INSET`) so a side does not wear the outline's stroke. It is a second sampler in the SAME material, so the seat
 * stays one call; it fades out only over the surfaces that face away (`paintWeight()`), so a belly or a nose is not
 * printed on the creature's back, and it shows from the front, the sides, above and three-quarters.
 *
 * ⛔ POSES STEP, NEVER BLEND: `pose(name)` sets every bone from `POSES[name]` over the rest pose at once. The clock that
 * chooses which pose of a clip shows (§ 3.5) is slice 5's; this file only knows how to show one.
 */

import {
    Bone,
    CanvasTexture,
    Color,
    CylinderGeometry,
    Float32BufferAttribute,
    MeshToonMaterial,
    PlaneGeometry,
    SRGBColorSpace,
    Skeleton,
    SkinnedMesh,
    Uint16BufferAttribute,
    Vector3,
} from '../vendor/three/three.module.js';
import { mergeGeometries } from '../vendor/three/examples/jsm/utils/BufferGeometryUtils.js';
import { rasterise } from './svg-texture.js';
import { DETAIL, partGeometry } from './part-geometry.js';

/** The face atlas's side, px, by default — § 6's 256 bucket; the near bucket (512) is the caller's to ask for. */
export const FACE_TEXTURE = 256;

const DEG = Math.PI / 180;

/** How far inside the outline a side's vertex samples the paint, frame units: past the 2-D outline's stroke. */
export const PAINT_INSET = 2.5;

/**
 * How much of the paint a surface wears, from its normal's `z` (toward the 2-D viewer): all of it facing the front, the
 * sides and up; none facing straight back — a smoothstep between.
 */
export function paintWeight(nz) {
    const t = Math.min(1, Math.max(0, (nz + 0.65) / 0.5));

    return t * t * (3 - 2 * t);
}

/** The vertex shader's and fragment shader's additions: the paint's coordinates in, its colour laid over the vertex colour. */
function wearPaint(material, paint) {
    material.onBeforeCompile = (shader) => {
        shader.uniforms.paintMap = { value: paint };
        shader.vertexShader = shader.vertexShader
            .replace('#include <common>', '#include <common>\nattribute vec3 paint;\nvarying vec3 vPaint;')
            .replace('#include <begin_vertex>', '#include <begin_vertex>\nvPaint = paint;');
        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', '#include <common>\nuniform sampler2D paintMap;\nvarying vec3 vPaint;')
            .replace('#include <color_fragment>', '#include <color_fragment>\nvec4 painted = texture2D( paintMap, vPaint.xy );\ndiffuseColor.rgb = mix( diffuseColor.rgb, painted.rgb, painted.a * vPaint.z );');
    };
    material.customProgramCacheKey = () => 'creature-paint';
}

/**
 * Each vertex's paint coordinates and weight: where its rest point stands in `paintDocument()`'s rectangle, stepped
 * `PAINT_INSET` back against its normal across the picture, and an intern's squash undone (`bodyParts()`'s `paint`).
 */
function setPaint(geo, painted, P, foot) {
    const pos = geo.getAttribute('position'), normal = geo.getAttribute('normal');
    const out = new Float32Array(pos.count * 3);

    for (let i = 0; i < pos.count; i++) {
        if (!painted) {
            continue;
        }

        const x = pos.getX(i) - normal.getX(i) * PAINT_INSET, y = pos.getY(i) - normal.getY(i) * PAINT_INSET;
        const rest = y < P.chin ? y / P.k : y + P.shift;

        out[i * 3] = (54 + x - P.box.x) / P.box.w;
        out[i * 3 + 1] = 1 - (foot - rest - P.box.y) / P.box.h;
        out[i * 3 + 2] = paintWeight(normal.getZ(i));
    }

    geo.setAttribute('paint', new Float32BufferAttribute(out, 3));

    return geo;
}

/**
 * The atlas's layout over a side of `size` px: the face fitted into the top three quarters, the laptop lid's back in the
 * lower left, and the white block the body samples in the lower right. Each region in texture coordinates (v up).
 */
export function atlasLayout(size, faceBox, lidAspect) {
    const faceW = Math.min(size, (size * 0.75 * faceBox.w) / faceBox.h);
    const faceH = (faceW * faceBox.h) / faceBox.w;
    const lidW = size * 0.6, lidH = Math.min(size * 0.25 - 2, lidW / lidAspect);
    const px = (x, y, w, h) => ({ x, y, w, h, u0: x / size, u1: (x + w) / size, v0: 1 - (y + h) / size, v1: 1 - y / size });

    return {
        size,
        face: px((size - faceW) / 2, 0, faceW, faceH),
        lid: px(0, size * 0.75 + 1, lidW, lidH),
        white: px(size - 8, size - 8, 8, 8),
    };
}

/**
 * The face patch: a band of a cylinder round the head's front, `faceBox()`'s width along its arc and its height up it —
 * or, on a flat body (a slice, a cut half), a flat patch on its face.
 */
function faceGeometry(face) {
    const { box, at, radius } = face;

    if (face.flat) {
        const plane = new PlaneGeometry(box.w, box.h).toNonIndexed();

        plane.translate(at[0], at[1], radius);

        return plane;
    }

    const arc = Math.min(box.w / radius, 2.6);
    const geo = new CylinderGeometry(radius, radius, box.h, 16, 1, true, -arc / 2, arc).toNonIndexed();

    geo.translate(at[0], at[1], at[2]);

    return geo;
}

/** Give a geometry the attributes every merged part carries: one colour, one bone at full weight, and its UVs mapped. */
function dress(geo, colour, bone, uvOf) {
    const n = geo.getAttribute('position').count;
    const c = new Color(colour);
    const colours = new Float32Array(n * 3), index = new Uint16Array(n * 4), weight = new Float32Array(n * 4);
    const uv = geo.getAttribute('uv');
    const uvs = new Float32Array(n * 2);

    for (let i = 0; i < n; i++) {
        colours.set([c.r, c.g, c.b], i * 3);
        index[i * 4] = bone;
        weight[i * 4] = 1;
        const [u, v] = uvOf(uv ? uv.getX(i) : 0, uv ? uv.getY(i) : 0);

        uvs[i * 2] = u;
        uvs[i * 2 + 1] = v;
    }

    for (const name of Object.keys(geo.attributes)) {
        if (name !== 'position' && name !== 'normal') {
            geo.deleteAttribute(name);
        }
    }

    geo.setAttribute('color', new Float32BufferAttribute(colours, 3));
    geo.setAttribute('uv', new Float32BufferAttribute(uvs, 2));
    geo.setAttribute('skinIndex', new Uint16BufferAttribute(index, 4));
    geo.setAttribute('skinWeight', new Float32BufferAttribute(weight, 4));
    geo.clearGroups();

    return geo;
}

/**
 * The creature's atlas canvases — open and shut — from its face and lid documents, through `rasterise()`.
 *
 * @param {Document} document
 * @param {{face: string, faceShut: string, lid: string|null}} docs
 * @param {object} layout `atlasLayout()`'s
 */
async function atlases(document, docs, layout) {
    const tile = async (svg, w, h) => {
        const canvas = document.createElement('canvas');

        await rasterise(canvas, svg, Math.max(1, Math.round(w)), Math.max(1, Math.round(h)));

        return canvas;
    };
    const lid = docs.lid === null ? null : await tile(docs.lid, layout.lid.w, layout.lid.h);
    const sheet = async (faceSvg) => {
        const canvas = document.createElement('canvas');

        canvas.width = layout.size;
        canvas.height = layout.size;
        const g = canvas.getContext('2d');
        const face = await tile(faceSvg, layout.face.w, layout.face.h);

        g.drawImage(face, layout.face.x, layout.face.y);
        if (lid !== null) {
            g.drawImage(lid, layout.lid.x, layout.lid.y);
        }
        g.fillStyle = '#ffffff';
        g.fillRect(layout.white.x, layout.white.y, layout.white.w, layout.white.h);

        return canvas;
    };

    return { open: await sheet(docs.face), shut: await sheet(docs.faceShut) };
}

/**
 * One creature: its skinned mesh, its pose and blink setters, and what disposes it.
 *
 * @param {Document} document
 * @param {object} characters the character tree's module (`resources/characters/index.js`): `bodyParts`, `chibiParts`,
 *        `faceDocument`, `lidDocument`, `BONES`, `BONE_PARENT`, `POSES`
 * @param {string} key the seat key or the intern key
 * @param {{species?: string, chibi?: boolean, pose?: string, height?: number, textureSize?: number}} [opt] `height` the
 *        character's world height its frame rect stands (`floor-world.js`'s figure; 1 by default)
 * @returns {Promise<{mesh: SkinnedMesh, parts: object, pose: function(string): void, blink: function(boolean): void,
 *          fade: function(number): void, textures: function(): object[], dispose: function(): void}>}
 */
export async function createCreature(document, characters, key, { species, chibi = false, pose = 'stand', height = 1, textureSize = FACE_TEXTURE } = {}) {
    const C = characters;
    const body = chibi ? C.chibiParts(key, { species }) : C.bodyParts(key, { species });
    const lidDoc = chibi ? C.lidDocument(key) : null;
    const [, , lidW, lidH] = lidDoc === null ? [0, 0, 2, 1] : lidDoc.match(/viewBox="([^"]+)"/)[1].split(' ').map(Number);
    const lidAspect = lidW / lidH;
    const layout = atlasLayout(textureSize, body.face.box, lidAspect);
    const faceDoc = (blink) => C.faceDocument(key, { species: body.species, pose, blink });
    const sheets = await atlases(document, { face: faceDoc(false), faceShut: faceDoc(true), lid: lidDoc }, layout);
    // the body's paint, as tall as the face atlas is wide, its width the paint rectangle's aspect
    const paintCanvas = document.createElement('canvas');
    const paintH = textureSize, paintW = Math.max(1, Math.round((textureSize * body.paint.box.w) / body.paint.box.h));

    await rasterise(paintCanvas, C.paintDocument(key, { species: body.species, box: body.paint.box }), paintW, paintH);

    // ---- the geometry: every part merged, the laptop's parts a second group ----
    const boneIndex = Object.fromEntries(C.BONES.map((b, i) => [b, i]));
    const white = ((layout.white.u0 + layout.white.u1) / 2), whiteV = (layout.white.v0 + layout.white.v1) / 2;
    const onWhite = () => [white, whiteV];
    const own = [], laptop = [];

    for (const part of body.parts) {
        const geo = partGeometry(part, chibi ? DETAIL.chibi : DETAIL.seat);

        if (part.laptop) {
            // the lid's back (+z, toward the viewer) carries the lid texture; every other face the shell's plain colour
            const isLidBack = part.name === 'laptop-lid';
            const normal = geo.getAttribute('normal');

            setPaint(dress(geo, part.colour, boneIndex[part.bone], onWhite), false, body.paint, C.FOOT);
            if (isLidBack) {
                const uv = geo.getAttribute('uv');
                const pos = geo.getAttribute('position');
                const box = new Vector3(), min = new Vector3(Infinity, Infinity, Infinity), max = new Vector3(-Infinity, -Infinity, -Infinity);

                for (let i = 0; i < pos.count; i++) {
                    box.fromBufferAttribute(pos, i);
                    min.min(box);
                    max.max(box);
                }

                for (let i = 0; i < pos.count; i++) {
                    if (normal.getZ(i) > 0.5) {
                        box.fromBufferAttribute(pos, i);
                        const u = (box.x - min.x) / (max.x - min.x), v = (box.y - min.y) / (max.y - min.y);

                        uv.setXY(i, layout.lid.u0 + u * (layout.lid.u1 - layout.lid.u0), layout.lid.v0 + v * (layout.lid.v1 - layout.lid.v0));
                        geo.getAttribute('color').setXYZ(i, 1, 1, 1);
                    }
                }
            }
            laptop.push(geo);
        } else {
            own.push(setPaint(dress(geo, part.colour, boneIndex[part.bone], onWhite), part.paint, body.paint, C.FOOT));
        }
    }

    own.push(setPaint(dress(faceGeometry(body.face), '#ffffff', boneIndex[body.face.bone], (u, v) => [
        layout.face.u0 + u * (layout.face.u1 - layout.face.u0),
        layout.face.v0 + v * (layout.face.v1 - layout.face.v0),
    ]), false, body.paint, C.FOOT));

    const groups = [mergeGeometries(own, false)];

    if (laptop.length > 0) {
        groups.push(mergeGeometries(laptop, false));
    }

    const geometry = groups.length === 1 ? groups[0] : mergeGeometries(groups, true);

    // ---- the material: one for the body and its face, one for the laptop; the texture's image shared ----
    const texture = (canvas) => {
        const t = new CanvasTexture(canvas);

        t.colorSpace = SRGBColorSpace;

        return t;
    };
    const open = texture(sheets.open), shut = texture(sheets.shut), paint = texture(paintCanvas);
    const material = new MeshToonMaterial({ map: open, vertexColors: true, alphaTest: 0.35 });

    wearPaint(material, paint);
    const materials = [material];

    if (laptop.length > 0) {
        materials.push(new MeshToonMaterial({ map: open, vertexColors: true, transparent: true, opacity: 1 }));
    }

    // ---- the skeleton: the fixed hierarchy, each bone at its joint ----
    const bones = C.BONES.map((name) => {
        const bone = new Bone();

        bone.name = name;

        return bone;
    });
    const rest = {};

    for (const [i, name] of C.BONES.entries()) {
        const parent = C.BONE_PARENT[name];
        const at = new Vector3(...body.joints[name]);

        if (parent !== null) {
            at.sub(new Vector3(...body.joints[parent]));
            bones[boneIndex[parent]].add(bones[i]);
        }

        bones[i].position.copy(at);
        rest[name] = at.clone();
    }

    const root = bones[boneIndex.root];
    const mesh = new SkinnedMesh(geometry, materials.length === 1 ? material : materials);

    mesh.add(root);
    mesh.bind(new Skeleton(bones));
    // the size about the foot after the bind, so the bind's rest pose is the part list's own and the size shows
    root.scale.setScalar(body.size);
    mesh.scale.setScalar(height / body.tall);
    mesh.name = `creature ${key}`;
    mesh.frustumCulled = false;

    const setPose = (name) => {
        const P = C.POSES[name];

        if (P === undefined) {
            throw new RangeError(`no pose "${name}"`);
        }

        for (const [i, b] of C.BONES.entries()) {
            const p = P[b] ?? {};
            const [rx, ry, rz] = p.rotate ?? [0, 0, 0];
            const tilt = b === 'root' ? -body.tilt : 0;

            bones[i].rotation.set(rx * DEG, ry * DEG, (rz + tilt) * DEG);
            bones[i].position.copy(rest[b]).add(new Vector3(...(p.at ?? [0, 0, 0])));
        }
    };

    setPose(pose);

    return {
        mesh,
        parts: body,
        pose: setPose,
        blink: (shutEyes) => {
            material.map = shutEyes ? shut : open;
        },
        fade: (opacity) => {
            if (materials.length > 1) {
                materials[1].opacity = opacity;
            }
        },
        /** The textures this creature holds, each `{ width, height }` — what the texture-memory leg sums. */
        textures: () => [open, shut, paint].map((t) => ({ width: t.image.width, height: t.image.height })),
        dispose: () => {
            geometry.dispose();
            open.dispose();
            shut.dispose();
            paint.dispose();
            mesh.skeleton.dispose();
            for (const m of materials) {
                m.dispose();
            }
        },
    };
}
