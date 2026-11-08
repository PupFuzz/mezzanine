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

import { ANIMATION_SET, LOOP_FPS, SNACK_HELD, loops } from '../wire/animation-set.js';
import { BAND_H, ELEVATOR, footprintsIntersect, mapDesks, mapGrid, thresholdOf } from './floor-layout.js';
import { floorTheme } from './floor-theme.js';
import { PLANE_KINDS, RESTROOM_DOOR, RESTROOM_PLATES, mapTiles, sceneryInput } from './map-tiles.js';
import { tilesetUrl } from './tileset.js';
import { BUBBLE_GAP, BUBBLE_MAX_W, BUBBLE_PAD, BUBBLE_TITLE_LINES, CHARACTER_SCALE, LINE, STOOL_FRAME, STOOL_H, STOOL_W, bubbleSeat, characterCentre, characterOverdraw, cloudOutline, deskAnchor, deskLayout, deskRects, fit, furnitureAsset, internKey, seatSolid, stoolSlots, TRAIL_REACH, trailCircles, trailFoot, union, wrap } from './desk-layout.js';
import { bubbleLayout } from '../desk/task-bubble.js';
import { CLEARANCE, CLEARANCE_TIERS, blockedFor, cellAt, cellCentre, lattice, pointAt, polylineLength, route, spotsNear } from './route.js';
import { perchPoint } from './bird.js';
import { SNACK_LINES } from './snack-lines.js';

// The pure readers a page without this module's scene machinery also needs (the cab's door view, card#12987) live
// in modules that import no animation; each name stays readable from here.
export { BAND_H } from './floor-layout.js';
export { floorTheme, unshippedThemeNotice } from './floor-theme.js';
export { PLANE_KINDS, RESTROOM_DOOR, RESTROOM_PLATES, mapTiles } from './map-tiles.js';

/**
 * THE FLOOR's FRAME (§ 4.2's frame list, card#11045 PR-D) — the band's parts, in scene px from the
 * band's left edge and top. Layout, carrying no fact, and this module's own — but for the band's height and the
 * elevator's place (`BAND_H`, `ELEVATOR`), which `floor-layout.js` holds beside the one threshold formula
 * (`thresholdOf()`). `Tests\Feature\Floor\TheFloorDrawsItsFrameTest` holds `ZONE_W` and `WINDOW`'s pitch and glazing
 * equal to FLOOR.md § 12's rows, as it does `BAND_H`.
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

/**
 * card#12747 — THE ELEVATOR's TWO CALL PLATES (§ 12's *Call plates*; the operator, 2026-11-01: *"There also needs to be a
 * set of smaller elevator buttons below the main ones at the subagent's height so that they can reach their buttons"*):
 * each plate's rect in the elevator surround's px from its top-left, in the reach the surround grows right of the frame.
 * `main` is the plate the surround has always carried, at a seat's reach; `low` is a smaller one under it, centred on the
 * same line, at an intern's (`INTERN_SIZE`'s height). The floor's theme draws both at these rects (`bandDocuments()`), and
 * the departures press them (`callPoint()`). Chosen; they carry no fact.
 */
export const CALL_PLATES = Object.freeze({
    main: Object.freeze({ x: 100, y: 58, w: 11, h: 24 }),
    low: Object.freeze({ x: 101, y: 116, w: 9, h: 17 }),
});

/**
 * card#12747 — how long a departing walker stands at its call plate pressing it, in loop frames (§ 12's *Call press*): one
 * second at the loop rate. Chosen; it carries no fact.
 */
export const CALL_PRESS_FRAMES = 4;

/** The slab under the floor's rooms — the front wall's top edge, scenery, outside every grid (§ 4.2). */
export const SLAB_H = 8;

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
        // card#12747: the call plates, in the surround's own px — the rects the scene decides and the departures press.
        { fn: 'elevatorSurround', rect: band.elevator.surround, input: { calls: CALL_PLATES } },
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

/**
 * card#12785 — THE SNACK GAP (§ 12's *Snack gap*, § 6.6 item 4): a walker's next break begins a seeded number of seconds
 * after its previous break BEGAN, uniform over this range — the operator's *"a random time between 1-2 hours since their
 * last snack break"* (2026-11-02). Chosen; it carries no fact.
 */
export const SNACK_GAP_S = Object.freeze({ min: 3600, max: 7200 });

/**
 * card#12785 — THE CHAIN's DAILY ANCHOR (§ 12's *Snack anchor*, § 6.6 item 4): the chain of breaks restarts once a day of
 * the corrected server clock (`⌊t ÷ 86,400⌋`, a UTC day), at a seeded second under one minimum gap into it — so a page
 * derives any instant's breaks from at most the day before's anchor and never replays the walker's history.
 */
export const SNACK_DAY_S = 86400;

/** § 12's *Snack dwell* (card#12749): how long a visitor stands at its table, seeded per key and break. Chosen. */
export const SNACK_DWELL_S = Object.freeze({ min: 45, max: 75 });

/**
 * § 12's *Snack tables* (card#12749, § 6.6 item 2): the two standing kinds of the snack corner, the size of each kind's
 * tile, and whose body each serves — the seat's (`walkerSize()`) at the tall table, the intern's (`INTERN_SIZE`) at the
 * low one. The tile's rect is the obstacle the lattice blocks, read off the map like every standing tile's.
 */
export const SNACK_TABLES = Object.freeze({
    'snack-table': Object.freeze({ w: 72, h: 96, visitor: 'seat' }),
    'snack-table-low': Object.freeze({ w: 48, h: 64, visitor: 'intern' }),
});

/**
 * § 12's *Snack spacing* (card#12749): the least distance between two visitors' spots, centre to centre — the visitor's
 * drawn width plus the `clear` tier's side margin, *Queue spacing*'s formula over the visitor's own body.
 */
export function snackSpacing(body) {
    return body.w + CLEARANCE.margin;
}

/**
 * § 12's *Snack spot* (card#12749, § 6.6 item 3): spot 0's offset from the table tile's bottom-left corner for a visitor
 * of `body` — half its width beside the table's front-left corner, *Walker clearance*'s depth plus 1 px below the foot,
 * before the snap to its lattice cell's centre; `mirror` is the front-right corner's, `dx` from the tile's right edge.
 */
export function snackSpotOffset(body) {
    return Object.freeze({ dx: -body.w / 2, mirror: body.w / 2, dy: CLEARANCE.depth + 1 });
}

/**
 * card#12786 — THE RECESS the painter clips an occupant to and draws the leaf over (§ 6.7 item 2): the door frame's opening,
 * inside its jambs and under its head, down to the sill, in the tile's px — the house theme's frame (6 px jambs, a 10 px
 * head under the sign plate, a 4 px sill), so the leaf fills what the frame leaves open.
 */
export const RESTROOM_RECESS = Object.freeze({ x: 6, y: 32, w: 52, h: 107 });

/**
 * card#12786 — § 12's *Restroom line* (§ 6.7 item 4): the one string every restroom walker carries in its thought bubble,
 * the operator's words verbatim (2026-11-02, *"their thought bubble shows the status "Taking a restroom break""*). One
 * string, no list, no seeded pick; [§ 5.4] records it by name as text the wire did not send.
 */
export const RESTROOM_LINE = 'Taking a restroom break';

/**
 * card#12786 — THE RESTROOM GAP (§ 12's *Restroom gap*, § 6.7 item 4): a walker's next restroom break begins a seeded
 * number of seconds after its previous one BEGAN, uniform over this range — the operator's *"between 1-5 hours since their
 * last restroom break"* (2026-11-02). Chosen; it carries no fact.
 */
export const RESTROOM_GAP_S = Object.freeze({ min: 3600, max: 18000 });

/** § 12's *Restroom dwell* (card#12786): how long a visitor is inside, seeded per key and break. Chosen. */
export const RESTROOM_DWELL_S = Object.freeze({ min: 90, max: 150 });

/**
 * card#12785 — THE DAY's ANCHOR BREAK of walker `key` (§ 6.6 item 4): `day × 86,400 + draw(key, '<prefix>-anchor:' + day)
 * mod <the chain's minimum gap>` — a seeded second in the first hour of UTC day `day` of the corrected server clock, under
 * one minimum gap, so the walkers of one floor do not all break at midnight. Absolute seconds. `chain` is the chain's
 * field prefix and gap range — the snack chain's (`SNACK_CHAIN`, § 12's *Snack anchor*) unless named; card#12786's
 * restroom chain is the same rule under `RESTROOM_CHAIN` (§ 12's *Restroom anchor*), one scheduler for both.
 *
 * @param {function(string, string): number} draw `resources/characters/seed.js`'s `draw()`
 */
export function snackAnchor(draw, key, day, chain = SNACK_CHAIN) {
    return day * SNACK_DAY_S + (draw(key, `${chain.prefix}-anchor:${day}`) % chain.gap.min);
}

/** The chains already derived, per draw function and `<prefix> <key> <day>` — a pure function's answers, kept small. */
const CHAINS = new WeakMap();

/**
 * card#12785 — ONE WALKER's BREAKS IN ONE DAY OF THE CHAIN (§ 6.6 item 4): the start seconds from the day's anchor break
 * up to, and not including, the next day's — each the one before it plus a gap `gap.min + draw(key, '<prefix>-gap:' + s)
 * mod (hi − gap.min + 1)`, where `hi` is the least of `gap.max` and what still leaves one minimum gap before the next
 * anchor. So EVERY gap — inside a day and across the anchor into the next — lies in the chain's range: the steps far from
 * the next anchor are uniform over the whole range, and only the last one or two of a day are narrowed so the chain lands
 * on the next day's anchor a whole gap later. One draw per break and two anchors a day, whatever the hour asked about.
 * `chain` as `snackAnchor()`'s (card#12786).
 */
export function snackDay(draw, key, day, chain = SNACK_CHAIN) {
    let cache = CHAINS.get(draw);

    if (cache === undefined || cache.size > 512) {
        cache = new Map();
        CHAINS.set(draw, cache);
    }

    const id = `${chain.prefix} ${key} ${day}`;

    if (!cache.has(id)) {
        const next = snackAnchor(draw, key, day + 1, chain);
        const starts = [snackAnchor(draw, key, day, chain)];

        for (let s = starts[0]; next - s > chain.gap.max;) {
            const hi = Math.min(chain.gap.max, next - s - chain.gap.min);

            s += chain.gap.min + (draw(key, `${chain.prefix}-gap:${s}`) % (hi - chain.gap.min + 1));
            starts.push(s);
        }

        cache.set(id, Object.freeze(starts));
    }

    return cache.get(id);
}

/**
 * card#12785 — ONE BREAK (§ 6.6 item 4): the break of walker `key` that begins at absolute second `start` — its dwell,
 * `45 + draw(key, 'snack-dwell:' + start) mod 31` seconds, and its food line, `draw(key, 'snack-line:' + start) mod` the
 * length of `snack-lines.js`'s list (drawn only for a busy walker, § 6.6 item 4) — the character tree's one seeded draw over
 * the walker's key (the seat's, or the intern's `<seat key>~<call_id>`) and the break's own second, so every viewer and
 * every reload draw one break, one dwell and one line.
 */
export function snackBreak(draw, key, start) {
    return Object.freeze({
        start_s: start,
        dwell_s: SNACK_DWELL_S.min + (draw(key, `snack-dwell:${start}`) % (SNACK_DWELL_S.max - SNACK_DWELL_S.min + 1)),
        line: draw(key, `snack-line:${start}`) % SNACK_LINES.length,
    });
}

