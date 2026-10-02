/**
 * THE CAMERA'S GESTURES ON A PAGE — the wheel, the drag and the touch pinch, wired from one element to a
 * screen's camera acts. `docs/design/FLOOR.md` Appendix B rows 15 and 16, § 4.5 (the operator's ruling of
 * 2026-10-01 on card#11045: plain wheel pans, Ctrl+wheel and the pinch zoom, one finger drags).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343 r1). The floor page (`floor/main.js`, row 15) wired
 * these inline and the lobby (`lobby/main.js`, row 16) near-copied them; two copies of *when a press
 * becomes a pan* would drift into two answers. Both pages call `cameraGestures()` on their drawing.
 *
 * ⛔ THIS FILE DECIDES NOTHING ABOUT THE CAMERA. How far a wheel pans, where a Ctrl+wheel or a pinch
 * zooms to and how far a drag pans are the screen's acts (`pan(delta)`, `zoom(point, delta)`,
 * `pinch(from, to, factor)`, `drag(dx, dy)`, each over `camera.js`); the camera that comes back is
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
 *    frames nothing before that frame and on a floor
 *    with nothing measurable on it (no map held and no desk — `floor-screen.js`), and there the events,
 *    which moved nothing, are the browser's too;
 *  · THE WHEEL CHOOSES BY `ctrlKey`: a plain wheel — a mouse's notch, a trackpad's two-finger scroll —
 *    is the screen's `pan()`, carrying the event's `deltaX`, `deltaY` and `deltaMode`; a Ctrl+wheel — a
 *    mouse's Ctrl+notch, or a trackpad's pinch, which a browser delivers as one — is its `zoom()` about
 *    the cursor, carrying `deltaY` and `deltaMode`. Either takes the event from the page: its scroll, or
 *    the browser's own page zoom — while the act answers that it `consumed` the event. A pan the camera
 *    can make no further the wheel's way is not consumed, and that wheel is the page's scroll, untouched
 *    and showing nothing (the edge, card#11045 Q3, the operator's ruling of 2026-10-01); a zoom, and a
 *    wheel over a lobby mid-ride, are always consumed — each the screen's answer, never this file's;
 *  · only the primary pointer's primary button starts a drag — a right-click's menu never starts a pan
 *    no `pointerup` of its own would end — and a press is a click until it has moved `DRAG_SLOP_PX`,
 *    when the pointer is captured;
 *  · A SECOND POINTER DOWN WHILE THE FIRST IS PRESSED IS A PINCH — two fingers on a touch screen. From
 *    then until one lifts, each move of either is the screen's `pinch()`: a zoom by the fingers' spread
 *    over their spread at the last move, about their midpoint, the scene under the midpoint carried
 *    where the midpoint goes. Both pointers are captured, and the gesture is no click. When one finger
 *    lifts, the other drags on from where it is; a third pointer is ignored. Only the drag's own
 *    pointer moves the drag — a second finger's move never pans;
 *  · a move with the primary button no longer held ends the drag (a press released outside the element
 *    before capture was taken is never heard released), and so does a `pointercancel` — a pointer the
 *    browser took back, which is no click either — and a `lostpointercapture` of a pointer the press still
 *    holds, a capture revoked with neither (card#11045);
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
 * ⚠ NOT VERIFIED ON A REAL DEVICE: the pinch and both wheels were driven with synthetic events in headless
 * Chromium (card#11045 PR-B); a real trackpad, a real touch screen and Safari were not. Safari may deliver
 * a trackpad pinch as its own `gesture*` events rather than as a Ctrl+wheel, and nothing here handles those.
 *
 * Driven under `node` with a stand-in element by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`.
 */

import { framesNothing } from './camera.js';

/** How far a press must move, in CSS px, before it is a drag and no longer a click. */
export const DRAG_SLOP_PX = 4;

/**
 * @param {EventTarget & {getBoundingClientRect: function(): {left: number, top: number},
 *         setPointerCapture: function(number): void, releasePointerCapture: function(number): void,
 *         style: object}} element the drawing the viewer points at
 * @param {{pan: function(object): {camera: object, consumed: boolean},
 *         zoom: function(object, object): {camera: object, consumed: boolean},
 *         pinch: function(object, object, number): object, drag: function(number, number): object,
 *         camera: function(): {bounds: object|null}}} acts the screen's camera acts — the wheel's two
 *        each returning the camera it leaves and whether it consumed the event, the pointer's two the
 *        camera it leaves — and the camera as it stands
 * @param {function(object): void} show puts a camera on the drawing (`camera-view.js`'s `show`)
 */
