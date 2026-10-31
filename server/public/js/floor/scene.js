/**
 * THE SCENE — `docs/design/FLOOR.md` Appendix B row 14's model: what is drawn where on the floor,
 * as data. It reads step 7's frame (`floor/floor-screen.js` — each room's placement, the floor's
 * extent, the back-wall band and every desk's slot) and the documents the client holds (each room's
 * map, the floor's hallway, the decoded tilesets), and emits every tile, every desk's elements, the
 * floor's frame (§ 4.2: the band with its elevator, clock and windows, the slab, each room's plane),
 * the overflow strip, the coordination line and the § 6.2 forms to draw, and § 9 F21's notices.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A MODEL AND NOT A PAGE (row 14: "a model no browser is needed for, and a DOM half no check
 * exercises"). There is no browser on the build host, so every decision about where a thing lands
 * is made here and read headlessly — AT-D3-19 and AT-D3-20 read this module's output — and
 * `floor/painter.js` draws what this says and decides nothing.
 *
 * ⛔ WHAT IT READS IS § 10.3's READ MEMBERS AND NOTHING BEYOND THEM: the grid, `tilesets[]`, the
 * tile layers in document order bottom first, and the one `desks` object group. It reads no field
 * steps 5 and 7 do not already hold and adds no § 6.2 row.
 *
 * ⛔ THE INPUTS A MODULE CANNOT IMPORT ARRIVE AS INPUTS: the furniture box and the desk sprite
 * (`resources/floor/furniture-box.js`), the page's text measurer, and the character's size
 * (`resources/characters/index.js`'s `SCENE_W`/`SCENE_H`). Both trees are served at the asset
 * route's absolute URLs, which resolve under no `node` harness, so a scene that imported them would
 * be a model no gate can load (row 14). The painter supplies them on the page and the fixture in the
 * harness.
 *
 * ⛔ THE SCENE STARTS NO CLAIM-BEARING MOTION. Every § 6.2 form below is the drawing of a row the
 * animation set already logged or a held render the desk model already selected; decorative motion
 * (§ 6.3) is emitted as data too — which element, where, that it is decoration — so the painter
 * draws it without deciding it and § 11's review question has a list to read.
 */

import { ANIMATION_SET, LOOP_FPS, loops } from '../wire/animation-set.js';
import { footprintsIntersect, mapDesks, mapGrid, mapLayers } from './floor-layout.js';
import { readEmbedded, splitGid, tilesetUrl } from './tileset.js';
import { BUBBLE_MAX_W, BUBBLE_PAD, BUBBLE_TITLE_LINES, CHARACTER_SCALE, LINE, STOOL_FRAME, STOOL_H, STOOL_W, bubbleSeat, characterCentre, characterOverdraw, cloudOutline, deskAnchor, deskLayout, deskRects, fit, furnitureAsset, internKey, stoolSlots, trailCircles, trailFoot, union, wrap } from './desk-layout.js';
import { bubbleLayout } from '../desk/task-bubble.js';
import { CLEARANCE, cellCentre, lattice, pointAt, polylineLength, route, spotsNear } from './route.js';
import { perchPoint } from './bird.js';

/**
 * The back-wall band's height (§ 4.2) — "its height is a drawing layer's" (`floor-layout.js`): a wall
 * about 2.2 m tall at the floor's scale (card#11045, the operator's 2026-10-01 ruling: tall office
 * windows, not a jail's). FLOOR.md § 12's *Back-wall band height* row is its other home, and
 * `Tests\Feature\Floor\TheFloorDrawsItsFrameTest` holds the two equal — as it does `ZONE_W` and
 * `WINDOW`'s pitch and glazing below.
 */
export const BAND_H = 160;

/**
 * THE FLOOR's FRAME (§ 4.2's frame list, card#11045 PR-D) — the band's parts, in scene px from the
 * band's left edge and top. Layout, carrying no fact, and this module's own.
 *
 * ⛔ THE RESERVED ZONE IS THE BAND's LEFT `ZONE_W` px, AND NO WINDOW EVER ENTERS IT: the two-door
 * elevator and the wall clock hang there, so the clock is never covered whatever the floor's width.
 * A band narrower than the zone — a stored map can be that narrow (F21) — is WIDENED to it, so the
 * clock (A17's fact) is always on the wall and never hanging past its edge over the exterior.
 *
 * ⛔ THE ELEVATOR IS WHERE A SEAT LEAVES AND RETURNS (§ 4.2, card#9566). It is the lobby cab's form — two
 * leaves meeting at a seam, a lamp over them — in the lobby's own door colours (`--door` / `--door-edge`),
 * so the floor and the lobby read as one building. Its leaves open and close inside an A1 or A2 walk, and
 * on the viewer's own ride (§ 4.5, card#11795: `scene.ride`, which the painter draws instead of the walks'
 * door spans while it is set — § 6.3's exception); it takes no § 6.2 row of its own. It IS the floor's
 * ride control: the painter draws it as a button that asks the floor screen for a ride to another floor
 * or the lobby, and the ride ends in a real navigation (`floor/floor-screen.js`'s `ride()` and its phases).
 *
 * ⛔ THE WINDOWS FILL WHAT IS LEFT, PAST THE ZONE: `n = ⌊(w − ZONE_W − margin) ÷ pitch⌋`, at least
 * one, spread evenly, each centred in its cell and `WINDOW.w` wide — narrowed to its cell less the gap
 * on a band too short for a full one, never under `WINDOW.min_w`. BELOW THE WIDTH THAT HOLDS THE ZONE,
 * THE MARGIN AND ONE MINIMUM WINDOW WITH ITS GAP (`ZONE_W + margin + min_w + gap`) THERE ARE NONE
 * (design review r3 MINOR-1): the formula's *at least one* holds from that width up, and under it a
 * window — or its sill — could only be drawn inside the zone. A stored map can be that narrow (F21).
 */
const ZONE_W = 332;
const ELEVATOR = { dx: 32, w: 84, h: 144, frame: 6, header: 14 };
/**
 * The wall clock, RAISED 44 px to the top of the band (card#12241, the operator's ruling of 2026-10-23:
 * *"I want the clock raised to the height of the current birdhouse"*): its rect's top is `dy` under the
 * band's top, where it was centred in the band's 160 px.
 */
const CLOCK = { dx: 164, size: 64, dy: 4 };
/**
 * THE BIRD PORTAL (card#12241, COORD-STORE.md § 6.1) — the birdhouse on a bracket to the RIGHT of the clock,
 * with `gap` px of clear wall between the clock face and the roof, the roof's peak level with the clock's
 * top. A fixture of the FRAME, on every floor and never of a map, so no authored furniture meets it and a
 * floor without one cannot occur. `ZONE_W` grows by the gap and the roof's width (272 → 332), so the first
 * window keeps from the birdhouse the clearance it kept from the clock.
 */
const PORTAL = { gap: 16, roof_w: 44, body: 34, bracket_w: 40, bracket_h: 3, hole_r: 8 };
const WINDOW = { margin: 24, pitch: 360, w: 208, min_w: 96, gap: 24, h: 80, dy: 22 };

/**
 * THE RECTS A FLOOR's THEME DRAWS THE BAND's SURROUNDS IN (FLOOR.md § 10.6 item 2, § 12): a window's surround
 * is its glazing grown by the surround margin — 32 px each side, 16 above, 18 below — and the elevator's is
 * its frame grown 18 px to the right, where the call buttons hang. Both stay clear of the clock's face at
 * every width (AT-D3-25's band leg).
 */
export const SURROUND = Object.freeze({ side: 32, above: 16, below: 18, elevator_reach: 18 });

/** The slab under the floor's rooms — the front wall's top edge, scenery, outside every grid (§ 4.2). */
export const SLAB_H = 8;

/**
 * FLOOR.md § 10.6 item 6's two classes of tile kind: the PLANE kinds, whose cells are merged into runs by the
 * one region pass (`tileRegions()`) and handed to the grid's plane document, and every other kind of the
 * registry's set, which STANDS — drawn at its tile's cell rect by the floor's theme. A tile with no kind, or
 * a kind the registry does not name, is drawn by nothing: the floor never draws a tile's image.
 */
export const PLANE_KINDS = Object.freeze(['wall', 'accent']);

/** The overflow strip's gap below the floor's extent, and its header — § 3.2's labelled row. */
export const STRIP_GAP = 24;

/**
 * The strip's header — the heading the floor page already carries for the same row
 * (`resources/views/floor.blade.php`), now drawn on the floor where the row is.
 *
 * § 3.2's label for the row, in the wording the operator ratified (card#7342, 2026-09-25).
 */
export const STRIP_HEADER = "Overflow — seats past the map's desks";

/**
 * § 6.3's bound on decorative motion: a cycle of at least § 12's *Decorative motion's minimum
 * cycle* (2 s). The glow runs at the 2.4 s the operator ratified in `docs/design/floor-preview/`.
 */
export const DECORATIVE_CYCLE_MS = 2400;

/**
 * A decoration's glow (§ 6.3): the ellipse the painter fills, INSIDE ITS TILE — at the tile's top, where
 * the kit's floor and table lamps carry their shade, the tile's full width across and as tall as it is
 * wide (no taller than the tile). The scene decides it, so a glow reaches no further than its own tile:
 * a lamp the author keeps inside the room's grid glows inside it (card#11045: the glow was drawn about
 * twice its tile, centred on its foot, and a lamp at the room's front edge glowed below the floor).
 */
function glowOf(cell) {
    const ry = Math.min(cell.w, cell.h) / 2;

    return Object.freeze({ cx: cell.x + cell.w / 2, cy: cell.y + ry, rx: cell.w / 2, ry });
}

/**
 * § 9 F21's two notices, in § 5.5's words: the objects by Tiled `id` in `id` order, the room, and
 * after the colon the `seat_id` at each object — omitted for an empty one.
 */
export function intersectNotice(a, b, installId, seats) {
    return `desk objects \`${a}\` and \`${b}\` intersect — ${roomSuffix(installId, seats)}`;
}

/**
 * § 9 F23's notice, in § 5.5's words (card#11046, FLOOR.md § 10.6 item 5): the theme as the layout names
 * it, and the floor by its name — `label ?? key`, the one rendering rule § 4.6 states.
 */
export function unshippedThemeNotice(name, floorName) {
    return `floor theme \`${name}\` is not installed — drawn in the house theme — \`${floorName}\``;
}

/**
 * The theme a floor is drawn in (FLOOR.md § 10.6 item 5): the one its layout entry names when the registry
 * holds it; the house theme when it names none; and the house theme under § 9 F23's notice when it names
 * one the build does not ship. With no registry held (`themes` absent — it failed to load, which is § 9
 * F14's), nothing is resolved and nothing is claimed.
 *
 * @param {{theme: string|null, name: string}} floor the frame's floor
 * @param {{names: list<string>, house: string}|null|undefined} themes the registry's `THEMES` and `HOUSE_THEME`
 * @returns {{theme: string|null, notice: string|null}}
 */
export function floorTheme(floor, themes) {
    if (themes === null || themes === undefined) {
        return { theme: null, notice: null };
    }

    if (floor.theme === null || floor.theme === undefined) {
        return { theme: themes.house, notice: null };
    }

    return themes.names.includes(floor.theme)
        ? { theme: floor.theme, notice: null }
        : { theme: themes.house, notice: unshippedThemeNotice(floor.theme, floor.name) };
}

/**
 * THE FLOOR's THEME, AND WHETHER IT CAN DRAW (FLOOR.md § 10.6 items 5 and 8): the registry the page loaded
 * (`themes.registry`, or `null` where its import was rejected), the theme module of each name it holds
 * (`themes.modules`, a name's `null` where that import was rejected) and what has failed. A floor whose theme
 * cannot draw — no registry, its module rejected or reported failed — draws every desk's placeholder and the
 * band's and every plane's flat fallback fills, and § 9 F14's strip names what failed: the registry by its
 * asset id, the module as `theme:<name>`. Each is added to `used` so the strip can name it.
 *
 * @returns {{name: string|null, resolved: string|null, drawn: boolean, kinds: list<string>, notice: string|null}}
 */
