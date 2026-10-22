/**
 * THE LOBBY DIRECTORY, PAINTED — `docs/design/FLOOR.md` § 4.1's directory board (card#12113): one plaque per floor,
 * each the plaque `lobby-model.js` decided, as elements in the camera's layer at the board's rect, and the building's
 * readings under the board. **What a plaque shows is § 4.1's table and `lobby-model.js`'s alone; this module states only
 * what its own code does.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS MODULE DECIDES NO FACT. Every string is the plaque's — its `name`, `href`, `accessible_name`, `chips`,
 * `staffing`, `markers`, `attention` and `floor_totals` (`lobby-model.js`'s `plate()`) — and the readings' strings are
 * the frame's. A figure computed here would be a second derivation free to disagree with the model, which is
 * AT-D3-15's whole subject.
 *
 * ⭐ THE BOXES HAVE THREE HOMES, AND ONE ENTRY WRITES WHICH (§ 4.1, decision 72; card#12187). `dockingEntry()` is the ONE
 * writer of every state the dock changes: where each box stands, its `data-home` — `board` in the camera's layer,
 * `dock` in the page's flow under the drawn room, `flow` in the page's flow with no room drawn (the markup ships
 * `flow`) — and the drawing surface's `touch-action`, `none` only while the boxes stand on the board, where the gesture
 * wire listens. Every style that depends on where a box stands is keyed on its own `data-home` (`mezzanine.css`).
 *
 * ⛔ THE TEXT IS AT THE PAGE's OWN SIZE AND IS NEVER SCALED BY THE CAMERA (§ 4.1, decision 37). On the board the
 * directory's box and the readings' box stand in the camera's layer at their rects in scene px (`building-scene.js`'s
 * `lobbyScene()`), so they stay on the board at every zoom and pan; inside each, a FACE is counter-scaled — its size the box's at the
 * camera's zoom, `scale()` by the zoom's inverse — so its text lays out at `1rem` on the screen. ONE WRITER:
 * `showDirectory()` sets the two custom properties every face reads (`--lobby-zoom`, `--lobby-scale`), called by the
 * page's camera hook (`lobby/main.js`'s `view()`) on every camera the page shows and never from a render, so a face can
 * never go stale between a render and the camera moves that follow it.
 *
 * ⛔ ON THE BOARD THE DIRECTORY SCROLLS INSIDE ITS BOX, AND DECLARES IT (§ 4.1). The plaques' list is `overflow-y: auto`
 * and carries `data-camera-scrolls`, the declaration `wire/camera-gestures.js` reads to give a wheel or a drag begun over
 * it to the list while the list can move that way and to the camera once it cannot. On the board it is `touch-action:
 * none` like the drawing around it, and the gesture wire scrolls it from the drag it owns (§ 4.1 says why); docked it is
 * no scroller, the page is, and the browser owns every touch.
 *
 * ⛔ EVERY LINE IS READ WHOLE: this module builds a row per part and the sheet wraps a row wider than its plaque, so a
 * plaque grows taller rather than hiding a figure (§ 4.1 says which part of the name row gives way).
 */

import { fitZoom, framesNothing } from '../wire/camera.js';

/** The directory's title line (§ 4.1, AT-D3-15's label): the board's heading and the plaques' list's name. */
export const DIRECTORY_TITLE = 'FLOOR DIRECTORY';

/** … and the half of it AT-D3-15 requires: the per-floor figures are counts of the seats this client holds. */
export const DIRECTORY_HELD = '— counts the seats this client holds';

/** A room the fleet reports no seat for, in § 4.6's words. */
export const ROOM_NOT_REPORTED = 'no seats reported for this room';

/**
 * ⭐ THE ONE WRITER — the counter-scale every face in the layer reads, for `camera`: `--lobby-zoom` (the camera's zoom,
 * which sizes each face to its box on the screen) and `--lobby-scale` (its inverse, which scales the face back into the
 * box). A camera that frames nothing writes nothing: the room is not drawn and the faces flow in the page.
 *
 * @param {{style: {setProperty: function(string, string): void}}} layer the camera's layer, `#lobby-room`
 * @param {object} camera a `wire/camera.js` camera — the one the room is shown under
 */
