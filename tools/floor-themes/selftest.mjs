#!/usr/bin/env node
// THE THEME GATES — AT-D3-25's THEME HALF, its node legs (docs/design/FLOOR.md § 11, § 10.6; card#11046,
// Appendix B row 22). Node, no dependencies, no network.
//
//   node tools/floor-themes/selftest.mjs
//
// It lives OUTSIDE `resources/floor/themes/` on purpose: every file in that tree owes a provenance row (§ 10.1
// Gate 1), and a test is not an asset. It loads the theme registry and every theme it names exactly as the
// page does, and draws every document each theme's `API` names over populations DERIVED ON THE RUN and none
// stored: band widths from 1 px up to `TheFloorDrawsItsFrameTest`'s own bound, through the shipped scene's
// `backWall()`; the shipped default's room, through the shipped scene's own map reading; side tables from no
// seat to § 8.1's cap; every kind at every tile size the shipped tileset declares; every desk key of
// `fx-snapshot-4` and `fx-interns`. The painter's and the scene's legs — a theme that fails keeps every fact,
// the hallway's plane, the cache keyed on the inputs — are `Tests\Feature\Floor\AFloorIsDrawnInItsThemeTest`.
//
// ⛔ EVERY LEG CAN FAIL, AND IS WATCHED FAILING ON EVERY RUN. Each of AT-D3-25's REDs that a node process can
// plant is planted below, in a MIRROR of the tree in a temporary directory — the registry, the theme, the
// scene's modules, the sheet, the chip tool — and the leg it names is required to red on it, naming what the
// RED says it names. A leg that stayed green on its plant fails this run. The controls run beside them.

import { cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const { wellFormed, vectorDefect } = await import(pathToFileURL(join(REPO, 'tools', 'design', 'svg-document.mjs')).href);

let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };
const section = (s) => console.log(`\n${s}`);
const measure = (s) => console.log(`  MEASURED ${s}`);
let fresh = 0;
const load = async (path) => import(`${pathToFileURL(path).href}?v=${fresh++}`);

// ── the tree a leg reads: the shipped one, or a planted mirror of it ──────────────────────────────────
const shipped = {
    root: REPO,
    themes: join(REPO, 'resources', 'floor', 'themes'),
    tileset: join(REPO, 'resources', 'floor', 'tiles', 'floor-plane.tsx'),
    map: join(REPO, 'resources', 'floor', 'default.tmj'),
    js: join(REPO, 'server', 'public', 'js'),
    css: join(REPO, 'server', 'public', 'css', 'mezzanine.css'),
    tool: join(REPO, 'tools', 'design', 'state-chip-colours.py'),
    surfaces: join(HERE, 'surfaces.mjs'),
    doc: join(REPO, 'docs', 'design', 'FLOOR.md'),
};

/** A writable mirror of the tree, `plant(mirror)` applied to it — the RED's planted defect. */
function mirror(plant) {
    const dir = mkdtempSync(join(tmpdir(), 'theme-gates-'));
    const copy = (from, to) => { mkdirSync(dirname(to), { recursive: true }); cpSync(from, to, { recursive: true }); };
    const t = {
        root: dir,
        themes: join(dir, 'resources', 'floor', 'themes'),
        tileset: join(dir, 'resources', 'floor', 'tiles', 'floor-plane.tsx'),
        map: join(dir, 'resources', 'floor', 'default.tmj'),
        js: join(dir, 'server', 'public', 'js'),
        css: join(dir, 'server', 'public', 'css', 'mezzanine.css'),
        tool: join(dir, 'tools', 'design', 'state-chip-colours.py'),
        surfaces: join(dir, 'tools', 'floor-themes', 'surfaces.mjs'),
        doc: join(dir, 'docs', 'design', 'FLOOR.md'),
    };

    copy(shipped.themes, t.themes);
    copy(join(REPO, 'resources', 'characters'), join(dir, 'resources', 'characters'));
    copy(join(REPO, 'resources', 'floor', 'tiles'), join(dir, 'resources', 'floor', 'tiles'));
    copy(shipped.map, t.map);
    copy(shipped.js, t.js);
    copy(shipped.css, t.css);
    copy(shipped.tool, t.tool);
    copy(shipped.surfaces, t.surfaces);
    copy(shipped.doc, t.doc);
    plant(t);

    return t;
}

/** One planted line in a mirror's file, required to be there exactly once. */
function edit(path, from, to) {
    const src = readFileSync(path, 'utf8');

    if (src.split(from).length !== 2) {
        throw new Error(`the plant's anchor is not in ${path} exactly once: ${from.slice(0, 80)}`);
    }

    writeFileSync(path, src.replace(from, to));
}