function themeOf(floor, themes, failed, used) {
    if (themes === null || themes === undefined) {
        return { name: null, resolved: null, drawn: false, kinds: [], notice: null };
    }

    if (themes.registry === null) {
        used.add(themes.asset);

        return { name: null, resolved: null, drawn: false, kinds: [], notice: null };
    }

    const resolved = floorTheme(floor, themes.registry);
    const asset = `theme:${resolved.theme}`;
    const held = (themes.modules?.[resolved.theme] ?? null) !== null && !failed.has(asset);

    if (!held) {
        used.add(asset);
    }

    return { name: resolved.theme, resolved: resolved.theme, drawn: held, kinds: [...themes.registry.kinds], notice: resolved.notice };
}

/**
 * The band's theme documents, each at the rect the scene decides (§ 10.6 item 2): the wall at the band, a
 * surround per window, the elevator's surround and the clock's case — band-relative inputs, scene rects.
 * Exported for the theme gates (`tools/floor-themes/selftest.mjs`), which draw them over every band width.
 */
export function bandDocuments(band) {
    const rel = (r) => Object.freeze({ x: r.x - band.x, y: r.y - band.y, w: r.w, h: r.h });
    const docs = [
        {
            fn: 'band',
            rect: { x: band.x, y: band.y, w: band.w, h: band.h },
            input: {
                w: band.w,
                h: band.h,
                zone: Object.freeze({ x: band.zone.x - band.x, w: band.zone.w }),
                elevator: rel(band.elevator.frame),
                clock: rel(band.clock),
                windows: Object.freeze(band.windows.map(rel)),
            },
        },
        ...band.windows.map((w, index) => ({ fn: 'windowSurround', rect: w.surround, input: { index, glazing: Object.freeze({ w: w.w, h: w.h }) } })),
        { fn: 'elevatorSurround', rect: band.elevator.surround, input: {} },
        { fn: 'clockCase', rect: { x: band.clock.x, y: band.clock.y, w: band.clock.w, h: band.clock.h }, input: {} },
    ];

    return Object.freeze(docs.map((d) => Object.freeze({ fn: d.fn, rect: Object.freeze(d.rect), input: Object.freeze(d.input) })));
}

export function undersizedNotice(id, installId, seats) {
    return `desk object \`${id}\` is smaller than the furniture box — ${roomSuffix(installId, seats)}`;
}

function roomSuffix(installId, seats) {
    const named = seats.filter((seat) => seat !== null);

    return named.length === 0 ? installId : `${installId}: ${named.join(', ')}`;
}

/**
 * The walk and the travel speeds the edge rows' frames are counted from, in scene pixels per frame
 * at § 12's loop rate (§ 12's *Walk speed* and *Envelope speed* rows). They decide how many frames a walk or
 * a flight takes and carry no fact: every walk or flight of one length takes the same number of frames,
 * whatever the seat.
 */
export const WALK_PX_PER_FRAME = 24;
const ENVELOPE_PX_PER_FRAME = 48;

/**
 * card#12241's arrival, RULED (the operator's "A"): from touchdown the bird sets the envelope on the desk and
 * lifts away, and the envelope RESTS for eight frames, is drawn at half opacity for one, and is gone —
 * eleven frames from touchdown, so nothing persists. Reduced motion draws the still envelope for the moving
 * form's whole duration, flight and rest (§ 6.2 A19's reduced cell).
 */
const ARRIVAL_REST_FRAMES = 11;

/**
 * card#12445's ledge dwell, RULED (the operator, 2026-10-26: *"When the bird reaches the birdhouse, it should stop there
 * on the birdhouse ledge for a second before hopping into the hole. When it arrives, it should come out of the hole and
 * wait on the ledge for 1 second before flying."*): the portal bird stands on the birdhouse's perch this long going out
 * and coming in — FLOOR.md § 12's *Ledge dwell* row — in seconds, and in loop frames at § 12's rate.
 */
export const LEDGE_DWELL_S = 1;
export const LEDGE_DWELL_FRAMES = LEDGE_DWELL_S * LOOP_FPS;

/** card#12445: the hop between the perch and the hole, in loop frames (§ 12's *Hole hop* row). Chosen; it carries no fact. */
export const HOLE_HOP_FRAMES = 2;

const RING_PX_PER_FRAME = 240;
const RING_FADE_FRAMES = 2;

/**
 * The elevator's leaves, in loop frames (FLOOR § 12's *Elevator leaves* row, card#9566; card#12705 halved their speed —
 * the operator's *"about 50% current speed"*): opening, the step through the doorway, closing.
 */
export const ELEVATOR_DOOR = Object.freeze({ open: 4, step: 1, close: 4 });

/**
 * The intern's laptop fade, in loop frames (FLOOR § 12's *Laptop fade* row, card#12450/card#12451): one second each way
 * at the loop rate — A10's `seat` legs and A24's fade. Chosen; it carries no fact.
 */
export const LAPTOP_FADE = 4;

/**
 * The longest a departing character waits at the elevator before the leaves open for it (§ 12's *Elevator wait* row,
 * card#12194; card#12705 — the operator's *"a random wait between 0-30 seconds"*), in seconds, and in loop frames at
 * § 12's rate.
 */
export const ELEVATOR_WAIT_S = 30;
export const WAIT_MAX_FRAMES = ELEVATOR_WAIT_S * LOOP_FPS;

/** Where every A1 and A2 walk meets the elevator: the leaves' seam, at their foot (§ 6.2's walk note item 3). */
export function threshold(band) {
    return band === null || band === undefined ? null : Object.freeze({ x: band.elevator.seam, y: band.elevator.y + band.elevator.h });
}

/**
 * § 12's *Queue spacing* (card#12705): the least distance between the centres of two spots walkers wait at by the
 * elevator — the seat walker's drawn width (`walkerSize()`) plus the `clear` tier's side margin, so two waiting side by
 * side keep a person's clearance between them. An intern is narrower, so the figure holds for every walker.
 */
export function queueSpacing(character) {
    return walkerSize(character).w + CLEARANCE.margin;
}

/** The legs' walk primitive: a glide along `points` from frame `start`, `⌈length ÷ walk speed⌉` frames, at least one. */
function walkLeg(start, points) {
    const length = polylineLength(points);

    return Object.freeze({ kind: 'walk', start, frames: Math.max(1, Math.ceil(length / WALK_PX_PER_FRAME)), points, length });
}

const samePoint = (a, b) => a.x === b.x && a.y === b.y;

/**
 * A DEPARTURE's walk to its waiting SPOT (walk note item 9, card#12705): its route to the threshold, then the spot's path
 * from the threshold — the spot's own path back to it, reversed — with every step it would retrace taken out (`A, B, A`
 * is `A`), so a walker bound for a spot beside the door turns off its route where the two meet rather than touching the
 * seam and stepping back. Every cell either path crosses is open (`route()`, `spotsNear()`), and removing a retraced
 * step keeps every remaining step one of theirs, so the joined path is open floor too. Spot 0 is the threshold: its
 * walk is the route.
 */
function spotWalk(walk, spot) {
    if (spot.path.length === 1) {
        return walk;
    }

    const out = [];

    for (const p of [...walk.points, ...[...spot.path].reverse().slice(1)]) {
        if (out.length >= 2 && samePoint(out[out.length - 2], p)) {
            out.pop();
        } else if (out.length === 0 || !samePoint(out[out.length - 1], p)) {
            out.push(p);
        }
    }

    return walkLeg(walk.start, Object.freeze(out));
}

/**
 * WHERE A DEPARTURE STANDS AND HOW IT BOARDS, for one waiting spot (walk note item 9, card#12705): its walk to the spot,
 * the frame it arrives there (`arrive`), and its walk from the spot to the threshold when it boards (`board`; `null`
 * at spot 0, which is the threshold). `queue` is the departure's own part, from the render that applied its row —
 * `lead` (the legs before it walks: an intern's laptop fade), `walk` (its route to the threshold), `wait` (its seeded
 * draw, frames) and `threshold`; `spot` is `{index, at, path}`, `spotsNear()`'s.
 */
export function queueShape(queue, spot) {
    const walk = spotWalk(queue.walk, spot);

    return Object.freeze({
        spot,
        walk,
        arrive: walk.start + walk.frames,
        board: spot.path.length === 1 ? null : walkLeg(0, spot.path),
    });
}

/**
 * The TIMING a departure alone at the elevator keeps (walk note item 5), in frames from its start: it waits its own draw
 * at its spot, the leaves open on it then, it walks to the threshold if its spot is not the threshold, steps into the cab
 * once the leaves are open, and they close behind it.
 */
export function loneTiming(queue, shape) {
    const D = ELEVATOR_DOOR;
    const open = shape.arrive + queue.wait;
    const step = Math.max(open + (shape.board?.frames ?? 0), open + D.open);

    return Object.freeze({ open, approach: open, step, close: step + D.step + D.close });
}

/**
 * A DEPARTURE's legs and door span for one timing (walk note items 5 and 9): the lead legs, the walk to its spot, the
 * `stand` it waits in there until it leaves for the door (`approach`), the walk from the spot to the threshold, the
 * `stand` at the door until it steps in (`step`), and the `cab` — inside, behind the leaves, until they have closed
 * (`close`). The door span is from the leaves' opening (`open`, never before the walk's own frame 0) to their closing.
 */
export function departureLegs(queue, shape, timing) {
    const at = queue.threshold;
    const stand = (start, frames, where, why) => Object.freeze({ kind: 'stand', start, frames, at: where, why });
    const board = shape.board === null ? null : Object.freeze({ ...shape.board, start: timing.approach });
    const atDoor = timing.approach + (board?.frames ?? 0);
    const legs = Object.freeze([
        ...queue.lead,
        shape.walk,
        stand(shape.arrive, timing.approach - shape.arrive, shape.spot.at, 'wait'),
        ...(board === null ? [] : [board]),
        stand(atDoor, timing.step - atDoor, at, 'door'),
        Object.freeze({ kind: 'cab', start: timing.step, frames: timing.close - timing.step, at }),
    ]);
    const door = Object.freeze({ start: Math.max(0, timing.open), frames: timing.close - Math.max(0, timing.open) });

    return Object.freeze({ legs, door, frames: Math.max(...legs.map((l) => l.start + l.frames), door.start + door.frames) });
}

/**
 * A DEPARTURE as the render that applies its row draws it (walk note items 5 and 9): its queue part and, drawn alone, its
 * legs at the threshold — spot 0 and its own wait. The holder (`floor/in-flight.js`) gives it its spot and re-times it
 * with every other walker at the elevator.
 */
function departing(lead, walk, wait, at) {
    const queue = Object.freeze({ lead: Object.freeze(lead), walk, wait, threshold: at });
    const shape = queueShape(queue, Object.freeze({ index: 0, at, path: Object.freeze([at]) }));

    return { queue, ...departureLegs(queue, shape, loneTiming(queue, shape)) };
}

/**
 * The Desk-column SIDE an A1 or A2 row delivers (§ 6.2's walk note item 6, clause 1): A2 leaves the desk drawing the
 * empty chair, A1 a character. A render whose model draws the seat's desk on another side cancels the walk.
 */
const WALK_SIDE = Object.freeze({ A1: 'staffed', A2: 'empty' });

/**
 * A walker's drawn rect — the character's at the scene's scale, its feet at its bottom centre: the size a walk effect
 * is drawn at and the body its route keeps clear (`route.js`'s `lattice()`, card#12442), one value for both.
 */
export function walkerSize(character) {
    return Object.freeze({ w: character.w * CHARACTER_SCALE, h: character.h * CHARACTER_SCALE });
}

/**
 * An INTERN walker's drawn rect (§ 6.2's *Interns ride the elevator too*, item 13; card#12450): the intern at its drawn
 * size — the stool's rect, `STOOL_W × STOOL_H`, its feet at its bottom centre — and the body its route keeps clear, as
 * the seat's is `walkerSize()`. Its image is drawn in `STOOL_FRAME`, the frame that holds every chibi's ink.
 */
export const INTERN_SIZE = Object.freeze({ w: STOOL_W, h: STOOL_H });

