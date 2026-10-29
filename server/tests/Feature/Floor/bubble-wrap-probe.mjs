/**
 * The probe `TheThoughtBubbleWrapsToThreeLinesTest` drives the scene's bubble pass through — `node`, no dependencies,
 * no DOM (card#12446).
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULES AND RE-IMPLEMENTS NOTHING. argv[2] is the module directory the rig hands every floor
 * probe (`public/js/wire`, or a mutated copy of the whole tree), and `floor/scene.js` and `floor/desk-layout.js` are read
 * beside it, so a planted control re-mints its defect in the real `placeBubbles()`, `wrap()` and `trailCircles()`.
 *
 * Each case is one desk set where `bubbleSeat()` sets it — with no tree answer (`head` null), or with the head the case
 * states — handed to `placeBubbles()` as `placeDesk()` hands it, each in a room of its own so no two are parted.
 *
 * stdin  — JSON: `{ "measurer": {glyph_w, line_h}, "box": {width, height}, "character": {w, h},
 *                   "cases": [{ "text", "source", "head": {face, mark}|null, "crest" }] }`
 * stdout — JSON: `{ "constants": {…}, "least_box": n, "bubbles": [{ desk: {bottom, foot}, bubble }] }`
 *
 * The measurer is `Support/harness-measurer.mjs`, the one every floor probe hands the scene.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node bubble-wrap-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const payload = JSON.parse(readFileSync(0, 'utf8'));
const layout = await import(pathToFileURL(join(dir, '..', 'floor', 'desk-layout.js')).href);
const { placeBubbles } = await import(pathToFileURL(join(dir, '..', 'floor', 'scene.js')).href);
const { harnessMeasurer } = await import(pathToFileURL(join(import.meta.dirname, '..', 'Support', 'harness-measurer.mjs')).href);
const measure = harnessMeasurer(payload.measurer);
const { box, character } = payload;

const desks = payload.cases.map((c, i) => {
    const at = { x: i * box.width, y: 0 };
    const seat = layout.bubbleSeat(box, character, c.head ?? null, c.crest ?? 0);

    return {
        key: `room-${i}/seat`,
        install_id: `room-${i}`,
        seat_id: 'seat',
        box: { x: at.x, y: at.y, w: box.width, h: box.height },
        anchor_x: at.x + layout.characterCentre(box, character),
        bubble_bottom: at.y + seat.bottom,
        trail_foot: { x: at.x + layout.trailFoot(box, character).x, y: at.y + seat.foot },
        elements: [],
        bubble_model: { text: c.text, truncated: false, source: c.source ?? null, degraded_note: null },
        bubble: null,
        seat: { bottom: at.y + seat.bottom, foot: at.y + seat.foot },
    };
});

placeBubbles(desks, measure, box.width, desks.map((d) => d.key));

console.log(JSON.stringify({
    constants: {
        title_lines: layout.BUBBLE_TITLE_LINES,
        bubble_lines: layout.BUBBLE_LINES,
        band: layout.BUBBLE_BAND,
        line: layout.LINE,
        pad: layout.BUBBLE_PAD,
        max_w: layout.BUBBLE_MAX_W,
        art_w: layout.ART_W,
        trail_radii: layout.TRAIL_RADII,
        trail_reach: layout.TRAIL_REACH,
    },
    least_box: layout.leastBoxHeight(box.width, character),
    bubbles: desks.map((d) => ({ box: d.box, seat: d.seat, bubble: d.bubble })),
}));
