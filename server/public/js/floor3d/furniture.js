/**
 * THE 3-D FLOOR's FURNITURE — every standing piece and every desk's furniture set the floor world places
 * (`floor/floor-world.js`'s `pieces` and each desk's `slab` and `parts`), drawn from Kenney's Furniture Kit where the kind
 * map gives a file and from the theme's `piece3d()` part lists where it lists the kind as first-party.
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.4 and § 6 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE INSTANCED MESH PER (PRIMITIVE, MATERIAL) PAIR — § 6's furniture term: a kit file is loaded once and each of its
 * primitives is one `InstancedMesh` holding every placement of its kind on the floor; a first-party piece is one merged
 * geometry of its parts per kind and drawn size, instanced the same way; the side table's couch is its file lengthened
 * once per couch length, instanced the same way (`shapesOf()`). So the floor's furniture costs a draw call per primitive
 * of each kind it places — the couch's per length it stands — whatever the number of placements.
 *
 * ⛔ THE KIT IS RECOLOURED BY REPLACING ITS MATERIALS, NEVER BY TINTING THEM. Its files declare `KHR_materials_unlit`,
 * so `GLTFLoader` builds a `MeshBasicMaterial` for each — unshaded. Every primitive is drawn in a `MeshToonMaterial` of
 * the colour the kind map's `PAINT` gives its material in the floor's theme, the creatures' material, so the two sources
 * shade alike under the room's lights; the loaded materials and textures are disposed of at once.
 *
 * ⛔ EACH PIECE STANDS WHERE THE WORLD PUTS IT AND AS BIG AS ITS 2-D PICTURE: a kit model is fitted to the drawn width
 * across and the drawn height up (its depth follows its width, so it keeps its plan), its foot on the floor and its
 * front toward `+z` — the side the 2-D floor draws; a desk is fitted to its slab, its drawers toward its sitter; a monitor
 * faces its sitter (plan § 3.4, the operator's Q4 answer); a couch is fitted to the box the world lays it in, at one
 * scale every way, lengthened between its arms (`lengthened()`), so its seat is where the world seats its interns.
 *
 * ⛔ IT DECIDES NOTHING ABOUT WHAT STANDS WHERE: `rebuild(world)` places what the world carries, and a file that has not
 * answered yet draws nothing until it does — `ready()` is called then, so the page draws the floor again (plan § 3.5's
 * restart on a late resource).
 */

import {
    Box3,
    BoxGeometry,
    Color,
    Float32BufferAttribute,
    InstancedMesh,
    Matrix4,
    MeshToonMaterial,
    Quaternion,
    Vector3,
} from '../vendor/three/three.module.js';
import { mergeGeometries } from '../vendor/three/examples/jsm/utils/BufferGeometryUtils.js';
import { GLTFLoader } from '../vendor/three/examples/jsm/loaders/GLTFLoader.js';
import { partGeometry } from './part-geometry.js';
import { COUCH } from '../floor/floor-world.js';

/** Where the kit's files are served — the asset route (`FloorAssets::SERVED`'s `.glb`). */
export const KIT = '/art/floor/models/kenney-furniture-kit/';

/** The kind map, by the same route. */
export const KIT_MAP = '/art/floor/models/kenney-furniture-kit/map.js';

/** The kind map module (`FILES`, `PIECE3D`, `PAINT`), loaded by the asset route as the other art modules are. */
export function loadKitMap() {
    return import(KIT_MAP);
}

/** A placeholder desk's box: the slab the 2-D floor draws as a plain rectangle (§ 9 F14), in the house's trim. */
const PLACEHOLDER = '#c9bfb0';

const Y = new Vector3(0, 1, 0);

/** A unit box's extent, its foot centre at the origin — what a placeholder slab is fitted from. */
const SLAB_BOX = new Box3(new Vector3(-0.5, 0, -0.5), new Vector3(0.5, 1, 0.5));

/** A `PALETTE` path's colour — `row.key` or `row.key.index`. */
function colourAt(palette, path) {
    return path.split('.').reduce((v, step) => (v === undefined || v === null ? undefined : v[step]), palette);
}

/** A kit file's primitives, each its geometry in the file's own frame and its material's name, and their box. */
function primitivesOf(gltf) {
    const out = [];

    gltf.scene.updateMatrixWorld(true);
    gltf.scene.traverse((node) => {
        if (node.isMesh) {
            const geometry = node.geometry.clone().applyMatrix4(node.matrixWorld);

            out.push({ geometry, material: node.material.name });
            node.geometry.dispose();
            node.material.map?.dispose();
            node.material.dispose();
        }
    });

    const box = new Box3();

    for (const p of out) {
        p.geometry.computeBoundingBox();
        box.union(p.geometry.boundingBox);
    }

    return { primitives: out, box };
}

/**
 * The matrix that fits a model's box to a placement: centred across and in depth on `at`, its foot at `at.y`, `w`
 * across and `h` up (`d` deep, or as deep as `w` scales it), turned `turn` radians about `y`.
 */
