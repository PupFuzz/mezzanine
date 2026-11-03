/**
 * card#12194 — THE ROUTED WALK, HEADLESS: what `floor/route.js` answers, read back for AT-D3-23
 * (`ASeatLeavesByTheElevatorAndReturnsByItTest`). `node`, no dependencies.
 *
 * Modes (stdin JSON `{ mode, … }`):
 *
 *   `shipped`  — the shipped default (`resources/floor/default.tmj`, or the map at `map`; its tileset, the theme
 *                registry's kinds) as the scene reads it: the lattice's cell and extent, the threshold, the walker's
 *                drawn body (`walkerSize()`), each desk's route and the clearance tier it was found at (card#12442),
 *                every `wall` and standing tile's rect
 *                (resolved through `scene.js`'s `mapTiles()` and `tileset.js`'s reader — the obstacles H1 and H15 hold
 *                a path to), and EVERY `desks` object routed to the threshold (H17), with the cost of the lattice and
 *                every route together, measured `repeat` times (H9). `walls` paints extra `wall` tiles into a COPY of
 *                the map first (`[[col, row], …]`, H17's plant).
 *   `points`   — `pointAt(effect, t)` for each `{ effect, times }` (H8, H15, H20).
 *   `stack`    — `floorStackOrder(desks, walkers)` for each `{ desks, walkers }` (H11, H16, H18).
 *   `draw`     — `resources/characters/seed.js`'s `draw(key, field)` for each `[key, field]` — the seeded draw H4 re-derives
 *                a departure's wait from, read from the character tree itself.
 *   `snack-chain` — card#12785: each key's chain of snack breaks as `scene.js`'s `snackBreaks()` derives it, over whole
 *                days and over the window a page at each given instant reads (AT-D3-32).
 *   `restroom` / `restroom-chain` — card#12786: the restroom's constants and the waiting spots from a given threshold;
 *                each key's restroom chain and snack chain over whole days (AT-D3-33).
 *   `lattice`  — H6's tileset and registry legs at the scene: `buildScene()` over one drawn frame and its rows twice —
 *                a tileset `pending` then held, the theme registry absent then held — each pair's lattice keys, and
 *                whether `EffectsInFlight` cancels the first scene's walk at a render carrying the second's key.
 *
 * argv: `<module-dir>` — the client tree's `wire/` (a mutated copy's, for a plant). stdout: the mode's answer.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const dir = process.argv[2];
const mod = async (p) => import(pathToFileURL(join(dir, '..', p)).href);
const REPO = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const input = JSON.parse(readFileSync(0, 'utf8'));

const { floorStackOrder, lattice, pointAt, route, cellCentre, polylineLength } = await mod('floor/route.js');

/** The answer, written whole: `process.exit()` straight after a write to a pipe can cut a long answer short. */
const answer = (value) => {
    process.stdout.write(JSON.stringify(value));
};

if (input.mode === 'points') {
    answer(input.cases.map(({ effect, times }) => times.map((t) => pointAt(effect, t))));
}

if (input.mode === 'draw') {
    const { draw } = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);

    answer(input.cases.map(([key, field]) => draw(key, field)));
}

if (input.mode === 'stack') {
    answer(input.cases.map(({ desks, walkers }) => floorStackOrder(desks, walkers).map((o) => ({ kind: o.kind, key: o.key }))));
}

