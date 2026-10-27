/**
 * THE PATH PRIMITIVE — `docs/design/FLOOR.md § 6.2`'s walk note items 3 and 9 (card#12194): where a walking
 * character may put its FEET, the route it takes between two points, where it is at any instant of its walk, and
 * the order the floor stacks walkers among the desks.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ PURE. No DOM, no clock, no `Map`/`Set` iteration order reaches a route: the same inputs give the same path on
 * every engine, every viewer and every reload. `floor/scene.js` builds the lattice and the routes (the painter
 * decides nothing, `scene.js`'s header), and the painter reads `pointAt()` and `floorStackOrder()` back.
 *
 * ⛔ THE LATTICE IS READ FROM THE MAP, NEVER FROM WHAT WAS DRAWN (walk note item 3). The FEET rule: a cell is
 * blocked iff its half-open rect meets a `wall` tile, a STANDING kind's tile image (the theme registry's kinds minus
 * the plane kinds), another desk's slot or drawn box, or lies outside the floor's extent. A tileset still pending
 * resolves no kind, so its cells are open until it arrives — and the lattice key (`scene.js`'s `latticeKey()`)
 * cancels every walk routed without it then. Other walkers are never obstacles.
 *
 * ⛔ A WALKER KEEPS CLEAR LIKE A PERSON IN A BUILDING (walk note item 3, card#12442). The feet rule alone lets a path
 * hug an obstacle one cell away while the body — the character's drawn rect, centred on the feet — is painted over
 * it. So every obstacle and every OTHER desk is inflated by the body round the feet: a cell is blocked when its
 * centre lies within half the drawn body's width plus `CLEARANCE.margin` of the rect sideways AND within
 * `CLEARANCE.depth` of its depth span. Another desk keeps the bare body without the margins: it is drawn in the
 * floor's depth order with the walker (walk note item 9), where a wall tile and a standing tile are not. A rect wholly above the feet on screen — behind the walker in the
 * three-quarter view — is drawn behind it and does not count; one beside the feet or in front of them does. The
 * width is the scene's drawn character rect (`body.w`), never a constant here, so a taller or wider character moves
 * the inflation with it. Paths prefer the middle of a walkway: a cell near a blocked one costs extra (`CENTRING`).
 *
 * ⛔ THREE TIERS, TRIED IN ORDER, PER WALK (`CLEARANCE_TIERS`): `clear` (the body plus both margins), `snug` (the
 * bare body, no margin), `feet` (the feet rule alone — the lattice before card#12442). Each tier's blocked set holds
 * the next one's, so a route found at one tier is legal at every tier after it. `route()` answers the first tier
 * that connects the two points and names it, so a map too tight for a body still walks, on the best route that fits.
 *
 * ⛔ NOTHING HERE KNOWS ABOUT ELEVATORS. A1 and A2 hand `route()` a desk's anchor and the elevator's threshold; a
 * later destination hands it another point.
 */

/** § 12's *Route lattice and cost* row: an orthogonal step costs 10, a diagonal one 14 — integers, so no float ties. */
export const STEP_COST = Object.freeze({ straight: 10, diagonal: 14 });

/**
 * § 12's *Walker clearance* row (card#12442): the px a walker's drawn body keeps from an obstacle beside it
 * (`margin`, past half the body's width), and the px its feet keep from an obstacle's depth span (`depth`) — the
 * `clear` tier's; `snug` keeps neither.
 */
export const CLEARANCE = Object.freeze({ margin: 8, depth: 4 });

/**
 * § 12's *Walkway centring* row (card#12442): a cell whose Chebyshev distance to the nearest blocked cell (or the
 * lattice's edge) is `d` cells costs `weight × max(0, ⌈reach ÷ cell⌉ − d)` on entry, on top of its step — so a path
 * keeps to the middle of a walkway up to `reach` px from its edges. Integers, as the step costs are.
 */
export const CENTRING = Object.freeze({ reach: 48, weight: 3 });

/** The clearance tiers, strictest first (the header's *Three tiers*). */
export const CLEARANCE_TIERS = Object.freeze(['clear', 'snug', 'feet']);

