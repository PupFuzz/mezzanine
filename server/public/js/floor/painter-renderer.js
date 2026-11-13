/**
 * THE FLOOR PAGE's 2-D RENDERER — `floor/painter.js`'s picture behind the renderer contract the floor page is written
 * against (`floor/floor-page.js`; `docs/design/plan-13033-stage2-3d-floor.md` § 4, card#13033 stage 2 slice 2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. It is the floor page's camera and drawing as they were wired inline before the hoist:
 * the camera is the screen's (`floor/floor-screen.js`'s acts over `wire/camera.js`), shown through `wire/camera-view.js`
 * — at once, or as a glide — and moved by the house gesture wire (`wire/camera-gestures.js`); the drawing is
 * `floor/painter.js`'s painter. It is the one home of `cameraGestures()` and `cameraView()` on the floor pages, so the
 * hoisted page imports neither.
 *
 * ⛔ THE CONTRACT, TWO HALVES (`floor-page.js`'s header states it for every renderer):
 *  · `camera(screen)` at once, before the art answers — `show`, `current`, `fit`, `resize`, `gestures(stage)` and
 *    `keys.acts` — because the page wires its surface, its gestures, its keys and *Fit* at load, so a resize before
 *    the art answers still reaches the screen;
 *  · `create(art, hooks)` once the art modules have answered (`loadArt()`), answering the drawing: `paint(scene,
 *    camera)`, `refresh(view)`, `paintCab(host, ride)`, `cabTheme(dialog)` — here the painter itself, constructed as
 *    the page constructed it.
 */

import { createPainter } from './painter.js';
import { cameraView } from '../wire/camera-view.js';
import { cameraGestures } from '../wire/camera-gestures.js';

/** A 2-D renderer: one per page, its painter `null` until `create()` has run. */
export function painterRenderer() {
    let painter = null;

    return {
        /**
         * The camera the drawing shows right now — the screen's, or a glide's step towards it (Appendix B row 15) —
         * shown through `wire/camera-view.js`: `show()` at once, `glideTo()` over a glide's length.
         */
        camera(screen) {
            const { show, glideTo, current } = cameraView((camera) => painter?.view(camera));

            return {
                show,
                current,
                /** *Fit the floor*: the screen's fit, glided to (a cut under `prefers-reduced-motion`, whose glide is 0 ms). */
                fit() {
                    const { from, to, glide_ms: ms } = screen.fitFloor();

                    glideTo(from, to, ms);
                },
                /**
                 * A resize of the drawing re-shows the screen's camera at once — stopping a glide in flight, whose every
                 * later step would otherwise be computed over the surface it started on.
                 */
                resize(surface) {
                    screen.resize(surface);
                    show(screen.camera());
                },
                /**
                 * A plain wheel or a trackpad's two-finger scroll pans; a Ctrl+wheel or a trackpad's pinch zooms about
                 * the cursor; one finger or the primary button drags to pan, and two fingers pinch — none renders.
                 */
                gestures(stage) {
                    cameraGestures(stage, { pan: screen.pan, zoom: screen.zoom, pinch: screen.pinch, drag: screen.drag, camera: screen.camera }, show);
                },
                /** What `wire/camera-keys.js` is handed: the screen's acts, whose `drag()` ignores a third argument. */
                keys: { acts: { zoomStep: screen.zoomStep, drag: screen.drag, camera: screen.camera } },
            };
        },

        /** Appendix B row 14: the room drawn by `floor/painter.js`, constructed once the art modules have answered. */
        create({ characters, themes, registry }, { failed, select, ride, clock }) {
            painter = createPainter({
                characters,
                themes,
                failed,
                select,
                // § 4.5: the drawn elevator's press.
                ride,
                house: registry?.HOUSE_THEME ?? null,
                clock,
            });

            return painter;
        },
    };
}
