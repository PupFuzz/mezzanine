/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsRideIsNavigationTest` reads the 3-D floor's elevator through
 * (`docs/design/FLOOR.md` § 4.5; `docs/design/plan-13033-stage2-3d-floor.md` § 3.8, card#13033 stage 2 slice 6). argv[2] is
 * the client's `wire/` directory (the shipped one or a MUTATED COPY of the tree); stdout is `{ "legs": { "<leg>": [defect, …] }, "read": { … } }`.
 *
 * ⛔ IT DRIVES THE SHIPPED MODULES OVER A STAND-IN DOCUMENT: `floor3d/floor-labels.js` synced with a world that has a band,
 * its elevator control pressed, keyed, focused and synced again; and `floor/cab-paint.js`'s band-leaf table read both ways —
 * as the 2-D floor's style (`rideLeaves()`) and as the 3-D floor's level (`rideLeafLevel()`). The vendored Three.js add-on
 * imports the bare `three`, which the page's import map resolves; here a resolve hook does the same.
 *
 * The legs:
 * - `control` — the elevator is the label layer's first element while the world has a band, and none without one: a
 *   `role="button"` tab stop named as the 2-D floor names it, `data-key="elevator"`; a click, Enter and Space each ride once
 *   and Tab rides nothing; Enter and Space have their default prevented; `aria-disabled` while a ride is in flight and not
 *   after; its focus hands the renderer the doorway's centre on the floor and names the elevator; a second sync neither
 *   takes it out of the page (which would drop the keyboard's focus) nor moves it from first.
 * - `leaves` — over every phase, at rest and under reduced motion: `rideLeafLevel()` is fully open where `rideLeaves()`
 *   stands the leaves open, shut where it draws none, and opening or closing (shut or open at the motion's start, the
 *   other at its end, moving between) where `rideLeaves()` animates `leaf-open` or `leaf-close`; `null` with no ride.
 *
 * ⚠ WHAT IT DOES NOT SEE: the pointer's press on the drawn doorway, which needs WebGL — `tools/design/floor3d.browser.mjs`'s
 * `ride` row presses it.
 */

import { registerHooks } from 'node:module';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const JS = join(process.argv[2], '..');

registerHooks({
    resolve: (specifier, context, next) => (specifier === 'three'
        ? { url: pathToFileURL(join(JS, 'vendor', 'three', 'three.module.js')).href, shortCircuit: true }
        : next(specifier, context)),
});

/** A stand-in element: what `floor-labels.js` and `CSS2DObject` touch, and nothing more. */
class Element {
    constructor(document, name) {
        this.ownerDocument = document;
        this.tagName = name.toUpperCase();
        this.attrs = {};
        this.children = [];
        this.parentNode = null;
        this.dataset = {};
        this.style = {};
        this.className = '';
        this.textContent = '';
        this.listeners = {};
        this.removals = 0;
    }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    getAttribute(k) { return this.attrs[k] ?? null; }

    removeAttribute(k) { delete this.attrs[k]; }

    toggleAttribute(k, on) { if (on) this.attrs[k] = ''; else delete this.attrs[k]; }

    get firstElementChild() { return this.children[0] ?? null; }

    insertBefore(n, ref) {
        n.parentNode?.children.splice(n.parentNode.children.indexOf(n), 1);
        n.parentNode = this;
        this.children.splice(ref === null ? this.children.length : this.children.indexOf(ref), 0, n);

        return n;
    }

    remove() {
        if (this.parentNode !== null) {
            this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1);
            this.parentNode = null;
            this.removals += 1;
        }
    }

    replaceChildren(...nodes) { this.children = []; nodes.forEach((n) => this.insertBefore(n, null)); }

    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }

    /** Fire `type` with `init`; answers whether a listener prevented its default. */
    dispatch(type, init = {}) {
        let prevented = false;

        for (const fn of this.listeners[type] ?? []) {
            fn({ type, target: this, ...init, preventDefault: () => { prevented = true; }, stopPropagation() {} });
        }

        return prevented;
    }

    getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; }
}

const document = { createElement: (name) => new Element(document, name), defaultView: { Element } };

const { floorLabels } = await import(pathToFileURL(join(JS, 'floor3d', 'floor-labels.js')).href);
const { rideLeaves, rideLeafLevel } = await import(pathToFileURL(join(JS, 'floor', 'cab-paint.js')).href);
const { ridePhase } = await import(pathToFileURL(join(JS, 'wire', 'ride.js')).href);
const { PIN, floorSlab } = await import(pathToFileURL(join(JS, 'floor', 'floor-world.js')).href);
const { Group } = await import(pathToFileURL(join(JS, 'vendor', 'three', 'three.module.js')).href);

const legs = { control: [], leaves: [] };
const read = {};

// ── control ─────────────────────────────────────────────────────────────────────────────────
{
    const d = legs.control;
    const container = new Element(document, 'div');
    const group = new Group();
    let rides = 0;
    const focusedAt = [];
    const labels = floorLabels(container, group, {
        select() {},
        link: (seat) => `/floor/aimla/3d/${seat}`,
        focused: (point, what) => focusedAt.push({ point, what }),
        ride: () => { rides += 1; },
    });
    const ELEVATOR = { x: 1.25, w: 1.5, y: 0, h: 2.25, z: -4 };
    const slab = floorSlab({ x: 0, y: 160, w: 1200, h: 900 });
    const desk = { key: 'aimla/aimla-pm', seat_id: 'aimla-pm', slab: { left: -1, right: 1, back: 0, front: 1, top: 0.75 } };
    const world = (band) => ({
        slab,
        band,
        desks: [desk],
        effects: [],
        labels: [{ key: desk.key, kind: 'name', text: 'aimla-pm', anchor: { x: 0, depth: 0, height: 0, beside: null, pin: PIN.centre } }],
    });

    labels.sync(world({ elevator: ELEVATOR }));
    const control = container.children[0];

    read.layer = container.children.map((e) => e.dataset.key);

    if (control?.dataset.key !== 'elevator') {
        d.push(`the label layer's first element is ${control?.dataset.key ?? 'nothing'}, not the elevator's control`);
    } else {
        if (control.getAttribute('role') !== 'button') d.push(`the elevator's control has role ${control.getAttribute('role')}, not button`);
        if (control.tabIndex !== 0) d.push(`the elevator's control has tabIndex ${control.tabIndex}, not 0 — it is no tab stop`);
        if (control.getAttribute('aria-label') !== 'the elevator — ride to another floor') d.push(`the elevator's control is named ${control.getAttribute('aria-label')}`);

        const presses = [];

        for (const [what, type, init] of [['a click', 'click', {}], ['Enter', 'keydown', { key: 'Enter' }], ['Space', 'keydown', { key: ' ' }], ['Tab', 'keydown', { key: 'Tab' }]]) {
            const before = rides;
            const prevented = control.dispatch(type, init);

            presses.push([what, rides - before, prevented]);
        }

        read.presses = presses;

        for (const [what, n, prevented] of presses) {
            const want = what === 'Tab' ? 0 : 1;

            if (n !== want) d.push(`${what} on the elevator rode ${n} times, not ${want}`);
            if ((what === 'Enter' || what === 'Space') && !prevented) d.push(`${what}'s default was not prevented`);
        }

        labels.riding(true);
        const during = control.getAttribute('aria-disabled');

        labels.riding(false);
        const after = control.getAttribute('aria-disabled');

        read.disabled = [during, after];
        if (during !== 'true') d.push(`a ride in flight leaves the control's aria-disabled at ${during}`);
        if (after !== null) d.push(`with no ride the control's aria-disabled is ${after}`);

        control.dispatch('focus');
        read.focused = focusedAt.at(-1) ?? null;
        const at = focusedAt.at(-1);

        if (at === undefined || at.what !== 'the elevator' || Math.abs(at.point.x - (ELEVATOR.x + ELEVATOR.w / 2)) > 1e-9 || Math.abs(at.point.z - ELEVATOR.z) > 1e-9) {
            d.push(`the control's focus handed the renderer ${JSON.stringify(at ?? null)}, not the doorway's centre on the floor`);
        }

        labels.sync(world({ elevator: ELEVATOR }));
        if (control.removals !== 0) d.push('a second sync took the elevator\'s control out of the page — the keyboard\'s focus would be dropped on every paint');
        if (container.children[0] !== control) d.push('a second sync moved the elevator\'s control from first');
        if (container.children.length !== 2) d.push(`the layer holds ${container.children.length} elements over one desk name and the elevator`);
    }

    labels.sync(world(null));
    if (container.children.some((e) => e.dataset.key === 'elevator')) d.push('a world with no band still carries the elevator\'s control');
}