/** Whether two half-open rects `{x, y, w, h}` share any area — touching edges do not. */
const meets = (a, b) => a.w > 0 && a.h > 0 && b.w > 0 && b.h > 0
    && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/**
 * THE WALKABLE MODEL of one floor (walk note item 3): a grid of lattice cells over the floor's extent, and for each
 * clearance tier the cells blocked for every walker and each desk's cells — because a walker's OWN desk is open to it
 * on its anchor's row (by the feet rule, never inflated: it sits there) and every other desk is blocked whole.
 *
 * @param {{extent: {x: number, y: number, width: number, height: number}, cell: number,
 *          obstacles: list<{x: number, y: number, w: number, h: number}>,
 *          desks: list<{key: string, rects: list<{x: number, y: number, w: number, h: number}>}>,
 *          body: {w: number}}} parts —
 *        `cell` the smallest tile edge over the floor's grids; `obstacles` the `wall` tiles' and standing tiles' rects;
 *        `desks` each desk's slot and drawn box; `body` the walker's drawn character rect, whose width is what clears
 * @returns {object} the lattice, read only by `route()` and the tests
 */
export function lattice({ extent, cell, obstacles, desks, body }) {
    const c = cell;
    const cols = Math.ceil(extent.width / c);
    const rows = Math.ceil(extent.height / c);
    const rectOf = (i) => ({ x: extent.x + (i % cols) * c, y: extent.y + Math.floor(i / cols) * c, w: c, h: c });
    // The cells one rect meets, by the half-open test itself over a range one cell wider each side.
    const cellsOf = (r) => {
        const out = [];
        const x0 = Math.max(0, Math.floor((r.x - extent.x) / c) - 1);
        const x1 = Math.min(cols - 1, Math.ceil((r.x + r.w - extent.x) / c));
        const y0 = Math.max(0, Math.floor((r.y - extent.y) / c) - 1);
        const y1 = Math.min(rows - 1, Math.ceil((r.y + r.h - extent.y) / c));

        for (let y = y0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++) {
                if (meets(rectOf(y * cols + x), r)) {
                    out.push(y * cols + x);
                }
            }
        }

        return out;
    };
    // The rect inflated by the body round the feet: the cells whose CENTRE lies strictly within `side` px of it
    // sideways and `depth` px of its depth span — with the cells the feet rule blocks, so each tier holds the next's.
    const haloOf = (r, side, depth) => {
        const out = new Set(cellsOf(r));

        if (r.w <= 0 || r.h <= 0) {
            return [...out];
        }

        const x0 = Math.max(0, Math.floor((r.x - side - extent.x) / c) - 1);
        const x1 = Math.min(cols - 1, Math.ceil((r.x + r.w + side - extent.x) / c));
        const y0 = Math.max(0, Math.floor((r.y - depth - extent.y) / c) - 1);
        const y1 = Math.min(rows - 1, Math.ceil((r.y + r.h + depth - extent.y) / c));

        for (let y = y0; y <= y1; y++) {
            const cy = extent.y + (y + 0.5) * c;

            if (cy > r.y - depth && cy < r.y + r.h + depth) {
                for (let x = x0; x <= x1; x++) {
                    const cx = extent.x + (x + 0.5) * c;

                    if (cx > r.x - side && cx < r.x + r.w + side) {
                        out.add(y * cols + x);
                    }
                }
            }
        }

        return [...out];
    };
    // A cell is inside the extent by construction, save the last column and row where the extent is not a whole
    // number of cells: their part past the extent is not floor.
    const outside = new Uint8Array(cols * rows);

    for (let i = 0; i < outside.length; i++) {
        const r = rectOf(i);

        if (r.x + r.w > extent.x + extent.width || r.y + r.h > extent.y + extent.height) {
            outside[i] = 1;
        }
    }

    const half = body.w / 2;
    // What each tier blocks round a wall or standing tile, and round another desk. A desk is drawn in the floor's
    // depth order with the walker (walk note item 9) and its box is its art's own extent, so it keeps the bare body
    // at both body tiers; the margins are the walls' and the standing furniture's.
    const reach = {
        clear: (r) => haloOf(r, half + CLEARANCE.margin, CLEARANCE.depth),
        snug: (r) => haloOf(r, half, 0),
        feet: cellsOf,
    };
    const deskReach = { clear: reach.snug, snug: reach.snug, feet: cellsOf };
    // How far in from the floor's edge each tier's centring counts the edge as reaching — the edge as a wall the
    // tier inflates, so a walkway along the edge is centred on the body and not on the feet. It blocks nothing.
    const edge = { clear: { side: half + CLEARANCE.margin, depth: CLEARANCE.depth }, snug: { side: half, depth: 0 }, feet: { side: 0, depth: 0 } };
    const sorted = (list) => Object.freeze([...new Set(list)].sort((a, b) => a - b));
    const built = new Map();

    return Object.freeze({
        origin: Object.freeze({ x: extent.x, y: extent.y }),
        cell: c,
        cols,
        rows,
        // Each desk's own cells by the feet rule: what its OWN walker may enter on its anchor's row.
        desks: Object.freeze(desks.map((d) => Object.freeze({ key: d.key, cells: sorted(d.rects.flatMap(cellsOf)) }))),
        // One tier's model, built the first time a route asks for it — a floor whose every walk routes at `clear`
        // never builds the others.
        tier: (name) => {
            if (built.has(name)) {
                return built.get(name);
            }

            const blocked = Uint8Array.from(outside);

            for (const r of obstacles) {
                for (const i of reach[name](r)) {
                    blocked[i] = 1;
                }
            }

            // Each desk as ANOTHER walker meets it: its rects inflated by the tier's desk reach.
            const tierDesks = Object.freeze(desks.map((d) => Object.freeze({ key: d.key, cells: sorted(d.rects.flatMap(deskReach[name])) })));
            const walled = Uint8Array.from(blocked);

            for (const d of tierDesks) {
                for (const i of d.cells) {
                    walled[i] = 1;
                }
            }

            built.set(name, Object.freeze({ name, blocked, desks: tierDesks, centring: centringCost(cols, rows, c, walled, edge[name]) }));

            return built.get(name);
        },
    });
}

