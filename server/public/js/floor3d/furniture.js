/**
 * THE 3-D FLOOR's FURNITURE — every standing piece and every desk's furniture set the floor world places
 * (`floor/floor-world.js`'s `pieces` and each desk's `slab` and `parts`), drawn from Kenney's Furniture Kit where the kind
 * map gives a file and from the theme's `piece3d()` part lists where it lists the kind as first-party, and a kit piece the
 * map lists as `DRESSED` carrying the theme's `dressing3d()` part list on its top (the snack tables' coffee maker, donuts
 * and cups). Where each placement stands is `floor3d/furniture-placements.js`'s.
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.4 and § 6 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE INSTANCED MESH PER (PRIMITIVE, MATERIAL) PAIR — § 6's furniture term: a kit file is loaded once and each of its
 * primitives is one `InstancedMesh` holding every placement of its kind on the floor; a first-party piece, and a
 * dressing, is one merged geometry of its parts per kind and drawn size, instanced the same way; the side table's couch
 * is its file lengthened once per couch length, instanced the same way (`shapesOf()`). So the floor's furniture costs a draw call per primitive
 * of each kind it places — the couch's per length it stands, a dressed kind's one more for its dressing — whatever the
 * number of placements.
 *
 * ⛔ THE KIT IS RECOLOURED BY REPLACING ITS MATERIALS, NEVER BY TINTING THEM. Its files declare `KHR_materials_unlit`,
 * so `GLTFLoader` builds a `MeshBasicMaterial` for each — unshaded. Every primitive is drawn in a toon material
 * (`toon.js`) of the colour the kind map's `PAINT` gives its material in the floor's theme, the creatures' material, so the
 * two sources shade alike under the room's lights; the loaded materials and textures are disposed of at once.
 *
 * ⛔ EACH PIECE STANDS WHERE THE WORLD PUTS IT AND AS BIG AS ITS 2-D PICTURE: a kit model is fitted to the drawn width
 * across and the drawn height up (its depth follows its width, so it keeps its plan), its foot on the floor and its
 * front toward `+z` — the side the 2-D floor draws, or turned as its placement says (a restroom door set into a side wall
 * faces the room from the wall's plane); a desk is fitted to its slab, its drawers toward its sitter; a monitor
 * faces its sitter (plan § 3.4, the operator's Q4 answer); a couch is fitted to the box the world lays it in, at one
 * scale up and across, as deep as that box (its row's footprint), lengthened between its arms (`lengthened()`), so its
 * seat is where the world seats its interns; a dressed piece is fitted to its dressing's top and depth, and the dressing
 * stands on it at the piece's foot.
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
    Quaternion,
    Vector3,
} from '../vendor/three/three.module.js';
import { mergeGeometries } from '../vendor/three/examples/jsm/utils/BufferGeometryUtils.js';
import { GLTFLoader } from '../vendor/three/examples/jsm/loaders/GLTFLoader.js';
import { DETAIL, partGeometry } from './part-geometry.js';
import { standsOnFloor } from './shade-rects.js';
import { toon } from './toon.js';
import { dressingOf, inputsOf, placementsOf } from './furniture-placements.js';
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
    const { size, sx, sz } = fitScale(box, { w, d });
    const centre = new Matrix4().makeTranslation(-(box.min.x + box.max.x) / 2, -box.min.y, -(box.min.z + box.max.z) / 2);
    const place = new Matrix4().compose(new Vector3(at.x, at.y, at.z), new Quaternion().setFromAxisAngle(Y, turn), new Vector3(sx, h / size.y, sz));

    return place.multiply(centre);
}

/** The scale `fitted()` applies across (`sx`) and in depth (`sz`), and the box's size. */
function fitScale(box, { w, d = null }) {
    const size = box.getSize(new Vector3());
    const sx = w / size.x;

    return { size, sx, sz: d === null ? sx : d / size.z };
}

