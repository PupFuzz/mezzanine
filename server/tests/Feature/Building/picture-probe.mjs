/**
 * The floor picture's two pure functions (`building/floor-picture.js`), under `node` — plan § 8 item 3's *the rest*:
 * `tokenNames()` finds every token the painter's style reads, in both forms, and `pictureSvg()` writes each resolved one
 * once on the picture's root, leaves an empty one out, and sizes the root in place of the painter's percentages.
 * Driven by `Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest` over the shipped tree or a mutated copy
 * (argv[2], the module tree's `building/` directory); prints `{ legs: { picture: [defects] }, printed }`.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that the browser draws the picture with the palette it carries — the browser leg reads the
 * page's own canvas for that (`tools/design/building-view.browser.mjs`).
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const P = await import(pathToFileURL(join(dir, 'floor-picture.js')).href);
const { STYLE } = await import(pathToFileURL(join(dir, '..', 'floor', 'painter.js')).href);

const defects = [];
const check = (what, ok) => {
    if (!ok) {
        defects.push(what);
    }
};

// tokenNames(): both forms, each name once, and a name read bare even once is not a fallback-form token.
const names = P.tokenNames('a{fill:var(--ink)}b{fill:var(--paper, #fff)}c{stroke:var( --x)}d{fill:var(--ink)}e{fill:var(--mixed,red)}f{fill:var(--mixed)}');
const byName = new Map(names.map((t) => [t.name, t.hasFallback]));

check(`tokenNames() over the control sheet answered ${JSON.stringify(names)}`, names.length === 3 && byName.get('ink') === false && byName.get('paper') === true && byName.get('mixed') === false);

// Over the painter's own style: the bare tokens the sheet test holds to `:root`, and the cab's fallback-form carriers.
const style = P.tokenNames(STYLE);
const bare = new Set([...STYLE.matchAll(/var\(--([a-z0-9_-]+)\)/g)].map((m) => m[1]));
const fallback = new Set([...STYLE.matchAll(/var\(--([a-z0-9_-]+),/g)].map((m) => m[1]));

check('tokenNames() over the painter\'s style finds no token', style.length > 0);
check('CONTROL — the painter\'s style reads no fallback-form token, so the fallback branch reads nothing', fallback.size > 0);

for (const name of bare) {
    check(`tokenNames() misses the painter's bare token --${name}, or marks it a fallback`, style.some((t) => t.name === name && t.hasFallback === false));
}

for (const name of [...fallback].filter((n) => !bare.has(n))) {
    check(`tokenNames() misses the painter's fallback-form token --${name}`, style.some((t) => t.name === name && t.hasFallback === true));
}

check('tokenNames() names a token twice', new Set(style.map((t) => t.name)).size === style.length);

// pictureSvg(): the root sized, each non-empty token once on its style, an empty one left out, the body untouched.
const painted = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" class="floor-scene" viewBox="0 0 10 20"><rect class="chip" width="100%"/></svg>';
const out = P.pictureSvg(painted, [{ name: 'ink', value: '#3b2f2a' }, { name: 'gone', value: '' }, { name: 'quote', value: 'a"b' }], 1784, 864);
const root = /^<svg\b[^>]*>/.exec(out)?.[0] ?? '';

check(`pictureSvg()'s root is not sized ${'1784 × 864'}: ${root}`, /\swidth="1784"/.test(root) && /\sheight="864"/.test(root) && !root.includes('100%'));
check(`pictureSvg()'s root does not carry each token once: ${root}`, (root.match(/--ink:#3b2f2a/g) ?? []).length === 1 && (root.match(/--quote:a&quot;b/g) ?? []).length === 1);
check(`pictureSvg() writes an empty token: ${root}`, !root.includes('--gone'));
check('pictureSvg() changed the drawing under its root', out.endsWith('<rect class="chip" width="100%"/></svg>'));

process.stdout.write(JSON.stringify({ legs: { picture: defects }, printed: { painter_tokens: style.length, fallback_form: style.filter((t) => t.hasFallback).length } }));
