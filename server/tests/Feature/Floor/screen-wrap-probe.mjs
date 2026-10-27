/**
 * The probe `TheMonitorTextFillsItsScreenTest` drives the scene's one wrap and the screen's rect through — `node`,
 * no dependencies, no DOM (card#12446).
 *
 * ⛔ IT IMPORTS THE SHIPPED MODULE AND RE-IMPLEMENTS NOTHING. argv[2] is the module directory the rig hands every
 * floor probe (`public/js/wire`, or a mutated copy of the whole tree), and `floor/desk-layout.js` is read beside it,
 * so a planted control re-mints its defect in the real `wrap()`.
 *
 * stdin  — JSON: `{ "measurer": {glyph_w, line_h}, "box": {width, height}, "character": {w, h},
 *                   "cases": [{ "text", "width", "role", "lines" }] }`
 * stdout — JSON: `{ "font_screen", "line_screen", "rects": {monitor, "monitor-text"}, "wrapped": [<wrap() output>] }`
 *
 * The measurer is `Support/harness-measurer.mjs`, the one every floor probe hands the scene.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];

if (typeof dir !== 'string' || dir === '') {
    console.error('usage: node screen-wrap-probe.mjs <module-dir>  (JSON payload on stdin)');
    process.exit(2);
}

const payload = JSON.parse(readFileSync(0, 'utf8'));
const layout = await import(pathToFileURL(join(dir, '..', 'floor', 'desk-layout.js')).href);
const { harnessMeasurer } = await import(pathToFileURL(join(import.meta.dirname, '..', 'Support', 'harness-measurer.mjs')).href);
const measure = harnessMeasurer(payload.measurer);
const R = layout.deskRects(payload.box, payload.character);

console.log(JSON.stringify({
    font_screen: layout.FONT_SCREEN,
    line_screen: layout.TYPE_ROLES.screen.line,
    rects: { monitor: R.monitor, 'monitor-text': R['monitor-text'] },
    wrapped: payload.cases.map((c) => layout.wrap(c.text, c.width, measure, c.role, c.lines)),
}));
