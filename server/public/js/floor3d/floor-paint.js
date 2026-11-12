/**
 * THE 3-D FLOOR's RENDERER — the floor world (`floor/floor-world.js`) drawn with Three.js over `#floor3d-canvas`, behind
 * the renderer contract the floor page is written against (`floor/floor-page.js`'s header). The one module of the 3-D
 * floor that touches WebGL, `OrbitControls` and `CSS2DRenderer`. `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.4–3.9
 * and § 4 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE FLOOR. What stands where is the world's (a projection of the scene), the camera's limits
 * and the cut-away are `floor3d/view-limits.js`'s, the keys' acts `floor3d/camera-acts.js`'s, the furniture
 * `floor3d/furniture.js`'s, the labels `floor3d/floor-labels.js`'s and the creatures `floor3d/creature-paint.js`'s. The
 * plane is the theme's `plane()` document and the band the theme's band documents, rasterised as the painter draws them;
 * the clock's hands, the windows' sky and the elevator's leaves are drawn here from the scene, as the painter draws them.
 *
 * ⛔ THE CAMERA IS `OrbitControls`, READING THE POINTER ITSELF (the operator's Q2 answer; `FLOOR.md` § 4.5): left-drag or
 * one finger turns, right-drag or two fingers pan ALONG THE FLOOR (`screenSpacePanning` off), the wheel and a pinch dolly,
 * within `limits()` — polar 0 to its maximum, distance its minimum to its maximum, both recomputed on a resize. After every
 * update the target is clamped into the floor's box and the eye moved by the same vector — the one clamp, which the keys'
 * acts reach through the same controls. A wheel that would dolly past a limit is the page's: it scrolls the page. The
 * controls' own key listener is never connected; the keys are the house wire's, over `camera-acts.js`. Under
 * `prefers-reduced-motion` damping is off, so every move is a cut.
 *
 * ⛔ THE FRAME LOOP RUNS ONLY WHILE SOMETHING MOVES (plan § 3.5): a walker in flight, the camera settling under damping,
 * or a cut-away lowering or rising — a frame each display refresh; and with only held loops moving (a held rendering
 * whose `motion` is true), one frame per step of the loop at its `frame_interval_ms`, never the display's rate. It stops
 * the first frame none of those holds, and restarts on a paint, on the controls' change (a drag, a key, a wheel), and on
 * a resource becoming ready — a kit file parsed, a creature built, the plane or the band rasterised — so a floor drawn
 * before its models arrived is drawn again when they do.
 *
 * ⛔ THE CLOCK (plan § 3.5): a held loop steps from the PAINT — `T = (now − paintedAt) / 1000`, its frame `⌊T × fps⌋` plus
 * the character's own loop offset (`loopOffset(key, length)`, keyed by its seat or intern key) — and a walker's time is
 * its effect's `elapsed_ms` plus the time since the paint. The time is set, never accumulated. The rows this slice
 * steps are the held loops whose clip `creatures-3d.js`'s `CLIPS` names (A3's `work`, A4's `think`, A23's
 * `intern-type`) and the walk; every other row is drawn at its still pose until the plan's slice 5 gives it its clip.
 *
 * ⛔ THE CUT-AWAY (plan § 3.7): each frame the segment from the eye to the target is tested against the walls and the
 * band (`cutaway()`); every one it meets is lowered to `CUTAWAY_H` over `CUTAWAY_MS` (a cut under reduced motion), and
 * raised again when clear — each wall on its own, an instance of its height's mesh. A desk and a character are never cut.
 *
 * ⛔ A LOST CONTEXT STOPS THE DRAWING AND SAYS SO (§ 7, `floor3d/main.js`'s statement); a restored one re-uploads every
 * texture from the canvas it is kept as.
 */

import {
    BoxGeometry,
    CanvasTexture,
    CircleGeometry,
    Color,
    DirectionalLight,
    Group,
    HemisphereLight,
    InstancedMesh,
    Matrix4,
    Mesh,
    MeshBasicMaterial,
    MeshToonMaterial,
    PerspectiveCamera,
    PlaneGeometry,
    SRGBColorSpace,
    Scene,
    Vector3,
    WebGLRenderer,
} from '../vendor/three/three.module.js';
import { OrbitControls } from '../vendor/three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { CUTAWAY_H, CUTAWAY_MS, cutaway, limits } from './view-limits.js';
import { SHORTCUTS, cameraActs } from './camera-acts.js';
import { createCreature } from './creature-paint.js';
import { createFurniture, loadKitMap } from './furniture.js';
import { floorLabels } from './floor-labels.js';
import { rasterise } from './svg-texture.js';
import { floorWorld, walkerAt } from '../floor/floor-world.js';
import { SKY_PAINT } from '../floor/floor-layout.js';
import { cabTheme } from '../floor/cab-paint.js';
import { textureSize } from '../building/stack-model.js';

