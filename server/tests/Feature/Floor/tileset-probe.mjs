/**
 * The probe `TheTilesetReaderReadsBothSpellingsTest` drives the SHIPPED tileset reader through —
 * `server/public/js/floor/tileset.js` (Appendix B row 14), or a mutated copy of it. `node`, no
 * dependencies, no DOM.
 *
 * stdin  — `{ "tilesets": [ { "url": "/art/floor/…", "text": "<the file>", "ids": [ … ] } ] }`
 * stdout — one entry per tileset: `{ ok, error, images, offset, tilewidth, tileheight,
 *          tiles: { "<id>": <tile()> } }`, every id asked for, in order.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const { readTileset } = await import(pathToFileURL(join(dir, 'tileset.js')).href);
const payload = JSON.parse(readFileSync(0, 'utf8'));

console.log(JSON.stringify(payload.tilesets.map(({ url, text, ids }) => {
    try {
        const tileset = readTileset(text, url);

        return {
            ok: true,
            error: null,
            images: [...tileset.images].sort(),
            offset: tileset.offset,
            tilewidth: tileset.tilewidth,
            tileheight: tileset.tileheight,
            tiles: Object.fromEntries(ids.map((id) => [id, tileset.tile(id)])),
        };
    } catch (error) {
        return { ok: false, error: `${error.name}: ${error.message}` };
    }
}), null, 2));
