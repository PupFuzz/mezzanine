/**
 * AT-D3-26's DOOR-VIEW LEG — the floor seen through the cab's open doors, PAINTED and WIRED (`docs/design/FLOOR.md` § 4.5's
 * *Through the open doors at a FLOOR is that floor*, card#12987; the plan is `docs/design/plan-12987-cab-door-view.md` § 6).
 * Imported by `elevator-control-probe.mjs` and read by `Tests\Feature\Floor\TheElevatorIsAControlOnTheDrawingTest`'s
 * `doorViewDefects()`; the model's own readings are `tools/design/door-view.selftest.mjs`'s, over the same fixture floors
 * (`tools/design/door-view-fixtures.mjs`).
 *
 * The real ride (`wire/ride.js`'s `Ride`, the floor page's constructor arguments, standing at `alpha`), the real door views
 * (`floor/door-views.js` over a real `Building` and `TilesetLoader` on a scripted fetch, the real theme modules), the real
 * ride page (`wire/ride-page.js`, over a fake dialog) and the module's `paintCab()`. Each scenario is what the plan's § 6
 * table names; each answers what was drawn under `.cab-beyond`, read back as the readings the test compares:
 *
 *   S1 `entering` at alpha · S2 `chosen('bravo')` · S3 bravo's map 503 · S3b bravo held at v1 under a failed refetch ·
 *   S4 bravo's map answering after `chosen` began (the wake and the one repaint) · S5 the cottage module absent ·
 *   S6 `charlie`'s two rooms, and again with a tileset the loader lacks · S7 a stop the stops do not offer ·
 *   S8 the press with `/api/building` answering 503, and again 401.
 *
 * `globalThis.location` names the floor page's own path throughout, so a painter that read it would read `alpha`.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..', '..', '..');
const T = 8;

/** Every node under `n`, depth first. */
const nodes = (n, out = []) => { out.push(n); n.children.forEach((c) => nodes(c, out)); return out; };
const classes = (n) => (n.getAttribute?.('class') ?? '').split(' ').filter(Boolean);
const num = (n, k) => Number(n.getAttribute(k));

/**
 * @param {string} JS the module tree the probe drives (`--js`)
 * @param {function(Node): list<object>} census the elevator probe's scenery census
 * @param {function(Node): object|null} clipOf the elevator probe's clip reader
 */