export function cameraGestures(element, acts, show) {
    /** The press: its pointer, where it last was, whether it has panned, and the pointers it has captured. */
    let drag = null;
    /** The second finger and the pinch's last midpoint and spread — `null` unless two pointers are down. */
    let pinch = null;
    let dragged = false;

    /** The one gate: the screen's camera frames nothing, so the event is the browser's (see the header). */
    const unframed = () => framesNothing(acts.camera());

    /** A point on the drawing, in CSS px from its top-left, from a client point. */
    const local = (x, y) => {
        const r = element.getBoundingClientRect();

        return { x: x - r.left, y: y - r.top };
    };

    /** The two fingers' midpoint on the drawing and their spread. */
    const span = () => {
        const a = drag;
        const b = pinch;

        return { mid: local((a.x + b.x) / 2, (a.y + b.y) / 2), spread: Math.hypot(b.x - a.x, b.y - a.y) };
    };

    /** Capture a pointer for the press, once. */
    const capture = (id) => {
        if (!drag.captured.includes(id)) {
            drag.captured.push(id);
            element.setPointerCapture(id);
        }
    };

    /** The press is over, however it ended: the drawing's text is selectable again. */
    function release() {
        drag = null;
        pinch = null;
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

        const { camera, consumed } = event.ctrlKey
            ? acts.zoom(local(event.clientX, event.clientY), { deltaY: event.deltaY, deltaMode: event.deltaMode })
            : acts.pan({ deltaX: event.deltaX, deltaY: event.deltaY, deltaMode: event.deltaMode });

        // The edge (card#11045 Q3): a wheel the act did not consume — the camera can pan no further its way —
        // is the page's scroll, and moves nothing here.
        if (!consumed) {
            return;
        }

        event.preventDefault();
        show(camera);
    }, { passive: false });

    element.addEventListener('pointerdown', (event) => {
        // Another pointer pressed while the press is down is its pinch's second finger.
        const second = drag !== null && event.pointerId !== drag.id;

        // A press is the primary pointer's primary button; a second finger, a primary contact while no pinch
        // is held. A third pointer, or a mouse's other button, is ignored.
        if (second ? pinch !== null || event.button !== 0 : !event.isPrimary || event.button !== 0) {
            return;
        }

        // Gate: nothing framed — a press selects text as ever, and no drag or pinch starts.
        if (unframed()) {
            return;
        }

        if (second) {
            pinch = { id: event.pointerId, x: event.clientX, y: event.clientY };
            Object.assign(pinch, span());
            drag.moved = true;
            capture(drag.id);
            capture(event.pointerId);

            return;
        }

        dragged = false;
        pinch = null;
        drag = { id: event.pointerId, x: event.clientX, y: event.clientY, moved: false, captured: [] };
        element.style.userSelect = 'none';
        element.style.webkitUserSelect = 'none';
    });

    element.addEventListener('pointermove', (event) => {
        if (drag === null || (event.buttons & 1) === 0) {
            release();

            return;
        }

        const finger = event.pointerId === drag.id ? drag : event.pointerId === pinch?.id ? pinch : null;

        // A pointer that is neither the press nor its pinch moves nothing.
        if (finger === null) {
            return;
        }

        // Gate: nothing framed any more — the press ends here, uncaptured, and pans nothing.
        if (unframed()) {
            // A press that has panned holds its pointers captured: let them go, so the release and its click
            // land on what is under the pointer rather than on the drawing (card#7343 c7692 item 3).
            for (const id of drag.captured) {
                element.releasePointerCapture(id);
            }
            release();

            return;
        }

        if (pinch !== null) {
            const was = { mid: pinch.mid, spread: pinch.spread };

            finger.x = event.clientX;
            finger.y = event.clientY;
            Object.assign(pinch, span());
            show(acts.pinch(was.mid, pinch.mid, was.spread > 0 ? pinch.spread / was.spread : 1));

            return;
        }

        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;

        if (!drag.moved && Math.hypot(dx, dy) < DRAG_SLOP_PX) {
            return;
        }

        if (!drag.moved) {
            drag.moved = true;
            capture(event.pointerId);
        }

        drag.x = event.clientX;
        drag.y = event.clientY;
        show(acts.drag(dx, dy));
    });

    element.addEventListener('pointerup', (event) => {
        // One finger of a pinch lifts: the other drags on from where it is.
        if (pinch !== null && (event.pointerId === drag.id || event.pointerId === pinch.id)) {
            if (event.pointerId === drag.id) {
                drag.id = pinch.id;
                drag.x = pinch.x;
                drag.y = pinch.y;
            }
            pinch = null;

            return;
        }

        if (drag !== null && event.pointerId !== drag.id) {
            return;
        }

        dragged = drag?.moved === true;
        release();
    });

    element.addEventListener('pointercancel', () => {
        dragged = false;
        release();
    });

    // A capture the browser revoked with no `pointerup` or `pointercancel` of its own — the element left the
    // document, say — ends the press as a cancel does: otherwise the press stays held, and the next touch,
    // under a new `pointerId`, is read as its pinch's second finger. Only a pointer the press still holds:
    // a pinch's lifted finger loses its capture after its `pointerup` has already handed the drag to the
    // other, and a press that ended normally loses its capture after `release()` — neither is ended twice,
    // and the click-after-drag veto (`dragged`) is left as the `pointerup` set it.
    element.addEventListener('lostpointercapture', (event) => {
        if (drag !== null && (event.pointerId === drag.id || event.pointerId === pinch?.id)) {
            release();
        }
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
