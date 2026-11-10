<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\HoldsEveryColourMixToAFallback;
use Tests\TestCase;

/**
 * **AT-D3-26's painter leg — the elevator is a control on the drawing** (`docs/design/FLOOR.md` § 4.5,
 * card#11795; Appendix B row 23), over a fake DOM (`elevator-control-probe.mjs`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ELEVATOR IS A BUTTON OVER ITS SURROUND: `role="button"`, a tab stop, `data-key="elevator"` (so the
 * painter's focus restore keeps the keyboard on it across a repaint), an accessible name, and a hit area that
 * is the surround — frame, header and call buttons. A click, Enter and Space each ride once; Tab rides nothing.
 * While a ride is in flight it says so (`aria-disabled`).
 *
 * ⛔ THE LEAVES ARE THE RIDE's WHILE A RIDE HOLDS THEM, PER PHASE, AND NEVER RESTART. `opening` opens them,
 * placed at the phase's elapsed time (a negative delay); `entering` to `leaving` hold them open with no
 * animation; `staying` and `arriving` draw them open and close them once the phase's fade is over — a delay of
 * the door delay less the elapsed time. A second paint 300 ms later moves every delay by 300 ms, so a
 * re-paint mid-phase lands mid-motion. A walk's door span adds nothing while a ride holds the leaves, and
 * with no ride the walks' leaves are what they were.
 *
 * ⛔ THE CAB (`paintCab()`) draws two leaves — closing on `entering`, opening on `chosen`, each after the door
 * delay — and no text; `null` empties it.
 *
 * ⛔ THE CAB IS IN THE HOUSE THEME's ELEVATOR PALETTE, AND FITTED WHOLE (card#12039). `cabTheme()` carries the shipped
 * house theme's `PALETTE.elevator` frame and lamp onto the dialog as `--cab-frame` / `--cab-lamp` — the one carrier
 * the drawing and the button popup both read — and marks it `data-themed` (card#12047: what turns the popup from the
 * page's own panel to the frame); a painter holding no theme removes all three. The cab's frame and lamp
 * are painted from those properties, never an inline colour; its space is fitted with `meet`, never cropped with
 * `slice`, its overflow hidden so nothing is drawn past the cab (card#12051), and its shape handed to the layout as
 * `--cab-aspect`. That the whole cab is then IN THE WINDOW is a browser's to measure: `tools/design/floor-ride.browser.mjs`'s
 * `cab-fit` row, run by hand.
 *
 * ⛔ THE CAB IS A ROOM IN DEPTH (card#12051): a one-point perspective of § 12's *Cab interior* (`cab-paint.js`'s `CAB`
 * and `LANDING`, held equal to that row here) — a space taller than it is wide, the back wall carrying the door where
 * the row's eye puts it and inset on every side of the cab's opening, two side walls each a trapezoid whose far edge is shorter than its near one, a
 * ceiling over the back wall and a floor under it, each painted by its class from `--cab-frame`, never inline, and each
 * with its depth overlay — the back wall's an even one (card#12052: § 4.5 says every surface is shaded); the leaves on the
 * back wall; and the layer behind the leaves (`.cab-beyond`) over the doorway's recess and under the leaves.
 * `drawCabInterior()` draws the same room, and no door, into any box it is handed, and answers its back wall as it drew it.
 *
 * ⛔ CLOSER, WIDER, AND A CALL PLATE THAT IS SCENERY (card#12071). The rider's eye stands close enough that the doorway takes
 * at least 1.2 times the share of the view it took from card#12051's eye; the band's cab is seen from the landing's eye,
 * not the rider's. Beside the door's frame is one decorative call plate — buttons and an indicator window, aria-hidden,
 * with no role, tab stop, handler or pointer, and no word, datum attribute, colour of its own or motion — and the band's
 * cab has none (§ 6.3: the real panel is the popup).
 *
 * ⛔ THROUGH THE OPEN DOORS (card#12052; at a floor's stop that floor, card#12987). In the cab, the layer behind the leaves
 * holds the stop's floor (`drawFloorView()`, over the model the ride carries), clipped to the doorway the leaves span — or the
 * bare room (`drawOfficeView()`: a floor, a ceiling and its lights, two walls and a far one, nothing standing) — SCENERY, so
 * every element in it is the view's own class, every face's class stroking its own fill, and none carries a word, a datum
 * attribute, a colour of its own or any motion (§ 6.3); its two declared exceptions are checked: the root group's `style`
 * is the theme's `--dv-…` carriers alone, and an `<image>` is a standing piece's own theme document. The door-view leg
 * (`door-view-leg.mjs`) rides from `alpha` through the plan's § 6 scenarios: the floor in the stop's theme, its walls on
 * the side the rider's frame puts them, its pieces in the one order, the bare room where the stop's data is not wholly
 * held, the cab repainted once when a model lands (woken by the press's own fetch), and no layout fetched at the press. On the band, `drawCabInterior()`'s room sits behind the leaves,
 * clipped to the doorway, with ids of its own, in the house theme's elevator palette when it loaded and the sheet's
 * door frame and lamp when not, still, and the scene it was painted from — `scene.doors` included — unchanged. NOTHING
 * IS DRAWN AFTER THE LEAVES in the cab's drawing, nor in the band's elevator control but its hit area: the seam is the
 * leaves' own edges, so no seam line of either stands in the open doorway — a structural reading; that nothing at all
 * crosses the open doorway as drawn is `floor-ride.browser.mjs`'s `through-doors` row's, run by hand. And every
 * `color-mix()` in `STYLE` sits in its `@supports` block after a plain rule of the same selector setting the same property
 * to one of the mix's own colours, the fallback a browser without `color-mix()` paints (`HoldsEveryColourMixToAFallback`,
 * the one check the sheet is held to as well).
 *
 * ⛔ THE VIEW BEHIND THE DOORS IS THE RIDE's `beyond`, AND AT THE LOBBY IT IS THE LOBBY (card#12113). `paintCab()` — a module
 * function since card#12113, which the painter hands on and a page with no painter calls itself — draws `drawLobbyView()`'s
 * lobby for `beyond: lobby` and the stop's floor (`drawFloorView()`) for `office`, in every phase whose doors move: front doors under
 * an arch, a window either side, a mat, a boarded floor, the ceiling's grid of panels at § 12's centres, the rail and the
 * skirting, clipped to the doorway, scenery under the office's rule — its daylight fixed, no A17 sky. `LOBBY_VIEW` is § 12's
 * *Lobby view* row, and the module's `cabTheme()` carries what the painter's does.
 *
 * ⛔ THE RIDE's DOORS MOVE CONTINUOUSLY, AND THE WALKS' STEP (card#12038). Every ride motion — band and cab — eases
 * (`ease-in-out`) over the length the phase hands it, and no ride style carries `steps(`: the ride is § 6.3's
 * exception, which is not claim-bearing, so no loop rate binds it (§ 12's *Ride door motion*). The walks' leaves are
 * claim-bearing and keep § 12's *Elevator leaves* frames, read out of the document, at the loop rate with `steps()`.
 */
