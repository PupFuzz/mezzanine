/**
 * The drill-down panel's thin DOM half — `docs/design/FLOOR.md § 4.3`, on the floor page.
 * Appendix B row 10, card#7342.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING, and that is the whole reason it is separate. There is no browser
 * on the build host, so nothing here is exercised by any check in this repository beyond a stub;
 * every string, every absence render and every selection is `drilldown-model.js`'s, which is pure
 * and is driven directly. If a rule appears below that is not in that module, it is in the wrong
 * file. What it does is put the model's strings into the `[data-panel-*]` slots the floor page
 * declares (`resources/views/floor.blade.php`), and hide a slot the model left empty — an absence
 * the model decided, drawn as an absence.
 *
 * ⛔ NO FETCH AND NO CLOCK LIVE HERE. The panel's two requests, its live patching and its stamps are
 * `drilldown-panel.js`'s, over the floor page's one client protocol; the floor page hands this file
 * the model the floor screen composed. `Tests\Feature\DrillDown\DrillDownModuleWiringTest` holds the
 * slot contract both ways against the floor page.
 *
 * ⛔ NO ANIMATION. § 6.2 A12 eases the context bar over 250 ms on a delta whose `changed[]` carries
 * `context`, on the DESK's gauge; the panel writes the bar at its value, which is A12's own
 * reduced-motion form, and § 6.5's "a snapshot never animates" covers the fetch it opened on.
 */

/** Text into a slot — hidden when the model left it empty — or nothing when the page has no such slot. */
function put(root, selector, text) {
    const el = root.querySelector(selector);

    if (el !== null) {
        el.textContent = text ?? '';
        el.hidden = text === null || text === undefined || text === '';
    }

    return el;
}

/** A list slot, rebuilt from rows the model has already decided the text of; hidden when empty. */
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
    list.hidden = rows.length === 0;
}

/** The model's own parts of one line, joined; a part the model left null is not drawn. */
function joined(parts) {
    return parts.filter((v) => v !== null && v !== undefined && v !== '').join(' · ');
}

/** `name: value` rows, or the model's statement for a list it could not source. */
function counterRows(rows) {
    return Array.isArray(rows) ? rows.map((row) => ({ data: { name: row.name }, text: `${row.name}: ${row.value}` })) : [];
}

/**
 * Render one open panel's model into `root`. The model is `drilldown-panel.js`'s `view()` —
 * `drilldown-model.js`'s `drillDownModel()` over the composed seat.
 */
