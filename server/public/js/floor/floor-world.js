/**
 * THE FLOOR AS A WORLD — the scene (`floor/scene.js`'s `buildScene()`, the one decision about what stands where) lifted
 * into the stage-1 world: boxes, placed pieces, placed characters, label anchors, and the timelines of what moves off a
 * walker (the leaves, the birds), in world units. Pure, frozen, no DOM
 * and no Three — the 3-D floor's renderer (`floor3d/`, slice 4) draws it and decides nothing.
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.1 (card#13033 stage 2 slice 2); `docs/design/FLOOR.md` § 11's AT-D3-38.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE WORLD (stage 1's, § 12's *Building view unit*): a world unit is `UNIT` scene px, `y` is up, and a floor is a
 * slab centred on `x` whose front edge — the drawing's bottom, where the overflow strip is — is at `+z`. `worldOf(slab,
 * point)` is the one map from a scene-px point to a world point on a slab, hoisted here at its second caller and
 * re-exported by `building/stack-model.js`, whose stack stands its slabs in the same frame; `floorSlab()` is the one
 * floor's slab, its top at `y = 0`. So every plan point the scene computes — a desk's foot, a walker's `pointAt()`, a
 * wall's cell — becomes a world point by the function the building view already uses, and nothing is placed twice.
 *
 * ⛔ THE FLOOR IS THE SCENE's EXTENT BELOW THE BAND: the band (§ 4.2's back wall) is drawn above the floor in the
 * picture, and in the world it is a wall standing on the floor's back edge, so its picture rows are HEIGHTS (`band`),
 * never floor.
 *
 * ⛔ DEPTH AGAINST HEIGHT (plan § 3.1's split, `anchor3d()`): the scene is a plan whose `y` is depth, except for what the
 * 2-D layout draws ABOVE a thing's foot — a bubble, a nameplate, the facts — whose offset above the foot is a HEIGHT. A
 * desk's element stands at the desk's own depth (`depth_y`, its furniture foot line) and at the height its centre is
 * drawn above the desk's foot: its sitter's feet, or the desk's foot line with nobody in it.
 *
 * ⛔ IT ADDS NO FACT AND READS NO FIELD THE SCENE DOES NOT CARRY (the scene's header's rule): every string on a label is
 * the scene's element's own, every wall the scene's `walls` (`floor/floor-walls.js`, the door view's classification),
 * every walker the scene's effect with its legs. The heights are the door view's centimetres (`floor/door-view.js`) over
 * its `SCALE` and `UNIT`, so the floor through the cab's doors and the floor in 3-D agree with no new constant.
 *
 * ⛔ WHICH WAY A DESK FACES (plan § 3.4, the operator's ruling of 2026-10-06 and answer Q4 of 2026-11-11): the sitter is
 * on the BAND side of the desk — behind it as the 2-D viewer sees it, facing `+z` — the drawers face the sitter, the
 * monitor faces its sitter and an intern's laptop its intern. A sitter stands half the slab's depth behind the slab's
 * back edge: its chair's centre, chosen (no figure in the scene places a chair in plan).
 *
 * `tests/Feature/Floor/floor-world-probe.mjs` drives it over the harness's scenes (`TheFloorIsTheScenesThirdRendererTest`).
 */

import { LINE, union } from './desk-layout.js';
import { deskSlab, monitorFrame } from './desk-geometry.js';
import { DESK_H, MONITOR_TOP, PARTITION_H, ROOM_H, SCALE } from './door-view.js';
import { pointAt } from './route.js';
import { ELEVATOR_DOOR } from './scene.js';
import { BIRD_LIFT, HOP_SCALE, arcLift } from './bird.js';
import { keyOf } from '../wire/seat-key.js';

/** Scene px per world unit (§ 12's *Building view unit*). */
export const UNIT = 100;

/** The heights, in world units: the door view's centimetres over its `SCALE` (cm per scene px) and `UNIT`. */
export const ROOM_H_U = ROOM_H / SCALE / UNIT;
export const PARTITION_H_U = PARTITION_H / SCALE / UNIT;
export const DESK_H_U = DESK_H / SCALE / UNIT;
export const MONITOR_TOP_U = MONITOR_TOP / SCALE / UNIT;

/** How far in front of the back wall's face a bird at the birdhouse stands, in units — clear of the band's picture. */
const BIRD_DEPTH = 0.08;

/** The kinds of a desk's elements its facts card carries, in the order the scene emits them. */
const FACT_KINDS = Object.freeze(['label', 'currency', 'lag', 'gauge-pct', 'badge', 'flag', 'stool-more', 'quiet-age']);