/**
 * One A1 or A2 walk (FLOOR § 6.2's walk note items 3 and 5, card#12194): a ROUTED path through open floor between
 * the desk's anchor and the elevator's threshold, as legs in frame order — A2 walks to the threshold, WAITS there for
 * its seeded wait, stands while the leaves open on it, and steps into the cab, where the closing leaves cover it, as
 * drawn alone (`departing()`; the holder gives it its spot and its turn in the queue, item 9, card#12705); A1
 * is revealed in the cab by the opening leaves, steps out, and walks to its desk while they close behind it, sitting
 * on its walk's last frame. `door` is the frames the leaves are not shut in. With no motion, no desk on this floor,
 * no band, or no open route, the row is drawn without its walk: no legs, frames 0 (walk note item 7).
 */
function walkEffect(base, id, anchor, door, motion, character, box, walking) {
    const key = `${base.install_id}/${base.seat_id}`;
    const none = (narrate) => Object.freeze({
        ...base,
        route: 'none',
        side: WALK_SIDE[id],
        lattice_key: walking.key,
        frames: 0,
        legs: Object.freeze([]),
        door: null,
        clearance: null,
        size: null,
        frame: null,
        // § 5.5's line, for a desk inside a room that cannot reach the elevator — a defect of the map, never an
        // overflow desk, which stands on no grid (walk note item 7).
        no_route: narrate,
    });

    if (!motion || anchor === null || door === null) {
        return none(false);
    }

    const [from, to] = id === 'A2' ? [anchor, door] : [door, anchor];
    const routed = walking.route(key, anchor, from, to);

    if (routed === null) {
        return none(walking.inRoom(key));
    }

    const { cells } = routed;

    // The route's cell centres, bracketed by the walk's two ends.
    const points = Object.freeze([from, ...cells.map((c) => walking.centre(c)), to].map((p) => Object.freeze({ x: p.x, y: p.y })));
    const D = ELEVATOR_DOOR;
    const at = Object.freeze({ x: door.x, y: door.y });
    const cab = (start, frames) => Object.freeze({ kind: 'cab', start, frames, at });
    // A2 queues at the elevator (walk note item 9): drawn alone at the threshold here, re-timed by the holder.
    const drawn = id === 'A2'
        ? departing([], walkLeg(0, points), walking.wait(key, base.cause), at)
        : {
            queue: null,
            legs: Object.freeze([cab(0, D.open), Object.freeze({ kind: 'stand', start: D.open, frames: D.step, at, why: 'step' }), walkLeg(D.open + D.step, points)]),
            door: Object.freeze({ start: 0, frames: D.open + D.step + D.close }),
        };
    const { legs } = drawn;

    return Object.freeze({
        ...base,
        route: 'routed',
        side: WALK_SIDE[id],
        lattice_key: walking.key,
        legs,
        door: drawn.door,
        ...(drawn.queue === null ? {} : { queue: drawn.queue }),
        no_route: false,
        // Which clearance tier the route was found at (`route.js`'s `CLEARANCE_TIERS`, card#12442) — read by the tests
        // and the walk probe, drawn nowhere.
        clearance: routed.tier,
        // The walker is the desk's character at its drawn size, its feet on the path: `size` its rect (the
        // placeholder's, § 9 F14, and the body the route keeps clear), `frame` the size its image is drawn at — the
        // character's overdraw, the frame every seat document is drawn in (§ 10.4's frame contract, card#12259),
        // centred on the feet as the desk's is.
        size: walkerSize(character),
        frame: Object.freeze((({ w, h }) => ({ w, h }))(characterOverdraw(box, character).overdraw)),
        frames: Math.max(...legs.map((l) => l.start + l.frames), drawn.door.start + drawn.door.frames),
    });
}

/**
 * The LATTICE KEY (§ 6.2's walk note item 6, clause 4): a fingerprint of exactly what the walkable model reads — the
 * layout's version, each rendered room's install, origin, map version and whether its map is drawn, the hallway's
 * presence, the theme registry's kinds as held, and each tileset the maps name with its status in the loader. A
 * render whose key differs from a walk's cancels it: the walk was routed on a floor no longer drawn. What the painter
 * drew (its failed assets, the theme's `drawn`) is deliberately not in it — the lattice reads the map's cells.
 */
function latticeKey(frame, input, grids, kinds) {
    const status = (url) => {
        const held = input.tilesets.get(url);

        return held === undefined ? 'absent' : held.pending === true ? 'pending' : held.failure !== undefined ? 'failure' : 'tileset';
    };
    const urls = [...new Set(grids.flatMap((g) => (Array.isArray(g.map.tilesets) ? g.map.tilesets : [])
        .filter((entry) => typeof entry?.source === 'string')
        .map((entry) => tilesetUrl(entry.source))))].sort();

    return JSON.stringify([
        input.layout_version ?? null,
        frame.rooms.map((room) => [room.install_id, room.origin?.x ?? null, room.origin?.y ?? null, room.map?.map_version ?? null, room.mapless === true]),
        grids.some((g) => g.install_id === null),
        kinds,
        urls.map((url) => [url, status(url)]),
    ]);
}

/**
 * The routing the walks of ONE render share (walk note item 3): the lattice, built at the first walk that asks and
 * never on a render that carries none, every route over it, and each departure's seeded wait.
 */
function walkRouting({ key, extent, cell, obstacles, desks, draw, character }) {
    const grids = new Map();
    const desksByKey = new Map(desks.map((d) => [d.key, d]));
    // One lattice per walker BODY, each built at the first walk that asks: the seat's character and, for an intern's walk
    // (card#12450), the intern at its drawn size — the body each route keeps clear (card#12442's clearance).
    const built = (body) => {
        const id = `${body.w}x${body.h}`;

        if (!grids.has(id)) {
            grids.set(id, extent === null || cell === null ? null : lattice({
                extent,
                cell,
                obstacles,
                desks: desks.map((d) => ({ key: d.key, rects: [d.slot_rect, d.box].filter((r) => r !== null) })),
                // The body the route keeps clear: the walker's drawn rect, the one its effect is drawn at (card#12442).
                body,
            }));
        }

        return grids.get(id);
    };
    let last = null;

    return {
        key,
        route: (seat, anchor, from, to, body = walkerSize(character)) => {
            const g = built(body);

            last = g;

            return g === null ? null : route(g, from, to, { key: seat, anchor });
        },
        centre: (c) => cellCentre(last, c),
        inRoom: (seat) => (desksByKey.get(seat)?.slot_rect ?? null) !== null,
        // § 5.1 of card#12194's design: a deterministic per-event draw over the character's own key — the character
        // tree's ONE seeded draw (`resources/characters/seed.js`'s `draw()`, § 10.4) — uniform over `0 … WAIT_MAX_FRAMES`.
        // With no character tree held there is no seeded draw, and the walker waits none (§ 9 F14's viewer).
        wait: (seat, cause) => (typeof draw === 'function' ? draw(seat, `elevator-wait:${cause}`) % (WAIT_MAX_FRAMES + 1) : 0),
        get lattice() {
            return built(walkerSize(character));
        },
    };
}

/** A § 6.2 edge row's single-frame forms — the 250 ms fades and eases, one loop frame each. */
const ONE_FRAME = new Set(['A5', 'A11', 'A12', 'A14', 'A17']);

/**
 * The scene for one floor frame, or `null` when the frame composed no floor.
 *
 * @param {object} frame `FloorScreen#draw()`'s frame
 * @param {object} input `{ box, measure, character, draw, themes, maps, hallway, tilesets, layout_version,
 *        failed, reduce, effects, previous }` — `draw` the character tree's seeded draw (`seed.js`'s `draw()`, or
 *        `null` where the tree failed to load); `themes` is `painter.js`'s `themeInputs()`; `maps` is
 *        install_id → the held map document or `null`; `tilesets` is `TilesetLoader#held`; `layout_version` the
 *        building layout's version held; `failed` the asset ids the painter reported; `effects` the animation-log
 *        rows this render wrote; `previous` key → the box the last scene drew
 */
