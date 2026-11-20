/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsLightNeverClipsTest` reads the 3-D floor's look through
 * (`docs/design/plan-13033-stage2-3d-floor.md` § 14.5; `docs/design/FLOOR.md` § 12's *Floor 3-D light*, *Floor 3-D ramp*
 * and *Floor 3-D ground shade* rows; card#13033). argv[2] is the client's `wire/` directory (the shipped one or a MUTATED
 * COPY of the tree); stdin carries `{ "scenes": [ { "run", "i", "scene" } ] }` — scenes the harness's floor screen drew —
 * and stdout is `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`.
 *
 * The legs:
 * - `clip` — in every phase `floor3d/look.js` names, every channel of `upFace()` is at most 1 and at least 0.75: no face
 *   clips, and the tint changes hue, never legibility;
 * - `ramp` — with `LOOK`'s sun, the ramp's values at the up, the `+z` and the `−x` normals (a top, a front, a side) are
 *   three different numbers, read as the shader samples the ramp (`rampAt()`);
 * - `runs` — `shade-rects.js`'s `wallRuns()` joins: a straight wall of n cells is one run n cells long, an L two runs,
 *   and over every drawn scene each wall cell at the floor lies in exactly one run (the runs' area is the cells');
 * - `band` — no wall cell of a drawn scene stands along the band's foot, so the band's own shade line is not doubled (and
 *   a cell planted there, in a copy of the scene, is seen);
 * - `pool` — the kind the pool lies round is the kind the kit map stands `lampRoundFloor.glb` for;
 * - `furniture` — over every drawn scene, the shipped `floor3d/furniture.js` stands the world's furniture (its kit files
 *   parsed here by Three's own loader, the house theme's part lists) and `shadeRects()` lays the shade from what it
 *   stood: each furniture rect of a piece rule W stood (`plan-13033-stage2-3d-floor.md` § 15.3) lies, turned, inside that
 *   piece's obstacle rect reaching to its wall's face, and each lamp's pool is centred on a lamp's shade — so the shade
 *   follows the body the anchor, turn and plan moved, not the placement's `at`.
 *
 * ⚠ WHAT IT DOES NOT SEE: the page — that the renderer lights the scene with these figures and draws the shade over the
 * population is `tools/design/floor3d.browser.mjs`'s `look` row; how it looks is an eye's.
 */

import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const { scenes } = JSON.parse(readFileSync(0, 'utf8'));
const L = await import(pathToFileURL(join(wire, '..', 'floor3d', 'look.js')).href);
const { shadeRects, wallRuns } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'shade-rects.js')).href);
const { floorWorld } = await import(pathToFileURL(join(wire, '..', 'floor', 'floor-world.js')).href);
const KIT = join(REPO, 'resources', 'floor', 'models', 'kenney-furniture-kit');
const KITMAP = await import(pathToFileURL(join(KIT, 'map.js')).href);
const { FILES } = KITMAP;
// The vendored Three's examples import it as the bare `three` (the page's import map); here, the same file.
const THREE = pathToFileURL(join(wire, '..', 'vendor', 'three', 'three.module.js')).href;

registerHooks({ resolve: (specifier, context, next) => (specifier === 'three' ? { url: THREE, shortCircuit: true } : next(specifier, context)) });

const { Group } = await import(THREE);
const { createFurniture } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'furniture.js')).href);
const HOUSE = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'themes', 'studio', 'theme.js')).href);

const EPS = 1e-6;
const legs = { clip: [], ramp: [], runs: [], band: [], pool: [], furniture: [] };
const printed = { upFace: {}, ramp: {}, runs: {}, band: {}, furniture: { rects: 0, pools: 0 } };

// ── clip: the up-facing face's light in every phase ──
for (const phase of Object.keys(L.LOOK.phases)) {
    const up = L.upFace(phase);

    printed.upFace[phase] = up.map((c) => Number(c.toFixed(4)));

    if (up.some((c) => c > 1 + EPS || c < 0.75)) {
        legs.clip.push(`${phase}: the up-facing face is lit at [${up.map((c) => c.toFixed(3)).join(', ')}] — not every channel within 0.75 and 1`);
    }
}

// ── ramp: a top, a front and a side on three ramp values ──
const [x, y, z] = L.sunDirection();
const faces = { up: L.rampAt(y), front: L.rampAt(z), left: L.rampAt(-x) };

printed.ramp = faces;
if (new Set(Object.values(faces)).size !== 3) {
    legs.ramp.push(`the ramp gives the top, the front and the side ${JSON.stringify(faces)} — not three different values`);
}

// ── runs: the join, on cells laid out here ──
const cell = (cx, cz) => ({ box: { x: cx * 0.1, y: 0, z: cz * 0.1, w: 0.1, h: 1, d: 0.1 } });
const n = 7;
const straight = wallRuns(Array.from({ length: n }, (_, i) => cell(i, 0)));
const column = wallRuns(Array.from({ length: n }, (_, i) => cell(0, i)));
const ell = wallRuns([...Array.from({ length: n }, (_, i) => cell(i, 0)), ...Array.from({ length: 4 }, (_, i) => cell(0, i + 1))]);

printed.runs.laid = { straight: straight.length, column: column.length, ell: ell.length };
if (straight.length !== 1 || Math.abs(straight[0].w - n * 0.1) > EPS) legs.runs.push(`a straight row of ${n} cells is ${straight.length} runs (${JSON.stringify(straight)}) — not one run ${n} cells long`);
if (column.length !== 1 || Math.abs(column[0].d - n * 0.1) > EPS) legs.runs.push(`a straight column of ${n} cells is ${column.length} runs — not one run ${n} cells deep`);
if (ell.length !== 2) legs.runs.push(`an L of ${n} cells and 4 is ${ell.length} runs — not two`);

// ── runs and band, over the drawn scenes' worlds ──
for (const { run, scene } of scenes) {
    const world = floorWorld(scene);
    const floor = world.walls.filter((w) => Math.abs(w.box.y) < EPS);
    const runs = wallRuns(world.walls);
    const cells = floor.reduce((s, w) => s + w.box.w * w.box.d, 0);
    const area = runs.reduce((s, r) => s + r.w * r.d, 0);

    printed.runs[run] = { cells: floor.length, runs: runs.length };
    if (Math.abs(cells - area) > EPS) legs.runs.push(`[${run}] the runs cover ${area.toFixed(4)} square units, the wall cells at the floor ${cells.toFixed(4)} — a cell in no run or in two`);
    if (floor.length > 0 && runs.length >= floor.length) legs.runs.push(`[${run}] ${floor.length} wall cells joined into ${runs.length} runs — nothing joined`);

    if (world.band === null) {
        legs.band.push(`CONTROL: [${run}] has no band — the band's foot was not read`);
        continue;
    }

    const alongBand = (w) => w.walls.filter((c) => Math.abs(c.box.y) < EPS && c.box.z < w.band.z + c.box.d / 2);
    const along = alongBand(world);

    printed.band[run] = { z: world.band.z, along: along.length };
    if (along.length > 0) legs.band.push(`[${run}] ${along.length} wall cells stand along the band's foot — the band's shade line would double theirs`);

    // The control: a cell planted in the footprint's top row, along the band, is seen.
    const [first] = scene.walls;
    const planted = first === undefined ? null : floorWorld({ ...scene, walls: [...scene.walls, { ...first, y: scene.band.y + scene.band.h }] });

    if (planted === null || alongBand(planted).length !== 1) legs.band.push(`CONTROL: [${run}] a wall cell planted along the band's foot was not seen`);
}

