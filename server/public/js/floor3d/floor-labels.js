/**
 * THE 3-D FLOOR's LABELS — every label the floor world carries (`floor/floor-world.js`'s `labels`: per desk its name,
 * status, chip, facts and bubble), as an HTML element `CSS2DRenderer` positions over the canvas at its anchor.
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.9 (card#13033 stage 2 slice 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ DOCUMENT ORDER IS READING ORDER, ON EVERY RENDER: the desks in `readingOrder()` (stage 1's, the building view's
 * labels' order) and within a desk name, status, chip, facts, bubble — the world's order. `CSS2DRenderer` appends an
 * element only when it is not already a child of its container, so every element is INSERTED here at its index before
 * the render that would append it, and a desk that arrives later (a fetched seat, a displacement) is inserted at its
 * index the same way, never appended at the end. A screen reader therefore hears the floor in one order whatever the
 * camera shows; the floor page's list view below the stage carries every desk besides (plan § 10: a label behind the
 * eye is `display: none`).
 *
 * ⛔ EVERY STRING IS THE WORLD's, which are the scene's (the status the screen's strings, the operator's Q4 answer): this
 * file writes them and decides none. The name is a link to the desk's drill-down, and a plain click opens the panel in
 * place, as the 2-D floor's desks do; focusing it is handed to the renderer, which moves the camera's target to it.
 *
 * ⛔ THE STATUS AND THE BUBBLE STAND BESIDE THEIR SITTER, ON THE VIEWER's RIGHT: each anchor carries a reach
 * (`floor-world.js`'s `anchorPoint()`), its point is moved that far along the camera's right on every frame (`place()`),
 * and its element stands on the point by the corner the anchor's `pin` names — the status hanging from it, the bubble
 * standing on it — so each begins clear of the body from every side the camera orbits to and the two never cover each
 * other. Every other label is centred on its point.
 *
 * ⛔ LABELS THAT WOULD OVERLAP ARE CULLED, NEVER REMOVED (plan § 3.7: stage 1's `cullOverlaps()`, reused): after each
 * render the drawn labels' boxes are read off the stage and the cull keeps, in `PRIORITY`'s order and the desks' reading
 * order within it, each label that overlaps none already kept; a culled label is made transparent and lets the pointer
 * through, but stays in its place in the document, so the accessibility tree is unchanged and its box is still measured
 * on the next frame. A culled name that takes the keyboard's focus is shown.
 *
 * ⛔ A11's BADGE FADES IN ON THE FACTS LABEL (plan § 3.5's A11 row): while the world carries an A11 row in flight for a
 * desk, its facts label's badge line runs one fade over the row's frames from the instant the paint read (its
 * `elapsed_ms` in the past) — a CSS animation, so it draws no frame of the canvas — once per row; under reduced motion
 * the row has no frames and the badge is simply there.
 *
 * ⛔ A PRESS ON A LABEL IS THE LABEL's: the container stops a pointer press from reaching the camera's controls, which
 * would otherwise capture the pointer and swallow the link's click.
 *
 * ⛔ THE ELEVATOR IS A CONTROL (`FLOOR.md` § 4.5, as on the 2-D floor): while the world has a band, the layer's FIRST
 * element — the band is the room's back, first in reading order — is the doorway's button, `role="button"`, a tab stop
 * with an accessible name and `data-key="elevator"` (the page's focus target after a ride), standing at the doorway's
 * centre. A click on it, Enter and Space each call the page's `ride` once; it says `aria-disabled` while a ride is in
 * flight (`riding()`), when the screen refuses the press; focusing it moves the camera's target to the doorway, as a
 * desk's name does to its desk. It shows only while it holds the keyboard's focus and lets the pointer through: a
 * pointer's press on the doorway is the renderer's (`floor-paint.js`), read off the drawn doorway itself.
 */

import { CSS2DObject } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { anchorPoint } from '../floor/floor-world.js';
import { cullOverlaps, readingOrder } from '../building/stack-model.js';
import { ELEVATOR_NAME } from '../floor/cab-paint.js';

/** Which labels win a contended place on the stage, first to last: every desk's name, then its status, chip, bubble, facts. */
const PRIORITY = Object.freeze(['name', 'status', 'chip', 'bubble', 'facts']);

/** A label's id among the held ones: its desk's key and its kind, as one map key. */
const idOf = (label) => JSON.stringify([label.key, label.kind]);

