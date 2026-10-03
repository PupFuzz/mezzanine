/**
 * THE HARNESS's TEXT MEASURER — the one stand-in for `floor/painter.js`'s `measurer()` that every floor
 * probe hands the scene, so a fixture states the measurer and no probe writes a second one.
 *
 * ⛔ IT ANSWERS IN A TYPE ROLE, AS THE PAGE's DOES (`floor/desk-layout.js`'s `TYPE_ROLES`: the fact role
 * and the nameplate's name role, card#11058 Q2). A fixture states one glyph width and one line height PER
 * ROLE — `{ "glyph_w": { "fact": 6, "name": 8 }, "line_h": { "fact": 12, "name": 16 } }` — and a role the
 * fixture does not state is refused rather than measured at the fact role's width: a string measured in
 * one role and drawn in another is the defect a role exists to prevent, and a default here would hide it.
 *
 * `node`, no dependencies.
 */

/**
 * @param {{glyph_w: Object<string, number>, line_h: Object<string, number>}} spec the fixture's measurer
 * @returns {function(string, string): {w: number, h: number}}
 */
export function harnessMeasurer(spec) {
    const roles = Object.keys(spec?.glyph_w ?? {});

    if (roles.length === 0 || typeof spec.line_h !== 'object' || roles.sort().join() !== Object.keys(spec.line_h).sort().join()) {
        throw new TypeError(`a harness measurer states a glyph width and a line height per type role, each for the same roles: ${JSON.stringify(spec)}`);
    }

    return (text, role) => {
        if (!roles.includes(role)) {
            throw new TypeError(`the harness measurer has no type role ${JSON.stringify(role)} — the fixture states ${roles.join(', ')}`);
        }

        return { w: [...String(text)].length * spec.glyph_w[role], h: spec.line_h[role] };
    };
}