export function showDirectory(layer, camera) {
    if (framesNothing(camera)) {
        return;
    }

    layer.style.setProperty('--lobby-zoom', String(camera.zoom));
    layer.style.setProperty('--lobby-scale', String(1 / camera.zoom));
}

/**
 * A box's style for its home — at its rect in scene px while it stands on the board, and nothing while it is docked or
 * flows with no room drawn, so it stands in the page; its face's, on the board, sized to the box on the screen and
 * counter-scaled by the two properties `showDirectory()` writes, and docked or flowing at the page's text size with no
 * counter-scale. Every member is set either way. Written by `dockingEntry()` alone.
 *
 * @param {{x: number, y: number, w: number, h: number}} rect the box's rect, in scene px
 * @param {boolean} drawn whether the box stands on the board (`data-home="board"`)
 * @returns {{box: object, face: object}}
 */
export function boxStyles(rect, drawn) {
    return {
        box: {
            position: drawn ? 'absolute' : '',
            left: drawn ? `${rect.x}px` : '',
            top: drawn ? `${rect.y}px` : '',
            width: drawn ? `${rect.w}px` : '',
            height: drawn ? `${rect.h}px` : '',
            overflow: drawn ? 'hidden' : '',
        },
        face: {
            width: drawn ? `calc(${rect.w}px * var(--lobby-zoom, 1))` : '',
            height: drawn ? `calc(${rect.h}px * var(--lobby-zoom, 1))` : '',
            transform: drawn ? 'scale(var(--lobby-scale, 1))' : '',
            transformOrigin: drawn ? '0 0' : '',
        },
    };
}

/**
 * § 12's *Directory dock width*: the least the directory's face may be at the room's fit — in `em` of the page's own
 * text size — for the boxes to stand on the board. Under either figure the lobby docks them under the room.
 */
export const DOCK_BOUND = Object.freeze({ w: 24, h: 15 });

/**
 * Where the boxes stand (§ 4.1, decision 72): `flow` with no room drawn; with it drawn, `dock` when the directory's
 * face at the room's fit — the camera's fit zoom over the room's extent, times the box's rect — is narrower than
 * `DOCK_BOUND.w` or shorter than `DOCK_BOUND.h` at the page's `rem`, and `board` otherwise.
 *
 * @param {boolean} drawn whether the room is drawn
 * @param {{width: number, height: number}} surface the drawing surface in CSS px
 * @param {number} rem the page's `1rem` in CSS px
 * @param {{extent: object, directory: object}} scene `building-scene.js`'s `lobbyScene()`
 * @returns {'flow'|'dock'|'board'}
 */
export function homeFor(drawn, surface, rem, scene) {
    if (!drawn) {
        return 'flow';
    }

    const zoom = fitZoom(surface, scene.extent);

    return scene.directory.w * zoom < DOCK_BOUND.w * rem || scene.directory.h * zoom < DOCK_BOUND.h * rem ? 'dock' : 'board';
}

/**
 * ⭐ THE DOCKING ENTRY — the ONE writer of every state the dock changes (§ 4.1, decision 72; canon #5: one writer, so no
 * second module derives the predicate). `place(drawn)` asks `homeFor()` over the surface as it measures now and the
 * page's `1rem` as a probe measures it, and then, for each box in order: moves it — into the camera's layer for
 * `board`, into the page's flow after the surface (the directory first) otherwise — only when its parent is not already
 * the right one, so a render that keeps the home moves nothing; writes its `data-home`; and writes its box's and its
 * face's styles (`boxStyles()`). Last it writes the surface's `touch-action`: `none` on the board, where the gesture
 * wire reads the drags, and `auto` docked and with no room drawn, where the browser owns every touch. The home is
 * re-asked on every `resize` and on every change of the page's text size — which fires no `resize` — through a
 * `ResizeObserver` on a hidden `1rem` probe, so an `em` bound never goes stale.
 *
 * @param {Window} win the page's window (its `ResizeObserver` and its `resize`)
 * @param {Document} doc the page's document
 * @param {{building: Element, layer: Element, boxes: Array<[Element, object]>, scene: object}} at the surface, the
 *   camera's layer, each box with its scene rect in page order, and the room's scene
 * @returns {{place: function(boolean=): string, home: function(): (string|null)}}
 */