// ── the populations, derived on the run ────────────────────────────────────────────────────────────
const FRAME_TEST = readFileSync(join(REPO, 'server', 'tests', 'Feature', 'Floor', 'TheFloorDrawsItsFrameTest.php'), 'utf8');
const SWEEP_TO = Number(FRAME_TEST.match(/private const SWEEP_TO = (\d+);/)?.[1] ?? NaN);
const DESK_LAYOUT = readFileSync(join(shipped.js, 'floor', 'desk-layout.js'), 'utf8');
const CAP = Number(DESK_LAYOUT.match(/export const STOOL_CAP = (\d+);/)?.[1] ?? NaN);
const SEATS_LEAST = Number(DESK_LAYOUT.match(/export const SIDE_TABLE_SEATS = (\d+);/)?.[1] ?? NaN);

/** Every `(install_id, seat_id)` a fixture's snapshot or delta names. */
function deskKeys(file) {
    const keys = new Set();
    const walk = (o) => {
        if (Array.isArray(o)) {
            o.forEach(walk);
        } else if (o !== null && typeof o === 'object') {
            if (typeof o.install_id === 'string' && typeof o.seat_id === 'string') {
                keys.add(`${o.install_id}/${o.seat_id}`);
            }

            Object.values(o).forEach(walk);
        }
    };

    walk(JSON.parse(readFileSync(join(REPO, 'server', 'tests', 'Feature', 'Floor', 'fixtures', file), 'utf8')));

    return [...keys].map((k) => ({ install_id: k.split('/')[0], seat_id: k.split('/').slice(1).join('/') }));
}

const KEYS = [...deskKeys('fx-snapshot-4.json'), ...deskKeys('fx-interns.json')];

/** The shipped tileset's tiles: id, kind (or null) and size, read out of the `.tsx`. */
function tilesOf(path) {
    const xml = readFileSync(path, 'utf8');

    return [...xml.matchAll(/<tile id="(\d+)">([\s\S]*?)<\/tile>/g)].map(([, id, body]) => ({
        id: Number(id),
        kind: body.match(/<property name="kind" value="([^"]*)"\/>/)?.[1] ?? null,
        w: Number(body.match(/<image [^>]*width="(\d+)"/)?.[1]),
        h: Number(body.match(/<image [^>]*height="(\d+)"/)?.[1]),
    }));
}

/** The registry, every theme module it names, and the scene's own modules, out of a tree. */
async function open(t) {
    const registry = await load(join(t.themes, 'index.js'));
    const themes = {};
    const missing = [];

    // A name the registry holds with no module to import is the API leg's to name; the other legs skip it.
    for (const name of registry.THEMES) {
        try {
            themes[name] = await load(join(t.themes, name, 'theme.js'));
        } catch {
            missing.push(name);
        }
    }

    const scene = await load(join(t.js, 'floor', 'scene.js'));
    const floorLayout = await load(join(t.js, 'floor', 'floor-layout.js'));
    const tileset = await load(join(t.js, 'floor', 'tileset.js'));

    return { t, registry, themes, missing, scene, floorLayout, tileset };
}

/**
 * Every document a theme's API names, over the populations — `[label, fn, input]` — the band's through the
 * scene's own `backWall()` and `bandDocuments()`, the default room's plane and scenery through the scene's own
 * map reading of `default.tmj` over the shipped tileset.
 */
