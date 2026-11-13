/**
 * THE 3-D FLOOR's BODIES — every character the floor world (`floor/floor-world.js`) draws a body for: each sitter, each
 * intern, each walker, built by `floor3d/creature-paint.js`, with a contact disc under each still one, and the clock that
 * chooses each body's pose at the instant a frame is drawn. One of the renderer's parts, composed by
 * `floor3d/floor-paint.js`, which owns the frame; `docs/design/plan-13033-stage2-3d-floor.md` §§ 3.3 and 3.5.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CLOCK (plan § 3.5) IS `floor3d/clip-clock.js`'s, ASKED WHEN THE FRAME RUNS: a seated body's form `since` the paint
 * that drew it (its held loop's clip, its blink, its marks), a walker's time its effect's `elapsed_ms` plus the time since
 * that paint. The time is set on every frame, never accumulated, and a body whose held rendering does not move is its
 * still — no time is set for it at all.
 *
 * ⛔ THE MARKS OVER A BODY ARE LABELS (plan § 3.5's A6, A7, A9 rows; § 3.9): the sleeper's three z's and a state marker,
 * each lit as the 2-D pulse lights it (`data-level` 0–3, the character tree's `zLevels()` and `markerLevel()`), and a
 * walker's thought bubble (A25's food thought, A26's restroom line), positioned by the marks' own `CSS2DRenderer` over
 * the canvas — DOM, so they cost no draw call — in the marks layer, apart from the desks' reading-ordered labels.
 *
 * ⛔ AN INTERN's LAPTOP IS ITS MATERIAL's OPACITY (A24, A10): shown or not as its stool's activity says, faded over the
 * row's frames a step a frame — from the opacity the scene says it starts at — and, on a walk, faded over the walk's
 * `seat` leg (in as it sits, out before it stands) and not carried while it walks.
 *
 * ⛔ A WALKER IS ITS BODY AWAY FROM ITS DESK: drawn under the walk, never twice; a body is built once per key and species,
 * and a body the world no longer draws is disposed.
 */

import { CircleGeometry, InstancedMesh, Matrix4, MeshBasicMaterial, Vector3 } from '../vendor/three/three.module.js';
import { CSS2DObject } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { createCreature } from './creature-paint.js';
import { heldAt, inFlightAt, walkAt } from './clip-clock.js';
import { onTerm } from './terms.js';
import { walkerAt } from '../floor/floor-world.js';

/** Where a body's marks float: this share of its height above its feet (over the head, clear of a raised hand). */
const MARK_RISE = 1.08;
/** Where a walker's thought bubble floats: this share of its height above its feet. */
const THOUGHT_RISE = 1.45;

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
 * @param {object} ctx.marks the group the marks' label objects stand in (the marks layer's scene)
 */
