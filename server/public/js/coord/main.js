/**
 * The coordination layer's thin DOM half — `docs/design/FLOOR.md § 5.7`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING, and that is the whole reason it is separate. There is no browser
 * on the build host, so nothing here is exercised by any check in this repository; keeping every
 * FACT in `coord-model.js` is what keeps the unverifiable part free of decisions. If a rule
 * appears below that is not in that module, it is in the wrong file.
 *
 * ⛔ NO JOIN IS CONSTRUCTED HERE, AND THIS FILE IS NOT WHERE ONE EVER WILL BE. `coordModel`
 * takes an `options.join` and this file passes NONE. ⚠ The reason is no longer that no builder
 * exists: `../floor/coord-join.js` builds one (card#7341 step 7), from the seat population the
 * client protocol holds. THIS page holds no seat population — it is the coordination surface's own
 * page and constructs no client protocol — so it has nothing to build a map FROM, and every
 * participant here renders `unresolved` with no thread line drawn, which is D3 § 5.7 clause 1's
 * permanent arm rather than a stub. The floor is where a resolved line is drawn, because the floor
 * is where the desks are (D3 § 14 item 24).
 * Synthesising a join HERE — from `seat_id` equality, from the roster's order, from a prefix —
 * would still be precisely the unvalidated-join-becoming-a-ratified-one that card#7957 was
 * filed to prevent, and it would be invisible on screen because a wrong line looks exactly like
 * a right one. `CoordDrawsNoLineWithoutAJoinTest` holds this file to that, and holds it to the
 * behaviour of the code rather than to the state of the design.
 */

import { UNRESOLVED, coordModel } from './coord-model.js';

/** Text into an element, or nothing if the element is not on this page. */
function put(root, selector, text) {
    const el = root.querySelector(selector);

    if (el !== null) {
        el.textContent = text;
    }

    return el;
}

/**
 * Render one floor's coordination layer into `root`.
 *
 * `messages` is the list of `coord.thread` / `coord.round` envelopes this client has applied on
 * this floor's channel — the feed's, in arrival order. There is no snapshot to seed it from
 * (D2 § 8.3.3), which is why an empty layer is drawn as empty and never as *quiet*.
 */
export function renderCoordLayer(root, messages, installId) {
    const model = coordModel(messages, { install_id: installId });

    put(root, '[data-coord-lines]', String(model.drawn_lines));
    put(root, '[data-coord-beads]', String(model.rounds.length));
    put(root, '[data-coord-unresolved]', model.unresolved.length === 0
        ? ''
        : `${UNRESOLVED}: ${model.unresolved.join(', ')}`);

    const list = root.querySelector('[data-coord-threads]');

    if (list !== null) {
        list.replaceChildren(...model.threads.map((thread) => {
            const row = root.ownerDocument.createElement('li');

            row.dataset.threadRef = thread.thread_ref ?? '';
            row.dataset.lifecycle = thread.lifecycle ?? '';
            row.dataset.ended = String(thread.ended);
            row.dataset.animations = thread.animations.join(' ');
            // ⚠ `post`/`posts` is a COUNT LABEL and D3 publishes no wording for it — the same
            // gap § 5.7 property 4 names for a coordination age, one element over. It is
            // written here, in the layer that decides nothing, precisely so that no fact is
            // resting on it; § 14 item 17's closure act is what would ratify a string.
            row.textContent = [thread.label, thread.carrier,
                `${thread.beads} post${thread.beads === 1 ? '' : 's'}`, thread.received]
                .filter((v) => v !== null && v !== '')
                .join(' · ');

            return row;
        }));
    }

    return model;
}
