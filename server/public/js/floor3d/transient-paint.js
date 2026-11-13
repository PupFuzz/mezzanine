/**
 * THE 3-D FLOOR's TRANSIENT DRAWINGS — the coordination rows' birds and ring (`docs/design/FLOOR.md` § 6.2 A19, A20,
 * A22; `docs/design/plan-13033-stage2-3d-floor.md` § 3.5's A19/A22/A20 row), drawn from the floor world's `effects`
 * (`floor/floor-world.js`) for as long as each is in flight, and gone after. One of the renderer's parts, composed by
 * `floor3d/floor-paint.js`, which owns the frame; every mesh here is on § 6's `transient` term.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A BIRD IS TWO DRAW CALLS (§ 6's transient term): its body, wings, tail and beak ONE merged geometry, built once for
 * each wing position (`birdShapes()`'s two: up and down), so a flap swaps the geometry and never adds a mesh — and its
 * envelope a box under it. Where it is and which wing it shows is `birdAt()`'s, the world's, at the effect's time
 * (`elapsed_ms` plus the time since the paint): the flight is continuous along its arc, as on the 2-D floor, and the
 * wing alternates a loop frame at a time.
 *
 * ⛔ A20's RING IS ONE FLAT ANNULUS on the floor round the origin desk, reaching the floor's far corner over its reach
 * frames a step a frame and then fading over its fade frames — once, never repeating — with the singing bird perched on
 * the desk while it runs. Under reduced motion it is that bird alone, still, while the scene draws the row's still form.
 *
 * ⛔ IT DECIDES NOTHING: what flies, from where to where and for how long is the scene's; the colours are `bird.js`'s
 * `PALETTE`, the 2-D bird's.
 */

import {
    BoxGeometry,
    Color,
    ConeGeometry,
    Float32BufferAttribute,
    Mesh,
    MeshBasicMaterial,
    MeshToonMaterial,
    RingGeometry,
    SphereGeometry,
} from '../vendor/three/three.module.js';
import { mergeGeometries } from '../vendor/three/examples/jsm/utils/BufferGeometryUtils.js';
import { onTerm } from './terms.js';
import { UNIT, birdAt } from '../floor/floor-world.js';
import { PALETTE } from '../floor/bird.js';

/** The bird's body, half-width and half-height, units: `birdShapes()`'s 9 × 7.2 px ellipse. */
const BODY = Object.freeze({ rx: 9 / UNIT, ry: 7.2 / UNIT });
/** The envelope: `envelopeShapes()`'s 14 × 10 px, a few px thick. */
const ENVELOPE = Object.freeze({ w: 14 / UNIT, h: 10 / UNIT, d: 3 / UNIT });

/** One part of the bird, coloured on its vertices. */
function coloured(geometry, colour) {
    const g = geometry.toNonIndexed();
    const c = new Color(colour);
    const n = g.getAttribute('position').count;
    const colours = new Float32Array(n * 3);

    for (let i = 0; i < n; i++) {
        colours.set([c.r, c.g, c.b], i * 3);
    }

    for (const name of Object.keys(g.attributes)) {
        if (name !== 'position' && name !== 'normal') {
            g.deleteAttribute(name);
        }
    }

    g.setAttribute('color', new Float32BufferAttribute(colours, 3));

    return g;
}

/** The bird facing +z with a wing `up` or `down`: body, belly, two wings, tail and beak merged into one geometry. */
function birdGeometry(wing) {
    const { rx, ry } = BODY;
    const body = new SphereGeometry(1, 14, 10).scale(rx * 0.8, ry, rx);
    const belly = new SphereGeometry(1, 10, 8).scale(rx * 0.5, ry * 0.55, rx * 0.4).translate(0, -ry * 0.25, rx * 0.62);
    const lift = wing === 'up' ? 0.9 : -0.35;
    const wingOf = (side) => new SphereGeometry(1, 8, 6).scale(rx * 0.75, ry * 0.18, rx * 0.5)
        .rotateZ(side * lift).translate(side * rx * 0.85, ry * 0.15 + (wing === 'up' ? ry * 0.45 : 0), -rx * 0.1);
    const tail = new ConeGeometry(ry * 0.45, rx * 0.8, 6).rotateX(-Math.PI / 2).translate(0, ry * 0.1, -rx * 1.2);
    const beak = new ConeGeometry(ry * 0.22, rx * 0.5, 6).rotateX(Math.PI / 2).translate(0, ry * 0.15, rx * 1.15);

    return mergeGeometries([
        coloured(body, PALETTE.bird_body),
        coloured(belly, PALETTE.bird_belly),
        coloured(wingOf(-1), PALETTE.bird_body),
        coloured(wingOf(1), PALETTE.bird_body),
        coloured(tail, PALETTE.bird_body),
        coloured(beak, PALETTE.beak),
    ], false);
}

/**
 * The transient drawings.
 *
 * @param {object} ctx
 * @param {object} ctx.group the group they stand in
 */
