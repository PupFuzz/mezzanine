/**
 * THE TILESET READER — `docs/design/FLOOR.md` Appendix B row 14: Tiled's tileset in both spellings
 * § 10.1 clause 1 admits, `.tsx` (XML) and `.tsj` (JSON), decoded into what the scene needs to draw
 * a cell — each tile's image URL and source rectangle — and every `source` resolved to the URL the
 * asset route serves it at (`/art/floor/…`). § 10.3 says the client decodes both; until this module
 * nothing did.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ PURE, AND NO DOM. There is no `DOMParser` under `node` and the scene that reads this is a model
 * the harness drives, so the XML spelling is read by the small reader below rather than by the
 * browser's parser — which would be a decision no gate can red. It reads the subset of the TSX
 * grammar a tileset carries: `<tileset>`'s grid attributes, `<tileoffset>`, a whole-tileset
 * `<image>`, each `<tile>` with its own `<image>` or its sub-rectangle, and `<properties>`.
 *
 * ⛔ A PATH THAT CLIMBS OUT OF THE ASSET ROOT RESOLVES TO NOTHING. A tileset's image `source` is
 * relative to the tileset, and a map's tileset `source` relative to the floor tree
 * (`App\Floor\FloorMap` resolves it there); a `..` that leaves `/art/floor/` is a reference the
 * asset route would refuse anyway, so it is refused here as an unresolvable asset rather than
 * requested.
 *
 * ⛔ A FILE THIS CANNOT READ IS A FAILURE, NEVER AN EMPTY TILESET. A tileset read as "no tiles"
 * draws a blank room and says nothing, which is § 9's opening sentence; a thrown `TilesetError`
 * is recorded by the loader as the failed asset it is, and § 9 F14 renders it.
 */

/** The URL prefix the asset route mounts the floor tree at (Appendix B row 14). */
export const FLOOR_ART = '/art/floor/';

/** Tiled's GID flip flags — the top three bits of a cell (Tiled's own documented layout). */
const FLIP_H = 0x80000000;
const FLIP_V = 0x40000000;
const FLIP_D = 0x20000000;
const GID_MASK = 0x1fffffff;

export class TilesetError extends Error {
    constructor(message) {
        super(message);
        this.name = 'TilesetError';
    }
}

/**
 * A POSIX path joined and normalised, or `null` when a `..` would climb above `base`'s root.
 *
 * @param {string} base a directory path ending in `/`, e.g. `/art/floor/tiles/`
 * @param {string} relative the reference as the document wrote it
 */
export function resolvePath(base, relative) {
    if (typeof relative !== 'string' || relative === '' || relative.startsWith('/') || /^[a-z]+:/i.test(relative)) {
        return null;
    }

    const floor = FLOOR_ART.split('/').filter((part) => part !== '').length;
    const parts = base.split('/').filter((part) => part !== '');

    for (const part of relative.split('/')) {
        if (part === '' || part === '.') {
            continue;
        }

        if (part === '..') {
            if (parts.length <= floor) {
                return null;
            }

            parts.pop();

            continue;
        }

        parts.push(part);
    }

    return `/${parts.join('/')}`;
}

/** The directory a URL sits in, with its trailing `/`. */
export function dirOf(url) {
    return url.slice(0, url.lastIndexOf('/') + 1);
}

/** A map's tileset `source` as the asset route serves it — relative to the floor tree (§ 10.3). */
export function tilesetUrl(source) {
    return resolvePath(FLOOR_ART, source);
}

/** A cell's GID split into the tile it names and Tiled's three flip flags. */
export function splitGid(cell) {
    const raw = Number(cell) >>> 0;

    return {
        gid: raw & GID_MASK,
        flip_h: (raw & FLIP_H) !== 0,
        flip_v: (raw & FLIP_V) !== 0,
        flip_d: (raw & FLIP_D) !== 0,
    };
}

/**
 * One tileset, decoded — from the file's text and the URL it was fetched from.
 *
 * @returns {{url: string, tilewidth: number, tileheight: number, offset: {x: number, y: number},
 *            tile: function(number): object|null, images: list<string>}}
 * @throws {TilesetError} naming what could not be read
 */
export function readTileset(text, url) {
    if (typeof text !== 'string') {
        throw new TilesetError(`${url}: no tileset text to read`);
    }

    const trimmed = text.trimStart();
    const parsed = trimmed.startsWith('<') ? fromXml(trimmed, url) : fromJson(trimmed, url);

    return build(parsed, dirOf(url), url);
}

/**
 * A tileset EMBEDDED in a map (a `tilesets[]` entry with no `source`) — the JSON spelling's own
 * members, resolved against the floor tree the map sits in.
 */
export function readEmbedded(entry, mapUrl = FLOOR_ART) {
    return build(normaliseJson(entry, mapUrl), dirOf(mapUrl), mapUrl);
}

