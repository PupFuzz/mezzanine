#!/usr/bin/env node
// Render a contact sheet of seat characters to a PNG, so a human can LOOK at the output.
//
//   node tools/characters/render-sheet.mjs [out.png] [scale]
//
// Defaults to `/tmp/mezzanine-characters.png` at 6x. It deliberately does NOT default anywhere
// inside the repository: the character tree admits `.ts`/`.js`/`.md` only, and a PNG committed
// beside the generator is exactly what Gate 2 exists to refuse.
//
// This is the NODE-side check — it proves what the generator produces. It is not a substitute
// for `harness.html`, which is what proves the browser can draw it; the two verify different
// things and neither one covers the other.

import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
import { portraitBuf, sceneFrames, PORTRAIT_W, PORTRAIT_H, SCENE_W, SCENE_H } from '../../resources/characters/index.js';

const OUT = process.argv[2] ?? '/tmp/mezzanine-characters.png';
const SCALE = Number(process.argv[3] ?? 6);

// The four real aimla seats (FLOOR.md section 3.2's worked table) followed by synthetic ones,
// so the sheet shows both what the fleet will actually look like and a spread of the palette.
/** @type {[string,string][]} */
const SEATS = [
  ['aimla', 'aimla-pm'], ['aimla', 'aimla-impl-1'], ['aimla', 'aimla-impl-2'], ['aimla', 'aimla-review'],
];
for (let i = 0; i < 20; i++) SEATS.push(['demo', `seat-${i}`]);

const COLS = 12;
const CELL_W = SCENE_W + 2;
// portrait, then the front walk frame, then the back one — the back view is what a seated
// agent shows the camera, so it needs looking at as much as the face does.
const CELL_H = PORTRAIT_H + SCENE_H * 2 + 4;
const ROWS = Math.ceil(SEATS.length / COLS);
const W = COLS * CELL_W * SCALE;
const H = ROWS * CELL_H * SCALE;

// checkerboard so transparent pixels are visibly transparent rather than "black"
const page = new Uint8Array(W * H * 4);
for (let y = 0; y < H; y++) {
  for (let x = 0; x < W; x++) {
    const c = ((x >> 3) + (y >> 3)) % 2 ? 210 : 235;
    const i = (y * W + x) * 4;
    page[i] = c; page[i + 1] = c; page[i + 2] = c; page[i + 3] = 255;
  }
}

/** @param {Uint8ClampedArray} buf @param {number} bw @param {number} bh @param {number} ox @param {number} oy */
function blit(buf, bw, bh, ox, oy) {
  for (let y = 0; y < bh; y++) {
    for (let x = 0; x < bw; x++) {
      const s = (y * bw + x) * 4;
      const a = buf[s + 3];
      if (a === 0) continue;
      for (let dy = 0; dy < SCALE; dy++) {
        for (let dx = 0; dx < SCALE; dx++) {
          const px = (ox + x) * SCALE + dx, py = (oy + y) * SCALE + dy;
          if (px < 0 || px >= W || py < 0 || py >= H) continue;
          const d = (py * W + px) * 4;
          const k = a / 255;
          page[d] = buf[s] * k + page[d] * (1 - k);
          page[d + 1] = buf[s + 1] * k + page[d + 1] * (1 - k);
          page[d + 2] = buf[s + 2] * k + page[d + 2] * (1 - k);
          page[d + 3] = 255;
        }
      }
    }
  }
}

SEATS.forEach(([inst, seat], i) => {
  const cx = (i % COLS) * CELL_W + 1;
  const cy = Math.floor(i / COLS) * CELL_H + 1;
  const f = sceneFrames(inst, seat);
  blit(portraitBuf(inst, seat), PORTRAIT_W, PORTRAIT_H, cx, cy);
  blit(f.front[1], SCENE_W, SCENE_H, cx, cy + PORTRAIT_H + 1);
  blit(f.back[2], SCENE_W, SCENE_H, cx, cy + PORTRAIT_H + SCENE_H + 2);
});

// --- minimal PNG writer (RGBA8, filter 0) --------------------------------------------------
const CRC_TABLE = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();
/** @param {Buffer} buf */
function crc32(buf) {
  let c = -1;
  for (const b of buf) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}
/** @param {string} type @param {Buffer} data */
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}
const ihdr = Buffer.alloc(13);
ihdr.writeUInt32BE(W, 0); ihdr.writeUInt32BE(H, 4);
ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
const raw = Buffer.alloc(H * (W * 4 + 1));
for (let y = 0; y < H; y++) {
  raw[y * (W * 4 + 1)] = 0;
  Buffer.from(page.buffer, y * W * 4, W * 4).copy(raw, y * (W * 4 + 1) + 1);
}
writeFileSync(OUT, Buffer.concat([
  Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  chunk('IHDR', ihdr),
  chunk('IDAT', deflateSync(raw, { level: 9 })),
  chunk('IEND', Buffer.alloc(0)),
]));
console.log(`wrote ${OUT} — ${W}x${H}, ${SEATS.length} seats at ${SCALE}x (portrait over scene frame)`);
