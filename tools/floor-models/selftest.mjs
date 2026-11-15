#!/usr/bin/env node
// THE KIND MAP's TOTALITY — `resources/floor/models/kenney-furniture-kit/map.js` held to the population it owes
// (card#13033 stage 2; `docs/design/plan-13033-stage2-3d-floor.md` § 9 slice 1). Node, no dependencies, no network.
//
//   node tools/floor-models/selftest.mjs
//
// It lives outside `resources/` because every file there owes a provenance row, and a test is not an asset.
//
// THE POPULATION IS DERIVED ON THE RUN, never restated: every standing kind is the theme registry's `KINDS` less
// `floor/map-tiles.js`'s `PLANE_KINDS`, and every desk part is the registry's `API` less the members that are not
// furniture (`NOT_FURNITURE` below, each with its reason) — so a kind or a part added to the registry owes the map a
// disposition the day it lands, and an API member nobody classified reds rather than slipping out of the population.
//
// The legs — each answers its defects, `[]` for a clean tree:
// - every member is in EXACTLY ONE of the map's `FILES`, `PIECE3D` and `MISSING`, and the map names nothing else;
// - every `FILES` value is a `.glb` beside the map, and every `.glb` beside it is a `FILES` value;
// - a non-empty `PIECE3D` has the house theme exporting `piece3d`, which draws every kind it lists (a part list, each
//   part coloured);
// - every `DRESSED` kind is a `FILES` kind, and every theme of the registry exports its own `dressing3d`, which dresses
//   each at its tile's size: a table top above the floor and under the tile's top, a depth, and a part list of coloured parts standing in
//   the tile's height;
// - the house theme's restroom door set into a side wall (each facing, at the shipped door's size and the side plates of
//   `floor/map-tiles.js`): its flag plate at the near jamb's end, out from the wall face and at the plate's heights, hung
//   on a bracket that runs from inside the wall face out along it, and the pictogram standing proud of both its faces;
// - `PAINT` names, for every `FILES` kind, exactly the materials its file carries (read off the `.glb`'s own JSON
//   chunk), each mapped for every theme of the registry to a `PALETTE` path holding a colour in that theme.
//
// ⛔ EVERY LEG IS WATCHED FAILING ON EVERY RUN: each plant below perturbs one input of the shipped tree's reading and
// the leg must red naming what the plant broke. A plant that stays green fails this run.

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const KIT_DIR = join(REPO, 'resources', 'floor', 'models', 'kenney-furniture-kit');
const load = (...parts) => import(pathToFileURL(join(REPO, ...parts)).href);

// The theme API members that are NOT furniture a 3-D desk stands: the room's architecture (first-party by the
// operator's answer Q1 A), the scenery dispatcher (its kinds are `KINDS`, counted there), and the two data exports.
/** A tile the dressing leg asks each theme to dress: the tall snack table's, 72 × 96 picture px, in world units. */
const { UNIT } = await load('server', 'public', 'js', 'floor', 'floor-world.js');
const TILE = Object.freeze({ w: 72 / UNIT, h: 96 / UNIT, px: 1 / UNIT });

const NOT_FURNITURE = Object.freeze(['band', 'windowSurround', 'elevatorSurround', 'clockCase', 'plane', 'scenery', 'PALETTE', 'surfaces']);

/**
 * A side door's `piece3d` inputs (`floor3d/furniture-placements.js`'s `inputsOf()` of the world's piece), derived from
 * the shipped tile and the side plates in the drawing box's px — the doorway strip's foot at the box's foot line and its
 * edge on the wall face — never restated.
 */
function sideInputs({ RESTROOM_DOOR, RESTROOM_PLATES }, facing) {
    const { plate, doorway } = RESTROOM_PLATES.side;
    const foot = doorway.y + doorway.h;

    return {
        w: RESTROOM_DOOR.w / UNIT, h: RESTROOM_DOOR.h / UNIT, px: 1 / UNIT, facing, wall: doorway.w / UNIT,
        flag: { gap: (doorway.x - (plate.x + plate.w)) / UNIT, out: plate.w / UNIT, y: (foot - (plate.y + plate.h)) / UNIT, h: plate.h / UNIT },
    };
}

