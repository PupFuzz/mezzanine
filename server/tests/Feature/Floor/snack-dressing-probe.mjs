/**
 * The probe `Tests\Feature\Floor\TheSnackTablesAreDressedIn3dTest` reads the 3-D floor's snack tables through
 * (`docs/design/FLOOR.md` § 10.6 item 1's `dressing3d`, § 6.6 item 2; card#13033). argv[2] is the client's `wire/`
 * directory (the shipped one or a MUTATED COPY of the tree); stdin carries `{ "scenes": [ { "run", "i", "scene" } ],
 * "themes": <dir> | null }` — scenes the harness's floor screen drew, and the theme tree to read every theme from (the
 * repository's unless a plant hands a copy) — and stdout is `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`.
 *
 * ⛔ IT DRIVES THE SHIPPED MODULES AND JUDGES WHAT THEY STAND: `floor/floor-world.js` lifts each scene, the shipped
 * `floor3d/furniture-placements.js` places its furniture with each registry theme's own `dressing3d()` in turn — every
 * scene's pieces judged as a floor in that theme would stand them — and the dressing's parts are built by the shipped
 * `floor3d/part-geometry.js` into the geometry the page draws, whose boxes are measured here.
 *
 * The legs, over every theme and every `DRESSED` piece of every scene:
 * - `themes` — the theme dresses its own snack tables (the 3-D floor shows what its 2-D floor draws): it exports
 *   `dressing3d`, which dresses every `DRESSED` kind;
 * - `palette` — every part is coloured from that theme's own `PALETTE['snack-table']`, the colours its 2-D table is
 *   drawn in;
 * - `placed` — the kit's table stands at the piece, as wide as its tile and fitted to its dressing's top and depth, and
 *   its dressing stands at the same foot at the tile's size — one of each per piece;
 * - `waist` — the table's top is waist-high to the body that visits it (§ 6.6 item 2): between two fifths and three
 *   fifths of the drawn height of the floor's seats at the tall table and of its interns at the low one;
 * - `rests` — the dressing is what stands ON that top: one part lies on it, nothing reaches below half its height (the
 *   cloth's hem hangs over the front edge, no more), nothing stands above the tile's drawn height, and nothing lies
 *   outside the table's plan by more than the cloth's overhang.
 *
 * ⚠ WHAT IT DOES NOT SEE: whether the things on the table read as a coffee maker and donuts — the browser leg's pictures
 * and an eye own that — and the kit table's own top surface, which is fitted to the box of a file nobody measures here
 * (its top is its box's, as every kit piece's).
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const wire = process.argv[2];
const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const { scenes, themes } = JSON.parse(readFileSync(0, 'utf8'));
const W = await import(pathToFileURL(join(wire, '..', 'floor', 'floor-world.js')).href);
const { placementsOf, dressingOf, inputsOf } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'furniture-placements.js')).href);
const { partGeometry, DETAIL } = await import(pathToFileURL(join(wire, '..', 'floor3d', 'part-geometry.js')).href);
const { DRESSED } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'models', 'kenney-furniture-kit', 'map.js')).href);
const THEMES = themes ?? join(REPO, 'resources', 'floor', 'themes');
const registry = await import(pathToFileURL(join(THEMES, 'index.js')).href);
const modules = Object.fromEntries(await Promise.all(registry.THEMES.map(async (t) => [t, await import(pathToFileURL(join(THEMES, t, 'theme.js')).href)])));
/** Every colour a palette row holds, its lists flattened. */
const coloursOf = (row) => new Set(Object.values(row ?? {}).flat());

const EPS = 1e-6;
const near = (a, b) => Math.abs(a - b) < EPS;
const legs = { themes: [], palette: [], placed: [], waist: [], rests: [] };
const printed = { scenes: scenes.length, themes: [...registry.THEMES], pieces: {}, waist: {}, top: {}, parts: {} };
/** Which body visits each dressed kind (`floor/scene.js`'s `SNACK_TABLES`), read off the world's desks. */
const VISITOR = { 'snack-table': 'seat', 'snack-table-low': 'intern' };