function fitted(box, { at, w, h, d = null, turn = 0 }) {
    const size = box.getSize(new Vector3());
    const sx = w / size.x;
    const sz = d === null ? sx : d / size.z;
    const centre = new Matrix4().makeTranslation(-(box.min.x + box.max.x) / 2, -box.min.y, -(box.min.z + box.max.z) / 2);
    const place = new Matrix4().compose(new Vector3(at.x, at.y, at.z), new Quaternion().setFromAxisAngle(Y, turn), new Vector3(sx, h / size.y, sz));

    return place.multiply(centre);
}

/**
 * The couch's file lengthened by `stretch` file units between its arms: every vertex right of the middle of its seat
 * moves out by that much — the whole right arm, and the seat's and the back's right ends — so the arms keep their
 * thickness at every length (every vertex stands at one arm or the other: `COUCH`, re-measured by the world probe).
 */
function lengthened(model, stretch) {
    const middle = model.box.min.x + (COUCH.seat.left + COUCH.seat.right) / 2;
    const primitives = model.primitives.map(({ geometry, material }) => {
        const out = geometry.clone();
        const position = out.getAttribute('position');

        for (let i = 0; i < position.count; i++) {
            if (position.getX(i) > middle) {
                position.setX(i, position.getX(i) + stretch);
            }
        }

        return { geometry: out, material };
    });
    const box = model.box.clone();

    box.max.x += stretch;

    return { primitives, box };
}

/** A part list merged into one geometry, each part's colour on its vertices. */
function mergedParts(parts) {
    return mergeGeometries(parts.map((part) => {
        const geo = partGeometry(part);
        const n = geo.getAttribute('position').count;
        const c = new Color(part.colour);
        const colours = new Float32Array(n * 3);

        for (let i = 0; i < n; i++) {
            colours.set([c.r, c.g, c.b], i * 3);
        }

        for (const name of Object.keys(geo.attributes)) {
            if (name !== 'position' && name !== 'normal') {
                geo.deleteAttribute(name);
            }
        }

        geo.setAttribute('color', new Float32BufferAttribute(colours, 3));

        return geo;
    }), false);
}

/**
 * The furniture over a scene graph `group`.
 *
 * @param {object} group the `Group` every mesh is added to
 * @param {{map: object, theme: string, palette: object, piece3d: function|null, ready: function(): void,
 *          fetch?: function(string): Promise<ArrayBuffer>}} options the kind map module (`FILES`, `PIECE3D`, `PAINT`),
 *        the floor's theme and its `PALETTE`, the `piece3d()` that draws the first-party kinds (the theme's, or the house
 *        theme's), what is called when a kit file has answered, and how a file's bytes are fetched
 * @returns {{rebuild: function(object): void, requested: function(): list<string>, dispose: function(): void}}
 */
