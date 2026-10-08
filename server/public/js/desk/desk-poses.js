/**
 * § 7.1's *Desk* column, as data — which pose, glyph, lighting and monitor each recognised
 * `render_state` draws (`docs/design/FLOOR.md § 7.1`). It lives in a module of its own, with no
 * imports, so that `wire/animation-set.js` can read the column's empty chair (§ 6.2's walk note item 1,
 * card#9566) without importing `desk/desk-render.js`, which imports the animation set: the lobby loads
 * the set on one path only, and a cycle through the desk render would put the set on a second one.
 */

/**
 * § 7.1's **Desk** column, as a picture per member: who is at the desk, in what pose, under what
 * light, and what the monitor shows. `glyph` is the state's own mark; `lighting` is § 7.3's
 * treatment column for the states it dims.
 *
 * ⛔ `stale` AND `offline` ARE THE EMPTY CHAIR, WITH NOBODY IN IT — never `idle`'s sleeper
 * (§ 7.5's *Asleep* bullet, AT-D3-5's third RED). "A sleeper is a *character*, an empty chair is
 * an *absence*, and neither needs the z's to be told from the other."
 */
export const DESK = Object.freeze({
    working: { pose: 'at-keyboard', glyph: 'working', lighting: 'full', monitor: 'on' },
    idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },
    blocked: { pose: 'raised-hand', glyph: 'attention', lighting: 'full', monitor: 'on' },
    stalled: { pose: 'head-in-hands', glyph: 'stalled', lighting: 'full', monitor: 'on' },
    unknown: { pose: 'present', glyph: 'question', lighting: 'full', monitor: 'on' },
    catching_up: { pose: 'present', glyph: 'replay', lighting: 'desaturated', monitor: 'on' },
    stale: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dimmed', monitor: 'on' },
    offline: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dark', monitor: 'on' },
    disabled: { pose: 'present', glyph: 'monitor-off', lighting: 'dimmed', monitor: 'off' },
});
