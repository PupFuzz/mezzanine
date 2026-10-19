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
 * ⛔ THE CAB IS A ROOM IN DEPTH (card#12051): a one-point perspective of § 12's *Cab interior* (`painter.js`'s `CAB`,
 * held equal to that row here) — a narrow space taller than it is wide, the back wall carrying the door inset on
 * every side of the cab's opening, two side walls each a trapezoid whose far edge is shorter than its near one, a
 * ceiling over the back wall and a floor under it, each painted by its class from `--cab-frame`, never inline, and each
 * with its depth overlay — the back wall's an even one (card#12052: § 4.5 says every surface is shaded); the leaves on the
 * back wall; and the layer behind the leaves (`.cab-beyond`) over the doorway's recess and under the leaves.
 * `drawCabInterior()` draws the same room, and no door, into any box it is handed, and answers its back wall as it drew it.
 *
 * ⛔ THROUGH THE OPEN DOORS (card#12052). In the cab, the layer behind the leaves holds the office (`drawOfficeView()`),
 * clipped to the doorway the leaves span: a floor, a ceiling, two walls and a far one, windows, two desks, two chairs and a
 * plant — SCENERY, so every element in it is the office's own class, and none carries a word, an image, a datum
 * attribute, a colour of its own or any motion (§ 6.3). On the band, `drawCabInterior()`'s room sits behind the leaves,
 * clipped to the doorway, with ids of its own, in the house theme's elevator palette when it loaded and the sheet's
 * door frame and lamp when not, still, and the scene it was painted from — `scene.doors` included — unchanged. NOTHING
 * IS DRAWN AFTER THE LEAVES in the cab's drawing, nor in the band's elevator control but its hit area: the seam is the
 * leaves' own edges, so no seam line of either stands in the open doorway — a structural reading; that nothing at all
 * crosses the open doorway as drawn is `floor-ride.browser.mjs`'s `through-doors` row's, run by hand. And every
 * `color-mix()` in `STYLE` sits in its `@supports` block after a plain rule of the same selector setting the same property
 * to one of the mix's own colours, the fallback a browser without `color-mix()` paints (`HoldsEveryColourMixToAFallback`,
 * the one check the sheet is held to as well).
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

    public function test_the_cab_doors_open_onto_the_office(): void
    {
        $this->assertSame([], $this->officeDefects($this->run_()));
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

    /** § 12's *Office view* row is `painter.js`'s `OFFICE`, figure for figure. */
    public function test_green_section_12s_office_view_is_office(): void
    {
        $this->assertSame(1, preg_match('/^\| \*\*Office view\*\* \| \*\*(\d+) × (\d+) × (\d+) cm\*\* wide × deep × high, its left wall (\d+) cm left of the door\'s centre line, the eye (\d+) cm from the doorway \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Office view* row in the form this test reads');

        $this->assertSame(['w' => (int) $m[1], 'd' => (int) $m[2], 'h' => (int) $m[3], 'left' => (int) $m[4], 'view' => (int) $m[5]], $this->run_()['office']['office']);
    }

    /** § 12's *Cab interior* row is `painter.js`'s `CAB`, figure for figure. */
    public function test_green_section_12s_cab_interior_is_cab(): void
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab interior\*\* \| \*\*(\d+) × (\d+) × (\d+) cm\*\* wide × deep × high, the eye (\d+) cm over the floor and (\d+) cm from the cab\'s open front, the door (\d+) × (\d+) cm \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab interior* row in the form this test reads');

        $this->assertSame(
            ['w' => (int) $m[1], 'd' => (int) $m[2], 'h' => (int) $m[3], 'eye' => (int) $m[4], 'view' => (int) $m[5], 'door' => ['w' => (int) $m[6], 'h' => (int) $m[7]]],
            $this->run_()['depth']['cab'],
        );
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
            'a word in the cab' => ["        const style = cabLeaves(ride);\n", "        const style = cabLeaves(ride);\n\n        node('text', {}, cab).textContent = 'B';\n", 'cabDefects'],
            // card#12038's RED: the ride's doors quantised again — by any step count — red the step check itself.
            'the ride doors stepped' => ["const RIDE_DOOR_EASING = 'ease-in-out';", "const RIDE_DOOR_EASING = 'steps(2)';", 'steppedDefects'],
            'the ride doors stepped, the cab alone' => ["        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING}',
                "        case 'chosen':\n".'            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms steps(4)', 'steppedDefects'],
            'the band doors linear' => ['            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms ${RIDE_DOOR_EASING} ${delay}ms forwards`;',
                '            return ride.ms === 0 ? \'transform:scaleX(.12)\' : `animation:leaf-open ${motion}ms linear ${delay}ms forwards`;', 'leafDefects'],
            // card#12039's REDs: the cab cropped again, the palette not carried, a stale palette kept, the frame or the
            // lamp painted off the carrier.
            'the cab sliced' => ["preserveAspectRatio: 'xMidYMid meet'", "preserveAspectRatio: 'xMidYMid slice'", 'paletteDefects'],
            'the palette not carried' => ["        el.style.setProperty('--cab-frame', palette.frame);\n", '', 'paletteDefects'],
            'a stale palette kept' => ["            el.style.removeProperty('--cab-lamp');\n", '', 'paletteDefects'],
            // card#12047's: the dialog never marked themed, or marked with no theme loaded.
            'the dialog not marked' => ["        el.toggleAttribute('data-themed', palette !== null);\n", '', 'paletteDefects'],
            'marked with no theme' => ["        el.toggleAttribute('data-themed', palette !== null);", "        el.toggleAttribute('data-themed', true);", 'paletteDefects'],
            'the frame off the palette' => ['.cab-frame{fill:var(--cab-frame);', '.cab-frame{fill:var(--door-frame);', 'paletteDefects'],
            'the lamp off the palette' => ['.cab-lamp{fill:var(--cab-lamp)}', '.cab-lamp{fill:var(--lamp)}', 'paletteDefects'],
            // card#12051's: the cab flattened back to a front-on door, its side walls gone or off the palette, drawn as
            // wide as a room, painted past its opening, or with no layer behind its leaves.
            'the cab flattened' => ['        const s = CAB.view / (CAB.view + Z);', '        const s = 1;', 'depthDefects'],
            'no side walls' => ["    face('cab-side left', ", "    false && face('cab-side left', ", 'depthDefects'],
            'a side wall off the palette' => ['.cab-side{fill:color-mix(in srgb,var(--cab-frame) 88%,var(--door))}', '.cab-side{fill:var(--door)}', 'depthDefects'],
            'the ceiling inline' => ["    face('cab-ceiling', pts(", "    node('polygon', { fill: 'tan', points: pts(at(l, top, 0)) }, g).setAttribute('class', 'cab-ceiling');\n    face('cab-ceiling-gone', pts(", 'depthDefects'],
            'the cab as wide as a room' => ['export const CAB = Object.freeze({ w: 160,', 'export const CAB = Object.freeze({ w: 400,', 'depthDefects'],
            'no layer behind the leaves' => ["        drawOfficeView(node('g', { class: 'cab-beyond' }, cab), L, 'cab-office');\n", '', 'depthDefects'],
            'the layer over the leaves' => ["        drawOfficeView(node('g', { class: 'cab-beyond' }, cab), L, 'cab-office');\n\n        const style = cabLeaves(ride);\n\n        // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.\n        node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);\n        node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n",
                "\n        const style = cabLeaves(ride);\n\n        // The two leaves meet at the seam, each edged in the door's edge colour: the seam is theirs, so it opens with them.\n        node('rect', { x: L.x, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf left', style }, cab);\n        node('rect', { x: seam + f, y: L.y, width: L.w / 2 - f, height: L.h, rx: 3 * f, class: 'elevator-leaf right', style }, cab);\n        drawOfficeView(node('g', { class: 'cab-beyond' }, cab), L, 'cab-office');\n", 'depthDefects'],
            'the cab painted past its opening' => ["preserveAspectRatio: 'xMidYMid meet', overflow: 'hidden'", "preserveAspectRatio: 'xMidYMid meet', overflow: 'visible'", 'paletteDefects'],
            'the room alone not answering its back wall' => ['    return { back };', '    return { back: { x: 0, y: 0, w: box.w, h: box.h } };', 'depthDefects'],
            // card#12052's: the ceiling or the back wall left unshaded (§ 4.5 says every surface deepens toward the back).
            'the ceiling unshaded' => ["at(l, top, D)), 'shade-ceiling');", 'at(l, top, D)));', 'depthDefects'],
            'the back wall unshaded' => ["class: 'cab-shade cab-back-shade', 'fill-opacity': FAR }, g);", "class: 'cab-back-shade-gone', 'fill-opacity': FAR }, g);", 'depthDefects'],
            // card#12052's: the office missing, unclipped, peopled, named, painted inline or moving; the band's cab missing,
            // unclipped, over the leaves, moving, adding a door span, sharing the dialog's ids or off the palette; a seam line
            // over either open doorway; a mixed colour with no fallback, or mixing outside its block.
            'the office not drawn' => ["        drawOfficeView(node('g', { class: 'cab-beyond' }, cab), L, 'cab-office');", "        node('g', { class: 'cab-beyond' }, cab);", 'officeDefects'],
            'the office unclipped' => ['    const room = clippedTo(g, defs, box, id);', '    const room = g;', 'officeDefects'],
            'a character in the office' => ['    return { clip: box };', "    node('image', { class: 'character', href: 'data:,' }, room);\n    return { clip: box };", 'officeDefects'],
            'a name in the office' => ['    return { clip: box };', "    node('text', { class: 'office-plate' }, room).textContent = 'aimla';\n    return { clip: box };", 'officeDefects'],
            'an inline hex in the office' => ["    face('office-floor', pts(", "    node('polygon', { points: pts(at(l, 0, 0)), fill: '#c0ffee', class: 'office-rug' }, room);\n    face('office-floor', pts(", 'officeDefects'],
            'the office moving' => ['    return { clip: box };', "    node('animateTransform', { attributeName: 'transform', type: 'translate', dur: '4s' }, room);\n    return { clip: box };", 'officeDefects'],
            'no cab behind the band\'s leaves' => ["            drawCabInterior(clippedTo(inside, node('defs', {}, inside), doorway, 'band-cab'), doorway, 'band-cab');\n", '', 'bandDefects'],
            'the band\'s cab unclipped' => ["drawCabInterior(clippedTo(inside, node('defs', {}, inside), doorway, 'band-cab'), doorway, 'band-cab');", "drawCabInterior(inside, doorway, 'band-cab');", 'bandDefects'],
            'the band\'s cab over the leaves' => ["class: 'elevator-leaf right', style: leaves }, control);\n", "class: 'elevator-leaf right', style: leaves }, control);\n            drawCabInterior(control, doorway, 'band-cab-over');\n", 'bandDefects'],
            'the band\'s cab moving' => ["doorway, 'band-cab'), doorway, 'band-cab');\n", "doorway, 'band-cab'), doorway, 'band-cab');\n            node('animate', { attributeName: 'opacity', dur: '1s' }, inside.children[1]);\n", 'bandDefects'],
            'the band\'s cab adding a door span' => ["            const doorway = { x: e.x, y: e.y, w: e.w, h: e.h };\n", "            const doorway = { x: e.x, y: e.y, w: e.w, h: e.h };\n            (scene.doors ??= []).push({ from: 0, to: 1 });\n", 'bandDefects'],
            'the band\'s cab sharing the dialog\'s ids' => ["doorway, 'band-cab'), doorway, 'band-cab');", "doorway, 'band-cab'), doorway, 'cab');", 'bandDefects'],
            'the band\'s cab off the palette' => ['                style: palette === null ? null : `--cab-frame:${palette.frame};--cab-lamp:${palette.lamp}`,', '                style: null,', 'bandDefects'],
            'a seam line over the band\'s doorway' => ["class: 'elevator-leaf right', style: leaves }, control);\n", "class: 'elevator-leaf right', style: leaves }, control);\n            node('line', { x1: e.seam, y1: e.y, x2: e.seam, y2: e.y + e.h, class: 'elevator-seam' }, control);\n", 'seamDefects'],
            'a seam line over the cab\'s doorway' => ["class: 'elevator-leaf right', style }, cab);\n", "class: 'elevator-leaf right', style }, cab);\n        node('line', { x1: seam, y1: L.y, x2: seam, y2: L.y + L.h, class: 'elevator-seam' }, cab);\n", 'seamDefects'],
            'no fallback for a mixed colour' => ['${unmixed(MIXED)}@supports', '@supports', 'mixDefects'],
            'the fallback still mixing' => ['const unmixed = (css) => css.replace(', 'const unmixed = (css) => css || css.replace(', 'mixDefects'],
            'a mix outside its block' => ['.cab-sill{fill:var(--door-edge)}', '.cab-sill{fill:color-mix(in srgb,var(--door-edge) 50%,var(--door))}', 'mixDefects'],
            // card#12070 (PR #343 review F4): a fallback that paints another property than its mix, or a colour that is not
            // one of the mix's own, or that comes after the block (overriding the mix where color-mix() is supported).
            'the fallback on another property' => ['${unmixed(MIXED)}@supports', '${unmixed(MIXED).replace(\'.cab-back{fill:\', \'.cab-back{stroke:\')}@supports', 'mixDefects'],
            'the fallback off the mix\'s colours' => ['${unmixed(MIXED)}@supports', '${unmixed(MIXED).replace(\'.cab-back{fill:var(--cab-frame)}\', \'.cab-back{fill:var(--lamp)}\')}@supports', 'mixDefects'],
            'the fallback after its block' => ['${unmixed(MIXED)}@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}', '@supports (fill:color-mix(in srgb,currentColor 50%,transparent)){${MIXED}}${unmixed(MIXED)}', 'mixDefects'],
            'the cab doors not closing on entering' => ["        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-close", "        case 'entering':\n            return ride.ms === 0 ? null : `animation:leaf-open", 'cabDefects'],
        ] as $what => [$from, $to, $check]) {
            $dir = $this->mutatedModules(['painter.js', $from, $to]);
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

        // An elevator's shape: taller than it is wide, and nowhere near the window's.
        if ($w / $h < 0.6 || $w / $h > 0.8) {
            $defects[] = "the cab's opening is {$w} × {$h} — not an elevator's narrow, tall shape";
        }

        // The back wall inset on every side of the opening, by more than a frame's width: the room has depth.
        if (! ($b['x'] > 0.1 * $w && $b['x'] + $b['w'] < 0.9 * $w && $b['y'] > 0.1 * $h && $b['y'] + $b['h'] < 0.9 * $h)) {
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

    /** @return list<string> */
    private function officeDefects(array $run): array
    {
        $o = $run['office'];
        $defects = [];

        if (! $o['found'] || $o['beyond'] !== 1) {
            return ['the cab\'s layer behind the leaves holds no office view, or more than one'];
        }

        $near = static fn ($a, $b) => $a !== null && $b !== null && max(array_map(static fn ($k) => abs($a[$k] - $b[$k]), ['x', 'y', 'w', 'h'])) < 0.01;

        if ($o['clip'] === null || ! $near($o['clip']['rect'], $o['door']) || $o['clip']['drawn'] === 0) {
            $defects[] = 'the office is not drawn clipped to the doorway the leaves span: '.json_encode(['clip' => $o['clip'], 'door' => $o['door']]);
        }

        $count = static fn ($cls) => count(array_filter($o['census'], static fn ($n) => in_array($cls, $n['cls'], true)));

        foreach (['office-floor' => 1, 'office-ceiling' => 1, 'office-wall' => 2, 'office-back' => 1, 'office-glass' => 1, 'office-desk-top' => 2, 'office-chair' => 2, 'office-pot' => 1, 'office-leaf' => 1] as $part => $least) {
            if ($count($part) < $least) {
                $defects[] = "the office draws {$count($part)} .{$part}, fewer than {$least}";
            }

            // The glass is painted by its gradient, whose stops are classes of their own (below).
            if ($part !== 'office-glass' && preg_match('/\.'.preg_quote($part, '/').'\{[a-z-]+:/', $run['style']) !== 1) {
                $defects[] = "STYLE paints no .{$part}";
            }
        }

        foreach (['office-sky-stop', 'office-day-stop', 'office-shade-stop'] as $stop) {
            if (preg_match('/\.'.$stop.'\{stop-color:var\(--[a-z0-9-]+\)\}/', $run['style']) !== 1) {
                $defects[] = "STYLE does not colour .{$stop} from a token";
            }
        }

        array_push($defects, ...$this->sceneryDefects('the office', $o['census'], 'office-'));

        $a = $o['alone'];

        if ($a['answered'] !== $a['box'] || $a['clip'] === null || $a['clip']['rect'] !== $a['box']) {
            $defects[] = 'drawOfficeView() alone is not clipped to the box it was handed, or does not answer it: '.json_encode($a);
        }

        if ($a['ids'] === [] || array_filter($a['ids'], static fn ($id) => ! str_starts_with($id, 'probe-office-')) !== []) {
            $defects[] = 'drawOfficeView()\'s ids are not the prefix it was handed: '.json_encode($a['ids']);
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
