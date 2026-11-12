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
 * ⛔ LABELS THAT WOULD OVERLAP ARE CULLED, NEVER REMOVED (plan § 3.7: stage 1's `cullOverlaps()`, reused): after each
 * render the drawn labels' boxes are read off the stage and the cull keeps, in `PRIORITY`'s order and the desks' reading
 * order within it, each label that overlaps none already kept; a culled label is made transparent and lets the pointer
 * through, but stays in its place in the document, so the accessibility tree is unchanged and its box is still measured
 * on the next frame. A culled name that takes the keyboard's focus is shown.
 *
 * ⛔ A PRESS ON A LABEL IS THE LABEL's: the container stops a pointer press from reaching the camera's controls, which
 * would otherwise capture the pointer and swallow the link's click.
 */

import { CSS2DObject } from '../vendor/three/examples/jsm/renderers/CSS2DRenderer.js';
import { anchorPoint } from '../floor/floor-world.js';
import { cullOverlaps, readingOrder } from '../building/stack-model.js';

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
 * @param {{select: function(string): void, link: function(string): string, focused: function(object): void}} hooks
 *        open a desk's panel, the desk's drill-down URL, and a desk's name label taking focus (the world's desk)
 */
export function floorLabels(container, group, { select, link, focused }) {
    const document = container.ownerDocument;
    /** `idOf()` → `{ object, element, lines, desk, kind }` */
    const held = new Map();
    /** the held labels' ids in document order */
    let order = [];

    container.addEventListener('pointerdown', (event) => event.stopPropagation());

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
            element.addEventListener('focus', () => focused(held.get(idOf(label)).desk));
        }

        const object = new CSS2DObject(element);

        group.add(object);

        return { object, element, lines: null, desk, kind: label.kind };
    };

    const write = (entry, label) => {
        const lines = linesOf(label);
        const sig = JSON.stringify([lines, label.lit ?? null, label.render_state ?? null]);

        if (sig === entry.lines) {
            return;
        }

        entry.lines = sig;
        const { element } = entry;

        if (label.kind === 'name') {
            element.textContent = lines[0];
            element.href = link(entry.desk.seat_id);
        } else {
            element.replaceChildren(...lines.map((text) => {
                const line = document.createElement('span');

                line.textContent = text;

                return line;
            }));
        }

        if (label.kind === 'status') {
            element.dataset.lit = label.lit;
        }

        if (label.kind === 'chip') {
            element.dataset.state = label.render_state ?? '';
        }
    };

    return {
        /**
         * The world's labels, held: each written, stood at its anchor and in its place in the container's order; a label
         * the world no longer carries is removed.
         */
        sync(world) {
            const desks = new Map(world.desks.map((d) => [d.key, d]));
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
                write(entry, label);
                entry.object.position.copy(anchorPoint(world.slab, label.anchor));

                if (container.children[i] !== entry.element) {
                    container.insertBefore(entry.element, container.children[i] ?? null);
                }
            });
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

        /** How many labels are held. */
        count: () => held.size,
    };
}
