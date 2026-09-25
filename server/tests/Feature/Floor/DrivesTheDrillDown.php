<?php

namespace Tests\Feature\Floor;

/**
 * The rig Appendix B row 10's gate tests share — `docs/design/FLOOR.md` § 4.3's **drill-down**,
 * opened from the floor and observed through step 3's **harness**. The floor's own half (the floor
 * screen run over the shipped client and building client, a frame per settled event) is
 * `DrivesTheFloorScreen` and is not restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT A "RENDERED PANEL" IS, HEADLESSLY. The probe opens the SHIPPED `drilldown/drilldown-panel.js`
 * through the SHIPPED floor screen (`openPanel`, the call a desk's click makes) at the instant the
 * fixture names, its two requests are answered on the scenario clock like every other response, and
 * every floor frame carries `panel` — the SHIPPED `drilldown-model.js` model over what the panel holds
 * at that frame. The DOM half (`drilldown/main.js`) writes that model's strings and decides nothing; it
 * is driven over a stub by `Tests\Feature\DrillDown`.
 *
 * ⛔ EVERY RUN IS A NAMED RUN IN `fixtures/fx-drilldown.json`, whose seat objects are lifted from the
 * fixture each run names, so every byte replayed here is in the diff.
 */
trait DrivesTheDrillDown
{
    use DrivesTheFloorScreen;

    /**
     * Every frame of a run that drew the panel, as `[at, panel]`, in order.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    protected function panelFrames(array $result): array
    {
        $frames = [];

        foreach ($result['floor_renders'] as $render) {
            if (($render['frame']['panel'] ?? null) !== null) {
                $frames[] = [$render['at'], $render['frame']['panel']];
            }
        }

        return $frames;
    }

    /**
     * The last frame whose panel had its detail request ANSWERED (not loading) for one seat.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function openPanel(array $result, string $seatId): array
    {
        $found = null;

        foreach ($this->panelFrames($result) as [, $panel]) {
            if ($panel['seat']['seat_id'] === $seatId && $panel['loading'] === false) {
                $found = $panel;
            }
        }

        $this->assertNotNull($found, "no frame drew the drill-down open on `{$seatId}` with its requests answered");

        return $found;
    }

    /**
     * A copy of the shipped tree with several anchored edits, each asserted present exactly once — the
     * multi-file twin of `mutatedModules`, for a plant that must land on the desk AND the panel.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $edits  [file relative to `wire/`, anchor, replacement]
     */
    protected function plantedTree(array $edits): string
    {
        $dir = $this->mutatedModules($edits[0]);

        foreach (array_slice($edits, 1) as [$file, $anchor, $replacement]) {
            $target = $dir.DIRECTORY_SEPARATOR.$file;
            $source = (string) file_get_contents($target);

            $this->assertSame(1, substr_count($source, $anchor),
                "a multi-edit plant's anchor is not in {$file} exactly once — it has been renamed, so this "
                .'control would mutate less than it claims');

            file_put_contents($target, str_replace($anchor, $replacement, $source));
        }

        return $dir;
    }
}
