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
 * - `pool` — the kind the pool lies round is the kind the kit map stands `lampRoundFloor.glb` for.
 *
 * ⚠ WHAT IT DOES NOT SEE: the page — that the renderer lights the scene with these figures and draws the shade over the
 * population is `tools/design/floor3d.browser.mjs`'s `look` row; how it looks is an eye's.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const { scenes } = JSON.parse(readFileSync(0, 'utf8'));
const L = await import(pathToFileURL(join(wire, '..', 'floor3d', 'look.js')).href);
const { wallRuns } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'shade-rects.js')).href);
const { floorWorld } = await import(pathToFileURL(join(wire, '..', 'floor', 'floor-world.js')).href);
const { FILES } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'models', 'kenney-furniture-kit', 'map.js')).href);

const EPS = 1e-6;
const legs = { clip: [], ramp: [], runs: [], band: [], pool: [] };
const printed = { upFace: {}, ramp: {}, runs: {}, band: {} };

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

// ── pool: the lamp's kind ──
if (FILES[L.LOOK.shade.pool.kind] !== 'lampRoundFloor.glb') {
    legs.pool.push(`the pool lies round '${L.LOOK.shade.pool.kind}', which the kit map stands ${FILES[L.LOOK.shade.pool.kind] ?? 'nothing'} for — not lampRoundFloor.glb`);
}

process.stdout.write(JSON.stringify({ legs, printed }));
