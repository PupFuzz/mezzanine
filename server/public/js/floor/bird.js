/**
 * THE MESSAGE BIRD and THE BIRD PORTAL — the drawing of § 6.2's coordination edges (A19's traveller, A20's
 * ring) and of the birdhouse every floor's back wall carries, as plain shape lists the painter turns into
 * SVG (card#12241's art specification, the operator's choices of 2026-10-23: *"I choose the bird carrying the
 * envelope"*; the singing bird and one ring of notes; *"the birdhouse on a bracket … to the right of the
 * clock"*).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DRAWS AND DECIDES NOTHING. Which edge fires, from which desk to which, and for how many frames
 * is `floor/scene.js`'s and § 6.2's; this module only answers *what does the bird look like* at a given
 * frame. Every shape is a `[tag, attributes]` pair in the bird's own coordinates — the body's centre at the
 * origin, facing +x — so the painter places, flips and animates it and nothing here knows the floor.
 *
 * ⛔ IT DRAWS NO TEXT AND NO HELD LINE (§ 5.7): a bird, an envelope, a ring of notes and a birdhouse, and
 * nothing that outlives a message.
 *
 * The palette is the art specification's table, one export here; the sizes are its numbers: a bird body
 * 18 × 14.4 px, an envelope 14 × 10 px with a red seal, the ring's sixteen notes.
 */

/** card#12241's palette, in the specification's own table order. */
export const PALETTE = Object.freeze({
    bird_body: '#6f9ac8',
    bird_outline: '#263d5e',
    bird_belly: '#fff6e6',
    beak: '#e0a43a',
    blush: '#f2b8c6',
    envelope: '#fffaf0',
    envelope_outline: '#3a2a20',
    seal: '#c8553d',
    notes: '#6e2c44',
    rim: '#fff6e6',
    portal_wood: '#b07a4f',
    portal_outline: '#5a3a1e',
    portal_hole: '#3d2b3c',
    portal_cream: '#fff6e6',
    portal_edge: '#8a6f5a',
    bracket: '#a8714a',
});

const P = PALETTE;

/** The bird drawn this many px above the point of its flight (the art specification's item 3). */
export const BIRD_LIFT = 14;

/** The arc's lift at its midpoint: `min(60, 0.12 × distance)` px. */
export function arcLift(distance) {
    return Math.min(60, 0.12 * distance);
}

/** A quadratic arc from `a` to `b`, its control point lifted over the midpoint — an SVG path. */
export function arcPath(a, b) {
    const lift = arcLift(Math.hypot(b.x - a.x, b.y - a.y));

    return `M ${a.x} ${a.y} Q ${(a.x + b.x) / 2} ${(a.y + b.y) / 2 - 2 * lift} ${b.x} ${b.y}`;
}

/**
 * The bird, facing +x, its body centred on the origin. `wing` is `up` or `down` — the one alternation;
 * `beak` is `open` for the singing perch.
 *
 * @returns {list<[string, object]>}
 */
export function birdShapes(wing = 'down', beak = 'closed') {
    const wingPath = wing === 'up'
        ? 'M -2 -2 C -6 -12 -14 -12 -12 -4 C -9 -2 -5 -1 -2 -2 Z'
        : 'M -2 0 C -6 6 -13 7 -12 2 C -9 0 -5 -1 -2 0 Z';
    const beakPath = beak === 'open'
        ? 'M 8.5 -2 L 13 -3.5 L 9 -0.6 Z M 8.5 -0.4 L 12.4 1.6 L 8.6 0.8 Z'
        : 'M 8.5 -2 L 13 -0.6 L 8.5 0.8 Z';

    return [
        // the tail, behind the body
        ['path', { d: 'M -8 -1 L -14 -5 L -13 1 Z', fill: P.bird_body, stroke: P.bird_outline, 'stroke-width': 1 }],
        // the rim outside the outline (the creatures' rim rule), then the body
        ['ellipse', { cx: 0, cy: 0, rx: 10, ry: 8.2, fill: 'none', stroke: P.rim, 'stroke-width': 2.4, opacity: 0.8 }],
        ['ellipse', { cx: 0, cy: 0, rx: 9, ry: 7.2, fill: P.bird_body, stroke: P.bird_outline, 'stroke-width': 1.2 }],
        ['ellipse', { cx: 2, cy: 2.6, rx: 5.4, ry: 3.8, fill: P.bird_belly }],
        ['path', { d: beakPath, fill: P.beak, stroke: P.bird_outline, 'stroke-width': 0.6 }],
        ['circle', { cx: 4.6, cy: -2.4, r: 1.5, fill: P.bird_outline }],
        ['circle', { cx: 5.1, cy: -2.9, r: 0.5, fill: P.rim }],
        ['circle', { cx: 5.4, cy: 0.6, r: 1.3, fill: P.blush, opacity: 0.9 }],
        ['path', { d: wingPath, fill: P.bird_body, stroke: P.bird_outline, 'stroke-width': 1 }],
    ];
}