function documents(o) {
    const out = [];
    const { backWall, bandDocuments, mapTiles, tileRegions } = o.scene;

    for (let w = 1; w <= SWEEP_TO; w += w < 600 ? 1 : 7) {
        for (const d of bandDocuments(backWall({ x: 0, y: 160, width: w }, null))) {
            out.push([`band ${w} px: ${d.fn}`, d.fn, d.input]);
        }
    }

    const tiles = tilesOf(o.t.tileset);
    const read = o.tileset.readTileset(readFileSync(o.t.tileset, 'utf8'), '/art/floor/tiles/floor-plane.tsx');
    const map = JSON.parse(readFileSync(o.t.map, 'utf8'));
    const grid = o.floorLayout.mapGrid(map);
    const cells = mapTiles(map, { x: 0, y: 0 }, () => read, 'default');
    const runs = (kind) => tileRegions(cells.filter((c) => c.kind === kind)).flatMap((r) => r.rects);
    const room = {
        w: grid.width * grid.tilewidth,
        h: grid.height * grid.tileheight,
        seed: 'default',
        desks: o.floorLayout.mapDesks(map).map((d) => ({ x: d.x, y: d.y, w: d.width, h: d.height })),
        walls: runs('wall'),
        accents: runs('accent'),
        cell: { w: grid.tilewidth, h: grid.tileheight },
        band_foot: true,
        threshold: { x: 74, y: 0 },
    };

    out.push(['the shipped default room', 'plane', room]);
    out.push(['the shipped default room, no landing, no band', 'plane', { ...room, band_foot: false, threshold: null }]);
    out.push(['a hallway grid', 'plane', { w: 1576, h: 96, seed: 'aimla', desks: [], walls: [], accents: [], cell: { w: 8, h: 8 }, band_foot: false, threshold: null }]);

    for (const c of cells.filter((c) => c.kind !== null && !o.scene.PLANE_KINDS.includes(c.kind))) {
        out.push([`default room scenery ${c.kind} at ${c.x},${c.y}`, 'scenery', { kind: c.kind, w: c.w, h: c.h, seed: 'default', index: c.index }]);
    }

    for (const tile of tiles) {
        if (tile.kind !== null && !o.scene.PLANE_KINDS.includes(tile.kind)) {
            for (const seed of ['aimla', 'beta', 'gamma']) {
                out.push([`${tile.kind} at ${tile.w} × ${tile.h}`, 'scenery', { kind: tile.kind, w: tile.w, h: tile.h, seed, index: tile.id }]);
            }
        }
    }

    for (const key of KEYS) {
        for (const fn of ['chair', 'desk', 'monitorFrame', 'deskProps']) {
            out.push([`${key.install_id}/${key.seat_id}: ${fn}`, fn, key]);
        }

        for (let seats = 0; seats <= CAP; seats++) {
            out.push([`${key.install_id}/${key.seat_id}: sideTable of ${seats}`, 'sideTable', { ...key, seats }]);
        }
    }

    return out;
}

const STATE = Object.freeze(['render_state', 'link_state', 'subagents', 'task']);

/** Two seat objects that differ in every state field — what no theme may read (input closure). */
const SEAT_A = Object.freeze({ seat_id: 'x', render_state: 'working', link_state: 'live', subagents: [], task: null });
const SEAT_B = Object.freeze({ seat_id: 'x', render_state: 'offline', link_state: 'stale', subagents: [{ call_id: 'c' }], task: { title: 't' } });

// ── the legs: each answers its defects, `[]` for a clean tree ───────────────────────────────────────

/** Totality and identity: every document draws, none throws; one input draws byte-identical markup twice, and again after a fresh module load. */
async function totality(o) {
    const defects = [];
    const docs = documents(o);

    for (const [name, theme] of Object.entries(o.themes)) {
        const again = await load(join(o.t.themes, name, 'theme.js'));

        for (const [label, fn, input] of docs) {
            let a;

            try {
                a = theme[fn](input);
            } catch (e) {
                defects.push(`${name}: ${label} threw — ${e.message}`);
                continue;
            }

            if (typeof a !== 'string' || theme[fn](input) !== a || again[fn](input) !== a) {
                defects.push(`${name}: ${label} is not one document per input — drawn twice, or after a fresh load, it differs`);
            }
        }
    }

    return defects;
}

