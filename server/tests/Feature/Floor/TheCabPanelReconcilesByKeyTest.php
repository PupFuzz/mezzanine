<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\MeasuresContrast;
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
 * is `--cab-frame` once the dialog is `data-themed`, a stop's button the leaves' `--door` / `--door-edge`, the current
 * floor's call button `--cab-lamp`; and the dialog's own defaults for the two carriers are the tokens the painter fell
 * back to before them. That the carriers then hold the theme's colours on the page is
 * `TheElevatorIsAControlOnTheDrawingTest`'s (`cabTheme()`), and what a browser paints
 * `tools/design/floor-ride.browser.mjs`'s `cab-palette` row.
 *
 * ⛔ EVERY TEXT IN THE POPUP HOLDS WCAG AA ON WHAT IT SITS ON, WITH THE HOUSE THEME AND WITHOUT IT (card#12047). The
 * population is every id in the panel's markup and every class `cab-panel.js` gives a stop's parts (held to that set,
 * so a new id or class reds until it is paired here; an element with neither is not seen), and the focus ring drawn
 * on the body. Each colour is resolved from this sheet:
 * the `:root` tokens, the cab block's custom properties for the state (the dialog's defaults; with the theme, its
 * `[data-themed]` rule and the house theme's `PALETTE.elevator` carried inline over both), `var()` and `color-mix()` in
 * sRGB. The body is read at its plain colour and under each stop of its light-and-shade gradient, the worst one
 * counting. ⚠ What this does not see: the corner rivets (drawn outside the padding the text sits in), and a cascade
 * by specificity — each pairing names its rules in the order they apply, so a new rule that out-ranks one is not read.
 * What a browser computes is the `cab-fallback` row's.
 */
class TheCabPanelReconcilesByKeyTest extends TestCase
{
    use DrivesAShippedClientModule;
    use MeasuresContrast;

    /** WCAG 2.x AA: body text (1.4.3), and a focus indicator's non-text contrast (1.4.11). */
    private const TEXT_FLOOR = 4.5;

    private const RING_FLOOR = 3.0;

    /**
     * The popup's text population. `color` — the rules the text's colour cascades through, in the order they apply,
     * and the property read (`color`, or `outline` for the focus ring); `on` — `body` (the panel's own background,
     * gradient and all), or the rules a stop's button takes its background from, in order.
     */
    private const POPUP_TEXTS = [
        'the heading' => [['.floor-cab', '.floor-cab-panel', '.floor-cab-panel h2'], 'color', 'body'],
        'the status line' => [['.floor-cab', '.floor-cab-panel', '#floor-cab-status'], 'color', 'body'],
        'the layout line' => [['.floor-cab', '.floor-cab-panel', '#floor-cab-layout'], 'color', 'body'],
        'the feed line' => [['.floor-cab', '.floor-cab-panel', '#floor-cab-feed'], 'color', 'body'],
        'a stop\'s name' => [['.floor-cab', '.floor-cab-panel', '.floor-cab-panel li > button', '.cab-name'], 'color', ['.floor-cab-panel li > button']],
        'the current stop\'s name' => [['.floor-cab', '.floor-cab-panel', '.floor-cab-panel li > button', '.cab-name'], 'color',
            ['.floor-cab-panel li > button', '.floor-cab-panel li.current > button']],
        '*you are here*' => [['.floor-cab', '.floor-cab-panel', '.floor-cab-panel li > button', '.cab-here'], 'color',
            ['.floor-cab-panel li > button', '.floor-cab-panel li.current > button']],
        'a stop\'s focus ring' => [['.floor-cab-panel li > button:focus-visible'], 'outline', 'body'],
    ];

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

    /** card#12047: every text in the popup is legible on what it sits on, with the house theme and with none. */
    public function test_every_popup_text_holds_wcag_aa_with_the_theme_and_without_it(): void
    {
        $this->assertSame([], $this->popupTextPopulationDefects());
        $this->assertSame([], $this->legibilityDefects($this->fullSheet(), $this->housePalette()));
    }

    /** ⛔ THE CONTROLS for the contrast check — each plants one defect in the sheet, and one in the population check. */
    public function test_the_contrast_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $sheet = $this->fullSheet();
        $palette = $this->housePalette();

        foreach ([
            // PR #340's fallback: with no theme the body is the door frame and the words the room's ink (1.01:1).
            'the old fallback' => [['    --cab-body: var(--panel);', '    --cab-body: var(--cab-frame);'], ['    --cab-ink: var(--ink);', '    --cab-ink: var(--scene-ink);'], 'without the theme'],
            'the themed words in the page\'s ink' => [['    --cab-ink: var(--scene-ink);', '    --cab-ink: var(--ink);'], null, 'with the theme'],
            'the focus ring in the room\'s ink' => [['    outline: 3px solid var(--cab-ink);', '    outline: 3px solid var(--scene-ink);'], null, 'without the theme'],
            'a stop\'s name off its plate' => [["    background: var(--door);\n    color: var(--scene-ink);", "    background: var(--door);\n    color: var(--cab-ink);"], null, 'without the theme'],
        ] as $what => [$first, $second, $state]) {
            $planted = $sheet;

            foreach (array_filter([$first, $second]) as [$from, $to]) {
                $this->assertSame(1, substr_count($planted, $from), "CONTROL ({$what}): its anchor `{$from}` is not in the sheet exactly once");
                $planted = str_replace($from, $to, $planted);
            }

            $defects = $this->legibilityDefects($planted, $palette);
            $this->assertNotSame([], $defects, "CONTROL ({$what}) did not bite");
            $this->assertNotSame([], array_filter($defects, fn (string $d): bool => str_starts_with($d, $state)), "CONTROL ({$what}) bit, but not {$state}: ".json_encode($defects));
        }

        $this->assertNotSame([], $this->popupTextPopulationDefects(['floor-cab-heading', 'floor-cab-status', 'floor-cab-stops', 'floor-cab-layout', 'floor-cab-feed', 'floor-cab-note'], null),
            'CONTROL (a new line in the popup, paired with nothing) did not bite');
        $this->assertNotSame([], $this->popupTextPopulationDefects(null, ['cab-cap', 'cab-name', 'cab-here', 'cab-badge']),
            'CONTROL (a new text in a stop\'s button, paired with nothing) did not bite');
    }

    /** § 12's *Cab button popup* is the sheet's: its bounds, its landscape share and its portrait height bound. */
    public function test_green_section_12s_cab_button_popup_is_the_sheets(): void
    {
        $this->assertSame([], $this->popupSizeDefects($this->cabSheet()));
        $this->assertNotSame([], $this->popupSizeDefects(str_replace('--cab-panel-max: 480px;', '--cab-panel-max: 520px;', $this->cabSheet())),
            'CONTROL (the sheet\'s widest popup moved off § 12) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace("        max-height: 55vh;\n", "        max-height: 70vh;\n", $this->cabSheet())),
            'CONTROL (the portrait height bound moved off § 12) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace("        max-height: 55dvh;\n", "        max-height: 70dvh;\n", $this->cabSheet())),
            'CONTROL (the portrait height bound\'s dynamic-viewport form moved off § 12) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace("        max-height: 55dvh;\n", '', $this->cabSheet())),
            'CONTROL (the portrait height bound with no dynamic-viewport form) did not bite');
        $this->assertNotSame([], $this->popupSizeDefects(str_replace('(max-width: 419px)', '(max-width: 359px)', $this->cabSheet())),
            'CONTROL (the stacked layout\'s width bound moved off the side-by-side one) did not bite');
    }

    /** ⛔ THE CONTROLS for the popup's palette — each plants one defect in the sheet's cab block. */
    public function test_the_palette_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $sheet = $this->cabSheet();

        foreach ([
            'a hex body' => ['    background-color: var(--cab-body);', '    background-color: #c9a873;'],
            'the themed body off the frame' => ['    --cab-body: var(--cab-frame);', '    --cab-body: var(--panel);'],
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
        return $this->cabBlockOf($this->fullSheet());
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
            '.floor-cab-panel' => ['background-color: var(--cab-body);'],
            '.floor-cab[data-themed]' => ['--cab-body: var(--cab-frame);'],
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
            // The height bound in both forms — `vh` first, the dynamic viewport's `dvh` over it (card#12047) — one figure.
            preg_match('/@media \(orientation: portrait\), \(max-width: \d+px\).*?\.floor-cab-panel \{[^}]*max-height: (\d+)vh;\s*max-height: (\d+)dvh;/s', $sheet, $h) === 1
                && $h[1] === $h[2] ? (int) $h[1] : null,
        ];
        $stated = array_map('intval', array_slice($cell, 1, 5));

        return $read === $stated ? [] : ['§ 12 says the popup is '.json_encode($stated).' (min px, max px, landscape vw, the narrowest side-by-side window px, portrait vh) and the sheet '.json_encode($read)];
    }

    /** The whole sheet: the `:root` tokens and the cab block. */
    private function fullSheet(): string
    {
        return (string) file_get_contents(public_path('css/mezzanine.css'));
    }

    /** @return array{frame: string, lamp: string} the shipped house theme's `PALETTE.elevator`, read by importing it as the page does */
    private function housePalette(): array
    {
        $themes = realpath(__DIR__.'/../../../../resources/floor/themes') ?: $this->fail('resources/floor/themes does not exist');
        $script = 'const d = process.argv[1]; const { HOUSE_THEME } = await import(d + "/index.js");'
            .' const { PALETTE } = await import(d + "/" + HOUSE_THEME + "/theme.js"); console.log(JSON.stringify(PALETTE.elevator));';
        $out = [];
        exec('node --input-type=module -e '.escapeshellarg($script).' '.escapeshellarg($themes).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "the house theme's palette could not be read:\n".implode("\n", $out));
        $palette = json_decode(implode("\n", $out), true);
        $this->assertIsArray($palette, 'the house theme\'s elevator palette is not an object');
        $this->assertArrayHasKey('frame', $palette);
        $this->assertArrayHasKey('lamp', $palette);

        return $palette;
    }

    /**
     * The popup's text-bearing elements are exactly the pairings `POPUP_TEXTS` holds: the ids in the panel's markup,
     * and the classes `cab-panel.js` gives a stop's button's parts. Either list may be handed in (a control's).
     *
     * @param  list<string>|null  $ids
     * @param  list<string>|null  $classes
     * @return list<string>
     */
    private function popupTextPopulationDefects(?array $ids = null, ?array $classes = null): array
    {
        if ($ids === null) {
            $blade = (string) file_get_contents(resource_path('views/floor.blade.php'));
            $this->assertSame(1, preg_match('#<section id="floor-cab-panel"[^>]*>(.*?)</section>#s', $blade, $section), 'the floor view has no cab panel section');
            preg_match_all('/\bid="([^"]+)"/', $section[1], $m);
            $ids = $m[1];
        }

        if ($classes === null) {
            preg_match_all("/\\.className = '([^']+)';/", (string) file_get_contents($this->moduleDir().'/cab-panel.js'), $m);
            $classes = $m[1];
        }

        // `floor-cab-stops` is the list of buttons, whose words are the classes'; `cab-cap` is the call button, which holds none.
        $paired = ['floor-cab-heading' => 'the heading', 'floor-cab-status' => 'the status line', 'floor-cab-layout' => 'the layout line',
            'floor-cab-feed' => 'the feed line', 'floor-cab-stops' => true, 'cab-cap' => true, 'cab-name' => 'a stop\'s name', 'cab-here' => '*you are here*'];
        $defects = [];

        foreach ([...$ids, ...$classes] as $element) {
            if (! array_key_exists($element, $paired)) {
                $defects[] = "the popup's `{$element}` has no pairing in POPUP_TEXTS — its contrast is not checked";
            }
        }

        foreach (array_keys($paired) as $element) {
            if (! in_array($element, [...$ids, ...$classes], true)) {
                $defects[] = "POPUP_TEXTS pairs `{$element}`, which the popup no longer builds";
            }
        }

        return $defects;
    }

    /**
     * Every `POPUP_TEXTS` pairing's contrast, without the theme and with the house theme, under its floor.
     *
     * @param  array{frame: string, lamp: string}  $palette
     * @return list<string>
     */
    private function legibilityDefects(string $sheet, array $palette): array
    {
        $this->assertSame(1, preg_match('/:root\s*\{([^}]*)\}/', $sheet, $root), 'the sheet declares no :root block');
        preg_match_all('/--([a-z0-9_-]+)\s*:\s*(#[0-9a-f]{6})\s*;/i', $root[1], $t);
        $tokens = array_combine($t[1], $t[2]);
        $rules = $this->rulesOf($this->cabBlockOf($sheet));
        $defects = [];

        foreach (['without the theme' => null, 'with the theme' => $palette] as $state => $carried) {
            // The dialog's custom properties: its defaults, the themed rule over them, and the carriers inline over both.
            $scope = $this->customProperties($rules['.floor-cab'] ?? []);

            if ($carried !== null) {
                $scope = [...$scope, ...$this->customProperties($rules['.floor-cab[data-themed]'] ?? []), '--cab-frame' => $carried['frame'], '--cab-lamp' => $carried['lamp']];
            }

            $resolve = function (string $value) use (&$resolve, $scope, $tokens, $state): array {
                $value = trim($value);

                if (preg_match('/^#[0-9a-f]{6}$/i', $value) === 1) {
                    return $this->rgbOf($value);
                }

                if (preg_match('/^var\(\s*(--[a-z0-9_-]+)\s*\)$/i', $value, $v) === 1) {
                    $named = $scope[$v[1]] ?? (isset($tokens[substr($v[1], 2)]) ? $tokens[substr($v[1], 2)] : null);
                    $this->assertNotNull($named, "{$state}: {$v[1]} resolves to nothing");

                    return $resolve($named);
                }

                if (preg_match('/^color-mix\(in srgb,\s*(.+?)\s+(\d+(?:\.\d+)?)%,\s*(.+)\)$/', $value, $mix) === 1) {
                    [$a, $b, $p] = [$resolve($mix[1]), $resolve($mix[3]), (float) $mix[2] / 100];

                    return [$a[0] * $p + $b[0] * (1 - $p), $a[1] * $p + $b[1] * (1 - $p), $a[2] * $p + $b[2] * (1 - $p)];
                }

                $this->fail("{$state}: the contrast check cannot resolve the colour `{$value}`");
            };

            foreach (self::POPUP_TEXTS as $what => [$colourRules, $property, $on]) {
                $ink = $this->lastDeclared($rules, $colourRules, $property);
                $this->assertNotNull($ink, "{$what}: no `{$property}` in ".implode(', ', $colourRules));

                if ($property === 'outline') {
                    $ink = (string) preg_replace('/^\S+\s+\S+\s+/', '', $ink);
                }

                if ($on === 'body') {
                    $base = $resolve((string) $this->lastDeclared($rules, ['.floor-cab-panel'], 'background-color'));
                    $grounds = [$base];
                    $image = (string) $this->lastDeclared($rules, ['.floor-cab-panel'], 'background-image');
                    $this->assertSame(1, preg_match('/linear-gradient\((.*)\)\s*$/s', $image, $gradient), 'the popup\'s body has no light-and-shade gradient this check can read');
                    preg_match_all('#rgb\((\d+) (\d+) (\d+) / ([\d.]+)\)#', $gradient[1], $stops, PREG_SET_ORDER);
                    $this->assertNotSame([], $stops, 'the popup\'s gradient has no rgb() stop this check can read');

                    foreach ($stops as [, $r, $g, $b, $alpha]) {
                        $a = (float) $alpha;
                        $grounds[] = [$base[0] * (1 - $a) + $r / 255 * $a, $base[1] * (1 - $a) + $g / 255 * $a, $base[2] * (1 - $a) + $b / 255 * $a];
                    }
                } else {
                    $grounds = [$resolve((string) $this->lastDeclared($rules, $on, 'background'))];
                }

                $ratio = min(array_map(fn (array $ground): float => $this->contrastRatio($resolve($ink), $ground), $grounds));
                $floor = $property === 'outline' ? self::RING_FLOOR : self::TEXT_FLOOR;

                if ($ratio < $floor) {
                    $defects[] = sprintf('%s: %s is %.2f:1 on what it sits on, under %.1f:1', $state, $what, $ratio, $floor);
                }
            }
        }

        return $defects;
    }

    /** The cab block of a whole sheet: from its § 4.5 comment to the sign-in card's rule that follows it. */
    private function cabBlockOf(string $sheet): string
    {
        $start = strpos($sheet, "/*\n * § 4.5's CAB");
        $end = strpos($sheet, "#floor-signin {\n    position: fixed;");
        $this->assertNotFalse($start, "the sheet has no § 4.5's CAB block this check can read");
        $this->assertNotFalse($end, 'the sheet has no sign-in rule closing the cab block');

        return substr($sheet, $start, $end - $start);
    }

    /** @return array<string, list<array{string, string}>> each single selector's declarations, in sheet order, comments dropped */
    private function rulesOf(string $block): array
    {
        $rules = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) preg_replace('#/\*.*?\*/#s', '', $block), $m, PREG_SET_ORDER);

        foreach ($m as [, $selectors, $body]) {
            preg_match_all('/([a-z-]+)\s*:\s*([^;]+);/i', $body, $d, PREG_SET_ORDER);

            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                foreach ($d as [, $property, $value]) {
                    $rules[$selector][] = [$property, trim($value)];
                }
            }
        }

        return $rules;
    }

    /**
     * @param  list<array{string, string}>  $declarations
     * @return array<string, string>
     */
    private function customProperties(array $declarations): array
    {
        $out = [];

        foreach ($declarations as [$property, $value]) {
            if (str_starts_with($property, '--')) {
                $out[$property] = $value;
            }
        }

        return $out;
    }

    /**
     * The value `property` last takes across `selectors`, in the order given.
     *
     * @param  array<string, list<array{string, string}>>  $rules
     * @param  list<string>  $selectors
     */
    private function lastDeclared(array $rules, array $selectors, string $property): ?string
    {
        $value = null;

        foreach ($selectors as $selector) {
            foreach ($rules[$selector] ?? [] as [$p, $v]) {
                if ($p === $property) {
                    $value = $v;
                }
            }
        }

        return $value;
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
