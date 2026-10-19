<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **AT-D3-26's panel leg — the cab's buttons are reconciled by key** (`docs/design/FLOOR.md` § 4.5, card#11795;
 * Appendix B row 23), `floor/cab-panel.js` over a fake DOM (`cab-panel-probe.mjs`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE BUTTON PER STOP, IN THE SCREEN's ORDER: every floor by name, the current floor lit — `aria-current`, the
 * `current` class and *— you are here* — and the lobby last, keyless (`data-stop=""`).
 *
 * ⛔ RECONCILED, NEVER REBUILT: a renamed stop keeps its node (its name changes in place), every other stop's node
 * survives a frame, and a stop removed while its button has focus hands focus to the current floor's button.
 *
 * ⛔ ONE PRESS IS ONE CHOICE: a pointer click chooses; a double click's second click does not; the lobby chooses
 * `null`; a keyboard activation (`detail === 0`) does nothing until a FRESH key — a keydown that is not a repeat,
 * or a key released since `arm()` (after its own activation, so the release of a Space held from the elevator
 * chooses nothing).
 *
 * ⛔ THE POPUP IS THE ELEVATOR's OWN BUTTON PANEL, IN THE HOUSE THEME's ELEVATOR PALETTE (card#12039), and the sheet
 * holds no copy of it: every `.floor-cab*` / `.cab-*` rule names its colours by token, never by hex; the popup's body
 * is `--cab-frame`, a stop's button the leaves' `--door` / `--door-edge`, the current floor's call button `--cab-lamp`;
 * and the dialog's own defaults for the two carriers are the tokens the painter fell back to before them. That the
 * carriers then hold the theme's colours on the page is `TheElevatorIsAControlOnTheDrawingTest`'s (`cabTheme()`), and
 * what a browser paints `tools/design/floor-ride.browser.mjs`'s `cab-palette` row.
 */
class TheCabPanelReconcilesByKeyTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/cab-panel-probe.mjs';
    }

    public function test_the_stops_are_drawn_in_order_with_the_current_floor_lit_and_the_lobby_last(): void
    {
        $this->assertSame([], $this->orderDefects($this->run_()));
    }

    public function test_the_buttons_are_reconciled_by_key(): void
    {
        $this->assertSame([], $this->keyedDefects($this->run_()));
    }

    public function test_one_press_is_one_choice(): void
    {
        $this->assertSame([], $this->pressDefects($this->run_()));
    }

    public function test_the_popup_is_drawn_in_the_elevators_palette(): void
    {
        $this->assertSame([], $this->paletteDefects($this->cabSheet()));
    }

    /** § 12's *Cab button popup* is the sheet's: its bounds, its landscape share and its portrait height bound. */
    public function test_green_section_12s_cab_button_popup_is_the_sheets(): void
    {
        $this->assertSame([], $this->popupSizeDefects($this->cabSheet()));
        $this->assertNotSame([], $this->popupSizeDefects(str_replace('--cab-panel-max: 480px;', '--cab-panel-max: 520px;', $this->cabSheet())),
            'CONTROL (the sheet\'s widest popup moved off § 12) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace("        max-height: 55vh;\n", "        max-height: 70vh;\n", $this->cabSheet())),
            'CONTROL (the portrait height bound moved off § 12) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace('(max-width: 419px)', '(max-width: 359px)', $this->cabSheet())),
            'CONTROL (the stacked layout\'s width bound moved off the side-by-side one) did not bite');
    }

    /** ⛔ THE CONTROLS for the popup's palette — each plants one defect in the sheet's cab block. */
    public function test_the_palette_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $sheet = $this->cabSheet();

        foreach ([
            'a hex body' => ['    background-color: var(--cab-frame);', '    background-color: #c9a873;'],
            'a stop off the door' => ['    background: var(--door);', '    background: var(--panel-2);'],
            'a stop edged off the door' => ['    border: 2px solid var(--door-edge);', '    border: 2px solid var(--line);'],
            'the lit stop off the lamp' => ["    background: var(--cab-lamp);\n    box-shadow: inset", "    background: var(--lamp);\n    box-shadow: inset"],
            'no default frame' => ['    --cab-frame: var(--door-frame);', ''],
        ] as $what => [$from, $to]) {
            $this->assertSame(1, substr_count($sheet, $from), "CONTROL ({$what}): its anchor is not in the cab block exactly once");
            $this->assertNotSame([], $this->paletteDefects(str_replace($from, $to, $sheet)), "CONTROL ({$what}) did not bite");
        }
    }

    /** ⛔ THE CONTROLS — each plants one defect in a copy of the shipped panel. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        foreach ([
            'no aria-current' => ["                    button.setAttribute('aria-current', 'true');", '', 'orderDefects'],
            'rebuilt, not reconciled' => ['            const held = new Map([...list.children].map((li) => [li.dataset.stop, li]));',
                "            [...list.children].forEach((li) => li.remove());\n            const held = new Map();", 'keyedDefects'],
            'focus dropped on removal' => ["            if (focusLost) {\n                current()?.focus();\n            }", '', 'keyedDefects'],
            'a double click counted' => ['            if (event.detail > 1 || (event.detail === 0 && !armed)) {', '            if (event.detail === 0 && !armed) {', 'pressDefects'],
            'a repeat arming the keys' => ["        if (!event.repeat) {\n            armed = true;\n        }", '        armed = true;', 'pressDefects'],
            'a release arming its own activation' => ["        setTimeout(() => {\n            armed = true;\n        }, 0);", '        armed = true;', 'pressDefects'],
            'a keyboard press never held' => ['            if (event.detail > 1 || (event.detail === 0 && !armed)) {', '            if (event.detail > 1) {', 'pressDefects'],
        ] as $what => [$from, $to, $check]) {
            $dir = $this->mutatedModules(['cab-panel.js', $from, $to]);
            $this->assertNotSame([], $this->{$check}($this->run_(dirname($dir))), "CONTROL ({$what}) did not bite");
        }
    }

    /** @return array<string, mixed> */
    private function run_(?string $jsRoot = null): array
    {
        $out = [];
        exec('node '.escapeshellarg($this->probeScript()).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "the panel probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true);
    }

    /** The sheet's cab block: from its § 4.5 comment to the sign-in card's rule that follows it. */
    private function cabSheet(): string
    {
        $sheet = (string) file_get_contents(public_path('css/mezzanine.css'));
        $start = strpos($sheet, "/*\n * § 4.5's CAB");
        $end = strpos($sheet, "#floor-signin {\n    position: fixed;");

        $this->assertNotFalse($start, "the sheet has no § 4.5's CAB block this check can read");
        $this->assertNotFalse($end, 'the sheet has no sign-in rule closing the cab block');

        return substr($sheet, $start, $end - $start);
    }

    /**
     * @param  string  $sheet  the cab block
     * @return list<string>
     */
    private function paletteDefects(string $sheet): array
    {
        $rules = [];
        $defects = [];

        // Rule by rule, comments dropped: selector => declarations, a media block's rules appended to the same selector's
        // (a match never starts at `@media`, whose `{` is followed by a rule's, so each match is one rule).
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $sheet), $m, PREG_SET_ORDER);

        foreach ($m as [, $selector, $body]) {
            $selector = trim($selector);
            $rules[$selector] = ($rules[$selector] ?? '').$body;

            if (preg_match('/#[0-9a-f]{3,8}\b/i', $body, $hex) === 1) {
                $defects[] = "{$selector} names a colour by hex ({$hex[0]}) — the cab's colours are the palette's and the sheet's tokens";
            }
        }

        $wants = [
            '.floor-cab' => ['--cab-frame: var(--door-frame);', '--cab-lamp: var(--lamp);'],
            '.floor-cab-panel' => ['background-color: var(--cab-frame);'],
            '.floor-cab-panel li > button' => ['border: 2px solid var(--door-edge);', 'background: var(--door);'],
            '.floor-cab-panel li.current .cab-cap' => ['background: var(--cab-lamp);'],
        ];

        foreach ($wants as $selector => $declarations) {
            foreach ($declarations as $declaration) {
                if (! str_contains($rules[$selector] ?? '', $declaration)) {
                    $defects[] = "{$selector} does not declare `{$declaration}`";
                }
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function popupSizeDefects(string $sheet): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab button popup\*\* \| \*\*(\d+) to (\d+) px wide\*\*: (\d+) % .*?narrower than (\d+) px.*?at most (\d+) % /m', $this->floorMd(), $cell),
            '§ 12 carries no *Cab button popup* row in the form this test reads');

        $read = [
            preg_match('/--cab-panel-w: clamp\((\d+)px, (\d+)vw, var\(--cab-panel-max\)\);/', $sheet, $w) === 1 ? (int) $w[1] : null,
            preg_match('/--cab-panel-max: (\d+)px;/', $sheet, $max) === 1 ? (int) $max[1] : null,
            isset($w[2]) ? (int) $w[2] : null,
            preg_match('/@media \(orientation: landscape\) and \(min-width: (\d+)px\)/', $sheet, $wide) === 1
                && preg_match('/@media \(orientation: portrait\), \(max-width: (\d+)px\)/', $sheet, $narrow) === 1
                && (int) $narrow[1] === (int) $wide[1] - 1 ? (int) $wide[1] : null,
            preg_match('/@media \(orientation: portrait\), \(max-width: \d+px\).*?\.floor-cab-panel \{[^}]*max-height: (\d+)vh;/s', $sheet, $h) === 1 ? (int) $h[1] : null,
        ];
        $stated = array_map('intval', array_slice($cell, 1, 5));

        return $read === $stated ? [] : ['§ 12 says the popup is '.json_encode($stated).' (min px, max px, landscape vw, the narrowest side-by-side window px, portrait vh) and the sheet '.json_encode($read)];
    }

    /** @return list<string> */
    private function orderDefects(array $run): array
    {
        $expected = [
            ['stop' => 'aimla', 'name' => 'aimla', 'current' => true, 'marked' => true, 'here' => true],
            ['stop' => 'kappa', 'name' => 'kappa', 'current' => false, 'marked' => false, 'here' => false],
            ['stop' => 'zeta', 'name' => 'the annex', 'current' => false, 'marked' => false, 'here' => false],
            ['stop' => '', 'name' => 'Lobby', 'current' => false, 'marked' => false, 'here' => false],
        ];

        return $run['order'] === $expected ? [] : ['the panel drew '.json_encode($run['order'])];
    }

    /** @return list<string> */
    private function keyedDefects(array $run): array
    {
        $defects = [];

        if (! $run['kept'] || array_column($run['renamed'], 'name') !== ['aimla', 'kappa', 'the new annex', 'Lobby']) {
            $defects[] = 'a frame with one stop renamed did not keep every node and rename the one in place';
        }

        if ($run['afterRemoval'] !== ['stops' => ['aimla', 'zeta', ''], 'focus' => 'aimla', 'kept' => true]) {
            $defects[] = 'removing the focused stop: '.json_encode($run['afterRemoval']).' — the rest kept, focus on the current floor';
        }

        return $defects;
    }

    /** @return list<string> */
    private function pressDefects(array $run): array
    {
        $expected = [
            'pointer' => ['zeta'],
            'double click second' => [],
            'lobby' => [null],
            'keyboard before any key' => [],
            'held key repeating' => [],
            'held Space released' => [],
            'after the release' => ['zeta'],
            'fresh key after arming' => ['kappa'],
        ];
        $defects = [];

        foreach ($expected as $case => $choices) {
            if (($run['presses'][$case] ?? 'missing') !== $choices) {
                $defects[] = "{$case}: chose ".json_encode($run['presses'][$case] ?? null).', expected '.json_encode($choices);
            }
        }

        return $defects;
    }
}