/** The lattice cell `[x, y]` holding a point (half-open), or `null` outside the lattice. */
export function cellAt(grid, point) {
    const x = Math.floor((point.x - grid.origin.x) / grid.cell);
    const y = Math.floor((point.y - grid.origin.y) / grid.cell);

    return x < 0 || y < 0 || x >= grid.cols || y >= grid.rows ? null : [x, y];
}

/** A cell's centre. */
export function cellCentre(grid, [x, y]) {
    return { x: grid.origin.x + (x + 0.5) * grid.cell, y: grid.origin.y + (y + 0.5) * grid.cell };
}

/**
 * The cells blocked for ONE walker at one tier: the tier's obstacles, every other desk whole as the tier inflates it,
 * and its own desk (by the feet rule) on every row but its anchor's — so it leaves and reaches its desk sideways, at
 * its seated depth (walk note item 3).
 *
 * @param {object} grid `lattice()`'s
 * @param {{key: string, anchor: {x: number, y: number}}} walker
 * @param {string} [tier] one of `CLEARANCE_TIERS`, the strictest when absent
 * @returns {Uint8Array}
 */
export function blockedFor(grid, walker, tier = CLEARANCE_TIERS[0]) {
    const t = grid.tier(tier);
    const out = Uint8Array.from(t.blocked);
    const anchor = cellAt(grid, walker.anchor);

    for (const desk of t.desks) {
        if (desk.key !== walker.key) {
            for (const i of desk.cells) {
                out[i] = 1;
            }
        }
    }

    // The own desk off its anchor's row. A cell of the anchor's row that another desk also meets stays blocked,
    // because that desk's cells are blocked whoever's row it is.
    for (const desk of grid.desks) {
        if (desk.key === walker.key) {
            for (const i of desk.cells) {
                if (anchor === null || Math.floor(i / grid.cols) !== anchor[1]) {
                    out[i] = 1;
                }
            }
        }
    }

    return out;
}

/**
 * The centring cost of entering each cell at one tier (§ 12's *Walkway centring* row): `weight × max(0, R − d)`, `d`
 * the cell's Chebyshev distance in cells to the nearest cell the tier blocks for its obstacles or any desk, or to the
 * floor's edge inflated as the tier inflates a wall (`edge`, px sideways and in depth), `R = ⌈reach ÷ cell⌉`. One
 * field per tier, shared by every walker: a walker's own desk repels its own walk as it does the others', which only
 * ever adds the same cost round the desk it must leave from.
 */
function centringCost(cols, rows, cell, blocked, edge) {
    const R = Math.ceil(CENTRING.reach / cell);
    // Whole cells from a cell's centre, `at` px from an edge, to that edge's inflation `by` px deep.
    const toEdge = (at, by) => Math.max(0, Math.ceil((at - by) / cell));
    const d = new Int32Array(cols * rows);
    const queue = [];

    for (let i = 0; i < d.length; i++) {
        const x = i % cols;
        const y = Math.floor(i / cols);

        if (blocked[i] === 1) {
            queue.push(i);
        } else {
            d[i] = Math.min(R,
                toEdge((x + 0.5) * cell, edge.side), toEdge((cols - x - 0.5) * cell, edge.side),
                toEdge((y + 0.5) * cell, edge.depth), toEdge((rows - y - 0.5) * cell, edge.depth));
        }
    }

    // A multi-source breadth-first pass, 8-connected: Chebyshev distance from the blocked cells, capped at R.
    for (let k = 0; k < queue.length; k++) {
        const i = queue[k];
        const x = i % cols;
        const y = Math.floor(i / cols);

        for (let dy = -1; dy <= 1; dy++) {
            for (let dx = -1; dx <= 1; dx++) {
                const nx = x + dx;
                const ny = y + dy;
                const n = ny * cols + nx;

                if (nx >= 0 && ny >= 0 && nx < cols && ny < rows && d[n] > d[i] + 1) {
                    d[n] = d[i] + 1;
                    queue.push(n);
                }
            }
        }
    }

    return d.map((v) => CENTRING.weight * Math.max(0, R - v));
}