let failures = 0;
const check = (cond, what) => { console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${what}`); if (!cond) failures++; };

/** The shipped tree's reading: everything a leg judges, as plain data a plant can perturb. */
async function reading() {
    const [registry, tiles, map] = await Promise.all([
        load('resources', 'floor', 'themes', 'index.js'),
        load('server', 'public', 'js', 'floor', 'map-tiles.js'),
        load('resources', 'floor', 'models', 'kenney-furniture-kit', 'map.js'),
    ]);
    const house = await load('resources', 'floor', 'themes', registry.HOUSE_THEME, 'theme.js');
    const modules = Object.fromEntries(await Promise.all(registry.THEMES.map(async (t) => [t, await load('resources', 'floor', 'themes', t, 'theme.js')])));
    const themes = Object.fromEntries(Object.entries(modules).map(([t, m]) => [t, m.PALETTE]));
    const glb = (file) => {
        const b = readFileSync(join(KIT_DIR, file));

        return JSON.parse(b.subarray(20, 20 + b.readUInt32LE(12)).toString('utf8'));
    };

    return {
        kinds: [...registry.KINDS],
        planeKinds: [...tiles.PLANE_KINDS],
        api: [...registry.API],
        houseHasPiece3d: typeof house.piece3d === 'function',
        // what the house theme's `piece3d` answers for each kind PIECE3D lists, at a tile's size
        drawn: Object.fromEntries(map.PIECE3D.map((k) => [k, typeof house.piece3d === 'function' ? house.piece3d(k, { w: 0.6, h: 1 }) : null])),
        // the restroom door set into a side wall, each facing, at the inputs the floor world hands it
        sideDoor: Object.fromEntries(['right', 'left'].map((facing) => [facing, typeof house.piece3d === 'function' ? house.piece3d('restroom-door', sideInputs(tiles, facing)) : null])),
        sideInputs: sideInputs(tiles, 'right'),
        // what each theme's own `dressing3d` answers for each kind DRESSED lists, at a tall snack table's tile — `null`
        // for a theme that exports none
        dressings: Object.fromEntries(Object.entries(modules).map(([t, m]) => [t, typeof m.dressing3d === 'function'
            ? Object.fromEntries(map.DRESSED.map((k) => [k, m.dressing3d(k, TILE)]))
            : null])),
        dressedKinds: [...map.DRESSED],
        materials: Object.fromEntries(Object.entries(map.FILES).map(([k, f]) => [k, (glb(f).materials ?? []).map((m) => m.name)])),
        paint: structuredClone(map.PAINT),
        palettes: themes,
        files: { ...map.FILES },
        piece3d: [...map.PIECE3D],
        missing: [...map.MISSING],
        beside: readdirSync(KIT_DIR).filter((f) => f.endsWith('.glb')),
    };
}

/** The population the map owes a disposition: every standing kind, then every desk part. */
function population(r) {
    return {
        standing: r.kinds.filter((k) => !r.planeKinds.includes(k)),
        parts: r.api.filter((m) => !NOT_FURNITURE.includes(m)),
    };
}

function totalityDefects(r) {
    const { standing, parts } = population(r);
    const owed = [...standing, ...parts];
    const lists = { FILES: Object.keys(r.files), PIECE3D: r.piece3d, MISSING: r.missing };
    const defects = [];

    for (const member of owed) {
        const homes = Object.entries(lists).filter(([, names]) => names.includes(member)).map(([list]) => list);

        if (homes.length === 0) {
            defects.push(`\`${member}\` is in none of FILES, PIECE3D and MISSING — the map owes it a disposition`);
        } else if (homes.length > 1) {
            defects.push(`\`${member}\` is in ${homes.join(' and ')} — one disposition each, or the renderer has to guess`);
        }
    }

    for (const [list, names] of Object.entries(lists)) {
        for (const name of names) {
            if (!owed.includes(name)) {
                defects.push(`${list} names \`${name}\`, which is neither a standing kind nor a desk part of the registry`);
            }
        }
    }

    return defects;
}

