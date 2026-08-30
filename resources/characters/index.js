// The character tree's public surface. Everything a consumer needs is here; nothing else in
// this directory should be imported directly by app code.
//
// Two layers under this one: `portrait-art.js` is the ported pixel generator (see `LINEAGE.md`)
// and `seed.js` turns a seat's identity into one of its recipes. This file adds only the two
// things neither of them should own — a cache keyed on the seat, and the canvas blit.

import { composePortrait, composeScene, PORTRAIT_W, PORTRAIT_H, SCENE_W, SCENE_H } from './portrait-art.js';
import { characterFor, seatKey } from './seed.js';

export { characterFor, seatKey, fnv1a32, draw } from './seed.js';
export { PORTRAIT_W, PORTRAIT_H, SCENE_W, SCENE_H } from './portrait-art.js';

/** @typedef {import('./portrait-art.js').Recipe} Recipe */
/** @typedef {Uint8ClampedArray} Buf */
/** @typedef {{ front: Buf[], back: Buf[] }} SceneFrames */

// Composition is a few thousand `set()` calls, so it is cached per seat rather than per paint —
// a floor repaints its desks far more often than the fleet gains a seat. The key space is the
// rendered seat set, which the feed already bounds, so there is nothing to evict.
/** @type {Map<string, Buf>} */
const portraitCache = new Map();
/** @type {Map<string, SceneFrames>} */
const sceneCache = new Map();

/**
 * The seat's portrait as raw RGBA, `PORTRAIT_W * PORTRAIT_H * 4` bytes. No DOM needed — this is
 * the layer a test or a server-side renderer reads.
 * @param {string} installId @param {string} seatId @returns {Buf}
 */
export function portraitBuf(installId, seatId) {
  const key = seatKey(installId, seatId);
  let buf = portraitCache.get(key);
  if (!buf) {
    buf = composePortrait(characterFor(installId, seatId));
    portraitCache.set(key, buf);
  }
  return buf;
}

/**
 * The seat's in-scene walk frames — stand, step-left, step-right — front and back, as raw RGBA
 * at `SCENE_W * SCENE_H`. This is the ONLY animation source for a character: the floor derives
 * its walk cycle from these frames, never from a vendored sprite sheet.
 * @param {string} installId @param {string} seatId @returns {SceneFrames}
 */
export function sceneFrames(installId, seatId) {
  const key = seatKey(installId, seatId);
  let frames = sceneCache.get(key);
  if (!frames) {
    const r = characterFor(installId, seatId);
    frames = {
      front: [composeScene(r, 0, false), composeScene(r, 1, false), composeScene(r, 2, false)],
      back: [composeScene(r, 0, true), composeScene(r, 1, true), composeScene(r, 2, true)],
    };
    sceneCache.set(key, frames);
  }
  return frames;
}

/**
 * Stage an RGBA buffer on a 1x offscreen canvas. Needs a DOM.
 * @param {Buf} buf @param {number} w @param {number} h @returns {HTMLCanvasElement}
 */
function stage(buf, w, h) {
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const sctx = /** @type {CanvasRenderingContext2D} */ (canvas.getContext('2d'));
  const img = sctx.createImageData(w, h);
  img.data.set(buf);
  sctx.putImageData(img, 0, 0);
  return canvas;
}

/**
 * Blit a buffer onto `ctx`'s canvas at `scale`, nearest-neighbour.
 *
 * THE PAINTER SIZES THE CANVAS. It does not accept one and hope the caller sized it to match,
 * because a canvas sized at one scale and painted at another crops the sprite to a corner —
 * and a cropped pixel character still looks like a pixel character, so nobody notices. Taking
 * the destination's geometry away from the caller is what makes that unrepresentable rather
 * than merely unlikely; these entry points already owned the whole canvas (upstream cleared it
 * outright), so this only finishes an assumption that was already being made.
 *
 * Smoothing OFF is the other half: at 18 px wide, bilinear scaling turns a face into a smudge.
 *
 * @param {CanvasRenderingContext2D} ctx @param {Buf} buf @param {number} w @param {number} h
 * @param {number} scale integer >= 1
 */
function blit(ctx, buf, w, h, scale) {
  if (!Number.isInteger(scale) || scale < 1) throw new RangeError(`scale must be a positive integer, got ${scale}`);
  // Assigning width/height resizes AND clears AND resets 2D context state — so smoothing has to
  // be turned off after it, not before.
  ctx.canvas.width = w * scale;
  ctx.canvas.height = h * scale;
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(stage(buf, w, h), 0, 0, w, h, 0, 0, w * scale, h * scale);
}

/**
 * Paint a seat's portrait, RESIZING `ctx`'s canvas to `PORTRAIT_W x PORTRAIT_H` at `scale`.
 * @param {CanvasRenderingContext2D} ctx @param {string} installId @param {string} seatId
 * @param {number} [scale]
 */
export function paintPortrait(ctx, installId, seatId, scale = 2) {
  blit(ctx, portraitBuf(installId, seatId), PORTRAIT_W, PORTRAIT_H, scale);
}

/**
 * Paint one in-scene frame of a seat, RESIZING `ctx`'s canvas to `SCENE_W x SCENE_H` at `scale`.
 * @param {CanvasRenderingContext2D} ctx @param {string} installId @param {string} seatId
 * @param {{ phase?: 0|1|2, back?: boolean, scale?: number }} [opts]
 */
export function paintSceneFrame(ctx, installId, seatId, opts = {}) {
  const { phase = 0, back = false, scale = 2 } = opts;
  const frames = sceneFrames(installId, seatId);
  const buf = (back ? frames.back : frames.front)[phase];
  blit(ctx, buf, SCENE_W, SCENE_H, scale);
}