export async function doorViewLeg(JS, census, clipOf) {
    const js = (...p) => pathToFileURL(join(JS, ...p)).href;
    const themesDir = join(REPO, 'resources', 'floor', 'themes');
    const cabPaint = await import(js('floor', 'cab-paint.js'));
    const { DoorViews } = await import(js('floor', 'door-views.js'));
    const { SCALE, ROOM_H: MODEL_ROOM_H, PARTITION_H } = await import(js('floor', 'door-view.js'));
    const { BAND_H, ELEVATOR } = await import(js('floor', 'floor-layout.js'));
    const { mapTiles, sceneryInput } = await import(js('floor', 'map-tiles.js'));
    const tileset = await import(js('floor', 'tileset.js'));
    const { Building } = await import(js('wire', 'building.js'));
    const { Ride } = await import(js('wire', 'ride.js'));
    const { ridePage } = await import(js('wire', 'ride-page.js'));
    const fx = await import(pathToFileURL(join(REPO, 'tools', 'design', 'door-view-fixtures.mjs')).href);
    const registry = await import(pathToFileURL(join(themesDir, 'index.js')).href);
    const modules = Object.fromEntries(await Promise.all(registry.THEMES.map(async (name) => [name, await import(pathToFileURL(join(themesDir, name, 'theme.js')).href)])));
    const o = { ...fx.kit(tileset, readFileSync(join(REPO, 'resources', 'floor', 'tiles', 'floor-plane.tsx'), 'utf8')), Building, TilesetLoader: tileset.TilesetLoader, tilesetUrl: tileset.tilesetUrl };
    const { CAB, OFFICE, paintCab, svgUri, themeInputs } = cabPaint;
    const themes = themeInputs(registry, modules);

    globalThis.location = { pathname: '/floor/alpha' };

    // ── the building: three floors, and a stop's map whose answer the probe releases ─────────────────────────────────
    const maps = { alpha: fx.alphaMap(o), bravo: fx.bravoMap(o), charlie: fx.charlieMap(o, 296), 'charlie-2': fx.charlieMap(o, 496) };
    const plate = (key, ids, theme = null) => ({ ...fx.stopRow(key, ids, theme), href: `/floor/${key}` });
    const ROWS = [plate('alpha', ['alpha']), plate('bravo', ['bravo'], 'cottage'), plate('charlie', ['charlie', 'charlie-2'])];

    /**
     * A page: the building and loader over a scripted fetch (each path's answer, `fail` paths answering 503, `late` a promise
     * the bravo map's answer waits on, `layoutAfter` the status `/api/building` answers once the page is set up), the page's
     * own floor `alpha` entered and its tileset loaded before the ride — as the floor page holds its own — and its door
     * views, the ride and the ride page. `rows` is the stops' source, read on every frame.
     */
    async function page({ fail = [], late = null, theme = themes, rooms = maps, rows = () => ROWS, layoutAfter = null } = {}) {
        let now = 1000;
        let settingUp = true;
        const clock = { now: () => now };
        const requests = [];
        const layout = layoutAfter === null ? null : { layout: { layout_version: 1, floors: [] }, rooms: [] };
        const base = fx.scripted(o, Object.fromEntries(Object.entries(rooms).map(([id, map]) => [id, { map }])), { fail, layout });
        const fetch = async (path, init) => {
            requests.push(path);

            if (path === '/api/building' && !settingUp) {
                return { status: layoutAfter, ok: false, json: async () => null, text: async () => '' };
            }

            if (late !== null && path.includes('/rooms/bravo/')) {
                await late.promise;
            }

            return base(path, init);
        };
        const building = new Building(fetch);
        const tilesets = new tileset.TilesetLoader(fetch);
        const paints = [];
        const wakes = [];
        let view = null;
        const ride = new Ride({
            clock,
            reduce: false,
            lobby_href: '/dashboard',
            endsRide: () => false,
            currentStop: () => ({ current: 'alpha', current_name: 'alpha' }),
            stops: rows,
        });
        const doorViews = new DoorViews({ building, tilesets, rows, themes: () => theme, wake: () => { wakes.push(now); view?.perform(frame()); } });
        // The frame the page paints: the ride's (its stops left out — the panel's own probe reads them) and the cab's scene ride.
        const frame = () => ({ ride: ride.rideFrame(now) === null ? null : { ...ride.rideFrame(now), stops: [] }, cab: doorViews.attach(ride.sceneRide(now)), feed: '', layout: null });

        if (layoutAfter !== null) {
            await building.fetchLayout();
        }

        await building.enterRooms(['alpha']);
        await tilesets.load([o.url]);
        requests.length = 0;
        settingUp = false;

        const doors = new Node('div');
        const host = (id) => ({ inert: false, style: {}, textContent: '', hidden: false, open: false, showModal() { this.open = true; }, close() { this.open = false; },
            animate: () => ({ finished: Promise.resolve() }), addEventListener() {}, ownerDocument: { activeElement: null }, children: [], querySelector: () => null, id });
        const els = { 'floor-cab-doors': doors };
        const saved = globalThis.document.getElementById;

        globalThis.window ??= { addEventListener() {}, location: { assign() {} } };
        globalThis.document.getElementById = (id) => els[id] ?? (els[id] = host(id));
        view = ridePage({
            prefix: 'floor',
            screen: () => ({ rideView: () => null }),
            paint: () => {},
            paintCab: (h, r) => { paints.push({ phase: r?.phase ?? null, at: now }); paintCab(h, r); },
            elevator: () => null,
            lastRide: () => null,
            timers: { after: () => 1, cancel() {} },
        });
        globalThis.document.getElementById = saved;

        return {
            building,
            tilesets,
            requests,
            paints,
            wakes,
            doors,
            ride,
            doorViews,
            perform: () => view.perform(frame()),
            frame,
            tick: (ms) => { now += ms; },
            press: () => { const answer = ride.press({}); return { answer, settled: answer === null ? Promise.resolve() : doorViews.prefetch() }; },
        };
    }

    // ── reading what was drawn ─────────────────────────────────────────────────────────────────────────────────────
    /** The view drawn under the cab's `.cab-beyond`, its doorway (the leaves' span) and the probe's own projection over it. */
    function drawn(p) {
        const beyond = nodes(p.doors).find((n) => classes(n).includes('cab-beyond')) ?? null;
        const root = beyond === null ? null : nodes(beyond).find((n) => classes(n).includes('floor-view')) ?? null;
        const leaves = nodes(p.doors).filter((n) => classes(n).includes('elevator-leaf'));
        const [l, r] = leaves.map((n) => ({ x: num(n, 'x'), y: num(n, 'y'), w: num(n, 'width'), h: num(n, 'height') }));
        const box = l === undefined ? null : { x: l.x, y: l.y, w: r.x + r.w - l.x, h: l.h };
        const kx = box === null ? 0 : box.w / CAB.door.w;
        const ky = box === null ? 0 : box.h / CAB.door.h;
        // The probe's own `at()` — the cab's eye `CAB.eye` up and `CAB.back` behind the doorway — written here, not imported.
        const at = (X, Y, Z) => {
            const s = CAB.back / (CAB.back + Z);

            return [box.x + box.w / 2 + X * s * kx, box.y + (CAB.door.h - CAB.eye + (CAB.eye - Y) * s) * ky];
        };
        const pts = (...ps) => ps.map((q) => q.map((v) => +v.toFixed(2)).join(',')).join(' ');
        const order = root === null ? [] : nodes(root);
        const index = (points) => order.findIndex((n) => n.name === 'polygon' && n.getAttribute('points') === points);
        const near = (b) => index(pts(at(b.x1, b.y2, b.z1), at(b.x2, b.y2, b.z1), at(b.x2, b.y1, b.z1), at(b.x1, b.y1, b.z1)));
        const of = (cls) => order.filter((n) => classes(n).includes(cls));
        const centreX = (n) => { const xs = n.getAttribute('points').split(' ').map((q) => Number(q.split(',')[0])); return (Math.min(...xs) + Math.max(...xs)) / 2; };
        const topY = (n) => Math.min(...n.getAttribute('points').split(' ').map((q) => Number(q.split(',')[1])));

        return { beyond, root, box, at, pts, order, index, near, of, centreX, topY, horizon: box === null ? null : box.y + (CAB.door.h - CAB.eye) * ky };
    }

    /** The rider's frame in cm of a floor px on a single-room floor at the origin — the threshold's seam 74 px in. */
    const X = (px) => (74 - px) * SCALE;
    const Z = (py) => py * SCALE;
    const box = (x1px, x2px, y1px, y2px, h) => ({ x1: X(x2px), x2: X(x1px), z1: Z(y1px), z2: Z(y2px), y1: 0, y2: h });
    const ROOM_H = 160 * SCALE;
    /** What every scenario's census reads, with the root group's `style` and each `<image>`'s `href` handed on for the check. */
    const sceneryOf = (d) => ({
        found: d.root !== null,
        clip: d.root === null ? null : clipOf(d.root),
        door: d.box,
        style: d.root?.getAttribute('style') ?? null,
        census: d.root === null ? [] : census(d.root).map((c, i) => ({ ...c, href: nodes(d.root)[i + 1].getAttribute?.('href') ?? null })),
        parts: Object.fromEntries(['dv-floor', 'dv-ceiling', 'dv-wall', 'dv-back', 'dv-light', 'dv-partition', 'dv-partition-cap', 'dv-desk', 'dv-desk-edge', 'dv-monitor', 'dv-accent', 'dv-piece']
            .map((cls) => [cls, d.of(cls).length])),
    });
    /** Every standing piece's document a floor's map yields, recomputed here: the hrefs a view of it may show. */
    const hrefsOf = (id, theme) => mapTiles(maps[id], { x: 0, y: 0 }, () => o.read, id)
        .filter((c) => c.kind !== null && !['wall', 'accent'].includes(c.kind))
        .map((c) => svgUri(modules[theme].scenery(sceneryInput(c, id))));
    const carriers = (theme) => Object.fromEntries([
        ['--dv-floor', modules[theme].PALETTE.floor.boards],
        ['--dv-wall-top', modules[theme].PALETTE.wall.top],
    ]);
    const styleOf = (s) => Object.fromEntries((s ?? '').split(';').filter(Boolean).map((d) => d.split(':')));

    // § 12's *Door-view scale* and *Partition height* rows, and what the scale is derived from.
    const out = { office: OFFICE, figures: { scale: SCALE, room_h: MODEL_ROOM_H, partition_h: PARTITION_H, band_h: BAND_H, elevator_h: ELEVATOR.h, door_h: CAB.door.h } };

    // ── S1 and S2: one ride from alpha — entering on alpha's floor, then the choice of bravo ─────────────────────────
    {
        const p = await page();
        const { settled } = p.press();

        await settled;
        p.ride.entered();
        p.perform();

        const d = drawn(p);
        // The partition column right of the seam (x 40..48), row by row below the top row; its last cell, on the far edge, a wall.
        const column = Array.from({ length: 90 }, (_, r) => r).filter((r) => r > 0)
            .map((r) => ({ r, i: d.near(box(40, 48, r * T, (r + 1) * T, r === 89 ? ROOM_H : 120)) }));
        const plantAt = d.order.findIndex((n) => n.name === 'image');

        out.s1 = {
            ...sceneryOf(d),
            stop: p.frame().cab?.stop ?? null,
            palette: styleOf(d.root?.getAttribute('style')),
            owed: carriers('studio'),
            hrefs: hrefsOf('alpha', 'studio'),
            column_faces: column.filter((c) => c.i >= 0).length,
            column_right: column.filter((c) => c.i >= 0).every((c) => d.centreX(d.order[c.i]) > d.box.x + d.box.w / 2),
            // The wall cell under the first desk's slab (x 240..248, y 360..368), and the slab's nearest row strip (the slab
            // x 218..398, y 340..400 snapped to the rows 336..400), each as the probe projects it.
            under_slab: d.near(box(240, 248, 360, 368, 120)),
            slab_strip: d.near(box(218, 398, 336, 344, 76)),
            desks_left: d.of('dv-desk').length > 0 && d.of('dv-desk').every((n) => d.centreX(n) < d.box.x + d.box.w / 2),
            far_desk: d.near(box(1218, 1398, 336, 344, 76)),
            plant: plantAt,
            behind_plant: column.filter((c) => c.r * T >= 504 && c.i >= 0).map((c) => c.i),
            before_plant: column.filter((c) => (c.r + 1) * T <= 504 && c.i >= 0).map((c) => c.i),
        };

        p.ride.shut();
        p.perform();
        p.ride.choose('bravo');
        p.perform();

        const e = drawn(p);
        const row = Array.from({ length: 75 }, (_, c) => ({ c, i: e.near(box(c * T, (c + 1) * T, 400, 408, c === 0 ? ROOM_H : 120)) }));
        const partitions = row.filter((c) => c.c > 0 && c.i >= 0).map((c) => e.order[c.i]);
        const post = row[0].i >= 0 ? e.order[row[0].i] : null;
        const cells = mapTiles(maps.bravo, { x: 0, y: 0 }, () => o.read, 'bravo');
        const bookcase = cells.find((c) => c.kind === 'bookcase' && c.col === 25 && c.row === 60);
        const owedHref = svgUri(modules.cottage.scenery(sceneryInput(bookcase, 'bravo')));
        const bookcaseAt = e.order.findIndex((n) => n.name === 'image' && n.getAttribute('href') === owedHref);
        const nearWall = Array.from({ length: 50 }, (_, c) => [120, ROOM_H].map((h) => e.near(box(c * T, (c + 1) * T, 0, T, h)))).flat();

        out.s2 = {
            ...sceneryOf(e),
            stop: p.frame().cab?.stop ?? null,
            palette: styleOf(e.root?.getAttribute('style')),
            owed: carriers('cottage'),
            hrefs: hrefsOf('bravo', 'cottage'),
            row_faces: partitions.length,
            row_spans_centre: partitions.some((n) => e.centreX(n) < e.box.x + e.box.w / 2) && partitions.some((n) => e.centreX(n) > e.box.x + e.box.w / 2),
            caps_below_horizon: e.of('dv-partition-cap').length > 0 && e.of('dv-partition-cap').every((n) => e.topY(n) > e.horizon),
            post_above_horizon: post !== null && e.topY(post) < e.horizon,
            near_wall: nearWall.filter((i) => i >= 0).length,
            bookcase: bookcaseAt,
            bookcase_href: bookcaseAt >= 0,
            row_after_bookcase: row.filter((c) => c.i >= 0).every((c) => c.i > bookcaseAt),
        };
    }

    // ── S3, S5, S7: bare rooms, each through the ride's choice ───────────────────────────────────────────────────────
    const bareAt = async (choice, options) => {
        const p = await page(options);
        const { settled } = p.press();

        await settled;
        p.ride.entered();
        p.ride.shut();
        p.ride.choose(choice);
        p.perform();

        return { ...sceneryOf(drawn(p)), model: { bare: p.frame().cab.view?.bare ?? null, reason: p.frame().cab.view?.reason ?? null } };
    };

    out.s3 = await bareAt('bravo', { fail: ['/rooms/bravo/'] });
    out.s5 = await bareAt('bravo', { theme: { ...themes, modules: { ...themes.modules, cottage: null } } });

    // S7: the ride chose `zulu`, and the layout then removed it — the stops no longer offer the stop the doors open on.
    {
        let rows = [...ROWS, plate('zulu', [])];
        const p = await page({ rows: () => rows });
        const { settled } = p.press();

        await settled;
        p.ride.entered();
        p.ride.shut();
        p.ride.choose('zulu');
        rows = ROWS;
        p.perform();
        out.s7 = { ...sceneryOf(drawn(p)), model: { bare: p.frame().cab.view?.bare ?? null, reason: p.frame().cab.view?.reason ?? null } };
    }

    // ── S3b: bravo held at v1, the layout reporting v2, the press's refetch of it answering 503 ──────────────────────
    {
        const layout = { layout: { layout_version: 2, floors: [] }, rooms: [{ install_id: 'bravo', map_version: 2 }] };
        const fetchOk = fx.scripted(o, { alpha: { map: maps.alpha }, bravo: { map: maps.bravo, version: 1 } });
        const fetchAfter = fx.scripted(o, { alpha: { map: maps.alpha } }, { fail: ['/rooms/bravo/'], layout });
        const swap = { after: false };
        const building = new Building(async (path, init) => (swap.after ? fetchAfter : fetchOk)(path, init));
        const tilesets = new tileset.TilesetLoader(fetchOk);

        await building.enterRooms(['alpha', 'bravo']);
        await tilesets.load([o.url]);
        swap.after = true;
        await building.fetchLayout();

        const doorViews = new DoorViews({ building, tilesets, rows: () => ROWS, themes: () => themes });

        await doorViews.prefetch();

        const v = doorViews.attach({ phase: 'chosen', elapsed_ms: 0, ms: 1250, door_delay_ms: 250, beyond: 'office', stop: 'bravo' }).view;

        out.s3b = { held_version: building.room('bravo')?.held?.map_version ?? null, failure: building.room('bravo')?.failure ?? null, bare: v.bare, reason: v.reason };
    }

    // ── S4: bravo's map answers 300 ms after `chosen` began — the wake, and one repaint ─────────────────────────────
    {
        let release;
        const late = { promise: new Promise((resolve) => { release = resolve; }) };
        const p = await page({ late });
        const { settled } = p.press();

        p.ride.entered();
        p.perform();
        p.ride.shut();
        p.perform();
        p.ride.choose('bravo');
        p.perform();

        const before = p.paints.length;
        const first = drawn(p);
        const leavesBefore = nodes(p.doors).filter((n) => classes(n).includes('elevator-leaf')).map((n) => n.getAttribute('style'));
        const firstBare = first.of('dv-partition').length === 0 && first.order.every((n) => n.name !== 'image');

        p.tick(300);
        release();
        await settled;

        const afterWake = p.paints.length;
        const second = drawn(p);
        const leavesAfter = nodes(p.doors).filter((n) => classes(n).includes('elevator-leaf')).map((n) => n.getAttribute('style'));

        // A render with nothing new: the memo answers the model last painted, and nothing is painted.
        p.perform();

        out.s4 = {
            phase: p.frame().ride?.phase ?? null,
            first_bare: firstBare,
            wakes: p.wakes.length,
            repaints: afterWake - before,
            after_idle: p.paints.length - afterWake,
            second_partitions: second.of('dv-partition').length,
            leaves_before: leavesBefore,
            leaves_after: leavesAfter,
            elapsed_moved: 300,
            census: sceneryOf(second).census,
        };
    }

    // ── S6: charlie's two rooms; and again with charlie-2 naming a tileset the loader lacks ─────────────────────────
    {
        const p = await page();
        const { settled } = p.press();

        await settled;
        p.ride.entered();
        p.ride.shut();
        p.ride.choose('charlie');
        p.perform();

        const d = drawn(p);

        // The first room's far wall, at its own footprint's depth (296 px) — the second room's (496 px, 544 px across) lies
        // outside the doorway's frustum at its depth, and is culled.
        out.s6 = {
            ...sceneryOf(d),
            far_wall: d.near(box(0, 480, 296, 296, ROOM_H)),
            far_walls: d.of('dv-back').length,
            model: { bare: p.frame().cab.view.bare, reason: p.frame().cab.view.reason },
        };

        const q = await page({ rooms: { ...maps, 'charlie-2': fx.charlieMap(o, 496, true) } });
        const again = q.press();

        await again.settled;
        q.ride.entered();
        q.ride.shut();
        q.ride.choose('charlie');
        q.perform();
        out.s6.missing = { ...sceneryOf(drawn(q)), model: { bare: q.frame().cab.view.bare, reason: q.frame().cab.view.reason } };
    }

    // ── S8: the press, with `/api/building` answering 503 and then 401 — the press fetches no layout ────────────────
    out.s8 = {};
    for (const status of [503, 401]) {
        const p = await page({ layoutAfter: status });
        const floorsBefore = p.building.floors;
        const { settled } = p.press();

        await settled;
        out.s8[status] = {
            held_floors: floorsBefore !== null,
            layout_requests: p.requests.filter((r) => r === '/api/building').length,
            room_requests: Object.fromEntries(['bravo', 'charlie', 'charlie-2'].map((id) => [id, p.requests.filter((r) => r === `/api/building/rooms/${encodeURIComponent(id)}/map`).length])),
            layout_failure: p.building.layoutFailure,
            floors_kept: p.building.floors === floorsBefore,
        };
    }

    return out;
}