export function floorBodies({ document, creatures, room, wake, art, failed, now: clock, marks }) {
    let world = null;
    let paintedAt = 0;
    /** key → `{ creature | null, spec, dead, mark, thought, read }` — `read` the clock's last answer, for `inspect()` */
    const bodies = new Map();
    /** intern key → its A24 fade in flight this paint (the world's desk rows) */
    let fades = new Map();
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
                out.set(n.key, { key: n.key, species: n.seat_species, chibi: true, h: n.h, at: n.at, pose: 'present', held: n.animation ?? null, walker: null, laptop: n.laptop });
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
            discs.setMatrixAt(i, new Matrix4().makeTranslation(s.at.x, s.at.y + 0.003, s.at.z).multiply(new Matrix4().makeScale(s.h * DISC, 1, s.h * DISC)));
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
                unmark(b);
                bodies.delete(key);
            }
        }

        for (const [key, spec] of wanted) {
            if (!bodies.has(key)) {
                const b = { creature: null, spec, dead: false, mark: null, thought: null, read: null };

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

    /** A label over the scene in the marks layer: an element and its object. */
    const markOf = (className, hidden) => {
        const element = document.createElement('div');

        element.className = className;
        if (hidden) {
            element.setAttribute('aria-hidden', 'true');
        }

        const object = new CSS2DObject(element);

        marks.add(object);

        return { element, object, sig: null };
    };

    const drop = (m) => {
        if (m !== null) {
            marks.remove(m.object);
            m.element.remove();
        }

        return null;
    };

    const unmark = (b) => {
        b.mark = drop(b.mark);
        b.thought = drop(b.thought);
    };

    /** The body's marks at the clock's answer: the z's or the marker over its head, lit as the pulse says, or none. */
    const markBody = (b, state) => {
        const m = state?.marks ?? null;

        if (m === null) {
            b.mark = drop(b.mark);

            return;
        }

        b.mark ??= markOf('floor3d-mark', true);
        b.mark.element.dataset.key = b.spec.key;

        const glyphs = m.kind === 'zs' ? ['z', 'z', 'Z'] : [m.kind];
        const sig = JSON.stringify([m.kind, m.levels]);

        if (sig !== b.mark.sig) {
            b.mark.sig = sig;
            b.mark.element.dataset.kind = m.kind === 'zs' ? 'zs' : 'marker';
            b.mark.element.replaceChildren(...glyphs.map((g, k) => {
                const glyph = document.createElement('span');

                glyph.className = 'floor3d-glyph';
                glyph.textContent = g;
                glyph.dataset.level = String(m.levels[k]);

                return glyph;
            }));
        }

        b.mark.object.position.set(b.spec.at.x, b.spec.at.y + b.spec.h * MARK_RISE, b.spec.at.z);
    };

    /** A walker's thought bubble (A25, A26: the scene's `thought` on its effect), over its head wherever it is. */
    const thinkBody = (b, p) => {
        const lines = b.spec.walker?.effect.thought?.lines ?? null;

        if (lines === null || p === null) {
            b.thought = drop(b.thought);

            return;
        }

        b.thought ??= markOf('floor3d-label floor3d-bubble floor3d-thought', false);

        const sig = JSON.stringify(lines.map((l) => l.text));

        if (sig !== b.thought.sig) {
            b.thought.sig = sig;
            b.thought.element.replaceChildren(...lines.map((l) => {
                const span = document.createElement('span');

                span.textContent = l.text;

                return span;
            }));
        }

        b.thought.object.position.set(p.x, p.y + b.spec.h * THOUGHT_RISE, p.z);
        b.thought.object.visible = b.creature?.mesh.visible ?? false;
    };

    /**
     * An intern's laptop `since` the paint: an A24 fade in flight from its start opacity to its end, a step a frame, else
     * the stool's activity (`laptop`) — with the ms to its next step while it fades (`null` once it has ended).
     */
    const laptopAt = (s, since) => {
        const fx = fades.get(s.key);

        if (fx === undefined) {
            return { opacity: s.laptop ? 1 : 0, next: null };
        }

        const t = (fx.elapsed_ms ?? 0) + since;
        const f = Math.floor(t / fx.frame_interval_ms);

        if (!(fx.frames > 0) || f >= fx.frames) {
            return { opacity: fx.to, next: null };
        }

        return { opacity: fx.from + (fx.to - fx.from) * (f / fx.frames), next: fx.frame_interval_ms - (t % fx.frame_interval_ms) };
    };

    /** A walking intern's laptop at `t` ms into its walk: faded over the walk's `seat` leg, and not carried off it. */
    const walkerLaptop = (fx, t) => {
        const seat = fx.legs.find((l) => l.kind === 'seat');

        if (seat === undefined) {
            return 0;
        }

        const f = t / fx.frame_interval_ms;
        const arriving = fx.leg_kind === 'arrive';
        const [from, to] = arriving ? [0, 1] : [1, 0];

        if (f < seat.start) {
            return from;
        }

        return f >= seat.start + seat.frames ? to : from + (to - from) * (Math.floor(f - seat.start) / seat.frames);
    };

    /**
     * One body at `now` (plan § 3.5's clock): a walker at its route's point, turned along its leg and stepping its walk; a
     * seated body at its place in its held form `since` the paint. Answers when it next needs a frame: `0` every display
     * frame (a walker in flight), a delay in ms (a held loop's or a laptop fade's next step), or `null` (still).
     */
    const place = (b, now) => {
        const c = b.creature;
        const s = b.spec;
        const characters = art().characters;
        const since = now - paintedAt;

        if (c === null) {
            return null;
        }

        if (s.walker !== null) {
            const fx = s.walker.effect;
            const span = Math.max(...fx.legs.map((l) => l.start + l.frames)) * fx.frame_interval_ms;
            const { t, running } = inFlightAt(fx, span, since);
            const p = walkerAt(world.slab, s.walker, Math.min(t, span));
            const q = walkerAt(world.slab, s.walker, Math.max(0, Math.min(t, span) - 60));
            const pose = walkAt(characters, fx, t, running);

            c.mesh.visible = p !== null && p.where === 'floor';

            if (p !== null) {
                // An intern on its seat's step stands at the height the world lifts it to and faces the front (`walkerAt()`).
                c.mesh.position.set(p.x, p.y, p.z);

                if (p.step) {
                    c.mesh.rotation.y = 0;
                } else if (q !== null && (p.x !== q.x || p.z !== q.z)) {
                    c.mesh.rotation.y = Math.atan2(p.x - q.x, p.z - q.z);
                }
            }

            c.pose(pose);
            c.blink(false);

            if (s.chibi) {
                c.fade(walkerLaptop(fx, Math.min(t, span)));
            }

            b.mark = drop(b.mark);
            thinkBody(b, p);
            b.read = { key: s.key, kind: 'walker', animation_id: fx.animation_id, t, pose, frame: running ? Math.floor(t / fx.frame_interval_ms) : null, now };

            return running ? 0 : null;
        }

        const state = heldAt(characters, { key: s.key, pose: s.pose, held: s.held }, since);
        const laptop = s.chibi ? laptopAt(s, since) : null;

        c.mesh.visible = true;
        c.mesh.position.set(s.at.x, s.at.y, s.at.z);
        c.mesh.rotation.y = 0;
        c.pose(state.pose);
        c.blink(state.blink);

        if (laptop !== null) {
            c.fade(laptop.opacity);
        }

        markBody(b, state);
        b.thought = drop(b.thought);
        b.read = { key: s.key, kind: 'held', animation_id: s.held?.animation_id ?? null, motion: s.held?.motion === true, t: state.frame === null ? null : since, pose: state.pose, blink: state.blink, frame: state.frame, marks: state.marks, now };

        const waits = [state.next, laptop?.next ?? null].filter((n) => n !== null);

        return waits.length === 0 ? null : Math.min(...waits);
    };

    const built = () => [...bodies.values()].filter((b) => b.creature !== null);

    return {
        /** The bodies for a world painted at `at` (ms, `performance.now()`) — the instant every held loop steps from. */
        paint(worldIn, at) {
            world = worldIn;
            paintedAt = at;
            fades = new Map(world.effects.filter((e) => e.kind === 'desk' && e.effect.animation_id === 'A24').map((e) => [e.key, e.effect]));
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
                // the clock's last answer for every built body: its time, its pose and its loop frame (the clip row's read)
                clock: built().map((b) => b.read).filter((r) => r !== null),
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