export function dockingEntry(win, doc, { building, layer, boxes, scene }) {
    const probe = doc.createElement('span');

    probe.setAttribute('aria-hidden', 'true');
    Object.assign(probe.style, { position: 'absolute', width: '1rem', height: '1rem', visibility: 'hidden', pointerEvents: 'none' });
    building.parentElement.append(probe);

    let drawn = false;
    let home = null;

    const place = (isDrawn = drawn) => {
        drawn = isDrawn;
        const next = homeFor(drawn, { width: building.clientWidth, height: building.clientHeight }, probe.getBoundingClientRect().height, scene);
        let after = building;

        for (const [box, rect] of boxes) {
            if (next === 'board') {
                if (box.parentElement !== layer) {
                    layer.append(box);
                }
            } else {
                if (box.parentElement !== building.parentElement) {
                    after.after(box);
                }

                after = box;
            }

            box.setAttribute('data-home', next);

            const styles = boxStyles(rect, next === 'board');

            Object.assign(box.style, styles.box);
            Object.assign(box.firstElementChild.style, styles.face);
        }

        building.style.touchAction = next === 'board' ? 'none' : 'auto';
        home = next;

        return next;
    };

    new win.ResizeObserver(() => place()).observe(probe);
    win.addEventListener('resize', () => place());

    return { place, home: () => home };
}

/** An element of `tag` with `className` and, where given, its text. */
function el(doc, tag, className, text = null) {
    const node = doc.createElement(tag);

    node.className = className;

    if (text !== null) {
        node.textContent = text;
    }

    return node;
}

/** The fill a chip or a marker reads: its member's `--state-<member>` token, or the sheet's unrecognised ink. */
function fill(state, recognised) {
    return recognised ? `var(--state-${state})` : 'var(--state-ink-unconfirmed)';
}

/**
 * One plaque, as elements: the name row — the numeral, the floor's name as the plaque's one link (to its key's route,
 * named by the plaque's accessible name), the rooms where the plaque names any, the agents and desks — the tally row
 * (one chip per member), the marker row (the first markers and the *+N more* tag), one row per attention part, and
 * the totals row.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in
 * @param {object} plaque a `lobby-model.js` plaque
 * @param {number} index its place in the directory, from `0`
 */
export function plaqueRow(doc, plaque, index) {
    const row = el(doc, 'li', 'lobby-plaque');

    row.dataset.floor = plaque.floor;
    row.dataset.key = `floor:${plaque.floor}`;

    const head = el(doc, 'div', 'lobby-plaque-row lobby-plaque-head');
    const link = el(doc, 'a', 'lobby-plaque-name');

    link.href = plaque.href;
    link.setAttribute('aria-label', plaque.accessible_name);
    link.append(el(doc, 'span', 'lobby-plaque-numeral', String(index + 1)), doc.createTextNode(plaque.name));
    head.append(link);

    // § 4.1 row 1: a floor of more than one room names its rooms, each by its `install_id` alone; a room the fleet
    // reports no seat for is marked as such in § 4.6's words.
    if (plaque.rooms.length > 1 || plaque.rooms.some((room) => !room.reported)) {
        head.append(el(doc, 'span', 'lobby-plaque-rooms', `rooms: ${plaque.rooms
            .map((room) => (room.reported ? room.install_id : `${room.install_id} (${ROOM_NOT_REPORTED})`))
            .join(', ')}`));
    }

    head.append(el(doc, 'span', 'lobby-plaque-staffing', plaque.staffing.text));

    const tally = el(doc, 'div', 'lobby-plaque-row lobby-plaque-tally');

    for (const chip of plaque.chips) {
        const node = el(doc, 'span', `lobby-chip${chip.recognised ? '' : ' unrecognised'}`, chip.text);

        node.style.setProperty('--fill', fill(chip.state, chip.recognised));
        tally.append(node);
    }

    const markerRow = el(doc, 'div', 'lobby-plaque-row lobby-plaque-markers');

    for (const marker of plaque.markers.shown) {
        const node = el(doc, 'span', `lobby-marker ${marker.shape}`);

        node.setAttribute('role', 'img');
        node.setAttribute('aria-label', marker.name);
        node.style.setProperty('--fill', fill(marker.state, marker.recognised));
        markerRow.append(node);
    }

    if (plaque.markers.more !== null) {
        markerRow.append(el(doc, 'span', 'lobby-marker-more', plaque.markers.more));
    }

    row.append(head, tally, markerRow);

    // § 4.1: the attention line names only what needs a look, a row per part, and is ABSENT when nothing does.
    for (const part of plaque.attention ?? []) {
        const line = el(doc, 'div', `lobby-plaque-row lobby-plaque-attention ${part.part}`, part.text);

        row.append(line);
    }

    row.append(el(doc, 'div', 'lobby-plaque-row lobby-plaque-totals', plaque.floor_totals.text));

    return row;
}