/** A slab's top face height. */
export function topOf(slab) {
    return slab.box.y + slab.box.h;
}

/**
 * A scene point on a slab, in world units — the one scene-px-to-world map: the drawing's bottom edge at the slab's
 * front, its top toward the back, its left at the slab's left.
 */
export function worldOf(slab, at) {
    return {
        x: slab.box.x + (at.x - slab.extent.x) / UNIT,
        y: topOf(slab),
        z: slab.box.z + (at.y - slab.extent.y) / UNIT,
    };
}

/**
 * The one floor's slab: `extent` (scene px) centred on `x`, its front edge at `+z`, its top the plane `y = 0`.
 *
 * @param {{x: number, y: number, w: number, h: number}} extent
 */
export function floorSlab(extent) {
    const w = extent.w / UNIT;
    const d = extent.h / UNIT;

    return Object.freeze({
        box: Object.freeze({ x: -w / 2, y: 0, z: -d / 2, w, h: 0, d }),
        extent: Object.freeze({ x: extent.x, y: extent.y, w: extent.w, h: extent.h }),
    });
}

/** A desk's foot line, scene px: its sitter's feet, or with nobody in it the desk's own furniture foot. */
function footOf(desk) {
    const character = desk.elements.find((e) => e.kind === 'character');

    return character === undefined ? desk.depth_y : character.y + character.h;
}

/**
 * Where one of a desk's drawn elements stands (plan § 3.1's split): its centre's `x`, the desk's depth, and the height
 * its centre is drawn above the desk's foot — 0 for one drawn at or below the foot. Scene px throughout.
 *
 * @param {{x: number, y: number, w: number, h: number}} el
 * @param {object} desk the scene's desk
 * @returns {{x: number, depth: number, height: number}}
 */
export function anchor3d(el, desk) {
    return Object.freeze({ x: el.x + el.w / 2, depth: desk.depth_y, height: Math.max(0, footOf(desk) - (el.y + el.h / 2)) });
}

/** A label's anchor as a world point on `slab`. */
export function anchorPoint(slab, anchor) {
    const p = worldOf(slab, { x: anchor.x, y: anchor.depth });

    return { ...p, y: p.y + anchor.height / UNIT };
}

/**
 * Where a walker is `tMs` into its effect — `route.js`'s `pointAt()` lifted onto the slab — with where it is drawn
 * (`floor`, `cab` or `inside`), or `null` for an effect with no legs.
 */
export function walkerAt(slab, walker, tMs) {
    const p = pointAt(walker.effect, tMs);

    return p === null ? null : Object.freeze({ ...worldOf(slab, p), where: p.where });
}

/** The key a walker is drawn under: an intern's (A10), a break's walker's (A25, A26), or its seat's (A1, A2). */
function walkerKey(fx) {
    return fx.intern_key ?? fx.walker_key ?? keyOf(fx);
}

/**
 * THE ELEVATOR's LEAVES AS A LEAF TIMELINE — the walks' door spans (`scene.doors`, frames from the paint) as the moves
 * `leafLevel()` reads: each span opens over `ELEVATOR_DOOR.open` frames from its start and closes over
 * `ELEVATOR_DOOR.close` frames to its end, a step a frame — the timeline the 2-D painter's `leafAnimation()` keys.
 */
export function elevatorLeaf(spans) {
    return Object.freeze(spans.flatMap((d) => [
        Object.freeze({ start: d.from, frames: ELEVATOR_DOOR.open, from: 0, to: 1 }),
        Object.freeze({ start: d.to - ELEVATOR_DOOR.close, frames: ELEVATOR_DOOR.close, from: 1, to: 0 }),
    ]));
}

/**
 * The rows the 3-D floor draws off the walkers, by what draws them: a bird's flight (A19, A22), A20's ring, and the
 * desk's own (A5's screen, A11's badge, A24's laptop). Every other non-walker row — A12's gauge and A14's pulse
 * (the card's and the strip's), A13's removal and A16's move (the next render's desk), A17's room tick (the band's
 * picture) — has no 3-D drawing of its own, as it has none on the 2-D floor's drawing but those.
 */
export const BIRD_ROWS = Object.freeze(['A19', 'A22']);
export const DESK_ROWS = Object.freeze(['A5', 'A11', 'A24']);