/** A label's text content, line by line — what decides whether its element is rewritten. */
function linesOf(label) {
    switch (label.kind) {
        case 'name':
        case 'chip':
            return [label.text ?? ''];
        case 'status':
            return [label.text ?? 'off'];
        case 'facts':
            return label.model.map((f) => f.text);
        case 'bubble':
            return [...label.model.lines.map((l) => l.text), ...(typeof label.model.source === 'string' ? [label.model.source] : [])];
        default:
            throw new RangeError(`no label of kind "${label.kind}"`);
    }
}

/**
 * The labels over `container` (the stage's label layer, `CSS2DRenderer`'s element), their objects under `group`.
 *
 * @param {HTMLElement} container
 * @param {object} group the scene's `Group` the label objects stand in
 * @param {{select: function(string): void, link: function(string): string,
 *          focused: function({x: number, z: number}, string): void, ride: function(): void}} hooks
 *        open a desk's panel, the desk's drill-down URL, a control taking focus — the point on the floor it stands over
 *        and what it is (a desk's seat, the elevator) — and the elevator's press
 */
export function floorLabels(container, group, { select, link, focused, ride }) {
    const document = container.ownerDocument;
    /** `idOf()` → `{ object, element, lines, desk, kind }` */
    const held = new Map();
    /** the held labels' ids in document order */
    let order = [];
    /** the slab the labels' anchors stand on, the world's */
    let slab = null;

    container.addEventListener('pointerdown', (event) => event.stopPropagation());

    const elevator = document.createElement('div');
    const elevatorObject = new CSS2DObject(elevator);
    /** the doorway the control stands at, the world's (`band.elevator`) */
    let doorway = null;

    elevator.className = 'floor3d-label floor3d-elevator';
    elevator.dataset.key = 'elevator';
    elevator.tabIndex = 0;
    elevator.setAttribute('role', 'button');
    elevator.setAttribute('aria-label', ELEVATOR_NAME);
    elevator.textContent = 'Elevator';
    elevator.addEventListener('click', () => ride());
    // A `role="button"` activates on Enter and on Space; Space's default would scroll the page.
    elevator.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            ride();
        }
    });
    elevator.addEventListener('focus', () => focused({ x: doorway.x + doorway.w / 2, z: doorway.z }, 'the elevator'));

    const make = (label, desk) => {
        const element = document.createElement(label.kind === 'name' ? 'a' : 'div');

        element.className = `floor3d-label floor3d-${label.kind}`;
        element.dataset.key = label.key;
        element.dataset.kind = label.kind;

        if (label.kind === 'name') {
            element.addEventListener('click', (event) => {
                event.preventDefault();
                select(desk.seat_id);
            });
            element.addEventListener('focus', () => {
                const { slab, seat_id: seat } = held.get(idOf(label)).desk;

                focused({ x: (slab.left + slab.right) / 2, z: (slab.back + slab.front) / 2 }, seat);
            });
        }

        const object = new CSS2DObject(element);

        // the point of its box the world stands on the anchor's point (`PIN`)
        object.center.set(label.anchor.pin.x, label.anchor.pin.y);

        group.add(object);

        return { object, element, lines: null, desk, kind: label.kind, raised: null, anchor: label.anchor };
    };

    const write = (entry, label) => {
        const lines = linesOf(label);
        const sig = JSON.stringify([lines, label.render_state ?? null]);

        if (sig === entry.lines) {
            return;
        }

        entry.lines = sig;
        const { element } = entry;

        if (label.kind === 'name') {
            element.textContent = lines[0];
            element.href = link(entry.desk.seat_id);
        } else {
            // new lines carry no fade: a row in flight starts it again on them (`raise()`)
            entry.raised = null;
            element.replaceChildren(...lines.map((text) => {
                const line = document.createElement('span');

                line.textContent = text;

                return line;
            }));
        }

        if (label.kind === 'chip') {
            element.dataset.state = label.render_state ?? '';
        }
    };

    /** A11's fade on a facts label's badge line, started once for the row in flight (`fx`), or none. */
    const raise = (entry, label, fx) => {
        const cause = fx === undefined ? null : fx.cause;

        if (cause === entry.raised) {
            return;
        }

        entry.raised = cause;
        const badge = label.model.findIndex((f) => f.kind === 'badge');

        [...entry.element.children].forEach((line, i) => {
            line.style.animation = fx !== undefined && i === badge
                ? `floor3d-badge-in ${fx.frames * fx.frame_interval_ms}ms linear ${-(fx.elapsed_ms ?? 0)}ms both`
                : '';
        });
    };

    return {
        /**
         * The world's labels, held: each written, given its anchor (stood there by `place()`, each frame) and in its place
         * in the container's order — after the elevator's control, while the world has a band; a label the world no longer
         * carries is removed.
         */
        sync(world) {
            doorway = world.band?.elevator ?? null;
            slab = world.slab;

            if (doorway === null) {
                group.remove(elevatorObject);
                elevator.remove();
            } else {
                // re-adding a child removes it first, and `CSS2DObject` takes its element out of the page on a removal —
                // which would drop the keyboard's focus on every paint
                if (elevatorObject.parent !== group) {
                    group.add(elevatorObject);
                }

                elevatorObject.position.set(doorway.x + doorway.w / 2, doorway.y + doorway.h / 2, doorway.z);

                if (container.firstElementChild !== elevator) {
                    container.insertBefore(elevator, container.firstElementChild);
                }
            }

            const first = doorway === null ? 0 : 1;

            const desks = new Map(world.desks.map((d) => [d.key, d]));
            const raises = new Map(world.effects.filter((e) => e.kind === 'desk' && e.effect.animation_id === 'A11' && e.effect.frames > 0).map((e) => [e.key, e.effect]));
            const desksInOrder = readingOrder(world.desks.map((d) => ({ key: d.key, at: { x: d.slab.left, y: d.slab.back } }))).map((d) => d.key);
            const rank = new Map(desksInOrder.map((key, i) => [key, i]));
            const wanted = [...world.labels].map((label, i) => ({ label, i }))
                .sort((a, b) => rank.get(a.label.key) - rank.get(b.label.key) || a.i - b.i)
                .map(({ label }) => label);
            order = wanted.map(idOf);
            const ids = new Set(order);

            for (const [id, entry] of held) {
                if (!ids.has(id)) {
                    group.remove(entry.object);
                    entry.element.remove();
                    held.delete(id);
                }
            }

            wanted.forEach((label, i) => {
                const id = idOf(label);
                const desk = desks.get(label.key);

                if (!held.has(id)) {
                    held.set(id, make(label, desk));
                }

                const entry = held.get(id);

                entry.desk = desk;
                entry.anchor = label.anchor;
                write(entry, label);

                if (label.kind === 'facts') {
                    raise(entry, label, raises.get(label.key));
                }

                if (container.children[first + i] !== entry.element) {
                    container.insertBefore(entry.element, container.children[first + i] ?? null);
                }
            });
        },

        /**
         * Every label stood at its anchor as the camera whose right is `right` sees it (`anchorPoint()`) — each frame,
         * before the labels are rendered, so a label beside its sitter stays on the viewer's right as the camera turns.
         */
        place(right) {
            for (const entry of held.values()) {
                entry.object.position.copy(anchorPoint(slab, entry.anchor, right));
            }
        },

        /** The overlap cull over the labels the last render drew (the header's rule). */
        cull() {
            const origin = container.getBoundingClientRect();
            const drawn = order.filter((id) => held.get(id).element.style.display !== 'none');
            const boxes = new Map(drawn.map((id) => {
                const r = held.get(id).element.getBoundingClientRect();

                return [id, { x: r.left - origin.left, y: r.top - origin.top, w: r.width, h: r.height }];
            }));
            const rank = (id) => PRIORITY.indexOf(held.get(id).kind) * order.length + order.indexOf(id);
            // `cullOverlaps()` keeps in reading order over `at`; the order handed it here is the priority above.
            const { culled } = cullOverlaps(drawn.map((id) => ({ key: id, at: { x: 0, y: rank(id) } })), boxes, null);

            for (const [id, entry] of held) {
                entry.element.toggleAttribute('data-culled', culled.has(id));
            }

            return culled.size;
        },

        /** A ride in flight, or none: the elevator's control says whether it answers a press. */
        riding(inFlight) {
            if (inFlight) {
                elevator.setAttribute('aria-disabled', 'true');
            } else {
                elevator.removeAttribute('aria-disabled');
            }
        },

        /** How many labels are held. */
        count: () => held.size,
    };
}
