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
// - `MISSING` — a kind the kit has no piece for and nothing first-party draws yet, named so the gap is a list rather
//   than an absence.
//
// `PAINT` is the recolour (plan § 3.4): for every `FILES` kind, each of its file's named materials → for every theme of
// the registry, the `PALETTE` path (`row.key` or `row.key.index`) whose colour REPLACES that material on a floor in that
// theme — replaced, never tinted: the kit's materials are unlit (`KHR_materials_unlit`, so `GLTFLoader` builds
// `MeshBasicMaterial`s), and the 3-D floor draws every piece in one toon material so the kit and the creatures shade alike.
//
// `tools/floor-models/selftest.mjs` derives that population from the registry and holds this file to it both ways,
// holds `FILES` to the `.glb` files beside it both ways, and holds `PAINT` to every material each file names and to a
// colour of every theme's `PALETTE`.

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

// `restroom-door` is the room's architecture — a doorway whose leaf and plates the scene drives — and the room is
// first-party by the same answer; the rest are office pieces the kit does not carry.
export const PIECE3D = Object.freeze(['restroom-door', 'water-cooler', 'filing-cabinet', 'copier', 'whiteboard', 'aquarium', 'bean-bag', 'globe']);

export const MISSING = Object.freeze([]);

const both = (studio, cottage) => Object.freeze({ studio, cottage });

export const PAINT = Object.freeze({
    bookcase: Object.freeze({ wood: both('bookcase.shelf', 'bookcase.paint') }),
    plant: Object.freeze({ wood: both('plant.pots.0', 'plant.baskets.0'), woodDark: both('plant.pots.4', 'plant.baskets.1'), plant: both('plant.leaf', 'plant.leaves.0') }),
    'floor-lamp': Object.freeze({ metal: both('floor-lamp.metal', 'floor-lamp.wood'), lamp: both('floor-lamp.shade', 'floor-lamp.shade') }),
    armchair: Object.freeze({ carpet: both('armchair.cover', 'armchair.cushion'), wood: both('armchair.legs', 'armchair.rattan') }),
    cushion: Object.freeze({ carpet: both('cushion.colours.0', 'cushion.colours.0') }),
    'book-pile': Object.freeze({
        carpetDarker: both('book-pile.books.0', 'book-pile.books.0'),
        carpetWhite: both('book-pile.books.5', 'book-pile.books.5'),
        plant: both('book-pile.books.3', 'book-pile.books.3'),
        metal: both('book-pile.books.2', 'book-pile.books.1'),
    }),
    'snack-table': Object.freeze({ wood: both('snack-table.wood', 'snack-table.paint') }),
    'snack-table-low': Object.freeze({ wood: both('snack-table.wood', 'snack-table.paint') }),
    'coat-rack': Object.freeze({ wood: both('coat-rack.wood', 'coat-rack.wood') }),
    desk: Object.freeze({ wood: both('desk.wood', 'desk.top'), metal: both('view.desk_edge', 'desk.paint') }),
    chair: Object.freeze({ metalMedium: both('view.desk_edge', 'monitor-frame.arm'), carpet: both('chair.colours.0', 'chair.colours.0') }),
    monitorFrame: Object.freeze({ metalDark: both('view.desk_edge', 'monitor-frame.arm'), metal: both('monitor-frame.bezel', 'monitor-frame.bezel') }),
    deskProps: Object.freeze({ metal: both('floor-lamp.metal', 'floor-lamp.wood'), lamp: both('desk-props.lamps.0', 'floor-lamp.shade') }),
    sideTable: Object.freeze({ carpet: both('side-table.couch.0', 'side-table.cushion.0'), wood: both('side-table.wood', 'side-table.bench.0') }),
});