/** What a placement fitted from `box` covers of the floor: its half width and half depth, centred on `at`, turned `turn`. */
function footprintOf(kind, box, p) {
    const { size, sz } = fitScale(box, p);

    return { kind, at: p.at, hw: p.w / 2, hd: (sz * size.z) / 2, turn: p.turn ?? 0 };
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

/** A part list merged into one geometry at `detail`'s facets, each part's colour on its vertices. */
function mergedParts(parts, detail) {
    return mergeGeometries(parts.map((part) => {
        const geo = partGeometry(part, detail);
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
 * @param {{map: object, theme: string, palette: object, piece3d: function|null, dressing3d: function|null,
 *          ready: function(): void, fetch?: function(string): Promise<ArrayBuffer>}} options the kind map module (`FILES`,
 *        `PIECE3D`, `DRESSED`, `PAINT`), the floor's theme and its `PALETTE`, the `piece3d()` that draws the first-party
 *        kinds and the `dressing3d()` that dresses the `DRESSED` ones (each the theme's, or the house theme's), what is
 *        called when a kit file has answered, and how a file's bytes are fetched
 * @returns {{rebuild: function(object): void, footprints: function(): list<object>, requested: function(): list<string>,
 *           dispose: function(): void}}
 */
export function createFurniture(group, { map, theme, palette, piece3d, dressing3d, ready, fetch = (url) => window.fetch(url).then((r) => r.arrayBuffer()) }) {
    const loader = new GLTFLoader();
    /** file → `{ primitives, box }` once parsed */
    const models = new Map();
    /** file → the request in flight or answered */
    const requests = new Map();
    /** `kind/material` → its toon material in the floor's theme */
    const materials = new Map();
    /** a dressing's placement key → the kind it dresses (placed only where `dressing3d` is given) */
    const dressings = new Map(map.DRESSED.map((kind) => [dressingOf(kind), kind]));
    const vertexColoured = toon({ vertexColors: true });
    const placeholder = toon({ color: new Color(PLACEHOLDER) });
    const slabGeometry = new BoxGeometry(1, 1, 1).translate(0, 0.5, 0);
    /** the meshes the last rebuild stood, and the first-party geometries it built */
    let meshes = [];
    let built = [];
    /** what the last stand covers of the floor: `{ kind, at, hw, hd, turn, facing? }` a placement drawn */
    let footprints = [];
    let last = null;
    /** what the last stand placed, and how many files had answered — a paint that changes neither rebuilds nothing */
    let stood = null;

    const materialOf = (kind, name) => {
        const key = `${kind}/${name}`;

        if (!materials.has(key)) {
            const path = map.PAINT[kind]?.[name]?.[theme];

            materials.set(key, toon({ color: new Color(colourAt(palette, path)) }));
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
        footprints = [];
    };

    /**
     * A first-party part list over `placements`: one merged geometry per distinct inputs (`inputsOf()` — the drawn size,
     * and a side door's facing and flag), its parts already in units, only turned and moved into place — `partsOf(inputs)`
     * the list, or `null` for none. A standing piece's (`kind`) footprint is its merged geometry's plan; a dressing has none.
     */
    const firstParty = (placements, partsOf, detail, kind = null) => {
        const shapes = new Map();

        for (const p of placements) {
            const key = JSON.stringify(inputsOf(p));

            shapes.set(key, [...(shapes.get(key) ?? []), p]);
        }

        for (const same of shapes.values()) {
            const parts = partsOf(inputsOf(same[0]));

            if (parts !== null) {
                const geometry = mergedParts(parts, detail);

                built.push(geometry);

                if (kind !== null) {
                    geometry.computeBoundingBox();
                    const { min, max } = geometry.boundingBox;
                    const mid = new Vector3((min.x + max.x) / 2, 0, (min.z + max.z) / 2);

                    for (const p of same) {
                        const at = mid.clone().applyAxisAngle(Y, p.turn ?? 0).add(new Vector3(p.at.x, p.at.y, p.at.z));

                        footprints.push({ kind, at: { x: at.x, y: p.at.y, z: at.z }, hw: (max.x - min.x) / 2, hd: (max.z - min.z) / 2, turn: p.turn ?? 0, ...(p.facing === undefined ? {} : { facing: p.facing }) });
                    }
                }

                instanced(geometry, vertexColoured, same.map((p) => new Matrix4().makeTranslation(p.at.x, p.at.y, p.at.z).multiply(new Matrix4().makeRotationY(p.turn ?? 0))));
            }
        }
    };

    const stand = (world) => {
        const placed = placementsOf(world, map.DRESSED, dressing3d);
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
                footprints.push(...placements.map((p) => footprintOf(kind, SLAB_BOX, p)));
            } else if (file !== undefined) {
                request(file);
                const model = models.get(file);

                if (model !== undefined) {
                    for (const [shape, same] of shapesOf(kind, model, placements)) {
                        for (const p of shape.primitives) {
                            instanced(p.geometry, materialOf(kind, p.material), same.map((q) => fitted(shape.box, q)));
                        }

                        footprints.push(...same.map((q) => footprintOf(kind, shape.box, q)));
                    }
                }
            } else if (map.PIECE3D.includes(kind) && piece3d !== null) {
                firstParty(placements, (size) => piece3d(kind, size), DETAIL.seat, kind);
            } else if (dressings.has(kind)) {
                // What a theme stands on a kit piece: small things, drawn at an intern's facets.
                firstParty(placements, (size) => dressing3d(dressings.get(kind), size)?.parts ?? null, DETAIL.chibi);
            }
        }
    };

    return {
        /** Stand the world's furniture, replacing the last; a kit file not yet answered is requested and drawn when it does. */
        rebuild(world) {
            last = world;
            stand(world);
        },

        /** What the last stand covers of the floor, a footprint a placement drawn (a dressing's none). */
        footprints: () => footprints,

        /**
         * How many placements the world stands on the floor — at `y` 0 and not set into a wall, a dressing not one —
         * whether drawn yet or not: the population the browser leg holds the ground shade's furniture rects to.
         */
        standing: () => (last === null ? 0 : [...placementsOf(last, map.DRESSED, dressing3d)].filter(([kind]) => !dressings.has(kind))
            .flatMap(([, placements]) => placements).filter(standsOnFloor).length),

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

