#!/usr/bin/env node
// THE GENERATOR's DOCUMENTS, DIGESTED PER SPECIES — the control a behaviour-preserving change to `resources/characters/`
// runs before and after itself (card#13033 stage 2 slice 3: the species `shape` data hoisted out of each `build()`,
// docs/design/plan-13033-stage2-3d-floor.md § 3.3 and § 8 item 1). Node, no dependencies, no network.
//
//   node tools/characters/digest.mjs --write before.json        # on the tree before the change
//   node tools/characters/digest.mjs --against before.json      # on the tree after it: a red names each species that moved
//
// For every species, a corpus of keys (`digest/<species>/<n>`, enough of them that every variant, colourway, hat,
// neckwear and extra the species can draw is drawn), and for each key every document the tree draws for it: the
// standing frame and the walk's two steps, every expression (a marked one over the floor's clear rects), the chibi
// frame, the intern's laptop still and its sway, every sleeper document over the floor's desk edge, plus `describe()`
// and `chibiGeometry()`. Equal bytes are equal pixels: a digest equal per species is the sheet pixel-equal per species,
// over more frames than the sheet shows. It stores nothing in the repository — the digest file is the caller's.

import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..');
const C = await import(pathToFileURL(join(REPO, 'resources', 'characters', 'index.js')).href);
const { characterOverdraw, deskRects } = await import(pathToFileURL(join(REPO, 'server', 'public', 'js', 'floor', 'desk-layout.js')).href);
const { FURNITURE_BOX } = await import(pathToFileURL(join(REPO, 'resources', 'floor', 'furniture-box.js')).href);

const argv = process.argv.slice(2);
const argOf = (flag) => { const i = argv.indexOf(flag); return i >= 0 ? argv[i + 1] : null; };
const write = argOf('--write'), against = argOf('--against');
// `--only a,b` digests those species alone — a quicker read while a change is in progress; a whole-tree verdict needs none
const only = argOf('--only')?.split(',') ?? null;
if ((write === null) === (against === null)) { console.error('usage: digest.mjs --write <file> | --against <file>'); process.exit(2); }

/** Keys a species: enough that every variant, colourway and worn thing the species can draw is drawn (read below). */
const KEYS = 40;
const { clear } = characterOverdraw(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
const rects = deskRects(FURNITURE_BOX, { w: C.SCENE_W, h: C.SCENE_H });
const deskTop = (rects['desk-sprite'].y - rects.character.y) / rects.character.h;

function documentsOf(sp, key) {
  const out = [];
  for (const phase of [0, 1, 2]) out.push(C.frameDocument(key, { species: sp, phase }));
  for (const expression of Object.keys(C.EXPRESSIONS)) out.push(C.frameDocument(key, { species: sp, expression, clear }));
  out.push(C.frameDocument(key, { species: sp, chibi: true }));
  out.push(C.frameDocument(key, { species: sp, chibi: true, laptop: true }));
  for (let sway = 0; sway < C.INTERN_LOOP; sway++) out.push(C.frameDocument(key, { species: sp, chibi: true, laptop: true, sway }));
  for (let frame = 0; frame <= C.SLEEP_LOOP; frame++) out.push(C.sleepDocument(key, { species: sp, frame, deskTop, clear }));
  out.push(JSON.stringify(C.describe(key, sp)), JSON.stringify(C.chibiGeometry(key, sp)));
  return out;
}

const digests = {}, reach = {};
for (const sp of C.SPECIES_KEYS.filter((k) => only === null || only.includes(k))) {
  const h = createHash('sha256');
  const seen = { variant: new Set(), pal: new Set(), hat: new Set(), neck: new Set(), extra: new Set() };
  for (let n = 0; n < KEYS; n++) {
    const key = `digest/${sp}/${n}`;
    for (const doc of documentsOf(sp, key)) h.update(doc).update('\0');
    const d = C.describe(key, sp);
    for (const f of Object.keys(seen)) seen[f].add(d[f]);
  }
  digests[sp] = h.digest('hex');
  reach[sp] = Object.fromEntries(Object.entries(seen).map(([f, s]) => [f, s.size]));
}

// The corpus's reach, printed so a reader sees what the digest covered: per species, how many of each closed list's
// members its keys drew (a species' hat list after WITHHELD, plus 'none', is the ceiling for the hat column).
const short = Object.entries(reach).filter(([sp, r]) => r.pal < C.SPECIES[sp].pals.length).map(([sp]) => sp);
console.log(`${Object.keys(digests).length} species × ${KEYS} keys digested; species whose keys missed a colourway: ${short.join(', ') || 'none'}`);

if (write !== null) {
  writeFileSync(write, `${JSON.stringify({ keys: KEYS, digests }, null, 1)}\n`);
  console.log(`wrote ${write}`);
  process.exit(0);
}
const before = JSON.parse(readFileSync(against, 'utf8'));
const moved = Object.keys(digests).filter((sp) => before.digests[sp] !== digests[sp]);
const gone = only === null ? Object.keys(before.digests).filter((sp) => !(sp in digests)) : [];
for (const sp of moved) console.log(`  FAIL ${sp}: its documents differ from ${against}`);
for (const sp of gone) console.log(`  FAIL ${sp}: digested before, absent now`);
console.log(moved.length + gone.length === 0 ? `ALL ${Object.keys(digests).length} SPECIES' DOCUMENTS ARE BYTE-EQUAL TO ${against}` : `${moved.length + gone.length} SPECIES DIFFER`);
process.exit(moved.length + gone.length === 0 ? 0 : 1);