/**
 * A* over the lattice (walk note item 3) at ONE tier's blocked set: 8-connected, `STEP_COST` plus the centring cost
 * of the cell entered, a diagonal refused when either orthogonal neighbour is blocked (no corner is cut), the octile
 * heuristic in the step units — never above the true cost, since no centring cost is negative — and the open set
 * ordered by `(f, g descending, y, x)`: a total order, so one path for one set of inputs.
 */
function search(grid, start, goal, blocked, extra) {
    const { cols, rows } = grid;
    const s = start[1] * cols + start[0];
    const t = goal[1] * cols + goal[0];

    if (blocked[s] === 1 || blocked[t] === 1) {
        return null;
    }

    const h = (i) => {
        const dx = Math.abs((i % cols) - goal[0]);
        const dy = Math.abs(Math.floor(i / cols) - goal[1]);

        return STEP_COST.straight * Math.max(dx, dy) + (STEP_COST.diagonal - STEP_COST.straight) * Math.min(dx, dy);
    };
    const g = new Int32Array(cols * rows).fill(-1);
    const parent = new Int32Array(cols * rows).fill(-1);
    const closed = new Uint8Array(cols * rows);
    // The open set: a binary heap of `[f, g, y, x, i]`, least first by `(f, g desc, y, x)`.
    const heap = [];
    const before = (a, b) => a[0] - b[0] || b[1] - a[1] || a[2] - b[2] || a[3] - b[3];
    const push = (e) => {
        heap.push(e);

        for (let k = heap.length - 1; k > 0;) {
            const p = (k - 1) >> 1;

            if (before(heap[k], heap[p]) >= 0) {
                break;
            }

            [heap[k], heap[p]] = [heap[p], heap[k]];
            k = p;
        }
    };
    const pop = () => {
        const top = heap[0];
        const last = heap.pop();

        if (heap.length > 0) {
            heap[0] = last;

            for (let k = 0; ;) {
                const l = 2 * k + 1;
                const r = l + 1;
                let m = k;

                if (l < heap.length && before(heap[l], heap[m]) < 0) {
                    m = l;
                }

                if (r < heap.length && before(heap[r], heap[m]) < 0) {
                    m = r;
                }

                if (m === k) {
                    break;
                }

                [heap[k], heap[m]] = [heap[m], heap[k]];
                k = m;
            }
        }

        return top;
    };
    const open = (x, y) => x >= 0 && y >= 0 && x < cols && y < rows && blocked[y * cols + x] === 0;

    g[s] = 0;
    push([h(s), 0, start[1], start[0], s]);

    while (heap.length > 0) {
        const [, gc, y, x, i] = pop();

        if (closed[i] === 1 || gc !== g[i]) {
            continue;
        }

        closed[i] = 1;

        if (i === t) {
            const cells = [];

            for (let k = t; k !== -1; k = parent[k]) {
                cells.push([k % cols, Math.floor(k / cols)]);
            }

            return cells.reverse();
        }

        for (let dy = -1; dy <= 1; dy++) {
            for (let dx = -1; dx <= 1; dx++) {
                if ((dx === 0 && dy === 0) || !open(x + dx, y + dy)) {
                    continue;
                }

                const diagonal = dx !== 0 && dy !== 0;

                // No corner cut: a diagonal needs both orthogonal neighbours open.
                if (diagonal && (!open(x + dx, y) || !open(x, y + dy))) {
                    continue;
                }

                const n = (y + dy) * cols + x + dx;
                const gn = gc + (diagonal ? STEP_COST.diagonal : STEP_COST.straight) + extra[n];

                if (closed[n] === 0 && (g[n] === -1 || gn < g[n])) {
                    g[n] = gn;
                    parent[n] = i;
                    push([gn + h(n), gn, y + dy, x + dx, n]);
                }
            }
        }
    }

    return null;
}

