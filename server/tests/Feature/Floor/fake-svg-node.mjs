/**
 * The fake DOM node the floor's painter probes paint into — exactly what `floor/painter.js` touches, and
 * nothing more. `painter-probe.mjs` (the desks) and `tile-seams-probe.mjs` (the tiles) both read what
 * `createPainter().paint()` wrote through it; each builds its own `document` around it, because what the
 * page's canvas answers is each probe's own question.
 */
export class Node {
    constructor(name) {
        this.name = name;
        this.attrs = {};
        this.children = [];
        this.parentNode = null;
        this.dataset = {};
        this.style = { setProperty() {} };
        this.textContent = '';
    }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    getAttribute(k) { return this.attrs[k] ?? null; }

    append(...nodes) { for (const n of nodes) { n.parentNode = this; this.children.push(n); } }

    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }

    addEventListener() {}

    contains() { return false; }

    focus() {}

    querySelector() { return null; }

    querySelectorAll() { return []; }
}
