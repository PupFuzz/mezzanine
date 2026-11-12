/**
 * THE 3-D FLOOR's ROOM — the floor's planes, its walls, the back wall (the band) and the desks' screens, drawn with
 * Three.js from the floor world (`floor/floor-world.js`). One of the renderer's parts, composed by `floor3d/floor-paint.js`,
 * which owns the frame; `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.4 and 3.7.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE FLOOR: the plane is the theme's `plane()` document and the band the theme's band
 * documents, rasterised as the painter draws them; the clock's hands and the windows' sky are drawn into the band's
 * picture from the scene, as the painter draws them, so A17's room tick is a cut — the picture drawn again.
 *
 * ⛔ WHAT MOVES IN THE ROOM IS THE SCENE's, STEPPED A FRAME AT A TIME FROM THE PAINT (plan § 3.5's A1, A2, A5, A15 and A26
 * rows): the elevator's two leaves — boxes over the doorway in front of the cab's dark interior, as wide as the walks'
 * leaf timeline says (`floorWorld()`'s `doors`, read by `leafLevel()`), narrowing toward the frame as the 2-D leaves do;
 * each restroom door's leaf over its recess and its slider's field, vacant or occupied, at the scene's rects (§ 6.7 item
 * 2: the leaf and the field are the renderer's, never the theme's); a desk's screen cross-fading from what it showed to
 * what it shows over A5's frames (the old picture a second plane, the transient term's); and A15's replay marker sweeping
 * the screen while its held rendering moves (`clip-clock.js`'s `screen`), still at rest otherwise. Under reduced motion
 * each is drawn at its end: the leaves where the timeline leaves them, the screen with no fade.
 *
 * ⛔ THE CUT-AWAY (plan § 3.7): each frame the segment from the eye to the target is tested against the walls and the
 * band (`cutaway()`); every one it meets is lowered to `CUTAWAY_H` over `CUTAWAY_MS` (a cut under reduced motion), and
 * raised again when clear — each wall on its own, an instance of its height's mesh. A desk and a character are never cut.
 *
 * ⛔ A RASTERISED PICTURE WAKES THE FRAME when it answers (`wake`), so a room drawn before its pictures is drawn again.
 */

import {
    BoxGeometry,
    CanvasTexture,
    Group,
    InstancedMesh,
    Matrix4,
    Mesh,
    MeshBasicMaterial,
    MeshToonMaterial,
    PlaneGeometry,
    SRGBColorSpace,
} from '../vendor/three/three.module.js';
import { CUTAWAY_H, CUTAWAY_MS, cutaway } from './view-limits.js';
import { rasterise } from './svg-texture.js';
import { REPLAY_LOOP, heldAt } from './clip-clock.js';
import { onTerm } from './terms.js';
import { leafLevel } from '../floor/scene.js';
import { SKY_PAINT } from '../floor/floor-layout.js';
import { textureSize } from '../building/stack-model.js';

/** A texture over a canvas, in the sheet's colour space. */
function textureOf(canvas) {
    const texture = new CanvasTexture(canvas);

    texture.colorSpace = SRGBColorSpace;

    return texture;
}

/** How narrow a leaf is fully open, a share of its shut width — the 2-D leaves' `scaleX(.12)` (`cab-paint.js`). */
const LEAF_OPEN = 0.12;
/** How deep the elevator's leaves and the cab's interior stand in front of the back wall's face, units. */
const LEAF_DEPTH = 0.03;

/** The screen's picture, px. */
const SCREEN = Object.freeze({ w: 256, h: 160 });

/**
 * The room.
 *
 * @param {object} ctx
 * @param {Document} ctx.document
 * @param {object} ctx.renderer the WebGL renderer (its texture cap)
 * @param {function(string): string} ctx.cssColour a custom property of the page's root
 * @param {boolean} ctx.reduce reduced motion, read once: the cut-away is a cut
 * @param {function(): void} ctx.wake the frame loop's wake
 * @param {object} ctx.room the group the planes, band and screens stand in (the `fixed` term)
 * @param {object} ctx.walls the group the walls stand in
 * @param {function(): object} ctx.art the art modules, once the page has them
 */