class TheElevatorIsAControlOnTheDrawingTest extends TestCase
{
    use DrivesAShippedClientModule;
    use HoldsEveryColourMixToAFallback;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/elevator-control-probe.mjs';
    }

    public function test_the_elevator_is_a_button_over_its_surround(): void
    {
        $this->assertSame([], $this->controlDefects($this->run_()));
    }

    public function test_the_band_leaves_follow_each_phase_without_restarting(): void
    {
        $this->assertSame([], $this->leafDefects($this->run_()));
    }

    public function test_the_cab_draws_two_leaves_and_no_words(): void
    {
        $this->assertSame([], $this->cabDefects($this->run_()));
    }

    public function test_the_cab_is_in_the_house_elevator_palette_and_fitted_whole(): void
    {
        $this->assertSame([], $this->paletteDefects($this->run_()));
    }

    public function test_the_cab_is_a_room_in_depth(): void
    {
        $this->assertSame([], $this->depthDefects($this->run_()));
    }

    public function test_the_cab_doors_open_onto_the_floor(): void
    {
        $this->assertSame([], $this->officeDefects($this->run_()));
    }

    /**
     * ⛔ THROUGH THE OPEN DOORS AT A FLOOR IS THAT FLOOR (card#12987; the plan's § 6): the ride from `alpha` draws alpha's floor
     * while the doors close and bravo's once bravo is chosen — each in its own theme's carriers, its walls on the side the
     * rider's frame puts them, its desks empty, its standing pieces the theme's own documents in the one order — and the bare
     * room where the client does not wholly hold the stop's data (S3–S7); a model that lands while the doors open repaints the
     * cab once, woken by the press's own fetch (S4); the press fetches no layout (S8); and `FloorData#roomTilesets()` loads
     * one room's tilesets and enters no room (S9, card#13033).
     */
    public function test_the_floor_behind_the_doors_is_that_floors(): void
    {
        $this->assertSame([], $this->doorViewDefects($this->run_()));
    }

    /** § 12's *Door-view scale* and *Partition height* rows are `door-view.js`'s `SCALE`, `ROOM_H` and `PARTITION_H`. */
    public function test_green_section_12s_door_view_is_door_view(): void
    {
        $this->assertSame([], $this->doorViewFigureDefects($this->run_()));
    }

    public function test_the_floors_doors_open_onto_the_cab(): void
    {
        $this->assertSame([], $this->bandDefects($this->run_()));
    }

    public function test_nothing_is_drawn_after_the_leaves(): void
    {
        $this->assertSame([], $this->seamDefects($this->run_()));
    }

    public function test_every_mixed_colour_has_a_plain_fallback(): void
    {
        $this->assertSame([], $this->mixDefects($this->run_()));
    }

    /**
     * ⛔ THE VIEW BEHIND THE DOORS IS THE RIDE's `beyond` (card#12113): with `beyond: lobby` the cab's layer behind its
     * leaves holds the lobby and no office, with `beyond: office` the office and no lobby — in every phase whose doors
     * move, through the module's `paintCab()` (the path a page with no painter takes) and the painter's alike.
     */
    public function test_the_cab_doors_open_onto_the_view_the_ride_names(): void
    {
        $this->assertSame([], $this->beyondDefects($this->run_()));
    }

    /**
     * ⛔ THROUGH THE OPEN DOORS AT THE LOBBY IS THE LOBBY (card#12113): `drawLobbyView()` clipped to the doorway the leaves
     * span — a ceiling with its grid of panels, a boarded floor, two side walls with their rail and skirting, the street
     * wall with the front doors under their arch, a window either side and the mat before the doors — SCENERY, so every
     * element in it is the view's own class and none carries a word, an image, a datum attribute, a colour of its own or
     * any motion, and its daylight is fixed: no A17 sky class.
     */
    public function test_the_cab_doors_open_onto_the_lobby(): void
    {
        $this->assertSame([], $this->lobbyDefects($this->run_()));
    }

    /** § 12's *Lobby view* row is `cab-paint.js`'s `LOBBY_VIEW` (re-exported by `painter.js`), figure for figure. */
    public function test_green_section_12s_lobby_view_is_lobby_view(): void
    {
        $this->assertSame([], $this->lobbyViewDefects($this->run_()));
    }

    /** The module's `cabTheme()`, which the lobby page calls with the palette it loaded, carries what the painter's does. */
    public function test_the_modules_cab_theme_is_the_painters(): void
    {
        $this->assertSame([], $this->themeDefects($this->run_()));
    }

    /** § 12's *Office view* row is `cab-paint.js`'s `OFFICE` (re-exported by `painter.js`), figure for figure. */
    public function test_green_section_12s_office_view_is_office(): void
    {
        $this->assertSame(1, preg_match('/^\| \*\*Office view\*\* \| \*\*(\d+) × (\d+) × (\d+) cm\*\* wide × deep × high, its left wall (\d+) cm left of the door\'s centre line, the eye (\d+) cm from the doorway \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Office view* row in the form this test reads');

        $this->assertSame(['w' => (int) $m[1], 'd' => (int) $m[2], 'h' => (int) $m[3], 'left' => (int) $m[4], 'view' => (int) $m[5]], $this->run_()['office']['office']);
    }

    /**
     * § 12's *Cab interior* row is `cab-paint.js`'s `CAB` and `LANDING`, figure for figure — the cab's width, the rider's eye
     * (its height, its distance from the doors' wall and the frame's from it) included (card#12071).
     */
    public function test_green_section_12s_cab_interior_is_cab(): void
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab interior\*\* \| \*\*(\d+) × (\d+) × (\d+) cm\*\* wide × deep × high, the eye (\d+) cm over the floor and (\d+) cm from the doors\' wall, the drawing\'s frame (\d+) cm from the eye, the door (\d+) × (\d+) cm; from the landing, the eye (\d+) cm from the cab\'s open front \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab interior* row in the form this test reads');

        $depth = $this->run_()['depth'];

        $this->assertSame(
            ['w' => (int) $m[1], 'd' => (int) $m[2], 'h' => (int) $m[3], 'eye' => (int) $m[4], 'back' => (int) $m[5], 'view' => (int) $m[6], 'door' => ['w' => (int) $m[7], 'h' => (int) $m[8]]],
            $depth['cab'],
        );
        $this->assertSame(['view' => (int) $m[9], 'back' => (int) $m[9] + (int) $m[2]], $depth['landing']);
    }

    /**
     * ⛔ THE CALL PLATE IS SCENERY (card#12071): one plate, beside the door's frame on the doors' wall, with its buttons and
     * its indicator window — and aria-hidden, with no role, tab stop or handler, taking no pointer, and carrying no word,
     * datum attribute, colour of its own or motion. The band's cab has none.
     */
    public function test_the_call_plate_beside_the_doors_is_inert_scenery(): void
    {
        $this->assertSame([], $this->plateDefects($this->run_()));
    }

    /**
     * ⛔ THE RIDER STANDS CLOSER TO THE DOORS THAN card#12051's EYE DID (card#12071): the doorway the leaves span takes at
     * least a fifth more of the cab's view than it did from that eye — 230 cm in front of a 160 × 140 × 230 cm cab's open
     * front, which drew the doors' wall at 230 / (230 + 140) of the opening.
     */
    public function test_the_doors_take_more_of_the_view_than_before(): void
    {
        $this->assertSame([], $this->closerDefects($this->run_()));
    }

    /** No ride door style — the band's in any phase, the cab's in any phase — is quantised with `steps(` (card#12038). */
    public function test_the_ride_doors_never_step(): void
    {
        $run = $this->run_();

        $this->assertSame([], $this->steppedDefects($run));
        $this->assertNotSame([], array_filter($this->rideStyles($run), static fn ($s) => str_contains((string) $s, 'animation:')),
            'no ride style carried an animation — the check measured nothing');
    }

    /** ⛔ THE CONTROLS — each plants one defect in a copy of the shipped painter. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        foreach ([
            'no role' => ["                role: 'button',\n                'aria-label': 'the elevator", "                'aria-label': 'the elevator", 'controlDefects'],
            'no tab stop' => ["                'data-key': 'elevator',\n                tabindex: 0,", "                'data-key': 'elevator',", 'controlDefects'],
            'no key' => ["                'data-key': 'elevator',", "                'data-key': 'lift',", 'controlDefects'],
            'no name' => ["                'aria-label': 'the elevator — ride to another floor',", '', 'controlDefects'],
            'Space does not ride' => ["            control.addEventListener('keydown', (event) => {\n                if (event.key === 'Enter' || event.key === ' ') {", "            control.addEventListener('keydown', (event) => {\n                if (event.key === 'Enter') {", 'controlDefects'],
            'the hit area the frame' => ['node(\'rect\', { x: e.surround.x, y: e.surround.y, width: e.surround.w, height: e.surround.h, class: \'elevator-hit\' }', 'node(\'rect\', { x: e.frame.x, y: e.frame.y, width: e.frame.w, height: e.frame.h, class: \'elevator-hit\' }', 'controlDefects'],
            'never disabled' => ["                'aria-disabled': riding ? 'true' : null,", '', 'controlDefects'],
            'no negative delay' => ['    return { delay: doorDelay - ride.elapsed_ms, motion: ride.ms - doorDelay };', '    return { delay: Math.max(0, doorDelay - ride.elapsed_ms), motion: ride.ms - doorDelay };', 'leafDefects'],
            'the delay without the door offset' => ['    return { delay: doorDelay - ride.elapsed_ms, motion: ride.ms - doorDelay };', '    return { delay: -ride.elapsed_ms, motion: ride.ms - doorDelay };', 'leafDefects'],
            'the leaves closing inside' => ["            // `entering`, `inside`, `chosen`, `leaving`: the floor's leaves stand open behind the cab.\n            return 'transform:scaleX(.12)';",
                '            return `animation:leaf-close ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms both`;', 'leafDefects'],
            'a walk adding to the ride' => ["            const leaves = riding\n                ? rideLeaves(scene.ride)", "            const leaves = riding && (scene.doors ?? []).length === 0\n                ? rideLeaves(scene.ride)", 'leafDefects'],
            'a word in the cab' => ["    const style = cabLeaves(ride);\n", "    const style = cabLeaves(ride);\n\n    node('text', {}, cab).textContent = 'B';\n", 'cabDefects'],
            // card#12038's RED: the ride's doors quantised again — by any step count — red the step check itself.
            'the ride doors stepped' => ["const RIDE_DOOR_EASING = 'ease-in-out';", "const RIDE_DOOR_EASING = 'steps(2)';", 'steppedDefects'],
            'the ride doors stepped, the cab alone' => ["        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING}',
                "        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms steps(4)', 'steppedDefects'],
            'the band doors linear' => ['            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms forwards`;',
                '            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms linear ${delay}ms forwards`;', 'leafDefects'],
            // card#12039's REDs: the cab cropped again, the palette not carried, a stale palette kept, the frame or the
            // lamp painted off the carrier.
            'the cab sliced' => ["preserveAspectRatio: 'xMidYMid meet'", "preserveAspectRatio: 'xMidYMid slice'", 'paletteDefects'],
            'the palette not carried' => ["    el.style.setProperty('--cab-frame', palette.frame);\n", '', 'paletteDefects'],
            'a stale palette kept' => ["        el.style.removeProperty('--cab-lamp');\n", '', 'paletteDefects'],
            // card#12047's: the dialog never marked themed, or marked with no theme loaded.
            'the dialog not marked' => ["    el.toggleAttribute('data-themed', palette !== null);\n", '', 'paletteDefects'],
            'marked with no theme' => ["    el.toggleAttribute('data-themed', palette !== null);", "    el.toggleAttribute('data-themed', true);", 'paletteDefects'],
            'the frame off the palette' => ['.cab-frame{fill:var(--cab-frame);', '.cab-frame{fill:var(--door-frame);', 'paletteDefects'],
            'the lamp off the palette' => ['.cab-lamp{fill:var(--cab-lamp)}', '.cab-lamp{fill:var(--lamp)}', 'paletteDefects'],
            // card#12051's: the cab flattened back to a front-on door, its side walls gone or off the palette, drawn as
            // wide as a room, painted past its opening, or with no layer behind its leaves.
            'the cab flattened' => ['        const s = lens.view / (lens.view + Z);', '        const s = 1;', 'depthDefects'],
            'no side walls' => ["    face('cab-side left', ", "    false && face('cab-side left', ", 'depthDefects'],
            'a side wall off the palette' => ['.cab-side{fill:color-mix(in srgb,var(--cab-frame) 88%,var(--door))}', '.cab-side{fill:var(--door)}', 'depthDefects'],
            'the ceiling inline' => ["    face('cab-ceiling', pts(", "    node('polygon', { fill: 'tan', points: pts(at(l, top, 0)) }, g).setAttribute('class', 'cab-ceiling');\n    face('cab-ceiling-gone', pts(", 'depthDefects'],
            'the cab as wide as a room' => ['export const CAB = Object.freeze({ w: 200,', 'export const CAB = Object.freeze({ w: 400,', 'depthDefects'],
            'no layer behind the leaves' => ["    BEYOND[ride.beyond](node('g', { class: 'cab-beyond' }, cab), L, `cab-\${ride.beyond}`, ride.view);\n", '', 'depthDefects'],
            'the layer over the leaves' => ["    BEYOND[ride.beyond](node('g', { class: 'cab-beyond' }, cab), L, `cab-\${ride.beyond}`, ride.view);\n\n    const style = cabLeaves(ride);\n\n    // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.\n    node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);\n    node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n",
                "\n    const style = cabLeaves(ride);\n\n    // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.\n    node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);\n    node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n    BEYOND[ride.beyond](node('g', { class: 'cab-beyond' }, cab), L, `cab-\${ride.beyond}`, ride.view);\n", 'depthDefects'],
            'the cab painted past its opening' => ["preserveAspectRatio: 'xMidYMid meet', overflow: 'hidden'", "preserveAspectRatio: 'xMidYMid meet', overflow: 'visible'", 'paletteDefects'],
            'the room alone not answering its back wall' => ['    return { back };', '    return { back: { x: 0, y: 0, w: box.w, h: box.h } };', 'depthDefects'],
            // card#12052's: the ceiling or the back wall left unshaded (§ 4.5 says every surface deepens toward the back).
            'the ceiling unshaded' => ["at(l, top, D)), 'shade-ceiling');", 'at(l, top, D)));', 'depthDefects'],
            'the back wall unshaded' => ["class: 'cab-shade cab-back-shade', 'fill-opacity': FAR }, g);", "class: 'cab-back-shade-gone', 'fill-opacity': FAR }, g);", 'depthDefects'],
            // card#12052's: the office missing, unclipped, peopled, named, painted inline or moving; the band's cab missing,
            // unclipped, over the leaves, moving, adding a door span, sharing the dialog's ids or off the palette; a seam line
            // over either open doorway; a mixed colour with no fallback, or mixing outside its block.
            'the office not drawn' => ["    BEYOND[ride.beyond](node('g', { class: 'cab-beyond' }, cab), L, `cab-\${ride.beyond}`, ride.view);", "    node('g', { class: 'cab-beyond' }, cab);", 'officeDefects'],
            'the floor unclipped' => ["    const room = clippedTo(g, defs, box, id);\n    const poly = ", "    const room = g;\n    const poly = ", 'officeDefects'],
            'a character in the floor' => ["            drawBody(room, at, pts, b);\n        }\n    }\n\n    return { clip: box };", "            drawBody(room, at, pts, b);\n        }\n    }\n    node('image', { class: 'character', href: 'data:,' }, room);\n    return { clip: box };", 'officeDefects'],
            'a name in the floor' => ["            drawBody(room, at, pts, b);\n        }\n    }\n\n    return { clip: box };", "            drawBody(room, at, pts, b);\n        }\n    }\n    node('text', { class: 'dv-plate' }, room).textContent = 'aimla';\n    return { clip: box };", 'officeDefects'],
            'an inline hex in the floor' => ["    poly('dv-floor', at(x1, 0, z1)", "    node('polygon', { points: pts(at(x1, 0, z1)), fill: '#c0ffee', class: 'dv-rug' }, room);\n    poly('dv-floor', at(x1, 0, z1)", 'officeDefects'],
            'the floor moving' => ["            drawBody(room, at, pts, b);\n        }\n    }\n\n    return { clip: box };", "            drawBody(room, at, pts, b);\n        }\n    }\n    node('animateTransform', { attributeName: 'transform', type: 'translate', dur: '4s' }, room);\n    return { clip: box };", 'officeDefects'],
            'the bare room unclipped' => ["    viewShades(defs, id, 'dv-shade-stop', { 'shade-left': 0.22, 'shade-right': 0.22, 'shade-floor': 0.2, 'shade-ceiling': 0.18 });\n\n    const room = clippedTo(g, defs, box, id);\n    const face = ", "    viewShades(defs, id, 'dv-shade-stop', { 'shade-left': 0.22, 'shade-right': 0.22, 'shade-floor': 0.2, 'shade-ceiling': 0.18 });\n\n    const room = g;\n    const face = ", 'officeDefects'],
            'a desk in the bare room' => ["    face('dv-floor', pts(at(l, 0, 0)", "    face('dv-desk', pts(at(-10, 76, 240), at(130, 76, 240), at(130, 76, 320)));\n    face('dv-floor', pts(at(l, 0, 0)", 'officeDefects'],
            'a face with no anti-seam stroke' => ['.dv-partition{fill:var(--dv-partition,color-mix(in srgb,var(--scene-paper) 70%,var(--house-wall-2)));stroke:var(--dv-partition,color-mix(in srgb,var(--scene-paper) 70%,var(--house-wall-2)));stroke-width:.5}', '.dv-partition{fill:var(--dv-partition,color-mix(in srgb,var(--scene-paper) 70%,var(--house-wall-2)))}', 'officeDefects'],
            'no cab behind the band\'s leaves' => ["            drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);\n", '', 'bandDefects'],
            'the band\'s cab unclipped' => ["cabLayer = clippedTo(inside, node('defs', {}, inside), doorway, 'band-cab');", 'cabLayer = inside;', 'bandDefects'],
            'the band\'s cab over the leaves' => ["class: 'elevator-leaf right', style: leaves }, control);\n", "class: 'elevator-leaf right', style: leaves }, control);\n            drawCabInterior(control, doorway, 'band-cab-over');\n", 'bandDefects'],
            'the band\'s cab moving' => ["            drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);\n", "            drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);\n            node('animate', { attributeName: 'opacity', dur: '1s' }, inside.children[1]);\n", 'bandDefects'],
            'the band\'s cab adding a door span' => ["            const doorway = { x: e.x, y: e.y, w: e.w, h: e.h };\n", "            const doorway = { x: e.x, y: e.y, w: e.w, h: e.h };\n            (scene.doors ??= []).push({ from: 0, to: 1 });\n", 'bandDefects'],
            'the band\'s cab sharing the dialog\'s ids' => ["drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);", "drawCabInterior(cabLayer, doorway, 'cab', LANDING);", 'bandDefects'],
            // card#12071's: the band's cab seen from the rider's eye inside it, not the landing's; the call plate named,
            // focusable, clickable, lit, moving, missing, or drawn on the band's cab; the rider's eye put back where card#12051 had it.
            'the band\'s cab from the rider\'s eye' => ["drawCabInterior(cabLayer, doorway, 'band-cab', LANDING);", "drawCabInterior(cabLayer, doorway, 'band-cab');", 'bandDefects'],
            'a floor name on the plate' => ["    node('circle', { cx: x + w / 2, cy: y + 52 * k, r: 1.6 * k, class: 'cab-plate-keyhole' }, g);\n", "    node('circle', { cx: x + w / 2, cy: y + 52 * k, r: 1.6 * k, class: 'cab-plate-keyhole' }, g);\n    node('text', { class: 'cab-plate-label' }, g).textContent = 'kappa';\n", 'plateDefects'],
            'the plate focusable' => ["    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true' }, parent);", "    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true', tabindex: 0 }, parent);", 'plateDefects'],
            'the plate a button' => ["    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true' }, parent);", "    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true', role: 'button' }, parent);\n    g.addEventListener('click', () => {});", 'plateDefects'],
            'the plate read aloud' => ["    const g = node('g', { class: 'cab-plate', 'aria-hidden': 'true' }, parent);", "    const g = node('g', { class: 'cab-plate' }, parent);", 'plateDefects'],
            'the plate taking a pointer' => ['.cab-plate{pointer-events:none}', '.cab-plate{cursor:pointer}', 'plateDefects'],
            'a stop on the plate' => ["class: 'cab-plate-button' }, g);", "class: 'cab-plate-button', 'data-stop': 'kappa' }, g);", 'plateDefects'],
            'a lit button on the plate' => ["class: 'cab-plate-button' }, g);", "class: 'cab-plate-button', fill: 'var(--cab-lamp)' }, g);", 'plateDefects'],
            'the plate moving' => ["    node('rect', { x: x + w / 2 - 0.5 * k,", "    node('animate', { attributeName: 'opacity', dur: '1s' }, g);\n    node('rect', { x: x + w / 2 - 0.5 * k,", 'plateDefects'],
            'no plate' => ["    drawCallPlate(cab, back, L.x + L.w + 8 * f);\n", '', 'plateDefects'],
            'the plate over the door' => ["    const x = (frameRight + wall) / 2 - w / 2;", "    const x = back.x + back.w / 2 - w / 2;", 'plateDefects'],
            'the old eye' => ['eye: 155, back: 120, view: 94,', 'eye: 140, back: 370, view: 230,', 'closerDefects'],
            'the band\'s cab off the palette' => ['                style: palette === null ? null : `--cab-frame:${palette.frame};--cab-lamp:${palette.lamp}`,', '                style: null,', 'bandDefects'],
            'a seam line over the band\'s doorway' => ["class: 'elevator-leaf right', style: leaves }, control);\n", "class: 'elevator-leaf right', style: leaves }, control);\n            node('line', { x1: e.seam, y1: e.y, x2: e.seam, y2: e.y + e.h, class: 'elevator-seam' }, control);\n", 'seamDefects'],
            'a seam line over the cab\'s doorway' => ["class: 'elevator-leaf right', style }, cab);\n", "class: 'elevator-leaf right', style }, cab);\n    node('line', { x1: seam, y1: L.y, x2: seam, y2: L.y + L.h, class: 'elevator-seam' }, cab);\n", 'seamDefects'],
            'no fallback for a mixed colour' => ['${unmixed(MIXED)}@supports', '@supports', 'mixDefects'],
            'the fallback still mixing' => ['const unmixed = (css) => css.replace(', 'const unmixed = (css) => css || css.replace(', 'mixDefects'],
            'a mix outside its block' => ['.cab-sill{fill:var(--door-edge)}', '.cab-sill{fill:color-mix(in srgb,var(--door-edge) 50%,var(--door))}', 'mixDefects'],
            // card#12070 (PR #343 review F4): a fallback that paints another property than its mix, or a colour that is not
            // one of the mix's own, or that comes after the block (overriding the mix where color-mix() is supported).
            'the fallback on another property' => ['${unmixed(MIXED)}@supports', '${unmixed(MIXED).replace(\'.cab-back{fill:\', \'.cab-back{stroke:\')}@supports', 'mixDefects'],
            'the fallback off the mix\'s colours' => ['${unmixed(MIXED)}@supports', '${unmixed(MIXED).replace(\'.cab-back{fill:var(--cab-frame)}\', \'.cab-back{fill:var(--lamp)}\')}@supports', 'mixDefects'],
            'the fallback after its block' => ['${unmixed(MIXED)}@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}', '@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}${unmixed(MIXED)}', 'mixDefects'],
            'the cab doors not closing on entering' => ["        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-close", "        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-open", 'cabDefects'],
            // card#12113's: the view behind the doors not the ride's `beyond` — the office for the lobby's stop, the lobby for
            // a floor's, or a view inferred by the painter from the page it runs on; the lobby view unclipped, peopled,
            // carrying A17's sky, a word, an inline colour or a motion, its ceiling grid gone or off its centres, its doors
            // or mat or windows missing; `LOBBY_VIEW` drifted from § 12; the module's `cabTheme()` not the painter's.
            'the office for the lobby' => ['const BEYOND = Object.freeze({ office: drawFloorView, lobby: drawLobbyView });', 'const BEYOND = Object.freeze({ office: drawFloorView, lobby: drawFloorView });', 'beyondDefects'],
            'the lobby for a floor' => ['const BEYOND = Object.freeze({ office: drawFloorView, lobby: drawLobbyView });', 'const BEYOND = Object.freeze({ office: drawLobbyView, lobby: drawLobbyView });', 'beyondDefects'],
            'the view inferred from the page' => ['    BEYOND[ride.beyond](node(', "    BEYOND[globalThis.location?.pathname === '/' ? 'lobby' : 'office'](node(", 'beyondDefects'],
            'the lobby unclipped' => ["    node('stop', { offset: 1, class: 'lobby-view-day-stop' }, glass);\n\n    const room = clippedTo(g, defs, box, id);", "    node('stop', { offset: 1, class: 'lobby-view-day-stop' }, glass);\n\n    const room = g;", 'lobbyDefects'],
            'a seat in the lobby' => ["    face('lobby-view-mat', pts(", "    node('image', { class: 'character', href: 'data:,' }, room);\n    face('lobby-view-mat', pts(", 'lobbyDefects'],
            'a word in the lobby' => ["    face('lobby-view-mat', pts(", "    node('text', { class: 'lobby-view-sign' }, room).textContent = 'Lobby';\n    face('lobby-view-mat', pts(", 'lobbyDefects'],
            'A17\'s sky in the lobby\'s windows' => ["fill: `url(#\${id}-glass)`, class: 'lobby-view-glass' }, room);\n        const mid", "fill: `url(#\${id}-glass)`, class: 'lobby-view-glass sky-day' }, room);\n        const mid", 'lobbyDefects'],
            'an inline colour in the lobby' => ["    face('lobby-view-mat', pts(", "    node('polygon', { points: '0,0 1,1 0,1', fill: '#e7866d', class: 'lobby-view-rug' }, room);\n    face('lobby-view-mat', pts(", 'lobbyDefects'],
            'the lobby moving' => ["    face('lobby-view-mat', pts(", "    node('animate', { attributeName: 'opacity', dur: '1s' }, room);\n    face('lobby-view-mat', pts(", 'lobbyDefects'],
            'no ceiling panels' => ["            face('lobby-view-light', pts(", "            false && face('lobby-view-light', pts(", 'lobbyDefects'],
            'the panels off their centres' => ['    for (let X = l + across / 2; X < r; X += across) {', '    for (let X = l + across / 2; X < r; X += across / 2) {', 'lobbyDefects'],
            'no front doors' => ["    face('lobby-view-door-frame', pts(", "    false && face('lobby-view-door-frame', pts(", 'lobbyDefects'],
            'no arch' => ["class: 'lobby-view-arch' }, room);", "class: 'lobby-view-arch-gone' }, room);", 'lobbyDefects'],
            'no mat' => ["    face('lobby-view-mat', pts(", "    false && face('lobby-view-mat', pts(", 'lobbyDefects'],
            'one window' => ['    const windows = [-300, 300].map(', '    const windows = [300].map(', 'lobbyDefects'],
            'the lobby wider than § 12' => ["    w: 1000,\n    d: 1400,", "    w: 1200,\n    d: 1400,", 'lobbyViewDefects'],
            'the panels larger than § 12' => ['    panel: Object.freeze({ w: 120, d: 100, across: 250, deep: 200 }),', '    panel: Object.freeze({ w: 140, d: 100, across: 250, deep: 200 }),', 'lobbyViewDefects'],
            'the module\'s cabTheme not the painter\'s' => ['        cabTheme: (el) => cabTheme(el, cabPalette()),', '        cabTheme: (el) => cabTheme(el, { frame: \'#000000\', lamp: \'#000000\' }),', 'themeDefects'],
            // card#12987's door view (the plan's § 6 RED table): the floor behind the doors not the stop's, not its layout, not its
            // theme; the order's piece behind a partition painted over it; a bare floor drawn with confidence; the model's
            // arrival never repainted or repainted every frame, or its wake removed; the press refetching the layout.
            'the stop never following the choice' => ['        const stop = CHOSEN_ON.includes(ride.phase) ? ride.chosen.key : ride.current;', '        const stop = ride.current;', 'doorViewDefects', '../wire/ride.js'],
            'the rider\'s frame unmirrored' => ['    const X = (px) => (threshold.x - px) * SCALE;', '    const X = (px) => (px - threshold.x) * SCALE;', 'doorViewDefects', 'door-view.js'],
            'the threshold at the extent\'s edge' => ['    const threshold = thresholdOf(placement.extent);', '    const threshold = { x: placement.extent.x, y: placement.extent.y };', 'doorViewDefects', 'door-view.js'],
            'the house theme\'s palette for every stop' => ['    const module = resolved === null ? null : themes.modules[resolved] ?? null;', '    const module = resolved === null ? null : themes.modules[themes.registry.house] ?? null;', 'doorViewDefects', 'door-view.js'],
            'a wall cell under a desk\'s slab drawn' => ['                if (y === f.y || underSlab(x, y)) {', '                if (y === f.y) {', 'doorViewDefects', 'door-view.js'],
            'bare from the held map alone' => ['    if (ids.some((id) => building.mapless(id))) {', '    if (ids.some((id) => (building.room(id)?.held?.map ?? null) === null)) {', 'doorViewDefects', 'door-view.js'],
            'the tilesets ignored' => ['    if (urls.some((url) => held.get(url)?.tileset === undefined)) {', '    if (false) {', 'doorViewDefects', 'door-view.js'],
            'every billboard after every other body' => ["    const extent = placement.extent;\n", "    bodies.sort((a, b) => (a.body.kind === 'billboard') - (b.body.kind === 'billboard'));\n    const extent = placement.extent;\n", 'doorViewDefects', 'door-view.js'],
            'the top-row cells drawn' => ['                if (y === f.y || underSlab(x, y)) {', '                if (underSlab(x, y)) {', 'doorViewDefects', 'door-view.js'],
            'the walls from the union extent' => ['    for (const { footprint: f } of grids.filter((g) => g.room)) {', '    for (const f of [placement.extent]) {', 'doorViewDefects', 'door-view.js'],
            'the billboards the house theme\'s' => ['        ...project(stopRow, placement, maps, (url) => held.get(url).tileset, themes.registry.kinds, module),', '        ...project(stopRow, placement, maps, (url) => held.get(url).tileset, themes.registry.kinds, themes.modules[themes.registry.house]),', 'doorViewDefects', 'door-view.js'],
            'the old office for a floor\'s stop' => ['const BEYOND = Object.freeze({ office: drawFloorView, lobby: drawLobbyView });', 'const BEYOND = Object.freeze({ office: drawOfficeView, lobby: drawLobbyView });', 'doorViewDefects'],
            'the floor read off the page' => ["`cab-\${ride.beyond}`, ride.view);", "`cab-\${ride.beyond}`, globalThis.location?.pathname?.startsWith('/floor/') ? undefined : ride.view);", 'doorViewDefects'],
            'the root styled with no module' => ["        style: palette === null ? null : Object.entries(palette)", "        style: palette === null ? '--dv-floor:undefined;' : Object.entries(palette)", 'doorViewDefects'],
            'the arrival never repainted' => ['            if (REPAINTED.includes(phase) && view.cab !== null && view.cab.view !== paintedView) {', '            if (false) {', 'doorViewDefects', '../wire/ride-page.js'],
            'the arrival repainted every frame' => ['            if (REPAINTED.includes(phase) && view.cab !== null && view.cab.view !== paintedView) {', '            if (REPAINTED.includes(phase) && view.cab !== null) {', 'doorViewDefects', '../wire/ride-page.js'],
            'the press\'s wake removed' => ['        this.#wake = wake;', '        this.#wake = () => {};', 'doorViewDefects', 'floor-data.js'],
            'the memo dropped' => ['        if (held !== undefined) {', '        if (false) {', 'doorViewDefects', 'door-views.js'],
            'the press refetching the layout' => ["    prefetch() {\n        const steps", "    prefetch() {\n        this.#building.fetchLayout();\n        const steps", 'doorViewDefects', 'floor-data.js'],
            // card#13033: `FloorData#roomTilesets()` — one room's held map's tilesets, no room entered, the page woken.
            'one room\'s tilesets by re-entering every room' => ["        await this.#tilesets.load(namedTilesets([this.#building.room(installId)?.held?.map ?? null]));\n", "        await this.prefetch();\n", 'doorViewDefects', 'floor-data.js'],
            'one room\'s tilesets as every row\'s' => ['namedTilesets([this.#building.room(installId)?.held?.map ?? null])', 'namedTilesets((this.#rows() ?? []).flatMap((row) => row.rooms.map((room) => this.#building.room(room.install_id)?.held?.map ?? null)))', 'doorViewDefects', 'floor-data.js'],
            'one room\'s tilesets with no wake' => ["        await this.#tilesets.load(namedTilesets([this.#building.room(installId)?.held?.map ?? null]));\n        this.#wake();\n", "        await this.#tilesets.load(namedTilesets([this.#building.room(installId)?.held?.map ?? null]));\n", 'doorViewDefects', 'floor-data.js'],
        ] as $what => $control) {
            [$from, $to, $check] = $control;
            // The cab's drawing is `cab-paint.js`'s since card#12113; the band and the painter's own wrappers stay in `painter.js`;
            // a control in any other module names it (card#12987's door view, its screens' views and the ride page).
            $file = $control[3] ?? (str_contains((string) file_get_contents($this->moduleDir().'/painter.js'), $from) ? 'painter.js' : 'cab-paint.js');
            $dir = $this->mutatedModules([$file, $from, $to]);
            $this->assertNotSame([], $this->{$check}($this->run_(dirname($dir))), "CONTROL ({$what}) did not bite");
        }
    }

    /** @return array<string, mixed> */
    private function run_(?string $jsRoot = null): array
    {
        $out = [];
        exec('node '.escapeshellarg($this->probeScript()).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "the elevator probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true);
    }

    /** @return array{open: int, step: int, close: int} § 12's *Elevator leaves* row */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator leaves* row in the form this test reads');

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    /** @return list<string> */
    private function controlDefects(array $run): array
    {
        $c = $run['control'];
        $defects = [];

        if (! $c['found']) {
            return ['the band draws no [data-key="elevator"]'];
        }

        if ($c['attrs']['role'] !== 'button' || $c['attrs']['tabindex'] !== '0' || ($c['attrs']['aria_label'] ?? '') === '') {
            $defects[] = 'the elevator is not a named tab-stop button: '.json_encode($c['attrs']);
        }

        if ($c['hit'] !== $c['surround']) {
            $defects[] = 'the hit area is not the surround: '.json_encode($c['hit']);
        }

        if ($c['presses'] !== ['click' => 1, 'enter' => 1, 'space' => 1, 'tab' => 0]) {
            $defects[] = 'presses: '.json_encode($c['presses']).' — one each for a click, Enter and Space, none for Tab';
        }

        if ($c['attrs']['aria_disabled'] !== null || $c['disabled_riding'] !== 'true') {
            $defects[] = 'aria-disabled is not set while a ride is in flight, and only then';
        }

        return $defects;
    }

    /** @return list<string> */
    private function leafDefects(array $run): array
    {
        $d = $this->door();
        $e = $run['elapsed'];
        $defects = [];
        $held = 'transform:scaleX(.12)';
        // The probe's ride: 750 ms, the doors 250 ms in (opening: 500, at once) — the painter moves them over whatever
        // the phase hands it, eased; how long that is is `ridePhase()`'s (`TheFloorsRideIsNavigationTest`).
        $expect = [
            'opening' => [fn ($t) => 'animation:leaf-open 500ms ease-in-out '.(0 - $t).'ms forwards'],
            'entering' => [fn () => $held], 'inside' => [fn () => $held], 'chosen' => [fn () => $held], 'leaving' => [fn () => $held],
            'staying' => [fn ($t) => 'animation:leaf-close 500ms ease-in-out '.(250 - $t).'ms both'],
            'arriving' => [fn ($t) => 'animation:leaf-close 500ms ease-in-out '.(250 - $t).'ms both'],
        ];

        foreach ($expect as $phase => [$style]) {
            foreach (['at' => $e, 'later' => $e + 300] as $when => $t) {
                if ($run['leaves'][$phase][$when] !== [$style($t), $style($t)]) {
                    $defects[] = "{$phase} at {$t} ms: ".json_encode($run['leaves'][$phase][$when]).' — expected '.$style($t);
                }
            }
        }

        $walk = 'animation:leaf-open '.($d['open'] * 0.25).'s steps('.$d['open'].') '.(3 * 0.25).'s forwards,'
            .'leaf-close '.($d['close'] * 0.25).'s steps('.$d['close'].') '.((8 - $d['close']) * 0.25).'s forwards';

        if ($run['walks']['no_ride'] !== [$walk, $walk] || $run['walks']['no_doors'] !== [null, null]) {
            $defects[] = 'with no ride the walks\' leaves are not what they were: '.json_encode($run['walks']);
        }

        if ($run['walks']['riding'] !== $run['walks']['riding_no_walk']) {
            $defects[] = 'a walk\'s door span changed the leaves a ride holds';
        }

        return $defects;
    }

    /** @return list<string> */
    private function cabDefects(array $run): array
    {
        $e = $run['elapsed'];
        $defects = [];
        $expect = [
            'entering' => 'animation:leaf-close 500ms ease-in-out '.(250 - $e).'ms both',
            'chosen' => 'animation:leaf-open 500ms ease-in-out '.(250 - $e).'ms both',
            'inside' => null,
            'leaving' => 'transform:scaleX(.12)',
        ];

        foreach ($expect as $phase => $style) {
            $cab = $run['cab'][$phase];

            if ($cab['leaves'] !== [$style, $style]) {
                $defects[] = "{$phase}: the cab's leaves are ".json_encode($cab['leaves']);
            }

            if ($cab['texts'] !== 0) {
                $defects[] = "{$phase}: the cab draws {$cab['texts']} <text>";
            }
        }

        if ($run['cab']['emptied']['children'] !== 0) {
            $defects[] = 'paintCab(null) leaves the cab drawn';
        }

        return $defects;
    }

    /** @return list<string> */
    private function paletteDefects(array $run): array
    {
        $p = $run['palette'];
        $defects = [];
        $palette = $p['palette'];

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) ($palette['frame'] ?? ''), 'the house theme has no elevator frame colour this check can read');

        if ($p['carried'] !== ['--cab-frame' => $palette['frame'], '--cab-lamp' => $palette['lamp']]) {
            $defects[] = 'cabTheme() carried '.json_encode($p['carried']).' onto the dialog, not the house elevator palette '.json_encode($palette);
        }

        if ($p['unthemed'] !== []) {
            $defects[] = 'with no theme, cabTheme() left '.json_encode($p['unthemed']).' on the dialog';
        }

        if ($p['marked'] !== ['data-themed' => '']) {
            $defects[] = 'with the house theme, cabTheme() left the dialog\'s attributes '.json_encode($p['marked']).', not marked data-themed';
        }

        if ($p['unmarked'] !== []) {
            $defects[] = 'with no theme, cabTheme() left '.json_encode($p['unmarked']).' on the dialog — the popup would draw as themed';
        }

        if ($p['fit'] !== 'xMidYMid meet' || $p['overflow'] !== 'hidden') {
            $defects[] = "the cab is fitted {$p['fit']} with overflow {$p['overflow']} — not whole, with nothing drawn past it";
        }

        if ($p['viewBox'] === null || $p['aspect'] !== str_replace(['0 0 ', ' '], ['', ' / '], $p['viewBox'])) {
            $defects[] = "the cab's shape handed to the layout ({$p['aspect']}) is not its space's ({$p['viewBox']})";
        }

        foreach (['frame', 'lamp'] as $part) {
            if ($p[$part] === null || $p[$part]['fill'] !== null) {
                $defects[] = "the cab's {$part} is ".json_encode($p[$part]).' — drawn, and painted by its class, never inline';
            }

            if (preg_match('/\.cab-'.$part.'\{fill:var\(--cab-'.$part.'\)[;}]/', $p['style']) !== 1) {
                $defects[] = "the cab's style paints .cab-{$part} from something other than --cab-{$part}";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function depthDefects(array $run): array
    {
        $d = $run['depth'];
        $defects = [];

        foreach (['back', 'left', 'right', 'ceiling', 'floor'] as $part) {
            if ($d[$part] === null) {
                $defects[] = "the cab draws no {$part}";
            }
        }

        if ($defects !== []) {
            return $defects;
        }

        [, , $w, $h] = array_map('floatval', explode(' ', (string) $d['viewBox']));
        $b = $d['back'];

        // An elevator's shape: taller than it is wide, and nowhere near the window's — § 12's 200 × 230 cm cab is 0.87 as
        // wide as it is high (card#12071; card#12051's 160 cm cab was 0.70, held between 0.6 and 0.8).
        if ($w / $h < 0.8 || $w / $h > 0.95) {
            $defects[] = "the cab's opening is {$w} × {$h} — not an elevator's shape, taller than it is wide";
        }

        // The doors' wall exactly where § 12's figures put it — the frame's size times `view / back`, about the eye's point
        // (card#12071) — and inset on every side by more than a twentieth of the opening, so each side wall, the ceiling and
        // the floor shows as a surface: the room has depth. (The rider stands close, so the ceiling's strip — (h − eye) × (1 − view / back)
        // — is the narrowest; card#12051's far eye held a tenth on every side.)
        $c = $d['cab'];
        $k = $c['view'] / $c['back'];
        $want = ['x' => $w / 2 * (1 - $k), 'y' => ($c['h'] - $c['eye']) * (1 - $k), 'w' => $w * $k, 'h' => $h * $k];

        if (max(array_map(static fn ($key) => abs($b[$key] - $want[$key]), ['x', 'y', 'w', 'h'])) > 0.05) {
            $defects[] = 'the doors\' wall is not where § 12\'s eye puts it: '.json_encode(['drawn' => $b, 'want' => $want]);
        }

        if (! ($b['x'] > 0.05 * $w && $b['x'] + $b['w'] < 0.95 * $w && $b['y'] > 0.05 * $h && $b['y'] + $b['h'] < 0.95 * $h)) {
            $defects[] = 'the back wall is not inset in the opening — the cab is drawn flat: '.json_encode($b);
        }

        // Each side wall a trapezoid from the opening's edge to the back wall's, its far edge the shorter.
        foreach (['left' => [0.0, $b['x']], 'right' => [$w, $b['x'] + $b['w']]] as $side => [$near, $far]) {
            $xs = array_map(static fn ($p) => $p[0], $d[$side]);
            $atX = static fn ($x) => array_values(array_filter($d[$side], static fn ($p) => abs($p[0] - $x) < 0.05));
            [$n, $f] = [$atX($near), $atX($far)];

            if (count($d[$side]) !== 4 || count($n) !== 2 || count($f) !== 2 || abs(min($xs) - min($near, $far)) > 0.05 || abs(max($xs) - max($near, $far)) > 0.05) {
                $defects[] = "the {$side} wall does not run from the opening's edge to the back wall's: ".json_encode($d[$side]);

                continue;
            }

            if (abs($f[0][1] - $f[1][1]) >= abs($n[0][1] - $n[1][1]) - 1) {
                $defects[] = "the {$side} wall's far edge is not shorter than its near one — it does not recede: ".json_encode($d[$side]);
            }
        }

        $ys = static fn ($pts) => array_map(static fn ($p) => $p[1], $pts);

        if (abs(min($ys($d['ceiling']))) > 0.05 || abs(max($ys($d['ceiling'])) - $b['y']) > 0.05) {
            $defects[] = 'the ceiling does not run from the opening\'s head to the back wall\'s: '.json_encode($d['ceiling']);
        }

        if (abs(max($ys($d['floor'])) - $h) > 0.05 || abs(min($ys($d['floor'])) - ($b['y'] + $b['h'])) > 0.05) {
            $defects[] = 'the floor does not run from the opening\'s foot to the back wall\'s: '.json_encode($d['floor']);
        }

        foreach ($d['leaves'] as $i => $leaf) {
            if ($leaf['x'] < $b['x'] || $leaf['x'] + $leaf['w'] > $b['x'] + $b['w'] + 0.01 || $leaf['y'] < $b['y'] || $leaf['y'] + $leaf['h'] > $b['y'] + $b['h'] + 0.01) {
                $defects[] = "leaf {$i} is not on the back wall: ".json_encode($leaf);
            }
        }

        if ($d['inline'] !== []) {
            $defects[] = 'a surface carries an inline colour: '.implode(', ', $d['inline']);
        }

        foreach (['back', 'side', 'ceiling', 'floor'] as $part) {
            if (preg_match('/\.cab-'.$part.'\{fill:color-mix\(in srgb,var\(--cab-frame\) \d+%,var\(--[a-z-]+\)\)\}/', $run['palette']['style']) !== 1) {
                $defects[] = "the cab's style does not paint .cab-{$part} from --cab-frame";
            }
        }

        $y = $d['beyond'];

        if ($y === null || ! ($y['recess'] < $y['at'] && $y['at'] < $y['leaf'])) {
            $defects[] = 'the layer behind the leaves is not a group over the recess and under the leaves: '.json_encode($y);
        }

        if (array_keys(array_filter($d['shaded'])) !== ['ceiling', 'floor', 'left', 'right', 'back']) {
            $defects[] = 'a surface carries no depth overlay: '.json_encode($d['shaded']);
        }

        $a = $d['alone'];
        $box = $a['box'];
        $ab = $a['back'];

        if ($ab === null || $a['answered'] !== $ab || $a['sides'] !== 2 || $a['ceiling'] !== 1 || $a['floor'] !== 1 || $a['leaves'] !== 0) {
            $defects[] = 'drawCabInterior() alone did not draw the room and answer its back wall, without a door: '.json_encode($a);
        } elseif (! ($ab['x'] > $box['x'] && $ab['y'] > $box['y'] && $ab['x'] + $ab['w'] < $box['x'] + $box['w'] && $ab['y'] + $ab['h'] < $box['y'] + $box['h'])) {
            $defects[] = 'drawCabInterior() drew its back wall outside the box it was handed: '.json_encode($a);
        }

        if ($a['ids'] === [] || array_filter($a['ids'], static fn ($id) => ! str_starts_with($id, 'probe-')) !== []) {
            $defects[] = 'drawCabInterior()\'s ids are not the prefix it was handed: '.json_encode($a['ids']);
        }

        return $defects;
    }

    /**
     * Scenery's census, read for what scenery may not carry: a word, an image, a datum attribute, a colour of its own, any
     * motion — and, where `$own` is given, a class outside the view's own (`$own` its prefix; `left`, `right`, `lit` its
     * modifiers), which is where a character, a desk slot or a badge would show.
     *
     * @param  list<array<string, mixed>>  $census
     * @return list<string>
     */
    private function sceneryDefects(string $what, array $census, ?string $own): array
    {
        $defects = [];

        foreach ($census as $n) {
            $at = "{$what}: <{$n['tag']} class=\"".implode(' ', $n['cls']).'">';

            if ($n['text'] || in_array($n['tag'], ['text', 'image', 'foreignObject', 'use'], true)) {
                $defects[] = "{$at} is a word or an image";
            }

            if ($n['data'] !== []) {
                $defects[] = "{$at} carries ".implode(', ', $n['data']);
            }

            if ($n['inline'] !== []) {
                $defects[] = "{$at} carries a colour of its own (".implode(', ', $n['inline']).')';
            }

            if ($n['motion']) {
                $defects[] = "{$at} moves";
            }

            if ($own !== null) {
                $foreign = array_filter($n['cls'], static fn ($c) => ! str_starts_with($c, $own) && ! in_array($c, ['left', 'right', 'lit'], true));

                if ($foreign !== []) {
                    $defects[] = "{$at} is not the view's own: ".implode(' ', $foreign);
                }
            }
        }

        return $defects;
    }

    /** @return list<string> every phase and painter path whose layer behind the leaves is not the view `beyond` names */
    private function beyondDefects(array $run): array
    {
        $defects = [];
        $views = $run['lobby']['views'];

        $this->assertCount(6, $views, 'the probe painted the cab under fewer cases than it names — the check measured less');

        foreach ($views as $case => $paths) {
            [, $beyond] = explode(':', $case);
            $other = $beyond === 'lobby' ? 'office' : 'lobby';

            foreach ($paths as $path => $drawn) {
                if ($drawn[$beyond] !== 1 || $drawn[$other] !== 0) {
                    $defects[] = "[{$case}, {$path}'s paintCab] the layer behind the leaves holds ".json_encode($drawn)." — beyond is {$beyond}";
                }
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function lobbyDefects(array $run): array
    {
        $o = $run['lobby'];
        $defects = [];

        if (! $o['found']) {
            return ['the cab\'s layer behind the leaves holds no lobby view with beyond: lobby'];
        }

        $near = static fn ($a, $b) => $a !== null && $b !== null && max(array_map(static fn ($k) => abs($a[$k] - $b[$k]), ['x', 'y', 'w', 'h'])) < 0.01;

        if ($o['clip'] === null || ! $near($o['clip']['rect'], $o['door']) || $o['clip']['drawn'] === 0) {
            $defects[] = 'the lobby is not drawn clipped to the doorway the leaves span: '.json_encode(['clip' => $o['clip'], 'door' => $o['door']]);
        }

        $count = static fn ($cls) => count(array_filter($o['census'], static fn ($n) => in_array($cls, $n['cls'], true)));
        $parts = ['lobby-view-floor' => 1, 'lobby-view-ceiling' => 1, 'lobby-view-plank' => 1, 'lobby-view-wall' => 2, 'lobby-view-back' => 1, 'lobby-view-skirting' => 3,
            'lobby-view-rail' => 2, 'lobby-view-door-frame' => 1, 'lobby-view-arch' => 1, 'lobby-view-mat' => 1, 'lobby-view-mullion' => 2, 'lobby-view-sun' => 1];

        foreach ($parts as $part => $least) {
            if ($count($part) < $least) {
                $defects[] = "the lobby draws {$count($part)} .{$part}, fewer than {$least}";
            }

            if (preg_match('/\.'.preg_quote($part, '/').'\{[a-z-]+:/', $run['style']) !== 1) {
                $defects[] = "STYLE paints no .{$part}";
            }
        }

        // The doors' two leaves and the two windows are glass, in daylight.
        if ($count('lobby-view-glass') < 4) {
            $defects[] = "the lobby draws {$count('lobby-view-glass')} panes of glass — two door leaves and two windows";
        }

        foreach (['lobby-view-sky-stop', 'lobby-view-day-stop', 'lobby-view-shade-stop'] as $stop) {
            if (preg_match('/\.'.$stop.'\{stop-color:var\(--[a-z0-9-]+\)\}/', $run['style']) !== 1) {
                $defects[] = "STYLE does not colour .{$stop} from a token";
            }
        }

        // The ceiling's grid: one panel per centre the room's width and depth hold at § 12's pitches.
        $lv = $o['lobby_view'];
        $grid = intdiv($lv['w'], $lv['panel']['across']) * intdiv($lv['d'], $lv['panel']['deep']);

        if ($o['alone']['lights'] !== $grid) {
            $defects[] = "drawLobbyView() draws {$o['alone']['lights']} ceiling panels, not the {$grid} its width and depth hold at § 12's centres";
        }

        array_push($defects, ...$this->sceneryDefects('the lobby', $o['census'], 'lobby-view'));

        $a = $o['alone'];

        if ($a['answered'] !== $a['box'] || $a['clip'] === null || $a['clip']['rect'] !== $a['box']) {
            $defects[] = 'drawLobbyView() alone is not clipped to the box it was handed, or does not answer it: '.json_encode($a);
        }

        if ($a['ids'] === [] || array_filter($a['ids'], static fn ($id) => ! str_starts_with($id, 'probe-lobby-')) !== []) {
            $defects[] = 'drawLobbyView()\'s ids are not the prefix it was handed: '.json_encode($a['ids']);
        }

        return $defects;
    }

    /** @return list<string> `LOBBY_VIEW` against § 12's *Lobby view* row, figure for figure */
    private function lobbyViewDefects(array $run): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Lobby view\*\* \| \*\*([\d,]+) × ([\d,]+) × ([\d,]+) cm\*\* wide × deep × high, its front doors \*\*(\d+) × (\d+) cm\*\* centred on the cab\'s door line, one window \*\*(\d+) cm\*\* wide either side of them, the eye \*\*(\d+) cm\*\* from the doorway; its ceiling panels \*\*(\d+) × (\d+) cm\*\* on \*\*(\d+) cm\*\* centres across and \*\*(\d+) cm\*\* centres in depth \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Lobby view* row in the form this test reads');

        $n = static fn (string $v): int => (int) str_replace(',', '', $v);
        $expected = ['w' => $n($m[1]), 'd' => $n($m[2]), 'h' => $n($m[3]), 'doors' => ['w' => $n($m[4]), 'h' => $n($m[5])], 'window' => $n($m[6]), 'view' => $n($m[7]),
            'panel' => ['w' => $n($m[8]), 'd' => $n($m[9]), 'across' => $n($m[10]), 'deep' => $n($m[11])]];

        return $run['lobby']['lobby_view'] === $expected ? [] : ['LOBBY_VIEW is '.json_encode($run['lobby']['lobby_view']).' where § 12 reads '.json_encode($expected)];
    }

    /** @return list<string> the module's `cabTheme()` against the painter's, over the same house palette, and with none */
    private function themeDefects(array $run): array
    {
        $t = $run['lobby']['theme'];
        $defects = [];

        if ($t['module'] !== $t['painter']) {
            $defects[] = 'the module\'s cabTheme() carries '.json_encode($t['module']).' where the painter\'s carries '.json_encode($t['painter']);
        }

        if ($t['unthemed']['props'] !== [] || $t['unthemed']['attrs'] !== []) {
            $defects[] = 'the module\'s cabTheme() with no palette leaves '.json_encode($t['unthemed']);
        }

        return $defects;
    }

    /**
     * The floor seen through the cab's doors (card#12987), over the door-view leg's S1 — alpha's floor while the doors close:
     * one view in the layer behind the leaves, clipped to the doorway, its surfaces each its class (and each face's class
     * stroking its own fill, so adjacent faces leave no hairline), its census scenery's; and the bare room it falls back to,
     * drawn on its own: the shell and nothing standing in it.
     *
     * @return list<string>
     */
    private function officeDefects(array $run): array
    {
        $s = $run['door_view']['s1'];
        $defects = [];

        if (! $s['found'] || $run['office']['beyond'] !== 1) {
            return ['the cab\'s layer behind the leaves holds no floor view, or more than one'];
        }

        $near = static fn ($a, $b) => $a !== null && $b !== null && max(array_map(static fn ($k) => abs($a[$k] - $b[$k]), ['x', 'y', 'w', 'h'])) < 0.01;

        if ($s['clip'] === null || ! $near($s['clip']['rect'], $s['door']) || $s['clip']['drawn'] === 0) {
            $defects[] = 'the floor is not drawn clipped to the doorway the leaves span: '.json_encode(['clip' => $s['clip'], 'door' => $s['door']]);
        }

        foreach (['dv-floor' => 1, 'dv-ceiling' => 1, 'dv-light' => 1, 'dv-back' => 1, 'dv-wall' => 1, 'dv-partition' => 1, 'dv-partition-cap' => 1, 'dv-desk' => 1, 'dv-desk-edge' => 1, 'dv-piece' => 1] as $part => $least) {
            if ($s['parts'][$part] < $least) {
                $defects[] = "the floor draws {$s['parts'][$part]} .{$part}, fewer than {$least}";
            }
        }

        // Every surface class is painted by STYLE, and each face's class strokes its own fill (the plan's § 7: no hairline).
        $plain = $this->withoutColourMixBlocks($run['style']);

        foreach (['dv-floor', 'dv-ceiling', 'dv-accent', 'dv-wall', 'dv-back', 'dv-partition', 'dv-partition-cap', 'dv-desk', 'dv-desk-edge', 'dv-monitor', 'dv-skirting'] as $part) {
            if (preg_match('/\.'.preg_quote($part, '/').'\{fill:([^;}]+);stroke:([^;}]+);stroke-width:[\d.]+/', $plain, $m) !== 1 || $m[1] !== $m[2]) {
                $defects[] = "STYLE does not paint .{$part} a fill stroked by itself";
            }
        }

        array_push($defects, ...$this->doorViewCensusDefects('the floor (S1)', $s));

        $a = $run['office']['alone'];

        if ($a['answered'] !== $a['box'] || $a['clip'] === null || $a['clip']['rect'] !== $a['box']) {
            $defects[] = 'drawOfficeView() alone is not clipped to the box it was handed, or does not answer it: '.json_encode($a);
        }

        if ($a['ids'] === [] || array_filter($a['ids'], static fn ($id) => ! str_starts_with($id, 'probe-office-')) !== []) {
            $defects[] = 'drawOfficeView()\'s ids are not the prefix it was handed: '.json_encode($a['ids']);
        }

        array_push($defects, ...$this->bareRoomDefects('the bare room alone', $a['parts']));
        array_push($defects, ...$this->sceneryDefects('the bare room alone', $a['census'], 'dv-'));

        return $defects;
    }

    /**
     * The bare room's parts (plan § 5): the shell — a floor, a ceiling with its lights, two walls and a far one — and nothing
     * standing in it.
     *
     * @param  array<string, int>  $parts
     * @return list<string>
     */
    private function bareRoomDefects(string $what, array $parts): array
    {
        $defects = [];

        foreach (['dv-floor' => 1, 'dv-ceiling' => 1, 'dv-light' => 1, 'dv-wall' => 2, 'dv-back' => 1] as $part => $least) {
            if ($parts[$part] < $least) {
                $defects[] = "{$what}: {$parts[$part]} .{$part}, fewer than the shell's {$least}";
            }
        }

        foreach (['dv-partition', 'dv-desk', 'dv-accent', 'dv-piece'] as $part) {
            if (($parts[$part] ?? 0) !== 0) {
                $defects[] = "{$what}: {$parts[$part]} .{$part} — the bare room has nothing standing in it";
            }
        }

        return $defects;
    }

    /**
     * A door-view scenario's census, as scenery's, with its two declared exceptions each CHECKED: the root group's `style`,
     * where present, is custom-property declarations of the `--dv-…` carriers and nothing else; an `<image>` is a standing
     * piece whose `href` is a theme document the probe recomputed from the stop's own map (`hrefs`), and carries nothing else.
     *
     * @param  array<string, mixed>  $s
     * @return list<string>
     */
    private function doorViewCensusDefects(string $what, array $s): array
    {
        $defects = [];

        if ($s['style'] !== null && preg_match('/^(--dv-[a-z-]+:#[0-9a-f]{6};)+$/', $s['style']) !== 1) {
            $defects[] = "{$what}: the root group's style is not the --dv-… carriers alone: {$s['style']}";
        }

        $images = array_values(array_filter($s['census'], static fn ($n) => $n['tag'] === 'image'));

        foreach ($images as $n) {
            if (! in_array($n['href'], $s['hrefs'] ?? [], true)) {
                $defects[] = "{$what}: an <image> whose href is not a theme document of the stop's own pieces: ".substr((string) $n['href'], 0, 60);
            }

            if (array_diff($n['data'], ['href']) !== [] || $n['inline'] !== [] || $n['motion'] || $n['cls'] !== ['dv-piece']) {
                $defects[] = "{$what}: a standing piece carries more than its document: ".json_encode($n);
            }
        }

        $rest = array_values(array_filter($s['census'], static fn ($n) => $n['tag'] !== 'image'));

        return [...$defects, ...$this->sceneryDefects($what, $rest, 'dv-')];
    }

    /**
     * The door-view leg's scenarios (the plan's § 6 table), each read for what it names.
     *
     * @return list<string>
     */
    private function doorViewDefects(array $run): array
    {
        $v = $run['door_view'];
        $defects = [];
        $styleOf = static function (?string $style): array {
            $out = [];

            foreach (array_filter(explode(';', (string) $style)) as $declaration) {
                [$k, $val] = explode(':', $declaration, 2);
                $out[$k] = $val;
            }

            return $out;
        };
        $owes = static fn (array $palette, array $owed): bool => array_intersect_assoc($owed, $palette) === $owed;

        // S1: alpha's floor while the doors close.
        $s1 = $v['s1'];

        if (! $s1['found'] || $s1['stop'] !== 'alpha' || ! $owes($s1['palette'], $s1['owed'])) {
            $defects[] = 'S1: the doors closing on alpha do not show alpha\'s floor in the studio theme: '.json_encode(['stop' => $s1['stop'], 'palette' => $s1['palette'], 'owed' => $s1['owed']]);
        }

        if ($s1['column_faces'] === 0 || ! $s1['column_right']) {
            $defects[] = "S1: alpha's partition column is not drawn to the rider's RIGHT of the doorway's centre line ({$s1['column_faces']} of its faces found where the probe projects them)";
        }

        if ($s1['under_slab'] !== -1) {
            $defects[] = 'S1: the wall cell under the first desk\'s slab is drawn — the desk stands on it';
        }

        if ($s1['slab_strip'] < 0 || ! $s1['desks_left'] || $s1['far_desk'] !== -1) {
            $defects[] = 'S1: the first desk is not drawn LEFT of centre at its slab\'s rows, or the far desk outside the doorway is drawn: '.json_encode(['strip' => $s1['slab_strip'], 'left' => $s1['desks_left'], 'far' => $s1['far_desk']]);
        }

        if ($s1['plant'] < 0 || $s1['behind_plant'] === [] || $s1['before_plant'] === [] || max($s1['behind_plant']) > $s1['plant'] || min($s1['before_plant']) < $s1['plant']) {
            $defects[] = 'S1: the plant is not painted after every column cell behind its foot line and before every one in front of it';
        }

        if ($s1['parts']['dv-accent'] !== 0) {
            $defects[] = "S1: {$s1['parts']['dv-accent']} accent runs on a floor with no accent";
        }

        array_push($defects, ...$this->doorViewCensusDefects('S1', $s1));

        // S2: bravo's floor once bravo is chosen.
        $s2 = $v['s2'];

        if (! $s2['found'] || $s2['stop'] !== 'bravo' || ! $owes($s2['palette'], $s2['owed'])) {
            $defects[] = 'S2: the doors opening on bravo do not show bravo\'s floor in the cottage theme: '.json_encode(['stop' => $s2['stop'], 'palette' => $s2['palette'], 'owed' => $s2['owed']]);
        }

        if ($s2['row_faces'] === 0 || ! $s2['row_spans_centre'] || ! $s2['caps_below_horizon'] || ! $s2['post_above_horizon']) {
            $defects[] = 'S2: bravo\'s partition row is not drawn across the centre line, under the eye, with its perimeter post over it: '.json_encode(array_intersect_key($s2, array_flip(['row_faces', 'row_spans_centre', 'caps_below_horizon', 'post_above_horizon'])));
        }

        if ($s2['near_wall'] !== 0) {
            $defects[] = "S2: {$s2['near_wall']} faces of the footprint's top row (the near wall along the band) are drawn across the doorway";
        }

        if ($s2['parts']['dv-accent'] < 1) {
            $defects[] = 'S2: no accent run on a floor of accent';
        }

        if (! $s2['bookcase_href'] || ! $s2['row_after_bookcase']) {
            $defects[] = 'S2: the bookcase behind the partition is not the cottage theme\'s own document painted before the partition row: '.json_encode(['href' => $s2['bookcase_href'], 'before' => $s2['row_after_bookcase']]);
        }

        array_push($defects, ...$this->doorViewCensusDefects('S2', $s2));

        // The bare rooms: S3 (the map 503), S5 (the theme module absent), S7 (a stop no longer offered).
        foreach (['s3' => ['mapless', 'cottage'], 's5' => ['theme', null], 's7' => ['stop', null]] as $case => [$reason, $theme]) {
            $s = $v[$case];
            $label = strtoupper($case);

            if ($s['model'] !== ['bare' => true, 'reason' => $reason]) {
                $defects[] = "{$label}: the model is ".json_encode($s['model'])." — bare, reason {$reason}, is owed";
            }

            if ($theme === null ? $s['style'] !== null : ! $owes($styleOf($s['style']), $v['s2']['owed'])) {
                $defects[] = "{$label}: the bare room's root carries ".json_encode($s['style']).($theme === null ? ' — no style is owed with no module' : " — {$theme}'s carriers are owed");
            }

            array_push($defects, ...$this->bareRoomDefects($label, $s['parts']));
            array_push($defects, ...$this->doorViewCensusDefects($label, $s));
        }

        if ($v['s3b'] !== ['held_version' => 1, 'failure' => ['status' => 503], 'bare' => true, 'reason' => 'mapless']) {
            $defects[] = 'S3b: a held v1 map under a failed refetch reads '.json_encode($v['s3b']).' — bare, mapless (F16\'s extent is not a map to draw)';
        }

        // S4: the map lands 300 ms into `chosen`.
        $s4 = $v['s4'];
        $delay = static fn (array $leaves): ?int => preg_match('/ease-in-out (-?\d+)ms/', (string) ($leaves[0] ?? ''), $m) === 1 ? (int) $m[1] : null;

        if ($s4['phase'] !== 'chosen' || ! $s4['first_bare'] || $s4['wakes'] === 0 || $s4['repaints'] !== 1 || $s4['second_partitions'] === 0) {
            $defects[] = 'S4: a map that lands while the doors open does not repaint the cab once, woken by the press\'s fetch: '.json_encode(array_intersect_key($s4, array_flip(['phase', 'first_bare', 'wakes', 'repaints', 'second_partitions'])));
        }

        if ($s4['after_idle'] !== 0) {
            $defects[] = "S4: a frame with nothing new repaints the cab {$s4['after_idle']} more time(s) — the leaves restart on every frame";
        }

        if ($delay($s4['leaves_before']) === null || $delay($s4['leaves_before']) - $delay($s4['leaves_after']) !== $s4['elapsed_moved']) {
            $defects[] = 'S4: the repaint does not place the leaves at the phase\'s elapsed time: '.json_encode([$s4['leaves_before'], $s4['leaves_after']]);
        }

        // S6: charlie's two rooms, and the floor bare where one of them names a tileset the loader lacks.
        $s6 = $v['s6'];

        if ($s6['model']['bare'] || $s6['far_wall'] < 0) {
            $defects[] = 'S6: charlie\'s first room is not closed by its own footprint\'s far wall: '.json_encode(['model' => $s6['model'], 'far_wall' => $s6['far_wall']]);
        }

        if ($s6['missing']['model'] !== ['bare' => true, 'reason' => 'tileset']) {
            $defects[] = 'S6: a tileset the loader lacks reads '.json_encode($s6['missing']['model']).' — the whole floor bare, reason tileset';
        } else {
            array_push($defects, ...$this->bareRoomDefects('S6 (a tileset missing)', $s6['missing']['parts']));
        }

        // S8: the press with `/api/building` failing.
        foreach ($v['s8'] as $status => $s) {
            if (! $s['held_floors'] || $s['layout_requests'] !== 0 || $s['layout_failure'] !== null || ! $s['floors_kept']) {
                $defects[] = "S8 ({$status}): the press fetched the layout or set its failure: ".json_encode($s);
            }

            if (array_filter($s['room_requests'], static fn ($n) => $n !== 1) !== []) {
                $defects[] = "S8 ({$status}): the press did not fetch each other stop's room once: ".json_encode($s['room_requests']);
            }
        }

        // S9 (card#13033): `FloorData#roomTilesets()` loads one room's held map's tilesets, enters no room — bravo's failed map,
        // which any re-entry fetches again, stays unrequested — and wakes the page once per call.
        $s9 = $v['s9'];

        if (! $s9['bravo_failed']) {
            $defects[] = 'S9: the fixture\'s bravo did not fail, so the leg cannot see a room re-entered';
        }

        if ($s9['alpha_requests'] !== $s9['owed']['alpha'] || $s9['charlie2_requests'] !== $s9['owed']['charlie2']) {
            $defects[] = 'S9: one room\'s tilesets requested '.json_encode(['alpha' => $s9['alpha_requests'], 'charlie-2' => $s9['charlie2_requests']]).', not '.json_encode($s9['owed']);
        }

        if ($s9['wakes'] !== 2) {
            $defects[] = "S9: two calls of roomTilesets() woke the page {$s9['wakes']} time(s), not 2";
        }

        return $defects;
    }

    /** @return list<string> § 12's *Door-view scale* and *Partition height* rows against the model's figures */
    private function doorViewFigureDefects(array $run): array
    {
        $md = $this->floorMd();

        $this->assertSame(1, preg_match('/^\| \*\*Door-view scale\*\* \| \*\*(\d+) \/ (\d+) cm per floor px\*\* \(≈ [\d.]+\), the room\'s height \*\*(\d+) px × that = (\d+) cm\*\* \|/m', $md, $scale),
            '§ 12 carries no *Door-view scale* row in the form this test reads');
        $this->assertSame(1, preg_match('/^\| \*\*Partition height\*\* \| \*\*(\d+) cm\*\* \|/m', $md, $partition),
            '§ 12 carries no *Partition height* row in the form this test reads');

        $f = $run['door_view']['figures'];
        $defects = [];

        if ((int) $scale[1] !== $f['door_h'] || (int) $scale[2] !== $f['elevator_h'] || abs($f['scale'] - $f['door_h'] / $f['elevator_h']) > 1e-12) {
            $defects[] = "SCALE is {$f['scale']} — § 12 reads {$scale[1]} / {$scale[2]}, the cab door's {$f['door_h']} cm over the band's {$f['elevator_h']} px";
        }

        if ((int) $scale[3] !== $f['band_h'] || (int) $scale[4] !== (int) round($f['room_h']) || abs($f['room_h'] - $f['band_h'] * $f['scale']) > 1e-9) {
            $defects[] = "ROOM_H is {$f['room_h']} — § 12 reads {$scale[3]} px × the scale = {$scale[4]} cm";
        }

        if ((int) $partition[1] !== $f['partition_h']) {
            $defects[] = "PARTITION_H is {$f['partition_h']} — § 12 reads {$partition[1]} cm";
        }

        // The bare room stands under the floor view's ceiling (§ 12's *Office view*): one height, so the swap moves no ceiling.
        if ($run['door_view']['office']['h'] !== (int) round($f['room_h'])) {
            $defects[] = "OFFICE.h is {$run['door_view']['office']['h']} — the room's height, ".round($f['room_h']).' cm, is owed';
        }

        return $defects;
    }

    /** @return list<string> */
    private function bandDefects(array $run): array
    {
        $b = $run['band'];
        $e = $b['elevator'];
        $door = ['x' => $e['x'], 'y' => $e['y'], 'w' => $e['w'], 'h' => $e['h']];
        $defects = [];

        foreach (['themed', 'walking', 'unthemed'] as $case) {
            $c = $b[$case];

            if (! $c['found']) {
                $defects[] = "{$case}: the band draws no cab behind its leaves";

                continue;
            }

            if ($c['rooms'] === [] || $c['leaf'] < 0 || max($c['rooms']) > $c['leaf']) {
                $defects[] = "{$case}: a cab room is drawn over the leaves (rooms at ".json_encode($c['rooms']).", the first leaf at {$c['leaf']})";
            }

            if ($c['sides'] !== 2 || count($c['surfaces']) !== 3) {
                $defects[] = "{$case}: the band's cab is not drawCabInterior()'s room: ".json_encode(['sides' => $c['sides'], 'surfaces' => $c['surfaces']]);
            }

            if ($c['clip'] === null || $c['clip']['rect'] !== $door || $c['clip']['drawn'] === 0) {
                $defects[] = "{$case}: the band's cab is not clipped to the doorway: ".json_encode($c['clip']);
            }

            array_push($defects, ...$this->sceneryDefects("{$case}: the band's cab", $c['census'], null));

            $ids = array_values(array_filter(array_map(static fn ($n) => $n['id'], $c['census'])));

            if ($ids === [] || array_filter($ids, static fn ($id) => ! str_starts_with($id, 'band-cab-')) !== []) {
                $defects[] = "{$case}: the band's cab's ids are not its own (band-cab-…): ".json_encode($ids);
            }

            if (! $c['scene']) {
                $defects[] = "{$case}: painting the band changed the scene it was handed: doors ".json_encode($c['doors']);
            }

            // card#12071: seen from the landing, its far wall at `LANDING.view / LANDING.back` of the doorway; and no call
            // plate — that is on the wall the landing looks through.
            $k = $run['depth']['landing']['view'] / $run['depth']['landing']['back'];

            if ($c['back'] === null || abs($c['back']['w'] / $door['w'] - $k) > 0.005 || abs($c['back']['h'] / $door['h'] - $k) > 0.005) {
                $defects[] = "{$case}: the band's cab is not seen from the landing's eye: ".json_encode(['back' => $c['back'], 'doorway' => $door]);
            }

            if ($c['plates'] !== 0) {
                $defects[] = "{$case}: the band's cab draws the call plate from inside";
            }
        }

        $palette = $b['palette'];

        if ($b['themed']['carriers'] !== "--cab-frame:{$palette['frame']};--cab-lamp:{$palette['lamp']}") {
            $defects[] = 'with the house theme, the band\'s cab is not in its elevator palette: '.json_encode($b['themed']['carriers']);
        }

        if ($b['unthemed']['carriers'] !== null || ! str_contains($run['style'], '.band-cab{--cab-frame:var(--door-frame);--cab-lamp:var(--lamp)}')) {
            $defects[] = 'with no theme, the band\'s cab does not fall back to the sheet\'s door frame and lamp';
        }

        if ($b['walking']['doors'] !== [['from' => 3, 'to' => 8]]) {
            $defects[] = 'the walk\'s door spans are not what the scene carried: '.json_encode($b['walking']['doors']);
        }

        return $defects;
    }

    /** @return list<string> */
    private function plateDefects(array $run): array
    {
        $p = $run['plate'];

        if ($p['count'] !== 1 || $p['body'] === null) {
            return ["the cab draws {$p['count']} call plates, not one"];
        }

        $defects = [];
        $attrs = $p['attrs'];

        if (($attrs['aria-hidden'] ?? null) !== 'true') {
            $defects[] = 'the call plate is not aria-hidden: '.json_encode($attrs);
        }

        $reach = array_intersect(array_keys($attrs), ['tabindex', 'role', 'aria-label', 'href', 'onclick']);

        if ($reach !== [] || $p['listeners'] !== [] || array_filter($p['census'], static fn ($n) => $n['tag'] === 'a') !== []) {
            $defects[] = 'the call plate can be reached or pressed: '.json_encode(['attrs' => $attrs, 'listeners' => $p['listeners']]);
        }

        if (preg_match('/\.cab-plate\{pointer-events:none\}/', $run['style']) !== 1) {
            $defects[] = 'the call plate takes a pointer: STYLE gives .cab-plate no pointer-events:none';
        }

        array_push($defects, ...$this->sceneryDefects('the call plate', $p['census'], 'cab-plate-'));

        if ($p['buttons'] < 3 || $p['windows'] !== 1) {
            $defects[] = "the call plate draws {$p['buttons']} buttons and {$p['windows']} indicator windows — a plate has a few buttons and one window";
        }

        // Beside the door's frame on the doors' wall, never over the door.
        [$b, $f, $r] = [$p['back'], $p['frame'], $p['body']];

        if (! ($r['x'] >= $f['x'] + $f['w'] && $r['x'] + $r['w'] <= $b['x'] + $b['w'] && $r['y'] >= $b['y'] && $r['y'] + $r['h'] <= $b['y'] + $b['h'])) {
            $defects[] = 'the call plate is not on the doors\' wall beside the frame: '.json_encode(['plate' => $r, 'frame' => $f, 'wall' => $b]);
        }

        if (! ($p['before']['plate'] >= 0 && $p['before']['plate'] < $p['before']['beyond'])) {
            $defects[] = 'the call plate is not drawn under the layer behind the leaves: '.json_encode($p['before']);
        }

        return $defects;
    }

    /** @return list<string> the doorway's share of the cab's view against card#12051's eye's */
    private function closerDefects(array $run): array
    {
        // card#12051's cab: 160 × 230 cm open front, its doors' wall at 230 / (230 + 140) of it, the door 110 × 200 cm.
        $k = 230 / (230 + 140);
        $before = (110 * $k * 200 * $k) / (160 * 230);
        $share = $run['closer']['share'];

        return $share !== null && $share >= 1.2 * $before
            ? []
            : ['the doors take '.json_encode($share).' of the cab\'s view — not a fifth more than card#12051\'s '.round($before, 3)];
    }

    /** @return list<string> nothing but the leaves (and, on the band, its hit area) drawn after the layer behind them */
    private function seamDefects(array $run): array
    {
        $defects = [];

        if ($run['seam']['cab'] !== ['g.cab-beyond', 'rect.elevator-leaf left', 'rect.elevator-leaf right']) {
            $defects[] = 'the cab draws more than its leaves after the layer behind them: '.json_encode($run['seam']['cab']);
        }

        if ($run['seam']['band'] !== ['rect.elevator-leaf left', 'rect.elevator-leaf right', 'rect.elevator-hit']) {
            $defects[] = 'the band\'s elevator draws more than its leaves and its hit area: '.json_encode($run['seam']['band']);
        }

        return $defects;
    }

    /** @return list<string> `STYLE` against the one `color-mix()` fallback shape (`HoldsEveryColourMixToAFallback`) */
    private function mixDefects(array $run): array
    {
        return $this->colourMixDefects($run['style'], 'STYLE');
    }

    /** @return list<string|null> every style the ride gives a leaf: the band's in each phase at both times, and the cab's */
    private function rideStyles(array $run): array
    {
        $styles = [];

        foreach ($run['leaves'] as $times) {
            foreach ($times as $leaves) {
                array_push($styles, ...$leaves);
            }
        }

        foreach (['entering', 'inside', 'chosen', 'leaving'] as $phase) {
            array_push($styles, ...$run['cab'][$phase]['leaves']);
        }

        return $styles;
    }

    /** @return list<string> every ride leaf style quantised with `steps(` — the walks' form, which the ride never takes */
    private function steppedDefects(array $run): array
    {
        return array_values(array_map(static fn ($s) => "a ride door is stepped: {$s}",
            array_filter($this->rideStyles($run), static fn ($s) => str_contains((string) $s, 'steps('))));
    }
}