/** The decoded shape both spellings reduce to, and the lookup the scene calls. */
function build(t, dir, url) {
    const positive = (v) => Number.isInteger(v) && v > 0;

    if (!positive(t.tilewidth) || !positive(t.tileheight)) {
        throw new TilesetError(`${url}: the tileset declares no positive tilewidth and tileheight`);
    }

    const tiles = new Map();

    for (const tile of t.tiles) {
        if (!Number.isInteger(tile.id) || tile.id < 0) {
            throw new TilesetError(`${url}: a tile declares no id`);
        }

        tiles.set(tile.id, tile);
    }

    const sheet = t.image === null ? null : {
        url: resolvePath(dir, t.image.source),
        width: t.image.width,
        height: t.image.height,
    };

    if (sheet !== null && sheet.url === null) {
        throw new TilesetError(`${url}: the tileset image \`${t.image.source}\` resolves outside the asset tree`);
    }

    const images = new Set(sheet === null ? [] : [sheet.url]);

    for (const tile of tiles.values()) {
        if (tile.image !== null) {
            const resolved = resolvePath(dir, tile.image.source);

            if (resolved === null) {
                throw new TilesetError(`${url}: tile ${tile.id}'s image \`${tile.image.source}\` resolves outside the asset tree`);
            }

            images.add(resolved);
        }
    }

    /** One tile's image and source rectangle, or `null` for an id the tileset does not carry. */
    const tile = (id) => {
        const own = tiles.get(id) ?? null;

        if (own !== null && own.image !== null) {
            const width = own.width ?? own.image.width;
            const height = own.height ?? own.image.height;

            return Object.freeze({
                image: resolvePath(dir, own.image.source),
                iw: own.image.width ?? width,
                ih: own.image.height ?? height,
                sx: own.x ?? 0,
                sy: own.y ?? 0,
                sw: width,
                sh: height,
                properties: own.properties,
            });
        }

        if (sheet === null || !positive(t.columns)) {
            return null;
        }

        if (Number.isInteger(t.tilecount) && id >= t.tilecount) {
            return null;
        }

        return Object.freeze({
            image: sheet.url,
            iw: sheet.width,
            ih: sheet.height,
            sx: t.margin + (id % t.columns) * (t.tilewidth + t.spacing),
            sy: t.margin + Math.floor(id / t.columns) * (t.tileheight + t.spacing),
            sw: t.tilewidth,
            sh: t.tileheight,
            properties: own?.properties ?? Object.freeze({}),
        });
    };

    /**
     * The collection tile whose own image is `imageUrl`, or `null` — how the scene finds the desk
     * sprite `resources/floor/furniture-box.js` names by its image path.
     */
    const imageTile = (imageUrl) => {
        for (const own of tiles.values()) {
            if (own.image !== null && resolvePath(dir, own.image.source) === imageUrl) {
                return tile(own.id);
            }
        }

        return null;
    };

    return Object.freeze({
        url,
        imageTile,
        tilewidth: t.tilewidth,
        tileheight: t.tileheight,
        offset: Object.freeze({ ...t.offset }),
        tile,
        images: Object.freeze([...images]),
    });
}

// ── The JSON spelling (`.tsj`, and a map's embedded entry) ───────────────────────────────────

function fromJson(text, url) {
    let decoded;

    try {
        decoded = JSON.parse(text);
    } catch (error) {
        throw new TilesetError(`${url}: not a JSON tileset (${error.message})`);
    }

    if (decoded === null || typeof decoded !== 'object' || Array.isArray(decoded)) {
        throw new TilesetError(`${url}: a tileset is a JSON object`);
    }

    return normaliseJson(decoded, url);
}

function normaliseJson(j, url) {
    const props = (list) => Object.freeze(Object.fromEntries((Array.isArray(list) ? list : [])
        .filter((p) => typeof p?.name === 'string')
        .map((p) => [p.name, p.value])));

    return {
        tilewidth: j.tilewidth,
        tileheight: j.tileheight,
        tilecount: j.tilecount ?? null,
        columns: j.columns ?? 0,
        spacing: j.spacing ?? 0,
        margin: j.margin ?? 0,
        offset: { x: j.tileoffset?.x ?? 0, y: j.tileoffset?.y ?? 0 },
        image: typeof j.image === 'string' ? { source: j.image, width: j.imagewidth ?? null, height: j.imageheight ?? null } : null,
        tiles: (Array.isArray(j.tiles) ? j.tiles : []).map((t) => {
            if (t === null || typeof t !== 'object') {
                throw new TilesetError(`${url}: a tile is not an object`);
            }

            return {
                id: t.id,
                image: typeof t.image === 'string' ? { source: t.image, width: t.imagewidth ?? null, height: t.imageheight ?? null } : null,
                x: t.x ?? null,
                y: t.y ?? null,
                width: t.width ?? null,
                height: t.height ?? null,
                properties: props(t.properties),
            };
        }),
    };
}

// ── The XML spelling (`.tsx`) — the subset a tileset carries ────────────────────────────────