export function buildScene(frame, input) {
    if (frame.floor === null || frame.floor === undefined || frame.rooms.length === 0) {
        return null;
    }

    const failed = new Set(input.failed ?? []);
    const used = new Set();
    const tilesetFailures = new Set();
    const decorative = [];
    const box = input.box;
    const W = box.width;
    const H = box.height;

    const tilesetFor = (url) => {
        const held = input.tilesets.get(url);

        if (held === undefined || held.pending === true) {
            return null;
        }

        if (held.failure !== undefined) {
            tilesetFailures.add(url);

            return null;
        }

        return held.tileset;
    };

    // ── The floor's theme (FLOOR.md § 10.6 item 5, § 9 F23), and whether it can draw (§ 10.6 item 8) ──
    const theme = themeOf(frame.floor, input.themes, failed, used);

    /**
     * One theme document the painter is to draw — the asset it is reported under, the API function, and
     * the inputs the scene hands it (§ 10.6 item 2) — or `null` where the theme cannot draw it: none held,
     * or that asset already reported failed. The painter's flat fallback is drawn under it either way.
     */
    const docOf = (asset, fn, docInput) => {
        if (!theme.drawn) {
            return null;
        }

        used.add(asset);

        return failed.has(asset) ? null : Object.freeze({ asset, theme: theme.name, fn, input: Object.freeze(docInput) });
    };

    // ── Each grid in draw order — the hallway's, then each room's: its plane, its scenery ─────────
    const grids = [];

    if (frame.planned && input.hallway !== null && input.hallway !== undefined) {
        grids.push({ install_id: null, map: input.hallway, origin: { x: 0, y: 0 }, seed: frame.floor.key });
    }

    const byRoom = new Map(frame.rooms.map((room) => [room.install_id, room]));

    for (const installId of frame.draw_order) {
        const room = byRoom.get(installId);
        const map = input.maps.get(installId) ?? null;

        // F16: a room whose map is not drawn has no plane — "every fact, no room".
        if (!room.mapless && map !== null && room.footprint !== null) {
            grids.push({ install_id: installId, map, origin: room.origin, seed: installId, footprint: room.footprint });
        }
    }

    // ⛔ THE FRAME's ONE PER-GRID PRIMITIVE: a plane under the grid's whole extent, opaque over the hallway as
    // a room is (§ 4.2), drawn before that grid's scenery — so it is under everything the author placed. Its
    // flat `--scene-floor` fill is drawn on every paint, and the theme's plane document over it.
    const band = frame.band === null ? null : backWall(frame.band, frame.room);
    const door = threshold(band);
    const planes = [];
    const scenery = [];
    const standing = theme.kinds.filter((kind) => !PLANE_KINDS.includes(kind));
    // The landing is drawn on the plane of the TOPMOST grid in draw order that holds the threshold (§ 10.6 item 7).
    const holds = (g, extent) => door !== null && door.x >= extent.x && door.x < extent.x + extent.w && door.y >= extent.y && door.y < extent.y + extent.h;
    // A room's extent is its footprint (`floor-layout.js`'s `placeRooms()`); the hallway's, its grid at the origin.
    const extents = grids.map((g) => {
        const grid = mapGrid(g.map);
        const f = g.footprint ?? (grid === null ? null : { x: g.origin.x, y: g.origin.y, width: grid.width * grid.tilewidth, height: grid.height * grid.tileheight });

        return grid === null || f === null ? null : { x: f.x, y: f.y, w: f.width, h: f.height, cell: { w: grid.tilewidth, h: grid.tileheight } };
    });
    const landingAt = extents.findLastIndex((e, i) => e !== null && holds(grids[i], e));
    // The walkable model's obstacles (§ 6.2's walk note item 3): every `wall` tile and every standing kind's tile, at
    // the rect the floor would draw it, read from the map's cells — whether or not the theme draws them.
    const obstacles = [];

    grids.forEach((g, i) => {
        const extent = extents[i];

        if (extent === null) {
            return;
        }

        const cells = mapTiles(g.map, g.origin, tilesetFor, g.install_id);

        for (const c of cells) {
            if (c.kind === 'wall' || standing.includes(c.kind)) {
                obstacles.push(Object.freeze({ x: c.x, y: c.y, w: c.w, h: c.h }));
            }
        }
        const of = (kind) => tileRegions(cells.filter((c) => c.kind === kind))
            .flatMap((region) => region.rects)
            .map((r) => Object.freeze({ x: r.x - extent.x, y: r.y - extent.y, w: r.w, h: r.h }));
        const desksOn = mapDesks(g.map).map((o) => Object.freeze({ x: o.x, y: o.y, w: o.width, h: o.height }));
        const asset = `theme:${theme.name}/plane:${g.install_id ?? 'hallway'}`;

        planes.push(Object.freeze({
            install_id: g.install_id,
            x: extent.x,
            y: extent.y,
            w: extent.w,
            h: extent.h,
            doc: docOf(asset, 'plane', {
                w: extent.w,
                h: extent.h,
                seed: g.seed,
                desks: Object.freeze(desksOn),
                walls: Object.freeze(of('wall')),
                accents: Object.freeze(of('accent')),
                cell: Object.freeze(extent.cell),
                band_foot: band !== null && band.y + band.h === extent.y,
                threshold: i === landingAt ? Object.freeze({ x: door.x - extent.x, y: door.y - extent.y }) : null,
            }),
        }));

        // § 10.6 item 6: every standing kind at its tile's cell rect, in tile order (the row-major y-sort).
        for (const cell of cells) {
            if (!standing.includes(cell.kind)) {
                continue;
            }

            const doc = docOf(`theme:${theme.name}/scenery:${cell.kind}`, 'scenery', { kind: cell.kind, w: cell.w, h: cell.h, seed: g.seed, index: cell.index });

            if (doc === null) {
                continue;
            }

            const decoration = typeof cell.properties?.decoration === 'string' ? cell.properties.decoration : null;

            scenery.push(Object.freeze({ room: g.install_id, kind: cell.kind, x: cell.x, y: cell.y, w: cell.w, h: cell.h, decoration, doc }));

            // § 6.3's decorative glow — a floor lamp's — the painter's, inside the tile of the piece it lights.
            if (decoration !== null) {
                decorative.push(Object.freeze({
                    decoration: cell.properties.decoration,
                    x: cell.x,
                    y: cell.y,
                    w: cell.w,
                    h: cell.h,
                    glow: glowOf(cell),
                    // § 6.3: claims nothing — no test reads it, it keeps glowing on a dead feed and
                    // asserts nothing by it, and it is on no element a § 6.2 row draws.
                    bound: '§ 6.3',
                    cycle_ms: DECORATIVE_CYCLE_MS,
                    motion: input.reduce !== true,
                }));
            }
        }
    });

    // ── Desks: inside their slots, the later `id` on top; F21's notices per room ──────────────
    const desks = [];
    const notices = [];
    const models = frame.desks.desks;

    // § 9 F14: a desk draws its placeholder when its art cannot be drawn — the floor's theme held by
    // nothing, its furniture set reported failed (all or nothing, § 10.6 item 8), or its character's.
    const artFor = (desk) => {
        const character = `character:${desk.install_id}/${desk.seat_id}`;
        const set = theme.drawn ? furnitureAsset(theme.name, desk) : null;

        if (desk.character) {
            used.add(character);
        }

        if (set !== null) {
            used.add(set);
        }

        return set === null || failed.has(set) || (desk.character && failed.has(character));
    };

    for (const installId of frame.draw_order) {
        const room = byRoom.get(installId);
        const map = input.maps.get(installId) ?? null;
        const objects = room.mapless || map === null ? [] : mapDesks(map);
        const seatAt = new Map(room.desks.filter((d) => d.slot !== null).map((d) => [d.slot, d.key]));

        if (!room.mapless && map !== null) {
            notices.push(...f21(installId, objects, seatAt, box, models));
        }

        const placed = room.desks
            .map((d) => ({ d, object: d.slot === null ? null : objects[d.slot] }))
            .sort((a, b) => (a.object?.id ?? 0) - (b.object?.id ?? 0) || a.d.key.localeCompare(b.d.key));

        for (const { d, object } of placed) {
            const model = models[d.key];

            if (model === undefined) {
                continue;
            }

            const slot = object === null ? null : {
                x: room.origin.x + object.x,
                y: room.origin.y + object.y,
                w: object.width,
                h: object.height,
            };
            // The box's anchor is the slot's bottom-left — the floor line its author drew — so a
            // slot at least the box's size holds it, and an undersized one's desk is drawn at
            // native size from that anchor, past the slot's edge (F21), neither scaled nor clipped.
            const at = slot === null
                ? { x: room.origin.x + d.grid_index * W, y: room.origin.y }
                : slotOrigin(slot, box);

            desks.push(placeDesk(model, d.key, installId, slot, at, {
                box,
                measure: input.measure,
                character: input.character,
                crest: input.crest ?? null,
                headClear: input.headClear ?? null,
                theme: theme.drawn ? theme.name : null,
                // F16: a mapless room's every desk is the placeholder, in a plain grid.
                placeholder: room.mapless || artFor(model),
                failed,
            }, { overflow: false, slot: d.slot, object_id: object?.id ?? null }));
        }
    }

    // § 9 F23: an unshipped name draws the house theme, and says so.
    if (theme.notice !== null) {
        notices.push(theme.notice);
    }

    // ── § 3.2's overflow row: the strip below the floor, each desk at the box, no slot ────────
    const extent = frame.extent;
    let strip = null;

    if (frame.overflow.length > 0 && extent !== null) {
        const top = extent.y + extent.height + STRIP_GAP;
        const header = fit(STRIP_HEADER, Math.max(W, extent.width), input.measure);
        const headerRect = { x: extent.x, y: top, w: header.w, h: header.h, text: header.text, truncated: header.truncated };
        // § 9 F13 (card#11045): the bench WRAPS — as many boxes to a row as the floor is wide, at least
        // one, and further rows below — so it never runs past the floor's width however many seats it holds.
        const perRow = Math.max(1, Math.floor(extent.width / W));
        const rows = Math.ceil(frame.overflow.length / perRow);

        frame.overflow.forEach((key, i) => {
            const model = models[key];

            if (model === undefined) {
                return;
            }

            const at = { x: extent.x + (i % perRow) * W, y: top + LINE + 4 + Math.floor(i / perRow) * H };

            desks.push(placeDesk(model, key, model.install_id, null, at, {
                box,
                measure: input.measure,
                character: input.character,
                crest: input.crest ?? null,
                headClear: input.headClear ?? null,
                theme: theme.drawn ? theme.name : null,
                placeholder: artFor(model),
                failed,
            }, { overflow: true, slot: null, object_id: null }));
        });

        strip = Object.freeze({
            x: extent.x,
            y: top,
            w: Math.max(extent.width, Math.min(frame.overflow.length, perRow) * W),
            h: LINE + 4 + rows * H,
            header: Object.freeze(headerRect),
        });
    }

    // § 9 F14: each intern's sprite is an asset of its own — a failure falls back for that stool alone
    // (`deskLayout()`), and is named on the strip like any other.
    for (const desk of desks) {
        for (const e of desk.elements) {
            if (e.kind === 'stool') {
                used.add(e.asset);
            }
        }
    }

    // ── The bubbles: § 5.1 rule 5's pass over the base rects, each set on its character ───────────
    placeBubbles(desks, input.measure, W, Object.keys(models));

    // ── The frame: the band over the floor's whole extent (§ 4.2) and the slab under it ────────
    // The band's documents are ONE failure unit (§ 10.6 item 8): the wall and every surround, drawn all or
    // none over the band's flat fallback fill.
    const bandAsset = `theme:${theme.name}/band`;

    if (band !== null && theme.drawn) {
        used.add(bandAsset);
    }

    const bandDocs = band === null || !theme.drawn || failed.has(bandAsset) ? null : Object.freeze({ asset: bandAsset, theme: theme.name, docs: bandDocuments(band) });
    const slab = band === null || extent === null ? null : Object.freeze({
        x: band.x,
        y: extent.y + extent.height,
        w: band.w,
        h: SLAB_H,
    });

    // ── The § 6.2 forms this render draws ──────────────────────────────────────────────────────
    const anchors = new Map(desks.map((desk) => [desk.key, anchorOf(desk)]));
    // The lattice's cell: the smallest tile edge over the floor's grids, the hallway's included (walk note item 3).
    const sizes = extents.filter((e) => e !== null).flatMap((e) => [e.cell.w, e.cell.h]);
    const walking = walkRouting({
        key: latticeKey(frame, input, grids, theme.kinds),
        extent: extent === null ? null : { x: extent.x, y: extent.y, width: extent.width, height: extent.height },
        cell: sizes.length === 0 ? null : Math.min(...sizes),
        obstacles,
        desks,
        draw: input.draw ?? null,
        character: input.character,
    });
    // card#12705: the spaced spots walkers wait at by the elevator (walk note item 9) — on the seat walker's lattice, whose
    // body is the widest, so a spot open to it is open to an intern — built on the holder's first ask, never otherwise.
    let spots = null;
    const queueSpot = (k) => {
        const door = threshold(band);
        const grid = door === null ? null : walking.lattice;

        if (grid === null) {
            return null;
        }

        spots ??= spotsNear(grid, door, queueSpacing(input.character));

        return spots(k);
    };
    const effects = buildEffects(input.effects ?? [], frame, anchors, input.previous ?? new Map(), extent, band, input.reduce === true, input.character, box, walking, {
        desks: new Map(desks.map((d) => [d.key, d])),
        // card#12450: the intern walks the holder carries into this render, and each intern's laptop opacity there
        // (`floor/in-flight.js`) — what a turn-round turns from and a departure fades from.
        in_flight: input.interns_in_flight ?? new Map(),
        laptop_at: input.laptop_at ?? (() => null),
    });

    // § 9 F14: every asset the scene asked to be drawn that the painter reported failed, and every
    // tileset that could not be fetched or read.
    const failedAssets = [...new Set([...[...used].filter((id) => failed.has(id)), ...tilesetFailures])].sort();

    const all = [
        ...(band === null ? [] : [{ x: band.x, y: band.y, w: band.w, h: band.h }]),
        ...(extent === null ? [] : [{ x: extent.x, y: extent.y, w: extent.width, h: extent.height }]),
        ...(slab === null ? [] : [slab]),
        ...desks.map((desk) => desk.box),
        ...(strip === null ? [] : [strip]),
    ];

    return Object.freeze({
        extent: union(all),
        band,
        slab,
        band_docs: bandDocs,
        planes: Object.freeze(planes),
        scenery: Object.freeze(scenery),
        desks: Object.freeze(desks),
        strip,
        effects,
        decorative: Object.freeze(decorative),
        notices: Object.freeze(notices),
        theme: theme.resolved,
        failed: Object.freeze(failedAssets),
        art_failed: failedAssets.length > 0,
        // Where each desk's travellers and walks meet it — what the NEXT render's walks start from.
        anchors: Object.freeze(Object.fromEntries(anchors)),
        // card#12450: each desk's interns' slot feet, in slot order — what an intern's walk is routed to and from, and the
        // anchor the holder's item 17 compares a walk in flight against.
        slot_feet: Object.freeze(Object.fromEntries(desks.map((d) => [d.key,
            Object.freeze(stoolSlots(box, input.character).map((_, slot) => slotFoot(d, slot, box, input.character)))]))),
        // What the walkable model reads, fingerprinted — every render's, so the holder can cancel a walk routed on another.
        lattice_key: walking.key,
        // card#12705: spot `k` by the elevator, `{at, path}` (`spotsNear()`'s), or `null` past the last the floor holds — what the holder
        // (`floor/in-flight.js`) stands each waiting departure at. A function, so a render that queues nobody builds none.
        queue_spot: queueSpot,
        loop_fps: LOOP_FPS,
    });
}

/**
 * Where a desk's box stands in its slot: the slot's bottom-left — the floor line its author drew — so a slot at least
 * the box's size holds it, and an undersized one's desk is drawn at native size from that anchor, past the slot's
 * edge (F21). Exported for the route probe, which places the shipped default's desks by the same rule.
 */
