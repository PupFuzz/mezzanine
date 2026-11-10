/**
 * THE BUILDING's LABELS — the floors' list and every desk's name, which is both the page's accessible surface and the
 * labels over the stage: one element per entry, docked onto the stage while the stack is drawn and flowing below it
 * otherwise. Appendix B row 47 (card#13033 slice 4; `docs/design/plan-13033-3d-view.md` § 3.4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE LIST IS THE LABELS, AND NO SECOND LIST IS WRITTEN. `#building-floors` holds one entry per directory row — the
 * floor's link and its notices, then its desks' links in READING ORDER (`stack-model.js`'s `readingOrder()`, the cull's
 * order too) — and the ground's, the lobby's link. That document order is the tab order, whatever the camera shows.
 * Entries are reconciled by key, never rebuilt, so a render does not take keyboard focus off the link it is on.
 *
 * ⛔ ONE DOCKING FUNCTION (`dock()`): it is the one writer of every entry's `data-home` — `stage` while the renderer runs,
 * `flow` otherwise — and the sheet keys every dependent style on it. `place()` moves each docked entry to its anchor's
 * projection on every camera applied and on every resize: a floor at its slab's corner nearest the camera
 * (`labelCorner()`), a desk at its nameplate's point on its slab (`worldOf()`).
 *
 * ⛔ A LABEL NOT DRAWN IS CULLED, NEVER HIDDEN: it takes the sheet's `.visually-hidden`, keeping its place in the
 * accessibility tree and the tab order — never the `hidden` attribute, which the sheet makes `display: none`. Which are
 * drawn is `labelsShown()`'s over `cullOverlaps()`, and the *Read this floor* link is `readFloorLink()`'s; this module
 * toggles the class and the link and decides neither.
 *
 * ⛔ ONLY THE KEYBOARD's FOCUS IS REPORTED (`:focus-visible`): a mouse press focuses a link too, and a drag begun on a
 * label must turn the building, not snap to the label's floor (plan § 3.4, round 6's M1).
 */

import { FONT_NAME } from '../floor/desk-layout.js';
import { GROUND_NAME } from './building-screen.js';
import { LABEL_BORDER, LABEL_H, LABEL_PAD, labelBoxes, labelCorner, labelsShown, poseCamera, readFloorLink, readingOrder, worldOf } from './stack-model.js';

/**
 * @param {Document} document
 * @param {{list: HTMLElement, readFloor: HTMLAnchorElement, measure: function(string, string): {w: number}}} elements
 *        `#building-floors`, `#building-read-floor`, and the page's text measurer (`floor/painter.js`'s `measurer()`)
 * @param {{focused: function({floor: string|null, desk: string|null}): void, blurred: function(object|null): void}} report
 *        the keyboard's focus landing on an entry, and leaving one for the entry (or nothing) it moved to
 */
