/**
 * THE 3-D FLOOR's RENDERER — the floor world (`floor/floor-world.js`) drawn with Three.js over `#floor3d-canvas`, behind
 * the renderer contract the floor page is written against (`floor/floor-page.js`'s header). The one module of the 3-D
 * floor that constructs the WebGL renderer, `OrbitControls` and `CSS2DRenderer`. `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.4–3.9
 * and § 4 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE FLOOR, AND IT IS THE COMPOSER. What stands where is the world's (a projection of the
 * scene), the camera's limits and the cut-away are `floor3d/view-limits.js`'s, the keys' acts `floor3d/camera-acts.js`'s,
 * the furniture `floor3d/furniture.js`'s, the labels `floor3d/floor-labels.js`'s; the room — planes, walls, band, the
 * elevator's and the restroom's leaves, screens — is `floor3d/room-paint.js`'s, the bodies, their marks and their clock
 * `floor3d/body-paint.js`'s (over `floor3d/clip-clock.js`), the birds and the ring `floor3d/transient-paint.js`'s, and the
 * one frame queue `floor3d/frame-loop.js`'s. This file holds the camera and its controls, builds the parts, and draws a
 * frame: each part says when it next needs one, and the soonest is the frame's answer.
 *
 * ⛔ THE CAMERA IS `OrbitControls`, READING THE POINTER ITSELF (the operator's Q2 answer; `FLOOR.md` § 4.5): left-drag or
 * one finger turns, right-drag or two fingers pan ALONG THE FLOOR, the wheel and a pinch dolly, within `limits()` as
 * `view-limits.js`'s `configureControls()` sets them — recomputed on a resize. An Alt +
 * left-drag is taken from the controls before they see it: its vertical movement raises or lowers the eye
 * (`camera-acts.js`'s `rise()`). After every update the camera is held in the office by `view-limits.js`'s
 * `confineControls()` — the one clamp, which the keys' acts, the rise and a label's focus reach through the same
 * controls, and after which nothing updates the controls before the frame is drawn. A wheel that would
 * dolly past a limit is the page's: it scrolls the page. The controls' own key listener is never connected; the keys
 * are the house wire's, over `camera-acts.js`. Under `prefers-reduced-motion` damping is off, so every move is a cut.
 *
 * ⛔ THE FRAME LOOP RUNS ONLY WHILE SOMETHING MOVES (plan § 3.5): a walker or a bird in flight, a screen cross-fading,
 * the camera settling under damping, or a cut-away lowering or rising — a frame each display refresh and never more, since one frame is pending at a time
 * (`frame-loop.js`); and with only held loops moving (a held rendering whose `motion` is true), one frame per step of the
 * loop at its `frame_interval_ms` — a body's, a mark's, a screen's, a leaf's, a ring's step — never the display's rate. It stops the first frame none of those holds, and restarts
 * on a paint, on the controls' change (a drag, a key, a wheel), and on a resource becoming ready — a kit file parsed, a
 * creature built, the plane or the band rasterised — so a floor drawn before its models arrived is drawn again when they
 * do.
 *
 * ⛔ THE ELEVATOR's DOORWAY IS A CONTROL (`FLOOR.md` § 4.5; plan § 3.8): a primary press and release on the stage that
 * moved less than `DRAG_SLOP_PX` — a click, not a turn — whose ray meets the doorway (`room-paint.js`'s hit area over its
 * frame, leaves and opening) before any wall, piece of furniture or part of the room calls the page's `ride` once; a
 * body or a bird in front of it does not stand in the way, as the 2-D floor's walkers let the pointer through. Its
 * keyboard route is `floor-labels.js`'s button. The ride's cab is the 2-D floor's — `floor/cab-paint.js`'s `paintCab()`,
 * handed on as the 2-D painter hands it — and the ride's leaves are drawn by `room-paint.js` from `scene.ride`.
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
    Raycaster,
    Scene,
    Vector2,
    Vector3,
    WebGLRenderer,
} from '../vendor/three/three.module.js';
import { OrbitControls } from '../vendor/three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { configureControls, confineControls, limits } from './view-limits.js';
import { SHORTCUTS, cameraActs } from './camera-acts.js';
import { createFurniture, loadKitMap } from './furniture.js';
import { floorLabels } from './floor-labels.js';
import { frameLoop } from './frame-loop.js';
import { floorRoom } from './room-paint.js';
import { floorBodies } from './body-paint.js';
import { floorTransients } from './transient-paint.js';
import { TERMS, onTerm } from './terms.js';
import { floorWorld } from '../floor/floor-world.js';
import { cabTheme, paintCab } from '../floor/cab-paint.js';
import { DRAG_SLOP_PX } from '../wire/camera-gestures.js';

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
    const markLayer = document.getElementById('floor3d-marks');
    const focusLine = document.getElementById('floor3d-focus');
    const css = window.getComputedStyle(document.documentElement);
    const cssColour = (name) => css.getPropertyValue(name).trim();

    const renderer = new WebGLRenderer({ canvas, antialias: true });
    const scene = new Scene();
    const camera = new PerspectiveCamera(45, 1, 0.05, 1000);
    const controls = new OrbitControls(camera);
    const labelRenderer = new CSS2DRenderer({ element: labelLayer });
    // The marks over the bodies — z's, markers, a walker's thought — in a layer and a scene of their own, apart from the
    // desks' labels, whose layer is in reading order (`floor-labels.js`).
    const markScene = new Scene();
    const markRenderer = new CSS2DRenderer({ element: markLayer });

    renderer.setPixelRatio(window.devicePixelRatio);
    // The cab's clipping planes (`room-paint.js`'s `clipCab()`): a material's own planes, which no other material carries.
    renderer.localClippingEnabled = true;
    renderer.setClearColor(new Color(cssColour('--house-trim') || '#1f3540'));
    // Every term's layer drawn: the budget leg narrows the camera to one at a time.
    for (const layer of Object.values(TERMS)) {
        camera.layers.enable(layer);
    }

    controls.enableDamping = !reduce;

    let surface = { width: 1, height: 1 };
    let bounds = null;
    let view = null;
    let world = null;
    let alive = true;
    let frames = 0;
    let paints = 0;
    /** when the last paint ran (`performance.now()`) — handed to every part as the instant it steps from */
    let paintAt = null;
    let screen = null;
    /** the character tree's frame (`SCENE_W` × `SCENE_H`), which the distance limit is one character's height of */
    let character = null;
    /** the pose the clamp last held the camera at — the confinement's `prev` */
    let pose = null;

    /** The world's limits over the stage, re-derived on every resize and every change of the floor's extent. */
    const reLimit = () => {
        if (world === null || character === null) {
            return;
        }

        const fresh = view === null;

        view = limits(world.extent, surface, character);
        configureControls(controls, view);

        if (fresh) {
            const { position, target } = view.entry;

            controls.target.set(target.x, target.y, target.z);
            camera.position.set(position.x, position.y, position.z);
            controls.update();
            pose = { eye: position, target };
        }
    };

    /** THE ONE CLAMP (plan § 3.6): the camera held in the office (`confine()`'s rule). */
    const clamp = () => {
        if (view !== null) {
            pose = confineControls(controls, view.bound, pose);
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
    const transientGroup = new Group();

    scene.add(roomGroup, wallGroup, furnitureGroup, creatures, labelGroup, transientGroup);

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
    /** The house theme's elevator palette — the cab dialog's and the cab's room's — or `null` when that theme did not load. */
    const cabPalette = () => art.themes[art.registry?.HOUSE_THEME]?.PALETTE?.elevator ?? null;

    // ── the parts, and the one frame ───────────────────────────────────────────────────────────
    const room = floorRoom({ document, renderer, cssColour, reduce, wake: () => loop.wake(), room: roomGroup, walls: wallGroup, art: () => art, cabPalette });
    const bodies = floorBodies({
        document,
        creatures,
        room: roomGroup,
        wake: () => loop.wake(),
        art: () => art,
        failed: (ids) => hooks.failed(ids),
        now: () => window.performance.now(),
        marks: markScene,
    });
    const transients = floorTransients({ group: transientGroup });

    // The pointer's ray, through every term's layer; what it may meet first is the room's structure and the furniture.
    const raycaster = new Raycaster();

    raycaster.layers.enableAll();

    /** Whether a pointer at `(clientX, clientY)` points at the elevator's doorway first (the header's rule). */
    const atDoorway = (clientX, clientY) => {
        const r = canvas.getBoundingClientRect();

        raycaster.setFromCamera(new Vector2(((clientX - r.left) / r.width) * 2 - 1, 1 - ((clientY - r.top) / r.height) * 2), camera);

        const [first] = raycaster.intersectObjects([roomGroup, wallGroup, furnitureGroup], true);

        return first !== undefined && room.isDoorway(first.object);
    };

    /**
     * One frame at `now`: the controls, the clamp, the cut-away, the bodies, the room's moving parts and the birds, drawn;
     * when the next one is needed — the soonest any of them asks for.
     */
    const draw = (now) => {
        if (!alive) {
            return null;
        }

        frames++;
        const settling = controls.update();

        clamp();

        const tweening = room.cutaway(now, camera.position, controls.target);
        const waits = [bodies.place(now), room.place(now), transients.place(now)].filter((n) => n !== null);

        renderer.render(scene, camera);
        labelRenderer.render(scene, camera);
        markRenderer.render(markScene, camera);
        labels?.cull();

        return settling || tweening ? 0 : waits.length === 0 ? null : Math.min(...waits);
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
     * The furniture in the floor's theme — its `PALETTE`, and its `piece3d()` and `dressing3d()`, each or else the house
     * theme's (`FLOOR.md` § 10.6 item 1) — made once the kind map has answered and a world is drawn, and made again if
     * the floor's theme changes.
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
                dressing3d: typeof own?.dressing3d === 'function' ? own.dressing3d : typeof house?.dressing3d === 'function' ? house.dressing3d : null,
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
        markRenderer.setSize(s.width, s.height);
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
                 * The stage's context menu is the controls' to suppress (right-drag pans). An Alt + left press is the
                 * rise's: its listener is added before the controls' and stops the event there, so they neither turn
                 * nor pan for it, and the drag's vertical movement raises or lowers the eye until it is released.
                 */
                gestures(stage) {
                    let rising = null;

                    stage.addEventListener('pointerdown', (event) => {
                        if (!event.altKey || event.button !== 0 || view === null) {
                            return;
                        }

                        event.stopImmediatePropagation();
                        event.preventDefault();
                        stage.setPointerCapture(event.pointerId);
                        rising = { id: event.pointerId, y: event.clientY };
                    });
                    stage.addEventListener('pointermove', (event) => {
                        if (rising?.id !== event.pointerId) {
                            return;
                        }

                        acts.rise(event.clientY - rising.y);
                        rising.y = event.clientY;
                        wake();
                    });

                    for (const type of ['pointerup', 'pointercancel']) {
                        stage.addEventListener(type, (event) => {
                            if (rising?.id === event.pointerId) {
                                rising = null;
                            }
                        });
                    }

                    // A click on the doorway rides (the header's rule): a primary press that comes up where it went down.
                    let press = null;

                    stage.addEventListener('pointerdown', (event) => {
                        press = event.button === 0 ? { id: event.pointerId, x: event.clientX, y: event.clientY } : null;
                    });
                    stage.addEventListener('pointerup', (event) => {
                        const was = press;

                        press = null;

                        if (was?.id === event.pointerId && hooks !== null && Math.hypot(event.clientX - was.x, event.clientY - was.y) < DRAG_SLOP_PX
                            && atDoorway(event.clientX, event.clientY)) {
                            hooks.ride();
                        }
                    });

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
                focused: (point, what) => {
                    // Keyboard focus on a desk's name or the elevator: the target to it, the eye moved by the same vector
                    // — the angles and the distance left as they are (plan § 3.6).
                    const to = new Vector3(point.x, 0, point.z);

                    camera.position.add(to.clone().sub(controls.target));
                    controls.target.copy(to);
                    controls.update();
                    clamp();
                    focusLine.textContent = `looking at ${what}`;
                    wake();
                },
                ride: hooks.ride,
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

                    const at = window.performance.now();

                    paints++;
                    paintAt = at;
                    // A theme module that failed to load (§ 9 F14) leaves no palette: the walls in the house trim, as the
                    // 2-D floor draws its walls in a flat fallback fill.
                    room.paint(world, sceneIn, palette?.view.partition ?? (cssColour('--house-trim') || '#1f3540'), at);
                    standFurniture();
                    bodies.paint(world, at);
                    transients.paint(world, at);
                    labels.sync(world);
                    labels.riding((sceneIn.ride ?? null) !== null);
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

                /** The ride's cab drawing (§ 4.5): the 2-D floor's, one cab for every page. */
                paintCab,

                /** The page's cab dialog in the house theme's elevator palette, as the 2-D floor's (card#12039). */
                cabTheme: (dialog) => cabTheme(dialog, cabPalette()),

                /**
                 * What the browser leg reads (`tools/design/floor3d.browser.mjs`): the camera, the frames drawn, the
                 * walls' heights, the renderer's counters, and § 6's figures term by term after a render of each alone —
                 * or, with `{ terms: false }`, everything but those renders, a read cheap enough to poll. It carries no
                 * fact and moves nothing.
                 */
                inspect({ terms: byTerm = true } = {}) {
                    const terms = {};

                    if (byTerm) {
                        for (const [term, layer] of Object.entries(TERMS)) {
                            camera.layers.set(layer);
                            renderer.render(scene, camera);
                            terms[term] = { calls: renderer.info.render.calls, triangles: renderer.info.render.triangles };
                        }

                        camera.layers.enableAll();
                        renderer.render(scene, camera);
                    }

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
                    // where the doorway's centre is drawn on the stage, px — what the browser leg clicks
                    const door = world?.band?.elevator ?? null;
                    const seen = door === null ? null : new Vector3(door.x + door.w / 2, door.y + door.h / 2, door.z).project(camera);
                    // the cab's room on the stage, px: its opening's corners and all eight of its box's —
                    // what the cab row's clip check reads
                    const r = world?.band?.cab?.room ?? null;
                    const onStage = (u, v, w) => {
                        const q = new Vector3(r.x + u * r.w, r.y + v * r.h, r.z + w * r.d).project(camera);

                        return { x: ((q.x + 1) / 2) * surface.width, y: ((1 - q.y) / 2) * surface.height };
                    };
                    const corners = [[0, 0], [1, 0], [0, 1], [1, 1]];
                    const cabSeen = r === null ? null : { opening: corners.map(([u, v]) => onStage(u, v, 1)), box: [0, 1].flatMap((w) => corners.map(([u, v]) => onStage(u, v, w))) };

                    return {
                        frames,
                        paints,
                        paintedAt: paintAt,
                        clock: held.clock,
                        marks: [...markLayer.children].map((e) => ({ key: e.dataset.key ?? null, kind: e.dataset.kind ?? 'thought', levels: [...e.children].map((c) => Number(c.dataset.level ?? -1)), text: e.textContent, shown: e.style.display !== 'none' })),
                        polar: controls.getPolarAngle(),
                        azimuth: controls.getAzimuthalAngle(),
                        distance: controls.getDistance(),
                        limits: view === null ? null : { polar: view.polar, distance: view.distance, near: view.near, far: view.far, bound: view.bound },
                        target: controls.target.toArray(),
                        eye: camera.position.toArray(),
                        doorway: seen === null ? null : { x: ((seen.x + 1) / 2) * surface.width, y: ((1 - seen.y) / 2) * surface.height },
                        cab: cabSeen,
                        walls: heights.walls,
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
                        room: room.inspect(),
                        transient: transients.inspect(),
                        requested: furniture?.requested() ?? [],
                        labels: labels.count(),
                    };
                },
            };
        },
    };
}