export function slotOrigin(slot, box) {
    return { x: slot.x, y: slot.y + slot.h - box.height };
}

/**
 * WHERE A DESK's CHARACTER's DRAWN HEAD IS (§ 5.1, the seat's ruling of 2026-10-27 on card#12444: the thought trail reaches
 * each character's drawn head): its topmost ink, in the tree's frame units under the character rect's top — the tree's
 * `crestTop()` for the seat's key, its delivered species and its pose, which the page hands the scene as `crest` once the
 * tree has loaded — and what its bubble must leave uncovered, the tree's `headClear()` (card#12446), handed as
 * `headClear`. With no tree, no drawn character, or a species the tree does not hold (§ 9 F14, which the painter draws
 * as the placeholder), `{ crest: 0, clear: null }`: the rect's top, the line the tallest crest reaches, and a bubble set
 * as high as any seat's (`bubbleSeat()`).
 */
function headOf(model, installId, elements, ctx) {
    const character = elements.find((e) => e.kind === 'character');
    const none = { crest: 0, clear: null };

    if (character === undefined || typeof ctx.crest !== 'function' || typeof ctx.headClear !== 'function' || typeof character.species !== 'string') {
        return none;
    }

    const at = { pose: character.pose, glyph: character.glyph, species: character.species, clear: character.clear };

    try {
        return { crest: ctx.crest(installId, model.seat_id, at), clear: ctx.headClear(installId, model.seat_id, at) };
    } catch {
        return none;
    }
}

/**
 * One desk placed at `at`: its box, its elements in scene coordinates, and their union. Exported for
 * the desk leaf guard (`TheNewDeskKeepsEveryLeafTest`), which reads a desk as the painter is handed it.
 */
export function placeDesk(model, key, installId, slot, at, ctx, extra) {
    const { elements, bubble } = deskLayout(model, ctx);
    const head = headOf(model, installId, elements, ctx);
    const seat = bubbleSeat(ctx.box, ctx.character, head.clear, head.crest);
    // A character's or an intern's overdraw (§ 10.4's frame contract) moves with its element; a sleeper's clear rects are frame units and do not.
    const moved = elements.map((e) => Object.freeze({
        ...e,
        x: at.x + e.x,
        y: at.y + e.y,
        ...(e.overdraw === undefined ? {} : { overdraw: Object.freeze({ ...e.overdraw, x: at.x + e.overdraw.x, y: at.y + e.overdraw.y }) }),
    }));

    return {
        key,
        install_id: installId,
        seat_id: model.seat_id,
        ...extra,
        slot_rect: slot === null ? null : Object.freeze(slot),
        box: Object.freeze({ x: at.x, y: at.y, w: ctx.box.width, h: ctx.box.height }),
        // The desk's DEPTH KEY (§ 6.2's walk note item 9, § 12's *Depth key*): its furniture foot line — the line the
        // side table and the interns stand on — which a walker's feet are compared with to stack it in front or behind.
        depth_y: at.y + (({ y, h }) => y + h)(deskRects(ctx.box, ctx.character)['desk-sprite']),
        // FLOOR.md § 10.6: where the bubble, the coordination birds and the walks meet this desk — its sitter's
        // centre line, through the one primitive (`characterCentre()`), and the height the birds and the walks
        // meet it at, the desk's own mid-height, below every facts plate (`deskAnchor()`, card#11468).
        anchor_x: at.x + characterCentre(ctx.box, ctx.character),
        anchor_y: at.y + deskAnchor(ctx.box, ctx.character).y,
        // § 5.1: where the bubble is set on its character and where its thought trail starts, in scene coordinates —
        // drawn only with a bubble (`bubbleSeat()`: as low as the face and the pose's mark allow, card#12446).
        bubble_bottom: at.y + seat.bottom,
        trail_foot: Object.freeze({ x: at.x + trailFoot(ctx.box, ctx.character).x, y: at.y + seat.foot }),
        placeholder: ctx.placeholder,
        elements: Object.freeze(moved),
        furniture: Object.freeze(union(moved)),
        held: model.held,
        // § 6.2 A23 (card#12448): the side table's held rendering, beside the desk's own.
        interns_held: model.interns_held,
        lighting: model.lighting,
        bubble_model: bubble,
        bubble: null,
    };
}

/**
 * § 5.1 rules 4 and 5: each bubble's title wrapped to `BUBBLE_TITLE_LINES` lines through the one wrap and its source line
 * cut through the one primitive, at most `BUBBLE_MAX_W` wide, its box sized from the MEASURED lines, and overlapping
 * bubbles parted by `desk/task-bubble.js`'s own pass over the base rects. THE BASE RECT IS SET ON ITS CHARACTER (the
 * operator's ruling of 2026-10-27 on card#12444, *"anchor the bubble to the character"*), as low as its face and its
 * pose's mark allow (card#12446, `bubbleSeat()`), and the trail drawn from where `bubbleSeat()` starts it up to wherever
 * the bubble ends (`trailCircles()`). A bubble no wider than the art column never reaches the facts plate, and
 * `leastBoxHeight()` keeps the tallest inside the box. Exported, with `placeDesk()`, for the desk leaf guard.
 */
export function placeBubbles(desks, measure, W, delivered) {
    const inner = Math.min(W, BUBBLE_MAX_W) - 2 * BUBBLE_PAD;
    const wanted = [];
    // ⛔ HANDED OVER IN THE ORDER THE CLIENT HOLDS THE SEATS, which is the order they were DELIVERED
    // — deliberately not an order this module fixes, because rule 5's determinism is
    // `bubbleLayout()`'s own sort and must not rest on a caller that happened to sort first
    // (AT-D3-20's sixth RED plants exactly that sort away).
    const order = new Map(delivered.map((key, i) => [key, i]));
    const byDelivery = [...desks].sort((a, b) => (order.get(a.key) ?? 0) - (order.get(b.key) ?? 0));

    for (const desk of byDelivery) {
        const b = desk.bubble_model;

        delete desk.bubble_model;

        if (b === null || b === undefined) {
            continue;
        }

        const title = wrap(b.text, inner, measure, 'fact', BUBBLE_TITLE_LINES);
        const second = [b.source, b.degraded_note].filter((s) => s !== null && s !== undefined);
        const line2 = second.length === 0 ? null : fit(second.join(' · '), inner, measure);
        const w = Math.max(title.w, line2?.w ?? 0) + 2 * BUBBLE_PAD;
        const h = title.h + (line2 === null ? 0 : LINE) + 2 * BUBBLE_PAD;
        // Above the character's column, kept inside the box: the anchor is the character's centre
        // line, moved only as far as the bubble's own width needs to stay within the box.
        const centre = Math.min(Math.max(desk.anchor_x, desk.box.x + w / 2), desk.box.x + W - w / 2);

        wanted.push({
            install_id: desk.install_id,
            seat_id: desk.seat_id,
            text: title.text,
            anchor: { x: centre, y: desk.bubble_bottom },
            title,
            second: line2,
            size: { w, h },
            source: b.source,
            degraded_note: b.degraded_note,
            truncated: b.truncated || title.truncated,
        });
    }

    const placed = bubbleLayout(wanted, (text, bubble) => bubble.size);
    const bySeat = new Map(placed.map((rect) => [`${rect.install_id}/${rect.seat_id}`, rect]));

    for (const desk of desks) {
        // The trail is the bubble's: a desk with no bubble draws none, and keeps no copy of where it would start.
        const foot = desk.trail_foot;

        delete desk.trail_foot;
        delete desk.bubble_bottom;

        const want = wanted.find((b) => b.install_id === desk.install_id && b.seat_id === desk.seat_id);

        if (want === undefined) {
            continue;
        }

        const rect = bySeat.get(`${desk.install_id}/${desk.seat_id}`);

        desk.bubble = Object.freeze({
            x: rect.x,
            y: rect.y,
            w: rect.w,
            h: rect.h,
            lifted: rect.lifted,
            text: want.title.text,
            text_w: want.title.w,
            // The title's lines, top first, each as measured (card#12446); the source line is drawn under the last.
            lines: Object.freeze(want.title.lines.map((l) => Object.freeze({ ...l }))),
            truncated: want.truncated,
            second: want.second?.text ?? null,
            source: want.source,
            degraded_note: want.degraded_note,
            // § 5.1 (card#11468): the cloud round the measured rect, and the trail of growing circles from just
            // above the creature to the cloud, on the anchor's line — to the bubble where it was set, parted or not.
            cloud: cloudOutline(rect),
            trail: trailCircles(foot.x, foot.y, rect.y + rect.h),
        });
    }

    for (const desk of desks) {
        Object.freeze(desk);
    }
}

/**
 * Every tile a map draws, in document order bottom first (§ 10.3's `layers[]` row), each cell
 * resolved to its tileset image and source rectangle — an image-collection tile is drawn at its own
 * size, bottom-aligned to its cell, as Tiled draws one.
 */
export function mapTiles(map, origin, tilesetFor, owner) {
    const grid = mapGrid(map);

    if (grid === null) {
        return [];
    }

    // Each `tilesets[]` entry by its `firstgid`, highest first, so a GID finds its own.
    const sets = (Array.isArray(map.tilesets) ? map.tilesets : [])
        .map((entry) => ({
            firstgid: entry?.firstgid ?? 1,
            tileset: typeof entry?.source === 'string' ? tilesetFor(tilesetUrl(entry.source)) : safeEmbedded(entry),
            url: typeof entry?.source === 'string' ? tilesetUrl(entry.source) : null,
        }))
        .sort((a, b) => b.firstgid - a.firstgid);

    const out = [];

    // § 10.3's layer tree, groups walked, through the one walk `mapDesks()` reads it by too.
    for (const { layer, offset: here, opacity: alpha, visible } of mapLayers(map)) {
        if (!visible || layer.type !== 'tilelayer' || !Array.isArray(layer.data)) {
            continue;
        }

        const columns = layer.width ?? grid.width;

        layer.data.forEach((cell, i) => {
            // Tiled's flip bits are stripped and never carried: the floor draws every tile unrotated (§ 10.6 item 6).
            const { gid } = splitGid(cell);

            if (gid === 0) {
                return;
            }

            const set = sets.find((s) => gid >= s.firstgid);

            if (set === undefined) {
                return;
            }

            const tile = set.tileset?.tile(gid - set.firstgid) ?? null;
            const col = i % columns;
            const row = Math.floor(i / columns);
            const offsetX = set.tileset?.offset.x ?? 0;
            const offsetY = set.tileset?.offset.y ?? 0;
            const w = tile?.sw ?? grid.tilewidth;
            const h = tile?.sh ?? grid.tileheight;

            out.push({
                room: owner,
                layer: layer.name ?? null,
                x: origin.x + here.x + col * grid.tilewidth + offsetX,
                y: origin.y + here.y + (row + 1) * grid.tileheight - h + offsetY,
                w,
                h,
                image: tile?.image ?? null,
                // The image's own size, so a sheet tile is drawn as a window onto its sheet.
                iw: tile?.iw ?? w,
                ih: tile?.ih ?? h,
                tileset: set.url,
                sx: tile?.sx ?? 0,
                sy: tile?.sy ?? 0,
                sw: w,
                sh: h,
                opacity: alpha,
                properties: tile?.properties ?? null,
                // § 10.6 item 6: what the floor's theme draws the tile as — never its image.
                kind: typeof tile?.properties?.kind === 'string' ? tile.properties.kind : null,
                // The cell's place in its layer — a standing piece's seeded choices are the cell's.
                index: i,
            });
        });
    }

    return out.map((cell) => Object.freeze(cell));
}

/**
 * WHAT A TILE IS, TO THE FLOOR — its KIND and its cell's size, and nothing about its image (FLOOR.md § 10.6
 * item 6: the floor draws a placed tile by its kind in the floor's theme and never draws the tile's image).
 * Tiles of one look are one kind at one size, so `tileRegions()` joins them on it into the runs a plane
 * document draws.
 */
export function tileLook(t) {
    return Object.freeze({ kind: t.kind, w: t.w, h: t.h });
}