export function buildingLabels(document, { list, readFloor, measure }, report) {
    /** floor key (`''` the ground) → `{ item, head, link, notices, desks, labels }` */
    const floors = new Map();
    /** desk key → its `<a>` */
    const desks = new Map();
    /** each desk label's width on the stage, by its text — measured once per text */
    const widths = new Map();
    let home = 'flow';
    /** Whether the list holds the building's entries yet, or still the page's WAITING placeholder. */
    let listed = false;

    // The label's box is the model's: its font and its padding, border and height are written from the one source.
    list.style.setProperty('--label-font', FONT_NAME);
    list.style.setProperty('--label-pad', `${LABEL_PAD}px`);
    list.style.setProperty('--label-border', `${LABEL_BORDER}px`);
    list.style.setProperty('--label-h', `${LABEL_H}px`);

    const widthOf = (label) => {
        if (!widths.has(label.text)) {
            widths.set(label.text, measure(label.text, 'name').w + 2 * (LABEL_PAD + LABEL_BORDER));
        }

        return widths.get(label.text);
    };

    /** The children a container should hold, put there only when they differ — a move takes focus off a link. */
    const hold = (node, children) => {
        if (node.children.length !== children.length || children.some((child, i) => node.children[i] !== child)) {
            node.replaceChildren(...children);
        }
    };

    const entry = (key) => {
        if (!floors.has(key)) {
            const item = document.createElement('li');
            const head = document.createElement('div');
            const link = document.createElement('a');
            const notices = document.createElement('ul');
            const deskList = document.createElement('ul');

            item.className = 'building-floor';
            item.dataset.floor = key;
            head.className = 'building-floor-head';
            // Classed, so the sheet's rules for them are about these elements and never about every link or list item —
            // the lobby's directory is built from the same tags, and its text must stay the page's body size.
            link.className = 'building-floor-link';
            notices.className = 'building-notices';
            deskList.className = 'building-desks';
            head.append(link, notices);
            item.append(head, deskList);
            floors.set(key, { item, head, link, notices, desks: deskList, labels: [] });
        }

        return floors.get(key);
    };

    const deskLink = (label) => {
        if (!desks.has(label.key)) {
            const link = document.createElement('a');
            const item = document.createElement('li');

            link.className = 'building-desk visually-hidden';
            link.dataset.desk = label.key;
            item.append(link);
            desks.set(label.key, link);
        }

        const link = desks.get(label.key);

        link.href = label.href;
        link.textContent = label.text;

        return link;
    };

    /** The entry an element is inside — its floor's key (`null` the ground) and its desk's, or `null` outside any. */
    const entryOf = (element) => {
        const link = element instanceof Element ? element.closest('a') : null;
        const item = link?.closest('li.building-floor') ?? null;

        if (item === null || !list.contains(item)) {
            return null;
        }

        return { floor: item.dataset.floor === '' ? null : item.dataset.floor, desk: link.dataset.desk ?? null };
    };

    list.addEventListener('focusin', (event) => {
        if (event.target.matches(':focus-visible')) {
            const at = entryOf(event.target);

            if (at !== null) {
                report.focused(at);
            }
        }
    });
    list.addEventListener('focusout', (event) => {
        report.blurred(entryOf(event.relatedTarget));
    });

    return {
        entryOf,

        /**
         * The list for one building frame: one entry per directory row in the frame's order, its notices and its desks in
         * reading order, then the ground's. Before any floor is composed the page's WAITING placeholder stands.
         *
         * @param {list<{row: object, labels: list<object>, notices: list<string>}>} drawn the frame's floors
         * @param {string|null} lobbyHref the ground's link
         */
        render(drawn, lobbyHref) {
            if (drawn.length === 0) {
                return;
            }

            for (const { row, labels, notices } of drawn) {
                const floor = entry(row.floor);

                floor.link.href = row.href;
                floor.link.textContent = row.name;
                floor.labels = labels;
                hold(floor.notices, notices.map((line) => {
                    const item = document.createElement('li');

                    item.className = 'building-notice';
                    item.textContent = line;

                    return item;
                }));
                floor.notices.hidden = notices.length === 0;
                hold(floor.desks, readingOrder(labels).map((label) => deskLink(label).parentElement));
            }

            const ground = entry('');

            ground.item.classList.add('building-ground');
            ground.link.href = lobbyHref ?? '';
            ground.link.textContent = GROUND_NAME;
            ground.notices.hidden = true;
            ground.desks.hidden = true;

            const keys = new Set(drawn.map(({ row }) => row.floor));
            const held = new Set(drawn.flatMap(({ labels }) => labels.map((label) => label.key)));

            for (const key of [...floors.keys()]) {
                if (key !== '' && !keys.has(key)) {
                    floors.delete(key);
                }
            }

            for (const key of [...desks.keys()]) {
                if (!held.has(key)) {
                    desks.delete(key);
                }
            }

            hold(list, [...drawn.map(({ row }) => floors.get(row.floor).item), ground.item]);
            listed = true;
            this.dock(home);
        },

        /** Every entry's home — `stage` while the renderer runs, `flow` otherwise. */
        dock(where) {
            home = where;

            if (!listed) {
                return;
            }

            list.dataset.home = where;

            for (const { head } of floors.values()) {
                head.dataset.home = where;

                if (where === 'flow') {
                    head.style.transform = '';
                }
            }

            for (const link of desks.values()) {
                link.dataset.home = where;

                if (where === 'flow') {
                    link.style.transform = '';
                    link.classList.remove('visually-hidden');
                }
            }
        },

        /**
         * Each docked entry at its anchor's projection under the camera as applied.
         *
         * @param {object} stack `stackModel()`'s
         * @param {{position: object}} camera the camera applied — a pose or a glide's step
         * @param {function(object): {x: number, y: number}} project the paint's projector
         */
        place(stack, camera, project) {
            if (home !== 'stage') {
                return;
            }

            const azimuth = Math.atan2(camera.position.x - stack.axis.x, camera.position.z - stack.axis.z);
            const at = (node, point, centred) => {
                const p = project(point);

                node.style.transform = `translate(${p.x}px, ${p.y}px) translate(-50%, ${centred ? '-50%' : '-100%'})`;
            };

            for (const slab of [...stack.slabs, stack.ground]) {
                const floor = floors.get(slab.floor ?? '');

                if (floor === undefined) {
                    continue;
                }

                at(floor.head, labelCorner(slab, azimuth), false);

                if (slab.extent !== null) {
                    for (const label of floor.labels) {
                        at(desks.get(label.key), worldOf(slab, label.at), true);
                    }
                }
            }
        },

        /**
         * Which desk labels are drawn: none while a glide is in flight (`view` `null`) or at the overview, else the
         * focused floor's that the overlap cull keeps, `leader` (a desk key) first; and the *Read this floor* link.
         *
         * @returns {Set<string>} the culled desk keys of the focused floor
         */
        cull(view, stack, leader) {
            const focused = view === null || view.pose === 'overview' ? null : stack.slabs.find((s) => s.floor === view.pose.floor) ?? null;
            const labels = focused === null ? [] : floors.get(focused.floor)?.labels ?? [];
            const boxes = focused === null || focused.extent === null
                ? new Map()
                : labelBoxes(labels, focused, poseCamera(view, stack, stack.surface), stack.surface, widthOf);
            const byFloor = new Map(focused === null ? [] : [[focused.floor, labels]]);
            const { shown, culled } = focused === null ? { shown: new Set(), culled: new Set() } : labelsShown(view, byFloor, boxes, leader);
            const link = readFloorLink(view ?? { pose: 'overview' }, culled, stack);

            for (const [key, node] of desks) {
                node.classList.toggle('visually-hidden', !shown.has(key));
            }

            readFloor.hidden = link.hidden;

            if (link.href !== null) {
                readFloor.href = link.href;
            }

            return culled;
        },
    };
}
