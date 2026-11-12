/**
 * THE 3-D FLOOR's BODIES — every character the floor world (`floor/floor-world.js`) draws a body for: each sitter, each
 * intern, each walker, built by `floor3d/creature-paint.js`, with a contact disc under each still one, and the clock that
 * chooses each body's pose at the instant a frame is drawn. One of the renderer's parts, composed by
 * `floor3d/floor-paint.js`, which owns the frame; `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.3 and 3.5.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CLOCK (plan § 3.5): a held loop steps from the PAINT — `T = (now − paintedAt) / 1000`, its frame `⌊T × fps⌋` plus
 * the character's own loop offset (`loopOffset(key, length)`, keyed by its seat or intern key) — and a walker's time is
 * its effect's `elapsed_ms` plus the time since the paint. The time is set, never accumulated. The rows this slice
 * steps are the held loops whose clip `creatures-3d.js`'s `CLIPS` names (A3's `work`, A4's `think`, A23's
 * `intern-type`) and the walk; every other row is drawn at its still pose until the plan's slice 5 gives it its clip.
 *
 * ⛔ A WALKER IS ITS BODY AWAY FROM ITS DESK: drawn under the walk, never twice; a body is built once per key and species,
 * and a body the world no longer draws is disposed.
 */

import { CircleGeometry, InstancedMesh, Matrix4, MeshBasicMaterial, Vector3 } from '../vendor/three/three.module.js';
import { createCreature } from './creature-paint.js';
import { onTerm } from './terms.js';
import { walkerAt } from '../floor/floor-world.js';

/** The held loops this slice steps, by animation id: the clip of `CLIPS` each draws (plan § 3.5's table). */
const HELD_CLIPS = Object.freeze({ A3: 'work', A4: 'think', A23: 'intern-type' });

/** The contact disc under each character: its radius as a share of the character's height. */
const DISC = 0.22;

/**
 * The bodies.
 *
 * @param {object} ctx
 * @param {Document} ctx.document
 * @param {object} ctx.creatures the group the bodies stand in
 * @param {object} ctx.room the group the contact discs stand in (the `fixed` term)
 * @param {function(): void} ctx.wake the frame loop's wake: a body built is drawn
 * @param {function(): object} ctx.art the art modules, once the page has them
 * @param {function(string[]): void} ctx.failed § 9 F14's report of an asset that could not be drawn
 * @param {function(): number} ctx.now the clock a body built between frames is placed at (`performance.now()`)
 */
export function floorBodies({ document, creatures, room, wake, art, failed, now: clock }) {
    let world = null;
    let paintedAt = 0;
    /** key → `{ creature | null, spec, dead }` */
    const bodies = new Map();
    const discGeometry = new CircleGeometry(1, 20).rotateX(-Math.PI / 2);
    const discMaterial = new MeshBasicMaterial({ color: '#000000', transparent: true, opacity: 0.18, depthWrite: false });
    let discs = null;

    // A walker away from its desk stands as tall as the floor's seated bodies of its kind (the scene draws all alike).
    const seatHeight = () => world.desks.find((d) => d.sitter !== null)?.sitter.h ?? 1.2;
    const internHeight = () => world.desks.flatMap((d) => d.interns)[0]?.h ?? 0.56;

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
                createCreature(document, art().characters, key, { species: spec.species ?? undefined, chibi: spec.chibi, pose: 'stand', height: spec.h })
                    .then((c) => {
                        if (b.dead) {
                            c.dispose();

                            return;
                        }

                        b.creature = c;
                        onTerm(c.mesh, 'characters');
                        creatures.add(c.mesh);
                        place(b, clock());
                        wake();
                    }, () => {
                        // § 9 F14's asset ids, the 2-D painter's: an intern's sprite, or a seat's character.
                        failed([spec.chibi ? `intern:${key}` : `character:${key}`]);
                    });
            }

            bodies.get(key).spec = spec;
        }

        standDiscs(wanted);
    };

    /**
     * One body at `now` (plan § 3.5's clock): a walker at its route's point, turned along its leg and stepping its walk; a
     * seated body at its place, its held loop's pose from the paint. Answers when it next needs a frame: `0` every display
     * frame (a walker in flight), a delay in ms (a held loop's next step), or `null` (still).
     */
    const place = (b, now) => {
        const c = b.creature;
        const s = b.spec;
        const characters = art().characters;

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
            const walk = characters.CLIPS.walk;

            c.pose(moving ? walk[Math.floor(t / fx.frame_interval_ms) % walk.length] : 'stand');

            return moving ? 0 : null;
        }

        c.mesh.visible = true;
        c.mesh.position.set(s.at.x, 0, s.at.z);
        c.mesh.rotation.y = 0;

        const clipName = s.held?.motion === true ? HELD_CLIPS[s.held.animation_id] : undefined;
        const base = characters.POSES[s.pose] !== undefined ? s.pose : 'stand';

        if (clipName === undefined || typeof s.held.frame_interval_ms !== 'number') {
            c.pose(base);

            return null;
        }

        const clip = characters.CLIPS[clipName];
        const ms = s.held.frame_interval_ms;
        const T = now - paintedAt;
        const step = Math.floor(T / ms) + characters.loopOffset(s.key, clip.length);

        c.pose(clip[step % clip.length]);

        return clip.length > 1 ? ms - (T % ms) : null;
    };

    const built = () => [...bodies.values()].filter((b) => b.creature !== null);

    return {
        /** The bodies for a world painted at `at` (ms, `performance.now()`) — the instant every held loop steps from. */
        paint(worldIn, at) {
            world = worldIn;
            paintedAt = at;
            standBodies();
        },

        /** Every body at `now`; when the next frame is needed — `0`, a delay in ms, or `null` (every body still). */
        place(now) {
            let next = null;

            for (const b of bodies.values()) {
                const n = place(b, now);

                next = n === null ? next : next === null ? n : Math.min(next, n);
            }

            return next;
        },

        /** The built bodies' figures — what the browser leg reads: the counts, each creature's textures, its face on the stage. */
        inspect(camera, surface) {
            const textures = [];

            for (const b of built()) {
                textures.push(...b.creature.textures().map((t) => ({ ...t, float: false })));
                if (b.creature.mesh.skeleton.boneTexture !== null) {
                    const image = b.creature.mesh.skeleton.boneTexture.image;

                    textures.push({ width: image.width, height: image.height, float: true });
                }
            }

            return {
                textures,
                meshes: new Set(built().map((b) => b.creature.mesh)),
                bodies: built().length,
                seats: built().filter((b) => !b.spec.chibi).length,
                interns: built().filter((b) => b.spec.chibi).length,
                // where each built body's face patch is on the stage, CSS px — the face leg's pixel read
                faces: built().filter((b) => b.creature.mesh.visible).map((b) => {
                    const p = b.creature.mesh.localToWorld(new Vector3(...b.creature.parts.face.at)).project(camera);

                    return { key: b.spec.key, x: ((p.x + 1) / 2) * surface.width, y: ((1 - p.y) / 2) * surface.height, h: b.spec.h };
                }),
            };
        },
    };
}
