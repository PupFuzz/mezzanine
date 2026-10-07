<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * AT-D3-25's SELECTION HALF, the floor's leg — `docs/design/FLOOR.md § 10.6` item 5 and § 9 F23, built at
 * Appendix B row 21 (card#11046): the scene resolves the floor's layout `theme` against the theme
 * registry. A floor naming none, and a floor naming the house theme, resolve to the house theme with no
 * notice; a floor naming a theme the build does not ship resolves to the house theme under F23's notice,
 * in § 5.5's words, naming the theme and the floor. The registry is `resources/floor/themes/index.js`,
 * read by the harness from disk as the page reads it by the asset route.
 *
 * The layout readers' half — a non-string refused at load, an unshipped name refused at the write — is
 * `Tests\Feature\Building\BuildingLayoutTest` and `Tests\Feature\Admin\LayoutConsoleTest`; the
 * cross-runtime pin is `compose-cases.json`'s theme cases.
 */
class TheFloorResolvesItsThemeTest extends TestCase
{
    use DrivesTheScene;

    private const RUN = 'scene_default';

    public function test_a_floor_naming_no_theme_is_drawn_in_the_house_theme_with_no_notice(): void
    {
        [$scene, $frame] = $this->themed(null);

        $this->assertSame('studio', $scene['theme']);
        $this->assertSame([], $this->f23($frame));
    }

    /** Control (c): naming the house theme draws exactly what naming none draws — no notice on every floor. */
    public function test_a_floor_naming_the_house_theme_is_drawn_as_a_floor_naming_none(): void
    {
        [$named, $namedFrame] = $this->themed('studio');
        [$none, $noneFrame] = $this->themed(null);

        $this->assertSame($none['theme'], $named['theme']);
        $this->assertSame($noneFrame['notices'], $namedFrame['notices']);
    }

    public function test_a_floor_naming_an_unshipped_theme_is_drawn_in_the_house_theme_under_f23(): void
    {
        [$scene, $frame] = $this->themed('nowhere');

        $this->assertSame('studio', $scene['theme']);
        $this->assertSame(['floor theme `nowhere` is not installed — drawn in the house theme — `aimla`'], $this->f23($frame));
    }

    public function test_red_the_silent_substitute(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js', 'if (resolved.notice !== null) {', 'if (false) {']);

        [, $frame] = $this->themed('nowhere', $dir);

        $this->assertSame([], $this->f23($frame), 'RED (the unshipped theme drawn as the house theme with no notice) still drew F23\'s line');
    }

    public function test_red_the_unshipped_name_drawn_as_named(): void
    {
        $dir = $this->mutatedModules(['../floor/scene.js',
            "        : { theme: themes.house, notice: unshippedThemeNotice(floor.theme, floor.name) };",
            "        : { theme: floor.theme, notice: null };"]);

        [$scene, $frame] = $this->themed('nowhere', $dir);

        $this->assertNotSame('studio', $scene['theme'], 'RED (the unshipped name resolved as itself) still resolved to the house theme');
        $this->assertSame([], $this->f23($frame));
    }

    /**
     * The shipped default run, its layout replaced by one floor — the `aimla` room — whose entry names
     * `$theme` (or none).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function themed(?string $theme, ?string $dir = null): array
    {
        $http = $this->fixture(self::RUN)['http'];
        $floor = ['floor' => 'aimla', 'label' => null, 'theme' => $theme, 'rooms' => [['install' => 'aimla', 'form' => 'open']]];

        $http['/api/building'] = [['status' => 200, 'body' => ['layout' => ['layout_version' => 1, 'floors' => [$floor]], 'rooms' => []]]];

        $result = $this->floorRun(self::RUN, $dir, ['http' => $http]);

        return [$this->lastScene($result, self::RUN), $this->lastFloor($result)];
    }

    /** @return list<string> */
    private function f23(array $frame): array
    {
        return array_values(array_filter($frame['notices'], fn (string $n): bool => str_starts_with($n, 'floor theme ')));
    }
}