export function floorTransients({ group }) {
    const wings = { up: birdGeometry('up'), down: birdGeometry('down') };
    const envelopeGeometry = new BoxGeometry(ENVELOPE.w, ENVELOPE.h, ENVELOPE.d);
    const birdMaterial = new MeshToonMaterial({ vertexColors: true });
    const envelopeMaterial = new MeshToonMaterial({ color: PALETTE.envelope });
    const ringGeometry = new RingGeometry(0.94, 1, 64).rotateX(-Math.PI / 2);
    let world = null;
    let paintedAt = 0;
    /** each drawn effect: `{ entry, bird, envelope, ring, ringMaterial }` */
    let drawn = [];

    const clear = () => {
        for (const d of drawn) {
            for (const mesh of [d.bird, d.envelope, d.ring]) {
                if (mesh !== null) {
                    group.remove(mesh);
                }
            }

            d.ringMaterial?.dispose();
        }

        drawn = [];
    };

    const mesh = (geometry, material) => {
        const m = onTerm(new Mesh(geometry, material), 'transient');

        m.visible = false;
        group.add(m);

        return m;
    };

    /** A bird's body and envelope at `p` (`birdAt()`'s), or hidden at `null`. */
    const placeBird = (d, p) => {
        d.bird.visible = p !== null && p.phase !== 'rest' && p.phase !== 'still';
        d.envelope.visible = p !== null;

        if (p === null) {
            return;
        }

        d.bird.geometry = wings[p.wing];
        d.bird.position.set(p.x, p.y, p.z);
        d.bird.rotation.y = p.heading;
        d.bird.scale.setScalar(p.scale);
        // carried under the bird in the air; set down on the desk at rest, or still there under reduced motion
        d.envelope.position.set(p.x, p.y - (d.bird.visible ? BODY.ry * 1.6 * p.scale : BODY.ry), p.z);
        d.envelope.rotation.y = p.heading;
        d.envelope.scale.setScalar(p.scale);
    };

    /** The ring at frame `f` (or its still form): reaching out a step a frame, then fading; the singing bird on its desk. */
    const placeRing = (d, f) => {
        const fx = d.entry.effect;
        const at = d.entry.at;
        const perch = d.entry.perch;

        d.bird.visible = true;
        d.bird.geometry = wings[fx.frames > 0 && Math.floor(f) % 2 === 0 ? 'up' : 'down'];
        d.bird.position.set(perch.x, perch.y, perch.z);

        if (!(fx.frames > 0) || f >= fx.frames) {
            d.ring.visible = false;

            return;
        }

        const reach = Math.min(1, (Math.floor(f) + 1) / fx.reach_frames);
        const fading = Math.max(0, Math.floor(f) - fx.reach_frames + 1) / fx.fade_frames;

        d.ring.visible = true;
        d.ring.position.set(at.x, 0.006, at.z);
        d.ring.scale.set(d.entry.radius * reach, 1, d.entry.radius * reach);
        d.ringMaterial.opacity = Math.max(0, 1 - fading);
    };

    return {
        /** The world's birds and rings, drawn anew for a world painted at `at`. */
        paint(worldIn, at) {
            world = worldIn;
            paintedAt = at;
            clear();

            for (const entry of world.effects) {
                if (entry.kind === 'bird') {
                    drawn.push({ entry, bird: mesh(wings.down, birdMaterial), envelope: mesh(envelopeGeometry, envelopeMaterial), ring: null, ringMaterial: null });
                } else if (entry.kind === 'ring') {
                    const ringMaterial = new MeshBasicMaterial({ color: PALETTE.notes, transparent: true, depthWrite: false });

                    drawn.push({ entry, bird: mesh(wings.down, birdMaterial), envelope: null, ring: mesh(ringGeometry, ringMaterial), ringMaterial });
                }
            }
        },

        /** What is drawn now, by kind — the population the browser leg's transient term is derived from. */
        inspect: () => ({
            birds: drawn.filter((d) => d.entry.kind === 'bird' && d.bird.visible).length,
            envelopes: drawn.filter((d) => d.entry.kind === 'bird' && d.envelope.visible).length,
            rings: drawn.filter((d) => d.entry.kind === 'ring' && d.ring.visible).length,
            singers: drawn.filter((d) => d.entry.kind === 'ring' && d.bird.visible).length,
        }),

        /** Every drawing at `now`; `0` while one moves, the ms until a still one ends, or `null`. */
        place(now) {
            if (world === null) {
                return null;
            }

            const since = now - paintedAt;
            let next = null;
            const sooner = (n) => {
                next = n === null ? next : next === null ? n : Math.min(next, n);
            };

            for (const d of drawn) {
                const fx = d.entry.effect;
                const t = (fx.elapsed_ms ?? 0) + since;

                if (d.entry.kind === 'bird') {
                    const p = birdAt(d.entry, t);

                    placeBird(d, p);

                    if (fx.frames > 0) {
                        sooner(t < (fx.wait_frames + fx.span_frames) * fx.frame_interval_ms ? 0 : null);
                    } else if (p !== null) {
                        // the still envelope: drawn until the moving form's duration ends, then gone
                        sooner(fx.still_frames * fx.frame_interval_ms - t);
                    }
                } else {
                    const f = t / fx.frame_interval_ms;

                    placeRing(d, f);
                    sooner(fx.frames > 0 && f < fx.frames ? fx.frame_interval_ms - (t % fx.frame_interval_ms) : null);
                }
            }

            return next;
        },
    };
}
