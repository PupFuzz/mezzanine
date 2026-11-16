/**
 * WHAT A FLOOR's MAP PLACES, CELL BY CELL — FLOOR.md § 10.3's tile layers read into cells (`mapTiles()`), the two
 * classes of tile kind (§ 10.6 item 6: `PLANE_KINDS`, and every other kind, which stands), and the one input a
 * standing piece's theme document is drawn from (`sceneryInput()`). Pure, DOM-free, and importing only the layout's
 * layer walk and the tileset reader — never the animation set — so a page that loads none of the floor's scene
 * machinery (the lobby's) can read a map as the floor page reads it. `floor/scene.js` re-exports the names it always
 * exported from here. The walkable model's obstacle rule (`obstaclesOf()`) is stated here once, for the scene and the
 * tests' probe alike.
 */

import { mapGrid, mapLayers } from './floor-layout.js';
import { readEmbedded, splitGid, tilesetUrl } from './tileset.js';

/**
 * FLOOR.md § 10.6 item 6's two classes of tile kind: the PLANE kinds, whose cells are merged into runs by the
 * one region pass (`scene.js`'s `tileRegions()`) and handed to the grid's plane document, and every other kind of the
 * registry's set, which STANDS — drawn at its tile's cell rect by the floor's theme. A tile with no kind, or
 * a kind the registry does not name, is drawn by nothing: the floor never draws a tile's image.
 */
export const PLANE_KINDS = Object.freeze(['wall', 'accent']);

/**
 * card#12786 — § 12's *Restroom door* (§ 6.7 item 2): the one standing kind of the restroom, and its tile's size — a door
 * frame taller than the *Character rect*'s character with its sign plate above. The tile's rect is the obstacle the
 * lattice blocks, read off the map like every standing tile's.
 */
export const RESTROOM_DOOR = Object.freeze({ kind: 'restroom-door', w: 64, h: 144 });

/**
 * card#12786, card#13033 — § 12's *Restroom plates* (§ 6.7 item 2): the rects the scene hands the theme with the door, where
 * the painter draws the vacant / occupied field (`sign`) and its leaf, and the theme the rims, the pictogram's plate
 * (`plate`) and, for a side door, the doorway in plan — whatever the theme, as the elevator's surround is handed its call
 * plates. `back`, in the tile's px from its top-left (a door drawn face-on); `side`, in the 2-D drawing box's px
 * (`SIDE_DOOR_BOX`) of a door set into the wall on its RIGHT — the flag `plate` and its `sign`, the `doorway` (the wall
 * strip over the span) and the `leaf` the painter slides over it; a `left` door mirrors each `x` in the box's width
 * (`restroomDoorGeometry()`), and the floor page's plan lowers the flag onto the doorway (`planPlates()`).
 */
export const RESTROOM_PLATES = Object.freeze({
    back: Object.freeze({
        sign: Object.freeze({ x: 21, y: 58, w: 22, h: 12 }),
        plate: Object.freeze({ x: 14, y: 2, w: 36, h: 18 }),
    }),
    side: Object.freeze({
        plate: Object.freeze({ x: 2, y: 2, w: 36, h: 32 }),
        sign: Object.freeze({ x: 9, y: 20, w: 22, h: 12 }),
        doorway: Object.freeze({ x: 40, y: 82, w: 8, h: 64 }),
        leaf: Object.freeze({ x: 42, y: 88, w: 4, h: 52 }),
    }),
});

/**
 * card#13033 — a side door's 2-D drawing box (§ 12's *Restroom door*): the wall strip over the doorway and the flag sign
 * standing over its near jamb, `{ x: x_w − 40, y: foot − 146 }` from the wall face `x_w` and the tile's foot for `right`.
 */
export const SIDE_DOOR_BOX = Object.freeze({ w: 48, h: 150, face: 40, rise: 146 });