/**
 * THE ROUTE between two points for one walker: the first of `CLEARANCE_TIERS` whose blocked set connects them, and
 * that tier's A* path.
 *
 * @param {object} grid `lattice()`'s
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {{key: string, anchor: {x: number, y: number}}} walker the walker whose desk is its own
 * @returns {{cells: list<[number, number]>, tier: string}|null} the cells from `from`'s to `to`'s, both included, and
 *          the tier they were found at; `null` with no open route at any tier
 */
export function route(grid, from, to, walker) {
    const start = cellAt(grid, from);
    const goal = cellAt(grid, to);

    if (start === null || goal === null) {
        return null;
    }

    for (const tier of CLEARANCE_TIERS) {
        const cells = search(grid, start, goal, blockedFor(grid, walker, tier), grid.tier(tier).centring);

        if (cells !== null) {
            return Object.freeze({ cells, tier });
        }
    }

    return null;
}

/** A polyline's length — the sum of its segments. */
export function polylineLength(points) {
    let length = 0;

    for (let i = 1; i < points.length; i++) {
        length += Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y);
    }

    return length;
}

/** The point at arc length `s` along a polyline, clamped to its ends. */
function along(points, s) {
    let left = Math.max(0, s);

    for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];
        const seg = Math.hypot(b.x - a.x, b.y - a.y);

        if (left <= seg && seg > 0) {
            return { x: a.x + (b.x - a.x) * (left / seg), y: a.y + (b.y - a.y) * (left / seg) };
        }

        left -= seg;
    }

    const end = points[points.length - 1];

    return { x: end.x, y: end.y };
}

/**
 * WHERE A WALKER IS — a pure function of time (walk note item 5): the feet's point and where they are drawn
 * (`floor`, or `cab` inside the band's cab), `t_ms` after the walk's start. Inside a `walk` leg, the point at arc
 * length `length × (t − start) ÷ duration` along its polyline — uniform speed; inside a `stand` or `cab` leg, its
 * `at`; before the first leg, the first leg's start; past the last leg, the last leg's end. The painter's glide and
 * its depth reconcile both read this one function from one time origin.
 *
 * @param {object} effect an A1 or A2 effect with `legs` and `frame_interval_ms`
 * @param {number} tMs milliseconds since the walk's start
 * @returns {{x: number, y: number, where: 'floor'|'cab', leg: number}|null} `null` for an effect with no legs
 */
export function pointAt(effect, tMs) {
    const legs = effect.legs ?? [];

    if (legs.length === 0) {
        return null;
    }

    const I = effect.frame_interval_ms;
    const last = legs.length - 1;
    let k = legs.findIndex((l) => tMs < (l.start + l.frames) * I);

    if (k === -1) {
        k = last;
    }

    const leg = legs[k];
    const where = leg.kind === 'cab' ? 'cab' : 'floor';

    if (leg.kind !== 'walk') {
        return { x: leg.at.x, y: leg.at.y, where, leg: k };
    }

    const fraction = Math.min(1, Math.max(0, (tMs - leg.start * I) / (leg.frames * I)));
    const p = along(leg.points, leg.length * fraction);

    return { x: p.x, y: p.y, where, leg: k };
}

/**
 * THE FLOOR STACK's ORDER (walk note item 9): the desks in their own scene order, never re-sorted, and each walker
 * placed before the first desk in that order whose depth key (`depth_y`, its furniture foot line) is greater than the
 * walker's feet `y` — so a desk on a tie is first, and LATER IN THE STACK IS IN FRONT. Walkers awaiting the same place
 * are ordered by `(y, seat key)`, the key compared by code unit (`a < b`, never `localeCompare`), so at equal `y` the
 * key that sorts first is behind.
 *
 * @param {list<{key: string, depth_y: number}>} desks in scene order
 * @param {list<{key: string, y: number}>} walkers each walker's feet `y` at the instant drawn
 * @returns {list<{kind: 'desk'|'walker', key: string, item: object}>}
 */
export function floorStackOrder(desks, walkers) {
    const slotOf = (w) => {
        const i = desks.findIndex((d) => d.depth_y > w.y);

        return i === -1 ? desks.length : i;
    };
    const placed = walkers.map((w) => ({ w, slot: slotOf(w) }))
        .sort((a, b) => a.slot - b.slot || a.w.y - b.w.y || (a.w.key < b.w.key ? -1 : a.w.key > b.w.key ? 1 : 0));
    const out = [];
    let j = 0;

    for (let i = 0; i <= desks.length; i++) {
        while (j < placed.length && placed[j].slot === i) {
            out.push({ kind: 'walker', key: placed[j].w.key, item: placed[j].w });
            j++;
        }

        if (i < desks.length) {
            out.push({ kind: 'desk', key: desks[i].key, item: desks[i] });
        }
    }

    return out;
}
