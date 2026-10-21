/**
 * AT-D3-26's ARRIVAL LEG — the layout's head script and `wire/ride-arrival.js` (`docs/design/FLOOR.md` § 4.5,
 * card#11795) under `node`, against stand-ins for every browser global the script reads. Driven by
 * `Tests\Feature\Floor\TheRideArrivalReadsOneNavigationTest`.
 *
 * stdin — JSON `{ "script": "<the head script's text, as the layout rendered it>", "module": "<path to
 *   ride-arrival.js>", "cases": [ { "hash", "navigationType", "reveals", "reduce", "hasPageRevealEvent",
 *   "reveals_fired": [ "transition" | "none", … ] } ] }`.
 * stdout — JSON `{ "cases": [ { "arrival", "arrival_at", "replaced_before", "reveals": [ { "skipped",
 *   "replaced", "timers", "classes" } ], "hash_after" } ], "module": { … } }`: for each case the record the script
 *   wrote at parse time, the `replaceState` calls it made before any reveal, and for each `pagereveal` fired
 *   (with a transition, or with `viewTransition: null`) whether the transition was skipped, the replaceState
 *   calls and timers it made, and `<html>`'s classes after it.
 *
 * The page's clock: `performance.now()` answers `T0` while the script parses and `T_REVEAL` inside a reveal, so a
 * bound armed from `t0` is `T0 + CAB_HOLD_MS − T_REVEAL` and one armed from the reveal is not.
 */

import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import vm from 'node:vm';

const T0 = 100;
const T_REVEAL = 130;
const input = JSON.parse(readFileSync(0, 'utf8'));

function runCase(c) {
    const classes = new Set();
    const attrs = new Set(c.reveals ? ['data-ride-reveals'] : []);
    const html = {
        dataset: {},
        hasAttribute: (a) => attrs.has(a),
        classList: {
            add: (k) => classes.add(k),
            replace: (a, b) => (classes.delete(a) ? (classes.add(b), true) : false),
            contains: (k) => classes.has(k),
        },
    };
    let clock = T0;
    const listeners = {};
    const replaced = [];
    const timers = [];
    const location = { hash: c.hash, pathname: '/floor/zeta', search: '?x=1' };
    const window = {};

    if (c.hasPageRevealEvent) {
        window.PageRevealEvent = function PageRevealEvent() {};
    }

    const context = vm.createContext({
        window,
        document: { documentElement: html },
        location,
        history: { replaceState: (state, title, url) => { replaced.push(url); location.hash = ''; } },
        performance: { now: () => clock, getEntriesByType: (t) => (t === 'navigation' ? [{ type: c.navigationType }] : []) },
        matchMedia: (q) => ({ matches: q === '(prefers-reduced-motion: reduce)' && c.reduce }),
        addEventListener: (type, fn) => { (listeners[type] ??= []).push(fn); },
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
    });

    // `'PageRevealEvent' in window` reads the browser's global object; here `window` is the stand-in.
    vm.runInContext(input.script, context);

    const record = {
        arrival: html.dataset.rideArrival ?? null,
        arrival_at: html.dataset.rideArrivalAt ?? null,
        replaced_before: [...replaced],
        listeners: Object.keys(listeners),
        reveals: [],
    };

    for (const kind of c.reveals_fired ?? []) {
        const before = { replaced: replaced.length, timers: timers.length };
        let skipped = 0;
        const viewTransition = kind === 'transition' ? { skipTransition: () => { skipped += 1; } } : null;

        clock = T_REVEAL;

        for (const fn of listeners.pagereveal ?? []) {
            fn({ viewTransition });
        }

        record.reveals.push({
            skipped,
            replaced: replaced.slice(before.replaced),
            timers: timers.slice(before.timers).map((t) => t.ms),
            classes: [...classes].sort(),
        });
    }

    // The bound, fired after the module half ended the hold: nothing changes.
    if (c.bound_after_module === true && timers.length > 0) {
        html.classList.replace('ride-holding', 'ride-arrived');
        timers[timers.length - 1].fn();
        record.after_bound = [...classes].sort();
    }

    record.hash_after = location.hash;

    return record;
}

const m = await import(pathToFileURL(input.module).href);
const element = (ride, at, classes = []) => {
    const set = new Set(classes);

    return {
        dataset: { rideArrival: ride, ...(at === undefined ? {} : { rideArrivalAt: String(at) }) },
        classList: { replace: (a, b) => (set.delete(a) ? (set.add(b), true) : false), add: (k) => set.add(k) },
        classes: () => [...set].sort(),
    };
};
const held = element('ride', T0, ['ride-holding']);
const arrived = element('ride', T0, ['ride-arrived']);
const never = element('none', undefined, []);

m.revealed(held);
m.revealed(arrived);
m.revealed(never);

console.log(JSON.stringify({
    cases: input.cases.map(runCase),
    module: {
        cab_hold_ms: m.CAB_HOLD_MS,
        arrival_ride: m.arrival(element('ride', T0), T_REVEAL + 20),
        arrival_none: m.arrival(element('none'), T_REVEAL + 20),
        revealed_held: held.classes(),
        revealed_arrived: arrived.classes(),
        revealed_never: never.classes(),
    },
    clock: { t0: T0, reveal: T_REVEAL },
}));
