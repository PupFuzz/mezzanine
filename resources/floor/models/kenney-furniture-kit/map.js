// THE KIND MAP — which file of Kenney's Furniture Kit stands each piece of the 3-D floor (card#13033 stage 2,
// `docs/design/plan-13033-stage2-3d-floor.md` § 3.4 and § 9 slice 1; the operator's answer Q1 A of 2026-11-11,
// `docs/design/FLOOR.md` § 14 item 49). Data, nothing computed.
//
// It owes a disposition to every standing kind of the theme registry (`../../themes/index.js`'s `KINDS` less the
// plane kinds) and every desk part of the theme API, each in EXACTLY ONE of:
//
// - `FILES` — a kit file in this directory, loaded once and instanced per placement, recoloured from the theme's
//   `PALETTE` by the renderer;
// - `PIECE3D` — drawn by the house theme's optional `piece3d` export (`FLOOR.md` § 10.6 item 1);
// - `MISSING` — a kind the kit has no piece for, named so the gap is a list rather than an absence: the
//   first-party work the plan's slice 4 owes.
//
// `tools/floor-models/selftest.mjs` derives that population from the registry and holds this file to it both ways,
// and holds `FILES` to the `.glb` files beside it both ways.

export const FILES = Object.freeze({
    bookcase: 'bookcaseOpen.glb',
    plant: 'pottedPlant.glb',
    'floor-lamp': 'lampRoundFloor.glb',
    armchair: 'loungeChair.glb',
    cushion: 'pillow.glb',
    'book-pile': 'books.glb',
    'snack-table': 'table.glb',
    'snack-table-low': 'tableCoffee.glb',
    'coat-rack': 'coatRackStanding.glb',
    desk: 'desk.glb',
    chair: 'chairDesk.glb',
    monitorFrame: 'computerScreen.glb',
    deskProps: 'lampRoundTable.glb',
    sideTable: 'loungeSofa.glb',
});

export const PIECE3D = Object.freeze([]);

// `restroom-door` is the room's architecture — a doorway whose leaf and plates the scene drives — and the room is
// first-party by the same answer; the rest are office pieces the kit does not carry.
export const MISSING = Object.freeze(['restroom-door', 'water-cooler', 'filing-cabinet', 'copier', 'whiteboard', 'aquarium', 'bean-bag', 'globe']);