for (const theme of registry.THEMES) {
    const own = modules[theme];
    const dressing3d = typeof own.dressing3d === 'function' ? own.dressing3d : null;
    const colours = coloursOf(own.PALETTE['snack-table']);

    if (dressing3d === null || DRESSED.some((kind) => dressing3d(kind, inputsOf({ w: 1, h: 1 })) === null)) {
        legs.themes.push(`${theme} does not dress every DRESSED kind with a dressing3d of its own — its 3-D snack tables would carry another theme's things`);
        continue;
    }

    for (const { run, i, scene } of scenes) {
        const world = W.floorWorld(scene);
        const placed = placementsOf(world, DRESSED, dressing3d);
        const where = `[${theme}: ${run} #${i}]`;
        const heights = {
            seat: world.desks.flatMap((d) => (d.sitter === null ? [] : [d.sitter.h])),
            intern: world.desks.flatMap((d) => d.interns.map((n) => n.h)),
        };

        for (const kind of DRESSED) {
            const pieces = world.pieces.filter((p) => p.kind === kind);
            const tables = placed.get(kind) ?? [];
            const dressings = placed.get(dressingOf(kind)) ?? [];

            printed.pieces[kind] = (printed.pieces[kind] ?? 0) + pieces.length;

            if (tables.length !== pieces.length || dressings.length !== pieces.length) {
                legs.placed.push(`${where} ${pieces.length} ${kind} piece(s) stand ${tables.length} table(s) and ${dressings.length} dressing(s) — not one of each`);
            }

            for (const piece of pieces) {
                const dress = dressing3d(kind, inputsOf(piece));
                const same = (q) => near(q.at.x, piece.at.x) && near(q.at.y, piece.at.y) && near(q.at.z, piece.at.z) && near(q.w, piece.w);
                const table = tables.find(same);
                const dressing = dressings.find(same);

                if (table === undefined || !near(table.h, dress.top) || !near(table.d, dress.d)) {
                    legs.placed.push(`${where} the ${kind} at x ${piece.at.x.toFixed(2)} stands its table as ${JSON.stringify(table)} — not the tile's width at the dressing's top ${dress.top.toFixed(3)} and depth ${dress.d.toFixed(3)}`);
                }

                if (dressing === undefined || !near(dressing.h, piece.h)) {
                    legs.placed.push(`${where} the ${kind} at x ${piece.at.x.toFixed(2)} stands its dressing as ${JSON.stringify(dressing)} — not at its foot at the tile's size`);
                }

                // waist-high to its visitor, whose drawn height the world carries
                const body = heights[VISITOR[kind]];

                printed.top[`${theme}/${kind}`] = Number(dress.top.toFixed(3));

                if (body.length > 0) {
                    const h = Math.max(...body);
                    const ratio = (table?.h ?? NaN) / h;

                    printed.waist[`${theme}/${kind}`] = Number(ratio.toFixed(3));

                    if (!(ratio >= 0.4 && ratio <= 0.6)) {
                        legs.waist.push(`${where} the ${kind}'s table stands ${table?.h?.toFixed(3)} tall, ${ratio.toFixed(2)} of its ${VISITOR[kind]}'s ${h.toFixed(3)} — not waist-high`);
                    }
                }

                // the dressing's parts, as the page builds them, measured in the piece's frame
                const boxes = dress.parts.map((part) => {
                    const geo = partGeometry(part, DETAIL.chibi);

                    geo.computeBoundingBox();

                    return geo.boundingBox;
                });
                const lowest = Math.min(...boxes.map((b) => b.min.y));
                const highest = Math.max(...boxes.map((b) => b.max.y));
                const overhang = piece.w * 0.05;

                printed.parts[`${theme}/${kind}`] = dress.parts.length;

                for (const off of new Set(dress.parts.map((q) => q.colour).filter((c) => !colours.has(c)))) {
                    legs.palette.push(`${where} the ${kind}'s dressing paints ${off}, no colour of ${theme}'s PALETTE['snack-table']`);
                }

                if (!boxes.some((b) => near(b.min.y, dress.top))) {
                    legs.rests.push(`${where} no part of the ${kind}'s dressing lies on its top ${dress.top.toFixed(3)}`);
                }

                if (lowest < dress.top / 2) {
                    legs.rests.push(`${where} the ${kind}'s dressing reaches down to ${lowest.toFixed(3)}, below half its top ${dress.top.toFixed(3)} — it is not standing on the table`);
                }

                if (highest > piece.h + EPS) {
                    legs.rests.push(`${where} the ${kind}'s dressing reaches up to ${highest.toFixed(3)}, above its tile's drawn ${piece.h.toFixed(3)}`);
                }

                for (const b of boxes) {
                    if (b.min.x < -piece.w / 2 - overhang || b.max.x > piece.w / 2 + overhang || b.min.z < -dress.d / 2 - overhang || b.max.z > dress.d / 2 + overhang) {
                        legs.rests.push(`${where} a part of the ${kind}'s dressing spans x ${b.min.x.toFixed(3)}…${b.max.x.toFixed(3)}, z ${b.min.z.toFixed(3)}…${b.max.z.toFixed(3)} — off its table's ${piece.w.toFixed(3)} × ${dress.d.toFixed(3)} plan`);
                        break;
                    }
                }
            }
        }
    }
}

// the controls: a leg judged over no table, or a waist judged against no visitor, measured nothing
for (const kind of DRESSED) {
    if (!(printed.pieces[kind] > 0)) {
        legs.placed.push(`CONTROL: no scene stands a ${kind} — every leg here judged nothing`);
    }

    if (registry.THEMES.some((theme) => printed.waist[`${theme}/${kind}`] === undefined)) {
        legs.waist.push(`CONTROL: no ${kind} was judged against a visitor — no scene drew its ${VISITOR[kind]}`);
    }
}

console.log(JSON.stringify({ legs, printed }));