/**
 * The tiles of one grid as REGIONS — THE SCENE's ONE REGION PASS (§ 10.6 item 6): a region is one tile and
 * every tile of the same look (`tileLook()`) joined to it edge to edge on its lattice — its copies at
 * `x + c·w`, `y + r·h`. Every tile is in exactly one region; a tile with no neighbour of its look is a region
 * of one. `rects` is the region's area, row by row: each run of copies side by side in a row is one rect,
 * and the rects never overlap. A grid's `wall` and `accent` cells reach its plane document as these rects.
 *
 * ⛔ WHY ONE AREA AND NOT ITS CELLS: a run drawn cell by cell has an edge of its own at every cell, and where
 * that edge lands on a fractional device pixel the pair leaves a hairline of whatever is under them (a dotted
 * ladder down a wall). The plane document draws a run as one shape, so its cells meet at no edge of their own.
 *
 * ⛔ A NEIGHBOUR IS FOUND BY ITS PLACE TO 1/1024 px, NEVER BY ITS EXACT COORDINATES. `mapTiles()` places a
 * cell at `origin + offsets + col·w`, and a group or tileset offset such as 0.1 or 1/3 makes that differ in
 * its last bits from the `x + w` the lookup computes, so an exact match misses identical neighbours. A dyadic
 * coordinate of ten fractional bits or fewer is exact at that quantum, and one of up to six decimal places
 * (or a third) lies at least 10⁻⁶ of a quantum from a rounding edge, where a sum's drift on a floor-sized
 * coordinate is orders of magnitude smaller. Inside a region a copy's place is its whole-number column and
 * row on the region's lattice, so `rects` are on that lattice exactly.
 *
 * ⛔ THE DRAW ORDER IS KEPT WHERE IT IS VISIBLE. A region is drawn where its first tile was, which moves
 * its later copies earlier; that is only allowed past tiles they do not overlap. A copy that would move
 * past a tile it overlaps — so that tile would now be drawn over a copy it used to be under — is held out
 * of the region and starts one of its own at its own place, and the regions are found again until the
 * order holds for every copy.
 *
 * @param {list<object>} tiles one pass's tiles, in draw order (`mapTiles()`'s cells)
 * @returns {list<{look: object, x: number, y: number, rects: list<{x: number, y: number, w: number, h: number}>}>}
 *          in draw order; `look` is the region's (`tileLook()`), and `x`, `y` its first tile's place — the
 *          origin of its lattice
 */
export function tileRegions(tiles) {
    const spot = (x, y) => `${Math.round(x * 1024)},${Math.round(y * 1024)}`;

    // Each look by a small number, so a place is looked up by a short key.
    const kinds = new Map();
    const looks = [];
    const keys = tiles.map((t) => {
        const look = tileLook(t);
        const kind = JSON.stringify(look);

        if (!kinds.has(kind)) {
            kinds.set(kind, kinds.size);
            looks.push(look);
        }

        return kinds.get(kind);
    });
    const at = new Map();

    tiles.forEach((t, i) => {
        const k = `${keys[i]}@${spot(t.x, t.y)}`;

        // Two identical tiles at one place (two layers) stay apart: the later one is a region of its own.
        if (!at.has(k)) {
            at.set(k, i);
        }
    });

    const heldOut = new Set();
    const regionOf = new Array(tiles.length);
    let regions;

    for (;;) {
        regionOf.fill(-1);
        regions = [];

        tiles.forEach((t, first) => {
            if (regionOf[first] !== -1) {
                return;
            }

            const { w, h } = looks[keys[first]];
            const id = regions.length;
            const members = [first];
            const cells = [[0, 0]];

            regionOf[first] = id;

            // Every tile of its look reachable edge to edge — a held-out tile joins no region but its own.
            for (let m = 0; m < members.length && !heldOut.has(first); m++) {
                const here = tiles[members[m]];

                for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
                    const i = at.get(`${keys[first]}@${spot(here.x + dx * w, here.y + dy * h)}`);

                    // Later than `first` (an earlier tile is already in a region), and never `first` itself —
                    // which a tile of no width or height would otherwise find at every step.
                    if (i !== undefined && i > first && regionOf[i] === -1 && !heldOut.has(i)) {
                        regionOf[i] = id;
                        members.push(i);
                        cells.push([cells[m][0] + dx, cells[m][1] + dy]);
                    }
                }
            }

            regions.push({ first, members, cells });
        });

        const late = misordered(tiles, regions, regionOf);

        if (late.length === 0) {
            break;
        }

        for (const m of late) {
            heldOut.add(m);
        }
    }

    return regions.map(({ first, cells }) => {
        const look = looks[keys[first]];
        const { x, y } = tiles[first];
        const runs = [];

        // Row by row, each run of whole-number columns side by side one rect.
        for (const [c, r] of [...cells].sort((a, b) => a[1] - b[1] || a[0] - b[0])) {
            const last = runs[runs.length - 1];

            if (last !== undefined && last.r === r && last.c + last.n === c) {
                last.n += 1;
            } else {
                runs.push({ c, r, n: 1 });
            }
        }

        const rects = runs.map(({ c, r, n }) => Object.freeze({ x: x + c * look.w, y: y + r * look.h, w: n * look.w, h: look.h }));

        return Object.freeze({ look, x, y, rects: Object.freeze(rects) });
    });
}

/**
 * The copies a region draws too early: a member `m` of region `R` is now drawn at `R`'s first tile, so
 * every tile between the two in the old order that `m` overlaps must still be drawn before it — in a
 * region that starts before `R` does. Each such member is returned, to be held out of `R`.
 */
function misordered(tiles, regions, regionOf) {
    const CELL = 64;
    const span = (t) => [Math.floor(t.x / CELL), Math.floor((t.x + t.w) / CELL), Math.floor(t.y / CELL), Math.floor((t.y + t.h) / CELL)];
    const grid = new Map();

    tiles.forEach((t, i) => {
        const [x0, x1, y0, y1] = span(t);

        for (let gx = x0; gx <= x1; gx++) {
            for (let gy = y0; gy <= y1; gy++) {
                const k = `${gx},${gy}`;

                if (!grid.has(k)) {
                    grid.set(k, []);
                }

                grid.get(k).push(i);
            }
        }
    });

    const overlap = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
    const late = [];

    regions.forEach(({ first, members }, r) => {
        for (const m of members) {
            const [x0, x1, y0, y1] = span(tiles[m]);
            let crossed = false;

            for (let gx = x0; gx <= x1 && !crossed; gx++) {
                for (let gy = y0; gy <= y1 && !crossed; gy++) {
                    crossed = (grid.get(`${gx},${gy}`) ?? []).some((a) => a > first && a < m && regionOf[a] !== r
                        && regions[regionOf[a]].first > first && overlap(tiles[a], tiles[m]));
                }
            }

            if (crossed) {
                late.push(m);
            }
        }
    });

    return late;
}

function safeEmbedded(entry) {
    try {
        return readEmbedded(entry);
    } catch {
        return null;
    }
}

/**
 * § 9 F21 for one room, from the map alone: its `desks` objects pairwise on step 7's half-open
 * footprint test (`floor/floor-layout.js`'s own, F18's), and each against the furniture box.
 */
function f21(installId, objects, seatAt, box, models) {
    const seat = (index) => {
        const key = seatAt.get(index);

        return key === undefined ? null : (models[key]?.seat_id ?? null);
    };
    const rect = (o) => ({ x: o.x, y: o.y, width: o.width, height: o.height });
    const notices = [];

    for (let i = 0; i < objects.length; i++) {
        for (let j = i + 1; j < objects.length; j++) {
            if (footprintsIntersect(rect(objects[i]), rect(objects[j]))) {
                notices.push(intersectNotice(objects[i].id, objects[j].id, installId, [seat(i), seat(j)]));
            }
        }
    }

    objects.forEach((o, i) => {
        if (o.width < box.width || o.height < box.height) {
            notices.push(undersizedNotice(o.id, installId, [seat(i)]));
        }
    });

    return notices;
}

/**
 * § 4.2's back wall over one span (`floor-layout.js`'s `backWallBand()`): the wall, its skirting, the
 * two-door elevator and the wall clock in the reserved zone, and the windows past it. Its height is
 * this layer's; its span is the frame's, widened to the reserved zone where the floor is narrower.
 *
 * ⛔ AT REST NOTHING ON THE BAND MEETS THE CLOCK's FACE (AT-D3-20's clock clause): the elevator ends left
 * of it and every window, sill included, starts right of the zone. `Tests\Feature\Floor\TheFloorDrawsItsFrameTest`
 * sweeps every width from 1 px.
 *
 * @param {{x: number, y: number, width: number}} span where the band stands: its left, the slab's top, its width
 * @param {object|null} room `roomTick()`'s value — the clock's hands and the windows' sky — or `null`
 */
export function backWall(span, room) {
    const w = Math.max(span.width, ZONE_W);
    const y = span.y - BAND_H;
    const floor = span.y;
    const windows = [];
    const remaining = w - ZONE_W - WINDOW.margin;
    const n = remaining < WINDOW.min_w + WINDOW.gap ? 0 : Math.max(1, Math.floor(remaining / WINDOW.pitch));

    for (let i = 0; i < n; i++) {
        const cell = remaining / n;
        // At least `min_w`: the threshold above holds one minimum window and its gap in a cell.
        const ww = Math.min(WINDOW.w, cell - WINDOW.gap);
        const x = span.x + ZONE_W + cell * (i + 0.5) - ww / 2;
        const top = y + WINDOW.dy;

        windows.push(Object.freeze({
            x,
            y: top,
            w: ww,
            h: WINDOW.h,
            sky: room?.sky ?? null,
            // The rect the floor's theme draws the window's frame, mullions, curtains, rod and sill in (§ 10.6
            // item 2): the glazing grown by the surround margin. The glazing itself is the painter's (A17).
            surround: Object.freeze({
                x: x - SURROUND.side,
                y: top - SURROUND.above,
                w: ww + 2 * SURROUND.side,
                h: WINDOW.h + SURROUND.above + SURROUND.below,
            }),
        }));
    }

    const lift = { x: span.x + ELEVATOR.dx, y: floor - ELEVATOR.h, w: ELEVATOR.w, h: ELEVATOR.h };
    const frameRect = { x: lift.x - ELEVATOR.frame, y: lift.y - ELEVATOR.header, w: lift.w + 2 * ELEVATOR.frame, h: lift.h + ELEVATOR.header };

    return Object.freeze({
        x: span.x,
        y,
        w,
        h: BAND_H,
        // The reserved zone the elevator and the clock hang in — no window enters it.
        zone: Object.freeze({ x: span.x, y, w: ZONE_W, h: BAND_H }),
        // The two-door elevator: its two leaves (the painter's, in the building's door colours), its frame,
        // and the surround the floor's theme draws the frame, the header plate, the lamp, the recess and the
        // call buttons in — the frame grown 18 px to the right (§ 10.6 item 2).
        elevator: Object.freeze({
            ...lift,
            frame: Object.freeze(frameRect),
            surround: Object.freeze({ ...frameRect, w: frameRect.w + SURROUND.elevator_reach }),
            seam: lift.x + lift.w / 2,
        }),
        // § 6.5: a room with no value is drawn as having none — `set: false`, never a plausible time.
        // Hung in the zone right of the elevator, raised to the band's top (card#12241).
        clock: Object.freeze({
            x: span.x + CLOCK.dx,
            y: y + CLOCK.dy,
            w: CLOCK.size,
            h: CLOCK.size,
            set: room !== null,
            hour_angle_deg: room?.hour_angle_deg ?? null,
            minute_angle_deg: room?.minute_angle_deg ?? null,
            text: room?.text ?? null,
            label: room?.label ?? null,
        }),
        portal: birdPortal(span.x, y, room?.sky ?? null),
        windows: Object.freeze(windows),
    });
}

