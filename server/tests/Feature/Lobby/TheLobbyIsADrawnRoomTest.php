<?php

namespace Tests\Feature\Lobby;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Floor\DrivesTheFleetClientModule;
use Tests\TestCase;

/**
 * **The lobby is a drawn room, and the floor directory is painted on its wall** — `docs/design/FLOOR.md § 4.1`'s *The room,
 * the directory and the elevator* (card#12113, Appendix B row 24): the room as shapes carrying no fact
 * (`lobby/building-scene.js`), painted once (`lobby/building-paint.js`), and the directory's plaques painted from the model
 * the lobby decided (`lobby/directory.js`), their text counter-scaled by the page's one camera hook and scrolling inside
 * the board's box. The plaque's FIGURES are `TheDirectoryPlaqueReadsWhatTheClientHoldsTest`'s (AT-D3-27); what is held
 * here is that the page draws them as decided — every figure where § 4.1 puts it, nothing re-derived — and draws the
 * room from nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE PLAQUES PAINTED ARE THE MODEL's OWN, READ OFF THE HARNESS: `fx-lobby.json`'s `lobby_markers` (two rooms, the
 * marker cap reached, an unrecognised state) and `lobby_attention` (three attention parts) replayed through the lobby
 * screen, each frame's `summary.floors` handed to `lobby-room-probe.mjs`, which paints them over a fake DOM.
 *
 * ⚠ WHAT NO CHECK HERE SEES: the pixels. Whether the text is legible on the plaque, whether a line is cut rather than
 * wrapped and whether the directory stays on the board at every zoom are rendered facts; the sheet's rules are read here as
 * rules, and `tools/design/lobby-directory.browser.mjs` measures the render by hand in headless Chromium (§ 4.1's directory
 * claims name which check holds which).
 */
class TheLobbyIsADrawnRoomTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    private const PROBE = __DIR__.'/lobby-room-probe.mjs';

    // ── GREEN — the room ─────────────────────────────────────────────────────────────────────

    public function test_green_the_room_is_drawn_from_nothing_and_its_only_words_are_the_signs(): void
    {
        $this->assertSame([], $this->roomDefects($this->paint([])['room'], $this->jsRoot()));
    }

    /** § 12's *Lobby room drawing*: the room's scene size and its ceiling grid are the row's figures, and the panels are an even grid receding. */
    public function test_green_the_room_is_section_12s_size_and_its_ceiling_an_even_grid_of_panels(): void
    {
        $this->assertSame([], $this->gridDefects($this->paint([])['room']));
    }

    // ── GREEN — the directory ────────────────────────────────────────────────────────────────

    public function test_green_each_plaque_is_painted_as_the_model_decided_it(): void
    {
        foreach (['lobby_markers', 'lobby_attention', 'lobby_plaque'] as $run) {
            $plaques = $this->plaquesOf($run);
            $this->assertSame([], $this->plaqueDefects($plaques, $this->paint($plaques)['directory']['plaques']), "[{$run}]");
        }
    }

    public function test_green_the_unclaimed_and_the_empty_directory_are_said_in_words(): void
    {
        $this->assertSame([], $this->unclaimedDefects($this->paint([], [['install_id' => 'aimla', 'href' => '/floor/aimla', 'held' => 1]])['directory']));
    }

    /**
     * § 4.1: a re-render over the same floors — every render's, and the 1 s tick's — brings each plaque up to date in place:
     * its link is the same element, so the keyboard's focus on it survives, and every row reads the new figures.
     */
    public function test_green_a_re_render_keeps_the_plaques_link_and_redraws_its_rows(): void
    {
        $this->assertSame([], $this->rerenderDefects($this->plaquesOf('lobby_markers')));
        $this->assertSame([], $this->unclaimedRerenderDefects());
    }

    /**
     * § 4.1 / § 2.5: the plaque's ages tick on the 1 s tick (`wire/age-readout.js`'s ticker, the lobby page's `tickView()`)
     * — the last activity's age moving on, everything else on the plaque as it was — and the tick draws no § 6.2 row and
     * moves no camera, since a tick is not an apply.
     */
    public function test_green_the_plaques_ages_tick_each_second_and_nothing_else_moves(): void
    {
        $this->assertSame([], $this->tickDefects($this->tickRun()));
    }

    /** § 4.1: the one writer of the counter-scale, the boxes at their rects while the room is drawn, and nothing before it. */
    public function test_green_the_text_is_counter_scaled_by_one_writer_inside_boxes_on_the_board(): void
    {
        $this->assertSame([], $this->scaleDefects($this->paint([])['directory']));
    }

    /** § 4.1: the list scrolls inside its box and declares it; every line wraps and none is cut; the face is the page's own size; the title is HTML. */
    public function test_green_the_directory_scrolls_inside_its_box_and_every_line_reads_whole(): void
    {
        $this->assertSame([], $this->sheetDefects($this->sheet(), $this->lobbyPage()));
    }

    /**
     * § 4.1 claim 14 (card#12187, decision 72): `directory.js`'s docking entry against § 12's *Directory dock width* — the
     * boxes flow with no room drawn, stand on the board above the bound and dock under it, re-asked on a paint, a resize
     * and a change of the page's text size; on the board the faces' text at the page's size on a face that holds the board
     * face and shrunk to fit a smaller one, never under the floor; and it alone writes their `data-home`, their
     * counter-scale and text share and the surface's `touch-action`.
     */
    public function test_green_the_docking_entry_docks_the_boxes_under_the_bound(): void
    {
        $this->assertSame([], $this->dockDefects($this->dockRun()));
    }

    /**
     * § 4.1: the lobby page sets no text size of its own — no second stylesheet, no style block or attribute sizing text,
     * and on the page chrome sheet no text size on the root, the body, `*` or a tag `directory.js` builds, and none with
     * `!important` — so the directory's `1rem` is the viewer's body text size. (Carried from the plate label's check, card#7343,
     * when card#12113 retired the plate.)
     */
    public function test_green_the_lobby_page_sets_no_text_size_of_its_own(): void
    {
        $this->assertSame([], $this->baseDefects($this->lobbyPage(), $this->sheet()));
    }

    // ── RED ──────────────────────────────────────────────────────────────────────────────────

    public function test_red_a_room_drawn_from_a_fact(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-scene.js', 'export function roomArt() {', 'export function roomArt(floors = []) {']);

        $this->assertNotSame([], $this->roomDefects($this->paint([], [], dirname($dir))['room'], dirname($dir)), 'RED (a room taking a fact) did not bite');
    }

    public function test_red_a_ceiling_grid_off_its_bays(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-scene.js', '            const xc = (col + 0.5) * bayX;', '            const xc = (col + 0.5) * bayX + col * col * 20;']);

        $this->assertNotSame([], $this->gridDefects($this->paint([], [], dirname($dir))['room']), 'RED (an uneven ceiling grid) did not bite');
    }

    public function test_red_a_ceiling_grid_that_does_not_recede(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-scene.js', '        const zc = (row + 0.5) * bayZ;', '        const zc = 0.5 * bayZ;']);

        $this->assertNotSame([], $this->gridDefects($this->paint([], [], dirname($dir))['room']), 'RED (panels that do not recede) did not bite');
    }

    /** Each plaque defect planted in `directory.js` — the page re-deciding or dropping what the model decided. */
    public function test_red_each_plaque_defect(): void
    {
        // The two runs between them carry every row a plaque has: two rooms, the cap, an unrecognised state, three parts.
        $plaques = [...$this->plaquesOf('lobby_markers'), ...$this->plaquesOf('lobby_attention')];

        foreach ([
            'the accessible name rebuilt by the page' => ["    link.setAttribute('aria-label', plaque.accessible_name);", "    link.setAttribute('aria-label', plaque.name);"],
            'the link to the label' => ['    link.href = plaque.href;', '    link.href = `/floor/${plaque.name}`;'],
            'the attention parts joined on one row' => ["    for (const part of plaque.attention ?? []) {\n        const line = el(doc, 'div', `lobby-plaque-row lobby-plaque-attention \${part.part}`, part.text);\n\n        row.append(line);\n    }",
                "    if (plaque.attention !== null) {\n        row.append(el(doc, 'div', 'lobby-plaque-row lobby-plaque-attention', plaque.attention.map((p) => p.text).join(' · ')));\n    }"],
            'the more tag dropped' => ["        markerRow.append(el(doc, 'span', 'lobby-marker-more', plaque.markers.more));", ''],
            'a marker with no name' => ["        node.setAttribute('aria-label', marker.name);\n", ''],
            'the chips re-counted' => ['const node = el(doc, \'span\', `lobby-chip${chip.recognised ? \'\' : \' unrecognised\'}`, chip.text);', 'const node = el(doc, \'span\', `lobby-chip${chip.recognised ? \'\' : \' unrecognised\'}`, `${chip.count} ${chip.state}`);'],
            'the rooms of a two-room floor dropped' => ['    if (plaque.rooms.length > 1 || plaque.rooms.some((room) => !room.reported)) {', '    if (false) {'],
            'the staffing re-derived' => ["    head.append(el(doc, 'span', 'lobby-plaque-staffing', plaque.staffing.text));", "    head.append(el(doc, 'span', 'lobby-plaque-staffing', `\${plaque.held} agents · \${plaque.held} desks`));"],
        ] as $what => [$from, $to]) {
            $dir = $this->mutatedModules(['../lobby/directory.js', $from, $to]);
            $this->assertNotSame([], $this->plaqueDefects($plaques, $this->paint($plaques, [], dirname($dir))['directory']['plaques']), "RED ({$what}) did not bite");
        }
    }

    /** A re-render that rebuilds every plaque: the focused link is removed, and the keyboard's place with it, every second. */
    public function test_red_a_re_render_that_rebuilds_the_plaques(): void
    {
        $dir = $this->mutatedModules(['../lobby/directory.js', '    const same = held.length === rows.length && held.every(', '    const same = false && held.every(']);

        $this->assertNotSame([], $this->rerenderDefects($this->plaquesOf('lobby_markers'), dirname($dir)), 'RED (a re-render rebuilding every plaque) did not bite');
        $this->assertNotSame([], $this->unclaimedRerenderDefects(dirname($dir)), 'RED (a re-render rebuilding every unclaimed room) did not bite');
    }

    /** A tick that re-reads nothing: the ages stand still between renders. */
    public function test_red_a_tick_that_re_reads_no_age(): void
    {
        $dir = $this->mutatedModules(['../lobby/lobby-screen.js', '        this.#drawn = this.#withRide({ ...this.#drawn, summary: this.#summary() }, this.#clock.now());',
            '        this.#drawn = this.#withRide(this.#drawn, this.#clock.now());']);

        $this->assertNotSame([], $this->tickDefects($this->tickRun($dir)), 'RED (a tick that re-reads no age) did not bite');
    }

    public function test_red_a_counter_scale_that_does_not_invert_the_zoom(): void
    {
        $dir = $this->mutatedModules(['../lobby/directory.js', "    layer.style.setProperty('--lobby-scale', String(1 / camera.zoom));", "    layer.style.setProperty('--lobby-scale', String(camera.zoom));"]);

        $this->assertNotSame([], $this->scaleDefects($this->paint([], [], dirname($dir))['directory']), 'RED (a counter-scale that scales with the camera) did not bite');
    }

    public function test_red_a_face_that_is_not_its_box_on_the_screen(): void
    {
        $dir = $this->mutatedModules(['../lobby/directory.js', "            width: drawn ? `calc(\${rect.w / text}px * var(--lobby-zoom, 1))` : '',", "            width: drawn ? `\${rect.w / text}px` : '',"]);

        $this->assertNotSame([], $this->scaleDefects($this->paint([], [], dirname($dir))['directory']), 'RED (a face not sized to its box on the screen) did not bite');
    }

    public function test_red_each_sheet_defect(): void
    {
        $sheet = $this->sheet();
        $page = $this->lobbyPage();

        foreach ([
            'a directory that does not scroll' => [".lobby [data-home=\"board\"] .lobby-plaques {\n    overflow-y: auto;", ".lobby [data-home=\"board\"] .lobby-plaques {\n    overflow-y: hidden;"],
            'a directory the browser scrolls natively' => [".lobby [data-home=\"board\"] .lobby-plaques {\n    overflow-y: auto;\n    touch-action: none;", ".lobby [data-home=\"board\"] .lobby-plaques {\n    overflow-y: auto;\n    touch-action: auto;"],
            // card#12187: `touch-action: none` or `pan-y` left on the docked list, and the tap target's floor dropped.
            'touch-action: none left on the docked list' => [".lobby .lobby-plaques {\n    flex: 1 1 auto;", ".lobby .lobby-plaques {\n    touch-action: none;\n    flex: 1 1 auto;"],
            'pan-y left on the docked list' => [".lobby .lobby-plaques {\n    flex: 1 1 auto;", ".lobby .lobby-plaques {\n    touch-action: pan-y;\n    flex: 1 1 auto;"],
            "a docked link's minimum height removed" => ["    min-width: 24px;\n    min-height: 24px;\n", "    min-width: 24px;\n"],
            'the un-drawn colours keyed back on the layer' => ['.lobby [data-home="flow"] .lobby-reading,', '.lobby #lobby-room:not([data-drawn]) .lobby-reading,'],
            'a row that does not wrap' => [".lobby .lobby-plaque-row {\n    display: flex;\n    flex-wrap: wrap;", ".lobby .lobby-plaque-row {\n    display: flex;\n    flex-wrap: nowrap;"],
            'a row cut at its end' => [".lobby .lobby-plaque-row {\n    display: flex;\n", ".lobby .lobby-plaque-row {\n    white-space: nowrap;\n    text-overflow: ellipsis;\n    display: flex;\n"],
            'a reading cut at its end' => [".lobby .lobby-reading li {\n    margin: 0;\n", ".lobby .lobby-reading li {\n    margin: 0;\n    white-space: nowrap;\n    text-overflow: ellipsis;\n"],
            'the agents and desks that shrink' => [".lobby .lobby-plaque-staffing {\n    flex: none;", ".lobby .lobby-plaque-staffing {\n    flex: 0 3 auto;"],
            'a word never broken' => ["    color: var(--lobby-plaque-ink);\n    overflow-wrap: anywhere;\n}", "    color: var(--lobby-plaque-ink);\n}"],
            'a face of its own size' => [".lobby .lobby-face {\n    box-sizing: border-box;\n    font-size: 1rem;", ".lobby .lobby-face {\n    box-sizing: border-box;\n    font-size: 13px;"],
        ] as $what => [$from, $to]) {
            $planted = str_replace($from, $to, $sheet);
            $this->assertNotSame($planted, $sheet, "the {$what} RED's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->sheetDefects($planted, $page), "RED ({$what}) did not bite");
        }

        $undeclared = str_replace(' data-camera-scrolls>', '>', $page);
        $this->assertNotSame($undeclared, $page);
        $this->assertNotSame([], $this->sheetDefects($sheet, $undeclared), 'RED (a directory that does not declare it scrolls) did not bite');

        foreach (['<section id="lobby-directory" aria-labelledby="lobby-directory-heading" data-home="flow">', '<div id="lobby-readings" data-home="flow">'] as $shipped) {
            $unshipped = str_replace(' data-home="flow"', '', $shipped);
            $planted = str_replace($shipped, $unshipped, $page);
            $this->assertNotSame($planted, $page, "the markup's {$shipped} anchor is gone");
            $this->assertNotSame([], $this->sheetDefects($sheet, $planted), "RED (the markup's data-home=\"flow\" removed from {$unshipped}) did not bite");
        }
    }

    /** Each docking-entry defect planted in `directory.js` — the bound, the text-size observer, the surface, the moves. */
    public function test_red_each_docking_entry_defect(): void
    {
        foreach ([
            'the floor set to nothing (the box back on the board at a phone)' => ['export const TEXT_FLOOR = 0.6;', 'export const TEXT_FLOOR = 0;'],
            "the floor raised to the page's own size (a 1,200 × 560 board docked)" => ['export const TEXT_FLOOR = 0.6;', 'export const TEXT_FLOOR = 1;'],
            'the board face set to 30 em' => ['export const BOARD_FACE = Object.freeze({ w: 24, h: 15 });', 'export const BOARD_FACE = Object.freeze({ w: 30, h: 15 });'],
            'the text never shrunk on a small board' => ['const styles = boxStyles(rect, text);', "const styles = boxStyles(rect, next === 'board' ? 1 : null);"],
            'the text share read at the camera, not the fit' => ['    return Math.min(1, (scene.directory.w * zoom) / (BOARD_FACE.w * rem), (scene.directory.h * zoom) / (BOARD_FACE.h * rem));', '    return Math.min(1, scene.directory.w / (BOARD_FACE.w * rem), scene.directory.h / (BOARD_FACE.h * rem));'],
            "the face's size not divided by the share" => ['            width: drawn ? `calc(${rect.w / text}px * var(--lobby-zoom, 1))` : \'\',', '            width: drawn ? `calc(${rect.w}px * var(--lobby-zoom, 1))` : \'\','],
            'the ResizeObserver on the 1rem probe removed' => ["    new win.ResizeObserver(() => place()).observe(probe);\n", ''],
            'the resize listener removed' => ["    win.addEventListener('resize', () => place());\n", ''],
            "the entry's surface rewrite skipped" => ["        building.style.touchAction = next === 'board' ? 'none' : 'auto';\n", ''],
            'a box moved on every placing' => ['                if (box.parentElement !== layer) {', '                if (true) {'],
            'the counter-scale kept on the dock' => ['const styles = boxStyles(rect, text);', 'const styles = boxStyles(rect, 1);'],
            "the box's home left unwritten" => ["            box.setAttribute('data-home', next);\n", ''],
        ] as $what => [$from, $to]) {
            $dir = $this->mutatedModules(['../lobby/directory.js', $from, $to]);
            $this->assertNotSame([], $this->dockDefects($this->dockRun(dirname($dir))), "RED ({$what}) did not bite");
        }

        $dir = $this->mutatedModules(['../lobby/building-scene.js', "        cursor: drawn ? 'grab' : '',", "        touchAction: drawn ? 'none' : '',\n        cursor: drawn ? 'grab' : '',"]);
        $this->assertNotSame([], $this->dockDefects($this->dockRun(dirname($dir))), 'RED (the surface style writing touch-action again) did not bite');
    }

    /** A page or a sheet that sets its own text size — `1rem` is then no longer the body text size. */
    public function test_red_a_page_that_sets_its_own_text_size(): void
    {
        $html = $this->lobbyPage();
        $sheet = $this->sheet();

        foreach ([
            'a second stylesheet' => '<link rel="stylesheet" href="/build/app.css">',
            'a style block' => '<style>body { font-size: 14px }</style>',
            'a style attribute' => '<p style="font-size: 14px">',
            'an unquoted style attribute' => '<p style=font-size:14px>',
        ] as $what => $tag) {
            $planted = str_replace('<main>', "<main>{$tag}", $html);
            $this->assertNotSame($planted, $html, "the {$what} RED's anchor is gone — it mutated nothing");
            $this->assertNotSame([], $this->baseDefects($planted, $sheet), "RED ({$what}) did not bite");
        }

        foreach ([
            'a size on the body' => "\nbody { font-size: 14px; }\n",
            'a size on a tag the directory builds' => "\nspan { font-size: 14px; }\n",
            'a size with !important' => "\n.anything { font-size: 14px !important; }\n",
        ] as $what => $rule) {
            $this->assertNotSame([], $this->baseDefects($html, $sheet.$rule), "RED ({$what}) did not bite");
        }
    }

    // ── the measurements ───────────────────────────────────────────────────────────────────────

    /** The plaques the lobby drew on a run's last frame — the model's own. @return list<array<string, mixed>> */
    private function plaquesOf(string $run): array
    {
        $renders = $this->replay($run)['lobby_renders'];

        return $renders[count($renders) - 1]['frame']['summary']['floors'];
    }

    /**
     * § 12's *Directory dock width*, read from the document: the board face, in em of its own text, across and down, and
     * the least share of the page's text size the board's text may shrink to. @return array{0: int, 1: int, 2: float}
     */
    private function dockBound(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Directory dock width\*\* \| the board\'s text at the page\'s own size on a fitted face of at least \*\*(\d+) em\*\* wide and \*\*(\d+) em\*\* tall, and shrunk to fit a smaller face down to \*\*([\d.]+)\*\* of the page\'s text size/m', $this->floorMd(), $m),
            '§ 12 carries no *Directory dock width* row in the form this test reads');

        return [(int) $m[1], (int) $m[2], (float) $m[3]];
    }

    /**
     * The board's text share § 12's row owes over a surface — this test's own derivation, from the scene the probe reads:
     * `1` where the face at the room's fit holds the board face, the share at which it does otherwise.
     *
     * @param  array{width: float|int, height: float|int}  $surface
     */
    private static function owedText(array $surface, float $rem, array $scene, int $bw, int $bh): float
    {
        $zoom = min($surface['width'] / $scene['extent']['w'], $surface['height'] / $scene['extent']['h']);

        return min(1.0, $scene['directory']['w'] * $zoom / ($bw * $rem), $scene['directory']['h'] * $zoom / ($bh * $rem));
    }

    /**
     * The docking entry driven through a page's sequence — undrawn, drawn on a desktop surface, painted again, resized to a
     * short window and to a phone, back, and the text size raised a little and a lot — and the predicate at faces a pixel
     * either side of each figure of the bound and of the board face, and at the windows card#13519 names.
     */
    private function dockRun(?string $jsRoot = null): array
    {
        [$bw, $bh, $floor] = $this->dockBound();
        // The room's extent and the directory's rect, the probe's own (`lobbyScene()`), so the faces are drawn at the bound.
        $scene = $this->paint([], [], $jsRoot)['room']['scene'];
        [$ew, $eh, $dw, $dh] = [$scene['extent']['w'], $scene['extent']['h'], $scene['directory']['w'], $scene['directory']['h']];
        // The surface at which the face is exactly `$em` × the board face wide at a 16 px rem (height unbounding); and the
        // height at which a height-limited fit first holds both figures — the board's 488 : 308 is narrower than 24 : 15,
        // so on a short surface the width figure is the one that binds first. At `$em = 1` the text starts to shrink; at
        // `$em = $floor` the boxes dock.
        $wAt = static fn (float $em): float => $em * $bw * 16 / $dw * $ew;
        $hAt = static fn (float $em): float => $em * max($bw * 16 / $dw, $bh * 16 / $dh) * $eh;
        // A window's surface: its width less the section's 8 px margins, its height the room's aspect capped at 80 vh.
        $window = static fn (int $w, int $h): array => ['width' => $w - 16, 'height' => min(($w - 16) * $eh / $ew, 0.8 * $h)];
        $faces = [
            ['why' => 'just wide enough for the page\'s own size', 'surface' => ['width' => $wAt(1) + 1, 'height' => 2000], 'rem' => 16, 'owed' => 'board'],
            ['why' => 'just too narrow for the page\'s own size', 'surface' => ['width' => $wAt(1) - 1, 'height' => 2000], 'rem' => 16, 'owed' => 'board'],
            ['why' => 'just wide enough', 'surface' => ['width' => $wAt($floor) + 1, 'height' => 2000], 'rem' => 16, 'owed' => 'board'],
            ['why' => 'just too narrow', 'surface' => ['width' => $wAt($floor) - 1, 'height' => 2000], 'rem' => 16, 'owed' => 'dock'],
            ['why' => 'just tall enough', 'surface' => ['width' => 4000, 'height' => $hAt($floor) + 1], 'rem' => 16, 'owed' => 'board'],
            ['why' => 'just too short', 'surface' => ['width' => 4000, 'height' => $hAt($floor) - 1], 'rem' => 16, 'owed' => 'dock'],
            ['why' => '1,440 × 780 (B\'s board run)', 'surface' => $window(1440, 780), 'rem' => 16, 'owed' => 'board'],
            ['why' => '1,400 × 1,000, at the page\'s own size', 'surface' => $window(1400, 1000), 'rem' => 16, 'owed' => 'board', 'full' => true],
            ['why' => '1,200 × 560, a laptop browser', 'surface' => $window(1200, 560), 'rem' => 16, 'owed' => 'board'],
            ['why' => '970 × 560', 'surface' => $window(970, 560), 'rem' => 16, 'owed' => 'board'],
            ['why' => '1,366 × 657, a 1,366 × 768 laptop\'s page', 'surface' => $window(1366, 657), 'rem' => 16, 'owed' => 'board'],
            ['why' => '390 × 844, a phone', 'surface' => $window(390, 844), 'rem' => 16, 'owed' => 'dock'],
        ];
        $desk = ['width' => 1424, 'height' => 720];
        $short = $window(1200, 560);
        $phone = ['width' => 374, 'height' => 200];
        $steps = [
            ['drawn' => false, 'surface' => $desk, 'rem' => 16, 'via' => 'place', 'owed' => 'flow'],
            ['drawn' => true, 'surface' => $desk, 'rem' => 16, 'via' => 'place', 'owed' => 'board'],
            ['drawn' => true, 'surface' => $desk, 'rem' => 16, 'via' => 'place', 'owed' => 'board', 'still' => true],
            ['surface' => $short, 'rem' => 16, 'via' => 'resize', 'owed' => 'board', 'still' => true],
            ['surface' => $phone, 'rem' => 16, 'via' => 'resize', 'owed' => 'dock'],
            ['drawn' => true, 'surface' => $phone, 'rem' => 16, 'via' => 'place', 'owed' => 'dock', 'still' => true],
            ['surface' => $desk, 'rem' => 16, 'via' => 'resize', 'owed' => 'board'],
            ['surface' => $desk, 'rem' => 22, 'via' => 'text', 'owed' => 'board', 'still' => true],
            ['surface' => $desk, 'rem' => 40, 'via' => 'text', 'owed' => 'dock'],
            ['surface' => $desk, 'rem' => 16, 'via' => 'text', 'owed' => 'board'],
            ['drawn' => false, 'surface' => $phone, 'rem' => 16, 'via' => 'place', 'owed' => 'flow'],
        ];
        $payload = escapeshellarg((string) json_encode(['dock' => $steps, 'dock_faces' => $faces]));
        $out = [];
        exec('echo '.$payload.' | node '.escapeshellarg(self::PROBE).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "the room probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true)['dock'] + ['bound_read' => [$bw, $bh, $floor], 'scene' => $scene];
    }

    /** @return list<string> */
    private function dockDefects(array $dock): array
    {
        $defects = [];

        [$bw, $bh, $floor] = $dock['bound_read'];

        if (($dock['bound']['face'] ?? null) !== ['w' => $bw, 'h' => $bh] || abs((float) ($dock['bound']['floor'] ?? -1) - $floor) > 1e-9) {
            $defects[] = 'the entry\'s bound is not § 12\'s *Directory dock width*: '.json_encode($dock['bound']).' against '.json_encode($dock['bound_read']);
        }

        foreach ($dock['predicate'] as $f) {
            $owedText = self::owedText($f['surface'], (float) $f['rem'], $dock['scene'], $bw, $bh);

            if ($f['home'] !== $f['owed']) {
                $defects[] = "the face {$f['why']} ".json_encode($f['surface'])." at a {$f['rem']} px rem is `{$f['home']}`, not `{$f['owed']}`";
            }

            if (abs((float) ($f['text'] ?? -1) - $owedText) > 1e-9) {
                $defects[] = "the face {$f['why']} reads its text at ".json_encode($f['text'] ?? null)." of the page's size, not {$owedText}";
            }

            if (($f['full'] ?? false) && $owedText !== 1.0) {
                $defects[] = "the face {$f['why']} is not at the page's own text size ({$owedText}) — a window the board always served";
            }
        }

        if (! $dock['observes_probe'] || $dock['probe'] === null || $dock['probe']['style']['width'] !== '1rem' || $dock['probe']['style']['height'] !== '1rem' || $dock['probe']['style']['visibility'] !== 'hidden') {
            $defects[] = 'the entry observes no hidden 1rem probe — a change of the text size is never re-asked: '.json_encode([$dock['observes_probe'], $dock['probe']]);
        }

        if (! $dock['listens_resize']) {
            $defects[] = 'the entry does not re-ask on a resize';
        }

        if (in_array('touchAction', $dock['surface_keys'], true)) {
            $defects[] = 'the surface\'s style writes `touch-action` beside the entry — two writers of one state';
        }

        foreach ($dock['steps'] as $i => $s) {
            $owed = $s['step']['owed'];
            $at = "step {$i} ({$s['step']['via']} at ".json_encode($s['step']['surface']).", rem {$s['step']['rem']})";
            $parent = $owed === 'board' ? 'layer' : 'page';

            if ($s['entry_home'] !== $owed || $s['data_home'] !== [$owed, $owed]) {
                $defects[] = "{$at}: the boxes are `".implode('/', $s['data_home'])."` (the entry says {$s['entry_home']}), not `{$owed}`";
            }

            if ($s['homes'] !== [$parent, $parent] || ($parent === 'page' && ($s['order'] !== ['directory', 'readings'] || ! $s['after_surface']))) {
                $defects[] = "{$at}: the boxes stand in ".implode('/', $s['homes']).' — '.json_encode($s['order']).", not in the {$parent}".($parent === 'page' ? ' after the surface, the directory first' : '');
            }

            $scaled = array_map(static fn ($t): bool => str_contains((string) $t, 'var(--lobby-scale'), $s['face_transform']);

            if ($scaled !== [$owed === 'board', $owed === 'board']) {
                $defects[] = "{$at}: the faces' counter-scale is ".json_encode($s['face_transform']).' — set on the board and unset everywhere else';
            }

            // On the board each face is its box over the text share, counter-scaled and then scaled by the share.
            if ($owed === 'board') {
                $text = self::owedText($s['step']['surface'], (float) $s['step']['rem'], $dock['scene'], $bw, $bh);

                foreach (['directory', 'readings'] as $i => $box) {
                    $rect = $dock['scene'][$box];
                    $read = preg_match('/^scale\(var\(--lobby-scale, 1\)\) scale\(([\d.e-]+)\)$/', (string) $s['face_transform'][$i], $t) === 1
                        && preg_match('/^calc\(([\d.e-]+)px \* var\(--lobby-zoom, 1\)\)$/', (string) $s['face_size'][$i][0], $w) === 1
                        && preg_match('/^calc\(([\d.e-]+)px \* var\(--lobby-zoom, 1\)\)$/', (string) $s['face_size'][$i][1], $h) === 1;

                    if (! $read || abs((float) $t[1] - $text) > 1e-9 || abs((float) $w[1] * $text - $rect['w']) > 1e-6 || abs((float) $h[1] * $text - $rect['h']) > 1e-6) {
                        $defects[] = "{$at}: the {$box}'s face is ".json_encode([$s['face_transform'][$i], $s['face_size'][$i]])." — not its box over the text share {$text}, scaled by it";
                    }
                }

                if ($text < $floor) {
                    $defects[] = "{$at}: the board's text is at {$text} of the page's size, under the floor {$floor}";
                }
            }

            if ($s['touch_action'] !== ($owed === 'board' ? 'none' : 'auto')) {
                $defects[] = "{$at}: the surface's touch-action is ".json_encode($s['touch_action']).' — `none` on the board alone, `auto` everywhere else';
            }

            if (($s['step']['still'] ?? false) && $s['moves'] !== 0) {
                $defects[] = "{$at}: a placing that kept the home moved {$s['moves']} box(es) — a render moves nothing";
            }
        }

        return $defects;
    }

    /** The room probe over plaques and unclaimed rooms. */
    private function paint(array $plaques, array $unclaimed = [], ?string $jsRoot = null): array
    {
        $payload = escapeshellarg((string) json_encode(['plaques' => $plaques, 'unclaimed' => $unclaimed], JSON_UNESCAPED_UNICODE));
        $out = [];
        exec('echo '.$payload.' | node '.escapeshellarg(self::PROBE).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "the room probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true);
    }

    /** @return list<string> */
    private function roomDefects(array $room, string $root): array
    {
        $defects = [];
        $scene = (string) file_get_contents($root.'/lobby/building-scene.js');

        foreach (['export function lobbyScene() {', 'export function roomArt() {'] as $signature) {
            if (! str_contains($scene, $signature)) {
                $defects[] = "the room's `{$signature}` takes an argument — the drawing can read a fact (§ 4.1)";
            }
        }

        if ($room['art_stable'] !== true) {
            $defects[] = 'the room draws differently from one call to the next';
        }

        if ($room['texts'] !== ['MEZZANINE']) {
            $defects[] = 'the room carries words beside the sign\'s: '.json_encode($room['texts']);
        }

        if ($room['window_glass'] !== 1) {
            $defects[] = "the room has {$room['window_glass']} windows of the sky — it has one";
        }

        if ($room['art']['attrs']['viewBox'] !== "0 0 {$room['room']['w']} {$room['room']['h']}") {
            $defects[] = "the room's drawing is not the room's scene: {$room['art']['attrs']['viewBox']}";
        }

        // The directory's box is the board's inner rectangle; the readings stand under the board; the elevator right of
        // the wall's centre, the board left of it.
        $s = $room['scene'];
        $inside = static fn (array $o, array $i): bool => $i['x'] >= $o['x'] && $i['y'] >= $o['y'] && $i['x'] + $i['w'] <= $o['x'] + $o['w'] && $i['y'] + $i['h'] <= $o['y'] + $o['h'];

        if (! $inside($s['board'], $s['directory']) || $s['readings']['y'] < $s['board']['y'] + $s['board']['h']) {
            $defects[] = 'the directory is not in the board, or the readings are not under it';
        }

        if (! ($s['board']['x'] + $s['board']['w'] <= $room['room']['w'] / 2 + 100 && $s['elevator']['x'] > $room['room']['w'] / 2)) {
            $defects[] = 'the board is not left of the elevator, or the elevator not right of the wall\'s centre';
        }

        return $defects;
    }

    /** @return list<string> */
    private function gridDefects(array $room): array
    {
        $defects = [];
        $md = $this->floorMd();

        $this->assertSame(1, preg_match('/^\| \*\*Lobby room drawing\*\* \| \*\*([\d,]+) × ([\d,]+)\*\* scene px; its ceiling panels \*\*(\d+)\*\* across × \*\*(\d+)\*\* deep \|/m', $md, $m),
            '§ 12 carries no *Lobby room drawing* row in the form this test reads');

        [$w, $h, $across, $deep] = [(int) str_replace(',', '', $m[1]), (int) str_replace(',', '', $m[2]), (int) $m[3], (int) $m[4]];

        if ([$room['room']['w'], $room['room']['h']] !== [$w, $h] || [$room['ceiling_grid']['across'], $room['ceiling_grid']['deep']] !== [$across, $deep]) {
            $defects[] = 'the room is not § 12\'s *Lobby room drawing*: '.json_encode([$room['room'], $room['ceiling_grid']]);
        }

        $panels = $room['panels'];

        if (count($panels) !== $across * $deep) {
            return [...$defects, 'the ceiling draws '.count($panels)." panels, not {$across} × {$deep}"];
        }

        // Row by row (a row is `across` panels at one depth): each panel a quad, the row's centres evenly spaced, and each
        // row narrower and shallower than the row nearer the viewer — foreshortened toward the far wall.
        $rows = array_chunk($panels, $across);
        $widths = [];

        foreach ($rows as $r => $row) {
            $centres = array_map(static fn (array $q): float => array_sum(array_column($q, 0)) / 4, $row);
            $gaps = [];

            for ($i = 1; $i < count($centres); $i++) {
                $gaps[] = $centres[$i] - $centres[$i - 1];
            }

            if (max($gaps) - min($gaps) > 0.5) {
                $defects[] = "the ceiling's row {$r} is not evenly spaced across: ".json_encode($gaps);
            }

            $widths[] = [$row[0][1][0] - $row[0][0][0], $row[0][3][1] - $row[0][0][1]];
        }

        for ($r = 1; $r < count($widths); $r++) {
            if (! ($widths[$r][0] < $widths[$r - 1][0] && $widths[$r][1] < $widths[$r - 1][1])) {
                $defects[] = "the ceiling's row {$r} is not narrower and shallower than the row before it — the grid does not recede";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function plaqueDefects(array $plaques, array $painted): array
    {
        $defects = [];

        if (count($painted) !== count($plaques) || $plaques === []) {
            return ['the directory painted '.count($painted).' plaques for '.count($plaques)];
        }

        foreach ($plaques as $i => $plaque) {
            $row = $painted[$i];
            $at = "the plaque {$plaque['floor']}";
            [$head, $tally, $markers] = $row['children'];
            $link = $head['children'][0];

            if ($row['dataset']['floor'] !== $plaque['floor'] || ($row['attrs']['class'] ?? '') !== 'lobby-plaque') {
                $defects[] = "{$at} is not its floor's plaque";
            }

            if ($link['name'] !== 'a' || $link['attrs']['href'] !== $plaque['href'] || $link['attrs']['aria-label'] !== $plaque['accessible_name']) {
                $defects[] = "{$at}'s link is not its href named by its accessible name: ".json_encode($link['attrs']);
            }

            if (($link['children'][0]['text'] ?? null) !== (string) ($i + 1) || ($link['children'][1]['text'] ?? null) !== $plaque['name']) {
                $defects[] = "{$at}'s link does not read its numeral and its name";
            }

            $named = count($plaque['rooms']) > 1 || in_array(false, array_column($plaque['rooms'], 'reported'), true);
            $rooms = array_values(array_filter($head['children'], static fn (array $n): bool => ($n['attrs']['class'] ?? '') === 'lobby-plaque-rooms'));

            if ($named !== ($rooms !== []) || ($named && ! str_starts_with($rooms[0]['text'], 'rooms: '.$plaque['rooms'][0]['install_id']))) {
                $defects[] = "{$at} names its rooms where it should not, or not where it should";
            }

            $staffing = array_values(array_filter($head['children'], static fn (array $n): bool => ($n['attrs']['class'] ?? '') === 'lobby-plaque-staffing'));

            if (($staffing[0]['text'] ?? null) !== $plaque['staffing']['text']) {
                $defects[] = "{$at}'s agents and desks are not the model's: ".json_encode($staffing[0]['text'] ?? null);
            }

            $chips = array_map(static fn (array $n): array => [$n['text'], $n['style']['--fill'] ?? null], $tally['children']);
            $expected = array_map(static fn (array $c): array => [$c['text'], $c['recognised'] ? "var(--state-{$c['state']})" : 'var(--state-ink-unconfirmed)'], $plaque['chips']);

            if ($chips !== $expected) {
                $defects[] = "{$at}'s chips are ".json_encode($chips).', not the model\'s '.json_encode($expected);
            }

            $shown = array_values(array_filter($markers['children'], static fn (array $n): bool => str_starts_with($n['attrs']['class'] ?? '', 'lobby-marker ')));
            $more = array_values(array_filter($markers['children'], static fn (array $n): bool => ($n['attrs']['class'] ?? '') === 'lobby-marker-more'));
            $got = array_map(static fn (array $n): array => [$n['attrs']['aria-label'] ?? null, $n['attrs']['role'] ?? null, $n['attrs']['class']], $shown);
            $want = array_map(static fn (array $m): array => [$m['name'], 'img', "lobby-marker {$m['shape']}"], $plaque['markers']['shown']);

            if ($got !== $want || ($plaque['markers']['more'] === null ? $more !== [] : ($more[0]['text'] ?? null) !== $plaque['markers']['more'])) {
                $defects[] = "{$at}'s markers are not the model's: ".json_encode([$got, array_column($more, 'text')]);
            }

            $rest = array_slice($row['children'], 3);
            $parts = array_values(array_filter($rest, static fn (array $n): bool => str_contains($n['attrs']['class'] ?? '', 'lobby-plaque-attention')));
            $totals = array_values(array_filter($rest, static fn (array $n): bool => str_contains($n['attrs']['class'] ?? '', 'lobby-plaque-totals')));

            if (array_column($parts, 'text') !== array_column($plaque['attention'] ?? [], 'text')) {
                $defects[] = "{$at}'s attention rows are ".json_encode(array_column($parts, 'text')).', not one per part of '.json_encode($plaque['attention']);
            }

            if (array_column($totals, 'text') !== [$plaque['floor_totals']['text']] || $rest[count($rest) - 1] !== $totals[0]) {
                $defects[] = "{$at}'s totals row is not the model's, last";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function rerenderDefects(array $plaques, ?string $jsRoot = null): array
    {
        // The second render: the same floors with every plaque's totals moved on, as a later clock moves its ages.
        $later = array_map(static function (array $p): array {
            $p['floor_totals']['text'] .= ' (later)';
            $p['accessible_name'] .= ' (later)';

            return $p;
        }, $plaques);
        $payload = escapeshellarg((string) json_encode(['rerender' => [['floors' => $plaques, 'unclaimed' => []], ['floors' => $later, 'unclaimed' => []]]], JSON_UNESCAPED_UNICODE));
        $out = [];
        exec('echo '.$payload.' | node '.escapeshellarg(self::PROBE).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "the room probe failed:\n".implode("\n", $out));
        $r = json_decode(implode("\n", $out), true)['directory']['rerendered'];

        return [
            ...($r['kept'] ? [] : ['a re-render replaced the plaque\'s link — the keyboard\'s focus on it is lost']),
            ...$this->plaqueDefects($later, $r['list']['children']),
        ];
    }

    /** § 9 F17's rows across a re-render: an unclaimed room's link is the same element. @return list<string> */
    private function unclaimedRerenderDefects(?string $jsRoot = null): array
    {
        $rooms = [['install_id' => 'aimla', 'href' => '/floor/aimla', 'held' => 1], ['install_id' => 'sola', 'href' => '/floor/sola', 'held' => 1]];
        $payload = escapeshellarg((string) json_encode(['rerender' => [['floors' => [], 'unclaimed' => $rooms], ['floors' => [], 'unclaimed' => $rooms]]]));
        $out = [];
        exec('echo '.$payload.' | node '.escapeshellarg(self::PROBE).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "the room probe failed:\n".implode("\n", $out));
        $r = json_decode(implode("\n", $out), true)['directory']['rerendered'];

        return $r['kept'] ? [] : ['a re-render replaced an unclaimed room\'s link — the keyboard\'s focus on it is lost every second'];
    }

    /** `fx-lobby.json`'s `lobby_plaque` with the 1 s tick on, run past its heartbeat. */
    private function tickRun(?string $dir = null): array
    {
        return $this->replay('lobby_plaque', $dir, ['lobby' => ['tick' => true], 'until_ms' => 5000]);
    }

    /** @return list<string> */
    private function tickDefects(array $result): array
    {
        $defects = [];
        $ticks = array_values(array_filter($result['lobby_renders'], static fn (array $r): bool => $r['trigger'] === 'tick' && $r['at'] > 1000));
        $plain = $this->replay('lobby_plaque', null, ['until_ms' => 5000]);

        if (count($ticks) < 3) {
            return ['the tick drew '.count($ticks).' frames after the heartbeat — the clause measured little'];
        }

        $ages = array_map(static fn (array $r): string => $r['frame']['summary']['floors'][0]['floor_totals']['last_activity'] ?? '', $ticks);

        if (count(array_unique($ages)) !== count($ages)) {
            $defects[] = 'the last activity\'s age did not move on each tick: '.json_encode($ages);
        }

        // Everything but the ages, as it was: the plaque with its ages and its totals' text taken out.
        $still = static function (array $r): string {
            $floors = array_map(static function (array $p): array {
                unset($p['floor_totals'], $p['attention']);

                return $p;
            }, $r['frame']['summary']['floors']);

            return json_encode([$floors, $r['frame']['camera']]);
        };

        if (count(array_unique(array_map($still, $ticks))) !== 1) {
            $defects[] = 'a tick changed something on the plaque beside its ages, or moved the camera';
        }

        if ($result['animation_log'] !== $plain['animation_log']) {
            $defects[] = 'the tick drew a § 6.2 row';
        }

        return $defects;
    }

    /** @return list<string> */
    private function unclaimedDefects(array $directory): array
    {
        $defects = [];
        $row = $directory['unclaimed'][0] ?? null;

        if ($row === null || ($row['children'][0]['text'] ?? null) !== 'aimla — no floor claimed' || ($row['children'][0]['attrs']['href'] ?? null) !== '/floor/aimla' || count($row['children']) !== 1) {
            $defects[] = 'an unclaimed install is not a plain link, *aimla — no floor claimed*, with no plaque figures: '.json_encode($row);
        }

        if (($directory['empty']['children'][0]['text'] ?? null) !== 'no installs are provisioned — the fleet reports none') {
            $defects[] = 'an empty directory is not said in words';
        }

        return $defects;
    }

    /** @return list<string> */
    private function scaleDefects(array $directory): array
    {
        $defects = [];
        $shown = $directory['shown'];
        $zoom = $shown['zoom'];

        foreach (['framed' => $zoom, 'zoomed' => $zoom * 2] as $which => $z) {
            if (abs((float) ($shown[$which]['--lobby-zoom'] ?? -1) - $z) > 1e-9 || abs((float) ($shown[$which]['--lobby-scale'] ?? -1) * $z - 1) > 1e-9) {
                $defects[] = "the {$which} camera's counter-scale is ".json_encode($shown[$which])." — not the zoom {$z} and its inverse";
            }
        }

        if ($shown['unframed'] !== []) {
            $defects[] = 'a camera that frames nothing writes a counter-scale: '.json_encode($shown['unframed']);
        }

        $drawn = $directory['boxes']['drawn'];

        if ($drawn['box']['position'] !== 'absolute' || $drawn['box']['overflow'] !== 'hidden'
            || ! str_contains($drawn['face']['width'], 'var(--lobby-zoom') || ! str_contains($drawn['face']['height'], 'var(--lobby-zoom')
            || ! str_contains($drawn['face']['transform'], 'var(--lobby-scale')) {
            $defects[] = 'the drawn box is not a clipped box at its rect holding a face sized by the zoom and scaled by its inverse: '.json_encode($drawn);
        }

        if (array_filter([...$directory['boxes']['undrawn']['box'], ...$directory['boxes']['undrawn']['face']], static fn ($v): bool => $v !== '') !== []) {
            $defects[] = 'an undrawn box keeps a style: '.json_encode($directory['boxes']['undrawn']);
        }

        return $defects;
    }

    /** @return list<string> */
    private function sheetDefects(string $sheet, string $page): array
    {
        $defects = [];
        $rule = function (string $selector) use ($sheet): string {
            $this->assertSame(1, preg_match('/^'.preg_quote($selector, '/').' \{\n(.*?)\n\}/ms', $sheet, $m), "the sheet has no `{$selector}` rule");

            return $m[1];
        };

        $list = $rule('.lobby [data-home="board"] .lobby-plaques');

        if (! str_contains($list, 'overflow-y: auto;') || ! str_contains($list, 'touch-action: none;')) {
            $defects[] = 'on the board the plaques\' list does not scroll inside its box, or the browser may scroll it natively (§ 4.1)';
        }

        // § 4.1 claim 14 (card#12187): `touch-action: none` only where the wire listens — every rule saying it keyed on
        // `[data-home="board"]` — and `pan-y` nowhere, so a docked finger keeps the page's pinch-zoom (WCAG 2.2 SC 1.4.4).
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $sheet), $rules, PREG_SET_ORDER);

        foreach ($rules as [, $selectors, $body]) {
            if (! str_contains($selectors, '.lobby')) {
                continue;
            }

            if (str_contains($body, 'pan-y')) {
                $defects[] = 'a lobby rule says `pan-y` (`'.trim($selectors).'`) — it refuses the page\'s pinch-zoom';
            }

            if (preg_match('/touch-action:\s*none/', $body) === 1) {
                foreach (explode(',', $selectors) as $selector) {
                    if (! str_contains($selector, '[data-home="board"]')) {
                        $defects[] = 'a lobby rule says `touch-action: none` off the board (`'.trim($selector).'`)';
                    }
                }
            }
        }

        // Docked, a plaque's link is at least 24 × 24 CSS px (WCAG 2.2 SC 2.5.8), under `[data-home="dock"]` and nowhere else.
        $tap = $rule('.lobby [data-home="dock"] .lobby-plaque-name');

        if (! str_contains($tap, 'min-height: 24px;') || ! str_contains($tap, 'min-width: 24px;')) {
            $defects[] = 'a docked plaque\'s link is not declared at least 24 × 24 CSS px';
        }

        // The placeholder colours are keyed on the box's own home, never on an ancestor it leaves (review round 1's F2).
        if (str_contains($sheet, '#lobby-room:not([data-drawn])')) {
            $defects[] = 'the un-drawn colours are keyed on `#lobby-room` — a box outside the layer reads none of them';
        }

        $rule('.lobby [data-home="flow"] .lobby-face');

        // § 4.1: every line is read whole — a line wider than its box wraps, and nothing the plaque or the readings show is
        // cut at its end; in the name row the name and the agents and desks never shrink, so the rooms line gives way.
        $row = $rule('.lobby .lobby-plaque-row');

        if (! str_contains($row, 'flex-wrap: wrap;')) {
            $defects[] = "a plaque's row does not wrap (`flex-wrap: wrap;` missing)";
        }

        foreach ([
            'a plaque\'s row' => $row,
            'the board\'s title' => $rule('.lobby #lobby-directory-heading'),
            'a reading' => $rule(".lobby .lobby-reading p,\n.lobby .lobby-reading li"),
            'the rooms line' => $rule('.lobby .lobby-plaque-rooms'),
            'the agents and desks' => $rule('.lobby .lobby-plaque-staffing'),
        ] as $what => $body) {
            foreach (['white-space: nowrap;', 'text-overflow: ellipsis;'] as $d) {
                if (str_contains($body, $d)) {
                    $defects[] = "{$what} is one line cut at its end (`{$d}`)";
                }
            }
        }

        foreach (['.lobby .lobby-plaque-name' => 'the floor\'s name', '.lobby .lobby-plaque-staffing' => 'the agents and desks'] as $selector => $what) {
            if (! str_contains($rule($selector), 'flex: none;')) {
                $defects[] = "{$what} can shrink on the name row (`flex: none;` missing)";
            }
        }

        $face = $rule('.lobby .lobby-face');

        if (! str_contains($face, 'font-size: 1rem;')) {
            $defects[] = 'the directory\'s face is not the page\'s own text size';
        }

        if (! str_contains($face, 'overflow-wrap: anywhere;')) {
            $defects[] = 'a word wider than its box is not broken (`overflow-wrap: anywhere;` missing on the face)';
        }

        // The boxes ship `data-home="flow"` (§ 4.1 claim 14), so the placeholders are the page's colours with no script run.
        if (preg_match('#<div id="lobby-readings" data-home="flow">#', $page) !== 1) {
            $defects[] = 'the readings\' box does not ship `data-home="flow"` in the markup';
        }

        if (preg_match('#<section id="lobby-directory" aria-labelledby="lobby-directory-heading" data-home="flow">\s*<div class="lobby-face lobby-directory-face">\s*<h3 id="lobby-directory-heading">(.*?)</h3>\s*<ol id="lobby-floors" class="lobby-plaques" aria-labelledby="lobby-directory-heading" data-camera-scrolls>#s', $page, $m) !== 1
            || trim(strip_tags($m[1])) !== 'FLOOR DIRECTORY — counts the seats this client holds') {
            $defects[] = 'the directory is not its HTML title over a list it names, declaring that it scrolls, shipped `data-home="flow"`';
        }

        return $defects;
    }

    /**
     * What sets the lobby page's text size other than the viewer: a stylesheet besides the page chrome sheet, a style
     * block or attribute sizing text, or a sheet rule sizing the root, the body, `*` or a tag `directory.js` builds, or
     * sizing anything `!important`.
     *
     * @return list<string>
     */
    private function baseDefects(string $html, string $sheet): array
    {
        $defects = [];

        preg_match_all('/<link\b[^>]*\brel\s*=\s*["\']?stylesheet[^>]*>/i', $html, $links);

        foreach ($links[0] as $link) {
            if (preg_match('#href="[^"]*/css/mezzanine\.css\?v=\d+"#', $link) !== 1) {
                $defects[] = "the lobby page loads a stylesheet ({$link}) — re-derive the body text size from it";
            }
        }

        preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $blocks);

        foreach ($blocks[1] as $block) {
            foreach ($this->sizeRuleDefects($block) as $defect) {
                $defects[] = "the lobby page's style block: {$defect}";
            }
        }

        preg_match_all('/\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($m as $attribute) {
            $style = $attribute[1] ?? $attribute[2] ?? $attribute[3];

            if (preg_match('/\bfont(-size)?\s*:/i', $style) === 1) {
                $defects[] = "the lobby page sets a font size in a style attribute (`{$style}`)";
            }
        }

        if (! str_contains($html, 'id="lobby-directory"')) {
            $defects[] = 'the page read is not the lobby — it has no directory';
        }

        return [...$defects, ...$this->sizeRuleDefects($sheet)];
    }

    /** @return list<string> */
    private function sizeRuleDefects(string $css): array
    {
        preg_match_all("/el\\(doc, '([a-z0-9]+)'/", (string) file_get_contents($this->jsRoot().'/lobby/directory.js'), $t);
        $tags = array_values(array_unique($t[1]));

        $this->assertContains('a', $tags, 'directory.js builds no tags this parse can read — the tag clause reads nothing');

        $sized = [...$tags, 'html', 'body', ':root', '*'];
        $defects = [];

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $css), $rules, PREG_SET_ORDER);

        foreach ($rules as [, $selectors, $body]) {
            if (preg_match('/(^|;|\s)font(-size)?\s*:/i', $body) !== 1) {
                continue;
            }

            if (preg_match('/font(-size)?\s*:[^;]*!\s*important/i', $body) === 1) {
                $defects[] = "a text size with !important (`".trim($selectors).'`)';
            }

            foreach (explode(',', $selectors) as $selector) {
                $parts = preg_split('/[\s>+~]+/', trim($selector)) ?: [];
                $subject = (string) end($parts);

                if (preg_match('/^(\*|:root|[a-z][a-z0-9]*)/i', $subject, $ty) === 1 && in_array(strtolower($ty[1]), $sized, true)) {
                    $defects[] = "a text size on `{$ty[1]}` (`".trim($selector).'`) — the directory\'s text is then not the body text size';
                }
            }
        }

        return $defects;
    }

    private function sheet(): string
    {
        return (string) file_get_contents(public_path('css/mezzanine.css'));
    }

    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get('/dashboard')->assertOk()->getContent() ?: '';
    }
}
