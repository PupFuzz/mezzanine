/**
 * WHICH THEME A FLOOR IS DRAWN IN — FLOOR.md § 10.6 item 5, and § 9 F23's notice when the layout names a theme the
 * build does not ship. Pure, DOM-free and importing nothing, so a page that loads none of the floor's scene machinery
 * (the lobby's) can resolve a floor's theme by the one rule the floor page draws by. `floor/scene.js` re-exports both
 * names, so a reader of the scene finds them where they were.
 */

/**
 * § 9 F23's notice, in § 5.5's words (card#11046, FLOOR.md § 10.6 item 5): the theme as the layout names
 * it, and the floor by its name — `label ?? key`, the one rendering rule § 4.6 states.
 */
export function unshippedThemeNotice(name, floorName) {
    return `floor theme \`${name}\` is not installed — drawn in the house theme — \`${floorName}\``;
}

/**
 * The theme a floor is drawn in (FLOOR.md § 10.6 item 5): the one its layout entry names when the registry
 * holds it; the house theme when it names none; and the house theme under § 9 F23's notice when it names
 * one the build does not ship. With no registry held (`themes` absent — it failed to load, which is § 9
 * F14's), nothing is resolved and nothing is claimed.
 *
 * @param {{theme: string|null, name: string}} floor the frame's floor
 * @param {{names: list<string>, house: string}|null|undefined} themes the registry's `THEMES` and `HOUSE_THEME`
 * @returns {{theme: string|null, notice: string|null}}
 */
export function floorTheme(floor, themes) {
    if (themes === null || themes === undefined) {
        return { theme: null, notice: null };
    }

    if (floor.theme === null || floor.theme === undefined) {
        return { theme: themes.house, notice: null };
    }

    return themes.names.includes(floor.theme)
        ? { theme: floor.theme, notice: null }
        : { theme: themes.house, notice: unshippedThemeNotice(floor.theme, floor.name) };
}
