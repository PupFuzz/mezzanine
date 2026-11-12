/**
 * THE 3-D FLOOR's ROOM — the floor's planes, its walls, the back wall (the band) and the desks' screens, drawn with
 * Three.js from the floor world (`floor/floor-world.js`). One of the renderer's parts, composed by `floor3d/floor-paint.js`,
 * which owns the frame; `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.4 and 3.7.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE FLOOR: the plane is the theme's `plane()` document and the band the theme's band
 * documents, rasterised as the painter draws them; the clock's hands, the windows' sky and the elevator's leaves are
 * drawn here from the scene, as the painter draws them.
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
import { onTerm } from './terms.js';
import { SKY_PAINT } from '../floor/floor-layout.js';
import { textureSize } from '../building/stack-model.js';

/** A texture over a canvas, in the sheet's colour space. */
function textureOf(canvas) {
    const texture = new CanvasTexture(canvas);

    texture.colorSpace = SRGBColorSpace;

    return texture;
}

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

    return {
        /**
         * The room for a world: the walls in the theme's partition colour (`colour`), stood again when the map moves one;
         * the planes and the band, rasterised again when they change; the screens.
         */
        paint(worldIn, sceneIn, colour) {
            world = worldIn;
            wallMaterial.color.set(colour);

            const wallKey = world.walls.map((w) => w.id).join(' ');

            if (wallKey !== stoodWalls) {
                stoodWalls = wallKey;
                standWalls();
            }

            standRoom(sceneIn);
            standScreens();
        },

        /** One frame's cut-away at `now` for the eye and the target; true while a wall or the band is still moving. */
        cutaway: applyCutaway,

        /** The loop has stopped: the next frame's tween starts from rest. */
        rested() {
            lastStep = null;
        },

        /** The walls' heights now, by id, and the band's — what the browser leg reads. */
        heights: () => ({ walls: [...walls].map(([id, w]) => [id, w.h]), band: band === null ? null : bandH * world.band.h }),
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
