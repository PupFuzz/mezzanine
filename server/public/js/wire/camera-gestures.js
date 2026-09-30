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
 *  · ⛔ NOTHING FRAMED, NOTHING TAKEN (card#7343 r4b, the seat's ruling, widening its r3b ruling on the
 *    wheel to the whole wire). Every gesture is the camera's only while the camera frames something —
 *    `camera.js`'s one `framesNothing()` over the screen's `camera()`, asked afresh by every handler below,
 *    because a building can arrive or go away between two events. A camera with no `bounds` has no scene
 *    to move, and the drawing is then no box of its own: the lobby's uncomposed list flows in the page
 *    (`lobby/building-scene.js`'s `surfaceStyle()`), so each event over it is the browser's, as it was
 *    before the lobby had a camera — the wheel scrolls the page, a `dragstart` drags a room's link, a press
 *    selects text and is never captured, and a press that moves on a room's link and is released there
 *    follows it. The gates: the wheel, the `dragstart`, the press (its `user-select`, and so every drag and
 *    capture after it), the move (a press that began framed stops panning the moment nothing is — no
 *    capture taken, a capture it already holds released, and no drag; card#7343 c7692 item 3) and the
 *    click-after-drag veto. On the floor the camera frames the floor's extent
 *    from the first frame that has one, so a drawn floor's every gesture is handled as before; its camera
 *    frames nothing before that frame, under the list view (where the drawing is hidden), and on a floor
 *    with nothing measurable on it (no map held and no desk — `floor-screen.js`), and there the events,
 *    which moved nothing, are the browser's too;
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
 *    parameter that keeps them apart;
 *  · A PRESS ON A FRAMED DRAWING NEVER SELECTS TEXT AND NEVER DRAGS AN ELEMENT OUT (card#7343 r2-3). A
 *    pan started on a plate's link was the browser's native link drag, and a pan across the plates
 *    selected their text. So a `dragstart` inside the drawing is refused — a drag here pans, and nothing
 *    in either drawing is meant to be dragged out of it — and from a primary press until the drag ends the drawing is
 *    `user-select: none`. That property, rather than cancelling the `pointerdown`: `user-select` is the
 *    one control whose whole meaning is *no selection starts here*, where cancelling a `pointerdown` is
 *    specified to suppress the compatibility mouse events and would also cancel the press's focus on
 *    the engines that tie focus to them — the plate a press lands on is a link the keyboard reaches
 *    too. Scoped to the press, so the drawing's text is selectable as ever by any other means. On the
 *    floor the two rules close the same two cases — its SVG `<text>` selected by a pan, and a
 *    native drag wherever an engine would start one — and change nothing else it does: a press there still pans, clicks and
 *    focuses exactly as before.
 *
 * Driven under `node` with a stand-in element by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { framesNothing } from './camera.js';

/** How far a press must move, in CSS px, before it is a drag and no longer a click. */
export const DRAG_SLOP_PX = 4;

/**
 * @param {EventTarget & {getBoundingClientRect: function(): {left: number, top: number},
 *         setPointerCapture: function(number): void, style: object}} element the drawing the viewer points at
 * @param {{wheel: function(object, object): object, drag: function(number, number): object,
 *         camera: function(): {bounds: object|null}}} acts the screen's camera acts, each returning the
 *        camera it leaves, and the camera as it stands
 * @param {function(object): void} show puts a camera on the drawing (`camera-view.js`'s `show`)
 */
export function cameraGestures(element, acts, show) {
    let drag = null;
    let dragged = false;

    /** The one gate: the screen's camera frames nothing, so the event is the browser's (see the header). */
    const unframed = () => framesNothing(acts.camera());

    /** The press is over, however it ended: the drawing's text is selectable again. */
    function release() {
        drag = null;
        element.style.userSelect = '';
        element.style.webkitUserSelect = '';
    }

    element.addEventListener('dragstart', (event) => {
        // Gate: nothing framed — a link in the flowing list drags as any link does.
        if (unframed()) {
            return;
        }

        event.preventDefault();
    });

    element.addEventListener('wheel', (event) => {
        // Gate: nothing framed — the wheel is the page's scroll.
        if (unframed()) {
            return;
        }

        event.preventDefault();

        const r = element.getBoundingClientRect();

        show(acts.wheel({ x: event.clientX - r.left, y: event.clientY - r.top }, { deltaY: event.deltaY, deltaMode: event.deltaMode, ctrlKey: event.ctrlKey }));
    }, { passive: false });

    element.addEventListener('pointerdown', (event) => {
        if (!event.isPrimary || event.button !== 0) {
            return;
        }

        // Gate: nothing framed — a press selects text as ever, and no drag starts.
        if (unframed()) {
            return;
        }

        dragged = false;
        drag = { x: event.clientX, y: event.clientY, moved: false };
        element.style.userSelect = 'none';
        element.style.webkitUserSelect = 'none';
    });

    element.addEventListener('pointermove', (event) => {
        if (drag === null || (event.buttons & 1) === 0) {
            release();

            return;
        }

        // Gate: nothing framed any more — the press ends here, uncaptured, and pans nothing.
        if (unframed()) {
            // A press that has panned holds the pointer captured: let it go, so the release and its click
            // land on what is under the pointer rather than on the drawing (card#7343 c7692 item 3).
            if (drag.moved) {
                element.releasePointerCapture(event.pointerId);
            }
            release();

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
        release();
    });

    element.addEventListener('pointercancel', () => {
        dragged = false;
        release();
    });

    element.addEventListener('click', (event) => {
        if (!dragged) {
            return;
        }

        dragged = false;

        // Gate: nothing framed any more — the click is the browser's. It follows the link it is on when the
        // press was never captured or moved again once nothing was framed (the move gate released it); a
        // press released still captured — it panned, and never moved after the frame went — clicks the
        // drawing, which follows nothing.
        if (unframed()) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
    }, { capture: true });
}
