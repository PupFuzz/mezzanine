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