/** The held loops this slice steps, by animation id: the clip of `CLIPS` each draws (plan § 3.5's table). */
const HELD_CLIPS = Object.freeze({ A3: 'work', A4: 'think', A23: 'intern-type' });

/** What each drawn thing counts against in § 6's draw-call budget — one camera layer each, so the leg reads a term alone. */
export const TERMS = Object.freeze({ characters: 1, furniture: 2, walls: 3, fixed: 4, transient: 5 });

/** The contact disc under each character: its radius as a share of the character's height. */
const DISC = 0.22;

/** A texture over a canvas, in the sheet's colour space. */
function textureOf(canvas) {
    const texture = new CanvasTexture(canvas);

    texture.colorSpace = SRGBColorSpace;

    return texture;
}

/** An object and its children on one budget term's layer, and drawn on every camera (layer 0 is the default). */
function onTerm(object, term) {
    object.traverse((o) => {
        o.layers.enable(TERMS[term]);
    });

    return object;
}

/**
 * The 3-D floor's renderer.
 *
 * @param {Document} document
 * @param {Window} window
 * @param {{reduce: boolean, lost: function(): void, restored: function(): void}} options reduced motion, read once, and
 *        what the page does when the WebGL context is lost and when it is back
 */
export function worldRenderer(document, window, { reduce, lost, restored }) {
    const canvas = document.getElementById('floor3d-canvas');
    const labelLayer = document.getElementById('floor3d-labels');
    const focusLine = document.getElementById('floor3d-focus');
    const css = window.getComputedStyle(document.documentElement);
    const cssColour = (name) => css.getPropertyValue(name).trim();

    const renderer = new WebGLRenderer({ canvas, antialias: true });
    const scene = new Scene();
    const camera = new PerspectiveCamera(45, 1, 0.05, 1000);
    const controls = new OrbitControls(camera);
    const labelRenderer = new CSS2DRenderer({ element: labelLayer });

    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setClearColor(new Color(cssColour('--house-trim') || '#1f3540'));
    // Every term's layer drawn: the budget leg narrows the camera to one at a time.
    for (const layer of Object.values(TERMS)) {
        camera.layers.enable(layer);
    }

    controls.screenSpacePanning = false;
    controls.enableDamping = !reduce;
    controls.minPolarAngle = 0;

    let surface = { width: 1, height: 1 };
    let bounds = null;
    let view = null;
    let world = null;
    let alive = true;
    let frames = 0;
    let raf = null;
    let timer = null;
    let screen = null;
    let paintedAt = 0;
    /** the character tree's frame (`SCENE_W` × `SCENE_H`), which the distance limit is one character's height of */
    let character = null;

    /** The world's limits over the stage, re-derived on every resize and every change of the floor's extent. */
    const reLimit = () => {
        if (world === null || character === null) {
            return;
        }

        const fresh = view === null;

        view = limits(world.extent, surface, character);
        camera.near = view.near;
        camera.far = view.far;
        camera.fov = (view.fov * 180) / Math.PI;
        camera.updateProjectionMatrix();
        controls.maxPolarAngle = view.polar.max;
        controls.minDistance = view.distance.min;
        controls.maxDistance = view.distance.max;

        if (fresh) {
            const { position, target } = view.entry;

            controls.target.set(target.x, target.y, target.z);
            camera.position.set(position.x, position.y, position.z);
            controls.update();
        }
    };

    /** THE ONE CLAMP (plan § 3.6): the target into the floor's box, the eye moved by the same vector. */
    const clamp = () => {
        if (view === null) {
            return;
        }

        const t = controls.target;
        const was = t.clone();

        t.set(
            Math.min(view.box.max.x, Math.max(view.box.min.x, t.x)),
            Math.min(view.box.max.y, Math.max(view.box.min.y, t.y)),
            Math.min(view.box.max.z, Math.max(view.box.min.z, t.z)),
        );

        if (!t.equals(was)) {
            camera.position.add(t.clone().sub(was));
            controls.update();
        }
    };

    const acts = cameraActs(controls, {
        surface: () => surface,
        entry: () => view.entry,
        clamp,
        bounds: () => bounds,
    });

    // ── the scene graph ────────────────────────────────────────────────────────────────────────
    const room = onTerm(new Group(), 'fixed');
    const wallGroup = new Group();
    const furnitureGroup = new Group();
    const creatures = new Group();
    const labelGroup = new Group();

    scene.add(room, wallGroup, furnitureGroup, creatures, labelGroup);

    const hemi = new HemisphereLight('#fff6e6', '#8f7a6a', 1.7);
    const sun = new DirectionalLight('#ffffff', 2.2);

    // § 10.4 item 5's one light, from the top-left of the 2-D viewer's room.
    sun.position.set(-4, 8, 6);
    scene.add(hemi, sun);

    // ── the frame loop (the header's rule) ─────────────────────────────────────────────────────
    const wake = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }

        if (raf === null) {
            raf = window.requestAnimationFrame(frame);
        }
    };

    controls.addEventListener('change', wake);

    canvas.addEventListener('webglcontextlost', (event) => {
        event.preventDefault();
        alive = false;
        lost();
    });
    canvas.addEventListener('webglcontextrestored', () => {
        alive = true;
        scene.traverse((o) => {
            for (const m of [o.material].flat()) {
                if (m?.map) {
                    m.map.needsUpdate = true;
                }
            }
        });
        restored();
        wake();
    });

    // ── the walls and the cut-away ─────────────────────────────────────────────────────────────
    const wallBox = new BoxGeometry(1, 1, 1).translate(0.5, 0.5, 0.5);
    /** wall id → `{ mesh, index, box, h }`, `h` the height it is drawn at now */
    let walls = new Map();
    let wallMeshes = [];
    /** the wall ids the walls were last stood for — a map edit that moves a wall stands them again */
    let stoodWalls = null;
    let band = null;
    let bandH = 1;
    let lastStep = null;

    const placeWall = (w) => {
        const m = new Matrix4().makeTranslation(w.box.x, 0, w.box.z).multiply(new Matrix4().makeScale(w.box.w, w.h, w.box.d));

        w.mesh.setMatrixAt(w.index, m);
        w.mesh.instanceMatrix.needsUpdate = true;
    };

    /** Lower what the eye→target segment meets and raise what it no longer does; true while any is still moving. */
    const applyCutaway = (now) => {
        if (world === null) {
            return false;
        }

        const cut = cutaway(camera.position, controls.target, world);
        const dt = lastStep === null ? 0 : now - lastStep;
        const rate = reduce ? Infinity : dt / CUTAWAY_MS;
        let moving = false;
        const toward = (h, full, lowered) => {
            const goal = lowered ? CUTAWAY_H : full;
            const step = Math.min(Math.abs(goal - h), (full - CUTAWAY_H) * rate);
            const next = h + Math.sign(goal - h) * step;

            moving ||= next !== goal;

            return next;
        };

        lastStep = now;

        for (const [id, w] of walls) {
            const h = toward(w.h, w.box.h, cut.has(id));

            if (h !== w.h) {
                w.h = h;
                placeWall(w);
            }
        }

        if (band !== null) {
            bandH = toward(bandH * world.band.h, world.band.h, cut.has('band')) / world.band.h;
            band.scale.y = bandH;
        }

        return moving;
    };

    const standWalls = () => {
        for (const mesh of wallMeshes) {
            wallGroup.remove(mesh);
            mesh.dispose();
        }

        wallMeshes = [];
        walls = new Map();

        for (const kind of ['edge', 'partition']) {
            const of = world.walls.filter((w) => w.kind === kind);

            if (of.length === 0) {
                continue;
            }

            const mesh = onTerm(new InstancedMesh(wallBox, wallMaterial, of.length), 'walls');

            of.forEach((w, index) => {
                const held = { mesh, index, box: w.box, h: w.box.h };

                walls.set(w.id, held);
                placeWall(held);
            });
            mesh.computeBoundingSphere();
            wallGroup.add(mesh);
            wallMeshes.push(mesh);
        }
    };

    // ── the room: the planes and the band ──────────────────────────────────────────────────────
    let roomKey = null;
    let roomParts = [];
    // The walls in the theme's door-view partition colour (`PALETTE.view`, which every theme carries), set on each paint.
    const wallMaterial = new MeshToonMaterial();

    const clearRoom = () => {
        for (const o of roomParts) {
            room.remove(o);
            o.geometry.dispose();
            o.material.map?.dispose();
            o.material.dispose();
        }

        roomParts = [];
        band = null;
    };

    /** A canvas drawn async, its texture uploaded when it answers, and the frame woken. */
    const canvasMesh = (width, height, geometry, draw) => {
        const picture = document.createElement('canvas');

        picture.width = width;
        picture.height = height;

        const texture = textureOf(picture);
        const mesh = onTerm(new Mesh(geometry, new MeshToonMaterial({ map: texture })), 'fixed');

        draw(picture).then(() => {
            texture.needsUpdate = true;
            wake();
        }, () => {});

        return mesh;
    };

    /** The SVG `doc` of a scene's art (`{ theme, fn, input }`) drawn into `g` at `rect`, `k` canvas px a scene px. */
    const drawDoc = async (g, doc, rect, k) => {
        const module = art.themes[doc.theme];

        if (module === null || module === undefined || typeof module[doc.fn] !== 'function') {
            return;
        }

        const tile = document.createElement('canvas');
        const w = Math.max(1, Math.round(rect.w * k));
        const h = Math.max(1, Math.round(rect.h * k));

        await rasterise(tile, module[doc.fn](doc.input), w, h);
        g.drawImage(tile, Math.round(rect.x * k), Math.round(rect.y * k), w, h);
    };

    const standRoom = (sceneIn) => {
        const key = JSON.stringify([world.planes.map((p) => [p.at, p.w, p.d, p.doc]), sceneIn.band, sceneIn.band_docs]);

        if (key === roomKey) {
            return;
        }

        roomKey = key;
        clearRoom();

        const max = renderer.capabilities.maxTextureSize;
        const floorFill = cssColour('--scene-floor') || '#e8dcc4';

        for (const [i, plane] of world.planes.entries()) {
            const px = sceneIn.planes[i];
            const size = textureSize({ w: px.w, h: px.h }, 1, max);
            const k = size.width / px.w;
            const mesh = canvasMesh(size.width, size.height, new PlaneGeometry(plane.w, plane.d), async (c) => {
                const g = c.getContext('2d');

                g.fillStyle = floorFill;
                g.fillRect(0, 0, c.width, c.height);

                if (plane.doc !== null) {
                    await drawDoc(g, plane.doc, { x: 0, y: 0, w: px.w, h: px.h }, k);
                }
            });

            mesh.rotation.x = -Math.PI / 2;
            mesh.position.set(plane.at.x + plane.w / 2, 0, plane.at.z + plane.d / 2);
            room.add(mesh);
            roomParts.push(mesh);
        }

        const b = sceneIn.band;

        if (b !== null && world.band !== null) {
            const size = textureSize({ w: b.w, h: b.h }, 1, max);
            const k = size.width / b.w;
            const at = (r) => ({ x: r.x - b.x, y: r.y - b.y, w: r.w, h: r.h });
            const docs = sceneIn.band_docs;
            const doc = (i) => (docs === null ? null : { theme: docs.theme, fn: docs.docs[i].fn, input: docs.docs[i].input, rect: docs.docs[i].rect });
            const geometry = new PlaneGeometry(world.band.w, world.band.h).translate(world.band.w / 2, world.band.h / 2, 0);

            band = canvasMesh(size.width, size.height, geometry, async (c) => {
                const g = c.getContext('2d');
                const put = async (d) => {
                    if (d !== null) {
                        await drawDoc(g, d, at(d.rect), k);
                    }
                };

                g.fillStyle = cssColour('--house-wall') || '#2a454f';
                g.fillRect(0, 0, c.width, c.height);
                await put(doc(0));

                for (const [i, w] of b.windows.entries()) {
                    const sky = SKY_PAINT[w.sky ?? 'unset'];
                    const r = at(w);
                    const fill = g.createLinearGradient(0, r.y * k, 0, (r.y + r.h) * k);

                    fill.addColorStop(0, sky.top);
                    fill.addColorStop(1, sky.bot);
                    g.fillStyle = fill;
                    g.fillRect(r.x * k, r.y * k, r.w * k, r.h * k);
                    await put(doc(1 + i));
                }

                await put(doc(1 + b.windows.length));

                // The elevator's leaves, shut (their motion is the plan's slice 5), in the building's door colours.
                const e = b.elevator;

                g.fillStyle = cssColour('--door') || '#dbe5ee';
                g.strokeStyle = cssColour('--door-edge') || '#9fb1c4';
                g.lineWidth = Math.max(1, k);

                for (const [x0, x1] of [[e.x, e.seam - 1], [e.seam + 1, e.x + e.w]]) {
                    g.fillRect((x0 - b.x) * k, (e.y - b.y) * k, (x1 - x0) * k, e.h * k);
                    g.strokeRect((x0 - b.x) * k, (e.y - b.y) * k, (x1 - x0) * k, e.h * k);
                }

                await put(doc(2 + b.windows.length));

                const cl = b.clock;
                const cx = (cl.x - b.x + cl.w / 2) * k;
                const cy = (cl.y - b.y + cl.h / 2) * k;

                g.fillStyle = cssColour('--scene-clock-face') || '#fffaf2';
                g.beginPath();
                g.arc(cx, cy, (cl.w / 2) * k * 0.8, 0, 2 * Math.PI);
                g.fill();

                if (cl.set) {
                    g.strokeStyle = cssColour('--scene-ink') || '#3b2f2a';
                    g.lineCap = 'round';

                    for (const [deg, len, width] of [[cl.hour_angle_deg, 0.25, 2.2], [cl.minute_angle_deg, 0.4, 1.4]]) {
                        const a = (deg * Math.PI) / 180;

                        g.lineWidth = width * k;
                        g.beginPath();
                        g.moveTo(cx, cy);
                        g.lineTo(cx + Math.sin(a) * cl.w * len * k, cy - Math.cos(a) * cl.w * len * k);
                        g.stroke();
                    }
                }
            });
            band.position.set(world.band.x, 0, world.band.z);
            band.scale.y = bandH;
            room.add(band);
            roomParts.push(band);
        }
    };

    // ── the screens ────────────────────────────────────────────────────────────────────────────
    /** desk key → `{ mesh, canvas, texture, text }` */
    const screens = new Map();

    const standScreens = () => {
        const wanted = new Set(world.desks.filter((d) => d.screen !== null && d.parts.monitor !== null).map((d) => d.key));

        for (const [key, s] of screens) {
            if (!wanted.has(key)) {
                room.remove(s.mesh);
                s.mesh.geometry.dispose();
                s.texture.dispose();
                s.mesh.material.dispose();
                screens.delete(key);
            }
        }

        for (const desk of world.desks) {
            if (!wanted.has(desk.key)) {
                continue;
            }

            const m = desk.parts.monitor;
            const w = m.w * 0.82;
            const h = m.h * 0.38;

            if (!screens.has(desk.key)) {
                const picture = document.createElement('canvas');

                picture.width = 256;
                picture.height = 160;

                const texture = textureOf(picture);
                const mesh = onTerm(new Mesh(new PlaneGeometry(1, 1), new MeshBasicMaterial({ map: texture })), 'fixed');

                // The screen toward the sitter (the operator's Q4 answer): its face is the plane's back, turned to −z.
                mesh.rotation.y = Math.PI;
                room.add(mesh);
                screens.set(desk.key, { mesh, canvas: picture, texture, text: null });
            }

            const s = screens.get(desk.key);
            const sig = JSON.stringify([desk.screen.lit, desk.screen.text]);

            s.mesh.scale.set(w, h, 1);
            s.mesh.position.set(m.at.x, desk.slab.top + m.h * 0.34, m.at.z - 0.06);

            if (s.text !== sig) {
                s.text = sig;
                const g = s.canvas.getContext('2d');
                const on = desk.screen.lit !== 'off';

                g.fillStyle = cssColour('--scene-monitor') || '#2d3342';
                g.fillRect(0, 0, 256, 160);

                if (on && typeof desk.screen.text === 'string') {
                    g.fillStyle = cssColour('--scene-monitor-on') || '#7fd3c4';
                    g.font = '600 22px system-ui, sans-serif';
                    g.textBaseline = 'top';
                    wrapText(g, desk.screen.text, 14, 16, 228, 26, 5);
                }

                s.texture.needsUpdate = true;
            }
        }
    };

    // ── the characters ────────────────────────────────────────────────────────────────────────
    /** key → `{ creature | null, pending, spec, disc }` */
    const bodies = new Map();
    const discGeometry = new CircleGeometry(1, 20).rotateX(-Math.PI / 2);
    const discMaterial = new MeshBasicMaterial({ color: '#000000', transparent: true, opacity: 0.18, depthWrite: false });
    let discs = null;

    /** What the world draws a body for: each sitter, each intern, each walker — its key, body and where it stands. */
    const wantedBodies = () => {
        const out = new Map();
        const speciesOf = new Map(world.desks.map((d) => [d.key, d.sitter?.species ?? null]));

        for (const d of world.desks) {
            if (d.sitter !== null && d.sitter.pose !== 'empty-chair') {
                out.set(d.key, { key: d.key, species: d.sitter.species, chibi: false, h: d.sitter.h, at: d.sitter.at, pose: d.sitter.pose, held: d.sitter.animation ?? null, walker: null });
            }

            for (const n of d.interns) {
                out.set(n.key, { key: n.key, species: n.seat_species, chibi: true, h: n.h, at: n.at, pose: 'present', held: n.animation ?? null, walker: null });
            }
        }

        for (const w of world.walkers) {
            const fx = w.effect;
            const body = w.chibi ? fx.intern_key : fx.seat_id;
            const species = w.species ?? fx.seat_species ?? speciesOf.get(fx.seat_id) ?? null;
            const seated = out.get(body);

            // A walker is its body away from its desk: drawn under the walk, never twice.
            out.set(body, { key: body, species, chibi: w.chibi, h: seated?.h ?? (w.chibi ? internHeight() : seatHeight()), at: null, pose: 'stand', held: null, walker: w });
        }

        return out;
    };

    const creatureOf = (mesh) => [...bodies.values()].find((b) => b.creature?.mesh === mesh).creature;
    // A walker away from its desk stands as tall as the floor's seated bodies of its kind (the scene draws all alike).
    const seatHeight = () => world.desks.find((d) => d.sitter !== null)?.sitter.h ?? 1.2;
    const internHeight = () => world.desks.flatMap((d) => d.interns)[0]?.h ?? 0.56;

    const standBodies = () => {
        const wanted = wantedBodies();

        for (const [key, b] of bodies) {
            const w = wanted.get(key);

            if (w === undefined || w.species !== b.spec.species || w.chibi !== b.spec.chibi) {
                b.creature?.dispose();
                b.creature && creatures.remove(b.creature.mesh);
                b.dead = true;
                bodies.delete(key);
            }
        }

        for (const [key, spec] of wanted) {
            if (!bodies.has(key)) {
                const b = { creature: null, spec, dead: false };

                bodies.set(key, b);
                createCreature(document, art.characters, key, { species: spec.species ?? undefined, chibi: spec.chibi, pose: 'stand', height: spec.h })
                    .then((c) => {
                        if (b.dead) {
                            c.dispose();

                            return;
                        }

                        b.creature = c;
                        onTerm(c.mesh, 'characters');
                        creatures.add(c.mesh);
                        place(b, window.performance.now());
                        wake();
                    }, () => {
                        // § 9 F14's asset ids, the 2-D painter's: an intern's sprite, or a seat's character.
                        hooks.failed([spec.chibi ? `intern:${key}` : `character:${key}`]);
                    });
            }

            bodies.get(key).spec = spec;
        }

        standDiscs(wanted);
    };

    const standDiscs = (wanted) => {
        if (discs !== null) {
            room.remove(discs);
            discs.dispose();
        }

        const still = [...wanted.values()].filter((s) => s.at !== null);

        discs = onTerm(new InstancedMesh(discGeometry, discMaterial, Math.max(1, still.length)), 'fixed');
        discs.count = still.length;
        still.forEach((s, i) => {
            discs.setMatrixAt(i, new Matrix4().makeTranslation(s.at.x, 0.003, s.at.z).multiply(new Matrix4().makeScale(s.h * DISC, 1, s.h * DISC)));
        });
        discs.instanceMatrix.needsUpdate = true;
        discs.computeBoundingSphere();
        room.add(discs);
    };

    /**
     * One body at `now` (plan § 3.5's clock): a walker at its route's point, turned along its leg and stepping its walk; a
     * seated body at its place, its held loop's pose from the paint. Answers when it next needs a frame: `0` every display
     * frame (a walker in flight), a delay in ms (a held loop's next step), or `null` (still).
     */
    const place = (b, now) => {
        const c = b.creature;
        const s = b.spec;

        if (c === null) {
            return null;
        }

        if (s.walker !== null) {
            const fx = s.walker.effect;
            const t = fx.elapsed_ms + (now - paintedAt);
            const span = Math.max(...fx.legs.map((l) => l.start + l.frames)) * fx.frame_interval_ms;
            const p = walkerAt(world.slab, s.walker, Math.min(t, span));
            const q = walkerAt(world.slab, s.walker, Math.max(0, Math.min(t, span) - 60));

            c.mesh.visible = p !== null && p.where === 'floor';

            if (p !== null) {
                c.mesh.position.set(p.x, 0, p.z);

                if (q !== null && (p.x !== q.x || p.z !== q.z)) {
                    c.mesh.rotation.y = Math.atan2(p.x - q.x, p.z - q.z);
                }
            }

            const moving = fx.frames > 0 && t < span;
            const walk = art.characters.CLIPS.walk;

            c.pose(moving ? walk[Math.floor(t / fx.frame_interval_ms) % walk.length] : 'stand');

            return moving ? 0 : null;
        }

        c.mesh.visible = true;
        c.mesh.position.set(s.at.x, 0, s.at.z);
        c.mesh.rotation.y = 0;

        const clipName = s.held?.motion === true ? HELD_CLIPS[s.held.animation_id] : undefined;
        const base = art.characters.POSES[s.pose] !== undefined ? s.pose : 'stand';

        if (clipName === undefined || typeof s.held.frame_interval_ms !== 'number') {
            c.pose(base);

            return null;
        }

        const clip = art.characters.CLIPS[clipName];
        const ms = s.held.frame_interval_ms;
        const T = now - paintedAt;
        const step = Math.floor(T / ms) + art.characters.loopOffset(s.key, clip.length);

        c.pose(clip[step % clip.length]);

        return clip.length > 1 ? ms - (T % ms) : null;
    };

    // ── one frame ─────────────────────────────────────────────────────────────────────────────
    function frame() {
        // The clock is read now, not taken from the frame's start: a step's timer wakes this frame AT the step, and the
        // frame's start time can precede it, which would draw the step before and wake again a moment later.
        const now = window.performance.now();

        raf = null;

        if (!alive) {
            return;
        }

        frames++;
        const settling = controls.update();

        clamp();

        const tweening = applyCutaway(now);
        let next = null;

        for (const b of bodies.values()) {
            const n = place(b, now);

            next = n === null ? next : next === null ? n : Math.min(next, n);
        }

        renderer.render(scene, camera);
        labelRenderer.render(scene, camera);
        labels?.cull();

        if (settling || tweening || next === 0) {
            raf = window.requestAnimationFrame(frame);
        } else if (next !== null) {
            timer = window.setTimeout(() => {
                timer = null;
                wake();
            }, next);
        } else {
            lastStep = null;
        }
    }

    let art = null;
    let hooks = null;
    let furniture = null;
    let furnitureTheme = null;
    let kitMap = null;
    let labels = null;

    /**
     * The furniture in the floor's theme — its `PALETTE`, and its `piece3d()` or else the house theme's (`FLOOR.md` § 10.6
     * item 1) — made once the kind map has answered and a world is drawn, and made again if the floor's theme changes.
     */
    const standFurniture = () => {
        if (kitMap === null || world === null) {
            return;
        }

        const own = art.themes[world.theme] ?? null;
        const house = art.themes[art.registry?.HOUSE_THEME] ?? null;

        // A floor whose theme module and house theme both failed to load (§ 9 F14) has no colours to paint the kit in:
        // it stands no furniture, as the 2-D floor draws its desks' placeholders and no art.
        if (own === null && house === null) {
            return;
        }

        if (furniture === null || furnitureTheme !== world.theme) {

            furniture?.dispose();
            furnitureTheme = world.theme;
            furniture = createFurniture(furnitureGroup, {
                map: kitMap,
                theme: world.theme,
                palette: (own ?? house).PALETTE,
                piece3d: typeof own?.piece3d === 'function' ? own.piece3d : typeof house?.piece3d === 'function' ? house.piece3d : null,
                ready: () => {
                    onTerm(furnitureGroup, 'furniture');
                    wake();
                },
            });
        }

        furniture.rebuild(world);
        onTerm(furnitureGroup, 'furniture');
        wake();
    };

    const resize = (s) => {
        surface = s;
        renderer.setSize(s.width, s.height, false);
        labelRenderer.setSize(s.width, s.height);
        camera.aspect = s.width / s.height;
        camera.updateProjectionMatrix();
        reLimit();
        wake();
    };

    return {
        camera(screenIn) {
            screen = screenIn;

            return {
                /** The camera an act left: the 3-D camera is the controls', so showing one is drawing a frame. */
                show: () => {
                    wake();
                },
                /** The camera a paint draws at: the controls' own, so the frame's is passed through untouched. */
                current: (frameCamera) => frameCamera,
                /** *Fit the floor*: the entry pose, re-derived for the stage as it stands. */
                fit: () => {
                    if (view !== null) {
                        acts.fit();
                    }

                    wake();
                },
                resize: (s) => {
                    screen.resize(s);
                    resize(s);
                },
                /**
                 * The pointer is the controls': connected to the stage, its wheel released to the page at a dolly limit.
                 * The stage's context menu is the controls' to suppress (right-drag pans).
                 */
                gestures(stage) {
                    controls.connect(stage);
                    stage.addEventListener('wheel', (event) => {
                        const d = controls.getDistance();
                        const atLimit = event.deltaY < 0 ? d <= controls.minDistance + 1e-6 : d >= controls.maxDistance - 1e-6;

                        controls.enableZoom = !(view !== null && event.deltaY !== 0 && atLimit);
                    }, { capture: true, passive: true });
                },
                keys: { acts, shortcuts: SHORTCUTS },
            };
        },

        create(artIn, hooksIn) {
            art = artIn;
            hooks = hooksIn;
            labels = floorLabels(labelLayer, labelGroup, {
                select: hooks.select,
                link: hooks.link,
                focused: (desk) => {
                    // Keyboard focus on a desk's name: the target to the desk, the eye moved by the same vector — the
                    // angles and the distance left as they are (plan § 3.6).
                    const to = new Vector3((desk.slab.left + desk.slab.right) / 2, 0, (desk.slab.back + desk.slab.front) / 2);

                    camera.position.add(to.clone().sub(controls.target));
                    controls.target.copy(to);
                    controls.update();
                    clamp();
                    focusLine.textContent = `looking at ${desk.seat_id}`;
                    wake();
                },
            });

            loadKitMap().then((map) => {
                kitMap = map;
                standFurniture();
            }, () => {
                hooks.failed(['models:kenney-furniture-kit']);
            });

            return {
                paint(sceneIn) {
                    if (sceneIn === null) {
                        return;
                    }

                    const extent = world?.extent ?? null;

                    world = { ...floorWorld(sceneIn), theme: sceneIn.theme };
                    paintedAt = window.performance.now();
                    bounds = screen.camera().bounds;

                    const palette = (art.themes[sceneIn.theme] ?? art.themes[art.registry?.HOUSE_THEME])?.PALETTE;

                    // A theme module that failed to load (§ 9 F14) leaves no palette: the walls in the house trim, as the
                    // 2-D floor draws its walls in a flat fallback fill.
                    wallMaterial.color.set(palette?.view.partition ?? (cssColour('--house-trim') || '#1f3540'));

                    if (extent === null || extent.w !== world.extent.w || extent.d !== world.extent.d) {
                        character = { w: art.characters.SCENE_W, h: art.characters.SCENE_H };
                        reLimit();
                    }

                    const wallKey = world.walls.map((w) => w.id).join(' ');

                    if (wallKey !== stoodWalls) {
                        stoodWalls = wallKey;
                        standWalls();
                    }

                    standRoom(sceneIn);
                    standFurniture();
                    standScreens();
                    standBodies();
                    labels.sync(world);
                    wake();
                },

                /**
                 * The 1 s age tick: the desks' strings again, written into the labels — nothing moves, so no frame is drawn
                 * for it (a label is DOM, and its new text shows as it is written).
                 */
                refresh(sceneView) {
                    if (sceneView === null || world === null) {
                        return;
                    }

                    world = { ...floorWorld(sceneView), theme: sceneView.theme };
                    labels.sync(world);
                },

                /** The ride's cab drawing (§ 4.5): no control on this floor starts a ride before the plan's slice 6. */
                paintCab() {},

                /** The page's cab dialog in the house theme's elevator palette, as the 2-D floor's (card#12039). */
                cabTheme: (dialog) => cabTheme(dialog, art.themes[art.registry?.HOUSE_THEME]?.PALETTE?.elevator ?? null),

                /**
                 * What the browser leg reads (`tools/design/floor3d.browser.mjs`): the camera, the frames drawn, the
                 * walls' heights, the renderer's counters, and § 6's figures term by term after a render of each alone.
                 * It carries no fact and moves nothing.
                 */
                inspect() {
                    const terms = {};

                    for (const [term, layer] of Object.entries(TERMS)) {
                        camera.layers.set(layer);
                        renderer.render(scene, camera);
                        terms[term] = { calls: renderer.info.render.calls, triangles: renderer.info.render.triangles };
                    }

                    camera.layers.enableAll();
                    renderer.render(scene, camera);

                    // Every texture the floor holds: the room's and the screens' maps, each creature's own (its face atlas
                    // open and shut and its paint, `textures()`) and its skeleton's bone texture.
                    const textures = [];
                    const held = new Set([...bodies.values()].filter((b) => b.creature !== null).map((b) => b.creature.mesh));

                    scene.traverse((o) => {
                        if (held.has(o)) {
                            textures.push(...creatureOf(o).textures().map((t) => ({ ...t, float: false })));
                            if (o.skeleton.boneTexture !== null) {
                                textures.push({ width: o.skeleton.boneTexture.image.width, height: o.skeleton.boneTexture.image.height, float: true });
                            }
                        } else if (o.material?.map) {
                            textures.push({ width: o.material.map.image.width, height: o.material.map.image.height, float: false });
                        }
                    });

                    return {
                        frames,
                        polar: controls.getPolarAngle(),
                        azimuth: controls.getAzimuthalAngle(),
                        distance: controls.getDistance(),
                        limits: view === null ? null : { polar: view.polar, distance: view.distance, near: view.near, far: view.far, box: view.box },
                        target: controls.target.toArray(),
                        eye: camera.position.toArray(),
                        walls: [...walls].map(([id, w]) => [id, w.h]),
                        band: band === null ? null : bandH * world.band.h,
                        calls: renderer.info.render.calls,
                        triangles: renderer.info.render.triangles,
                        memory: { ...renderer.info.memory },
                        terms,
                        textures,
                        bodies: [...bodies.values()].filter((b) => b.creature !== null).length,
                        seats: [...bodies.values()].filter((b) => b.creature !== null && !b.spec.chibi).length,
                        interns: [...bodies.values()].filter((b) => b.creature !== null && b.spec.chibi).length,
                        desks: world === null ? [] : world.desks.map((d) => ({ key: d.key, back: d.slab.back, front: d.slab.front, left: d.slab.left, right: d.slab.right, seat: d.seat_id })),
                        // where each built body's face patch is on the stage, CSS px — the face leg's pixel read
                        faces: [...bodies.values()].filter((b) => b.creature !== null && b.creature.mesh.visible).map((b) => {
                            const p = b.creature.mesh.localToWorld(new Vector3(...b.creature.parts.face.at)).project(camera);

                            return { key: b.spec.key, x: ((p.x + 1) / 2) * surface.width, y: ((1 - p.y) / 2) * surface.height, h: b.spec.h };
                        }),
                        requested: furniture?.requested() ?? [],
                        labels: labels.count(),
                    };
                },
            };
        },
    };
}

/** Text wrapped into at most `lines` lines of `width` px from `(x, y)`, `step` px apart, the last cut with a mark. */
function wrapText(g, text, x, y, width, step, lines) {
    const words = String(text).split(/\s+/);
    const out = [];
    let line = '';

    for (const word of words) {
        const next = line === '' ? word : `${line} ${word}`;

        if (g.measureText(next).width > width && line !== '') {
            out.push(line);
            line = word;
        } else {
            line = next;
        }
    }

    out.push(line);

    if (out.length > lines) {
        out.length = lines;
        out[lines - 1] = `${out[lines - 1]}…`;
    }

    out.forEach((l, i) => g.fillText(l, x, y + i * step));
}