/**
 * WHERE A BIRD IS `tMs` into its effect (§ 6.2 A19, A22; `scene.js`'s `travel()` timeline): on its flight, carried along
 * the chord between its two ends and lifted over it by `arcLift()` — the 2-D arc's lift, as a height — flapping a wing
 * a frame; standing on the birdhouse's perch for its dwell; hopping between the perch and the hole, shrinking to
 * `HOP_SCALE`; or its envelope resting where it set it down — or `null` before its first part and after its last. Under
 * reduced motion (`frames` 0) it is the still envelope at its end for the moving form's duration (`still_frames`).
 *
 * @param {object} bird the world's bird (`floorWorld()`'s `effects` entry of kind `bird`)
 * @param {number} tMs
 * @returns {{phase: string, x: number, y: number, z: number, heading: number, wing: string, scale: number}|null}
 */
export function birdAt(bird, tMs) {
    const fx = bird.effect;
    const f = tMs / fx.frame_interval_ms - fx.wait_frames;
    const at = (p, phase, extra = {}) => Object.freeze({ phase, x: p.x, y: p.y, z: p.z, heading: bird.heading, wing: 'down', scale: 1, ...extra });
    const within = (start, frames) => f >= start && f < start + frames;

    if (fx.frames === 0) {
        return f >= 0 && f < fx.still_frames ? at(bird.to, 'still') : null;
    }

    const p = fx.perch;

    if (p !== null && within(p.hop_start, p.hop_frames)) {
        // the hop in shrinks from the perch into the hole; the hop out grows from it onto the perch
        const u = (f - p.hop_start) / p.hop_frames;
        const k = p.hop === 'in' ? u : 1 - u;
        const lerp = (a, b) => a + (b - a) * k;

        return at({ x: lerp(bird.ledge.x, bird.hole.x), y: lerp(bird.ledge.y, bird.hole.y), z: bird.ledge.z }, 'hop', { scale: 1 + (HOP_SCALE - 1) * k, wing: Math.floor(f) % 2 === 0 ? 'up' : 'down' });
    }

    if (p !== null && within(p.dwell_start, p.dwell_frames)) {
        return at(bird.ledge, 'perch');
    }

    if (within(fx.flight_start, fx.frames)) {
        const u = (f - fx.flight_start) / fx.frames;
        const lerp = (a, b) => a + (b - a) * u;

        return at({ x: lerp(bird.from.x, bird.to.x), y: lerp(bird.from.y, bird.to.y) + bird.lift * 4 * u * (1 - u), z: lerp(bird.from.z, bird.to.z) }, 'flight', { wing: Math.floor(f) % 2 === 0 ? 'up' : 'down' });
    }

    if (within(fx.flight_start + fx.frames, fx.rest_frames)) {
        return at(bird.to, 'rest');
    }

    return null;
}

/**
 * The scene's floor as a world.
 *
 * @param {object} scene `buildScene()`'s scene (as the floor screen hands it, its effects in flight included)
 * @returns {object} the frozen world: `slab`, `extent` (units), `planes`, `band`, `walls`, `pieces`, `desks`,
 *          `walkers`, `effects` (the birds, rings and desk rows drawn off the walkers), `doors` (the elevator's leaf
 *          timeline), `restroom_doors`, `labels`
 */
