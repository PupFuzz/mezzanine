// THE THEME REGISTRY — `docs/design/FLOOR.md § 10.6` item 1 (card#11046, Appendix B row 21): the closed
// set of themes the build ships, the house theme, the tile kinds every theme draws, and the names every
// theme module exports. A floor's layout entry names a theme (§ 4.6's `theme`); this file is what that
// name is held against.
//
// ⛔ THIS FILE IS DATA, AND EACH LINE BELOW IS PARSED BY PHP AS WRITTEN (`App\Floor\FloorThemes`, the
// precedent `furniture-box.js` set): one `export const NAME = Object.freeze([...]);` line per list and one
// `export const HOUSE_THEME = '...';` line, quoted names and nothing computed. An edit that computes a
// value is refused by name rather than read as a guess. The browser imports this module by the asset
// route (`/art/floor/themes/index.js`) and the harness from disk; PHP reads the same lines, so the two
// runtimes hold one registry.
//
// ⚠ ROW 21 SHIPS THE REGISTRY BEFORE ANY THEME DRAWS: `studio` is named here and its module,
// `themes/studio/theme.js`, arrives with Appendix B row 22, which is also where the registry is held to
// the `themes/` directories (AT-D3-25's theme half). Until then a floor resolves to a theme name and is
// drawn as it was.

export const THEMES = Object.freeze(['studio']);

export const HOUSE_THEME = 'studio';

export const KINDS = Object.freeze(['wall', 'accent', 'bookcase', 'plant', 'floor-lamp', 'armchair', 'cushion', 'book-pile']);

export const API = Object.freeze(['band', 'windowSurround', 'elevatorSurround', 'clockCase', 'plane', 'scenery', 'chair', 'desk', 'monitorFrame', 'deskProps', 'sideTable', 'PALETTE', 'surfaces']);
