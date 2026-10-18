/**
 * AT-D3-26's PANEL LEG — the cab's stop buttons (`docs/design/FLOOR.md` § 4.5, card#11795), `floor/cab-panel.js`
 * over a minimal fake HTML DOM. `node`, no dependencies. Driven by `Tests\Feature\Floor\TheCabPanelReconcilesByKeyTest`.
 *
 * Legs, each a record the test reads:
 *   - ORDER: three floors and the lobby reconciled — each button's key, name, current marking;
 *   - KEYED: the same stops again with one renamed, and with one removed while its button has focus — whether
 *     each surviving `<li>` is the same node, and where focus went;
 *   - PRESSES: a pointer click, a double click's second click, keyboard activations (`detail === 0`) before any
 *     fresh key, after a repeated keydown, after a fresh keydown, from a keyup's own activation and after it.
 *
 * argv: `--js <tree>` (default: the shipped tree). stdout: JSON `{ order, keyed, presses }`.
 */

import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const JS = args.includes('--js') ? args[args.indexOf('--js') + 1] : join(HERE, '..', '..', '..', 'public', 'js');

/** The few HTML element behaviours `cab-panel.js` uses, and nothing more. */
class El {
    constructor(doc, tag) {
        this.ownerDocument = doc;
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parent = null;
        this.attrs = {};
        this.dataset = {};
        this.listeners = {};
        this.className = '';
        this.hidden = false;
        this._text = '';
        this.classList = {
            toggle: (c, on) => {
                const set = new Set(this.className.split(' ').filter(Boolean));

                if (on) { set.add(c); } else { set.delete(c); }
                this.className = [...set].join(' ');
            },
            contains: (c) => this.className.split(' ').includes(c),
        };
    }

    set textContent(v) { this._text = String(v); this.children = []; }

    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }

    get firstElementChild() { return this.children[0] ?? null; }

    set type(v) { this.attrs.type = v; }

    append(...nodes) { for (const n of nodes) { n.parent?.removeChild(n); n.parent = this; this.children.push(n); } }

    removeChild(n) { this.children = this.children.filter((c) => c !== n); n.parent = null; }

    insertBefore(n, ref) {
        n.parent?.removeChild(n);
        n.parent = this;
        const i = ref === null ? this.children.length : this.children.indexOf(ref);
        this.children.splice(i < 0 ? this.children.length : i, 0, n);
        if (this.ownerDocument.activeElement !== null && n.contains(this.ownerDocument.activeElement)) {
            this.ownerDocument.activeElement = null; // a moved node loses focus, as a browser's does
        }
    }

    remove() { this.parent?.removeChild(this); if (this.contains(this.ownerDocument.activeElement)) { this.ownerDocument.activeElement = null; } }

    contains(n) { return n === this || this.children.some((c) => c.contains(n)); }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    getAttribute(k) { return this.attrs[k] ?? null; }

    removeAttribute(k) { delete this.attrs[k]; }

    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }

    /** An event fired at this element and bubbled to its ancestors. */
    dispatch(type, init = {}) {
        for (let at = this; at !== null; at = at.parent) {
            for (const fn of at.listeners[type] ?? []) {
                fn({ type, target: this, ...init });
            }
        }
    }

    focus() { this.ownerDocument.activeElement = this; }

    /** `.cab-name`, `.cab-here`, `button`, `button[aria-current="true"]` — the selectors the module asks. */
    querySelector(sel) {
        const all = [];
        const walk = (n) => { for (const c of n.children) { all.push(c); walk(c); } };

        walk(this);

        const m = /^button\[aria-current="true"\]$/.exec(sel);

        if (m) return all.find((n) => n.tagName === 'BUTTON' && n.attrs['aria-current'] === 'true') ?? null;
        if (sel === 'button') return all.find((n) => n.tagName === 'BUTTON') ?? null;
        if (sel.startsWith('.')) return all.find((n) => n.className.split(' ').includes(sel.slice(1))) ?? null;

        throw new Error(`the fake DOM answers no selector ${sel}`);
    }
}

const doc = { activeElement: null, createElement: (tag) => new El(doc, tag) };
const list = new El(doc, 'ul');

const { cabPanel } = await import(pathToFileURL(join(JS, 'floor', 'cab-panel.js')).href);

const chosen = [];
const panel = cabPanel(list, (key) => chosen.push(key));
const STOPS = [
    { key: 'aimla', name: 'aimla', href: '/floor/aimla', current: true },
    { key: 'kappa', name: 'kappa', href: '/floor/kappa', current: false },
    { key: 'zeta', name: 'the annex', href: '/floor/zeta', current: false },
    { key: null, name: 'Lobby', href: '/dashboard', current: false },
];

const read = () => list.children.map((li) => ({
    stop: li.dataset.stop,
    name: li.firstElementChild.querySelector('.cab-name').textContent,
    current: li.firstElementChild.getAttribute('aria-current') === 'true',
    marked: li.classList.contains('current'),
    here: !li.firstElementChild.querySelector('.cab-here').hidden,
}));

// ── ORDER ────────────────────────────────────────────────────────────────────────────────────────
panel.reconcile(STOPS);
const order = read();
const nodes = Object.fromEntries(list.children.map((li) => [li.dataset.stop, li]));

// ── KEYED ────────────────────────────────────────────────────────────────────────────────────────
panel.reconcile(STOPS.map((s) => (s.key === 'zeta' ? { ...s, name: 'the new annex' } : s)));
const renamed = read();
const kept = list.children.every((li) => nodes[li.dataset.stop] === li);

nodes.kappa.firstElementChild.focus();
panel.reconcile(STOPS.filter((s) => s.key !== 'kappa'));
const afterRemoval = { stops: list.children.map((li) => li.dataset.stop), focus: doc.activeElement?.parent?.dataset.stop ?? null, kept: list.children.every((li) => nodes[li.dataset.stop] === li) };

panel.reconcile(STOPS);

// ── PRESSES ──────────────────────────────────────────────────────────────────────────────────────
const button = (key) => list.children.find((li) => li.dataset.stop === key).firstElementChild;
const press = async (label, steps) => {
    chosen.length = 0;
    for (const step of steps) {
        if (step === 'tick') {
            await new Promise((resolve) => setTimeout(resolve, 0));
        } else {
            const [type, key, init] = step;

            button(key).dispatch(type, init);
        }
    }

    return [label, [...chosen]];
};

panel.arm();

const presses = Object.fromEntries([
    await press('pointer', [['click', 'zeta', { detail: 1 }]]),
    await press('double click second', [['click', 'zeta', { detail: 2 }]]),
    await press('lobby', [['click', '', { detail: 1 }]]),
    await press('keyboard before any key', [['click', 'zeta', { detail: 0 }]]),
    await press('held key repeating', [['keydown', 'zeta', { repeat: true, key: 'Enter' }], ['click', 'zeta', { detail: 0 }]]),
    await press('held Space released', [['keyup', 'zeta', { key: ' ' }], ['click', 'zeta', { detail: 0 }]]),
    await press('after the release', ['tick', ['click', 'zeta', { detail: 0 }]]),
]);

panel.arm();

Object.assign(presses, Object.fromEntries([
    await press('fresh key after arming', [['keydown', 'kappa', { repeat: false, key: 'Enter' }], ['click', 'kappa', { detail: 0 }]]),
]));

console.log(JSON.stringify({ order, renamed, kept, afterRemoval, presses }));