export function createFurniture(group, { map, theme, palette, piece3d, ready, fetch = (url) => window.fetch(url).then((r) => r.arrayBuffer()) }) {
    const loader = new GLTFLoader();
    /** file → `{ primitives, box }` once parsed */
    const models = new Map();
    /** file → the request in flight or answered */
    const requests = new Map();
    /** `kind/material` → its toon material in the floor's theme */
    const materials = new Map();
    const vertexColoured = new MeshToonMaterial({ vertexColors: true });
    const placeholder = new MeshToonMaterial({ color: new Color(PLACEHOLDER) });
    const slabGeometry = new BoxGeometry(1, 1, 1).translate(0, 0.5, 0);
    /** the meshes the last rebuild stood, and the first-party geometries it built */
    let meshes = [];
    let built = [];
    let last = null;
    /** what the last stand placed, and how many files had answered — a paint that changes neither rebuilds nothing */
    let stood = null;

    const materialOf = (kind, name) => {
        const key = `${kind}/${name}`;

        if (!materials.has(key)) {
            const path = map.PAINT[kind]?.[name]?.[theme];

            materials.set(key, new MeshToonMaterial({ color: new Color(colourAt(palette, path)) }));
        }

        return materials.get(key);
    };

    const request = (file) => {
        if (!requests.has(file)) {
            requests.set(file, fetch(KIT + file)
                .then((bytes) => loader.parseAsync(bytes, KIT))
                .then((gltf) => {
                    models.set(file, primitivesOf(gltf));

                    if (last !== null) {
                        stand(last);
                    }

                    ready();
                }));
        }
    };

    /** One instanced mesh of `geometry` and `material` over `placements` (each a matrix). */
    const instanced = (geometry, material, placements) => {
        const mesh = new InstancedMesh(geometry, material, placements.length);

        placements.forEach((m, i) => mesh.setMatrixAt(i, m));
        mesh.instanceMatrix.needsUpdate = true;
        mesh.computeBoundingSphere();
        group.add(mesh);
        meshes.push(mesh);
    };

    /** What the world places: kind → `[{ at, w, h, d?, turn? }]`. */
    const placementsOf = (world) => {
        const by = new Map();
        const add = (kind, p) => by.set(kind, [...(by.get(kind) ?? []), p]);

        for (const piece of world.pieces) {
            add(piece.kind, { at: piece.at, w: piece.w, h: piece.h });
        }

        for (const desk of world.desks) {
            const { slab, parts } = desk;
            const centre = { x: (slab.left + slab.right) / 2, y: 0, z: (slab.back + slab.front) / 2 };
            const depth = slab.front - slab.back;

            if (desk.placeholder) {
                add('placeholder', { at: centre, w: slab.right - slab.left, h: slab.top, d: depth });
                continue;
            }

            // The drawers toward the sitter: the kit's desk is turned to face the band.
            add('desk', { at: centre, w: slab.right - slab.left, h: slab.top, d: depth, turn: Math.PI });

            if (parts.chair !== null) {
                // The chair behind its sitter, its seat under it.
                add('chair', { at: { ...parts.chair.at, z: parts.chair.at.z - parts.chair.w * 0.25 }, w: parts.chair.w * 0.8, h: parts.chair.h * 0.8 });
            }

            if (parts.monitor !== null) {
                // The monitor facing its sitter (the operator's Q4 answer).
                add('monitorFrame', { at: { ...parts.monitor.at, y: slab.top }, w: parts.monitor.w, h: parts.monitor.h * 0.6, turn: Math.PI });
            }

            if (parts.props !== null) {
                add('deskProps', { at: { ...parts.props.at, x: slab.left + parts.props.w * 0.35, y: slab.top }, w: parts.props.w * 0.4, h: parts.props.h * 0.8 });
            }

            // A couch a row of the side table's seats, each as long as the world lays it.
            for (const couch of parts.sideTable ?? []) {
                add('sideTable', { at: couch.at, w: couch.w, h: couch.h, d: couch.d });
            }
        }

        return by;
    };

    /**
     * The model each placement of a kind is fitted from, with the placements it serves: the file as loaded, or — the side
     * table's couch — the file lengthened to each couch's length at its scale (`h` over the file's height), one shape a
     * length, its geometries disposed with the rebuild's.
     */
    const shapesOf = (kind, model, placements) => {
        if (kind !== 'sideTable') {
            return [[model, placements]];
        }

        const size = model.box.getSize(new Vector3());
        const lengths = new Map();

        for (const q of placements) {
            const stretch = q.w / (q.h / size.y) - size.x;
            const key = stretch.toFixed(6);

            lengths.set(key, { stretch, same: [...(lengths.get(key)?.same ?? []), q] });
        }

        return [...lengths.values()].map(({ stretch, same }) => {
            const shape = lengthened(model, stretch);

            built.push(...shape.primitives.map((p) => p.geometry));

            return [shape, same];
        });
    };

    const clear = () => {
        for (const mesh of meshes) {
            group.remove(mesh);
            mesh.dispose();
        }

        for (const geometry of built) {
            geometry.dispose();
        }

        meshes = [];
        built = [];
    };

    const stand = (world) => {
        const placed = placementsOf(world);
        const signature = `${models.size}|${JSON.stringify([...placed])}`;

        if (signature === stood) {
            return;
        }

        stood = signature;
        clear();

        for (const [kind, placements] of placed) {
            const file = map.FILES[kind];

            if (kind === 'placeholder') {
                instanced(slabGeometry, placeholder, placements.map((p) => fitted(SLAB_BOX, p)));
            } else if (file !== undefined) {
                request(file);
                const model = models.get(file);

                if (model !== undefined) {
                    for (const [shape, same] of shapesOf(kind, model, placements)) {
                        for (const p of shape.primitives) {
                            instanced(p.geometry, materialOf(kind, p.material), same.map((q) => fitted(shape.box, q)));
                        }
                    }
                }
            } else if (map.PIECE3D.includes(kind) && piece3d !== null) {
                // One merged geometry per kind and drawn size, its parts already in units: only moved into place.
                const sizes = new Map();

                for (const p of placements) {
                    const size = `${p.w}×${p.h}`;

                    sizes.set(size, [...(sizes.get(size) ?? []), p]);
                }

                for (const same of sizes.values()) {
                    const parts = piece3d(kind, { w: same[0].w, h: same[0].h });

                    if (parts !== null) {
                        const geometry = mergedParts(parts);

                        built.push(geometry);
                        instanced(geometry, vertexColoured, same.map((p) => new Matrix4().makeTranslation(p.at.x, p.at.y, p.at.z)));
                    }
                }
            }
        }
    };

    return {
        /** Stand the world's furniture, replacing the last; a kit file not yet answered is requested and drawn when it does. */
        rebuild(world) {
            last = world;
            stand(world);
        },

        /** The kit files requested so far — each once (the browser leg's *requested once each*). */
        requested: () => [...requests.keys()],

        dispose() {
            clear();

            slabGeometry.dispose();

            for (const m of [...materials.values(), vertexColoured, placeholder]) {
                m.dispose();
            }

            for (const model of models.values()) {
                for (const p of model.primitives) {
                    p.geometry.dispose();
                }
            }
        },
    };
}

