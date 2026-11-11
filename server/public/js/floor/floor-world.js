/**
 * THE FLOOR AS A WORLD — the scene (`floor/scene.js`'s `buildScene()`, the one decision about what stands where) lifted
 * into the stage-1 world: boxes, placed pieces, placed characters, label anchors, in world units. Pure, frozen, no DOM
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
import { keyOf } from '../wire/seat-key.js';

/** Scene px per world unit (§ 12's *Building view unit*). */
export const UNIT = 100;

/** The heights, in world units: the door view's centimetres over its `SCALE` (cm per scene px) and `UNIT`. */
export const ROOM_H_U = ROOM_H / SCALE / UNIT;
export const PARTITION_H_U = PARTITION_H / SCALE / UNIT;
export const DESK_H_U = DESK_H / SCALE / UNIT;
export const MONITOR_TOP_U = MONITOR_TOP / SCALE / UNIT;

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
 * The scene's floor as a world.
 *
 * @param {object} scene `buildScene()`'s scene (as the floor screen hands it, its effects in flight included)
 * @returns {object} the frozen world: `slab`, `extent` (units), `planes`, `band`, `walls`, `pieces`, `desks`,
 *          `walkers`, `labels`
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

        desks.push(Object.freeze({
            key: d.key,
            seat_id: d.seat_id,
            install_id: d.install_id,
            overflow: d.overflow,
            placeholder: d.placeholder,
            lighting: d.lighting,
            slab: Object.freeze({ left: back.x, right: front.x, back: back.z, front: front.z, top: DESK_H_U, drawers: 'sitter' }),
            sitter: character === undefined ? null : Object.freeze({
                key: d.key,
                species: character.species,
                pose: character.pose,
                glyph: character.glyph,
                animation: character.animation,
                at: at({ x: d.anchor_x, y: plan.y - plan.h / 2 }),
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
            decoration: s.decoration,
            doc: s.doc,
        }))),
        desks: Object.freeze(desks),
        walkers: Object.freeze(walkers),
        labels: Object.freeze(labels),
    });
}
