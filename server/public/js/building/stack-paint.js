/**
 * THE STACK's PAINT — the one module that touches WebGL: the building view's slabs drawn with Three.js over
 * `#building-canvas`, each floor's top face its picture (`building/floor-picture.js`), the camera applied from the pose
 * the page shows, and the projector the labels dock with. Appendix B row 47 (card#13033 slice 4;
 * `docs/design/plan-13033-3d-view.md` §§ 3.1, 3.3 and 7).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING. Where a slab stands is `stack-model.js`'s `stackModel()` box, where the camera stands is the
 * pose the page hands `apply()`, and which slab a pointer is over is the model's `aim()` over its own `rayThrough()` —
 * this file draws what it is given. No OrbitControls (plan § 3.3): the camera is never this file's to move.
 *
 * ⛔ UNLIT, AND THE SHEET's SLAB TOKEN: every material is a `MeshBasicMaterial` — the drawings carry their own light —
 * and a slab's edges and a slab with no picture are `--house-trim`, the painter's own `.slab` fill, read off the page.
 *
 * ⛔ A LOST CONTEXT STOPS THE DRAWING AND SAYS SO (§ 7); a restored one re-uploads every picture from the canvas it is
 * held as — Three's `needsUpdate` over the kept canvases, bypassing the pictures' string compare, since a lost context
 * lost every upload whatever the drawing did. No picture is re-painted for it.
 */

import {
    BoxGeometry,
    CanvasTexture,
    Color,
    LinearMipmapLinearFilter,
    Mesh,
    MeshBasicMaterial,
    PerspectiveCamera,
    SRGBColorSpace,
    Scene,
    Vector3,
    WebGLRenderer,
} from '../vendor/three/three.module.js';

/** A box face's index in `BoxGeometry`'s material order (+x, −x, +y, −y, +z, −z): the top. */
const TOP = 2;

/**
 * The paint over a canvas.
 *
 * @param {HTMLCanvasElement} canvas `#building-canvas`
 * @param {{slab: string, pixelRatio: number, lost: function(): void, restored: function(): void}} options the slab
 *        token's value, the device pixel ratio, and what the page does when the context is lost and when it is back
 */
export function stackPaint(canvas, { slab, pixelRatio, lost, restored }) {
    const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true });
    const scene = new Scene();
    const camera = new PerspectiveCamera(45, 1, 0.1, 1000);
    const side = new MeshBasicMaterial({ color: new Color(slab) });
    const anisotropy = renderer.capabilities.getMaxAnisotropy();

    /** key → `{ mesh, texture, canvas, size }`; the ground under `null`. */
    const slabs = new Map();
    let surface = { width: 1, height: 1 };
    let alive = true;

    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x000000, 0);

    canvas.addEventListener('webglcontextlost', (event) => {
        // Prevented, so the browser may restore it.
        event.preventDefault();
        alive = false;
        lost();
    });
    canvas.addEventListener('webglcontextrestored', () => {
        alive = true;

        for (const held of slabs.values()) {
            if (held.texture !== null) {
                held.texture.needsUpdate = true;
            }
        }

        restored();
    });

    const textureOf = (picture) => {
        const texture = new CanvasTexture(picture);

        texture.colorSpace = SRGBColorSpace;
        texture.minFilter = LinearMipmapLinearFilter;
        texture.anisotropy = anisotropy;

        return texture;
    };

    const draw = () => {
        if (alive) {
            renderer.render(scene, camera);
        }
    };

    /** The material set for a slab: its top the picture, or the slab token where it has none. */
    const materials = (texture) => {
        const faces = [side, side, side, side, side, side];

        if (texture !== null) {
            faces[TOP] = new MeshBasicMaterial({ map: texture });
        }

        return faces;
    };

    const remove = (key) => {
        const held = slabs.get(key);

        scene.remove(held.mesh);
        held.mesh.geometry.dispose();
        held.texture?.dispose();

        if (held.mesh.material[TOP] !== side) {
            held.mesh.material[TOP].dispose();
        }

        slabs.delete(key);
    };

    return {
        /** The renderer's largest texture side — the cap's other half (`stack-model.js`'s `textureSize()`). */
        maxTexture: renderer.capabilities.maxTextureSize,

        /**
         * The stack's slabs as they stand, each top face its picture: a slab whose box, picture canvas or picture size
         * changed is rebuilt; a picture whose drawing changed on the same canvas is re-uploaded.
         *
         * @param {object} stack `stackModel()`'s
         * @param {Map<string, {canvas: HTMLCanvasElement|null, changed: boolean}>} pictures `floorPictures().paint()`'s
         */
        build(stack, pictures) {
            const wanted = new Map([[null, { slab: stack.ground, picture: null }], ...stack.slabs.map((s) => [s.floor, { slab: s, picture: pictures.get(s.floor) ?? null }])]);

            for (const key of [...slabs.keys()]) {
                if (!wanted.has(key)) {
                    remove(key);
                }
            }

            for (const [key, { slab: s, picture }] of wanted) {
                const box = s.box;
                const image = picture?.canvas ?? null;
                const size = image === null ? null : `${image.width}×${image.height}`;
                const held = slabs.get(key);
                const shape = `${box.x} ${box.y} ${box.z} ${box.w} ${box.h} ${box.d}`;

                if (held !== undefined && held.shape === shape && held.canvas === image && held.size === size) {
                    if (held.texture !== null && picture.changed) {
                        held.texture.needsUpdate = true;
                    }

                    continue;
                }

                if (held !== undefined) {
                    remove(key);
                }

                const texture = image === null ? null : textureOf(image);
                const mesh = new Mesh(new BoxGeometry(box.w, box.h, box.d), materials(texture));

                mesh.position.set(box.x + box.w / 2, box.y + box.h / 2, box.z + box.d / 2);
                scene.add(mesh);
                slabs.set(key, { mesh, texture, canvas: image, size, shape });
            }

            draw();
        },

        /** The stage's size, CSS px: the drawing buffer follows at the device's pixel ratio. */
        resize(stage) {
            surface = stage;
            renderer.setSize(stage.width, stage.height, false);
            camera.aspect = stage.width / stage.height;
            camera.updateProjectionMatrix();
            draw();
        },

        /** One camera of the model's (`{ position, target, fov }`), applied and drawn. */
        apply(pose) {
            camera.fov = (pose.fov * 180) / Math.PI;
            camera.position.set(pose.position.x, pose.position.y, pose.position.z);
            camera.lookAt(pose.target.x, pose.target.y, pose.target.z);
            camera.updateProjectionMatrix();
            draw();
        },

        /** A world point on the stage, CSS px from its top-left, as the camera last applied draws it. */
        project(point) {
            const p = new Vector3(point.x, point.y, point.z).project(camera);

            return { x: ((p.x + 1) / 2) * surface.width, y: ((1 - p.y) / 2) * surface.height };
        },
    };
}