/**
 * card#12786 — ONE RESTROOM BREAK (§ 6.7 item 4): the break of walker `key` that begins at absolute second `start` — its
 * dwell inside, `90 + draw(key, 'restroom-dwell:' + start) mod 61` seconds (§ 12's *Restroom dwell*). Its line is the one
 * fixed string (`RESTROOM_LINE`), so it draws none.
 */
export function restroomBreak(draw, key, start) {
    return Object.freeze({
        start_s: start,
        dwell_s: RESTROOM_DWELL_S.min + (draw(key, `restroom-dwell:${start}`) % (RESTROOM_DWELL_S.max - RESTROOM_DWELL_S.min + 1)),
    });
}

/**
 * THE TWO CHAINS OF BREAKS one scheduler derives (card#12785, card#12786): each its field prefix, its gap range and its
 * one break's draw. The snack chain is § 12's *Snack anchor* and *Snack gap*; the restroom chain § 12's *Restroom anchor*
 * and *Restroom gap*. A walker's two chains are independent draws over one key.
 */
export const SNACK_CHAIN = Object.freeze({ prefix: 'snack', gap: SNACK_GAP_S, at: snackBreak });

export const RESTROOM_CHAIN = Object.freeze({ prefix: 'restroom', gap: RESTROOM_GAP_S, at: restroomBreak });

/**
 * card#12785 — THE BREAKS of walker `key` whose start second lies in `[fromS, toS]`, oldest first (§ 6.6 item 4). A break
 * of day `d` begins at or after `d × 86,400` and before the next day's anchor, inside its first hour, so the days read are
 * the one before `fromS`'s and every day to `toS`'s — the chain is derived from an anchor at most a day and an hour back,
 * never replayed from a first break. `chain` as `snackAnchor()`'s (card#12786): the restroom chain's breaks are
 * `restroomBreak()`'s.
 */
export function snackBreaks(draw, key, fromS, toS, chain = SNACK_CHAIN) {
    const out = [];

    for (let day = Math.floor(fromS / SNACK_DAY_S) - 1; day <= Math.floor(toS / SNACK_DAY_S); day++) {
        for (const s of snackDay(draw, key, day, chain)) {
            if (s >= fromS && s <= toS) {
                out.push(chain.at(draw, key, s));
            }
        }
    }

    return out;
}

/**
 * card#12749 — THE WALK HOME FROM A TRIP (the operator's ruling of 2026-11-02 on § 14 item 44 Q2, *"Walk back"*; § 6.6
 * item 5): a visitor recalled to work while its trip is in flight walks home ALONG ITS ROUTE, from where it is at
 * `elapsedMs` into the trip — back the way it came on its walk out, its route home from its spot while it stands there, or
 * the rest of its route home on its walk back — starting at frame 0 of a new effect the holder starts at that instant, so
 * no frame it has drawn moves; the paint-only refresh at the walk's end seats the character in its new pose. Since
 * card#12785 a recall is a seat's change to a held state that is neither `idle` nor `working` (`blocked`, `stalled`,
 * `unknown`, `catching_up`) or an intern's to a value that is neither — never a change to `working`, which keeps the trip
 * — so the walk home carries no laptop.
 */
export function snackHome(effect, elapsedMs) {
    const I = effect.frame_interval_ms;
    const [out, , back] = effect.legs;
    const here = pointAt(effect, elapsedMs);
    const p = Object.freeze({ x: here.x, y: here.y });
    // The vertices of a walk leg passed by `ms`, and the ones still ahead of it.
    const split = (leg, ms) => {
        const travelled = leg.length * Math.min(1, Math.max(0, (ms - leg.start * I) / (leg.frames * I)));
        let at = 0;
        const passed = [leg.points[0]];
        const ahead = [];

        for (let i = 1; i < leg.points.length; i++) {
            at += Math.hypot(leg.points[i].x - leg.points[i - 1].x, leg.points[i].y - leg.points[i - 1].y);
            (at <= travelled ? passed : ahead).push(leg.points[i]);
        }

        return { passed, ahead };
    };
    const path = elapsedMs < out.start * I + out.frames * I
        ? [p, ...split(out, elapsedMs).passed.reverse()]
        : elapsedMs < back.start * I
            ? back.points
            : [p, ...split(back, elapsedMs).ahead];
    const points = Object.freeze(path.filter((q, i) => i === 0 || q.x !== path[i - 1].x || q.y !== path[i - 1].y).map((q) => Object.freeze({ x: q.x, y: q.y })));
    const walk = walkLeg(0, points.length === 1 ? Object.freeze([points[0], points[0]]) : points);

    return Object.freeze({ ...effect, leg_kind: 'snack-home', legs: Object.freeze([walk]), frames: walk.frames });
}

/**
 * card#12786 — HOW OPEN A RESTROOM DOOR's LEAF IS at frame `f` of a leaf timeline (§ 6.7 item 4): 0 closed, 1 open, each
 * move `{ start, frames, from, to }` stepping from `from` to `to` over its whole frames — the leaves' `steps()` — and the
 * leaf closed before the first. A pure function of the timeline, read by the holder and the painter alike.
 */
export function leafLevel(leaf, f) {
    let level = 0;

    for (const m of leaf) {
        if (f >= m.start + m.frames) {
            level = m.to;
        } else if (f >= m.start) {
            level = m.from + (m.to - m.from) * (Math.floor(f - m.start) / m.frames);
        }
    }

    return level;
}

/**
 * card#12786 — A RESTROOM WALKER COMING OUT EARLY (§ 6.7 item 5): what a recall, or an A2 or A10 that takes the walker over,
 * does at `elapsedMs` into its visit when the walker is AT THE DOOR — `null` while it is still on its way there (its walk
 * out, its wait at a spot, its walk to the threshold) or already on its walk home, where it walks on from where it is. At
 * the threshold while the leaf opens for it, it walks at once (`lead` 0) and the leaf finishes opening and closes behind
 * it; inside, the field turns vacant now and the leaf opens from where it is — reversing a closing leaf from the frame it
 * has reached (A24's precedent) — and the walker leaves the threshold when it is open (`lead` the frames that takes), the
 * leaf closing behind it a step after. Frames from the instant asked about: `{ lead, at, leaf, free }`, `free` the frame
 * the door is closed again and free for the next occupant.
 */
export function restroomExit(effect, elapsedMs) {
    const D = ELEVATOR_DOOR;
    const f = elapsedMs / effect.frame_interval_ms;
    const atDoor = effect.legs.find((l) => l.kind === 'stand' && l.why === 'door');
    const inside = effect.legs.find((l) => l.kind === 'inside');

    if (atDoor === undefined || inside === undefined || f < atDoor.start || f >= inside.start + inside.frames) {
        return null;
    }

    const level = leafLevel(effect.leaf, f);
    const opening = Math.ceil((1 - level) * D.open - 1e-9);
    const moves = (from) => (opening > 0 ? [Object.freeze({ start: 0, frames: opening, from: level, to: 1 })] : []).concat(
        [Object.freeze({ start: from, frames: D.close, from: 1, to: 0 })]);

    if (f < inside.start) {
        const close = Math.max(opening, D.step);

        return Object.freeze({ lead: 0, at: inside.at, leaf: Object.freeze(moves(close)), free: close + D.close });
    }

    return Object.freeze({ lead: opening, at: inside.at, leaf: Object.freeze(moves(opening + D.step)), free: opening + D.step + D.close });
}

/**
 * card#12786 — THE WALK HOME FROM A RESTROOM VISIT on a recall (§ 6.7 item 5, the operator's *"Walk back"* of card#12749 as
 * card#12785 applies it): from where the walker is at `elapsedMs` — on its way out or waiting at a spot, back the way it
 * came; at the door, out first (`restroomExit()`), then its route home from the threshold; on its walk home, the rest of
 * that route — as frame 0 of a new effect the holder starts at that instant, so no frame drawn moves. `leaf` is the exit's
 * leaf timeline from that instant, or `null` where the walker was not at the door (its door's own timeline stands).
 */
export function restroomHome(effect, elapsedMs) {
    const I = effect.frame_interval_ms;
    const legs = effect.legs;
    const home = legs[legs.length - 1];
    const exit = restroomExit(effect, elapsedMs);
    const here = pointAt(effect, elapsedMs);
    const p = Object.freeze({ x: here.x, y: here.y });
    const dedupe = (path) => Object.freeze(path.filter((q, i) => i === 0 || q.x !== path[i - 1].x || q.y !== path[i - 1].y).map((q) => Object.freeze({ x: q.x, y: q.y })));
    // The vertices of a walk leg passed by `ms`, and the ones still ahead of it — `snackHome()`'s reading of a leg.
    const split = (leg, ms) => {
        const travelled = leg.length * Math.min(1, Math.max(0, (ms - leg.start * I) / (leg.frames * I)));
        let at = 0;
        const passed = [leg.points[0]];
        const ahead = [];

        for (let i = 1; i < leg.points.length; i++) {
            at += Math.hypot(leg.points[i].x - leg.points[i - 1].x, leg.points[i].y - leg.points[i - 1].y);
            (at <= travelled ? passed : ahead).push(leg.points[i]);
        }

        return { passed, ahead };
    };
    let out;

    if (exit !== null) {
        const walk = walkLeg(exit.lead, home.points);

        out = exit.lead > 0 ? [Object.freeze({ kind: 'inside', start: 0, frames: exit.lead, at: exit.at }), walk] : [walk];
    } else if (elapsedMs >= home.start * I) {
        const path = dedupe([p, ...split(home, elapsedMs).ahead]);

        out = [walkLeg(0, path.length === 1 ? Object.freeze([path[0], path[0]]) : path)];
    } else {
        // Back the way it came: every walk leg begun by now, the part walked, reversed from where it stands.
        const walked = legs.filter((l) => l.kind === 'walk' && l.start * I <= elapsedMs).flatMap((l) => split(l, elapsedMs).passed);
        const path = dedupe([p, ...walked.reverse()]);

        out = [walkLeg(0, path.length === 1 ? Object.freeze([path[0], path[0]]) : path)];
    }

    const last = out[out.length - 1];

    return Object.freeze({ ...effect, leg_kind: 'restroom-home', legs: Object.freeze(out), frames: last.start + last.frames, leaf: exit?.leaf ?? null, occupied: null });
}

/**
 * WHERE A WALK THAT TAKES A TRIP OVER BEGINS (card#12749, § 6.6 item 5; card#12786, § 6.7 item 5): the point the trip's
 * walker is at in this render's instant, and `lead`, the frames it first stands `inside` the restroom door while the leaf
 * opens — 0 but for a restroom visit at the door (`restroomExit()`). `trip` is the holder's `{ effect, elapsed_ms }`.
 */
export function tripStart(trip) {
    const exit = trip.effect.animation_id === 'A26' ? restroomExit(trip.effect, trip.elapsed_ms) : null;
    const p = exit?.at ?? pointAt(trip.effect, trip.elapsed_ms);

    return Object.freeze({ x: p.x, y: p.y, lead: exit?.lead ?? 0 });
}

/** Where every A1 and A2 walk meets the elevator: the leaves' seam, at their foot (§ 6.2's walk note item 3). */
export function threshold(band) {
    return band === null || band === undefined ? null : Object.freeze({ x: band.elevator.seam, y: band.elevator.y + band.elevator.h });
}