export function floorRoom({ document, renderer, cssColour, reduce, wake, room, walls: wallGroup, art }) {
    let world = null;
    let paintedAt = 0;
    /** the scene's loop rate, frames a second — the rate every frame count this file reads is in */
    let loopFps = 4;

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
    // The walls in the theme's door-view partition colour (`PALETTE.view`, which every theme carries), set on each paint.
    const wallMaterial = new MeshToonMaterial();

    const placeWall = (w) => {
        const m = new Matrix4().makeTranslation(w.box.x, 0, w.box.z).multiply(new Matrix4().makeScale(w.box.w, w.h, w.box.d));

        w.mesh.setMatrixAt(w.index, m);
        w.mesh.instanceMatrix.needsUpdate = true;
    };

    /** Lower what the eye→target segment meets and raise what it no longer does; true while any is still moving. */
    const applyCutaway = (now, eye, target) => {
        if (world === null) {
            return false;
        }

        const cut = cutaway(eye, target, world);
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
        const module = art().themes[doc.theme];

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
            // The doorway's cab and leaves stand on the band, so the cut-away lowers them with it.
            band.add(doorway);
            room.add(band);
            roomParts.push(band);
        }
    };

    // ── the elevator's leaves and the cab behind them ─────────────────────────────────────────
    const doorBox = new BoxGeometry(1, 1, 1).translate(0.5, 0.5, 0.5);
    const leafMaterial = new MeshToonMaterial({ color: cssColour('--door') || '#dbe5ee' });
    const cabMaterial = new MeshBasicMaterial({ color: cssColour('--scene-ink') || '#3b2f2a' });
    const doorway = new Group();
    const cab = onTerm(new Mesh(doorBox, cabMaterial), 'fixed');
    const leaves = [onTerm(new Mesh(doorBox, leafMaterial), 'fixed'), onTerm(new Mesh(doorBox, leafMaterial), 'fixed')];

    doorway.add(cab, ...leaves);

    /** The doorway's boxes at frame `f` of the walks' leaf timeline: the cab's interior, and each leaf as wide as it is shut. */
    const placeDoorway = (f) => {
        const at = (r, z, depth) => [r.x - world.band.x, r.y, z, r.w, r.h, depth];
        const set = (mesh, [x, y, z, w, h, d]) => {
            mesh.position.set(x, y, z);
            mesh.scale.set(w, h, d);
        };
        const shut = 1 - (1 - LEAF_OPEN) * leafLevel(world.doors, f);
        const [left, right] = world.band.leaves;

        // the cab's dark interior fills the doorway the two leaves close
        set(cab, at({ x: left.x, y: left.y, w: right.x + right.w - left.x, h: left.h }, 0.002, LEAF_DEPTH * 0.5));
        // the left leaf narrows toward the frame's left, the right toward its right (`.elevator-leaf`'s transform origins)
        set(leaves[0], at({ ...left, w: left.w * shut }, LEAF_DEPTH * 0.5, LEAF_DEPTH));
        set(leaves[1], at({ ...right, x: right.x + right.w * (1 - shut), w: right.w * shut }, LEAF_DEPTH * 0.5, LEAF_DEPTH));
    };

    // ── the restroom doors ─────────────────────────────────────────────────────────────────────
    const vacant = new MeshBasicMaterial({ color: cssColour('--restroom-vacant') || '#5f9a62' });
    const occupied = new MeshBasicMaterial({ color: cssColour('--restroom-occupied') || '#c8553d' });
    /** door id → `{ leaf, field, door }`, `door` the world's */
    let restroom = new Map();

    const standRestroom = () => {
        for (const d of restroom.values()) {
            room.remove(d.leaf, d.field);
        }

        restroom = new Map(world.restroom_doors.map((d) => {
            const leaf = onTerm(new Mesh(doorBox, leafMaterial), 'fixed');
            const field = onTerm(new Mesh(doorBox, vacant), 'fixed');

            room.add(leaf, field);

            return [d.id, { leaf, field, door: d }];
        }));
    };

    /** Each restroom door at frame `f` of its timeline: its leaf as wide as it is shut, from its recess's left, and its field. */
    const placeRestroom = (f) => {
        for (const { leaf, field, door } of restroom.values()) {
            const r = door.recess;
            const shut = 1 - (1 - LEAF_OPEN) * leafLevel(door.leaf, f);
            const inside = door.occupied.some((o) => f >= o.start && f < o.start + o.frames);

            leaf.position.set(r.x, r.y, door.at.z + 0.006);
            leaf.scale.set(r.w * shut, r.h, LEAF_DEPTH);
            // in front of the theme's sign plate, which the field is drawn on
            field.position.set(door.sign.x, door.sign.y, door.at.z + 0.034);
            field.scale.set(door.sign.w, door.sign.h, 0.01);
            field.material = inside ? occupied : vacant;
        }
    };

    /** The last frame of every move a timeline holds, or 0 — after it nothing it holds moves again. */
    const endOf = (leaf) => Math.max(0, ...leaf.map((m) => m.start + m.frames));

    // ── the screens ────────────────────────────────────────────────────────────────────────────
    /** desk key → `{ mesh, canvas, texture, sig, fade }`, `fade` A5's old picture while it cross-fades */
    const screens = new Map();
    const screenCanvas = () => {
        const picture = document.createElement('canvas');

        picture.width = SCREEN.w;
        picture.height = SCREEN.h;

        return picture;
    };

    /** A desk's screen picture: its glyph line and action, lit or off, and A15's replay marker at `replay` (a loop frame,
     *  `null` its still), or none. */
    const drawScreen = (g, desk, replay) => {
        const on = desk.screen.lit !== 'off';

        g.fillStyle = cssColour('--scene-monitor') || '#2d3342';
        g.fillRect(0, 0, SCREEN.w, SCREEN.h);

        if (on && typeof desk.screen.text === 'string') {
            g.fillStyle = cssColour('--scene-monitor-on') || '#7fd3c4';
            g.font = '600 22px system-ui, sans-serif';
            g.textBaseline = 'top';
            wrapText(g, desk.screen.text, 14, 16, 228, 26, replay === undefined ? 5 : 4);
        }

        if (replay !== undefined) {
            // the replay marker: a bar along the screen's foot, its lit block swept across it a loop frame at a time, and
            // at rest at the start of the bar
            const track = { x: 14, y: SCREEN.h - 26, w: SCREEN.w - 28, h: 10 };
            const block = track.w / 4;
            const k = replay === null ? 0 : replay / (REPLAY_LOOP - 1);

            g.fillStyle = cssColour('--scene-monitor-on') || '#7fd3c4';
            g.globalAlpha = 0.35;
            g.fillRect(track.x, track.y, track.w, track.h);
            g.globalAlpha = 1;
            g.fillRect(track.x + (track.w - block) * k, track.y, block, track.h);
        }
    };

    const removeFade = (s) => {
        if (s.fade !== null) {
            room.remove(s.fade.mesh);
            s.fade.mesh.geometry.dispose();
            s.fade.texture.dispose();
            s.fade.mesh.material.dispose();
            s.fade = null;
        }
    };

    const standScreens = () => {
        const wanted = new Set(world.desks.filter((d) => d.screen !== null && d.parts.monitor !== null).map((d) => d.key));
        const swaps = new Map(world.effects.filter((e) => e.kind === 'desk' && e.effect.animation_id === 'A5' && e.effect.frames > 0).map((e) => [e.key, e.effect]));

        for (const [key, s] of screens) {
            if (!wanted.has(key)) {
                removeFade(s);
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

            if (!screens.has(desk.key)) {
                const picture = screenCanvas();
                const texture = textureOf(picture);
                const mesh = onTerm(new Mesh(new PlaneGeometry(1, 1), new MeshBasicMaterial({ map: texture })), 'fixed');

                // The screen toward the sitter (the operator's Q4 answer): its face is the plane's back, turned to −z.
                mesh.rotation.y = Math.PI;
                room.add(mesh);
                screens.set(desk.key, { mesh, canvas: picture, texture, sig: null, fade: null, desk });
            }

            const s = screens.get(desk.key);
            const swap = swaps.get(desk.key);

            s.desk = desk;
            s.mesh.scale.set(m.w * 0.82, m.h * 0.38, 1);
            s.mesh.position.set(m.at.x, desk.slab.top + m.h * 0.34, m.at.z - 0.06);
            removeFade(s);

            if (swap !== undefined && s.sig !== null) {
                // A5: what the screen showed, kept on a second plane just in front of it, fading out over the row's frames
                const old = screenCanvas();

                old.getContext('2d').drawImage(s.canvas, 0, 0);

                const texture = textureOf(old);
                const mesh = onTerm(new Mesh(new PlaneGeometry(1, 1), new MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false })), 'transient');

                mesh.rotation.y = Math.PI;
                mesh.scale.copy(s.mesh.scale);
                mesh.position.copy(s.mesh.position).add({ x: 0, y: 0, z: -0.002 });
                room.add(mesh);
                s.fade = { mesh, texture, effect: swap };
            }

            s.sig = null;
        }
    };

    /** Every screen's picture at `since` (its replay marker's step), and A5's fade; when a screen next needs a frame. */
    const placeScreens = (since) => {
        const characters = art().characters;
        let next = null;
        const sooner = (n) => {
            next = n === null ? next : next === null ? n : Math.min(next, n);
        };

        for (const s of screens.values()) {
            const held = s.desk.sitter?.animation ?? null;
            const state = held?.animation_id === 'A15' ? heldAt(characters, { key: s.desk.key, pose: 'present', held }, since) : null;
            const replay = state === null ? undefined : state.screen.frame;
            const sig = JSON.stringify([s.desk.screen.lit, s.desk.screen.text, replay ?? 'none']);

            if (sig !== s.sig) {
                s.sig = sig;
                drawScreen(s.canvas.getContext('2d'), s.desk, replay);
                s.texture.needsUpdate = true;
            }

            sooner(state?.next ?? null);

            if (s.fade !== null) {
                const fx = s.fade.effect;
                const span = fx.frames * fx.frame_interval_ms;
                const t = (fx.elapsed_ms ?? 0) + since;

                if (t >= span) {
                    removeFade(s);
                } else {
                    s.fade.mesh.material.opacity = 1 - t / span;
                    sooner(0);
                }
            }
        }

        return next;
    };

    return {
        /**
         * The room for a world: the walls in the theme's partition colour (`colour`), stood again when the map moves one;
         * the planes and the band, rasterised again when they change; the screens.
         */
        paint(worldIn, sceneIn, colour, at) {
            world = worldIn;
            paintedAt = at;
            loopFps = sceneIn.loop_fps;
            wallMaterial.color.set(colour);

            const wallKey = world.walls.map((w) => w.id).join(' ');

            if (wallKey !== stoodWalls) {
                stoodWalls = wallKey;
                standWalls();
            }

            standRoom(sceneIn);
            standRestroom();
            standScreens();
        },

        /**
         * What moves in the room at `now`: the leaves, the restroom doors, the screens. Answers when it next needs a
         * frame — `0`, a delay in ms, or `null` (nothing moves).
         */
        place(now) {
            if (world === null) {
                return null;
            }

            const since = now - paintedAt;
            const ms = 1000 / loopFps;
            const f = since / ms;
            const step = ms - (since % ms);
            const moves = [world.band === null ? 0 : endOf(world.doors), ...world.restroom_doors.map((d) => endOf(d.leaf))];
            const waits = [placeScreens(since)];

            if (world.band !== null) {
                placeDoorway(f);
            }

            placeRestroom(f);

            // the leaves step a frame at a time until their last move has ended; a field turning occupied or vacant is
            // within the same timeline's span
            const last = Math.max(...moves, ...world.restroom_doors.flatMap((d) => d.occupied.map((o) => o.start + o.frames)));

            waits.push(f < last ? step : null);

            const real = waits.filter((n) => n !== null);

            return real.length === 0 ? null : Math.min(...real);
        },

        /** One frame's cut-away at `now` for the eye and the target; true while a wall or the band is still moving. */
        cutaway: applyCutaway,

        /** The loop has stopped: the next frame's tween starts from rest. */
        rested() {
            lastStep = null;
        },

        /** The walls' heights now, by id, and the band's — what the browser leg reads. */
        heights: () => ({ walls: [...walls].map(([id, w]) => [id, w.h]), band: band === null ? null : bandH * world.band.h }),

        /**
         * What the browser leg reads of the room's moving parts and its at-rest members: each leaf's drawn width against
         * its shut one, each restroom door's, the screens fading, and the members § 6's fixed term is counted from.
         */
        inspect: () => ({
            leaves: world === null || world.band === null ? null : leaves.map((l, k) => [l.scale.x, world.band.leaves[k].w]),
            restroom: [...restroom.values()].map(({ leaf, field, door }) => ({ id: door.id, leaf: [leaf.scale.x, door.recess.w], occupied: field.material === occupied })),
            fading: [...screens.values()].filter((x) => x.fade !== null).length,
            members: { planes: world === null ? 0 : world.planes.length, band: band === null ? 0 : 1, screens: screens.size, restroom: restroom.size },
        }),
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
