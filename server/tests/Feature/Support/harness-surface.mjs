/**
 * The drawing surface a probe hands a screen when its run states none — the floor's
 * (`../Floor/fleet-client-probe.mjs`, `../Floor/coord-cap-probe.mjs`) and the lobby's
 * (`../Lobby/lobby-probe.mjs`, `../Floor/fleet-client-probe.mjs`'s building runs).
 *
 * ⛔ A HARNESS CHOICE AND NOT A RULE OF THE PRODUCT. The floor draws at every surface size (FLOOR.md
 * § 4.5, the operator's ruling of 2026-10-01 on card#7341), so nothing in `public/js` holds a size like
 * this one. It is the size every run written before that ruling was drawn at — the figure
 * `floor-screen.js` exported as `VIEWPORT_FLOOR` until then — kept so those runs' recorded cameras,
 * scenes and label geometry stay what they were. A run about a size states its own `viewport`
 * (`fixtures/fx-camera.json`'s small-window runs do).
 */
export const HARNESS_SURFACE = Object.freeze({ width: 1280, height: 800 });