/**
 * card#13033 — a side door's plates AS THE FLOOR PAGE's PLAN DRAWS THEM (§ 12's *Restroom plates*, its plan drop): the flag
 * `plate` and its `sign` lowered until the plate's foot meets the doorway's head, the rest as handed. The `side` rects
 * stand the flag at its heights over the foot line, where the 3-D floor and the cab's door view stand it; the plan draws
 * the doorway as a strip along the wall rather than upright, so at those heights its flag would hang clear of the door.
 * The theme's `plan` document (`sceneryInput()`) and the painter's field (the scene's `field`) both read this.
 *
 * @param {object} plates a side door's plates, `RESTROOM_PLATES.side` or its `left` mirror
 */
export function planPlates(plates) {
    const drop = plates.doorway.y - (plates.plate.y + plates.plate.h);
    const down = (r) => Object.freeze({ ...r, y: r.y + drop });

    return Object.freeze({ ...plates, plate: down(plates.plate), sign: down(plates.sign) });
}

/**
 * The views of a side door's theme document (`sceneryInput()`'s `view`): the floor page's plan and the cab's flag
 * billboard. The cab's door view draws the doorway in its wall as polygons, from no theme document.
 */
export const DOOR_VIEWS = Object.freeze(['plan', 'flag']);

/**
 * THE INPUT A STANDING PIECE's THEME DOCUMENT IS DRAWN FROM (§ 10.6 items 2 and 6): the cell's kind and size, the
 * grid's seed and the cell's place in its layer (the piece's seeded choices are the cell's) — and, for the restroom
 * door, its rects (§ 12's *Restroom plates*), where the painter draws the field and the theme the rims. Every caller
 * hands the theme's `scenery()` this one shape.
 *
 * card#13033: a door's input follows its geometry (`floor-walls.js`'s `restroomDoorGeometry()`; `null`, a door no wall
 * stands against, reads as `back`). A `back` door's is the face-on picture's, whatever the view. A side door's carries its
 * `facing` and the `view` it is drawn for — `plan`, the floor page's, in the drawing box (`SIDE_DOOR_BOX`) with every
 * `side` plate mirrored for its facing and the flag lowered onto the doorway (`planPlates()`); `flag`, the door view's
 * billboard of the flag plate alone at its own size.
 *
 * @param {{kind: string, w: number, h: number, index: number}} cell a cell of `mapTiles()`
 * @param {string} seed the grid's seed — its room's `install_id`, or the floor's key for the hallway
 * @param {?{facing: string, plates: object}} geometry the door's geometry, for a `restroom-door` cell
 * @param {'plan'|'flag'} view what a side door is drawn as
 */
export function sceneryInput(cell, seed, geometry = null, view = 'plan') {
    const base = { kind: cell.kind, w: cell.w, h: cell.h, seed, index: cell.index };

    if (cell.kind !== RESTROOM_DOOR.kind) {
        return base;
    }

    if (geometry === null || geometry.facing === 'back') {
        return { ...base, ...RESTROOM_PLATES.back };
    }

    const side = { ...base, facing: geometry.facing, view };
    const { plate, sign } = geometry.plates;

    if (view === 'flag') {
        return { ...side, w: plate.w, h: plate.h, plate: { x: 0, y: 0, w: plate.w, h: plate.h }, sign: { x: sign.x - plate.x, y: sign.y - plate.y, w: sign.w, h: sign.h } };
    }

    return { ...side, w: SIDE_DOOR_BOX.w, h: SIDE_DOOR_BOX.h, ...planPlates(geometry.plates) };
}

/**
 * THE WALKABLE MODEL's OBSTACLES (§ 6.2's walk note item 3) — the ONE statement of the rule, read by the scene and by the
 * tests' probe: every `wall` cell and every standing kind's tile, at the rect the floor would draw it, whether or not the
 * theme draws it — less a restroom door set into a side wall (card#13033, § 6.7 item 2), whose wall cells already block
 * its doorway's line, so its tile returns to the floor. `tools/design/verify-floor.py`'s G-scenery leg 4 is the one copy
 * that cannot import this (Python), held to it by `verify-design-docs.selftest.py`'s drift check.
 *
 * @param {list<object>} cells `mapTiles()` cells
 * @param {list<string>} standing the standing kinds (the theme registry's kinds less `PLANE_KINDS`)
 * @param {function(object): {facing: string}} geometryOf a door cell's geometry (`restroomDoorGeometry()`)
 * @returns {list<{x: number, y: number, w: number, h: number, kind: string}>}
 */