export function renderDrillDown(root, model) {
    put(root, '[data-panel-seat]', model.header.seat_id ?? model.seat.seat_id);
    put(root, '[data-panel-floor]', model.header.floor);
    put(root, '[data-panel-state]', model.render_state.label);
    put(root, '[data-panel-line]', model.header.line);
    put(root, '[data-panel-currency]', model.header.currency);
    put(root, '[data-panel-clock]', model.no_clock_statement);

    const task = model.task;

    put(root, '[data-panel-task]', task.present ? task.title : task.statement);
    put(root, '[data-panel-task-source]', task.present ? task.source : null);
    // § 5.2: the reference is PLAIN TEXT, never a link — the slot is not an anchor.
    put(root, '[data-panel-task-ref]', task.present ? task.ref : null);
    put(root, '[data-panel-task-degraded]', task.present ? task.degraded_note : null);

    const action = model.action;

    put(root, '[data-panel-action]', action.present ? (action.descriptor ?? action.tool_name) : action.statement);
    put(root, '[data-panel-action-started]', action.present ? action.started_at : null);
    put(root, '[data-panel-action-elapsed]', action.present ? action.elapsed : null);
    put(root, '[data-panel-action-scope]', action.present ? action.agent_scope : null);

    put(root, '[data-panel-last-kind]', joined([model.quiet_age.last_kind, model.quiet_age.last_event_time]));

    const context = model.context;

    put(root, '[data-panel-context]', context.reported ? context.pct : context.statement);
    put(root, '[data-panel-context-tokens]', context.reported ? context.numerals : null);
    put(root, '[data-panel-context-source]', context.reported ? context.source : null);
    put(root, '[data-panel-context-age]', context.reported ? joined([context.sampled_at, context.age]) : null);

    // ⛔ THE BAR IS ABSENT WHEN THE SAMPLE IS (§ 5.6, § 7.5): a bar at 0 % is the one thing this
    // gauge may never draw, so the element's own value is removed rather than set to zero.
    const bar = root.querySelector('[data-panel-context-bar]');

    if (bar !== null) {
        if (context.bar === null) {
            bar.removeAttribute('value');
            bar.hidden = true;
        } else {
            bar.setAttribute('value', String(context.bar));
            bar.hidden = false;
        }
    }

    // § 8: the count is the wire's `subagents_open`, drawn beside the uncapped list and never
    // computed from it; an empty list is absent (AT-D3-14's panel half), not drawn empty.
    const interns = model.interns;

    put(root, '[data-panel-interns-open]', interns.listed && interns.open !== null ? String(interns.open) : null);
    put(root, '[data-panel-interns-statement]', interns.statement);
    putRows(root, '[data-panel-interns]', interns.rows.map((intern) => ({
        data: { callId: intern.call_id, untitled: intern.untitled, type: intern.type },
        text: joined([intern.label, intern.type, intern.untitled ? intern.call_id : null, intern.started_at]),
    })));

    const activity = model.activity;

    put(root, '[data-panel-activity-statement]', activity.statement);
    putRows(root, '[data-panel-activity]', activity.rows.map((row) => ({
        data: { kind: row.kind },
        text: joined([row.kind, row.event_time, row.received_at, row.age]),
    })));

    const transport = model.transport;

    put(root, '[data-panel-transport-asof]', transport.as_of);
    put(root, '[data-panel-receipt]', transport.receipt_age);
    put(root, '[data-panel-quiet]', transport.quiet_age);
    put(root, '[data-panel-heartbeat]', transport.heartbeat);
    put(root, '[data-panel-no-data-since]', transport.no_data_since);
    put(root, '[data-panel-skew]', transport.clock_skew);
    put(root, '[data-panel-spool]', transport.spool_lag_events);
    put(root, '[data-panel-oldest-unsent]', transport.oldest_unsent);
    put(root, '[data-panel-seq-epoch]', transport.seq_epoch);
    put(root, '[data-panel-last-seq]', transport.last_seq);

    const derivation = model.derivation;

    put(root, '[data-panel-derivation-asof]', derivation.as_of);
    put(root, '[data-panel-lag]', derivation.lag_line);
    put(root, '[data-panel-computed-at]', derivation.computed_at);
    put(root, '[data-panel-cursor]', derivation.cursor_event_id);

    const reporter = model.reporter;

    put(root, '[data-panel-reporter-asof]', reporter.as_of);
    put(root, '[data-panel-reporter-version]', reporter.version);
    put(root, '[data-panel-reporter-platform]', reporter.platform);
    put(root, '[data-panel-uptime]', reporter.uptime);
    put(root, '[data-panel-enabled]', reporter.enabled);
    putRows(root, '[data-panel-selftest]', reporter.selftest_failed.map((check) => ({ data: { check }, text: check })));

    const badges = model.badges;

    put(root, '[data-panel-badges-since]', badges.since);
    putRows(root, '[data-panel-badges]', badges.rows.map((badge) => ({
        data: { badge: badge.badge, recognised: badge.recognised },
        text: joined([
            badge.line,
            Array.isArray(badge.counters) ? badge.counters.map((c) => `${c.name}: ${c.value}`).join(', ') : badge.counters,
            badge.since_reporter_start,
        ]),
    })));

    const session = model.session;

    put(root, '[data-panel-session]', session.present ? session.session_id : session.statement);
    put(root, '[data-panel-session-started]', session.present ? session.started_at : null);
    put(root, '[data-panel-session-source]', session.present ? session.source : null);
    put(root, '[data-panel-session-project]', session.present ? session.project_label : null);
    put(root, '[data-panel-session-harness]', session.present ? session.harness_label : null);
    put(root, '[data-panel-model]', session.model_label);

    const counters = model.counters;

    put(root, '[data-panel-counters-asof]', counters.available ? counters.as_of : counters.statement);
    putRows(root, '[data-panel-counters]', counterRows(counters.server));
    put(root, '[data-panel-reporter-counters-since]', counters.reporter?.since ?? null);
    put(root, '[data-panel-reporter-counters-statement]', typeof counters.reporter?.rows === 'string' ? counters.reporter.rows : null);
    putRows(root, '[data-panel-reporter-counters]', counterRows(counters.reporter?.rows));
    put(root, '[data-panel-predicates-statement]', typeof counters.predicates === 'string' ? counters.predicates : null);
    putRows(root, '[data-panel-predicates]', counterRows(counters.predicates));

    put(root, '[data-panel-state-version]', model.raw.state_version);
    put(root, '[data-panel-raw-seq-epoch]', model.raw.seq_epoch);
    put(root, '[data-panel-raw-last-seq]', model.raw.last_seq);

    return model;
}
