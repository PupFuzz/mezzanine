/**
 * The probe `Tests\Feature\Floor\TheFloorIsTheScenesThirdRendererTest` reads ONE CHARACTER IN BOTH VIEWS through: every
 * body the 2-D painter draws and every body the 3-D floor builds, over the same scenes, is the same character — the same
 * key handed to the character tree, drawn as the same species, so the same colourway, face, girth, size, tilt, worn
 * things and, for an intern, laptop. argv[2] is the client's `wire/` directory (the shipped one or a MUTATED COPY of the
 * tree); stdin carries `{ "scenes": [ { "run", "i", "scene" } ], "tree"? }` — `tree` the character tree's directory,
 * `resources/characters` by default or a mutated copy — and stdout is `{ "legs": { "<leg>": [defect, …] }, "printed": { … } }`.
 *
 * ⛔ BOTH SIDES ARE THE SHIPPED RENDERERS, NOT A RESTATEMENT OF THEM. The 2-D side is `floor/painter.js`'s `paint()` over a
 * fake DOM; the 3-D side is `floor/floor-world.js`'s `floorWorld()` handed to `floor3d/body-paint.js`'s `floorBodies()`,
 * which builds each body through `floor3d/creature-paint.js` (Three resolved by a module hook; the build stops at its
 * first canvas, after it has asked the tree for the body). Each is handed the REAL character tree behind a recorder, which
 * notes every call that names a body — its entry, its key and the species beside it — and answers with the real drawing.
 *
 * ⛔ WHAT A BODY IS DRAWN AS IS READ OFF THE TREE's ANSWER, NEVER OFF ITS RULES. A seat's entries take the species they
 * draw (the tree's forced-species path, `recipe(key, force)`), and a 3-D part list names its own `species`. An intern's
 * 2-D entries take its SEAT's species, which the tree keeps the intern off; the species the intern was drawn as is found
 * here by matching the document the entry answered against `frameDocument()`'s for each species in turn — so this probe
 * holds no copy of the intern rule (`internSpeciesOf()`), and a tree that changed the rule is read as drawn. Every field
 * of the identity is then the tree's `recipe()` and `laptopOf()` for that key and species.
 *
 * Legs: `fields` — a key whose 2-D and 3-D identities differ, field by field; `kind` — a key drawn as an intern in one view
 * and a seat in the other; `views` — one view drawing one key as two characters; `coverage` — a key with a body in one
 * view and none in the other; `match` — a 2-D intern drawing that is no species' drawing (the reading failed, not the
 * floor); `parts` — a 3-D face or paint asked for under another key or species than its body's.
 *
 * ⚠ WHAT IT DOES NOT SEE: pixels, the 3-D face's expression (a 3-D body is built once per key, in its standing face), and
 * where in its walk a body is shown (`walkerAt()`'s, read by the world half). A body neither view draws is outside it.
 */

import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Node } from './fake-svg-node.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const wire = process.argv[2];
const root = join(wire, '..');
const mod = (path) => import(pathToFileURL(join(root, path)).href);

// The vendored Three's own examples import it as the bare `three` (the page's import map); here, the same file.
const THREE = pathToFileURL(join(root, 'vendor', 'three', 'three.module.js')).href;

registerHooks({ resolve: (specifier, context, next) => (specifier === 'three' ? { url: THREE, shortCircuit: true } : next(specifier, context)) });

const { scenes, tree = join(HERE, '..', '..', '..', '..', 'resources', 'characters') } = JSON.parse(readFileSync(0, 'utf8'));
const C = await import(pathToFileURL(join(tree, 'index.js')).href);

// ---- the fake page both renderers draw on ----
const host = new Node('div');