/** The envelope, 14 × 10, centred on the origin: a V flap and a red seal, kept at every size. */
export function envelopeShapes() {
    return [
        ['rect', { x: -7, y: -5, width: 14, height: 10, rx: 1, fill: P.envelope, stroke: P.envelope_outline, 'stroke-width': 1 }],
        ['path', { d: 'M -7 -5 L 0 1 L 7 -5', fill: 'none', stroke: P.envelope_outline, 'stroke-width': 0.9 }],
        ['circle', { cx: 0, cy: 1, r: 1.6, fill: P.seal }],
    ];
}

/** The bird's two short legs, holding the envelope under it. */
export function legShapes() {
    return [
        ['path', { d: 'M -2 6.5 L -2 9 M 2 6.5 L 2 9', stroke: P.bird_outline, 'stroke-width': 1, fill: 'none' }],
    ];
}

/** One eighth-note at the origin, with its cream rim. */
function note() {
    return [
        ['ellipse', { cx: 0, cy: 0, rx: 2.4, ry: 1.8, fill: P.notes, stroke: P.rim, 'stroke-width': 0.8 }],
        ['path', { d: 'M 2.1 -0.4 L 2.1 -7 L 5 -5', fill: 'none', stroke: P.notes, 'stroke-width': 1 }],
    ];
}

/**
 * The broadcast's ring — a dotted circle of `radius` with sixteen notes on it — centred on the origin.
 * The painter scales the group from 0 to 1 over the ring's reach frames, as the ring always was.
 */
export function ringShapes(radius) {
    const shapes = [['circle', { cx: 0, cy: 0, r: radius, fill: 'none', stroke: P.notes, 'stroke-width': 2, 'stroke-dasharray': '2 6' }]];

    for (let i = 0; i < 16; i++) {
        const a = (i / 16) * 2 * Math.PI;

        shapes.push(['g', { transform: `translate(${radius * Math.cos(a)} ${radius * Math.sin(a)})` }, note()]);
    }

    return shapes;
}

/** The three still notes beside a perched bird — the broadcast's reduced-motion form. */
export function stillNotes() {
    return [6, 12, 18].map((dx, i) => ['g', { transform: `translate(${dx + 6} ${-10 - 4 * i})` }, note()]);
}

/**
 * THE BIRDHOUSE, at the band's `portal` (`floor/scene.js`'s `birdPortal()`): a gabled house, its body cream
 * edged brown, a wooden roof with a dark eave line, a round hole painted with the windows' own sky (A17's
 * paint, so it never shows a second time of day), a peg perch under the hole and a bracket under the body.
 * Still: it has no idle motion.
 *
 * @param {object} portal the scene's portal geometry
 * @param {string|null} sky the windows' sky phase, or null when the room has no value (§ 6.5)
 */
export function birdhouseShapes(portal, sky) {
    const { roof, body, bracket, mouth } = portal;
    const ridge = `M ${roof.x} ${roof.eave_y} L ${portal.x} ${roof.peak_y} L ${roof.x + roof.w} ${roof.eave_y} Z`;

    return [
        ['rect', { x: bracket.x, y: bracket.y, width: bracket.w, height: bracket.h, rx: 1, fill: P.bracket, stroke: P.portal_outline, 'stroke-width': 1 }],
        ['path', { d: `M ${bracket.x + 6} ${bracket.y} L ${bracket.x + 6} ${body.y + body.h} M ${bracket.x + bracket.w - 6} ${bracket.y} L ${bracket.x + bracket.w - 6} ${body.y + body.h}`, stroke: P.portal_outline, 'stroke-width': 1 }],
        ['rect', { x: body.x, y: body.y, width: body.w, height: body.h, rx: 2, fill: P.portal_cream, stroke: P.portal_edge, 'stroke-width': 1.2 }],
        ['path', { d: ridge, fill: P.portal_wood, stroke: P.portal_outline, 'stroke-width': 1.4, 'stroke-linejoin': 'round' }],
        ['path', { d: `M ${roof.x + 3} ${roof.eave_y + 1} L ${roof.x + roof.w - 3} ${roof.eave_y + 1}`, stroke: P.portal_outline, 'stroke-width': 1.2 }],
        ['circle', { cx: mouth.x, cy: mouth.y, r: mouth.r, class: `portal-hole sky-${sky ?? 'unset'}`, stroke: P.portal_outline, 'stroke-width': 1.2 }],
        ['path', { d: `M ${mouth.x} ${mouth.y + mouth.r + 3} L ${mouth.x} ${mouth.y + mouth.r + 8}`, stroke: P.portal_outline, 'stroke-width': 2, 'stroke-linecap': 'round' }],
    ];
}
