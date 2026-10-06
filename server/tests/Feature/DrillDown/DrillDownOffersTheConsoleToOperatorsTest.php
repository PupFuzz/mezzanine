<?php

namespace Tests\Feature\DrillDown;

use Tests\TestCase;

/**
 * card#9416 — `docs/design/FLOOR.md § 4.3`'s **console** row: an ***Open console on claude.ai***
 * link, opening in a new tab, on an OPERATOR's panel and on no other.
 *
 * The gate is the server's (`FleetController`, D2 § 8.2.3 — `Tests\Feature\Fold\
 * ConsoleUrlReachesOperatorsOnlyTest` holds it): an operator's seat response carries
 * `detail.console_url` and an observer's carries no such member. So this suite drives the shipped
 * client over the two response shapes and reads what the DOM receives — the panel must draw the link
 * from the first, and nothing from the second or from an operator's `null`. Every session id is
 * synthetic.
 */
class DrillDownOffersTheConsoleToOperatorsTest extends TestCase
{
    use DrivesTheDrillDownClient;

    private const URL = 'https://claude.ai/code/session_SynthAAAAAAAAAAAAAAAAAAA';

    /** @return array<string, mixed> the `[data-panel-console]` slot as `main.js` left it */
    private function consoleSlot(array $detail, ?string $moduleDir = null): array
    {
        $out = $this->probe([
            'seat' => $this->seatBody([], $detail),
            'timeline' => $this->timelineBody(),
            'now_ms' => $this->nowMs(),
            'drive_main' => true,
        ], $moduleDir);

        return $out['main']['dom']['[data-panel-console]'] ?? $this->fail('main.js never wrote the console slot');
    }

    /** An operator's response: the link, its text naming where it goes, and its paragraph shown. */
    public function test_an_operators_panel_offers_the_console_link(): void
    {
        $slot = $this->consoleSlot($this->detailBody() + ['console_url' => self::URL]);

        $this->assertSame(self::URL, $slot['attributes']['href'] ?? null);
        $this->assertSame('Open console on claude.ai', $slot['text']);
        $this->assertFalse($slot['hidden']);
        $this->assertFalse($slot['parent_hidden'], 'the link is drawn inside a hidden paragraph');
    }

    /** An observer's response carries no member; an operator's may carry `null`. Neither draws a line. */
    public function test_an_observers_panel_and_a_null_draw_no_line(): void
    {
        foreach (['observer (no member)' => $this->detailBody(), 'operator, null' => $this->detailBody() + ['console_url' => null]] as $case => $detail) {
            $slot = $this->consoleSlot($detail);

            $this->assertArrayNotHasKey('href', $slot['attributes'], $case.': an href with no URL behind it');
            $this->assertSame('', $slot['text'], $case.': the link text was drawn with no link');
            $this->assertTrue($slot['hidden'], $case.': the link is not hidden');
            $this->assertTrue($slot['parent_hidden'], $case.': an empty paragraph is left on the panel');
        }
    }

    /** The page's link opens a new tab and hands claude.ai no handle on this page. */
    public function test_the_page_declares_the_link_as_a_new_tab_without_an_opener(): void
    {
        $blade = (string) file_get_contents(resource_path('views/floor.blade.php'));

        $this->assertMatchesRegularExpression(
            '#<a data-panel-console target="_blank" rel="noopener noreferrer" hidden></a>#',
            $blade,
            'the console link is not a new-tab link with `rel="noopener noreferrer"`',
        );
    }

    /**
     * ⛔ RED — a client that draws the link whatever the response carries puts an operator-only control
     * on an observer's panel. Planted on a copy, and seen to fail the observer case above.
     */
    public function test_red_a_client_that_ignores_the_member_draws_a_link_for_an_observer(): void
    {
        $dir = $this->mutatedModules([
            'drilldown-model.js',
            "return typeof url === 'string' && url !== '' ? { url, text: OPEN_CONSOLE } : null;",
            "return { url: url ?? 'https://claude.ai/code', text: OPEN_CONSOLE };",
        ]);

        $slot = $this->consoleSlot($this->detailBody(), $dir);

        $this->assertFalse($slot['hidden'], 'the planted defect did not draw the link — the observer check above would not see it');
        $this->assertSame('Open console on claude.ai', $slot['text']);
    }
}
