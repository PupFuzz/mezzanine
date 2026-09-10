/**
 * The coordination layer's thin DOM half — `docs/design/FLOOR.md § 5.7`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING, and that is the whole reason it is separate. There is no browser
 * on the build host, so nothing here is exercised by any check in this repository; keeping every
 * FACT in `coord-model.js` is what keeps the unverifiable part free of decisions. If a rule
 * appears below that is not in that module, it is in the wrong file.
 *
 * ⛔ NO JOIN IS CONSTRUCTED HERE. `coordModel` takes an `options.join` and this file passes
 * NONE, because nothing in this deployment produces one: no wire event and no config carries a
 * protocol-agent-name → `seat_id` mapping (D2 § 8.3.3; card#7957's ruling (d) is still
 * unlanded). Synthesising one — from `seat_id` equality, from the roster's order, from a prefix
 * — is precisely the unvalidated-join-becoming-a-ratified-one that card#7957 was filed to
 * prevent, and it would be invisible on screen because a wrong line looks exactly like a right
 * one. So every participant renders `unresolved` today and no thread line is drawn.
 * `CoordDrawsNoLineWithoutAJoinTest` holds this file to that.
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
