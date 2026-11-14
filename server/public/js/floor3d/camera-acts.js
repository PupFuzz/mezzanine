/**
 * THE 3-D FLOOR's CAMERA ACTS — what the house keys wire (`wire/camera-keys.js`) is handed on the 3-D floor: the renderer
 * contract's `keys.acts` (`floor/floor-page.js`'s header), each a call on the controls' public methods. No DOM and no
 * Three: the controls are injected (`OrbitControls` on the page, slice 4; a stub recording its calls under `node`,
 * `tests/Feature/Floor/camera-acts-probe.mjs`). `docs/design/plan-13033-stage2-3d-floor.md` § 3.6 and § 12 item 3
 * (card#13033 stage 2 slice 2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE KEYBOARD MAP (the seat's ruling, plan § 12 item 3, as `/building`'s arrows turn its view): an arrow turns —
 * `←`/`→` the azimuth, `↑`/`↓` the tilt, within the limits the controls hold — by the turn a drag of `PAN_STEP_PX`
 * across the stage makes (`wire/camera.js`'s `turnOf()`, the building view's rate); Shift + an arrow pans the target
 * along the floor by the same step; `+`/`−` and the zoom buttons dolly by the house notch (`ZOOM_STEP`); *Fit the floor*
 * sets the entry pose; Page Up / Page Down raise and lower the eye (`rise()`) by the same step, as Alt + a vertical drag
 * does (`floor-paint.js` hands the drag here). The keys wire hands each arrow as a drag of the scene (`dx`, `dy`) with its
 * modifiers, so the view moves the way the arrow points, as on every page. Each act ends in the controls' own update
 * (every public method of theirs updates) and the renderer's target clamp, and answers the camera the wire gates on.
 *
 * ⛔ RISING MOVES THE TARGET AND THE EYE BY ONE VECTOR, straight up or down, so the view's direction and distance are
 * kept; the clamp holds the target's `y` within its box (0 to `ROOM_H_U`) and moves the eye back with it, and the polar
 * limit keeps the eye above the target. A drag of `dy` px rises `dy` px of the scene at the target's distance — the
 * rate `OrbitControls` pans at (its `_pan()`), so the room follows the pointer when the eye looks level.
 */

import { ZOOM_STEP, turnOf } from '../wire/camera.js';

/** What the 3-D floor's stage names in `aria-keyshortcuts` while it is offered — every key above moves something. */
export const SHORTCUTS = '+ - ArrowLeft ArrowRight ArrowUp ArrowDown Shift+ArrowLeft Shift+ArrowRight Shift+ArrowUp Shift+ArrowDown PageUp PageDown';

/**
 * @param {object} controls `rotateLeft(angle)`, `rotateUp(angle)`, `pan(dx, dy)`, `dollyIn(scale)`, `dollyOut(scale)`,
 *        `getDistance()`, `target` and `object.position` (each with `x`, `y`, `z` and `set(x, y, z)`), `object.fov`
 *        (degrees) and `update()` — `OrbitControls`'s public members and its perspective camera's
 * @param {{surface: function(): {width: number, height: number}, entry: function(): {position: object, target: object},
 *          clamp: function(): void, bounds: function(): object|null}} view the stage's size, the entry pose
 *        (`view-limits.js`'s `limits().entry`, re-derived on a resize), the renderer's target clamp, and the box the
 *        camera frames (`null` while it frames nothing)
 * @returns {{drag: function(number, number, {shift: boolean}=): object, zoomStep: function(number): object,
 *            rise: function(number): object, fit: function(): object, camera: function(): {bounds: object|null}}}
 */
export function cameraActs(controls, { surface, entry, clamp, bounds }) {
    const camera = () => Object.freeze({ bounds: bounds() });
    const settled = () => {
        clamp();

        return camera();
    };

    return {
        drag(dx, dy, modifiers = {}) {
            if (modifiers.shift) {
                controls.pan(dx, dy);
            } else {
                const { width } = surface();

                controls.rotateLeft(turnOf(dx, width));
                controls.rotateUp(turnOf(dy, width));
            }

            return settled();
        },

        zoomStep(notches) {
            // The controls' factor is the distance's (`dollyIn(s)` multiplies it by `s`, `dollyOut(s)` divides it, as their
            // wheel passes one factor under 1 to both): a notch in is the distance over `ZOOM_STEP`, a notch out times it.
            const scale = ZOOM_STEP ** -Math.abs(notches);

            if (notches > 0) {
                controls.dollyIn(scale);
            } else {
                controls.dollyOut(scale);
            }

            return settled();
        },

        /** The eye and the target raised by a drag of the scene `dy` px down the stage (lowered by one up). */
        rise(dy) {
            const { height } = surface();
            const h = (2 * dy * controls.getDistance() * Math.tan((controls.object.fov * Math.PI) / 360)) / height;
            const { target, object: { position } } = controls;

            target.set(target.x, target.y + h, target.z);
            position.set(position.x, position.y + h, position.z);
            controls.update();

            return settled();
        },

        fit() {
            const { position, target } = entry();

            controls.target.set(target.x, target.y, target.z);
            controls.object.position.set(position.x, position.y, position.z);
            controls.update();

            return settled();
        },

        camera,
    };
}