/** Every element of the document as `{name, attrs, start, end, selfClosing}`, in order. */
function tags(xml) {
    const out = [];
    const re = /<(\/?)([A-Za-z_][\w.-]*)((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|'[^']*'))*)\s*(\/?)>/g;
    const clean = xml.replace(/<!--[\s\S]*?-->/g, (c) => ' '.repeat(c.length)).replace(/<\?[\s\S]*?\?>/g, (c) => ' '.repeat(c.length));
    let m;

    while ((m = re.exec(clean)) !== null) {
        out.push({ closing: m[1] === '/', name: m[2], attrs: attributes(m[3]), selfClosing: m[4] === '/' });
    }

    return out;
}

function attributes(text) {
    const attrs = {};
    const re = /([\w:.-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g;
    let m;

    while ((m = re.exec(text)) !== null) {
        attrs[m[1]] = decodeEntities(m[2] ?? m[3]);
    }

    return attrs;
}

function decodeEntities(value) {
    return value
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'")
        .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
        .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
        .replace(/&amp;/g, '&');
}

function int(value) {
    if (value === undefined) {
        return null;
    }

    const n = Number(value);

    return Number.isInteger(n) ? n : null;
}

function fromXml(xml, url) {
    const all = tags(xml);
    const root = all.find((t) => !t.closing);

    if (root === undefined || root.name !== 'tileset') {
        throw new TilesetError(`${url}: not a Tiled tileset (its root element is ${root === undefined ? 'absent' : `<${root.name}>`})`);
    }

    const out = {
        tilewidth: int(root.attrs.tilewidth),
        tileheight: int(root.attrs.tileheight),
        tilecount: int(root.attrs.tilecount),
        columns: int(root.attrs.columns) ?? 0,
        spacing: int(root.attrs.spacing) ?? 0,
        margin: int(root.attrs.margin) ?? 0,
        offset: { x: 0, y: 0 },
        image: null,
        tiles: [],
    };

    // A depth-tracked walk: `<image>` directly under `<tileset>` is the sheet, under `<tile>` is
    // that tile's own; `<property>` belongs to the `<tile>` it is inside.
    let tile = null;

    for (const t of all.slice(all.indexOf(root) + 1)) {
        if (t.closing) {
            if (t.name === 'tile') {
                tile = null;
            }

            continue;
        }

        switch (t.name) {
            case 'tileoffset':
                out.offset = { x: int(t.attrs.x) ?? 0, y: int(t.attrs.y) ?? 0 };
                break;
            case 'image': {
                const image = { source: t.attrs.source ?? null, width: int(t.attrs.width), height: int(t.attrs.height) };

                if (image.source === null) {
                    // § 10.1 clause 3: an `<image>` that names no `source` embeds its bytes.
                    throw new TilesetError(`${url}: an <image> names no source — an embedded image this reader does not decode`);
                }

                if (tile === null) {
                    out.image = image;
                } else {
                    tile.image = image;
                }

                break;
            }
            case 'tile':
                tile = {
                    id: int(t.attrs.id),
                    image: null,
                    x: int(t.attrs.x),
                    y: int(t.attrs.y),
                    width: int(t.attrs.width),
                    height: int(t.attrs.height),
                    properties: {},
                };
                out.tiles.push(tile);

                if (t.selfClosing) {
                    tile = null;
                }

                break;
            case 'property':
                if (tile !== null && typeof t.attrs.name === 'string') {
                    tile.properties[t.attrs.name] = t.attrs.value ?? '';
                }

                break;
            default:
                break;
        }
    }

    for (const t of out.tiles) {
        t.properties = Object.freeze(t.properties);
    }

    return out;
}

/**
 * The tilesets a map names, fetched through the page's own `fetch` and held by URL — § 10.3's
 * "the client decodes both". A failed fetch or an unreadable file is held as a FAILURE under the
 * tileset's URL, which is the asset id the scene reports to § 9 F14 — never as an empty tileset.
 *
 * ⛔ A URL IS ASKED FOR ONCE PER PAGE. A tileset is an asset, not a document with a version, and
 * F14's recovery is *retry on reload* — so a failure is not re-asked on every render, which would
 * be a poll behind every delta.
 */
export class TilesetLoader {
    #fetch;

    /** url → `{tileset}`, `{failure: string}`, or `{pending: true}` while its request is out. */
    #held = new Map();

    constructor(fetchImpl) {
        this.#fetch = fetchImpl;
    }

    /** What is held for one URL, or `undefined` when it has not been asked for. */
    get(url) {
        return this.#held.get(url);
    }

    /** Every URL held, with what is held for it. */
    get held() {
        return new Map(this.#held);
    }

    /** Fetch each URL not yet held. Resolves once every one has answered. */
    async load(urls) {
        const wanted = [...new Set(urls)].filter((url) => url !== null && !this.#held.has(url));

        await Promise.all(wanted.map(async (url) => {
            // Held as pending first, so a render that overlaps this one does not ask twice — and
            // PENDING, never failed: a tileset still in flight is not an asset that failed.
            this.#held.set(url, { pending: true });

            try {
                const response = await this.#fetch(url, { credentials: 'same-origin' });

                if (!response.ok) {
                    this.#held.set(url, { failure: `HTTP ${response.status}` });

                    return;
                }

                this.#held.set(url, { tileset: readTileset(await response.text(), url) });
            } catch (error) {
                this.#held.set(url, { failure: String(error?.message ?? error) });
            }
        }));
    }
}