export function obstaclesOf(cells, standing, geometryOf) {
    return cells
        .filter((c) => c.kind === 'wall' || (standing.includes(c.kind) && (c.kind !== RESTROOM_DOOR.kind || geometryOf(c).facing === 'back')))
        .map((c) => Object.freeze({ x: c.x, y: c.y, w: c.w, h: c.h, kind: c.kind }));
}

/**
 * Every tile a map draws, in document order bottom first (§ 10.3's `layers[]` row), each cell
 * resolved to its tileset image and source rectangle — an image-collection tile is drawn at its own
 * size, bottom-aligned to its cell, as Tiled draws one.
 */
export function mapTiles(map, origin, tilesetFor, owner) {
    const grid = mapGrid(map);

    if (grid === null) {
        return [];
    }

    // Each `tilesets[]` entry by its `firstgid`, highest first, so a GID finds its own.
    const sets = (Array.isArray(map.tilesets) ? map.tilesets : [])
        .map((entry) => ({
            firstgid: entry?.firstgid ?? 1,
            tileset: typeof entry?.source === 'string' ? tilesetFor(tilesetUrl(entry.source)) : safeEmbedded(entry),
            url: typeof entry?.source === 'string' ? tilesetUrl(entry.source) : null,
        }))
        .sort((a, b) => b.firstgid - a.firstgid);

    const out = [];

    // § 10.3's layer tree, groups walked, through the one walk `mapDesks()` reads it by too.
    for (const { layer, offset: here, opacity: alpha, visible } of mapLayers(map)) {
        if (!visible || layer.type !== 'tilelayer' || !Array.isArray(layer.data)) {
            continue;
        }

        const columns = layer.width ?? grid.width;

        layer.data.forEach((cell, i) => {
            // Tiled's flip bits are stripped and never carried: the floor draws every tile unrotated (§ 10.6 item 6).
            const { gid } = splitGid(cell);

            if (gid === 0) {
                return;
            }

            const set = sets.find((s) => gid >= s.firstgid);

            if (set === undefined) {
                return;
            }

            const tile = set.tileset?.tile(gid - set.firstgid) ?? null;
            const col = i % columns;
            const row = Math.floor(i / columns);
            const offsetX = set.tileset?.offset.x ?? 0;
            const offsetY = set.tileset?.offset.y ?? 0;
            const w = tile?.sw ?? grid.tilewidth;
            const h = tile?.sh ?? grid.tileheight;

            out.push({
                room: owner,
                layer: layer.name ?? null,
                x: origin.x + here.x + col * grid.tilewidth + offsetX,
                y: origin.y + here.y + (row + 1) * grid.tileheight - h + offsetY,
                w,
                h,
                image: tile?.image ?? null,
                // The image's own size, so a sheet tile is drawn as a window onto its sheet.
                iw: tile?.iw ?? w,
                ih: tile?.ih ?? h,
                tileset: set.url,
                sx: tile?.sx ?? 0,
                sy: tile?.sy ?? 0,
                sw: w,
                sh: h,
                opacity: alpha,
                properties: tile?.properties ?? null,
                // § 10.6 item 6: what the floor's theme draws the tile as — never its image.
                kind: typeof tile?.properties?.kind === 'string' ? tile.properties.kind : null,
                // The cell's place in its layer — a standing piece's seeded choices are the cell's — and that place as the
                // layer's column and row, before any offset (the cab's door view classifies a cell by it).
                index: i,
                col,
                row,
            });
        });
    }

    return out.map((cell) => Object.freeze(cell));
}

function safeEmbedded(entry) {
    try {
        return readEmbedded(entry);
    } catch {
        return null;
    }
}
