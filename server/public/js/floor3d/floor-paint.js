/**
 * THE 3-D FLOOR's RENDERER — the floor world (`floor/floor-world.js`) drawn with Three.js over `#floor3d-canvas`, behind
 * the renderer contract the floor page is written against (`floor/floor-page.js`'s header). The one module of the 3-D
 * floor that constructs the WebGL renderer, `OrbitControls` and `CSS2DRenderer`. `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.4–3.9
 * and § 4 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE FLOOR, AND IT IS THE COMPOSER. What stands where is the world's (a projection of the
 * scene), the camera's limits and the cut-away are `floor3d/view-limits.js`'s, the keys' acts `floor3d/camera-acts.js`'s,
 * the furniture `floor3d/furniture.js`'s, the labels `floor3d/floor-labels.js`'s; the room — planes, walls, band, screens —
 * is `floor3d/room-paint.js`'s, the bodies and their clock `floor3d/body-paint.js`'s, and the one frame queue
 * `floor3d/frame-loop.js`'s. This file holds the camera and its controls, builds the parts, and draws a frame.
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
 * or a cut-away lowering or rising — a frame each display refresh and never more, since one frame is pending at a time
 * (`frame-loop.js`); and with only held loops moving (a held rendering whose `motion` is true), one frame per step of the
 * loop at its `frame_interval_ms`, never the display's rate. It stops the first frame none of those holds, and restarts
 * on a paint, on the controls' change (a drag, a key, a wheel), and on a resource becoming ready — a kit file parsed, a
 * creature built, the plane or the band rasterised — so a floor drawn before its models arrived is drawn again when they
 * do.
 *
 * ⛔ THE CLOCK (plan § 3.5) is `body-paint.js`'s and the CUT-AWAY (plan § 3.7) `room-paint.js`'s; each states its rule.
 *
 * ⛔ A LOST CONTEXT STOPS THE DRAWING AND SAYS SO (§ 7, `floor3d/main.js`'s statement); a restored one re-uploads every
 * texture from the canvas it is kept as.
 */

import {
    Color,
    DirectionalLight,
    Group,
    HemisphereLight,
    PerspectiveCamera,
    Scene,
    Vector3,
    WebGLRenderer,
} from '../vendor/three/three.module.js';
import { OrbitControls } from '../vendor/three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { limits } from './view-limits.js';
import { SHORTCUTS, cameraActs } from './camera-acts.js';
import { createFurniture, loadKitMap } from './furniture.js';
import { floorLabels } from './floor-labels.js';
import { frameLoop } from './frame-loop.js';
import { floorRoom } from './room-paint.js';
import { floorBodies } from './body-paint.js';
import { TERMS, onTerm } from './terms.js';
import { floorWorld } from '../floor/floor-world.js';
import { cabTheme } from '../floor/cab-paint.js';

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
    let screen = null;
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
    const roomGroup = onTerm(new Group(), 'fixed');
    const wallGroup = new Group();
    const furnitureGroup = new Group();
    const creatures = new Group();
    const labelGroup = new Group();

    scene.add(roomGroup, wallGroup, furnitureGroup, creatures, labelGroup);

    const hemi = new HemisphereLight('#fff6e6', '#8f7a6a', 1.7);
    const sun = new DirectionalLight('#ffffff', 2.2);

    // § 10.4 item 5's one light, from the top-left of the 2-D viewer's room.
    sun.position.set(-4, 8, 6);
    scene.add(hemi, sun);

    let art = null;
    let hooks = null;
    let furniture = null;
    let furnitureTheme = null;
    let kitMap = null;
    let labels = null;

    // ── the parts, and the one frame ───────────────────────────────────────────────────────────
    const room = floorRoom({ document, renderer, cssColour, reduce, wake: () => loop.wake(), room: roomGroup, walls: wallGroup, art: () => art });
    const bodies = floorBodies({
        document,
        creatures,
        room: roomGroup,
        wake: () => loop.wake(),
        art: () => art,
        failed: (ids) => hooks.failed(ids),
        now: () => window.performance.now(),
    });

    /** One frame at `now`: the controls, the clamp, the cut-away and the bodies, drawn; when the next one is needed. */
    const draw = (now) => {
        if (!alive) {
            return null;
        }

        frames++;
        const settling = controls.update();

        clamp();

        const tweening = room.cutaway(now, camera.position, controls.target);
        const next = bodies.place(now);

        renderer.render(scene, camera);
        labelRenderer.render(scene, camera);
        labels?.cull();

        return settling || tweening ? 0 : next;
    };

    const loop = frameLoop(window, draw, { rested: () => room.rested() });
    const wake = loop.wake;

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
                    bounds = screen.camera().bounds;

                    const palette = (art.themes[sceneIn.theme] ?? art.themes[art.registry?.HOUSE_THEME])?.PALETTE;

                    if (extent === null || extent.w !== world.extent.w || extent.d !== world.extent.d) {
                        character = { w: art.characters.SCENE_W, h: art.characters.SCENE_H };
                        reLimit();
                    }

                    // A theme module that failed to load (§ 9 F14) leaves no palette: the walls in the house trim, as the
                    // 2-D floor draws its walls in a flat fallback fill.
                    room.paint(world, sceneIn, palette?.view.partition ?? (cssColour('--house-trim') || '#1f3540'));
                    standFurniture();
                    bodies.paint(world, window.performance.now());
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

                    // Every texture the floor holds: the room's and the screens' maps, and each creature's own (its face
                    // atlas open and shut and its paint) with its skeleton's bone texture (`body-paint.js`'s).
                    const held = bodies.inspect(camera, surface);
                    const textures = [...held.textures];

                    scene.traverse((o) => {
                        if (!held.meshes.has(o) && o.material?.map) {
                            textures.push({ width: o.material.map.image.width, height: o.material.map.image.height, float: false });
                        }
                    });

                    const heights = room.heights();

                    return {
                        frames,
                        polar: controls.getPolarAngle(),
                        azimuth: controls.getAzimuthalAngle(),
                        distance: controls.getDistance(),
                        limits: view === null ? null : { polar: view.polar, distance: view.distance, near: view.near, far: view.far, box: view.box },
                        target: controls.target.toArray(),
                        eye: camera.position.toArray(),
                        walls: heights.walls,
                        band: heights.band,
                        calls: renderer.info.render.calls,
                        triangles: renderer.info.render.triangles,
                        memory: { ...renderer.info.memory },
                        terms,
                        textures,
                        bodies: held.bodies,
                        seats: held.seats,
                        interns: held.interns,
                        desks: world === null ? [] : world.desks.map((d) => ({ key: d.key, back: d.slab.back, front: d.slab.front, left: d.slab.left, right: d.slab.right, seat: d.seat_id })),
                        faces: held.faces,
                        requested: furniture?.requested() ?? [],
                        labels: labels.count(),
                    };
                },
            };
        },
    };
}
