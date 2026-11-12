/**
 * The probe `Tests\Feature\Floor\TheFloorIsTheScenesThirdRendererTest` reads `floor/floor-world.js` through — AT-D3-38's
 * world half (`docs/design/FLOOR.md` § 11; `docs/design/plan-13033-stage2-3d-floor.md` § 8 item 2). argv[2] is the
 * client's `wire/` directory (the shipped one or a MUTATED COPY of the tree); stdin carries `{ "scenes": [ { "run", "i",
 * "scene" } ] }` — scenes the harness's floor screen drew, as its frames carry them — and stdout is `{ "legs": { "<leg>":
 * [defect, …] }, "printed": { … } }`, each leg a list of defects, empty when it holds.
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NONE OF ITS DECISIONS — except the one frame it checks them against.
 * The world's frame (a unit of `UNIT` scene px, the floor below the band centred on `x` with its front at `+z`) is
 * re-derived here from the scene's own extent and band, so a `worldOf()` that flipped an axis moves every observation
 * off the frame and reds; everything else — the strings, the walkers' points, the heights — is compared with the
 * scene's own elements and `route.js`'s `pointAt()`, which the module must reproduce and does not own.
 *
 * ⛔ A WALKER's POINT IS LEGAL (slice 5, the AT-D3-23/-31/-32/-33/-36 replays): every sampled point a walker stands at
 * on the floor lifts inside the floor and on OPEN lattice — in no wall cell and in no standing piece's cell the scene
 * draws (its decorations aside, which are walked over) — read off the scene's own `walls` and `scenery`.
 *
 * ⛔ WHAT MOVES OFF A WALKER IS THE SCENE's (slice 5): the elevator's leaf timeline is the walks' door spans, each open
 * after `ELEVATOR_DOOR.open` frames and shut at its end; each restroom door's leaf and field are the scene's timeline at
 * the scene's rects stood on the tile's foot; every bird, ring and desk row the world draws is one of the scene's
 * effects, both ways, each bird flying between its two ends lifted (a desk's top, or the birdhouse's picture row as a
 * height) and above the chord between them at its flight's middle, gone after its last part.
 *
 * ⚠ WHAT IT DOES NOT SEE: whether every `wall` cell of a map has a body (the walls are read off the scene, whose
 * `walls` the door view's classification answers; that classification's own coverage is `door-view.selftest.mjs`'s),
 * and whether a route avoids other desks' slots (the scene's walk tests own the route; this probe holds that the world
 * lifts the point the route gives onto open floor).
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const wire = process.argv[2];
const floor = join(wire, '..', 'floor');
const W = await import(pathToFileURL(join(floor, 'floor-world.js')).href);
const { pointAt } = await import(pathToFileURL(join(floor, 'route.js')).href);
const { deskSlab } = await import(pathToFileURL(join(floor, 'desk-geometry.js')).href);
const { ELEVATOR_DOOR, leafLevel } = await import(pathToFileURL(join(floor, 'scene.js')).href);
const { BIRD_LIFT } = await import(pathToFileURL(join(floor, 'bird.js')).href);
const { keyOf } = await import(pathToFileURL(join(wire, 'seat-key.js')).href);

const { scenes } = JSON.parse(readFileSync(0, 'utf8'));
const EPS = 1e-6;
const near = (a, b) => Math.abs(a - b) < EPS;
const legs = { frame: [], desks: [], facing: [], parts: [], interns: [], pieces: [], walls: [], walkers: [], lattice: [], labels: [], status: [], doors: [], restroom: [], effects: [], birds: [] };
const printed = { scenes: scenes.length, desks: 0, parts: 0, interns: 0, pieces: 0, walls: { edge: 0, partition: 0 }, walkers: 0, sampled: 0, labels: {}, runs: {}, doorSpans: 0, restroomDoors: 0, birds: 0, rings: 0, deskRows: 0 };
const WORLD_MEMBERS = ['band', 'desks', 'doors', 'effects', 'extent', 'labels', 'pieces', 'planes', 'restroom_doors', 'slab', 'walkers', 'walls'];
const LABEL_ORDER = ['name', 'status', 'chip', 'facts', 'bubble'];
const FACT_KINDS = ['label', 'currency', 'lag', 'gauge-pct', 'badge', 'flag', 'stool-more', 'quiet-age'];

for (const { run, i, scene } of scenes) {
    const at = `[${run} #${i}]`;
    const world = W.floorWorld(scene);
    // The frame, re-derived: the floor is the scene's extent below the band.
    const top = scene.band === null ? scene.extent.y : scene.band.y + scene.band.h;
    const D = (scene.extent.y + scene.extent.h - top) / W.UNIT;
    const Wd = scene.extent.w / W.UNIT;
    const X = (px) => -Wd / 2 + (px - scene.extent.x) / W.UNIT;
    const Z = (py) => -D / 2 + (py - top) / W.UNIT;
    const same = (p, x, z, y = 0) => near(p.x, x) && near(p.z, z) && near(p.y, y);

    // ── the frame: what the world carries, and nothing a viewer's camera or ride would ─────────
    const members = Object.keys(world).sort();

    if (JSON.stringify(members) !== JSON.stringify(WORLD_MEMBERS)) {
        legs.frame.push(`${at} the world's members are ${members.join(', ')} — not ${WORLD_MEMBERS.join(', ')} (no camera, no ride)`);
    }

    if (!near(world.extent.w, Wd) || !near(world.extent.d, D)) {
        legs.frame.push(`${at} the world's extent is ${world.extent.w} × ${world.extent.d} units, not the floor below the band's ${Wd} × ${D}`);
    }

    // ── the desks: one per scene desk, in its order, its slab where its plan rect stands ───────
    const keys = (xs) => xs.map((x) => x.key).join(' ');

    if (keys(world.desks) !== keys(scene.desks)) {
        legs.desks.push(`${at} the world's desks are [${keys(world.desks)}], the scene's [${keys(scene.desks)}]`);
    }

    scene.desks.forEach((d, k) => {
        const w = world.desks[k];

        if (w === undefined) {
            return;
        }

        printed.desks++;
        const plan = deskSlab({ x: d.box.x, y: d.box.y + d.box.h });

        if (!near(w.slab.back, Z(plan.y)) || !near(w.slab.front, Z(plan.y + plan.h)) || !near(w.slab.left, X(plan.x)) || !near(w.slab.right, X(plan.x + plan.w))) {
            legs.desks.push(`${at} ${d.key}'s slab stands at x ${w.slab.left}…${w.slab.right}, z ${w.slab.back}…${w.slab.front}, not its plan rect's`);
        }

        // M4: the sitter on the band side of the slab, the drawers toward it; the monitor and the lids face their users.
        if (w.sitter !== null && !(w.sitter.at.z < w.slab.back && w.slab.back < w.slab.front)) {
            legs.facing.push(`${at} ${d.key}'s sitter stands at z ${w.sitter.at.z}, not behind its slab (z ${w.slab.back}…${w.slab.front}) on the band side`);
        }

        if ((w.sitter === null) !== !d.elements.some((e) => e.kind === 'character')) {
            legs.facing.push(`${at} ${d.key} has ${w.sitter === null ? 'no sitter' : 'a sitter'} where the scene ${w.sitter === null ? 'draws' : 'draws no'} character`);
        }

        if (w.slab.drawers !== 'sitter' || (w.screen !== null && w.screen.facing !== 'sitter') || w.interns.some((n) => n.lid !== 'intern')) {
            legs.facing.push(`${at} ${d.key}'s drawers, monitor or a laptop do not face their user`);
        }

        // The desk's furniture (plan § 3.1's `parts`): each present exactly where the scene draws its art element, at
        // that element's centre across — the chair behind the slab where its sitter sits, the monitor and the props over
        // the slab, the side table at its element's foot.
        const PARTS = { chair: 'chair', monitor: 'monitor-frame', props: 'desk-props', sideTable: 'side-table' };

        for (const [name, kind] of Object.entries(PARTS)) {
            const [e] = d.elements.filter((x) => x.kind === kind);
            const p = w.parts[name];

            if ((p === null) !== (e === undefined)) {
                legs.parts.push(`${at} ${d.key} has ${p === null ? 'no' : 'a'} ${name} where the scene draws ${e === undefined ? 'no' : 'a'} ${kind}`);
                continue;
            }

            if (p === null) {
                continue;
            }

            printed.parts++;
            const depth = { chair: Z(plan.y - plan.h / 2), monitor: Z(plan.y + plan.h / 2), props: Z(plan.y + plan.h / 2), sideTable: Z(e.y + e.h) }[name];

            if (!same(p.at, X(e.x + e.w / 2), depth) || !near(p.w, e.w / W.UNIT) || !near(p.h, e.h / W.UNIT)) {
                legs.parts.push(`${at} ${d.key}'s ${name} stands at ${JSON.stringify(p.at)} ${p.w} × ${p.h}, not where and as its ${kind} is drawn`);
            }

            if (name === 'chair' && w.sitter !== null && (!near(p.at.z, w.sitter.at.z) || !near(w.sitter.h, d.elements.find((x) => x.kind === 'character').h / W.UNIT))) {
                legs.parts.push(`${at} ${d.key}'s chair is at z ${p.at.z}, its sitter at ${w.sitter.at.z} and ${w.sitter.h} tall — not its character's height`);
            }
        }

        // Every intern a chibi at its stool's foot.
        const stools = d.elements.filter((e) => e.kind === 'stool');

        if (keys(w.interns) !== keys(stools)) {
            legs.interns.push(`${at} ${d.key}'s interns are [${keys(w.interns)}], its stools [${keys(stools)}]`);
        }

        stools.forEach((s, n) => {
            const intern = w.interns[n];

            printed.interns++;

            if (intern !== undefined && (!intern.chibi || !same(intern.at, X(s.x + s.w / 2), Z(s.y + s.h)) || !near(intern.h, s.h / W.UNIT) || intern.laptop !== (s.activity === 'working'))) {
                legs.interns.push(`${at} ${s.key} is not a chibi at its stool's foot, its stool's height, with its laptop as its activity says`);
            }
        });

        // ── the labels: the painter's strings, in the one order, at the 2-D offset above the foot ──
        const own = world.labels.filter((l) => l.key === d.key);
        const order = own.map((l) => l.kind);
        const want = LABEL_ORDER.filter((kind) => order.includes(kind));

        if (JSON.stringify(order) !== JSON.stringify(want)) {
            legs.labels.push(`${at} ${d.key}'s labels are [${order.join(', ')}] — not in the order ${LABEL_ORDER.join(', ')}`);
        }

        const character = d.elements.find((e) => e.kind === 'character');
        const foot = character === undefined ? d.depth_y : character.y + character.h;
        const one = (kind) => d.elements.find((e) => e.kind === kind);
        const element = { name: one('nameplate'), chip: one('chip'), bubble: d.bubble ?? undefined };
        const facts = d.elements.filter((e) => FACT_KINDS.includes(e.kind) && typeof e.text === 'string');

        for (const l of own) {
            printed.labels[l.kind] = (printed.labels[l.kind] ?? 0) + 1;

            if (l.anchor.depth !== d.depth_y) {
                legs.labels.push(`${at} ${d.key}'s ${l.kind} label stands at depth ${l.anchor.depth}, not its desk's ${d.depth_y}`);
            }

            const el = element[l.kind];

            if (el !== undefined && el !== null) {
                const height = Math.max(0, foot - (el.y + el.h / 2));

                if (!near(l.anchor.height, height) || !near(l.anchor.x, el.x + el.w / 2)) {
                    legs.labels.push(`${at} ${d.key}'s ${l.kind} label stands ${l.anchor.height} px up at x ${l.anchor.x}, not its element's ${height} at ${el.x + el.w / 2}`);
                }

                if (l.kind !== 'bubble' && l.text !== el.text) {
                    legs.labels.push(`${at} ${d.key}'s ${l.kind} label reads "${l.text}", the painter's "${el.text}"`);
                }
            }
        }

        for (const [kind, el] of Object.entries(element)) {
            if (el !== undefined && el !== null && !own.some((l) => l.kind === kind)) {
                legs.labels.push(`${at} ${d.key} draws a ${kind} and carries no ${kind} label`);
            }
        }

        const factLabel = own.find((l) => l.kind === 'facts');

        if (JSON.stringify(factLabel?.model?.map((f) => f.text) ?? []) !== JSON.stringify(facts.map((e) => e.text))) {
            legs.labels.push(`${at} ${d.key}'s facts label reads ${JSON.stringify(factLabel?.model?.map((f) => f.text) ?? [])}, the painter's ${JSON.stringify(facts.map((e) => e.text))}`);
        }

        // The status (the operator's Q4 answer): the monitor's own strings, the off form for a dark one.
        const monitor = one('monitor');
        const screen = one('monitor-text');
        const status = own.filter((l) => l.kind === 'status');
        const text = monitor === undefined || monitor.lit === 'off' ? null : screen?.text ?? null;

        if (status.length !== (monitor === undefined ? 0 : 1)) {
            legs.status.push(`${at} ${d.key} carries ${status.length} status labels over ${monitor === undefined ? 'no monitor' : 'one monitor'}`);
        } else if (monitor !== undefined && (status[0].text !== text || status[0].lit !== monitor.lit)) {
            legs.status.push(`${at} ${d.key}'s status reads ${JSON.stringify(status[0].text)} (${status[0].lit}), its screen ${JSON.stringify(text)} (${monitor.lit})`);
        } else if (monitor !== undefined && !(status[0].anchor.height > W.MONITOR_TOP_U * W.UNIT)) {
            legs.status.push(`${at} ${d.key}'s status floats at ${status[0].anchor.height} px, not above the monitor's top`);
        }
    });

    if (world.labels.some((l) => !scene.desks.some((d) => d.key === l.key))) {
        legs.labels.push(`${at} a label names a desk the scene does not draw`);
    }

    // ── the pieces: every standing tile, at its cell's foot line, and no piece the scene does not place ──
    const cells = (xs) => xs.map((c) => `${c.room}:${c.kind}:${c.x},${c.y}`).sort().join(' ');

    if (cells(world.pieces.map((p) => ({ room: p.room, kind: p.kind, ...p.cell }))) !== cells(scene.scenery)) {
        legs.pieces.push(`${at} the world's pieces are not the scene's standing tiles, both ways`);
    }

    scene.scenery.forEach((s, k) => {
        const p = world.pieces[k];

        printed.pieces++;

        if (p !== undefined && !same(p.at, X(s.x + s.w / 2), Z(s.y + s.h))) {
            legs.pieces.push(`${at} ${s.kind} at ${s.x},${s.y} stands at ${JSON.stringify(p.at)}, not at its cell's foot line`);
        }

        if (p !== undefined && (!near(p.w, s.w / W.UNIT) || !near(p.h, s.h / W.UNIT))) {
            legs.pieces.push(`${at} ${s.kind} at ${s.x},${s.y} is ${p.w} × ${p.h} units, not its cell's ${s.w} × ${s.h} px`);
        }
    });

    // ── the walls: every standing wall cell a box, as tall as its footprint position makes it ──
    const ids = (xs) => xs.map((w) => w.id).sort().join(' ');

    if (ids(world.walls) !== ids(scene.walls.map((w) => ({ id: `wall:${w.x}:${w.y}` })))) {
        legs.walls.push(`${at} the world's walls are not the scene's, both ways`);
    }

    const planes = [...scene.planes].sort((a, b) => (a.install_id === null) - (b.install_id === null));

    scene.walls.forEach((c, k) => {
        const w = world.walls[k];
        const f = planes.find((p) => c.x >= p.x && c.x < p.x + p.w && c.y >= p.y && c.y < p.y + p.h);
        const T = c.size;

        if (w === undefined || f === undefined) {
            legs.walls.push(`${at} the wall cell at ${c.x},${c.y} has no box, or stands on no plane`);

            return;
        }

        const edge = c.x === f.x || c.x + T === f.x + f.w || c.y + T === f.y + f.h;
        const height = edge ? W.ROOM_H_U : W.PARTITION_H_U;

        printed.walls[edge ? 'edge' : 'partition']++;

        if (w.kind !== (edge ? 'edge' : 'partition') || !near(w.box.h, height) || !same(w.box, X(c.x), Z(c.y)) || !near(w.box.w, T / W.UNIT)) {
            legs.walls.push(`${at} the wall at ${c.x},${c.y} is a ${w.kind} ${w.box.h} units tall at ${JSON.stringify(w.box)} — its footprint makes it an ${edge ? 'edge' : 'partition'} ${height} tall`);
        }
    });

    // ── the walkers: every effect in flight with legs, its point the route's, lifted, inside the floor ──
    const flying = scene.effects.filter((fx) => (fx.legs?.length ?? 0) > 0);

    if (world.walkers.length !== flying.length || world.walkers.some((w, k) => JSON.stringify(w.effect) !== JSON.stringify(flying[k]))) {
        legs.walkers.push(`${at} the world's walkers are not the scene's effects in flight, both ways`);
    }

    // The cells no walker stands in: the walls, and every standing piece the scene draws but a decoration.
    const blocked = [...scene.walls.map((c) => ({ x: c.x, y: c.y, w: c.size, h: c.size, what: `the ${c.kind} wall` })),
        ...scene.scenery.filter((t) => !t.decoration).map((t) => ({ x: t.x, y: t.y, w: t.w, h: t.h, what: `the ${t.kind}` }))];
    const inside = (p, r) => p.x > r.x + EPS && p.x < r.x + r.w - EPS && p.y > r.y + EPS && p.y < r.y + r.h - EPS;

    printed.runs[run] = (printed.runs[run] ?? 0) + world.walkers.length;

    world.walkers.forEach((w) => {
        printed.walkers++;

        if (typeof w.key !== 'string' || w.chibi !== (typeof w.effect.intern_key === 'string')) {
            legs.walkers.push(`${at} a ${w.animation_id} walker is keyed ${JSON.stringify(w.key)}, chibi ${w.chibi} — not its seat's, intern's or walker's key`);
        }
        const span = Math.max(...w.effect.legs.map((l) => l.start + l.frames)) * w.effect.frame_interval_ms;
        // Three instants inside the walk, off its cab legs and its span behind the restroom's door (`where`).
        const points = [0.2, 0.5, 0.8].map((u) => u * span).map((t) => [t, pointAt(w.effect, t)]).filter(([, p]) => p !== null && p.where === 'floor');

        for (const [t, p] of points) {
            const got = W.walkerAt(world.slab, w, t);

            printed.sampled++;

            if (!same(got, X(p.x), Z(p.y))) {
                legs.walkers.push(`${at} ${w.key} at ${Math.round(t)} ms stands at ${JSON.stringify(got)}, not the route's point ${p.x},${p.y} lifted`);
            } else if (Math.abs(got.x) > Wd / 2 + EPS || Math.abs(got.z) > D / 2 + EPS) {
                legs.walkers.push(`${at} ${w.key} at ${Math.round(t)} ms stands outside the floor`);
            }

            const cell = blocked.find((r) => inside(p, r));

            if (cell !== undefined) {
                legs.lattice.push(`${at} ${w.key} (${w.animation_id}) at ${Math.round(t)} ms stands at ${p.x},${p.y}, inside ${cell.what}'s cell at ${cell.x},${cell.y}`);
            }
        }
    });
}

for (const { run, i, scene } of scenes) {
    const at = `[${run} #${i}]`;
    const world = W.floorWorld(scene);
    const top = scene.band === null ? scene.extent.y : scene.band.y + scene.band.h;
    const D = (scene.extent.y + scene.extent.h - top) / W.UNIT;
    const Wd = scene.extent.w / W.UNIT;
    const X = (px) => -Wd / 2 + (px - scene.extent.x) / W.UNIT;
    const Z = (py) => -D / 2 + (py - top) / W.UNIT;

    // ── the elevator's leaves: shut at a span's start, open once it has opened, shut again at its end ──
    for (const d of scene.doors ?? []) {
        printed.doorSpans++;

        const level = (f) => leafLevel(world.doors, f);

        if (level(d.from) !== 0 || level(d.from + ELEVATOR_DOOR.open) !== 1 || level(d.to) !== 0) {
            legs.doors.push(`${at} the door span ${d.from}…${d.to} reads ${level(d.from)} at its start, ${level(d.from + ELEVATOR_DOOR.open)} once open, ${level(d.to)} at its end — not 0, 1, 0`);
        }
    }

    if ((scene.doors ?? []).length === 0 && world.doors.length !== 0) {
        legs.doors.push(`${at} the world holds a leaf timeline with no door span in the scene`);
    }

    // ── the restroom doors: the scene's timeline, at its rects stood on the tile's foot ──
    const doors = scene.restroom_doors ?? [];

    if (world.restroom_doors.length !== doors.length) {
        legs.restroom.push(`${at} the world holds ${world.restroom_doors.length} restroom doors, the scene ${doors.length}`);
    }

    doors.forEach((d, k) => {
        const w = world.restroom_doors[k];
        const foot = d.y + d.h;

        printed.restroomDoors++;

        if (w === undefined) {
            return;
        }

        for (const name of ['recess', 'sign']) {
            const r = d[name];
            const got = w[name];

            if (!near(got.x, X(r.x)) || !near(got.w, r.w / W.UNIT) || !near(got.y, (foot - (r.y + r.h)) / W.UNIT) || !near(got.h, r.h / W.UNIT)) {
                legs.restroom.push(`${at} restroom ${d.id}'s ${name} stands at ${JSON.stringify(got)}, not its rect ${JSON.stringify(r)} on the tile's foot`);
            }
        }

        if (!near(w.at.z, Z(foot)) || JSON.stringify(w.leaf) !== JSON.stringify(d.leaf) || JSON.stringify(w.occupied) !== JSON.stringify(d.occupied)) {
            legs.restroom.push(`${at} restroom ${d.id}'s foot, leaf or field is not the scene's`);
        }
    });

    // ── the effects off the walkers: the scene's, both ways ──
    const drawnOff = (fx) => W.BIRD_ROWS.includes(fx.animation_id) || (fx.animation_id === 'A20' && fx.at !== null && fx.at !== undefined)
        || (W.DESK_ROWS.includes(fx.animation_id) && (fx.seat_id !== null || typeof fx.intern_key === 'string'));
    const wanted = scene.effects.filter(drawnOff);

    if (world.effects.length !== wanted.length || world.effects.some((e, k) => JSON.stringify(e.effect) !== JSON.stringify(wanted[k]))) {
        legs.effects.push(`${at} the world's effects are [${world.effects.map((e) => e.effect.animation_id)}], the scene's drawn off its walkers [${wanted.map((fx) => fx.animation_id)}]`);
    }

    for (const e of world.effects) {
        const fx = e.effect;

        if (e.kind === 'ring') {
            printed.rings++;

            if (!near(e.at.x, X(fx.at.x)) || !near(e.at.z, Z(fx.at.y)) || !near(e.radius, fx.radius / W.UNIT)) {
                legs.effects.push(`${at} the ring stands at ${JSON.stringify(e.at)} ${e.radius} units, not round its origin desk`);
            }

            continue;
        }

        if (e.kind === 'desk') {
            printed.deskRows++;

            if (e.key !== (fx.animation_id === 'A24' ? fx.intern_key : keyOf(fx))) {
                legs.effects.push(`${at} ${fx.animation_id} is keyed ${e.key}, not its desk's or its intern's key`);
            }

            continue;
        }

        // ── a bird: its two ends lifted, and its flight between them, above the chord, gone after its last part ──
        printed.birds++;

        const end = (p, onWall) => (onWall
            ? { x: X(p.x), y: (top - p.y + BIRD_LIFT) / W.UNIT }
            : { x: X(p.x), z: Z(p.y), y: W.DESK_H_U + BIRD_LIFT / W.UNIT });
        const portal = fx.perch !== null && fx.perch !== undefined;
        const want = { from: end(fx.from, portal && fx.leg === 'arrival'), to: end(fx.to, portal && fx.leg === 'departure') };

        for (const side of ['from', 'to']) {
            const got = e[side];
            const w = want[side];

            if (!near(got.x, w.x) || !near(got.y, w.y) || (w.z !== undefined && !near(got.z, w.z))) {
                legs.birds.push(`${at} ${fx.animation_id}'s ${side} end stands at ${JSON.stringify(got)}, not ${JSON.stringify(w)}`);
            }
        }

        const ms = fx.frame_interval_ms;

        if (fx.frames > 0) {
            const mid = W.birdAt(e, (fx.wait_frames + fx.flight_start + fx.frames / 2) * ms);
            const chord = (e.from.y + e.to.y) / 2;

            if (mid === null || mid.phase !== 'flight' || !(mid.y > chord + EPS) || Math.abs(mid.x - (e.from.x + e.to.x) / 2) > 1e-3) {
                legs.birds.push(`${at} ${fx.animation_id} at its flight's middle is ${JSON.stringify(mid)} — not flying above the chord between its ends (${chord})`);
            }

            if (W.birdAt(e, (fx.wait_frames + fx.span_frames) * ms + 1) !== null) {
                legs.birds.push(`${at} ${fx.animation_id} is still drawn after its last part`);
            }
        } else if (fx.still_frames > 0) {
            const still = W.birdAt(e, 0);

            if (still === null || still.phase !== 'still' || !near(still.x, e.to.x) || W.birdAt(e, fx.still_frames * ms + 1) !== null) {
                legs.birds.push(`${at} ${fx.animation_id}'s still form is ${JSON.stringify(still)} — not the envelope at its end for the moving form's duration`);
            }
        }
    }
}

// The controls: a population this probe reads nothing of is a pass that measured nothing.
if (printed.desks === 0 || printed.parts === 0 || printed.interns === 0 || printed.pieces === 0 || printed.walls.edge === 0 || printed.walls.partition === 0
    || printed.sampled === 0 || (printed.labels.bubble ?? 0) === 0) {
    legs.frame.push(`CONTROL: the scenes hold no desk, desk part, intern, piece, edge wall, partition, sampled walker or bubble to read — ${JSON.stringify(printed)}`);
}

if (printed.doorSpans === 0 || printed.restroomDoors === 0 || printed.birds === 0 || printed.rings === 0 || printed.deskRows === 0) {
    legs.frame.push(`CONTROL: the scenes hold no door span, restroom door, bird, ring or desk row to read — ${JSON.stringify(printed)}`);
}

// Every walking run handed walkers in flight: a run whose scenes carried none measured no walk.
for (const [run, n] of Object.entries(printed.runs)) {
    if (n === 0 && scenes.some((x) => x.run === run && x.walking)) {
        legs.walkers.push(`CONTROL: the walking run ${run} handed no walker in flight`);
    }
}

process.stdout.write(JSON.stringify({ legs, printed }));