if (scenes.length === 0) legs.runs.push('CONTROL: no scene was read');

// ── furniture: the shade under each body rule W stood, and each lamp's pool on its lamp ──
/** The world's furniture as the page stands it in the house theme, once every kit file it asks for has answered. */
async function stoodFurniture(world) {
    let answered = 0;
    const furniture = createFurniture(new Group(), {
        map: KITMAP,
        theme: 'studio',
        palette: HOUSE.PALETTE,
        piece3d: HOUSE.piece3d,
        dressing3d: HOUSE.dressing3d,
        ready: () => { answered++; },
        fetch: async (url) => {
            const b = readFileSync(join(KIT, url.split('/').pop()));

            return b.buffer.slice(b.byteOffset, b.byteOffset + b.length);
        },
    });

    furniture.rebuild(world);

    for (let k = 0; k < 500 && answered < furniture.requested().length; k++) {
        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    if (answered < furniture.requested().length) {
        legs.furniture.push(`CONTROL: ${furniture.requested().length - answered} kit file(s) never answered — the furniture was not stood`);
    }

    const footprints = furniture.footprints();

    furniture.dispose();

    return footprints;
}

for (const { run, scene } of scenes) {
    const world = floorWorld(scene);
    const footprints = await stoodFurniture(world);
    const rects = shadeRects(world, footprints, [], 'night');
    const top = scene.band === null ? scene.extent.y : scene.band.y + scene.band.h;
    const D = (scene.extent.y + scene.extent.h - top) / 100;
    // World units back to scene px — the world's frame (`floor-world.js`'s `worldOf()`), inverted.
    const px = (x) => (x + scene.extent.w / 200) * 100 + scene.extent.x;
    const py = (z) => (z + D / 2) * 100 + top;
    // Each piece rule W stood, its obstacle rect reaching to its wall's face, scene px.
    const reach = scene.scenery.filter((s) => s.against !== undefined).map((s) => ({
        kind: s.kind,
        x0: s.against === 'left' ? Math.min(s.x, s.face) : s.x,
        x1: s.against === 'right' ? Math.max(s.x + s.w, s.face) : s.x + s.w,
        y0: s.against === 'back' ? Math.min(s.y, s.face) : s.y,
        y1: s.y + s.h,
    }));
    const inRect = (q, r) => q.x >= r.x0 - EPS && q.x <= r.x1 + EPS && q.y >= r.y0 - EPS && q.y <= r.y1 + EPS;
    const corners = (r) => [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([u, v]) => ({
        x: px(r.x + u * r.hw * Math.cos(r.turn) + v * r.hd * Math.sin(r.turn)),
        y: py(r.z - u * r.hw * Math.sin(r.turn) + v * r.hd * Math.cos(r.turn)),
    }));
    const covered = new Set(reach.map((r) => r.kind));

    for (const r of rects.filter((q) => q.kind === 'furniture')) {
        const centre = { x: px(r.x), y: py(r.z) };
        const own = reach.filter((q) => inRect(centre, q));

        if (own.length === 0) {
            // a rect of a kind rule W stood, centred in no such piece's rect, is a shade beside its body
            const kind = footprints.find((f) => Math.abs(f.at.x - r.x) < EPS && Math.abs(f.at.z - r.z) < EPS)?.kind;

            if (covered.has(kind)) {
                legs.furniture.push(`[${run}] a ${kind}'s shade is centred at ${centre.x.toFixed(1)},${centre.y.toFixed(1)}, in no ${kind}'s obstacle rect reaching to its wall`);
            }

            continue;
        }

        printed.furniture.rects++;

        if (!own.some((q) => corners(r).every((c) => inRect(c, q)))) {
            legs.furniture.push(`[${run}] the shade centred at ${centre.x.toFixed(1)},${centre.y.toFixed(1)} reaches past its piece's obstacle rect to the wall: ${corners(r).map((c) => `${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' ')}`);
        }
    }

    // A pool is centred on a lamp's shade — the lamp's body, which the rects above hold under it.
    const lamps = rects.filter((q) => q.kind === 'furniture' && footprints.some((f) => f.kind === L.LOOK.shade.pool.kind && Math.abs(f.at.x - q.x) < EPS && Math.abs(f.at.z - q.z) < EPS));

    for (const r of rects.filter((q) => q.kind === 'pool')) {
        printed.furniture.pools++;

        if (!lamps.some((q) => Math.abs(q.x - r.x) < EPS && Math.abs(q.z - r.z) < EPS)) {
            legs.furniture.push(`[${run}] a lamp's pool lies at ${px(r.x).toFixed(1)},${py(r.z).toFixed(1)}, not at the centre of any lamp's body`);
        }
    }

    if (reach.length > 0 && printed.furniture.rects === 0) {
        legs.furniture.push(`CONTROL: [${run}] no shade rect lay under a piece rule W stood — the leg read nothing`);
    }
}

if (printed.furniture.pools === 0) {
    legs.furniture.push('CONTROL: no lamp pool was laid — the pool was not read against its lamp');
}

// ── pool: the lamp's kind ──
if (FILES[L.LOOK.shade.pool.kind] !== 'lampRoundFloor.glb') {
    legs.pool.push(`the pool lies round '${L.LOOK.shade.pool.kind}', which the kit map stands ${FILES[L.LOOK.shade.pool.kind] ?? 'nothing'} for — not lampRoundFloor.glb`);
}

process.stdout.write(JSON.stringify({ legs, printed }));
