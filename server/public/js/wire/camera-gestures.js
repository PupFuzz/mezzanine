/**
 * THE CAMERA'S GESTURES ON A PAGE — the wheel and the drag, wired from one element to a screen's camera
 * acts. `docs/design/FLOOR.md` Appendix B rows 15 and 16.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343 r1). The floor page (`floor/main.js`, row 15) wired
 * these inline and the lobby (`lobby/main.js`, row 16) near-copied them; two copies of *when a press
 * becomes a pan* would drift into two answers. Both pages call `cameraGestures()` on their drawing.
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT THE CAMERA. Where a wheel zooms to and how far a drag pans are the
 * screen's acts (`wheel(point, delta)`, `drag(dx, dy)`, each over `camera.js`); what comes back is
 * handed to `show` — `camera-view.js`'s — which renders nothing. What IS decided here is the gesture:
 *  · the wheel zooms about the cursor, carrying the event's `deltaMode` and `ctrlKey` (a pinch) with
 *    its `deltaY`, and takes the event from the page's own scroll;
 *  · only the primary pointer's primary button drags — a right-click's menu or a second finger never
 *    starts a pan no `pointerup` of its own would end — and a press is a click until it has moved
 *    `DRAG_SLOP_PX`, when the pointer is captured;
 *  · a move with the primary button no longer held ends the drag (a press released outside the element
 *    before capture was taken is never heard released), and so does a `pointercancel` — a pointer the
 *    browser took back, which is no click either;
 *  · A DRAG THAT MOVED IS NO CLICK — neither its handlers nor its default action. That is the one click
 *    policy, and it is the lobby's: a plate there is a link, and a drag ending over it must not follow
 *    it. The floor's inline copy only stopped the click's propagation, which was complete there only
 *    because nothing inside the floor's drawing has a default click action (its desks are SVG groups
 *    with click handlers, `floor/painter.js`); taking the default action too changes nothing on the
 *    floor and closes the case on the lobby, so the two pages share the whole policy rather than a
 *    parameter that keeps them apart.
 *
 * Driven under `node` with a stand-in element by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

/** How far a press must move, in CSS px, before it is a drag and no longer a click. */
export const DRAG_SLOP_PX = 4;

/**
 * @param {EventTarget & {getBoundingClientRect: function(): {left: number, top: number},
 *         setPointerCapture: function(number): void}} element the drawing the viewer points at
 * @param {{wheel: function(object, object): object, drag: function(number, number): object}} acts the
 *        screen's camera acts, each returning the camera it leaves
 * @param {function(object): void} show puts a camera on the drawing (`camera-view.js`'s `show`)
 */
export function cameraGestures(element, acts, show) {
    let drag = null;
    let dragged = false;

    element.addEventListener('wheel', (event) => {
        event.preventDefault();

        const r = element.getBoundingClientRect();

        show(acts.wheel({ x: event.clientX - r.left, y: event.clientY - r.top }, { deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }));
    }, { passive: false });

    element.addEventListener('pointerdown', (event) => {
        if (!event.isPrimary || event.button !== 0) {
            return;
        }

        dragged = false;
        drag = { x: event.clientX, y: event.clientY, moved: false };
    });

    element.addEventListener('pointermove', (event) => {
        if (drag === null || (event.buttons & 1) === 0) {
            drag = null;

            return;
        }

        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;

        if (!drag.moved && Math.hypot(dx, dy) < DRAG_SLOP_PX) {
            return;
        }

        if (!drag.moved) {
            drag.moved = true;
            element.setPointerCapture(event.pointerId);
        }

        drag.x = event.clientX;
        drag.y = event.clientY;
        show(acts.drag(dx, dy));
    });

    element.addEventListener('pointerup', () => {
        dragged = drag?.moved === true;
        drag = null;
    });

    element.addEventListener('pointercancel', () => {
        dragged = false;
        drag = null;
    });

    element.addEventListener('click', (event) => {
        if (dragged) {
            event.preventDefault();
            event.stopPropagation();
            dragged = false;
        }
    }, { capture: true });
}