const { PLANE_KINDS, backWall, mapTiles, slotOrigin, threshold, buildScene, walkerSize } = await mod('floor/scene.js');
const { backWallBand, mapDesks, mapGrid } = await mod('floor/floor-layout.js');
const { readTileset, tilesetUrl } = await mod('floor/tileset.js');
const { characterCentre, deskAnchor } = await mod('floor/desk-layout.js');
const registry = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'themes', 'index.js')).href);
const { FURNITURE_BOX } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'furniture-box.js')).href);
const characters = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
const CHARACTER = { w: characters.SCENE_W, h: characters.SCENE_H };
const TSX = readFileSync(join(REPO, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8');

if (input.mode === 'shipped') {
    const map = JSON.parse(readFileSync(input.map ?? join(REPO, 'resources', 'floor', 'default.tmj'), 'utf8'));
    const grid = mapGrid(map);

    if ((input.walls ?? []).length > 0) {
        const walls = map.layers.find((l) => l.name === 'walls');

        for (const [col, row] of input.walls) {
            walls.data[row * grid.width + col] = 3;
        }
    }

    const tileset = readTileset(TSX, tilesetUrl(map.tilesets[0].source));
    const standing = registry.KINDS.filter((k) => !PLANE_KINDS.includes(k));
    const extent = { x: 0, y: 0, width: grid.width * grid.tilewidth, height: grid.height * grid.tileheight };
    const door = threshold(backWall(backWallBand(extent), null));
    const box = FURNITURE_BOX;
    const desks = mapDesks(map).map((o) => {
        const slot = { x: o.x, y: o.y, w: o.width, h: o.height };
        const at = slotOrigin(slot, box);

        return {
            id: o.id,
            key: `desk-${o.id}`,
            slot,
            box: { x: at.x, y: at.y, w: box.width, h: box.height },
            anchor: { x: at.x + characterCentre(box, CHARACTER), y: at.y + deskAnchor(box, CHARACTER).y },
        };
    });
    const obstacles = () => mapTiles(map, { x: 0, y: 0 }, (url) => (url === tilesetUrl(map.tilesets[0].source) ? tileset : null), null)
        .filter((c) => c.kind === 'wall' || standing.includes(c.kind))
        .map((c) => ({ x: c.x, y: c.y, w: c.w, h: c.h, kind: c.kind }));
    const once = () => {
        const g = lattice({
            extent,
            cell: Math.min(grid.tilewidth, grid.tileheight),
            obstacles: obstacles(),
            desks: desks.map((d) => ({ key: d.key, rects: [d.slot, d.box] })),
            body: walkerSize(CHARACTER),
        });

        return { g, routes: desks.map((d) => route(g, d.anchor, door, { key: d.key, anchor: d.anchor })) };
    };
    const costs = [];
    let last = null;

    for (let i = 0; i < (input.repeat ?? 1); i++) {
        const t0 = performance.now();

        last = once();
        costs.push(performance.now() - t0);
    }

    const { g, routes } = last;

    answer({
        cell: g.cell,
        extent,
        threshold: door,
        obstacles: obstacles(),
        body: walkerSize(CHARACTER),
        desks: desks.map((d, i) => {
            const cells = routes[i]?.cells ?? null;
            const points = cells === null ? null : [d.anchor, ...cells.map((c) => cellCentre(g, c)), door];

            return { ...d, cells, clearance: routes[i]?.tier ?? null, points, length: points === null ? null : polylineLength(points) };
        }),
        cost_ms: costs,
    });
}

if (input.mode === 'lattice') {
    const { EffectsInFlight, walkSide } = await mod('floor/in-flight.js');
    const { themeInputs } = await mod('floor/painter.js');
    const { harnessMeasurer } = await import(pathToFileURL(join(REPO, 'server', 'tests', 'Feature', 'Support', 'harness-measurer.mjs')).href);
    const map = JSON.parse(readFileSync(join(REPO, 'resources', 'floor', 'default.tmj'), 'utf8'));
    const url = tilesetUrl(map.tilesets[0].source);
    const themes = Object.fromEntries(registry.THEMES.map((name) => [name, {}]));
    const frame = input.frame;
    const sceneOf = ({ tileset, withRegistry }) => buildScene(frame, {
        box: FURNITURE_BOX,
        measure: harnessMeasurer(input.measurer),
        character: CHARACTER,
        draw: characters.draw,
        themes: themeInputs(withRegistry ? registry : null, withRegistry ? themes : {}),
        maps: new Map(frame.rooms.map((room) => [room.install_id, map])),
        hallway: null,
        tilesets: new Map([[url, tileset ? { tileset: readTileset(TSX, url) } : { pending: true }]]),
        layout_version: input.layout_version ?? 0,
        failed: new Set(),
        reduce: false,
        effects: input.rows,
        previous: new Map(),
    });
    const leg = (before, after) => {
        const a = sceneOf(before);
        const b = sceneOf(after);
        const walk = a.effects.find((e) => e.animation_id === 'A1' || e.animation_id === 'A2');
        const holder = new EffectsInFlight(1000 / 4);
        const ctx = (scene) => ({
            journal: [],
            anchors: new Map(Object.entries(scene.anchors)),
            threshold: threshold(scene.band),
            unconfirmed: () => false,
            stilled: false,
            side: (key) => walkSide(frame.desks.desks[key]?.render_state?.value),
            lattice_key: scene.lattice_key,
        });

        holder.render(a.effects, 0, ctx(a));

        return {
            keys_differ: a.lattice_key !== b.lattice_key,
            walk_routed: walk?.route ?? null,
            cancelled: holder.render([], 500, ctx(b)),
        };
    };

    answer({
        tileset: leg({ tileset: false, withRegistry: true }, { tileset: true, withRegistry: true }),
        registry: leg({ tileset: true, withRegistry: false }, { tileset: true, withRegistry: true }),
    });
}

// card#12749 (AT-D3-32): the snack corner's constants as the scene declares them, and the spots `spotsNear()` finds on a
// visitor's own lattice from a spot 0 the TEST derived from § 12's rule — so the test holds the scene's trips to both.
if (input.mode === 'snack') {
    const scene = await mod('floor/scene.js');
    const { CLEARANCE, spotsNear } = await mod('floor/route.js');
    const map = JSON.parse(readFileSync(join(REPO, input.map ?? join('resources', 'floor', 'default.tmj')), 'utf8'));
    const grid = mapGrid(map);
    const tileset = readTileset(TSX, tilesetUrl(map.tilesets[0].source));
    const standing = registry.KINDS.filter((k) => !PLANE_KINDS.includes(k));
    const extent = { x: 0, y: 0, width: grid.width * grid.tilewidth, height: grid.height * grid.tileheight };
    const box = FURNITURE_BOX;
    // The desks the scene drew (`input.desks`, each `{key, rects}`) — a slot nobody holds is open floor — else every slot.
    const desks = input.desks ?? mapDesks(map).map((o) => {
        const slot = { x: o.x, y: o.y, w: o.width, h: o.height };
        const at = slotOrigin(slot, box);

        return { key: `desk-${o.id}`, rects: [slot, { x: at.x, y: at.y, w: box.width, h: box.height }] };
    });
    const cells = mapTiles(map, { x: 0, y: 0 }, () => tileset, null);
    const obstacles = cells.filter((c) => c.kind === 'wall' || standing.includes(c.kind)).map((c) => ({ x: c.x, y: c.y, w: c.w, h: c.h }));
    const bodies = { seat: walkerSize(CHARACTER), intern: scene.INTERN_SIZE };

    const { SNACK_LINES } = await mod('floor/snack-lines.js');

    answer({
        gap_s: scene.SNACK_GAP_S,
        day_s: scene.SNACK_DAY_S,
        dwell_s: scene.SNACK_DWELL_S,
        lines: SNACK_LINES,
        tables: scene.SNACK_TABLES,
        clearance: CLEARANCE,
        bodies,
        offset: Object.fromEntries(Object.entries(bodies).map(([k, b]) => [k, scene.snackSpotOffset(b)])),
        spacing: Object.fromEntries(Object.entries(bodies).map(([k, b]) => [k, scene.snackSpacing(b)])),
        placed: cells.filter((c) => scene.SNACK_TABLES[c.kind] !== undefined).map((c) => ({ kind: c.kind, x: c.x, y: c.y, w: c.w, h: c.h })),
        spots: (input.spots ?? []).map(({ visitor, from, count }) => {
            const g = lattice({ extent, cell: Math.min(grid.tilewidth, grid.tileheight), obstacles, desks, body: bodies[visitor] });
            const at = spotsNear(g, from, scene.snackSpacing(bodies[visitor]));

            return Array.from({ length: count }, (_, k) => at(k)?.at ?? null);
        }),
    });
}

// card#12785 (AT-D3-32): each key's chain of breaks as the scene derives it — `snackBreaks()` over whole days from
// `from_day`, and over the window a page at each instant of `at` reads (`snackBreaks(key, t − gap max, t + gap max)`), so
// the test holds the chain's gaps to § 12 and a page loaded at any instant to the same breaks.
if (input.mode === 'snack-chain') {
    const scene = await mod('floor/scene.js');
    const { draw } = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
    const D = scene.SNACK_DAY_S;
    const G = scene.SNACK_GAP_S.max;

    answer(Object.fromEntries(input.keys.map((key) => [key, {
        days: scene.snackBreaks(draw, key, input.from_day * D, (input.from_day + input.days) * D - 1),
        at: (input.at ?? []).map((t) => scene.snackBreaks(draw, key, t - G, t + G)),
    }])));
}

// card#12786 (AT-D3-33): the restroom's constants as the scene declares them, and the waiting spots `spotsNear()` finds on the
// seat's lattice from a threshold the TEST derived from § 12's rule, clear of the snack spot 0s it names — so the test holds
// the scene's visits to both. `desks` as the `snack` mode's.
if (input.mode === 'restroom') {
    const scene = await mod('floor/scene.js');
    const { CLEARANCE, spotsNear, cellAt, blockedFor } = await mod('floor/route.js');
    const map = JSON.parse(readFileSync(join(REPO, input.map ?? join('resources', 'floor', 'default.tmj')), 'utf8'));
    const grid = mapGrid(map);
    const tileset = readTileset(TSX, tilesetUrl(map.tilesets[0].source));
    const standing = registry.KINDS.filter((k) => !PLANE_KINDS.includes(k));
    const extent = { x: 0, y: 0, width: grid.width * grid.tilewidth, height: grid.height * grid.tileheight };
    const desks = input.desks ?? mapDesks(map).map((o) => {
        const slot = { x: o.x, y: o.y, w: o.width, h: o.height };
        const at = slotOrigin(slot, FURNITURE_BOX);

        return { key: `desk-${o.id}`, rects: [slot, { x: at.x, y: at.y, w: FURNITURE_BOX.width, h: FURNITURE_BOX.height }] };
    });
    const cells = mapTiles(map, { x: 0, y: 0 }, () => tileset, null);
    const obstacles = cells.filter((c) => c.kind === 'wall' || standing.includes(c.kind)).map((c) => ({ x: c.x, y: c.y, w: c.w, h: c.h }));
    const seat = walkerSize(CHARACTER);
    const { ELEVATOR_DOOR } = scene;

    answer({
        gap_s: scene.RESTROOM_GAP_S,
        day_s: scene.SNACK_DAY_S,
        dwell_s: scene.RESTROOM_DWELL_S,
        door: scene.RESTROOM_DOOR,
        plates: scene.RESTROOM_PLATES,
        line: scene.RESTROOM_LINE,
        door_motion: ELEVATOR_DOOR,
        queue_spacing: scene.queueSpacing(CHARACTER),
        clearance: CLEARANCE,
        seat,
        placed: cells.filter((c) => c.kind === scene.RESTROOM_DOOR.kind).map((c) => ({ x: c.x, y: c.y, w: c.w, h: c.h })),
        spots: input.from === undefined ? null : (() => {
            const g = lattice({ extent, cell: Math.min(grid.tilewidth, grid.tileheight), obstacles, desks, body: seat });
            const at = spotsNear(g, input.from, scene.queueSpacing(CHARACTER), input.clear ?? []);

            return Array.from({ length: input.count ?? 3 }, (_, k) => at(k)?.at ?? null);
        })(),
        // whether each point's cell is open at `clear` for the seat's body, as nobody's walk (the threshold's test)
        open: (input.open ?? []).map((p) => {
            const g = lattice({ extent, cell: Math.min(grid.tilewidth, grid.tileheight), obstacles, desks, body: seat });
            const c = cellAt(g, p);

            return c !== null && blockedFor(g, { key: null, anchor: p }, 'clear')[c[1] * g.cols + c[0]] === 0;
        }),
    });
}

// card#12786 (AT-D3-33): each key's chain of restroom breaks as the scene derives it — `snackBreaks()` under `RESTROOM_CHAIN`
// over whole days from `from_day` — and its snack chain over the same days, so the test holds the two apart.
if (input.mode === 'restroom-chain') {
    const scene = await mod('floor/scene.js');
    const { draw } = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
    const D = scene.SNACK_DAY_S;

    answer(Object.fromEntries(input.keys.map((key) => [key, {
        days: scene.snackBreaks(draw, key, input.from_day * D, (input.from_day + input.days) * D - 1, scene.RESTROOM_CHAIN),
        snack: scene.snackBreaks(draw, key, input.from_day * D, (input.from_day + input.days) * D - 1),
    }])));
}

if (!['points', 'draw', 'stack', 'shipped', 'lattice', 'snack', 'snack-chain', 'restroom', 'restroom-chain'].includes(input.mode)) {
    throw new Error(`unknown mode ${input.mode}`);
}