Object.defineProperty(globalThis, 'performance', { value: { now: () => 0 }, configurable: true });
globalThis.document = {
    activeElement: null,
    timeline: { currentTime: 0 },
    getElementById: (id) => (id === 'floor-drawing' ? host : null),
    createElementNS: (ns, name) => new Node(name),
    // the painter's text measurer; the 3-D build's first canvas is where it stops
    createElement: (name) => (name === 'canvas' ? NO_CANVAS() : { getContext: () => ({ measureText: (t) => ({ width: String(t).length }) }) }),
};
globalThis.CSS = { escape: (s) => s };
globalThis.setInterval = () => 0;
globalThis.clearInterval = () => {};

let canvasOk = true;
function NO_CANVAS() {
    if (canvasOk) {
        return { getContext: () => ({ measureText: (t) => ({ width: String(t).length }) }) };
    }

    throw new Error('the probe has no canvas: the body was asked for, and nothing is drawn');
}

const { createPainter } = await mod('floor/painter.js');
const { floorWorld } = await mod('floor/floor-world.js');
const { floorBodies } = await mod('floor3d/body-paint.js');
const { Group } = await import(THREE);

// ---- the recorder: the real tree, with every call that names a body noted ----
const SEAT_ENTRIES = ['standingFrame', 'walkFrames', 'poseFrames', 'sleepFrames', 'bodyParts'];
const INTERN_ENTRIES = ['chibiFrame', 'internFrames', 'chibiParts'];
const PART_ENTRIES = ['faceDocument', 'paintDocument', 'lidDocument'];

function recorder(log) {
    return new Proxy(C, {
        get(target, name) {
            const real = target[name];

            if (typeof real !== 'function' || ![...SEAT_ENTRIES, ...INTERN_ENTRIES, ...PART_ENTRIES].includes(name)) {
                return real;
            }

            return (...args) => {
                const answer = real(...args);

                log.push({ entry: name, args, answer });

                return answer;
            };
        },
    });
}

// ---- what a recorded call drew ----
const FIELDS = ['species', 'variant', 'pal', 'stout', 'size', 'tilt', 'eyes', 'mouth', 'blush', 'brows', 'hatDraw', 'neckDraw', 'extra', 'acc1', 'acc2'];

function identity(key, species, intern) {
    const r = C.recipe(key, species);
    const id = { intern };

    for (const f of FIELDS) {
        id[f] = r[f] ?? null;
    }

    id.laptop = intern ? C.laptopOf(key) : null;

    return id;
}

const matched = new Map();

/** The species a 2-D intern document is the drawing of: the one whose `frameDocument()` it is, or `null`. */
function internSpeciesDrawn(key, doc, laptop) {
    const memo = `${key}\u0000${laptop}\u0000${doc.length}\u0000${doc}`;

    if (!matched.has(memo)) {
        matched.set(memo, C.SPECIES_KEYS.find((s) => C.frameDocument(key, { chibi: true, laptop, species: s }) === doc) ?? null);
    }

    return matched.get(memo);
}

/** A 2-D call's body: its key, intern or not, and the species it was drawn as — or why it could not be read. */
function drawn2d({ entry, args, answer }) {
    const key = args[0];

    if (entry === 'chibiFrame' || entry === 'internFrames') {
        const doc = entry === 'chibiFrame' ? answer : answer.frames[0];
        const species = internSpeciesDrawn(key, doc, entry === 'internFrames');

        return species === null ? { key, unread: `${entry}(${key}, ${args[1]}) is no species' drawing` } : { key, intern: true, species };
    }

    const species = entry === 'poseFrames' ? args[1].species : args[entry === 'sleepFrames' ? 3 : 1];

    return { key, intern: false, species };
}

const legs = { fields: [], kind: [], views: [], coverage: [], match: [], parts: [] };
const printed = { scenes: scenes.length, bodies: 0, compared: 0, routes: {} };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