function fileDefects(r) {
    const named = Object.values(r.files);
    const defects = [];

    for (const [kind, file] of Object.entries(r.files)) {
        if (!file.endsWith('.glb') || !r.beside.includes(file)) {
            defects.push(`FILES maps \`${kind}\` to \`${file}\`, which is not a .glb beside the map`);
        }
    }

    for (const file of r.beside) {
        if (!named.includes(file)) {
            defects.push(`\`${file}\` ships beside the map and no kind is mapped to it — only the files the map needs are committed`);
        }
    }

    return defects;
}

function piece3dDefects(r) {
    if (r.piece3d.length > 0 && !r.houseHasPiece3d) {
        return [`PIECE3D lists ${r.piece3d.join(', ')} and the house theme exports no \`piece3d\` to draw them`];
    }

    return r.piece3d.filter((k) => !Array.isArray(r.drawn[k]) || r.drawn[k].length === 0 || !r.drawn[k].every((p) => /^#[0-9a-f]{6}$/i.test(p.colour)))
        .map((k) => `PIECE3D lists \`${k}\` and the house theme's \`piece3d\` draws it as ${JSON.stringify(r.drawn[k])?.slice(0, 60)} — not a part list of coloured parts`);
}

function dressingDefects(r) {
    const defects = r.dressedKinds.filter((k) => !Object.hasOwn(r.files, k)).map((k) => `DRESSED lists \`${k}\`, which FILES does not map to a kit piece to dress`);

    // every theme dresses its own tables: a dressed kind is drawn differently by each theme's 2-D floor
    for (const [theme, dressed] of Object.entries(r.dressings)) {
        if (r.dressedKinds.length > 0 && dressed === null) {
            defects.push(`DRESSED lists ${r.dressedKinds.join(', ')} and ${theme} exports no \`dressing3d\` — its 3-D tables would carry the house theme's things`);
            continue;
        }

        for (const k of r.dressedKinds) {
            const a = dressed[k];
            const parts = a?.parts;

            if (!(a?.top > 0 && a.top < TILE.h) || !(a.d > 0)) {
                defects.push(`DRESSED lists \`${k}\` and ${theme}'s \`dressing3d\` answers top ${a?.top} and depth ${a?.d} — not a table top inside the tile's height, nor a depth`);
            } else if (!Array.isArray(parts) || parts.length === 0 || !parts.every((p) => /^#[0-9a-f]{6}$/i.test(p.colour) && p.at[1] >= 0 && p.at[1] <= TILE.h)) {
                defects.push(`DRESSED lists \`${k}\` and ${theme}'s \`dressing3d\` dresses it with ${JSON.stringify(parts)?.slice(0, 60)} — not a part list of coloured parts inside the tile's height`);
            }
        }
    }

    return defects;
}

/** A `PALETTE` path's colour — `row.key` or `row.key.index` — or `undefined`. */
const colourAt = (palette, path) => path.split('.').reduce((v, step) => (v === undefined || v === null ? undefined : v[step]), palette);

function paintDefects(r) {
    const defects = [];

    for (const [kind, materials] of Object.entries(r.materials)) {
        const mapped = Object.keys(r.paint[kind] ?? {});

        for (const m of materials.filter((x) => !mapped.includes(x))) {
            defects.push(`\`${kind}\`'s file carries the material \`${m}\` and PAINT does not recolour it`);
        }

        for (const m of mapped.filter((x) => !materials.includes(x))) {
            defects.push(`PAINT recolours \`${kind}\`'s \`${m}\`, a material its file does not carry`);
        }

        for (const m of mapped) {
            for (const [theme, palette] of Object.entries(r.palettes)) {
                const path = r.paint[kind][m][theme];
                const colour = typeof path === 'string' ? colourAt(palette, path) : undefined;

                if (typeof colour !== 'string' || !/^#[0-9a-f]{6}$/i.test(colour)) {
                    defects.push(`PAINT maps \`${kind}\`'s \`${m}\` in ${theme} to ${JSON.stringify(path)}, which is no colour of that theme's PALETTE`);
                }
            }
        }
    }

    for (const kind of Object.keys(r.paint).filter((k) => !Object.hasOwn(r.files, k))) {
        defects.push(`PAINT recolours \`${kind}\`, which FILES does not map to a file`);
    }

    return defects;
}

/** A part's box in the piece's frame — `[min, max]` per axis — for the shapes a flag is drawn with. */
function extent(p) {
    const half = p.shape === 'box' ? p.params.size.map((v, k) => v * p.scale[k] / 2)
        : p.shape === 'sphere' ? p.scale.map((v) => v * p.params.r)
            : p.shape === 'cone' ? [p.params.radius * p.scale[0], p.params.height / 2, p.params.radius * p.scale[2]] : null;

    if (half === null) {
        return null;
    }

    const centre = p.shape === 'cone' ? [p.at[0], p.at[1] + p.params.height / 2, p.at[2]] : p.at;

    return centre.map((c, k) => [c - half[k], c + half[k]]);
}

function flagDefects(r) {
    const { w, flag } = r.sideInputs;
    const E = 1e-9;
    const defects = [];

    for (const [facing, parts] of Object.entries(r.sideDoor)) {
        const end = (facing === 'right' ? 1 : -1) * w / 2;
        const boxes = (parts ?? []).map(extent).filter((b) => b !== null);
        const atEnd = (b) => b[0][0] < end + E && b[0][1] > end - E;
        const [top, bottom] = [flag.y + flag.h, flag.y];
        const plate = boxes.find((b) => atEnd(b) && Math.abs(b[2][0] - flag.gap) < E && Math.abs(b[2][1] - flag.gap - flag.out) < E && Math.abs(b[1][0] - bottom) < E && Math.abs(b[1][1] - top) < E);

        if (plate === undefined) {
            defects.push(`the ${facing} side door draws no flag plate at its near jamb's end (x ${end}), ${flag.gap}–${flag.gap + flag.out} out from the wall face and ${bottom}–${top} up`);
            continue;
        }

        if (!boxes.some((b) => atEnd(b) && b[2][0] < E && b[2][1] > flag.gap + flag.out - E && b[1][0] > bottom - E && b[1][1] < top + E)) {
            defects.push(`the ${facing} side door's flag hangs on no bracket from inside the wall face out along it — it floats ${flag.gap} units off the wall`);
        }

        const proud = boxes.filter((b) => b !== plate && b[0][0] < plate[0][0] - E && b[0][1] > plate[0][1] + E && b[2][0] > flag.gap && b[2][1] < flag.gap + flag.out && b[1][0] > bottom && b[1][1] < top);

        if (proud.length < 2) {
            defects.push(`the ${facing} side door's pictogram stands proud of both the flag's faces in ${proud.length} part(s), not its two figures'`);
        }
    }

    return defects;
}

const LEGS = { totality: totalityDefects, files: fileDefects, piece3d: piece3dDefects, flag: flagDefects, dressing: dressingDefects, paint: paintDefects };

const shipped = await reading();
const { standing, parts } = population(shipped);

console.log('the kind map — the shipped tree');
check(standing.length > 0 && parts.length > 0, `the population was read: ${standing.length} standing kind(s), ${parts.length} desk part(s) — derived from the registry`);
for (const [leg, defects] of Object.entries(LEGS)) {
    const found = defects(shipped);

    check(found.length === 0, `${leg}: clean${found.length ? ` — ${found.join('; ')}` : ''}`);
}
console.log(`  MEASURED FILES ${Object.keys(shipped.files).length} · PIECE3D ${shipped.piece3d.length} · MISSING ${shipped.missing.length} (${shipped.missing.join(', ')}) · DRESSED ${shipped.dressedKinds.length} — a MISSING kind is one nothing draws in 3-D`);

// ── the plants: each perturbs ONE input and its leg must red, naming the defect ─────────────────────────────
const plant = (patch) => ({ ...structuredClone(shipped), ...patch(structuredClone(shipped)) });
const firstFile = Object.keys(shipped.files)[0];
const PLANTS = [
    ['totality', 'a standing kind dropped from the map', (r) => ({ files: Object.fromEntries(Object.entries(r.files).filter(([k]) => k !== 'bookcase')) }), 'is in none of'],
    ['totality', 'a desk part dropped from the map', (r) => ({ files: Object.fromEntries(Object.entries(r.files).filter(([k]) => k !== 'chair')) }), 'is in none of'],
    ['totality', 'a kind in FILES and MISSING both', (r) => ({ missing: [...r.missing, firstFile] }), 'is in FILES and MISSING'],
    ['totality', 'a key nobody owes — a stale kind', (r) => ({ missing: [...r.missing, 'sofa'] }), 'neither a standing kind nor a desk part'],
    ['totality', 'a NEW registry kind with no disposition — the population is derived, not listed', (r) => ({ kinds: [...r.kinds, 'piano'] }), '`piano` is in none of'],
    ['totality', 'a NEW theme API member nobody classified', (r) => ({ api: [...r.api, 'lampshade'] }), '`lampshade` is in none of'],
    ['files', 'a FILES entry naming a file not beside the map', (r) => ({ files: { ...r.files, [firstFile]: 'nobodyShipsThis.glb' } }), 'not a .glb beside the map'],
    ['files', 'a .glb shipped that no kind is mapped to', (r) => ({ beside: [...r.beside, 'bathtub.glb'] }), 'no kind is mapped to it'],
    ['piece3d', 'PIECE3D naming kinds while the house theme exports no piece3d', () => ({ houseHasPiece3d: false }), 'exports no `piece3d`'],
    ['piece3d', 'a PIECE3D kind the house theme\'s piece3d does not draw', (r) => ({ drawn: { ...r.drawn, globe: null } }), '`globe` and the house theme'],
    ['flag', 'the flag drawn at the far jamb', (r) => ({ sideDoor: { ...r.sideDoor, right: r.sideDoor.right.map((p) => ({ ...p, at: [-p.at[0], p.at[1], p.at[2]] })) } }), 'no flag plate at its near jamb'],
    ['flag', 'the bracket dropped — a flag floating off the wall', (r) => ({ sideDoor: { ...r.sideDoor, left: r.sideDoor.left.filter((p) => extent(p)?.[2][0] >= 0) } }), 'hangs on no bracket'],
    ['flag', 'the pictogram flat in the plate', (r) => ({ sideDoor: { ...r.sideDoor, right: r.sideDoor.right.map((p) => (p.shape === 'box' ? p : { ...p, scale: [0, 1, 1] })) } }), 'pictogram stands proud'],
    ['dressing', 'a DRESSED kind with no kit piece to dress', (r) => ({ dressedKinds: [...r.dressedKinds, 'globe'] }), '`globe`, which FILES does not map'],
    ['dressing', 'DRESSED naming kinds while the house theme exports no dressing3d', (r) => ({ dressings: { ...r.dressings, studio: null } }), 'studio exports no `dressing3d`'],
    ['dressing', 'a second theme with no dressing3d of its own', (r) => ({ dressings: { ...r.dressings, cottage: null } }), 'cottage exports no `dressing3d`'],
    ['dressing', 'a dressing whose table top is the tile\'s whole height', (r) => ({ dressings: { ...r.dressings, cottage: { ...r.dressings.cottage, 'snack-table': { ...r.dressings.cottage['snack-table'], top: TILE.h } } } }), 'not a table top inside'],
    ['dressing', 'a dressing with no parts', (r) => ({ dressings: { ...r.dressings, studio: { ...r.dressings.studio, 'snack-table': { ...r.dressings.studio['snack-table'], parts: [] } } } }), 'not a part list'],
    ['paint', 'a material of a file PAINT does not recolour', (r) => ({ paint: { ...r.paint, desk: { wood: r.paint.desk.wood } } }), 'carries the material `metal`'],
    ['paint', 'a recolour naming no colour of a theme', (r) => ({ paint: { ...r.paint, desk: { ...r.paint.desk, wood: { ...r.paint.desk.wood, cottage: 'desk.wood' } } } }), 'in cottage to "desk.wood"'],
    ['paint', 'a theme added to the registry with no recolour', (r) => ({ palettes: { ...r.palettes, attic: r.palettes.studio } }), 'in attic to undefined'],
];

console.log('\nthe plants — each must red its leg, naming the defect');
for (const [leg, what, patch, names] of PLANTS) {
    const found = LEGS[leg](plant(patch));

    check(found.some((d) => d.includes(names)), `${leg} reds on ${what}${found.length ? '' : ' — it stayed GREEN'}`);
}

console.log(`\n${failures === 0 ? 'ALL PASS' : `${failures} FAILED`}`);
process.exit(failures === 0 ? 0 : 1);
