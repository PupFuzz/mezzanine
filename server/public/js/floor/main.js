/**
 * THE FLOOR PAGE's DOM ENTRY — `docs/design/FLOOR.md` Appendix B row 8, § 4.4's `/floor/{floor}`: the hoisted floor page
 * (`floor/floor-page.js`, which owns the page's wiring and states its rules) over the 2-D renderer
 * (`floor/painter-renderer.js`, `floor/painter.js`'s picture), on the 2-D route. Nothing else: a rule written here is in
 * the wrong file (`docs/design/plan-13033-stage2-3d-floor.md` § 4).
 */

import { startFloorPage } from './floor-page.js';
import { painterRenderer } from './painter-renderer.js';

startFloorPage(document, window, { renderer: painterRenderer(), route: '2d' });