// ── leaves ──────────────────────────────────────────────────────────────────────────────────
{
    const d = legs.leaves;
    const rows = [];

    if (rideLeafLevel(null, 0) !== null) d.push('with no ride the 3-D floor\'s leaves are not the walks\'');

    for (const reduce of [false, true]) {
        for (const phase of ['opening', 'entering', 'inside', 'chosen', 'leaving', 'staying', 'arriving']) {
            const timing = ridePhase(phase, reduce);
            const ride = { phase, elapsed_ms: 0, ...timing };
            const style = rideLeaves(ride);
            const at = (t) => rideLeafLevel(ride, t);
            const start = at((timing.door_delay_ms ?? 0) - 1);
            const end = at(timing.ms + 1);
            const mid = at(((timing.door_delay_ms ?? 0) + timing.ms) / 2);

            rows.push({ reduce, phase, style, start, mid, end });

            const want = style === null ? 'shut' : style.startsWith('transform:scaleX(.12)') ? 'open'
                : style.includes('leaf-open') ? 'opens' : style.includes('leaf-close') ? 'closes' : 'unknown';
            const got = !start.moving && !mid.moving && start.open === end.open ? (end.open === 1 ? 'open' : end.open === 0 ? 'shut' : 'unknown')
                : start.open === 0 && end.open === 1 && mid.moving && mid.open > 0 && mid.open < 1 ? 'opens'
                    : start.open === 1 && end.open === 0 && mid.moving && mid.open > 0 && mid.open < 1 ? 'closes' : 'unknown';

            if (want !== got) d.push(`${phase}${reduce ? ' (reduced)' : ''}: the 2-D leaves are ${want} (${style}) and the 3-D ones ${got}`);
            if (end.moving) d.push(`${phase}${reduce ? ' (reduced)' : ''}: the 3-D leaves are still moving after the phase`);
        }
    }

    read.leaves = rows;
}

process.stdout.write(JSON.stringify({ legs, read }));