/**
 * § 9 F17's directory: an install of the snapshot as a plain link, *`install_id` — no floor claimed*, with no plaque
 * figures — every seat still reachable through its own link, which § 4.4 resolves once the layout is readable.
 */
export function unclaimedRow(doc, room) {
    const row = el(doc, 'li', 'lobby-plaque unclaimed');
    const link = el(doc, 'a', 'lobby-plaque-name', `${room.install_id} — no floor claimed`);

    row.dataset.key = `room:${room.install_id}`;
    link.href = room.href;
    row.append(link);

    return row;
}

/**
 * The plaque `old` brought up to `fresh` IN PLACE, keeping its link element — the one a keyboard focuses — and its name
 * row: the link's href, name and text are rewritten, the name row's other parts and every other row replaced. A focused
 * link is never removed from the document, so a re-render (the 1 s tick's included) never takes the keyboard's focus.
 */
function patchPlaque(old, fresh) {
    const [oldHead] = old.children;
    const [freshHead, ...rows] = [...fresh.children];
    const link = oldHead.firstElementChild;
    const freshLink = freshHead.firstElementChild;

    link.href = freshLink.href;
    link.setAttribute('aria-label', freshLink.getAttribute('aria-label'));
    link.replaceChildren(...freshLink.childNodes);

    while (oldHead.lastChild !== link) {
        oldHead.lastChild.remove();
    }

    oldHead.append(...[...freshHead.children].slice(1));

    while (old.lastChild !== oldHead) {
        old.lastChild.remove();
    }

    old.append(...rows);
}

/** A kept row brought up to date: a plaque in place (`patchPlaque()`), an unclaimed room's link in place, the empty line as it is. */
function patchRow(old, fresh) {
    if (old.dataset.key.startsWith('floor:')) {
        patchPlaque(old, fresh);

        return;
    }

    if (old.dataset.key.startsWith('room:')) {
        const link = old.firstElementChild;
        const freshLink = fresh.firstElementChild;

        link.href = freshLink.href;
        link.textContent = freshLink.textContent;
    }
}

/**
 * The directory's list for one summary: a plaque per floor, in the model's order, or under § 9 F17 the snapshot's
 * installs unclaimed — and, with neither, the fleet's having none IN WORDS, because an empty list and a lobby that
 * failed to draw are the same pixels.
 *
 * ⛔ A RE-RENDER KEEPS THE KEYBOARD's PLACE. Where the list already holds the same rows in the same order — a plaque per
 * floor, or § 9 F17's unclaimed rooms, keyed by `data-key` — each is brought up to date in place (`patchRow()`), so a
 * focused link stays focused across every render and every 1 s tick, and the list's scroll stays where the viewer left
 * it. Any other change rebuilds the list.
 *
 * @param {Document} doc the page's `document`, or the probe's stand-in
 * @param {Element} list the plaques' list, `#lobby-floors`
 * @param {{floors: Array<object>, unclaimed: Array<object>}} summary the lobby frame's `summary`
 */
export function renderDirectory(doc, list, summary) {
    const rows = [
        ...summary.floors.map((plaque, i) => plaqueRow(doc, plaque, i)),
        ...summary.unclaimed.map((room) => unclaimedRow(doc, room)),
    ];

    if (rows.length === 0) {
        const none = el(doc, 'li', 'lobby-plaque none', 'no installs are provisioned — the fleet reports none');

        none.dataset.key = 'none';
        rows.push(none);
    }

    const held = [...list.children];
    const keyOf = (li) => li.dataset.key ?? null;
    const same = held.length === rows.length && held.every((li, i) => keyOf(li) !== null && keyOf(li) === keyOf(rows[i]));

    if (!same) {
        list.replaceChildren(...rows);

        return;
    }

    held.forEach((li, i) => patchRow(li, rows[i]));
}