export function floorWorld(scene) {
    const band = scene.band;
    const top = band === null ? scene.extent.y : band.y + band.h;
    const slab = floorSlab({ x: scene.extent.x, y: top, w: scene.extent.w, h: scene.extent.y + scene.extent.h - top });
    const at = (point) => Object.freeze(worldOf(slab, point));
    // A rect on the band's picture, stood on the floor's back edge: its rows are heights above the floor.
    const onBand = (r) => Object.freeze({ x: at(r).x, w: r.w / UNIT, y: (top - (r.y + r.h)) / UNIT, h: r.h / UNIT, z: slab.box.z });

    const desks = [];
    const labels = [];

    for (const d of scene.desks) {
        const plan = deskSlab({ x: d.box.x, y: d.box.y + d.box.h });
        const back = worldOf(slab, { x: plan.x, y: plan.y });
        const front = worldOf(slab, { x: plan.x + plan.w, y: plan.y + plan.h });
        const of = (kind) => d.elements.filter((e) => e.kind === kind);
        const [character] = of('character');
        const [monitor] = of('monitor');
        const [screenText] = of('monitor-text');
        const monitorAt = monitorFrame(plan);
        const sitterZ = plan.y - plan.h / 2;
        // One of the desk's pieces of furniture: where its art element's centre is across, at the plan depth `y` (scene
        // px) it stands at, its drawn width and height in units — or `null` for a desk the scene draws without it (a placeholder's).
        const part = (kind, y) => {
            const [e] = of(kind);

            return e === undefined ? null : Object.freeze({ at: at({ x: e.x + e.w / 2, y: y(e) }), w: e.w / UNIT, h: e.h / UNIT });
        };

        desks.push(Object.freeze({
            key: d.key,
            seat_id: d.seat_id,
            install_id: d.install_id,
            overflow: d.overflow,
            placeholder: d.placeholder,
            lighting: d.lighting,
            slab: Object.freeze({ left: back.x, right: front.x, back: back.z, front: front.z, top: DESK_H_U, drawers: 'sitter' }),
            // The furniture the theme draws for the desk (plan § 3.1's `parts`), each where its 2-D element stands: the
            // chair where its sitter sits, the monitor and the props on the slab, the side table at its foot.
            parts: Object.freeze({
                chair: part('chair', () => sitterZ),
                monitor: part('monitor-frame', () => plan.y + plan.h / 2),
                props: part('desk-props', () => plan.y + plan.h / 2),
                sideTable: part('side-table', (e) => e.y + e.h),
            }),
            sitter: character === undefined ? null : Object.freeze({
                key: d.key,
                species: character.species,
                pose: character.pose,
                glyph: character.glyph,
                animation: character.animation,
                at: at({ x: d.anchor_x, y: sitterZ }),
                // as tall as its 2-D character is drawn (§ 3.1's heights: the door view's arithmetic, so no new figure)
                h: character.h / UNIT,
                facing: '+z',
            }),
            interns: Object.freeze(of('stool').map((e) => Object.freeze({
                key: e.key,
                call_id: e.call_id,
                seat_species: e.seat_species,
                chibi: true,
                laptop: e.activity === 'working',
                lid: 'intern',
                animation: e.animation,
                at: at({ x: e.x + e.w / 2, y: e.y + e.h }),
                h: e.h / UNIT,
            }))),
            screen: monitor === undefined ? null : Object.freeze({
                facing: 'sitter',
                lit: monitor.lit,
                text: screenText?.text ?? null,
                at: at({ x: monitorAt.x + monitorAt.w / 2, y: plan.y + plan.h / 2 }),
            }),
        }));

        // The desk's labels in their one order — name, status, chip, facts, bubble (plan § 3.9).
        const label = (kind, el, fields) => labels.push(Object.freeze({ key: d.key, kind, anchor: anchor3d(el, d), ...fields }));
        const [name] = of('nameplate');
        const [chip] = of('chip');
        const facts = d.elements.filter((e) => FACT_KINDS.includes(e.kind) && typeof e.text === 'string');

        if (name !== undefined) {
            label('name', name, { text: name.text });
        }

        if (monitor !== undefined) {
            // The screen's strings (the operator's Q4 answer): what the monitor shows, floated over the monitor's top.
            labels.push(Object.freeze({
                key: d.key,
                kind: 'status',
                anchor: Object.freeze({ x: monitorAt.x + monitorAt.w / 2, depth: d.depth_y, height: MONITOR_TOP / SCALE + LINE }),
                lit: monitor.lit,
                text: monitor.lit === 'off' ? null : screenText?.text ?? null,
            }));
        }

        if (chip !== undefined) {
            label('chip', chip, { text: chip.text, render_state: chip.render_state });
        }

        if (facts.length > 0) {
            const [plate] = of('facts-plate');

            label('facts', plate ?? union(facts), { model: Object.freeze(facts.map((e) => Object.freeze({ kind: e.kind, text: e.text }))) });
        }

        if (d.bubble !== null) {
            label('bubble', d.bubble, { model: d.bubble });
        }
    }

    // A bird's end in the world: a desk's anchor on the floor, its envelope set down on the desk's top; the birdhouse's
    // perch or hole on the band, its picture row a height. The bird flies `BIRD_LIFT` above the point, as on the 2-D floor.
    const onDesk = (p) => Object.freeze({ ...at(p), y: DESK_H_U + BIRD_LIFT / UNIT });
    const onWall = (p) => Object.freeze({ x: at(p).x, y: (top - p.y + BIRD_LIFT) / UNIT, z: slab.box.z + BIRD_DEPTH });
    const effects = [];

    for (const fx of scene.effects) {
        if (BIRD_ROWS.includes(fx.animation_id)) {
            const portal = fx.perch !== null && fx.perch !== undefined;
            const out = portal && fx.leg === 'departure';
            const into = portal && fx.leg === 'arrival';
            const from = into ? onWall(fx.from) : onDesk(fx.from);
            const to = out ? onWall(fx.to) : onDesk(fx.to);

            effects.push(Object.freeze({
                kind: 'bird',
                key: keyOf(fx),
                from,
                to,
                ledge: portal ? onWall(fx.perch.point) : null,
                hole: portal ? onWall(fx.perch.hole) : null,
                lift: arcLift(Math.hypot(fx.to.x - fx.from.x, fx.to.y - fx.from.y)) / UNIT,
                // the plan heading the bird faces as it flies, about `y` (0 toward +z)
                heading: Math.atan2(to.x - from.x, to.z - from.z),
                effect: fx,
            }));
        } else if (fx.animation_id === 'A20' && fx.at !== null && fx.at !== undefined) {
            // the ring on the floor round the origin desk's anchor, the singing bird perched on that desk
            effects.push(Object.freeze({ kind: 'ring', key: keyOf(fx), at: at(fx.at), perch: onDesk(fx.at), radius: fx.radius / UNIT, effect: fx }));
        } else if (DESK_ROWS.includes(fx.animation_id) && (fx.seat_id !== null || typeof fx.intern_key === 'string')) {
            effects.push(Object.freeze({ kind: 'desk', key: fx.animation_id === 'A24' ? fx.intern_key : keyOf(fx), effect: fx }));
        }
    }

    // The restroom doors (§ 6.7): each door tile's leaf and its slider's field at the rects the 2-D painter draws them,
    // stood on the tile's foot line — a rect on a standing tile's face-on picture, its rows heights above that line.
    const restroomDoors = (scene.restroom_doors ?? []).map((d) => {
        const foot = d.y + d.h;
        const onTile = (r) => Object.freeze({ x: at(r).x, w: r.w / UNIT, y: (foot - (r.y + r.h)) / UNIT, h: r.h / UNIT });

        return Object.freeze({
            id: d.id,
            at: at({ x: d.x + d.w / 2, y: foot }),
            recess: onTile(d.recess),
            sign: onTile(d.sign),
            leaf: d.leaf,
            occupied: d.occupied,
        });
    });

    const walkers = scene.effects.filter((fx) => (fx.legs?.length ?? 0) > 0).map((fx) => Object.freeze({
        key: walkerKey(fx),
        animation_id: fx.animation_id,
        chibi: typeof fx.intern_key === 'string',
        species: fx.species ?? null,
        effect: fx,
    }));

    return Object.freeze({
        slab,
        extent: Object.freeze({ w: slab.box.w, d: slab.box.d }),
        planes: Object.freeze(scene.planes.map((p) => Object.freeze({ install_id: p.install_id, at: at(p), w: p.w / UNIT, d: p.h / UNIT, doc: p.doc }))),
        band: band === null ? null : Object.freeze({
            ...onBand(band),
            h: ROOM_H_U,
            docs: scene.band_docs,
            elevator: onBand(band.elevator.frame),
            // The two leaves, meeting at the seam, each the 2-D painter's rect (a pixel short of the seam).
            leaves: Object.freeze([
                onBand({ x: band.elevator.x, y: band.elevator.y, w: band.elevator.seam - band.elevator.x - 1, h: band.elevator.h }),
                onBand({ x: band.elevator.seam + 1, y: band.elevator.y, w: band.elevator.x + band.elevator.w - band.elevator.seam - 1, h: band.elevator.h }),
            ]),
            clock: onBand(band.clock),
            windows: Object.freeze(band.windows.map(onBand)),
        }),
        walls: Object.freeze(scene.walls.map((w) => {
            const corner = at(w);

            return Object.freeze({
                id: `wall:${w.x}:${w.y}`,
                kind: w.kind,
                box: Object.freeze({ x: corner.x, y: 0, z: corner.z, w: w.size / UNIT, h: w.kind === 'edge' ? ROOM_H_U : PARTITION_H_U, d: w.size / UNIT }),
            });
        })),
        pieces: Object.freeze(scene.scenery.map((s) => Object.freeze({
            kind: s.kind,
            room: s.room,
            cell: Object.freeze({ x: s.x, y: s.y }),
            at: at({ x: s.x + s.w / 2, y: s.y + s.h }),
            w: s.w / UNIT,
            h: s.h / UNIT,
            decoration: s.decoration,
            doc: s.doc,
        }))),
        desks: Object.freeze(desks),
        walkers: Object.freeze(walkers),
        effects: Object.freeze(effects),
        // The elevator's leaves for the walks in flight, a leaf timeline in frames from the paint (`elevatorLeaf()`).
        doors: elevatorLeaf(scene.doors ?? []),
        restroom_doors: Object.freeze(restroomDoors),
        labels: Object.freeze(labels),
    });
}
