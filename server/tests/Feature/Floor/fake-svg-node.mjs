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

    // The floor stack's moves (card#12194): a walker's node re-inserted before a sibling, or last for `null`. As the DOM
    // does, the move removes the node and inserts it anew — a probe counts the moves, and its animations are new.
    insertBefore(n, ref) {
        if (n.parentNode !== null) {
            n.parentNode.children = n.parentNode.children.filter((c) => c !== n);
        }

        const at = ref === null ? this.children.length : this.children.indexOf(ref);

        if (at === -1) {
            throw new Error('insertBefore: the reference node is not a child');
        }

        n.parentNode = this;
        this.children.splice(at, 0, n);
        n.animations = undefined;
        this.moves = (this.moves ?? 0) + 1;

        return n;
    }

    get nextSibling() {
        if (this.parentNode === null) {
            return null;
        }

        const siblings = this.parentNode.children;

        return siblings[siblings.indexOf(this) + 1] ?? null;
    }

    // Listeners are recorded so a probe can fire one (`dispatch`) — the painter's `<image>` error listener
    // is § 9 F14's third cause (card#11046).
    addEventListener(type, fn) { (this.listeners ??= {})[type] = [...(this.listeners?.[type] ?? []), fn]; }

    dispatch(type) { for (const fn of this.listeners?.[type] ?? []) fn({ type, target: this }); }

    contains() { return false; }

    focus() {}

    querySelector() { return null; }

    querySelectorAll() { return []; }

    // One animation per node, whose `startTime` the painter may pin (a bird flight's, card#12345) and a probe reads —
    // and whose effect's delay it may move (card#12457: `pin()` starts the animation at the frame's time and carries the
    // rest of its instant in the delay). The delay starts as the node's style wrote it — its first `animation-delay`, in
    // ms, 0 where it writes none — and `authored` keeps that value, so a probe can read the instant the pin counted the
    // authored delay from: `startTime + effect delay − authored`.
    getAnimations() {
        if (this.animations === undefined) {
            const written = /(?:^|;)animation-delay:\s*(-?[\d.]+)(m?s)/.exec(this.attrs.style ?? '');
            const authored = written === null ? 0 : Number(written[1]) * (written[2] === 's' ? 1000 : 1);
            const timing = { delay: authored };

            this.animations = [{
                startTime: null,
                authored,
                effect: { getTiming: () => ({ ...timing }), updateTiming: (t) => Object.assign(timing, t) },
            }];
        }

        return this.animations;
    }
}

/**
 * What a probe reads of a node's (first) animation: its `start`, its effect's `delay` as it stands and as the style
 * wrote it (`authored`), all in ms, and `pinned` — the instant on the timeline its authored delay is counted from
 * (`null` while it has no start). A node whose animation nothing asked for reads all `null`.
 */
export function animationOf(n) {
    const a = n.animations?.[0];

    if (a === undefined) {
        return { start: null, delay: null, authored: null, pinned: null };
    }

    const delay = a.effect.getTiming().delay;

    // To the microsecond: the subtraction of a delay read back in floating point is not exact.
    return { start: a.startTime, delay, authored: a.authored, pinned: a.startTime === null ? null : Math.round((a.startTime + delay - a.authored) * 1000) / 1000 };
}
