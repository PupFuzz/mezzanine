/**
 * WHAT A FLOOR's MAP PLACES, CELL BY CELL — FLOOR.md § 10.3's tile layers read into cells (`mapTiles()`), the two
 * classes of tile kind (§ 10.6 item 6: `PLANE_KINDS`, and every other kind, which stands), and the one input a
 * standing piece's theme document is drawn from (`sceneryInput()`). Pure, DOM-free, and importing only the layout's
 * layer walk and the tileset reader — never the animation set — so a page that loads none of the floor's scene
 * machinery (the lobby's) can read a map as the floor page reads it. `floor/scene.js` re-exports the names it always
 * exported from here.
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
 * card#12786 — § 12's *Restroom plates* (§ 6.7 item 2): the two rects the scene hands the theme with the door's tile, in
 * the tile's px from its top-left — `sign`, the slider plate the painter draws the vacant / occupied field inside, and
 * `plate`, the sign plate the theme draws the pictogram on. The theme draws the rims where the painter draws the field,
 * whatever the theme, as the elevator's surround is handed its call plates.
 */
export const RESTROOM_PLATES = Object.freeze({
    sign: Object.freeze({ x: 21, y: 58, w: 22, h: 12 }),
    plate: Object.freeze({ x: 14, y: 2, w: 36, h: 18 }),
});

/**
 * THE INPUT A STANDING PIECE's THEME DOCUMENT IS DRAWN FROM (§ 10.6 items 2 and 6): the cell's kind and size, the
 * grid's seed and the cell's place in its layer (the piece's seeded choices are the cell's) — and, for the restroom
 * door, the slider's and the sign plate's rects (§ 12's *Restroom plates*), where the painter draws the field and the
 * theme the rims. Every caller hands the theme's `scenery()` this one shape.
 *
 * @param {{kind: string, w: number, h: number, index: number}} cell a cell of `mapTiles()`
 * @param {string} seed the grid's seed — its room's `install_id`, or the floor's key for the hallway
 */
export function sceneryInput(cell, seed) {
    return {
        kind: cell.kind,
        w: cell.w,
        h: cell.h,
        seed,
        index: cell.index,
        ...(cell.kind === RESTROOM_DOOR.kind ? RESTROOM_PLATES : {}),
    };
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
                // The cell's place in its layer — a standing piece's seeded choices are the cell's.
                index: i,
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