/** Well-formed and self-contained: one standalone SVG document, strict XML, no reference that leaves it, and no word. */
function wellFormedness(o) {
    const defects = [];
    const docs = documents(o);

    for (const [name, theme] of Object.entries(o.themes)) {
        for (const [label, fn, input] of docs) {
            let d;

            try {
                d = theme[fn](input);
            } catch {
                continue;
            }

            const bad = wellFormed(d) ?? vectorDefect(d) ?? (/<script\b/.test(d) ? 'a <script>' : null)
                ?? (/<(text|tspan|foreignObject)\b/.exec(d)?.[0]?.replace('<', 'a <').concat('>') ?? null);

            if (bad !== null) {
                defects.push(`${name}: ${label} — ${bad}`);
            }
        }

        const code = readFileSync(join(o.t.themes, name, 'theme.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

        if (/\b(fetch|XMLHttpRequest|import\(|localStorage|new Image|Math\.random|Date\.now|new Date)\b/.test(code)) {
            defects.push(`${name}: the module requests, reads a clock, a random source or storage`);
        }
    }

    return defects;
}

/** Input closure: a seat object passed as an extra argument changes nothing, and the module keeps no state of its own. */
function closure(o) {
    const defects = [];
    const docs = documents(o).filter((_, i) => i % 5 === 0);

    for (const [name, theme] of Object.entries(o.themes)) {
        for (const [label, fn, input] of docs) {
            try {
                const a = theme[fn](input, SEAT_A);
                const b = theme[fn](input, SEAT_B);

                if (a !== b) {
                    defects.push(`${name}: ${fn} draws ${label} differently for a seat that differs in ${STATE.join(', ')} — a state reached the theme`);
                }
            } catch {
                // totality's to report
            }
        }

        const src = readFileSync(join(o.t.themes, name, 'theme.js'), 'utf8');
        const top = src.split('\n').filter((line) => /^(let|var)\s/.test(line));

        if (top.length > 0) {
            defects.push(`${name}: a module-level binding that can be assigned after load — ${top[0].trim()}`);
        }
    }

    return defects;
}

/** The API is the registry's: `THEMES` and the directories one set; every theme exports every `API` name and draws every kind; every shipped tile declares a kind in `KINDS`. */
function api(o) {
    const defects = [];
    const dirs = readdirSync(o.t.themes, { withFileTypes: true }).filter((e) => e.isDirectory()).map((e) => e.name);

    for (const name of o.registry.THEMES) {
        if (!dirs.includes(name)) {
            defects.push(`the registry names the theme ${name}, which has no directory`);
        }
    }

    for (const dir of dirs) {
        if (!o.registry.THEMES.includes(dir)) {
            defects.push(`the directory themes/${dir} is a theme the registry does not name`);
        }
    }

    for (const [name, theme] of Object.entries(o.themes)) {
        for (const member of o.registry.API) {
            if (!(member in theme)) {
                defects.push(`${name}: exports no ${member}`);
            }
        }

        for (const kind of o.registry.KINDS.filter((k) => !o.scene.PLANE_KINDS.includes(k))) {
            const d = theme.scenery?.({ kind, w: 72, h: 128, seed: 'kinds', index: 0 }) ?? '';

            if (!/<(path|ellipse|rect|circle)\b/.test(d.replace(/<defs>[\s\S]*?<\/defs>/, ''))) {
                defects.push(`${name}: draws nothing for the kind ${kind}`);
            }
        }

        const base = { w: 96, h: 96, seed: 'kinds', desks: [], walls: [], accents: [], cell: { w: 8, h: 8 }, band_foot: false, threshold: null };
        const bare = theme.plane?.(base);

        for (const [kind, member] of [['wall', 'walls'], ['accent', 'accents']]) {
            if (theme.plane?.({ ...base, [member]: [{ x: 8, y: 8, w: 48, h: 8 }] }) === bare) {
                defects.push(`${name}: draws nothing for the kind ${kind}`);
            }
        }
    }

    for (const tile of tilesOf(o.t.tileset)) {
        if (tile.kind === null || !o.registry.KINDS.includes(tile.kind)) {
            defects.push(`tile ${tile.id} of floor-plane.tsx declares ${tile.kind === null ? 'no kind' : `the kind ${tile.kind}, which the registry does not name`}`);
        }
    }

    return defects;
}

/** The colours of a PALETTE entry, flattened. */
const hexes = (v) => (typeof v === 'string' ? [v.toLowerCase()] : Object.values(v).flatMap(hexes));

/** The drift leg: § 10.6's house table and the house theme's `PALETTE`, piece by piece, both directions; and every colour the module writes is the palette's or the paint rules'. */
function drift(o) {
    const defects = [];
    const doc = readFileSync(o.t.doc, 'utf8');
    const start = doc.indexOf('#### The house theme, `studio`');
    const table = doc.slice(start, doc.indexOf('\n\n**What the house picture does NOT', start));
    const house = o.themes[o.registry.HOUSE_THEME];
    const rows = [...table.matchAll(/^\| [^|]*`PALETTE(?:\.([\w-]+)|\['([\w-]+)'\])`[^|]* \| [^|]* \| ([^|]*) \| [^|]* \|$/gm)];
    const seen = new Set();

    if (rows.length === 0) {
        return ['the house table names no PALETTE piece — the drift leg would compare nothing'];
    }

    for (const [, dotted, bracketed, colours] of rows) {
        const key = dotted ?? bracketed;
        const stated = new Set([...colours.matchAll(/#[0-9a-fA-F]{6}\b/g)].map((m) => m[0].toLowerCase()));
        const drawn = new Set(house.PALETTE[key] === undefined ? [] : hexes(house.PALETTE[key]));

        seen.add(key);

        if (house.PALETTE[key] === undefined) {
            defects.push(`the table's piece ${key} is not in the module's PALETTE`);
        }

        for (const c of stated) {
            if (!drawn.has(c)) {
                defects.push(`${key}: the table states ${c}, the module's PALETTE holds ${[...drawn].join(', ')}`);
            }
        }

        for (const c of drawn) {
            if (!stated.has(c)) {
                defects.push(`${key}: the module's PALETTE holds ${c}, the table states ${[...stated].join(', ')}`);
            }
        }
    }

    for (const key of Object.keys(house.PALETTE)) {
        if (!seen.has(key)) {
            defects.push(`the module's PALETTE piece ${key} has no row in the table`);
        }
    }

    const allowed = new Set([...hexes(house.PALETTE), ...hexes(house.RULES)]);
    const src = readFileSync(join(o.t.themes, o.registry.HOUSE_THEME, 'theme.js'), 'utf8').replace(/^\s*\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');

    for (const [c] of src.matchAll(/#[0-9a-fA-F]{6}\b/g)) {
        if (!allowed.has(c.toLowerCase())) {
            defects.push(`the module writes ${c}, which is neither a PALETTE colour nor a paint rule's`);
        }
    }

    return defects;
}

/** § 12's surround margin, out of its row: `[side, above, below]`. */
function margin(o) {
    const row = readFileSync(o.t.doc, 'utf8').match(/^\| \*\*Window surround margin\*\* \| \*\*(\d+)\*\* px each side, \*\*(\d+)\*\* above, \*\*(\d+)\*\* below \|/m);

    return row === null ? null : row.slice(1, 4).map(Number);
}

const meets = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
const viewBox = (d) => d.match(/viewBox="0 0 ([\d.]+) ([\d.]+)"/)?.slice(1, 3).map(Number) ?? null;

/** The band leg: each surround is its glazing or frame grown by its stated reach, none meets the clock's face, the case is at the clock's own rect, and every document is the size of its rect — at every swept width. */
function bandLeg(o) {
    const defects = [];
    const m = margin(o);
    const { backWall, bandDocuments, SURROUND } = o.scene;
    const house = o.themes[o.registry.HOUSE_THEME];

    if (m === null) {
        return ["§ 12's *Window surround margin* row was not read"];
    }

    if (m.join() !== [SURROUND.side, SURROUND.above, SURROUND.below].join()) {
        defects.push(`the scene's surround margin ${SURROUND.side}/${SURROUND.above}/${SURROUND.below} is not § 12's ${m.join('/')}`);
    }

    for (let w = 1; w <= SWEEP_TO; w++) {
        const band = backWall({ x: 0, y: 160, width: w }, null);
        const c = band.clock;
        const face = { x: c.x, y: c.y, w: c.w, h: c.h };

        band.windows.forEach((g, i) => {
            const want = { x: g.x - m[0], y: g.y - m[1], w: g.w + 2 * m[0], h: g.h + m[1] + m[2] };

            if (JSON.stringify(g.surround) !== JSON.stringify(want)) {
                defects.push(`width ${w}: window ${i}'s surround ${JSON.stringify(g.surround)} is not its glazing grown by § 12's margin`);
            }

            if (meets(g.surround, face)) {
                defects.push(`width ${w}: window ${i}'s surround meets the clock's face`);
            }
        });

        const f = band.elevator.frame;

        if (JSON.stringify(band.elevator.surround) !== JSON.stringify({ ...f, w: f.w + 18 })) {
            defects.push(`width ${w}: the elevator's surround is not its frame grown 18 px to the right`);
        }

        if (meets(band.elevator.surround, face)) {
            defects.push(`width ${w}: the elevator's surround meets the clock's face`);
        }

        if (w % 97 === 1 || w === SWEEP_TO) {
            for (const d of bandDocuments(band)) {
                if (d.fn === 'clockCase' && JSON.stringify(d.rect) !== JSON.stringify(face)) {
                    defects.push(`width ${w}: the clock's case is drawn at ${JSON.stringify(d.rect)}, not the clock's rect`);
                }

                const size = viewBox(house[d.fn](d.input));

                if (size === null || Math.abs(size[0] - d.rect.w) > 0.01 || Math.abs(size[1] - d.rect.h) > 0.01) {
                    defects.push(`width ${w}: ${d.fn}'s document is ${size?.join(' × ')}, its rect ${d.rect.w} × ${d.rect.h}`);
                }
            }
        }
    }

    return defects;
}

// ── colour arithmetic, for the ink's contrast ──────────────────────────────────────────────────────
const lin = (v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
const lum = (h) => { const [r, g, b] = rgb(h).map(lin); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const over = (top, under, alpha) => `#${rgb(top).map((v, i) => Math.round(255 * (alpha * v + (1 - alpha) * rgb(under)[i])).toString(16).padStart(2, '0')).join('')}`;
const token = (css, name) => css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6});`))?.[1] ?? null;

/** The surfaces leg: no exclusion is a surface; the ink holds over every surface and the fallback; § 12's bound and model are the tool's; the chip tool holds every reviewed pair over the surfaces `surfaces.mjs` prints. */
function surfacesLeg(o) {
    const defects = [];
    const css = readFileSync(o.t.css, 'utf8');
    const ink = token(css, 'scene-ink');
    const floor = token(css, 'scene-floor');

    for (const [name, theme] of Object.entries(o.themes)) {
        const P = theme.PALETTE;
        const excluded = new Map([
            ...hexes(P.landing ?? {}).map((c) => [c, 'the landing']),
            ...hexes(P['wall-run'] ?? {}).map((c) => [c, 'a wall run']),
            ...hexes(P.wall ?? {}).map((c) => [c, 'the band']),
            ...['bookcase', 'plant', 'floor-lamp', 'armchair', 'cushion', 'book-pile'].flatMap((k) => hexes(P[k] ?? {}).map((c) => [c, `the ${k}`])),
        ]);

        for (const [surface, colour] of Object.entries(theme.surfaces())) {
            if (excluded.has(colour.toLowerCase())) {
                defects.push(`${name}: surfaces() returns ${surface} ${colour}, which is ${excluded.get(colour.toLowerCase())}'s — an exclusion, not a floor`);
            }

            if (contrast(ink, colour) < 4.5) {
                defects.push(`${name}: --scene-ink holds ${contrast(ink, colour).toFixed(2)}:1 over ${surface} ${colour}, under 4.5`);
            }
        }
    }

    if (floor === null || contrast(ink, floor) < 4.5) {
        defects.push(`the fallback --scene-floor ${floor} holds --scene-ink at ${floor === null ? 'nothing' : contrast(ink, floor).toFixed(2)}:1, under 4.5`);
    }

    // § 12's *State chip bound* row against the tool's own constants.
    const row = readFileSync(o.t.doc, 'utf8').match(/^\| \*\*State chip bound\*\* \| \*\*([\d.]+)\*\* \|(.*)$/m);
    const model = JSON.parse(execFileSync('python3', [o.t.tool, '--repo', o.t.root, '--model'], { encoding: 'utf8' }).trim().split('\n').pop());

    if (row === null) {
        defects.push("§ 12's *State chip bound* row was not read");
    } else {
        const reviewed = row[2].match(/reviewed set \(([^)]*)\)/)?.[1].match(/`(\w+)`/g)?.map((s) => s.slice(1, -1)) ?? [];
        const opacity = row[2].match(/dimmed ([\d.]+), dark ([\d.]+), saturate ([\d.]+)/)?.slice(1, 4).map(Number) ?? [];

        if (Number(row[1]) !== model.bound) {
            defects.push(`§ 12's bound ${row[1]} is not the tool's BOUND ${model.bound}`);
        }

        if (reviewed.join() !== model.reviewed.join()) {
            defects.push(`§ 12's reviewed set ${reviewed.join(', ')} is not the tool's REVIEWED ${model.reviewed.join(', ')}`);
        }

        if (opacity.join() !== [model.opacity.dimmed, model.opacity.dark, model.saturate].join() || model.hollow_opacity !== model.opacity.dimmed) {
            defects.push(`§ 12's opacity model ${opacity.join('/')} is not the tool's ${model.opacity.dimmed}/${model.opacity.dark}/${model.saturate} with the hollow chip at ${model.hollow_opacity}`);
        }
    }

    // The chip tool's gate, over the surfaces as `surfaces.mjs` prints them from this tree.
    const out = join(o.t.root, '.surfaces.json');

    writeFileSync(out, execFileSync('node', [o.t.surfaces], { encoding: 'utf8' }));

    try {
        execFileSync('python3', [o.t.tool, '--repo', o.t.root, '--surfaces', out, '--check'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (e) {
        const lines = String(e.stderr).trim().split('\n');

        defects.push(`the chip tool's --check fails over the surfaces: ${[...lines.slice(0, 2), lines[lines.length - 1]].join(' | ')}`);
    }

    return defects;
}

/** § 10.6's contrast table's floor-dependent columns, PRINTED for every theme over its surfaces — gating nothing. */
function printScreenInk(o) {
    const css = readFileSync(o.t.css, 'utf8');
    const screens = [['lit', token(css, 'state-ink'), token(css, 'scene-monitor-on')], ['dimmed', token(css, 'scene-screen-ink-dim'), token(css, 'scene-monitor-dim')]];

    for (const [name, theme] of Object.entries(o.themes)) {
        for (const [screen, ink, fill] of screens) {
            const cells = [0.72, 0.45].map((alpha) => {
                const worst = Math.min(...Object.values(theme.surfaces()).map((s) => contrast(over(ink, s, alpha), over(fill, s, alpha))));

                return `${alpha === 0.72 ? 'dimmed' : 'dark'} ${worst.toFixed(2)}:1`;
            });

            measure(`${name}: the ${screen} screen's text, the worst over every surface — ${cells.join(', ')} (gating nothing, § 10.6)`);
        }
    }
}

const LEGS = { totality, wellFormedness, closure, api, drift, band: bandLeg, surfaces: surfacesLeg };

// ── the shipped tree ───────────────────────────────────────────────────────────────────────────────
const real = await open(shipped);

console.log(`populations: band widths 1–${SWEEP_TO} px (TheFloorDrawsItsFrameTest's bound), the shipped default room, side tables 0–${CAP} seats, `
    + `${tilesOf(shipped.tileset).length} tiles of the shipped tileset, ${KEYS.length} desk keys of fx-snapshot-4 and fx-interns; `
    + `${documents(real).length} documents per theme, themes: ${real.registry.THEMES.join(', ')}`);
check(Number.isFinite(SWEEP_TO) && SWEEP_TO > 1000 && CAP === 8 && SEATS_LEAST === 4 && KEYS.length >= 4,
    `the populations were read: sweep bound ${SWEEP_TO}, cap ${CAP}, least seats ${SEATS_LEAST}, ${KEYS.length} desk keys`);

for (const [leg, run] of Object.entries(LEGS)) {
    section(`${leg} — the shipped tree`);

    const defects = await run(real);

    check(defects.length === 0, `${leg}: ${defects.length === 0 ? 'clean' : defects.slice(0, 4).join('; ')}`);
}

printScreenInk(real);

// ── every planted RED, each required to red on the leg it names ────────────────────────────────────
section('the planted REDs — each must red its leg, naming what the RED says it names');

const THEME = (t) => join(t.themes, 'studio', 'theme.js');
const PLANTS = [
    ['the registry against its tree: a directory the registry does not name', 'api', 'themes/attic',
        (t) => cpSync(join(t.themes, 'studio'), join(t.themes, 'attic'), { recursive: true })],
    ['the registry against its tree: a name with no directory', 'api', 'loft, which has no directory',
        (t) => edit(join(t.themes, 'index.js'), "export const THEMES = Object.freeze(['studio']);", "export const THEMES = Object.freeze(['studio', 'loft']);")],
    ['the theme that reaches out', 'wellFormedness', 'chair — an <image>',
        (t) => edit(THEME(t), "return doc(pen, CHAIR.w, CHAIR.h, rimmed(", 'return doc(pen, CHAIR.w, CHAIR.h, `<image href="https://example.invalid/a.png"/>` + rimmed(')],
    ['an undecodable document: an unescaped &', 'wellFormedness', 'unescaped "&"',
        (t) => edit(THEME(t), "return doc(pen, CHAIR.w, CHAIR.h, rimmed(", "return doc(pen, CHAIR.w, CHAIR.h, '<g>a & b</g>' + rimmed(")],
    ['an undecodable document: an unclosed <g>', 'wellFormedness', 'an unclosed <g>',
        (t) => edit(THEME(t), "return doc(pen, CHAIR.w, CHAIR.h, rimmed(", "return doc(pen, CHAIR.w, CHAIR.h, '<g>' + rimmed(")],
    ['an undecodable document: a root without its xmlns', 'wellFormedness', 'no xmlns',
        (t) => edit(THEME(t), 'return `<svg xmlns="http://www.w3.org/2000/svg" viewBox=', 'return `<svg viewBox=')],
    ['the kind a theme forgot: armchair', 'api', 'studio: draws nothing for the kind armchair',
        (t) => edit(THEME(t), "} else if (kind === 'armchair') {", "} else if (kind === 'no-armchair') {")],
    ['the kind a theme forgot: a tile with no kind', 'api', 'declares no kind',
        (t) => edit(t.tileset, '<property name="kind" value="cushion"/>', '<property name="note" value="cushion"/>')],
    ['the palette drifts: the house floor back to direction A\'s', 'drift', "floor: the table states #d9c7a5, the module's PALETTE holds #c99a6b",
        (t) => edit(THEME(t), "floor: Object.freeze({ boards: '#d9c7a5' }),", "floor: Object.freeze({ boards: '#c99a6b' }),")],
    ['a surround too wide', 'band', "surround meets the clock's face",
        (t) => edit(join(t.js, 'floor', 'scene.js'), 'export const SURROUND = Object.freeze({ side: 32,', 'export const SURROUND = Object.freeze({ side: 64,')],
    ['the dark fallback: --scene-floor as the drawing\'s --ground', 'surfaces', 'the fallback --scene-floor #171a28',
        (t) => edit(t.css, '--scene-floor: #e8dcc4;', '--scene-floor: #171a28;')],
    ['text in a theme: the seat\'s id in the chair', 'wellFormedness', 'chair — a <text>',
        (t) => edit(THEME(t), "return doc(pen, CHAIR.w, CHAIR.h, rimmed(", 'return doc(pen, CHAIR.w, CHAIR.h, `<text>${seatId}</text>` + rimmed(')],
    ['state reaching a theme: the chair reads render_state', 'closure', 'chair draws',
        (t) => edit(THEME(t), "export function chair({ install_id: installId, seat_id: seatId }) {\n    const pen = new Pen();\n    const c = pick(deskKey(installId, seatId), 'theme:chair', PALETTE.chair.colours);",
            "export function chair({ install_id: installId, seat_id: seatId }, seat) {\n    const pen = new Pen();\n    const c = seat?.render_state === 'offline' ? PALETTE.chair.colours[0] : pick(deskKey(installId, seatId), 'theme:chair', PALETTE.chair.colours);")],
    ['the reviewed set shrunk: offline dropped from REVIEWED', 'surfaces', "is not the tool's REVIEWED stale, disabled",
        (t) => edit(t.tool, "REVIEWED = ['stale', 'offline', 'disabled']", "REVIEWED = ['stale', 'disabled']")],
    ['a surface that is not a floor: the landing\'s colour', 'surfaces', "the landing's — an exclusion",
        (t) => edit(THEME(t), "        darkest,\n", "        darkest,\n        doorstep: PALETTE.landing.top,\n")],
    ['the floor that breaks a chip: the house floor darkened', 'surfaces', "reviewed pair(s) on the sheet under ΔE2000",
        (t) => edit(THEME(t), "floor: Object.freeze({ boards: '#d9c7a5' }),", "floor: Object.freeze({ boards: '#8f8070' }),")],
    ['the floor too dark for the facts\' ink: the chip tool\'s ink gate', 'surfaces', "--check fails over the surfaces: check: --scene-ink holds",
        (t) => edit(THEME(t), "floor: Object.freeze({ boards: '#d9c7a5' }),", "floor: Object.freeze({ boards: '#8f8070' }),")],
    ['a draw that is not the key\'s: Math.random() for one seeded choice', 'totality', 'is not one document per input',
        (t) => edit(THEME(t), "const c = pick(deskKey(installId, seatId), 'theme:chair', PALETTE.chair.colours);", 'const c = PALETTE.chair.colours[Math.floor(Math.random() * PALETTE.chair.colours.length)];')],
];

for (const [red, leg, says, plant] of PLANTS) {
    const t = mirror(plant);

    try {
        const defects = await LEGS[leg](await open(t));
        const named = defects.find((d) => d.includes(says));

        check(named !== undefined, `RED — ${red}: the ${leg} leg ${named !== undefined ? `reds: ${named.slice(0, 160)}` : `stayed ${defects.length === 0 ? 'green' : `red for another reason: ${defects[0]}`}`}`);
    } catch (e) {
        check(false, `RED — ${red}: the plant could not be run — ${e.message}`);
    } finally {
        rmSync(t.root, { recursive: true, force: true });
    }
}

// ── the controls ───────────────────────────────────────────────────────────────────────────────────
section('controls');
const house = real.themes[real.registry.HOUSE_THEME];
const big = house.plane(documents(real).find(([label]) => label === 'the shipped default room')[2]);
const commands = [...big.matchAll(/ d="([^"]*)"/g)].reduce((n, [, d]) => n + (d.match(/[MLCQAZHVTS]/gi) ?? []).length, 0);
const shapes = (big.match(/<(path|rect|ellipse|circle)\b/g) ?? []).length;

check(wellFormed(big) === null && vectorDefect(big) === null && commands > 400 && shapes > 200,
    `CONTROL (b) — a genuinely complex document, the house floor (${shapes} shapes, ${commands} path commands, ${big.length} B), passes the self-contained leg`);
check(wellFormed('<svg xmlns="http://www.w3.org/2000/svg"><g>a & b</g></svg>') !== null, 'CONTROL — the reader refuses an unescaped "&"');
const refuses = (fn, input) => { try { house[fn](input); return false; } catch (e) { return e instanceof RangeError; } };
check(refuses('plane', { w: Infinity, h: 8, seed: 's', desks: [], walls: [], accents: [], cell: { w: 8, h: 8 }, band_foot: false, threshold: null })
    && refuses('scenery', { kind: 'plant', w: NaN, h: 8, seed: 's', index: 0 }) && refuses('band', { w: Infinity, h: 160 }),
    'CONTROL — a size that is not a finite number is refused by name, never looped over or written as NaN');

console.log(failures === 0 ? '\nALL THEME GATES PASS' : `\n${failures} THEME GATE FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