/**
 * card#12747 — WHERE A DEPARTING WALKER STANDS TO PRESS ITS CALL PLATE (§ 6.2's walk note item 9, § 12's *Call plates*):
 * on the floor line the threshold stands on, under the plate's centre — or, where that is nearer the threshold than a
 * seat waiting there leaves room for, as near the plate as keeps the walker's drawn body *Walker clearance*'s side margin
 * clear of that seat's: `x = max(plate centre, seam + seat width ÷ 2 + presser width ÷ 2 + margin)`. Seats press `main`
 * and interns `low`, so a plate's presser is a seat (`walkerSize()`) or an intern (`INTERN_SIZE`); the plate lies inside
 * the presser's drawn width either way. `gap` is the frames a walker reaching the
 * plates waits behind the one before it has left them — § 12's *Queue spacing* at § 12's *Walk speed* (`#call()` in
 * `floor/in-flight.js`). `null` with no band.
 *
 * @param {?object} band the scene's band
 * @param {{w: number, h: number}} character the seat character's rect
 * @param {'main'|'low'} plate
 */
export function callPoint(band, character, plate) {
    if (band === null || band === undefined) {
        return null;
    }

    const door = threshold(band);
    const r = band.elevator.calls[plate];
    const body = plate === 'main' ? walkerSize(character) : INTERN_SIZE;

    return Object.freeze({
        at: Object.freeze({ x: Math.max(r.x + r.w / 2, door.x + walkerSize(character).w / 2 + body.w / 2 + CLEARANCE.margin), y: door.y }),
        plate,
        gap: Math.ceil(queueSpacing(character) / WALK_PX_PER_FRAME),
    });
}

/**
 * card#12744 — WHERE A RIDER STANDS IN THE CAB (§ 6.2's walk note item 10, § 12's *Cab spots*; the operator, 2026-11-01:
 * *"If there are multiple agents and subagents entering the elevator, stagger them inside the elevator so that they
 * aren't all at one point"*). Each spot is a rider's feet, as an offset in band px from the threshold — the seam at the
 * doorway's foot — a negative `dy` being deeper into the cab. Spot 0 is the threshold itself, where a lone rider stands
 * as it always has; spots 1 and 2 are a row in, to either side; 3 and 4 a row further in, between them; 5 at the back.
 * Every spot's feet lie on the cab floor `cab-paint.js`'s `drawCabInterior()` draws behind the leaves at the band
 * elevator's size from the landing's eye (`LANDING`), with the side walls' margin to spare; the rider's drawn body may
 * reach past the doorway's edge, where the frame's clip hides it as a doorway hides someone standing to its side.
 * Past the last spot, riders share the inner two rows' points (`cabSpot()`). Chosen; it carries no fact.
 */
export const CAB_SPOTS = Object.freeze([[0, 0], [-20, -12], [20, -12], [-10, -24], [10, -24], [0, -34]]
    .map(([dx, dy]) => Object.freeze({ dx, dy })));

/** Rider `k`'s feet in the cab (`CAB_SPOTS`): past the last spot, the inner two rows' points again, in turn. */
export function cabSpot(door, k) {
    const inner = 3;
    const s = CAB_SPOTS[k < CAB_SPOTS.length ? k : inner + ((k - inner) % (CAB_SPOTS.length - inner))];

    return Object.freeze({ x: door.x + s.dx, y: door.y + s.dy });
}

/**
 * A walk's `cab` leg STOOD AT RIDER SPOT `slot` (card#12744, § 6.2's walk note item 10): the leg's `at` is the spot, and a
 * rider whose spot is not the threshold steps between the two over § 12's *Elevator leaves* step, inside the cab — a
 * boarder (a floor leg before its cab leg) from the threshold to its spot over the leg's first step (`enter`), an
 * arrival (a floor leg after it) from its spot to the threshold over its last (`leave`), and a rider with neither — item
 * 16's turn-round in the cab — from where it stood (`from`) to its spot. A rider with a floor leg after it and a `from`
 * — a seat turned back from inside the cab, card#12466 — stands where it stood and leaves from there. At spot 0 it stands
 * where the scene drew it.
 * The timing is never touched: only where the cab leg stands. Re-seating a seated walk gives the same legs.
 *
 * @param {list<object>} legs the walk's legs
 * @param {{x: number, y: number}} door the threshold
 * @param {number} slot the rider's spot (`cabSpot()`)
 * @param {?{x: number, y: number}} from where a rider with no floor leg either side steps from, or `null`
 */
export function seatInCab(legs, door, slot, from = null) {
    const i = legs.findIndex((l) => l.kind === 'cab');

    if (i === -1) {
        return legs;
    }

    const at = cabSpot(door, slot);
    const { enter, leave, ...leg } = legs[i];
    const step = ELEVATOR_DOOR.step;

    // card#12466: a seat turned back from inside the cab (item 6a) leaves it from where it stood when it turned.
    if (i === 0 && i < legs.length - 1 && from !== null) {
        const stood = Object.freeze({ x: from.x, y: from.y });
        const out = stood.x === door.x && stood.y === door.y ? {} : { leave: Object.freeze({ to: Object.freeze({ x: door.x, y: door.y }), frames: step }) };

        return Object.freeze(legs.map((l, j) => (j === i ? Object.freeze({ ...leg, at: stood, ...out }) : l)));
    }

    const here = (p) => p.x === at.x && p.y === at.y;
    const moved = i > 0
        ? (here(door) ? {} : { enter: Object.freeze({ from: Object.freeze({ x: door.x, y: door.y }), frames: step }) })
        : i < legs.length - 1
            ? (here(door) ? {} : { leave: Object.freeze({ to: Object.freeze({ x: door.x, y: door.y }), frames: step }) })
            : (from === null || here(from) ? {} : { enter: Object.freeze({ from: Object.freeze({ x: from.x, y: from.y }), frames: step }) });

    return Object.freeze(legs.map((l, j) => (j === i ? Object.freeze({ ...leg, at, ...moved }) : l)));
}

/**
 * § 12's *Queue spacing* (card#12705): the least distance between the centres of two spots walkers wait at by the
 * elevator — the seat walker's drawn width (`walkerSize()`) plus the `clear` tier's side margin, so two waiting side by
 * side keep a person's clearance between them. An intern is narrower, so the figure holds for every walker.
 */
export function queueSpacing(character) {
    return walkerSize(character).w + CLEARANCE.margin;
}

/**
 * card#12793, card#12841 — A DOORWAY'S LANE (§ 12's *Elevator lane* and *Restroom lane*): the strip of floor in front of a
 * door that no waiting walker's feet stand in, and no incoming walker's feet enter while an outgoing one's are in it. As
 * wide as the doorway — `span`, or a seat's drawn body plus both *Walker clearance* side margins where that is wider, so a
 * narrower door widens it rather than shrinks it — centred on `foot`, the doorway's centre on its floor line, and from that
 * line down one drawn body's height (`walkerSize()`), so a walker standing outside it is drawn wholly below the doorway's
 * foot. One lane, sized to the seat, for seats and interns alike. A rect `{x, y, w, h}` in scene px.
 *
 * @param {{x: number, y: number}} foot
 * @param {number} span the doorway's width, px
 * @param {{w: number, h: number}} character the seat character's rect
 */
export function doorLane(foot, span, character) {
    const body = walkerSize(character);
    const w = Math.max(span, body.w + 2 * CLEARANCE.margin);

    return Object.freeze({ x: foot.x - w / 2, y: foot.y, w, h: body.h });
}

/**
 * card#12793 — § 12's *ELEVATOR LANE* (§ 6.2's walk note item 9; the operator, 2026-11-02: *"Etiquette dictates that people
 * waiting for elevator leave enough room so that people inside the elevator can come out first"*): `doorLane()` in front of
 * the leaves, their span wide, centred on the seam at the threshold. `null` with no band.
 *
 * @param {?object} band the scene's band
 * @param {{w: number, h: number}} character the seat character's rect
 */
export function elevatorLane(band, character) {
    const door = threshold(band);

    return door === null ? null : doorLane(door, band.elevator.w, character);
}

/**
 * card#12841 — § 12's *RESTROOM LANE* (§ 6.7 item 3 rule 2; the operator's answer to § 14 item 47 Q3, 2026-11-04: the same
 * etiquette at the restroom door): `doorLane()` in front of a restroom door's leaf — its span the leaf's, the recess it
 * fills (`RESTROOM_RECESS`), centred on the leaf at the tile's foot. The door's threshold lies inside it: its centre is
 * within the door's width and at most one lattice cell below the foot, and the lane is wider than the door.
 *
 * @param {{x: number, y: number, w: number, h: number}} door the door's tile rect, scene px
 * @param {{w: number, h: number}} character the seat character's rect
 */
export function restroomLane(door, character) {
    return doorLane({ x: door.x + RESTROOM_RECESS.x + RESTROOM_RECESS.w / 2, y: door.y + door.h }, RESTROOM_RECESS.w, character);
}

/**
 * Whether a walker's FEET at `p` are in a lane (`doorLane()`): the rect's edges included, so the elevator's threshold — on
 * its top edge — is in it. A lane is a rule on feet, never on drawn bodies (§ 12's *Elevator lane*).
 */
export function inLane(lane, p) {
    return p.x >= lane.x && p.x <= lane.x + lane.w && p.y >= lane.y && p.y <= lane.y + lane.h;
}

/**
 * card#12793, card#12841 — THE FRAME A WALKER's FEET CROSS A LANE's EDGE: the first frame of `effect` from `from` at which
 * `pointAt()` has the feet on the floor inside `lane` (`inside` true: the frame a walker heading for a door sets foot in
 * its lane) or not (`inside` false: the frame one leaving a door is clear of it — off the floor, back in the cab, counting
 * as clear), else its last frame. A function of the effect's legs alone, so every render reads one frame off one walk.
 */