for (const { run, i, scene } of scenes) {
    const where = `[${run} #${i}]`;

    // ---- 2-D: the painter over the scene ----
    const log2d = [];

    host.children = [];
    canvasOk = true;
    createPainter({ characters: recorder(log2d), themes: {}, failed: () => {}, select: () => {}, clock: { now: () => 0 } })
        .paint(scene, { bounds: null, view: null });

    const views2d = new Map();

    for (const call of log2d) {
        const d = drawn2d(call);

        if (d.unread !== undefined) {
            legs.match.push(`${where} ${d.unread}`);
            continue;
        }

        const id = identity(d.key, d.species, d.intern);
        const held = views2d.get(d.key);

        if (held !== undefined && !same(held.id, id)) {
            legs.views.push(`${where} ${d.key}: the 2-D view draws it as two characters (${call.entry}: ${d.species}, ${held.entry}: ${held.id.species})`);
        }

        views2d.set(d.key, held ?? { id, entry: call.entry });
    }

    // ---- 3-D: the world, and the bodies built over it ----
    const log3d = [];
    const world = floorWorld(scene);

    canvasOk = false;
    const bodies = floorBodies({
        document: globalThis.document,
        creatures: new Group(),
        room: new Group(),
        marks: new Group(),
        wake: () => {},
        art: () => ({ characters: recorder(log3d) }),
        failed: () => {},
        now: () => 0,
    });

    bodies.paint(world, 0);
    // the builds stop at their first canvas; let their rejections settle
    await new Promise((resolve) => setImmediate(resolve));

    const views3d = new Map();

    for (const call of log3d.filter((c) => c.entry === 'bodyParts' || c.entry === 'chibiParts')) {
        const key = call.args[0];
        const body = call.answer;
        const id = identity(key, body.species, body.chibi);

        if (views3d.has(key) && !same(views3d.get(key), id)) {
            legs.views.push(`${where} ${key}: the 3-D view builds it as two characters`);
        }

        views3d.set(key, id);

        for (const part of log3d.filter((c) => PART_ENTRIES.includes(c.entry) && c.args[0] === key)) {
            const species = part.args[1]?.species;

            if (part.entry !== 'lidDocument' && species !== body.species) {
                legs.parts.push(`${where} ${key}: its 3-D ${part.entry} is drawn as ${species}, its body as ${body.species}`);
            }
        }
    }

    for (const part of log3d.filter((c) => PART_ENTRIES.includes(c.entry) && !views3d.has(c.args[0]))) {
        legs.parts.push(`${where} ${part.args[0]}: a 3-D ${part.entry} for a key with no body`);
    }

    // the population by route — each body the world stands, as `floorBodies()` reads it: a sitter, an intern, a walker
    const count = (r) => { printed.routes[r] = (printed.routes[r] ?? 0) + 1; };

    for (const d of world.desks) {
        if (d.sitter !== null && d.sitter.pose !== 'empty-chair') {
            count('desk');
        }

        d.interns.forEach(() => count('stool'));
    }

    for (const w of world.walkers) {
        count(`${w.animation_id}${w.chibi ? ' intern' : ''}`);
    }

    // ---- the two views, key by key ----
    for (const key of new Set([...views2d.keys(), ...views3d.keys()])) {
        const a = views2d.get(key)?.id ?? null;
        const b = views3d.get(key) ?? null;

        printed.bodies++;

        if (a === null || b === null) {
            legs.coverage.push(`${where} ${key}: a body in the ${a === null ? '3-D' : '2-D'} view only`);
            continue;
        }

        printed.compared++;

        if (a.intern !== b.intern) {
            legs.kind.push(`${where} ${key}: 2-D draws it as ${a.intern ? 'an intern' : 'a seat'}, 3-D as ${b.intern ? 'an intern' : 'a seat'}`);
            continue;
        }

        for (const f of [...FIELDS, 'laptop']) {
            if (!same(a[f], b[f])) {
                legs.fields.push(`${where} ${key}: ${f} — 2-D ${JSON.stringify(a[f])}, 3-D ${JSON.stringify(b[f])}`);
            }
        }
    }
}

process.stdout.write(JSON.stringify({ legs, printed }));
