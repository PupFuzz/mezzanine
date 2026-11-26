/**
 * WHERE THE 3-D FLOOR's FURNITURE STANDS — the world's pieces and desks (`floor/floor-world.js`) read into placements, by
 * kind, for `floor3d/furniture.js` to instance. Pure, no DOM and no Three, so `snack-dressing-probe.mjs` reads it under
 * node. `docs/design/plan-13033-stage2-3d-floor.md` § 3.4.
 *
 * A placement is `{ at, w, h, d?, turn?, anchor?, plan? }`: centred across on `at`, its foot at `at.y`, `w` across and
 * `h` up (`d` deep, or as deep as its model's plan keeps it at `w`), turned `turn` radians about `y`; in depth its back
 * (`anchor: 'back'`) or its front (`'front'`) on `at`, or centred on it with no `anchor`; and with an anchor, its body
 * shrunk in plan, never in height, to fit inside `plan` (`{ w, d }`, across and deep in its own frame) where it would
 * not — rule W's placement (`floor-world.js`'s piece; plan § 15.3). A restroom door set into a side wall also carries
 * its `facing`, the wall's thickness (`wall`) and its `flag`, which its theme's part list is drawn from.
 *
 * ⛔ A DRESSED PIECE STANDS AS TWO PLACEMENTS: the kit's piece fitted to the height and depth its dressing gives — the
 * theme's 2-D table top, not the tile's height, which holds what stands on it too — and the dressing under
 * `dressingOf(kind)` at the piece's own foot and size, which the theme lays out on that top.
 */

import { UNIT } from '../floor/floor-world.js';

/** The key a dressed kind's dressing is placed under. */
export function dressingOf(kind) {
    return `${kind}+dressing`;
}

/**
 * The inputs a theme's `piece3d()` and `dressing3d()` are called with for a placement: its size, and a picture px's — and
 * a side door's `facing`, `wall` and `flag`. A part list is a function of these alone, so placements with equal inputs
 * share one geometry.
 */
export function inputsOf(p) {
    return { w: p.w, h: p.h, px: 1 / UNIT, ...(p.facing === undefined ? {} : { facing: p.facing, wall: p.wall, flag: p.flag }) };
}

/**
 * What the world places: kind → `[placement]`.
 *
 * @param {object} world the floor's world
 * @param {list<string>} dressed the kind map's `DRESSED`
 * @param {function|null} dressing the theme's `dressing3d()`, or `null` where no theme exports one
 * @returns {Map<string, list<object>>}
 */
export function placementsOf(world, dressed, dressing) {
    const by = new Map();
    const add = (kind, p) => by.set(kind, [...(by.get(kind) ?? []), p]);

    for (const piece of world.pieces) {
        const p = {
            at: piece.at,
            w: piece.w,
            h: piece.h,
            ...(piece.turn === undefined ? {} : { turn: piece.turn }),
            ...(piece.anchor === undefined ? {} : { anchor: piece.anchor, plan: piece.plan }),
            ...(piece.facing === undefined ? {} : { facing: piece.facing, wall: piece.wall, flag: piece.flag }),
        };
        const dress = dressed.includes(piece.kind) && dressing !== null ? dressing(piece.kind, inputsOf(p)) : null;

        if (dress === null) {
            add(piece.kind, p);
        } else {
            add(piece.kind, { ...p, h: dress.top, d: dress.d });
            add(dressingOf(piece.kind), p);
        }
    }

    for (const desk of world.desks) {
        const { slab, parts } = desk;
        const centre = { x: (slab.left + slab.right) / 2, y: 0, z: (slab.back + slab.front) / 2 };
        const depth = slab.front - slab.back;

        if (desk.placeholder) {
            add('placeholder', { at: centre, w: slab.right - slab.left, h: slab.top, d: depth });
            continue;
        }

        // The drawers toward the sitter: the kit's desk is turned to face the band.
        add('desk', { at: centre, w: slab.right - slab.left, h: slab.top, d: depth, turn: Math.PI });

        if (parts.chair !== null) {
            // The chair behind its sitter, its seat under it.
            add('chair', { at: { ...parts.chair.at, z: parts.chair.at.z - parts.chair.w * 0.25 }, w: parts.chair.w * 0.8, h: parts.chair.h * 0.8 });
        }

        if (parts.monitor !== null) {
            // The monitor facing its sitter (the operator's Q4 answer).
            add('monitorFrame', { at: { ...parts.monitor.at, y: slab.top }, w: parts.monitor.w, h: parts.monitor.h * 0.6, turn: Math.PI });
        }

        if (parts.props !== null) {
            add('deskProps', { at: { ...parts.props.at, x: slab.left + parts.props.w * 0.35, y: slab.top }, w: parts.props.w * 0.4, h: parts.props.h * 0.8 });
        }

        // A couch a row of the side table's seats, each as long as the world lays it.
        for (const couch of parts.sideTable ?? []) {
            add('sideTable', { at: couch.at, w: couch.w, h: couch.h, d: couch.d });
        }
    }

    return by;
}