/**
 * The birdhouse's geometry, band-relative to `left` and the band's top `top` (card#12241's measured layout):
 * its centre `c` is 22 px right of the roof's left edge, the roof's left edge `PORTAL.gap` px right of the
 * clock face; `c.y` is 28 px under the clock's top, so the roof's 28 px gable peaks level with it. The MOUTH
 * is the hole's centre — where a portal bird enters and leaves (COORD-STORE.md § 6.1), hopping between it and the
 * `perch` under it (card#12445). `sky` is the room's sky phase, `null` when the room has no value (§ 6.5).
 */
function birdPortal(left, top, sky) {
    const cx = left + CLOCK.dx + CLOCK.size + PORTAL.gap + PORTAL.roof_w / 2;
    const cy = top + CLOCK.dy + 28;

    return Object.freeze({
        x: cx,
        y: cy,
        roof: Object.freeze({ x: cx - PORTAL.roof_w / 2, w: PORTAL.roof_w, peak_y: cy - 28, eave_y: cy - 12 }),
        body: Object.freeze({ x: cx - PORTAL.body / 2, y: cy - 12, w: PORTAL.body, h: PORTAL.body }),
        bracket: Object.freeze({ x: cx - PORTAL.bracket_w / 2, y: cy + 22, w: PORTAL.bracket_w, h: PORTAL.bracket_h }),
        mouth: Object.freeze({ x: cx, y: cy + 2, r: PORTAL.hole_r }),
        // The peg perch under the hole, seen end-on: from the wall (`top`) to its tip nearest the viewer (`tip`), where a
        // portal bird stands (card#12445, `bird.js`'s `perchPoint()`).
        perch: Object.freeze({ x: cx, top: cy + 2 + PORTAL.hole_r + 3, tip: cy + 2 + PORTAL.hole_r + 8 }),
        // The hole shows the windows' own sky (A17's value), so the portal never shows a second time of day.
        sky,
    });
}

/** Where an envelope or a walk meets a desk: the character's column, at the desk's own mid-height (`deskAnchor()`). */
function anchorOf(desk) {
    return Object.freeze({ x: desk.anchor_x, y: desk.anchor_y });
}

/** § 6.2's form for one row — its class, whether it loops, and § 6.4's rendering. */
function form(animationId, reduce) {
    const row = ANIMATION_SET[animationId];

    return {
        class: row.class,
        loops: loops(animationId),
        form: reduce ? row.reduced : row.animation,
        motion: !reduce,
    };
}

/**
 * One bird's flight from `from` to `to` carrying an envelope — A19's traveller and both halves of A22's
 * portal bird share it, so the two forms cannot drift apart. `rest` is how many frames the envelope rests
 * where the bird set it down (none when it flies into the birdhouse). With no motion it is the still
 * envelope at `to`, shown for the moving form's own duration (§ 6.2's reduced cells).
 *
 * card#12445: `portal` — `{ ledge, hole, leg }` for A22's bird, `null` for A19's — puts the bird on the birdhouse's
 * perch for § 12's *Ledge dwell* and hops it between the perch and the hole: going out it flies to the perch, stands,
 * and hops in (flight → dwell → hop); coming in it hops out onto the perch, stands, and flies (hop → dwell → flight).
 * `perch` carries each part's first frame from the effect's start, so the painter draws the timeline and decides
 * none of it; `span_frames` is the whole moving form. `wait_frames` is the in-flight holder's — the frames the bird
 * waits for the ledge another bird holds (`EffectsInFlight`) — and every part starts that much later.
 */
function travel(base, from, to, moving, rest, portal = null) {
    const flight = Math.max(1, Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / ENVELOPE_PX_PER_FRAME));
    const out = portal?.leg === 'departure';
    const perch = portal === null ? null : Object.freeze({
        point: portal.ledge,
        hole: portal.hole,
        hop: out ? 'in' : 'out',
        dwell_start: out ? flight : HOLE_HOP_FRAMES,
        dwell_frames: LEDGE_DWELL_FRAMES,
        hop_start: out ? flight + LEDGE_DWELL_FRAMES : 0,
        hop_frames: HOLE_HOP_FRAMES,
    });
    const atPerch = perch === null ? 0 : LEDGE_DWELL_FRAMES + HOLE_HOP_FRAMES;

    return Object.freeze({
        ...base,
        from,
        to,
        // The operator's QA ruling (card#7341 scope addition 3): the traveller faces its direction of
        // travel — card#12241's bird flips to it.
        heading_deg: Math.atan2(to.y - from.y, to.x - from.x) * 180 / Math.PI,
        frames: moving ? flight : 0,
        flight_start: moving && portal !== null && !out ? atPerch : 0,
        rest_frames: moving ? rest : 0,
        perch: moving ? perch : null,
        wait_frames: 0,
        span_frames: moving ? flight + rest + atPerch : 0,
        // The reduced form is transient too: shown for the moving form's own duration.
        still_frames: moving ? 0 : flight + rest + atPerch,
    });
}

/**
 * WHERE AN INTERN SITS, in scene coordinates (item 13): its slot's foot — the slot rect's bottom-centre in the desk's
 * box (`stoolSlots()`), the point the chibi frame stands on — or `null` for a desk this scene does not draw.
 */
function slotFoot(desk, slot, box, character) {
    const s = desk === undefined ? null : stoolSlots(box, character)[slot] ?? null;

    return s === null ? null : Object.freeze({ x: desk.box.x + s.x + s.w / 2, y: desk.box.y + s.y + s.h });
}

/**
 * ONE A10 ROW's WALKS (§ 6.2's *Interns ride the elevator too*, items 13–16; card#12450): an effect per intern the delta
 * gained (an arrival) or lost (a departure, or item 16's turn-round of an arrival still in flight), each keyed
 * `<seat key>~<call_id>` and drawn on § 6.2's walk primitive — the seat's own A1/A2 legs, routed for the intern's body
 * between its slot's foot and the threshold, with a `seat` leg for its laptop's fade.
 *
 * ⛔ AT THE CAP NOTHING WALKS (item 15): where `subagents_open` exceeds 8 on either side of the delta the array is a
 * reduction, so a lost `call_id` is not a stop and a gained one not a spawn — the stool fills or empties as the row's
 * reduced form draws it, and an arrival in flight for an intern the cap dropped is a plain cancel (the holder's).
 *
 * The departure's wait is A2's seeded draw over the INTERN's key, `draw(seat~<call_id>, elevator-wait:<cause>)`, so two
 * interns leaving on one delta wait apart and every viewer agrees.
 */
function internWalks(row, base, frame, interns, band, moving, character, box, walking) {
    const seat = `${row.install_id}/${row.seat_id}`;
    const move = (frame.desks.interns ?? []).find((m) => m.key === seat && m.cause === row.cause) ?? null;

    if (move === null || !move.exact || !moving) {
        return [];
    }

    const desk = interns.desks.get(seat);
    const door = threshold(band);
    // Where every intern walk meets the elevator — the seat walks' threshold (`threshold()`), one frozen point.
    const sill = door === null ? null : Object.freeze({ x: door.x, y: door.y });
    const D = ELEVATOR_DOOR;
    const out = [];
    const shape = (element, kind) => {
        const key = `${seat}~${element.call_id}`;
        const anchor = slotFoot(desk, element.slot, box, character);

        return {
            ...base,
            call_id: element.call_id,
            intern_key: key,
            intern_seat_key: internKey(row.seat_id, element.call_id),
            slot: element.slot,
            anchor,
            leg_kind: kind,
            activity_state: element.activity_state ?? null,
            // card#12443: the body the intern must not wear — its seat's delivered species.
            seat_species: frame.desks.desks[seat]?.species ?? null,
            lattice_key: walking.key,
            size: INTERN_SIZE,
            frame: Object.freeze({ w: STOOL_FRAME, h: STOOL_H }),
            side: null,
        };
    };
    const routed = (fx, from, to) => {
        const r = fx.anchor === null || door === null ? null : walking.route(seat, fx.anchor, from, to, INTERN_SIZE);

        if (r === null) {
            return null;
        }

        const points = Object.freeze([from, ...r.cells.map((c) => walking.centre(c)), to].map((p) => Object.freeze({ x: p.x, y: p.y })));

        return { points, length: polylineLength(points), tier: r.tier };
    };
    const walk = (start, r) => walkLeg(start, r.points);
    const leg = (kind, start, frames, at, why = null) => Object.freeze({ kind, start, frames, at, ...(why === null ? {} : { why }) });
    const done = (fx, legs, doorSpan, tier, queue = null) => Object.freeze({
        ...fx,
        route: 'routed',
        clearance: tier,
        legs: Object.freeze(legs),
        door: Object.freeze(doorSpan),
        ...(queue === null ? {} : { queue }),
        no_route: false,
        frames: Math.max(...legs.map((l) => l.start + l.frames), doorSpan.start + doorSpan.frames),
    });
    // A departure from the slot (item 13): the laptop fades out from `from` (a `seat` leg; none when it is already 0),
    // then the walk, the seeded wait and the door — A2's queue at the elevator (item 14).
    const departure = (fx, from) => {
        const r = routed(fx, fx.anchor, door);

        if (r === null) {
            return null;
        }

        const F = from > 0 ? LAPTOP_FADE : 0;
        const gone = departing(F > 0 ? [Object.freeze({ ...leg('seat', 0, F, fx.anchor), laptop_from: from, laptop_to: 0 })] : [],
            walk(F, r), walking.wait(fx.intern_key, row.cause), sill);

        return done(fx, gone.legs, gone.door, r.tier, gone.queue);
    };

    for (const element of move.gained) {
        const fx = shape(element, 'arrive');
        const r = door === null ? null : routed(fx, door, fx.anchor);

        if (r === null) {
            continue;
        }

        const w = walk(D.open + D.step, r);
        const legs = [leg('cab', 0, D.open, sill), leg('stand', D.open, D.step, sill, 'step'), w];

        // The laptop fades in at the stool — read off the intern's HELD state at each paint, so one that went idle on
        // the way sits with none; an intern that arrives idle has no `seat` leg at all.
        if (fx.activity_state === 'working') {
            legs.push(Object.freeze({ ...leg('seat', w.start + w.frames, LAPTOP_FADE, fx.anchor), laptop_from: 0, laptop_to: 1 }));
        }

        out.push(done(fx, legs, { start: 0, frames: D.open + D.step + D.close }, r.tier));
    }

    for (const element of move.lost) {
        const fx = shape(element, 'leave');
        const flying = interns.in_flight.get(fx.intern_key) ?? null;
        const held = interns.laptop_at(fx.intern_key);
        const from = held ?? (fx.activity_state === 'working' ? 1 : 0);

        if (flying !== null && flying.effect.leg_kind === 'arrive') {
            const turned = turnRound(fx, flying, from, door, routed, walk, leg, done, walking, row);

            if (turned !== null) {
                out.push(turned);
            }

            continue;
        }

        const gone = door === null || fx.anchor === null ? null : departure(fx, from);

        if (gone !== null) {
            out.push(gone);
        }
    }

    return out;
}

/**
 * ITEM 16 — A STOP THAT LANDS BEFORE THE INTERN REACHES ITS STOOL TURNS IT ROUND, from wherever it is at the cancelling
 * render's instant, and the laptop never fades in: in the `cab` leg the leaves close over it; in the `stand` leg it
 * steps back in and they close; in the `walk` leg it turns at `pointAt()`'s point, routes from there back to the
 * threshold, waits its seeded draw for the stop's `cause` and takes the full door; in the `seat` leg it is at its stool,
 * so it is an ordinary departure fading the laptop out from the opacity it has reached. The holder replaces the arrival
 * with this effect (one walk per intern).
 */