export function laneFrame(effect, lane, from, inside) {
    for (let t = from; t < effect.frames; t++) {
        const p = pointAt(effect, t * effect.frame_interval_ms);

        if ((p.where === 'floor' && inLane(lane, p)) === inside) {
            return t;
        }
    }

    return Math.max(from, effect.frames);
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
 * step keeps every remaining step one of theirs, so the joined path is open floor too. A walker waiting on the threshold
 * itself — past the last spot (card#12793: no spot is the threshold) — walks the route.
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
 * card#12747 — A DEPARTURE's WAY TO THE ELEVATOR, BY ITS CALL PLATE (§ 6.2's walk note item 9; the operator, 2026-11-01:
 * *"When agents go to the elevator, they should go to the buttons first (pause to press the button), and then move to the
 * waiting queue"*): from `from` at frame `start`, its walk to its plate's standing point (`callPoint()`), the `stand` it
 * presses there for § 12's *Call press*, and its route from there to the threshold — `{ press, walk, tier }`, `press`
 * the queue part's `{ walk, stand, plate, gap, line }` (`line` the frames it waits for the plates before it walks, 0 here;
 * `queueLater()`), `tier` the looser of the two routes' clearance tiers. Where either route is closed, or there is no
 * plate, the departure walks `straight` — its route from `from` to the threshold — with no press, as before the plates.
 *
 * @param {function({x: number, y: number}, {x: number, y: number}): ?{points: list<object>, tier: string}} routed
 * @param {{points: list<object>, tier: string}} straight
 */
function wayOut(routed, straight, from, start, door, call) {
    const there = call === null ? null : routed(from, call.at);
    const back = there === null ? null : routed(call.at, door);

    if (back === null) {
        return Object.freeze({ press: null, walk: walkLeg(start, straight.points), tier: straight.tier });
    }

    const walk = walkLeg(start, there.points);
    const stand = Object.freeze({ kind: 'stand', start: walk.start + walk.frames, frames: CALL_PRESS_FRAMES, at: call.at, why: 'press', plate: call.plate });

    return Object.freeze({
        press: Object.freeze({ walk, stand, plate: call.plate, gap: call.gap, line: 0 }),
        walk: walkLeg(stand.start + stand.frames, back.points),
        tier: CLEARANCE_TIERS[Math.max(CLEARANCE_TIERS.indexOf(there.tier), CLEARANCE_TIERS.indexOf(back.tier))],
    });
}

/**
 * card#12747 — A DEPARTURE's QUEUE PART WITH ITS WAY TO THE PLATES `frames` LATER: it stands where it set off for those
 * frames (its `line`) before it walks, and its walk to the plates, its press and its route on to the threshold all start
 * that much later. Used only on a departure no frame of which has been drawn (the holder's `#call()`).
 */
export function queueLater(queue, frames) {
    const p = queue.press;
    const later = (leg) => Object.freeze({ ...leg, start: leg.start + frames });

    return Object.freeze({
        ...queue,
        press: Object.freeze({ ...p, line: p.line + frames, walk: later(p.walk), stand: later(p.stand) }),
        walk: later(queue.walk),
    });
}

/**
 * The frame a departure's seeded WAIT is counted from (walk note item 9, card#12747): the end of its press — it is the
 * wait for the elevator the press called — or, for a departure that walks straight to the threshold, its reaching its
 * spot, as before the plates.
 */
export function waitFrom(queue, shape) {
    return queue.press === null ? shape.arrive : queue.walk.start;
}

/**
 * WHERE A DEPARTURE STANDS AND HOW IT BOARDS, for one waiting spot (walk note item 9, card#12705): its walk to the spot,
 * the frame it arrives there (`arrive`), and its walk from the spot to the threshold when it boards (`board`; `null`
 * on the threshold itself, where a walker past the last spot waits). `queue` is the departure's own part, from the render that applied its row —
 * `lead` (the legs before it walks: an intern's laptop fade), `press` (its way to its call plate and its press there,
 * `wayOut()`'s, or `null`; card#12747), `walk` (its route to the threshold — from its plate, where it pressed one), `wait`
 * (its seeded draw, frames) and `threshold`; `spot` is `{index, at, path}`, `spotsNear()`'s.
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
 * (counted from its press, card#12747 — `waitFrom()`) and is at its spot, the leaves open on it then, it walks to the
 * threshold if its spot is not the threshold, steps into the cab once the leaves are open, and they close behind it.
 */
export function loneTiming(queue, shape) {
    const D = ELEVATOR_DOOR;
    const open = Math.max(shape.arrive, waitFrom(queue, shape) + queue.wait);
    const step = Math.max(open + (shape.board?.frames ?? 0), open + D.open);

    return Object.freeze({ open, approach: open, step, close: step + D.step + D.close });
}

/**
 * A DEPARTURE's legs and door span for one timing (walk note items 5 and 9): the lead legs; where it presses its call
 * plate (card#12747) — the `stand` it waits in for the plates where it set off (`line`, when it has one), its walk to
 * the plate and the `stand` it presses in there; the walk to its spot, the `stand` it waits in there until it leaves for
 * the door (`approach`), the walk from the spot to the threshold, the `stand` at the door until it steps in (`step`), and
 * the `cab` — inside, behind the leaves, until they have closed (`close`), drawn at the threshold here and stood at its
 * rider spot by the holder (`seatInCab()`, card#12744). The door span is from the leaves' opening (`open`, never before
 * the walk's own frame 0) to their closing.
 */
export function departureLegs(queue, shape, timing) {
    const at = queue.threshold;
    const stand = (start, frames, where, why) => Object.freeze({ kind: 'stand', start, frames, at: where, why });
    const board = shape.board === null ? null : Object.freeze({ ...shape.board, start: timing.approach });
    const atDoor = timing.approach + (board?.frames ?? 0);
    const p = queue.press;
    const called = p === null ? [] : [...(p.line > 0 ? [stand(p.walk.start - p.line, p.line, p.walk.points[0], 'line')] : []), p.walk, p.stand];
    const legs = Object.freeze([
        ...queue.lead,
        ...called,
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
 * legs at the threshold with its own wait, which the holder replaces when it places it at its spot. `way` is `wayOut()`'s — `press` `null` for a departure that walks
 * straight to the threshold. The holder (`floor/in-flight.js`) gives it its turn at the plates and its spot, and
 * re-times it with every other walker at the elevator.
 */
function departing(lead, way, wait, at) {
    const queue = Object.freeze({ lead: Object.freeze(lead), press: way.press, walk: way.walk, wait, threshold: at });
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
 * the desk's anchor and the elevator's threshold, as legs in frame order — A2 walks to its call plate and presses it
 * (`wayOut()`, card#12747; straight to the threshold where a plate's route is closed), walks on to the threshold, WAITS
 * there for its seeded wait, stands while the leaves open on it, and steps into the cab, where the closing leaves cover
 * it, as drawn alone (`departing()`; the holder gives it its turn at the plates, its spot and its turn in the queue,
 * item 9, card#12705); A1
 * is revealed in the cab by the opening leaves, steps out, and walks to its desk while they close behind it, sitting
 * on its walk's last frame. `door` is the frames the leaves are not shut in. With no motion, no desk on this floor,
 * no band, or no open route, the row is drawn without its walk: no legs, frames 0 (walk note item 7).
 */
function walkEffect(base, id, anchor, door, call, motion, character, box, walking, start = null) {
    const key = `${base.install_id}/${base.seat_id}`;
    const none = (narrate) => noWalk(base, id, walking, narrate);

    if (!motion || anchor === null || door === null) {
        return none(false);
    }

    // card#12749 (§ 6.6 item 5): an A2 that lands while the seat's snack trip is in flight walks from where the trip has
    // the character at this render's instant (`start`), never from the desk. card#12786 (§ 6.7 item 5): from a restroom
    // visit at the door, it comes out first — `start.lead` frames `inside` at the threshold while the leaf opens.
    const [from, to] = id === 'A2' ? [start ?? anchor, door] : [door, anchor];
    const lead = start?.lead ?? 0;
    const routeTo = seatRoutes(walking, key, anchor);
    const routed = routeTo(from, to);

    if (routed === null) {
        return none(walking.inRoom(key));
    }

    const { points } = routed;
    const D = ELEVATOR_DOOR;
    const at = Object.freeze({ x: door.x, y: door.y });
    const cab = (start, frames) => Object.freeze({ kind: 'cab', start, frames, at });
    // card#12747: A2 walks by its call plate and presses it on its way, where both routes are open; else straight to the
    // threshold, as before the plates.
    const way = id !== 'A2' ? null : wayOut(routeTo, routed, from, lead, door, call);
    // A2 queues at the elevator (walk note item 9): drawn alone at the threshold here, re-timed by the holder.
    const drawn = id === 'A2'
        ? departing(lead > 0 ? [Object.freeze({ kind: 'inside', start: 0, frames: lead, at: Object.freeze({ x: from.x, y: from.y }) })] : [], way, walking.wait(key, base.cause), at)
        : {
            queue: null,
            legs: Object.freeze([cab(0, D.open), Object.freeze({ kind: 'stand', start: D.open, frames: D.step, at, why: 'step' }), walkLeg(D.open + D.step, points)]),
            door: Object.freeze({ start: 0, frames: D.open + D.step + D.close }),
        };

    return seatWalk(base, id, walking, character, box, {
        legs: drawn.legs,
        door: drawn.door,
        ...(drawn.queue === null ? {} : { queue: drawn.queue }),
        ...(start === null ? {} : { from_trip: true }),
        clearance: way?.tier ?? routed.tier,
    });
}

/**
 * A SEAT's ROUTES (§ 6.2's walk note item 3): from one point to another on the walking model, for the seat walker's body
 * and with the seat's own desk open on its anchor's row — `{ points, tier }`, the route's cell centres bracketed by its two
 * ends, or `null` where no route exists. One reader for a seat's walk (`walkEffect()`) and its turn-round (`seatTurn()`).
 */
function seatRoutes(walking, key, anchor) {
    // A route's cell centres, bracketed by the walk's two ends.
    const pointsOf = ({ cells }, a, b) => Object.freeze([a, ...cells.map((c) => walking.centre(c)), b].map((p) => Object.freeze({ x: p.x, y: p.y })));

    return (a, b) => {
        const r = walking.route(key, anchor, a, b);

        return r === null ? null : { points: pointsOf(r, a, b), tier: r.tier };
    };
}

/**
 * A SEAT's row DRAWN WITHOUT ITS WALK (§ 6.2's walk note item 7): no legs, frames 0, no door — the shape `walkEffect()`
 * returns with no motion, no desk on this floor, no band or no open route, and a turn-round (card#12466) whose walker is
 * already at its desk. `narrate` is § 5.5's line, for a desk inside a room that cannot reach the elevator — a defect of the
 * map, never an overflow desk, which stands on no grid.
 */
function noWalk(base, id, walking, narrate) {
    return Object.freeze({
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
        no_route: narrate,
    });
}

/**
 * A SEAT's routed walk effect — an A1 or A2 walk (`walkEffect()`) or a turn-round of one (`seatTurn()`, card#12466): its
 * legs, its door span (`null` for none), `door_rest` where a turn-round carries leaves still closing from the walk it
 * replaced, its queue part where it is a departure, and the clearance tier its route was found at (`route.js`'s
 * `CLEARANCE_TIERS`, card#12442 — read by the tests and the walk probe, drawn nowhere). The walker is the desk's character
 * at its drawn size, its feet on the path: `size` its rect (the placeholder's, § 9 F14, and the body the route keeps
 * clear), `frame` the size its image is drawn at — the character's overdraw, the frame every seat document is drawn in
 * (§ 10.4's frame contract, card#12259), centred on the feet as the desk's is.
 */
function seatWalk(base, id, walking, character, box, parts) {
    const { legs, door, door_rest: rest = null, ...more } = parts;
    const spans = [door, rest].filter((s) => s !== null && s !== undefined);

    return Object.freeze({
        ...base,
        route: 'routed',
        side: WALK_SIDE[id],
        lattice_key: walking.key,
        legs: Object.freeze(legs),
        door: door === null ? null : Object.freeze(door),
        ...(rest === null ? {} : { door_rest: Object.freeze(rest) }),
        ...more,
        no_route: false,
        size: walkerSize(character),
        frame: Object.freeze((({ w, h }) => ({ w, h }))(characterOverdraw(box, character).overdraw)),
        frames: Math.max(...legs.map((l) => l.start + l.frames), ...spans.map((s) => s.start + s.frames)),
    });
}

/**
 * card#12466 — A SEAT's A1 OR A2 THAT LANDS WHILE ITS OPPOSITE WALK IS IN FLIGHT turns the walker round (§ 6.2's walk note
 * item 6a): `turnRound()`, the intern's primitive, handed the seat's geometry — its desk's anchor, the main call plate, its
 * own seeded wait for the row's `cause`. An A1 over a departure walks the person back to its
 * desk from where it is; an A2 over an arrival walks it back out from where it is. A walker already at its desk, or one
 * with no open route, is the row drawn without its walk; the holder admits the turn only where nothing but the row itself
 * touched the walk it replaces (`floor/in-flight.js`).
 */
function seatTurn(base, id, flying, anchor, door, call, character, box, walking) {
    const key = `${base.install_id}/${base.seat_id}`;
    const routeTo = seatRoutes(walking, key, anchor);
    const turned = turnRound(flying, {
        // The row says where the person goes — an A1 to its desk, an A2 to the elevator — whatever shape the walk turned from.
        to: id === 'A1' ? 'desk' : 'elevator',
        door,
        anchor,
        lead: [],
        home: (p) => routeTo(p, anchor),
        out: (p, lead) => {
            const r = routeTo(p, door);

            if (r === null) {
                return null;
            }

            // It walks once its lead — coming out of a restroom door — has ended.
            const way = wayOut(routeTo, r, p, Math.max(0, ...lead.map((l) => l.start + l.frames)), door, call);

            return { ...departing(lead, way, walking.wait(key, base.cause), door), tier: way.tier };
        },
    });

    if (turned === null || turned.seated === true) {
        return noWalk(base, id, walking, false);
    }

    return seatWalk(base, id, walking, character, box, {
        legs: turned.legs,
        door: turned.door,
        door_rest: turned.door_rest ?? null,
        ...(turned.queue === undefined ? {} : { queue: turned.queue }),
        leg_kind: 'turn',
        turned_in: turned.turned_in,
        turned_at: turned.turned_at,
        clearance: turned.tier,
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
                desks: desks.map((d) => ({ key: d.key, rects: [d.slot_rect, d.box].filter((r) => r !== null), seat: seatOf(d, character) })),
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
        // card#12749: the lattice for one walker body — the snack corner's spots are found on the visitor's own.
        grid: (body) => built(body),
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
 *        `null` where the tree failed to load); `themes` is `cab-paint.js`'s `themeInputs()` (re-exported by `painter.js`); `maps` is
 *        install_id → the held map document or `null`; `tilesets` is `TilesetLoader#held`; `layout_version` the
 *        building layout's version held; `failed` the asset ids the painter reported; `effects` the animation-log
 *        rows this render wrote; `previous` key → the box the last scene drew; `interns_in_flight`, `laptop_at`,
 *        `trips_in_flight` and `walks_in_flight` the holder's intern walks, laptop opacities, snack trips and seat walks
 *        going into this render (card#12450, card#12749, card#12466)
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
    // card#12749 (§ 6.6 items 2 and 3): the snack corner's tables, read off the map by kind like every standing tile —
    // the client never finds a place for one — whether or not the theme draws them.
    const snackTables = [];
    // card#12786 (§ 6.7 items 2 and 3): the restroom doors, read off the map by kind the same way.
    const restroomDoors = [];

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

            if (SNACK_TABLES[c.kind] !== undefined && standing.includes(c.kind)) {
                snackTables.push(Object.freeze({ id: `${g.install_id ?? ''}#${c.layer ?? ''}#${c.index}`, room: g.install_id, kind: c.kind, x: c.x, y: c.y, w: c.w, h: c.h, index: c.index }));
            }

            if (c.kind === RESTROOM_DOOR.kind && standing.includes(c.kind)) {
                restroomDoors.push(Object.freeze({ id: `${g.install_id ?? ''}#${c.layer ?? ''}#${c.index}`, room: g.install_id, kind: c.kind, x: c.x, y: c.y, w: c.w, h: c.h, index: c.index }));
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

            const doc = docOf(`theme:${theme.name}/scenery:${cell.kind}`, 'scenery', sceneryInput(cell, g.seed));

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
    // card#12793: none in the lane in front of the doors, the threshold included (`elevatorLane()`).
    const lane = elevatorLane(band, input.character);
    let spots = null;
    const queueSpot = (k) => {
        const door = threshold(band);
        const grid = door === null ? null : walking.lattice;

        if (grid === null) {
            return null;
        }

        // card#12747: no spot within the spacing of where a departure presses a call plate.
        spots ??= spotsNear(grid, door, queueSpacing(input.character), ['main', 'low'].map((plate) => callPoint(band, input.character, plate).at),
            (p) => inLane(lane, p));

        return spots(k);
    };
    const effects = buildEffects(input.effects ?? [], frame, anchors, input.previous ?? new Map(), extent, band, input.reduce === true, input.character, box, walking, {
        desks: new Map(desks.map((d) => [d.key, d])),
        // card#12450: the intern walks the holder carries into this render, and each intern's laptop opacity there
        // (`floor/in-flight.js`) — what a turn-round turns from and a departure fades from.
        in_flight: input.interns_in_flight ?? new Map(),
        laptop_at: input.laptop_at ?? (() => null),
        // card#12749: the snack trips in flight going into this render, walker key → `{ effect, elapsed_ms }` — where an A2
        // or an A10 that lands mid-trip walks from (§ 6.6 item 5).
        trips: input.trips_in_flight ?? new Map(),
        // card#12466: the seats' A1 and A2 walks in flight going into this render, seat key → `{ effect, elapsed_ms }` — what
        // a seat's turn-round turns from (§ 6.2's walk note item 6a).
        walks: input.walks_in_flight ?? new Map(),
    });
    const snack = snackCorner(snackTables, desks, models, anchors, walking, input, box);
    // card#12786: the restroom door, beside the corner whose walkers and spot 0s it reads.
    const restroom = restroomDoor(restroomDoors, snack, walking, input, box);

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
        // card#12793: § 12's *Elevator lane* (`elevatorLane()`), `null` with no band — what the holder releases the first
        // boarder of a rider's opening against: once every rider has stepped out of it.
        lane,
        // card#12749: the snack corner — its tables, every walker this scene could send to one, and the trip a walker
        // takes (`snackCorner()`); the holder (`floor/in-flight.js`) decides when, and keeps each trip it starts.
        snack,
        // card#12786: the restroom — its doors, each with its threshold, and the visit a walker takes (`restroomDoor()`);
        // the holder decides when, keeps each visit, and keeps each door's leaf and field.
        restroom,
        loop_fps: LOOP_FPS,
    });
}

/**
 * card#12749 — THE SNACK CORNER OF ONE SCENE (FLOOR.md § 6.6 items 3 and 4): `tables`, the map's snack tables; `walkers`,
 * every character this scene draws at a desk or a stool that a trip could take — each with its anchor, its body, whether
 * its hold permits a trip now (`eligible`: A25's held rendering draws motion), whether its hold has ended while it is
 * still drawn at its desk (`recalled`: the held field left `SNACK_HELD` — a recall walks it home, § 6.6 item 5), and
 * whether it is BUSY (`busy`: its held state is `working` — the seat's `render_state`, the intern's `activity_state` —
 * card#12785, whose walker carries a food thought); `schedule(key, fromS, toS)`, `snackBreaks()` over the scene's seeded
 * draw; `thought(line, effect)`, the food thought's bubble laid out for `snack-lines.js`'s line `line` over a trip's
 * walker; and `plan(walker, break,
 * taken)`, the trip itself.
 *
 * `plan()` answers `null` where the floor holds no table of the walker's height (a plain floor, nothing to narrate),
 * `{ no_route }` where no such table can be reached (narrated once by the screen for a desk inside a room's grid),
 * `{ full }` past the last spot, and otherwise the trip's effect: the table nearest by route length from the walker's
 * anchor to its spot 0 (ties by room, then tile), the lowest spot of it that no trip in `taken` stands on, walks to or
 * stands within the spacing of, and three legs from the trip's own start — `walk` from the anchor to the spot, `stand`
 * for the dwell, `walk` home — on `route()`, at § 12's *Walk speed*. Spot 0 is § 12's *Snack spot*; the rest are
 * `spotsNear()`'s from it at *Snack spacing* on the visitor's own lattice. Nothing here starts a trip, and nothing here
 * decides whether a trip draws its thought: the screen reads `busy` off the walker each render (§ 6.6 item 4).
 */
function snackCorner(tables, desks, models, anchors, walking, input, box) {
    const draw = typeof input.draw === 'function' ? input.draw : null;
    const reduce = input.reduce === true;
    const character = input.character;
    const bodyOf = (visitor) => (visitor === 'seat' ? walkerSize(character) : INTERN_SIZE);
    const ordered = [...tables].sort((a, b) => ((a.room ?? '') < (b.room ?? '') ? -1 : (a.room ?? '') > (b.room ?? '') ? 1 : a.index - b.index));
    // Each table's spots on its visitor's lattice, built on the first ask.
    const spotsOf = new Map();
    const spots = (t) => {
        if (!spotsOf.has(t.id)) {
            const body = bodyOf(SNACK_TABLES[t.kind].visitor);
            // The visitor's own body's lattice — the one its routes are found on.
            const grid = walking.grid(body);
            const o = snackSpotOffset(body);
            const open = (p) => {
                const c = grid === null ? null : cellAt(grid, p);

                if (c === null) {
                    return null;
                }

                const snapped = cellCentre(grid, c);

                return CLEARANCE_TIERS.some((tier) => blockedFor(grid, { key: null, anchor: snapped }, tier)[c[1] * grid.cols + c[0]] === 0) ? snapped : null;
            };
            const spot0 = open({ x: t.x + o.dx, y: t.y + t.h + o.dy }) ?? open({ x: t.x + t.w + o.mirror, y: t.y + t.h + o.dy });

            spotsOf.set(t.id, spot0 === null ? () => null : spotsNear(grid, spot0, snackSpacing(body)));
        }

        return spotsOf.get(t.id);
    };
    const walkers = [];

    for (const desk of desks) {
        const model = models[desk.key];
        const anchor = anchors.get(desk.key) ?? null;

        if (model === undefined || anchor === null) {
            continue;
        }

        walkers.push(Object.freeze({
            key: desk.key,
            seat_key: desk.key,
            kind: 'seat',
            install_id: desk.install_id,
            seat_id: desk.seat_id,
            anchor,
            in_room: desk.slot_rect !== null,
            species: model.species ?? null,
            eligible: model.snack_held?.motion === true,
            // The held field left A25's values and the desk still draws its character: a trip in flight walks it home.
            recalled: model.character === true && (model.snack_held ?? null) === null,
            busy: model.render_state?.value === 'working',
            // card#12786: the restroom break's hold (A26), read off its own half as `eligible` is off A25's.
            restroom_eligible: model.restroom_held?.motion === true,
        }));

        for (const stool of model.side_table?.stools ?? []) {
            walkers.push(Object.freeze({
                key: `${desk.key}~${stool.call_id}`,
                seat_key: desk.key,
                kind: 'intern',
                install_id: desk.install_id,
                seat_id: desk.seat_id,
                call_id: stool.call_id,
                slot: stool.slot,
                intern_seat_key: internKey(desk.seat_id, stool.call_id),
                seat_species: model.species ?? null,
                activity_state: stool.activity,
                anchor: slotFoot(desk, stool.slot, box, character),
                in_room: desk.slot_rect !== null,
                eligible: SNACK_HELD.includes(stool.activity) && model.snack_interns_held?.motion === true,
                recalled: !SNACK_HELD.includes(stool.activity),
                busy: stool.activity === 'working',
                restroom_eligible: SNACK_HELD.includes(stool.activity) && model.restroom_interns_held?.motion === true,
            }));
        }
    }

    // `from`: card#12786 (§ 6.7 item 7) — a break handed over from the walker's restroom visit walks from the threshold,
    // not from the anchor; its walk home is to the anchor all the same.
    const plan = (w, schedule, taken, from = null) => {
        const visitor = w.kind;
        const start = from ?? w.anchor;
        const mine = ordered.filter((t) => SNACK_TABLES[t.kind].visitor === visitor);

        if (mine.length === 0 || reduce || draw === null || w.anchor === null) {
            return null;
        }

        const body = bodyOf(visitor);
        const spacing = snackSpacing(body);
        const routed = (from, to) => {
            const r = walking.route(w.seat_key, w.anchor, from, to, body);

            if (r === null) {
                return null;
            }

            return { points: Object.freeze([from, ...r.cells.map((c) => walking.centre(c)), to].map((p) => Object.freeze({ x: p.x, y: p.y }))), tier: r.tier };
        };
        let best = null;

        for (const t of mine) {
            const s0 = spots(t)(0);
            const r = s0 === null ? null : routed(start, s0.at);

            if (r !== null && (best === null || polylineLength(r.points) < polylineLength(best.r.points))) {
                best = { t, r };
            }
        }

        if (best === null) {
            return Object.freeze({ no_route: true });
        }

        for (let k = 0; ; k++) {
            const s = spots(best.t)(k);

            if (s === null) {
                return Object.freeze({ full: true });
            }

            if (spotTaken(taken, best.t.id, k, s.at, spacing)) {
                continue;
            }

            const there = k === 0 ? best.r : routed(start, s.at);
            const home = there === null ? null : routed(s.at, w.anchor);

            if (home === null) {
                continue;
            }

            const out = walkLeg(0, there.points);
            const stand = Object.freeze({ kind: 'stand', start: out.frames, frames: schedule.dwell_s * LOOP_FPS, at: s.at, why: 'snack' });
            const back = walkLeg(stand.start + stand.frames, home.points);
            const legs = Object.freeze([out, stand, back]);
            const intern = visitor === 'intern';

            return Object.freeze({
                animation_id: 'A25',
                ...form('A25', false),
                cause: null,
                install_id: w.install_id,
                seat_id: w.seat_id,
                ...(intern
                    ? { intern_key: w.key, call_id: w.call_id, intern_seat_key: w.intern_seat_key, seat_species: w.seat_species, slot: w.slot }
                    : { species: w.species }),
                walker_key: w.key,
                leg_kind: 'snack',
                start_s: schedule.start_s,
                dwell_s: schedule.dwell_s,
                // card#12785: the break's food line, an index into `snack-lines.js`'s list — drawn only while the walker
                // is busy, which the screen reads off the walker each render (`thought` on the effect it hands the painter).
                line: schedule.line,
                table: best.t.id,
                table_kind: best.t.kind,
                spot: Object.freeze({ table: best.t.id, index: k, at: s.at, spacing }),
                anchor: w.anchor,
                route: 'routed',
                clearance: CLEARANCE_TIERS[Math.max(CLEARANCE_TIERS.indexOf(there.tier), CLEARANCE_TIERS.indexOf(home.tier))],
                lattice_key: walking.key,
                size: body,
                frame: intern ? Object.freeze({ w: STOOL_FRAME, h: STOOL_H }) : Object.freeze((({ w: fw, h: fh }) => ({ w: fw, h: fh }))(characterOverdraw(box, character).overdraw)),
                frame_interval_ms: 1000 / LOOP_FPS,
                door: null,
                side: null,
                legs,
                frames: back.start + back.frames,
            });
        }
    };

    return Object.freeze({
        tables: Object.freeze(ordered),
        walkers: Object.freeze(walkers),
        schedule: (key, fromS, toS) => (draw === null ? [] : snackBreaks(draw, key, fromS, toS)),
        thought: (line, effect) => snackThought(SNACK_LINES[line], input.measure, effect),
        plan,
        spot: (tableId, k) => {
            const t = ordered.find((x) => x.id === tableId);

            return t === undefined ? null : spots(t)(k);
        },
    });
}

/**
 * WHETHER SPOT `k` OF CORNER `id`, standing at `at` for a visitor whose spacing is `spacing`, is TAKEN by a trip in flight
 * (card#12749; card#12786's one `taken` set across the snack corner and the restroom door, § 6.7 item 3 rule 2): another
 * visitor stands on it, walks to it, or stands within the larger of the two spacings of it.
 */
function spotTaken(taken, id, k, at, spacing) {
    return taken.some((o) => (o.table === id && o.index === k) || Math.hypot(o.at.x - at.x, o.at.y - at.y) < Math.max(o.spacing, spacing));
}

/** A plan's answer past the last waiting spot the floor holds (card#12786): no visit for that break. */
const NO_SPOT = Object.freeze({ full: true });

/**
 * card#12786 — THE RESTROOM DOOR OF ONE SCENE (FLOOR.md § 6.7 items 2–5): `doors`, the map's restroom doors, each with its
 * threshold (`null` where none is open) and the rects the painter draws its leaf, its field and its occupant at; `schedule
 * (key, fromS, toS)`, the walker's restroom chain (`snackBreaks()` under `RESTROOM_CHAIN`); `thought(effect)`, the restroom
 * line's bubble over a visit's walker; `spot(doorId, k)`; and `plan(walker, break, { from, taken, turn, clear })`, the
 * visit — `turn(doorId)` the frame, from the visit's start, the door is free for it, and `clear(doorId)` (card#12841) the
 * frame every walker who came out of it before is clear of its lane (§ 12's *Restroom lane*, each door's `lane`).
 *
 * The walkers are the snack corner's (`snack.walkers`), each with its `restroom_eligible` hold. `plan()` answers `null`
 * where the floor holds no door (or reduced motion, or no seeded draw), `{ no_route }` where no door's threshold can be
 * reached, `{ full }` where the walker must wait and no waiting spot is free, and otherwise the visit's effect: the door
 * nearest by route length from where it sets off (ties by room, then tile); and its legs from the visit's own start —
 * `walk` to the threshold where the door is free when it arrives (`turn()`, the frame the door frees, at or before its
 * arrival) and the lane is clear when its feet enter it (`clear()`, at or before that frame — card#12841), else `walk` to
 * the lowest waiting spot no trip in `taken` holds, `stand` there (`wait`) until the later of the two, and `walk` the
 * spot's path to the threshold; then `stand` at the threshold while the leaf opens (`door`), `inside` through the
 * closing, the dwell and the opening, and `walk` home. With it, the door's leaf timeline (`leaf`), the field's occupied
 * frames (`occupied`) and the frames the door is held (`claim`), all in the visit's frames, and the door's `lane`. Spots
 * past the threshold are `spotsNear()`'s at § 12's *Queue spacing* on the SEAT's lattice, clear of both snack spot 0s and
 * of the threshold, and — card#12841 — none in the door's lane: the lane is `spotsNear()`'s `avoid`, as the elevator's.
 */
function restroomDoor(doors, snack, walking, input, box) {
    const draw = typeof input.draw === 'function' ? input.draw : null;
    const reduce = input.reduce === true;
    const character = input.character;
    const D = ELEVATOR_DOOR;
    const seat = walkerSize(character);
    const grid = doors.length === 0 ? null : walking.grid(seat);
    const spacing = queueSpacing(character);
    const ordered = [...doors].sort((a, b) => ((a.room ?? '') < (b.room ?? '') ? -1 : (a.room ?? '') > (b.room ?? '') ? 1 : a.index - b.index));
    // § 12's *Restroom threshold*: under the door's centre, *Walker clearance*'s depth plus 1 px below its foot, snapped to
    // its cell on the seat's lattice; where that cell is not open at `clear`, the nearest cell on its row that is, its
    // centre within the door's width, ties to the left; none, no threshold.
    const clearAt = (c) => {
        const centre = cellCentre(grid, c);

        return blockedFor(grid, { key: null, anchor: centre }, CLEARANCE_TIERS[0])[c[1] * grid.cols + c[0]] === 0;
    };
    const thresholdOf = (d) => {
        const under = grid === null ? null : cellAt(grid, { x: d.x + d.w / 2, y: d.y + d.h + CLEARANCE.depth + 1 });

        if (under === null) {
            return null;
        }

        const mid = d.x + d.w / 2;
        const row = [];

        for (let col = 0; col < grid.cols; col++) {
            const centre = cellCentre(grid, [col, under[1]]);

            if (centre.x >= d.x && centre.x < d.x + d.w) {
                row.push({ c: [col, under[1]], centre });
            }
        }

        row.sort((a, b) => Math.abs(a.centre.x - mid) - Math.abs(b.centre.x - mid) || a.centre.x - b.centre.x);

        const first = clearAt(under) ? { centre: cellCentre(grid, under) } : row.find((r) => clearAt(r.c)) ?? null;

        return first === null ? null : Object.freeze({ x: first.centre.x, y: first.centre.y });
    };
    const clear = snack.tables.map((t) => snack.spot(t.id, 0)?.at ?? null).filter((p) => p !== null);
    const at = (d, r) => Object.freeze({ x: d.x + r.x, y: d.y + r.y, w: r.w, h: r.h });
    const placed = ordered.map((d) => Object.freeze({
        ...d,
        threshold: thresholdOf(d),
        sign: at(d, RESTROOM_PLATES.sign),
        plate: at(d, RESTROOM_PLATES.plate),
        recess: at(d, RESTROOM_RECESS),
        lane: restroomLane(d, character),
    }));
    const spotsOf = new Map();
    // Spot 0 is the threshold; spots 1, 2, … are `spotsNear()`'s from it — which skips the threshold, inside the lane, so
    // it is passed as a `clear` point to keep the waiting spots *Queue spacing* from the walker standing there.
    const spots = (d) => {
        if (!spotsOf.has(d.id)) {
            const near = d.threshold === null ? null : spotsNear(grid, d.threshold, spacing, [d.threshold, ...clear], (p) => inLane(d.lane, p));
            const door = d.threshold === null ? null : Object.freeze({ at: d.threshold, path: Object.freeze([d.threshold]) });

            spotsOf.set(d.id, near === null ? () => null : (k) => (k === 0 ? door : near(k - 1)));
        }

        return spotsOf.get(d.id);
    };

    const plan = (w, brk, { from = null, taken = [], turn = () => 0, clear: laneFree = () => 0 } = {}) => {
        const usable = placed.filter((d) => d.threshold !== null);

        if (placed.length === 0 || reduce || draw === null || w.anchor === null) {
            return null;
        }

        const body = w.kind === 'seat' ? seat : INTERN_SIZE;
        const start = from ?? w.anchor;
        const routed = (a, b) => {
            const r = walking.route(w.seat_key, w.anchor, a, b, body);

            return r === null ? null : { points: Object.freeze([a, ...r.cells.map((c) => walking.centre(c)), b].map((q) => Object.freeze({ x: q.x, y: q.y }))), tier: r.tier };
        };
        let best = null;

        for (const d of usable) {
            const r = routed(w.anchor, d.threshold);

            if (r !== null && (best === null || polylineLength(r.points) < polylineLength(best.r.points))) {
                best = { d, r };
            }
        }

        if (best === null) {
            return Object.freeze({ no_route: true });
        }

        const d = best.d;
        const thr = d.threshold;
        const direct = from === null ? best.r : routed(start, thr);
        const back = routed(thr, w.anchor);

        if (direct === null || back === null) {
            return Object.freeze({ no_route: true });
        }

        const stand = (from_, frames, where, why) => Object.freeze({ kind: 'stand', start: from_, frames, at: where, why });
        const walkOut = walkLeg(0, direct.points);
        // The frame, from the visit's start, the door is free for this visitor (the holder's: every visit admitted to it
        // before this one has freed it), and — card#12841 — the frame every walker who came out of it before is clear of
        // its lane; and the frame this one's walk straight to the door would set foot in that lane.
        const free = turn(d.id);
        const cleared = laneFree(d.id);
        const entry = laneFrame(Object.freeze({ legs: Object.freeze([walkOut]), frames: walkOut.frames, frame_interval_ms: 1000 / LOOP_FPS }), d.lane, 0, true);
        let lead;
        let spot;
        let tier = direct.tier;

        if (free <= walkOut.frames && cleared <= entry) {
            lead = [walkOut];
            spot = Object.freeze({ table: d.id, index: 0, at: thr, spacing });
        } else {
            let found = null;

            for (let k = 1; found === null; k++) {
                const s = spots(d)(k);

                if (s === null) {
                    return NO_SPOT;
                }

                if (spotTaken(taken, d.id, k, s.at, spacing)) {
                    continue;
                }

                const there = routed(start, s.at);

                if (there !== null) {
                    found = { k, s, there };
                }
            }

            const out = walkLeg(0, found.there.points);
            const leave = Math.max(out.frames, free, cleared);
            const board = walkLeg(leave, found.s.path);

            lead = [out, stand(out.frames, leave - out.frames, found.s.at, 'wait'), board];
            spot = Object.freeze({ table: d.id, index: found.k, at: found.s.at, spacing });
            tier = CLEARANCE_TIERS[Math.max(CLEARANCE_TIERS.indexOf(tier), CLEARANCE_TIERS.indexOf(found.there.tier))];
        }

        const reached = lead[lead.length - 1];
        const atDoor = stand(reached.start + reached.frames, D.open, thr, 'door');
        const dwell = brk.dwell_s * LOOP_FPS;
        const inside = Object.freeze({ kind: 'inside', start: atDoor.start + D.open, frames: D.close + dwell + D.open, at: thr });
        const home = walkLeg(inside.start + inside.frames, back.points);
        const legs = Object.freeze([...lead, atDoor, inside, home]);
        const intern = w.kind === 'intern';

        return Object.freeze({
            animation_id: 'A26',
            ...form('A26', false),
            cause: null,
            install_id: w.install_id,
            seat_id: w.seat_id,
            ...(intern
                ? { intern_key: w.key, call_id: w.call_id, intern_seat_key: w.intern_seat_key, seat_species: w.seat_species, slot: w.slot }
                : { species: w.species }),
            walker_key: w.key,
            leg_kind: 'restroom',
            start_s: brk.start_s,
            dwell_s: brk.dwell_s,
            restroom: d.id,
            threshold: thr,
            // card#12841: the door's lane, which the holder reads this walker's way out of (`laneFrame()`).
            lane: d.lane,
            spot,
            anchor: w.anchor,
            route: 'routed',
            clearance: CLEARANCE_TIERS[Math.max(CLEARANCE_TIERS.indexOf(tier), CLEARANCE_TIERS.indexOf(back.tier))],
            lattice_key: walking.key,
            size: body,
            frame: intern ? Object.freeze({ w: STOOL_FRAME, h: STOOL_H }) : Object.freeze((({ w: fw, h: fh }) => ({ w: fw, h: fh }))(characterOverdraw(box, character).overdraw)),
            frame_interval_ms: 1000 / LOOP_FPS,
            door: null,
            side: null,
            legs,
            // The door's leaf (§ 12's *Restroom door motion*, the elevator's leaves' frames): opening as the walker
            // arrives at the threshold, closing over it, opening when the dwell ends, closing a step after it walks away.
            leaf: Object.freeze([
                Object.freeze({ start: atDoor.start, frames: D.open, from: 0, to: 1 }),
                Object.freeze({ start: inside.start, frames: D.close, from: 1, to: 0 }),
                Object.freeze({ start: inside.start + D.close + dwell, frames: D.open, from: 0, to: 1 }),
                Object.freeze({ start: home.start + D.step, frames: D.close, from: 1, to: 0 }),
            ]),
            // The field reads occupied from the closing's end through the dwell (§ 6.7 item 4).
            occupied: Object.freeze({ start: inside.start + D.close, frames: dwell }),
            // The door is held from the threshold stand's first frame to the leaf's close behind the walker.
            claim: Object.freeze({ start: atDoor.start, end: home.start + D.step + D.close }),
            frames: home.start + home.frames,
        });
    };

    return Object.freeze({
        doors: Object.freeze(placed),
        schedule: (key, fromS, toS) => (draw === null ? [] : snackBreaks(draw, key, fromS, toS, RESTROOM_CHAIN)),
        thought: (effect) => snackThought(RESTROOM_LINE, input.measure, effect),
        plan,
        spot: (doorId, k) => {
            const d = placed.find((x) => x.id === doorId);

            return d === undefined ? null : spots(d)(k);
        },
    });
}

/**
 * card#12785 — A BUSY WALKER's FOOD THOUGHT (FLOOR.md § 6.6 item 4): `text`'s bubble, laid out RELATIVE TO THE WALKER's
 * FEET so the painter draws it inside each of the walker's glides and it travels with the walker — the cloud
 * (`cloudOutline()`) round the text wrapped through the scene's one wrap on at most two lines of the bubble's widest,
 * centred over the walker, its bottom `BUBBLE_GAP` above the walker's frame and at least the trail's reach above the
 * character's rect, and the thought trail (`trailCircles()`) from the rect's top up to it. The task bubble's primitives,
 * so the form is one; what keeps it apart from the task bubble is that it carries no source line, rides a walker and
 * never a desk, and is classed `snack-thought` (the painter's italic).
 */
export function snackThought(text, measure, effect) {
    const title = wrap(text, BUBBLE_MAX_W - 2 * BUBBLE_PAD, measure, 'fact', 2);
    const w = title.w + 2 * BUBBLE_PAD;
    const h = title.h + 2 * BUBBLE_PAD;
    const top = -effect.size.h;
    const bottom = Math.min(-effect.frame.h - BUBBLE_GAP, top - TRAIL_REACH);
    const rect = Object.freeze({ x: -w / 2, y: bottom - h, w, h });

    return Object.freeze({
        ...rect,
        text: title.text,
        truncated: title.truncated,
        lines: Object.freeze(title.lines.map((l, i) => Object.freeze({ text: l.text, w: l.w, x: rect.x + BUBBLE_PAD, y: rect.y + BUBBLE_PAD + i * LINE }))),
        cloud: cloudOutline(rect),
        trail: trailCircles(0, top, bottom),
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
            // card#12785: a desk whose character is away on its snack break keeps its bubble — the card it works on —
            // and draws no trail, which is the thinker's and leaves with it (`in-flight.js`'s `awayDesk()`).
            trail: b.trail !== false,
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
            trail: want.trail ? trailCircles(foot.x, foot.y, rect.y + rect.h) : Object.freeze([]),
        });
    }

    for (const desk of desks) {
        Object.freeze(desk);
    }
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
    // card#12747: the call plates in scene px, from the surround's top-left (`CALL_PLATES`).
    const plate = (r) => Object.freeze({ x: frameRect.x + r.x, y: frameRect.y + r.y, w: r.w, h: r.h });

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
            seam: thresholdOf(span).x,
            // card#12747: the two call plates the theme draws in the surround — `main` at a seat's reach, `low` an intern's.
            calls: Object.freeze({ main: plate(CALL_PLATES.main), low: plate(CALL_PLATES.low) }),
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

/**
 * A desk's SEAT as the lattice reads it (walk note item 3, card#12513): its chair — the anchor — and the furniture its
 * own seat keeps its feet off (`seatSolid()`), in scene coordinates.
 */
function seatOf(desk, character) {
    const at = desk.box;

    return Object.freeze({
        anchor: anchorOf(desk),
        solid: Object.freeze(seatSolid({ width: at.w, height: at.h }, character).map((r) => Object.freeze({ x: at.x + r.x, y: at.y + r.y, w: r.w, h: r.h }))),
    });
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
    // card#12747: where an intern presses the LOW call plate, at its own reach.
    const call = callPoint(band, character, 'low');
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
    // `rest` (card#12466): the leaves' closing a turn-round carries from the walk it replaced (`door_rest`), as a seat's does.
    const done = (fx, legs, doorSpan, tier, queue = null, rest = null) => Object.freeze({
        ...fx,
        route: 'routed',
        clearance: tier,
        legs: Object.freeze(legs),
        door: doorSpan === null ? null : Object.freeze(doorSpan),
        ...(rest === null ? {} : { door_rest: Object.freeze(rest) }),
        ...(queue === null ? {} : { queue }),
        no_route: false,
        frames: Math.max(...legs.map((l) => l.start + l.frames), ...[doorSpan, rest].filter((d) => d !== null).map((d) => d.start + d.frames)),
    });
    // A departure from the slot (item 13): the laptop fades out from `from` (a `seat` leg; none when it is already 0),
    // then the walk by the low call plate and the press (card#12747; straight to the threshold where a plate's route is
    // closed), the seeded wait and the door — A2's queue at the elevator (item 14).
    const departure = (fx, from) => {
        const r = routed(fx, fx.anchor, door);

        if (r === null) {
            return null;
        }

        const F = from > 0 ? LAPTOP_FADE : 0;
        const way = wayOut((a, b) => routed(fx, a, b), r, fx.anchor, F, door, call);
        const gone = departing(F > 0 ? [Object.freeze({ ...leg('seat', 0, F, fx.anchor), laptop_from: from, laptop_to: 0 })] : [],
            way, walking.wait(fx.intern_key, row.cause), sill);

        return done(fx, gone.legs, gone.door, way.tier, gone.queue);
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
        const trip = interns.trips.get(fx.intern_key) ?? null;

        // card#12749 (§ 6.6 item 5): an intern whose snack trip is in flight leaves from where the trip has it at this
        // render's instant — routed from that point by the low call plate, as item 16's turn-round routes from mid-walk.
        if (trip !== null && door !== null) {
            // card#12786 (§ 6.7 item 5): from a restroom visit at the door, out first (`tripStart()`'s `lead`).
            const { lead, ...here } = tripStart(trip);
            const r = routed(fx, here, door);

            if (r !== null) {
                const way = wayOut((a, b) => routed(fx, a, b), r, here, lead, door, call);
                const gone = departing(lead > 0 ? [Object.freeze({ kind: 'inside', start: 0, frames: lead, at: Object.freeze({ ...here }) })] : [], way, walking.wait(fx.intern_key, row.cause), sill);

                out.push(done({ ...fx, from_trip: true }, gone.legs, gone.door, way.tier, gone.queue));
            }

            continue;
        }
        const held = interns.laptop_at(fx.intern_key);
        const from = held ?? (fx.activity_state === 'working' ? 1 : 0);

        if (flying !== null && flying.effect.leg_kind === 'arrive') {
            const F = from > 0 ? LAPTOP_FADE : 0;
            const turned = turnRound(flying, {
                to: 'elevator',
                door: sill,
                anchor: fx.anchor,
                lead: F > 0 ? [Object.freeze({ ...leg('seat', 0, F, fx.anchor), laptop_from: from, laptop_to: 0 })] : [],
                out: (p, lead) => {
                    const r = routed(fx, p, door);

                    if (r === null) {
                        return null;
                    }

                    // It walks once its lead — the laptop's fade at its stool — has ended.
                    const way = wayOut((a, b) => routed(fx, a, b), r, p, Math.max(0, ...lead.map((l) => l.start + l.frames)), door, call);

                    return { ...departing(lead, way, walking.wait(fx.intern_key, row.cause), sill), tier: way.tier };
                },
            });

            if (turned !== null) {
                out.push(done({ ...fx, leg_kind: 'turn', turned_in: turned.turned_in, turned_at: turned.turned_at },
                    turned.legs, turned.door, turned.tier, turned.queue ?? null, turned.door_rest ?? null));
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
 * card#12466 — A WALKER's POSE, `flying.elapsed_ms` into the walk it is on: the only thing a turn-round reads of that walk
 * (`turn()`). `p` is where its feet are drawn (`pointAt()`, a cab step glided), `where` is where they are — `floor`, `cab`
 * or `inside` a restroom door, a property of the leg's KIND, which is its geometry — `inside_left` the frames until the
 * walker is out of that door (0 elsewhere), `leaves` what the elevator's leaves are doing now (`leavesNow()`, over the
 * walk's own `door` and `door_rest`) and `tier` the clearance tier the walk was routed at, which a turn that walks no new
 * route keeps. No leg's reason (`why`), no queue part and no walk kind is in it: a pose cannot say which walk it came from.
 */
export function poseOf(flying) {
    const fx = flying.effect;
    const e = flying.elapsed_ms / fx.frame_interval_ms;
    const here = pointAt(fx, flying.elapsed_ms);
    const leg = fx.legs[here.leg];

    return Object.freeze({
        p: Object.freeze({ x: here.x, y: here.y }),
        where: here.where,
        inside_left: here.where === 'inside' ? leg.start + leg.frames - e : 0,
        leaves: leavesNow(fx, e),
        tier: fx.clearance,
    });
}

/** Two points as one where they agree to a millionth of a pixel — a figure that close to its anchor or the threshold is on it. */
const atPoint = (a, b) => Math.round(a.x * 1e6) === Math.round(b.x * 1e6) && Math.round(a.y * 1e6) === Math.round(b.y * 1e6);

/**
 * THE TURN-ROUND — ONE PRIMITIVE FOR EVERY WALKER WHOSE FACT REVERSES WHILE ITS WALK IS IN FLIGHT: § 6.2's walk note item
 * 16 for an intern (card#12450) and item 6a for a seat (card#12466). The walker turns from where it is drawn at the
 * cancelling render's instant, finishing only what is physically irreversible — a closing or shut cab, coming out of a
 * restroom door — and the new effect REPLACES the old one in the holder (one walk per walker). `flying` is the holder's
 * `{ effect, elapsed_ms }`; `way` is the walker's own geometry — `to` (where the row sends it: `elevator` or `desk`), `door`
 * (the threshold), `anchor` (its desk's anchor or its stool's foot), `lead` (the legs an intern's departure from its stool
 * begins with: its laptop's fade), `out(from, lead)` (its way out from a point: `departing()`'s parts and the route's tier,
 * or `null`) and `home(from)` (its route from a point to its anchor, `{ points, tier }` or `null`).
 *
 * ⛔ THE TURN READS THE FIGURE's POSE AND THE ROW's DIRECTION, NEVER A LABEL OF THE WALK IT LEAVES (card#12466, review
 * rounds 1 and 2): `turn()` is handed `poseOf(flying)` and `way` and nothing else, so no leg's `why`, no queue part, no
 * `leg_kind` and no animation id can decide what it draws. A turn-round of a turn-round of any depth is the same reading
 * of the same two facts — where the figure is and what the leaves are doing. `walk-probe.mjs`'s `turn-blind` mode holds it
 * to that: the same walk with every label stripped turns the same way.
 */
export function turnRound(flying, way) {
    const pose = poseOf(flying);
    const turned = turn(pose, way);

    return turned === null || turned.seated === true ? turned : { ...turned, turned_in: pose.where, turned_at: pose.p };
}

/**
 * The turn itself (`turnRound()`), from a pose (`poseOf()`). Leaves that have begun to open finish opening before they
 * close, and leaves that have begun to close close as they began to — the span moving them now carried over in frames from
 * now (`door`, or `door_rest` for a closing the walker is no longer behind) — so nothing drawn is re-timed.
 *
 * TO THE ELEVATOR (an intern's item 16, a seat's A2): in the cab with the leaves opening or open it stays in and they close
 * over it; in the cab with them closing it rides on behind them; in the cab behind shut leaves it has gone (`null`); on the
 * floor at the threshold with the leaves open it steps back in and they close; coming out of a restroom door it finishes
 * coming out and leaves from the door; at its anchor it is an ordinary departure from there (an intern's laptop fading out
 * from the opacity it has reached, `way.lead`); anywhere else on the floor it walks out from where it is — by its call
 * plate, its seeded wait and the full door, a departure the holder queues.
 *
 * TO ITS DESK (a seat's A1: a closed `call_id` never reopens, so no intern turns this way): on the floor at its anchor it
 * is home and sits at once (`{ seated: true }`); anywhere else on the floor it walks home from where it is; coming out of a
 * restroom door it finishes coming out and walks home from the door; in the cab with the leaves opening or open it steps
 * out once they are open and walks home; in the cab with them closing or shut the ride completes — they close as they
 * began to, stay shut through the next frame, and open again on it, and it steps out and walks home, the arrival A1 draws.
 *
 * @returns {?object} `{ legs, door, door_rest?, queue?, tier }`; `{ seated: true }` for a walker already at its desk;
 *          `null` where no route exists or nothing is left to draw — `turnRound()` adds the pose it turned from,
 *          `turned_in` (`floor`, `cab` or `inside`) and `turned_at` (the drawn point)
 */
function turn(pose, way) {
    const D = ELEVATOR_DOOR;
    const at = way.door;

    if (at === null) {
        return null;
    }

    const { p, where, leaves } = pose;
    const leg = (kind, start, frames, q, why = null) => Object.freeze({ kind, start, frames, at: q, ...(why === null ? {} : { why }) });
    // The span moving the leaves now, finished: opening ones open fully and close once `after` (frames from now) has passed.
    const finish = (after) => {
        const close = Math.max(after, leaves.span.start + D.open);

        return Object.freeze({ start: leaves.span.start, frames: close + D.close - leaves.span.start });
    };
    // Whatever the leaves are doing now, carried over as a span the walker is no longer behind.
    const carried = leaves.state === 'closing' ? leaves.span : leaves.state === 'open' ? finish(0) : null;
    const rest = carried === null ? {} : { door_rest: carried };
    const inside = where === 'inside' ? [leg('inside', 0, pose.inside_left, p)] : [];

    if (way.to === 'elevator') {
        if (where === 'cab') {
            if (leaves.state === 'open') {
                const door = finish(0);

                return { legs: [leg('cab', 0, door.start + door.frames, at)], door, tier: pose.tier };
            }

            // Behind leaves already closing it rides on; behind shut ones it has gone, and nothing is left to draw.
            return leaves.state === 'closing'
                ? { legs: [leg('cab', 0, leaves.span.start + leaves.span.frames, at)], door: null, door_rest: leaves.span, tier: pose.tier }
                : null;
        }

        if (where === 'floor' && atPoint(p, at) && leaves.state === 'open') {
            const door = finish(D.step);

            return { legs: [leg('stand', 0, D.step, at, 'step'), leg('cab', D.step, door.start + door.frames - D.step, at)], door, tier: pose.tier };
        }

        const home = where === 'floor' && atPoint(p, way.anchor);
        const gone = home ? way.out(way.anchor, way.lead) : way.out(p, inside);

        return gone === null ? null : { legs: gone.legs, door: gone.door, queue: gone.queue, tier: gone.tier, ...rest };
    }

    if (where === 'floor' && atPoint(p, way.anchor)) {
        return { seated: true };
    }

    const homeFrom = (q, start) => {
        const r = way.home(q);

        return r === null ? null : { walk: walkLeg(start, r.points), tier: r.tier };
    };

    if (where === 'cab') {
        const h = homeFrom(at, 0);

        if (h === null) {
            return null;
        }

        if (leaves.state === 'open') {
            // The leaves opening or open: it steps out once they are open, and walks home while they close behind it.
            const out = Math.max(0, leaves.span.start + D.open) + D.step;

            return { legs: [leg('cab', 0, out, at), Object.freeze({ ...h.walk, start: out })], door: finish(out), tier: h.tier };
        }

        // Closing over it, or shut: the ride completes as drawn, they stay shut a frame, and open again on it. The leaves'
        // last closing instant is a whole millisecond — two whole-ms instants apart — so the frame after it is counted in ms.
        const I = 1000 / LOOP_FPS;
        const shut = leaves.state === 'closing' ? Math.round((leaves.span.start + leaves.span.frames) * I) : 0;
        const R = Math.ceil(shut / I) + 1;

        return {
            legs: [leg('cab', 0, R + D.open, at), leg('stand', R + D.open, D.step, at, 'step'), Object.freeze({ ...h.walk, start: R + D.open + D.step })],
            door: Object.freeze({ start: R, frames: D.open + D.step + D.close }),
            ...(leaves.state === 'closing' ? { door_rest: leaves.span } : {}),
            tier: h.tier,
        };
    }

    // On the floor, or coming out of a restroom door first: it walks home from where it is.
    const h = homeFrom(p, where === 'inside' ? pose.inside_left : 0);

    if (h === null) {
        return null;
    }

    // Leaves that have begun to open finish opening and close behind it; a closing it left behind closes as it began.
    return { legs: [...inside, h.walk], door: null, ...rest, tier: h.tier };
}

/**
 * card#12466 — WHAT THE LEAVES ARE DOING `e` FRAMES INTO A WALK, by the spans it carries (`door`, `door_rest`): `{ state,
 * span }`, `state` `open` while a span has begun and its closing has not (the leaves opening or open), `closing` once it has,
 * `shut` with no span moving them now — `span` that span in frames from this instant, or `null`. A span still to begin is
 * not drawn and moves nothing now.
 */
function leavesNow(effect, e) {
    const D = ELEVATOR_DOOR;
    const spans = [effect.door_rest, effect.door]
        .filter((s) => s !== null && s !== undefined)
        .map((s) => Object.freeze({ start: s.start - e, frames: s.frames }));
    const span = spans.find((s) => s.start <= 0 && 0 < s.start + s.frames) ?? null;

    if (span === null) {
        return { state: 'shut', span: null };
    }

    return { state: span.start + span.frames - D.close > 0 ? 'open' : 'closing', span };
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

        // An intern whose arrival is still in flight is the arrival's to draw: its `seat` leg reads the held state. One on
        // a snack trip is the trip's (card#12749): it has no laptop with it, and a recall's walk home sits it down to work.
        if (interns.in_flight.has(key) || interns.trips.has(key)) {
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

            // card#12749: an A2 over a snack trip in flight starts where the trip has the character now — card#12786: or
            // over a restroom visit at the door, at the threshold once the leaf has opened (`tripStart()`).
            const trip = id === 'A2' && key !== null ? interns.trips.get(key) ?? null : null;
            // card#12466: the seat's opposite walk in flight going into this render — an A1 over a departure, an A2 over an
            // arrival — is turned round from where its walker is (§ 6.2's walk note item 6a).
            const flying = key === null ? null : interns.walks.get(key) ?? null;

            if (flying !== null && flying.effect.animation_id !== id && motion && !reduce && now !== null && band !== null) {
                effects.push(seatTurn({ ...base, species }, id, flying, now, threshold(band), callPoint(band, character, 'main'), character, box, walking));

                continue;
            }

            effects.push(walkEffect({ ...base, species }, id, now, threshold(band), callPoint(band, character, 'main'), motion && !reduce, character, box, walking,
                trip === null ? null : tripStart(trip)));

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
