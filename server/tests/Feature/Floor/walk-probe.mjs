/**
 * card#12194 — THE ROUTED WALK, HEADLESS: what `floor/route.js` answers, read back for AT-D3-23
 * (`ASeatLeavesByTheElevatorAndReturnsByItTest`). `node`, no dependencies.
 *
 * Modes (stdin JSON `{ mode, … }`):
 *
 *   `shipped`  — the shipped default (`resources/floor/default.tmj`, its tileset, the theme registry's kinds) as the
 *                scene reads it: the lattice's cell and extent, the threshold, every `wall` and standing tile's rect
 *                (resolved through `scene.js`'s `mapTiles()` and `tileset.js`'s reader — the obstacles H1 and H15 hold
 *                a path to), and EVERY `desks` object routed to the threshold (H17), with the cost of the lattice and
 *                every route together, measured `repeat` times (H9). `walls` paints extra `wall` tiles into a COPY of
 *                the map first (`[[col, row], …]`, H17's plant).
 *   `points`   — `pointAt(effect, t)` for each `{ effect, times }` (H8, H15, H20).
 *   `stack`    — `floorStackOrder(desks, walkers)` for each `{ desks, walkers }` (H11, H16, H18).
 *   `draw`     — `resources/characters/seed.js`'s `draw(key, field)` for each `[key, field]` — the seeded draw H4 re-derives
 *                a departure's wait from, read from the character tree itself.
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

const { PLANE_KINDS, backWall, mapTiles, slotOrigin, threshold, buildScene } = await mod('floor/scene.js');
const { backWallBand, mapDesks, mapGrid } = await mod('floor/floor-layout.js');
const { readTileset, tilesetUrl } = await mod('floor/tileset.js');
const { characterCentre, deskAnchor } = await mod('floor/desk-layout.js');
const registry = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'themes', 'index.js')).href);
const { FURNITURE_BOX } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'furniture-box.js')).href);
const characters = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
const CHARACTER = { w: characters.SCENE_W, h: characters.SCENE_H };
const TSX = readFileSync(join(REPO, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8');

if (input.mode === 'shipped') {
    const map = JSON.parse(readFileSync(join(REPO, 'resources', 'floor', 'default.tmj'), 'utf8'));
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
        desks: desks.map((d, i) => {
            const cells = routes[i];
            const points = cells === null ? null : [d.anchor, ...cells.map((c) => cellCentre(g, c)), door];

            return { ...d, cells, points, length: points === null ? null : polylineLength(points) };
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

if (!['points', 'draw', 'stack', 'shipped', 'lattice'].includes(input.mode)) {
    throw new Error(`unknown mode ${input.mode}`);
}
