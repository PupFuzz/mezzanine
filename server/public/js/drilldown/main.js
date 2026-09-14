/**
 * The drill-down panel's thin DOM half — `docs/design/FLOOR.md § 4.3`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING, and that is the whole reason it is separate. There is no browser
 * on the build host, so nothing here is exercised by any check in this repository; every string,
 * every absence render and every selection is `drilldown-model.js`'s, which is pure and is
 * driven directly. If a rule appears below that is not in that module, it is in the wrong file.
 *
 * ⛔ NO FETCH LIVES HERE, AND THAT IS DELIBERATE. § 4.3 puts two requests on the panel's open and
 * § 4.4 names the route that makes them — `/floor/{install_id}/{seat_id}`, which is not built:
 * the floor screen is card#9208-blocked on a D2 read surface for an authored map, so nothing in
 * this deployment can open a panel. A `fetch` written here today would be code no page can
 * reach, wired to a route that does not exist, exercised by nothing. What this file owes that
 * route is a render function it can call with the two response bodies, and that is what it is.
 *
 * ⛔ NO ANIMATION. § 6.2 A12 eases the context bar over 250 ms on a delta whose `changed[]`
 * carries `context`; there is no delta-feed client in this repository, so there is no delta to
 * animate from, and § 6.5's "a snapshot never animates" covers the one input this file has.
 * The bar is written at its value, which is A12's own reduced-motion form.
 */

import { clockOffsetMs } from '../wire/duration.js';
import { drillDownModel } from './drilldown-model.js';

/** Text into a slot, or nothing when this panel does not carry that element. */
function put(root, selector, text) {
    const el = root.querySelector(selector);

    if (el !== null) {
        el.textContent = text ?? '';
    }

    return el;
}

/** A list slot, rebuilt from rows the model has already decided the text of. */
function putRows(root, selector, rows) {
    const list = root.querySelector(selector);

    if (list === null) {
        return;
    }

    list.replaceChildren(...rows.map((row) => {
        const li = root.ownerDocument.createElement('li');

        for (const [key, value] of Object.entries(row.data ?? {})) {
            li.dataset[key] = value === null || value === undefined ? '' : String(value);
        }

        li.textContent = row.text;

        return li;
    }));
}

/**
 * § 2.1 row 1's corrected clock: `server_time − browser_now`, applied to `browser_now`. It is
 * arithmetic over a value the response carries, not a judgement — which is why it is admitted
 * into this layer while nothing else is.
 *
 * `null` when the response carried no readable `server_time`: the model then renders no age at
 * all rather than one measured against the viewer's own machine clock, which § 2.4 admits at
 * exactly one place on this product and it is not here.
 */
function correctedNowMs(seat, browserNowMs) {
    const offset = clockOffsetMs(seat?.server_time ?? null, browserNowMs);

    return offset === null ? null : browserNowMs + offset;
}

/**
 * Render one seat's panel into `root`.
 *
 * `seat` is `GET /api/fleet/seats/{install_id}/{seat_id}`'s body (the seat object plus
 * `detail`); `timeline` is `…/timeline?limit=50`'s, or `null` when it has not been fetched.
 */
export function renderDrillDown(root, seat, timeline, options = {}) {
    const model = drillDownModel(seat, timeline, {
        now_ms: options.now_ms ?? correctedNowMs(seat, Date.now()),
        ref_bases: options.ref_bases ?? null,
    });

    put(root, '[data-panel-seat]', model.seat.seat_id);
    put(root, '[data-panel-floor]', model.seat.install_id);
    put(root, '[data-panel-state]', model.render_state.label);
    put(root, '[data-panel-clock]', model.no_clock_statement);

    const task = model.task;

    put(root, '[data-panel-task]', task.present ? task.title : task.statement);
    put(root, '[data-panel-task-source]', task.present ? task.source : '');
    put(root, '[data-panel-task-degraded]', task.present ? task.degraded_note : '');

    // § 5.2: the reference is a LINK only when a base URL is configured for its shape, and plain
    // text otherwise. The element is written either way; only its `href` differs.
    const ref = root.querySelector('[data-panel-task-ref]');

    if (ref !== null) {
        ref.textContent = task.present ? (task.ref ?? '') : '';

        if (task.present && task.ref_href !== null) {
            ref.setAttribute('href', task.ref_href);
        } else {
            ref.removeAttribute('href');
        }
    }

    const action = model.action;

    put(root, '[data-panel-action]', action.present
        ? (action.descriptor ?? action.tool_name)
        : action.statement);
    put(root, '[data-panel-action-started]', action.present ? action.started_at : '');
    put(root, '[data-panel-action-elapsed]', action.present ? action.elapsed : '');
    put(root, '[data-panel-action-scope]', action.present ? action.agent_scope : '');

    put(root, '[data-panel-quiet]', model.quiet_age.line);
    put(root, '[data-panel-last-kind]', model.quiet_age.last_kind);

    const context = model.context;

    put(root, '[data-panel-context]', context.reported ? context.pct : context.statement);
    put(root, '[data-panel-context-tokens]', context.reported ? context.numerals : '');
    put(root, '[data-panel-context-source]', context.reported ? context.source : '');
    put(root, '[data-panel-context-age]', context.reported ? context.age : '');

    // ⛔ THE BAR IS ABSENT WHEN THE SAMPLE IS (§ 5.6, § 7.5): a bar at 0 % is the one thing this
    // gauge may never draw, so the element's own value is removed rather than set to zero.
    const bar = root.querySelector('[data-panel-context-bar]');

    if (bar !== null) {
        if (context.bar === null) {
            bar.removeAttribute('value');
        } else {
            bar.setAttribute('value', String(context.bar));
        }
    }

    // § 8: the count is the wire's `subagents_open`, drawn beside the uncapped list and never
    // computed from it.
    put(root, '[data-panel-interns-open]', model.interns.open === null ? '' : String(model.interns.open));
    put(root, '[data-panel-interns-statement]', model.interns.statement);
    putRows(root, '[data-panel-interns]', model.interns.rows.map((intern) => ({
        data: { callId: intern.call_id, untitled: intern.untitled, type: intern.type },
        text: [intern.label, intern.type, intern.started_at]
            .filter((v) => v !== null && v !== '')
            .join(' · '),
    })));

    put(root, '[data-panel-activity-statement]', model.activity.statement);
    putRows(root, '[data-panel-activity]', model.activity.rows.map((row) => ({
        data: { kind: row.kind },
        text: [row.kind, row.event_time, row.received_at, row.age]
            .filter((v) => v !== null && v !== '')
            .join(' · '),
    })));

    return model;
}