function turnRound(fx, flying, from, door, routed, walk, leg, done, walking, row) {
    const D = ELEVATOR_DOOR;
    const I = flying.effect.frame_interval_ms;
    const elapsed = flying.elapsed_ms;
    const legs = flying.effect.legs;
    const k = Math.max(0, legs.findIndex((l) => elapsed < (l.start + l.frames) * I));
    const now = legs[k === -1 ? legs.length - 1 : k];
    const at = door === null ? null : Object.freeze({ x: door.x, y: door.y });

    if (at === null) {
        return null;
    }

    if (now.kind === 'cab') {
        return done({ ...fx, leg_kind: 'turn', turned_in: 'cab' }, [leg('cab', 0, D.close, at)], { start: 0, frames: D.close }, flying.effect.clearance);
    }

    if (now.kind === 'stand') {
        return done({ ...fx, leg_kind: 'turn', turned_in: 'stand' }, [leg('stand', 0, D.step, at, 'step'), leg('cab', D.step, D.close, at)],
            { start: 0, frames: D.step + D.close }, flying.effect.clearance);
    }

    if (now.kind === 'walk') {
        const p = pointAt(flying.effect, elapsed);
        const here = Object.freeze({ x: p.x, y: p.y });
        const r = routed(fx, here, door);

        if (r === null) {
            return null;
        }

        const back = departing([], walk(0, r), walking.wait(fx.intern_key, row.cause), at);

        return done({ ...fx, leg_kind: 'turn', turned_in: 'walk', turned_at: here }, back.legs, back.door, r.tier, back.queue);
    }

    // In the `seat` leg: at its stool, its laptop part-way up — an ordinary departure from the opacity it has reached.
    const r = routed(fx, fx.anchor, door);

    if (r === null) {
        return null;
    }

    const F = from > 0 ? LAPTOP_FADE : 0;
    const gone = departing(F > 0 ? [Object.freeze({ ...leg('seat', 0, F, fx.anchor), laptop_from: from, laptop_to: 0 })] : [],
        walk(F, r), walking.wait(fx.intern_key, row.cause), at);

    return done({ ...fx, leg_kind: 'turn', turned_in: 'seat' }, gone.legs, gone.door, r.tier, gone.queue);
}

/**
 * ITEM 19 — GAINED AND LOST IN ONE RENDER: the shortest subagent is seen in the cab. Item 16's cab-leg turn-round from
 * frame zero: the leaves open on the intern standing in the cab and close over it — the *Elevator leaves* opening then
 * closing frames, no step out, no wait, no walk, no `seat` leg and no laptop. The arrival's row and the loss's are both
 * logged; this is the one drawing for the two.
 */
function internBlink(arrival, loss) {
    const D = ELEVATOR_DOOR;
    const at = arrival.legs[0].at;

    return Object.freeze({
        ...arrival,
        leg_kind: 'blink',
        cause: loss.cause,
        legs: Object.freeze([Object.freeze({ kind: 'cab', start: 0, frames: D.open + D.close, at })]),
        door: Object.freeze({ start: 0, frames: D.open + D.close }),
        frames: D.open + D.close,
    });
}

/**
 * ONE A24 ROW's FADES (§ 8's *Working and idle interns*; card#12451): for each intern of the delta present on both sides
 * whose `activity_state` changed, its laptop's fade over § 12's *Laptop fade* — from the opacity it has reached (a fade
 * interrupted by the opposite flip reverses from there, never from 0) to 1 for `working` and 0 for `idle`. An intern
 * whose arrival is still in flight is the arrival's to draw: its `seat` leg reads the held state at each paint. Reduced
 * motion and a row drawn without motion draw no fade: the laptop is simply present or absent on the applying render.
 */
function laptopFades(row, base, frame, interns, moving) {
    const seat = `${row.install_id}/${row.seat_id}`;
    const flip = (frame.desks.flips ?? []).find((f) => f.key === seat && f.cause === row.cause) ?? null;
    const opacity = (state) => (state === 'working' ? 1 : 0);
    const out = [];

    for (const call of flip?.calls ?? []) {
        const key = `${seat}~${call.call_id}`;

        // An intern whose arrival is still in flight is the arrival's to draw: its `seat` leg reads the held state.
        if (interns.in_flight.has(key)) {
            continue;
        }

        const from = interns.laptop_at(key) ?? opacity(call.from);
        const to = opacity(call.to);

        out.push(Object.freeze({
            ...base,
            call_id: call.call_id,
            intern_key: key,
            from,
            to,
            frames: moving && from !== to ? LAPTOP_FADE : 0,
        }));
    }

    return out;
}

/**
 * Every § 6.2 edge row this render WROTE, as the frames to draw — at § 12's loop rate, from where
 * the fact happened to where it ends. Rows are the animation set's; nothing here fires one.
 */
function buildEffects(rows, frame, anchors, previous, extent, band, reduce, character, box, walking, interns) {
    const interval = 1000 / LOOP_FPS;
    const effects = [];
    const seen = new Set();
    // card#12450: the interns this render's A10 rows gained, by intern key → index into `effects`, so a loss in the same
    // render redraws that arrival as item 19's cab-leg blink rather than drawing nothing.
    const arrivedHere = new Map();
    // card#12451: each intern's A24 fades in this render, folded to ONE from the first's start to the last's end (item 19).
    const fadedHere = new Map();

    for (const row of rows) {
        if (row.class !== 'edge') {
            continue;
        }

        const id = row.animation_id;
        const motion = row.motion === true;
        const key = row.seat_id === null ? null : `${row.install_id}/${row.seat_id}`;
        const base = {
            animation_id: id,
            cause: row.cause,
            install_id: row.install_id,
            seat_id: row.seat_id,
            ...form(id, !motion || reduce),
            frame_interval_ms: interval,
        };

        if (id === 'A19' || id === 'A20') {
            // One drawing per post: A19 is logged once per destination and drawn as that many
            // envelopes; A20 is one ring.
            const tag = `${id} ${row.install_id} ${row.cause}`;

            if (seen.has(tag)) {
                continue;
            }

            seen.add(tag);

            // An install-scope post is its room's; a fleet-scope one (card#12239) is the floor's, and its
            // ends are already `install_id/seat_id` keys, its travellers only the destinations drawn here.
            const local = frame.coord?.[row.install_id]?.rounds.find((r) => r.post_ref === row.cause) ?? null;
            const fleet = local === null ? (frame.coord_fleet?.rounds.find((r) => r.post_ref === row.cause) ?? null) : null;
            const round = local ?? fleet;
            const keyOf = (seatId) => (fleet === null ? `${row.install_id}/${seatId}` : seatId);
            const origin = round?.origin.agent?.resolved ? anchors.get(keyOf(round.origin.agent.seat_id)) ?? null : null;
            const destinations = round === null ? [] : fleet === null ? round.targets.desks.map((d) => d.seat_id) : round.travellers;

            if (id === 'A19' && round !== null && origin !== null) {
                for (const target of destinations) {
                    const to = anchors.get(keyOf(target)) ?? null;

                    if (to === null) {
                        continue;
                    }

                    effects.push(travel(base, origin, to, motion && !reduce, ARRIVAL_REST_FRAMES));
                }
            }

            if (id === 'A20' && origin !== null && extent !== null) {
                // The operator's QA ruling: the ring spans the whole floor BEFORE it fades — reach
                // first, fade after, never dissipating mid-office. Its reach is the floor's farthest
                // corner from the origin desk.
                const reach = Math.max(...[
                    [extent.x, extent.y],
                    [extent.x + extent.width, extent.y],
                    [extent.x, extent.y + extent.height],
                    [extent.x + extent.width, extent.y + extent.height],
                ].map(([x, y]) => Math.hypot(x - origin.x, y - origin.y)));
                const reachFrames = Math.max(1, Math.ceil(reach / RING_PX_PER_FRAME));

                effects.push(Object.freeze({
                    ...base,
                    at: origin,
                    radius: reach,
                    reach_frames: motion && !reduce ? reachFrames : 0,
                    fade_frames: motion && !reduce ? RING_FADE_FRAMES : 0,
                    frames: motion && !reduce ? reachFrames + RING_FADE_FRAMES : 0,
                }));
            }

            continue;
        }

        if (id === 'A22') {
            // § 6.2 A22, the portal bird (COORD-STORE.md § 6.1 rule 4): the set logs one row for a post's
            // departure and one per arrival here, and the scene draws the post's whole crossing once —
            // the bird leaving its origin desk for THIS floor's birdhouse and going in, and a bird arriving
            // from it at each destination desk here, setting its envelope down. Nothing is drawn toward
            // another floor's birdhouse: the far floor draws its own half.
            const tag = `${id} ${row.cause}`;
            const round = frame.coord_fleet?.rounds.find((r) => r.post_ref === row.cause) ?? null;
            const mouth = band?.portal?.mouth ?? null;

            if (seen.has(tag) || round === null || mouth === null) {
                continue;
            }

            seen.add(tag);

            // card#12445: the bird flies to and from the birdhouse's perch, and hops between it and the hole.
            const ledge = perchPoint(band.portal.perch);
            const hole = Object.freeze({ x: mouth.x, y: mouth.y });
            const moving = motion && !reduce;

            if (round.portal.departure) {
                const from = anchors.get(round.origin.agent.seat_id) ?? null;

                if (from !== null) {
                    // It goes in: no envelope is left at the hole, so it has no rest.
                    effects.push(Object.freeze({ ...travel(base, from, ledge, moving, 0, { ledge, hole, leg: 'departure' }), leg: 'departure' }));
                }
            }

            for (const key of round.portal.arrivals) {
                const to = anchors.get(key) ?? null;

                if (to !== null) {
                    effects.push(Object.freeze({ ...travel(base, ledge, to, moving, ARRIVAL_REST_FRAMES, { ledge, hole, leg: 'arrival' }), leg: 'arrival' }));
                }
            }

            continue;
        }

        if (id === 'A14') {
            effects.push(Object.freeze({ ...base, target: 'strip', frames: motion ? 1 : 0 }));

            continue;
        }

        if (id === 'A17') {
            effects.push(Object.freeze({ ...base, target: 'band', at: band?.clock ?? null, frames: motion ? 1 : 0 }));

            continue;
        }

        // A desk row: at the desk the row names — where it is now, and for a walk where it was.
        const now = key === null ? null : anchors.get(key) ?? null;
        const before = key === null ? null : previous.get(key) ?? null;

        if (id === 'A10') {
            for (const fx of internWalks(row, base, frame, interns, band, motion && !reduce, character, box, walking)) {
                const at = arrivedHere.get(fx.intern_key);

                if (fx.leg_kind === 'leave' && at !== undefined) {
                    // Item 19: gained and lost in one render — the doors open on the intern and close over it.
                    effects[at] = internBlink(effects[at], fx);
                    arrivedHere.delete(fx.intern_key);

                    continue;
                }

                if (fx.leg_kind === 'arrive') {
                    arrivedHere.set(fx.intern_key, effects.length);
                }

                effects.push(fx);
            }

            continue;
        }

        if (id === 'A24') {
            for (const fx of laptopFades(row, base, frame, interns, motion && !reduce)) {
                const held = fadedHere.get(fx.intern_key);

                if (held !== undefined) {
                    // Item 19: two flips the page applies in one render are one fade, from where the first began to where
                    // the second ends — none at all where it ends where it began.
                    effects[held] = Object.freeze({ ...effects[held], to: fx.to, frames: motion && !reduce && effects[held].from !== fx.to ? LAPTOP_FADE : 0, cause: fx.cause });

                    continue;
                }

                fadedHere.set(fx.intern_key, effects.length);
                effects.push(fx);
            }

            continue;
        }

        if (id === 'A1' || id === 'A2') {
            // card#12443: the walker wears the seat's delivered body, read off the desk model this render holds.
            const species = key === null ? null : frame.desks.desks[key]?.species ?? null;

            effects.push(walkEffect({ ...base, species }, id, now, threshold(band), motion && !reduce, character, box, walking));

            continue;
        }

        if (id === 'A13' || id === 'A16') {
            const from = before ?? now;
            const to = id === 'A16' ? now : (from === null ? null : { x: from.x, y: (extent?.y ?? from.y) + (extent?.height ?? 0) });
            const length = from === null || to === null ? 0 : Math.hypot(to.x - from.x, to.y - from.y);

            effects.push(Object.freeze({
                ...base,
                from,
                to,
                frames: motion && !reduce ? Math.max(1, Math.ceil(length / WALK_PX_PER_FRAME)) : 0,
            }));

            continue;
        }

        effects.push(Object.freeze({
            ...base,
            at: now,
            frames: motion && !reduce && (ONE_FRAME.has(id) || id === 'A10') ? 1 : 0,
        }));
    }

    return Object.freeze(effects);
}
