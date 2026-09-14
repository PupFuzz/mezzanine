<?php

namespace Tests\Feature\DrillDown;

use Tests\TestCase;

/**
 * `public/js/wire/duration.js` against `docs/design/FLOOR.md § 2.4` — the GUARD that makes the
 * client's copy of the duration format a restatement somebody checks rather than a comment.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE EXPECTED VALUES ARE RE-DERIVED FROM THE DOCUMENT ON EVERY RUN, never written here. § 2.4
 * publishes seven clauses and a boundary table that "reproduces every row of this table on every
 * run … so a clause edited without its outputs — or an output edited without its clause — reds".
 * `tools/design/verify-floor.py` holds the PYTHON re-implementation to that table; a browser
 * cannot run that verifier, so the shipped `.js` needs its own leg of the same guard, and this
 * is it. A hand-written expected table here would be a THIRD copy of the format — the shape that
 * lets two copies agree while the document says something else.
 *
 * ⚠ WHAT THIS DOES NOT REACH, said rather than implied: § 2.4's WORDING table (four strings, one
 * per fact) and the durations that appear inside § 7.1's label cells. Those are rendered by
 * surfaces this card does not build, and the § 12 gate G12 that evaluates them is the design
 * verifier's. What is checked here is the FORMAT function the client ships.
 */
class DurationFormatMatchesTheDocumentTest extends TestCase
{
    use DrivesTheDrillDownClient;

    public function test_the_client_reproduces_every_row_of_the_boundary_table(): void
    {
        $rows = $this->documentDurations();

        // ⛔ THE POPULATION IS ASSERTED BEFORE IT IS TRUSTED. A parse that returns nothing makes
        // the loop below iterate zero times and report clean over a table it never read, which
        // is the one way this guard becomes a decoration. The bound is stated as "more than a
        // handful" rather than as a count, because a count here is a second copy of how many
        // rows § 2.4 happens to publish and would red on the day a clause gains a boundary.
        $this->assertGreaterThan(5, count($rows),
            'the § 2.4 boundary table parsed to almost nothing — every assertion below would be '
            .'clean over an unread population');

        $probe = $this->probe(['durations' => array_map(fn ($r) => (float) $r[0], $rows)]);

        foreach ($rows as $i => [$seconds, $rendered]) {
            $this->assertSame($rendered, $probe['durations'][$i],
                "§ 2.4's boundary table says {$seconds}s renders `{$rendered}`, and the shipped "
                .'client does not');
        }
    }

    /** ⛔ THE CONTROLS. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the parse's own bound. § 2.4's table header is what bounds the walk, and a
        // renamed header answers `false` from `strpos`, which is `0` in arithmetic and would
        // hand the parse the whole document. It must return NOTHING rather than "some rows".
        $renamed = str_replace(self::S24_TABLE, '| Seconds In | Renders | Why |', $this->floorMd());

        $this->assertNotSame($renamed, $this->floorMd(), 'CONTROL 1 planted nothing — the header has moved');
        $this->assertSame([], $this->documentDurations($renamed),
            'CONTROL 1 did not bite: the header was renamed and the parser still found rows, so '
            .'it is reading something other than the table it names');

        // CONTROL 2 — the format itself. Clause 5 zero-pads the SECOND unit ("`2h 06m`, `2m
        // 05s`, never `2h 6m`"), which is the clause a re-implementation drops most quietly
        // because the string still looks like a duration. Break it in the SHIPPED module and
        // require the comparison above to notice.
        $dir = $this->mutatedModules([
            '../wire/duration.js',
            "String(second).padStart(2, '0')",
            'String(second)',
        ]);

        $rows = $this->documentDurations();
        $probe = $this->probe(['durations' => array_map(fn ($r) => (float) $r[0], $rows)], $dir);

        $mismatch = false;

        foreach ($rows as $i => [, $rendered]) {
            if ($probe['durations'][$i] !== $rendered) {
                $mismatch = true;
            }
        }

        $this->assertTrue($mismatch,
            'CONTROL 2 did not bite: the pad was removed from the shipped formatter and every '
            .'row of § 2.4 still reproduced, so this guard cannot fail');

        // CONTROL 3 — a null basis is NOT `0s`. Clause 7 is explicit that `0s` is a measurement
        // and never the render of a missing one, so the function must refuse rather than answer.
        [$status, , $stderr] = $this->runProbe(['durations' => [null]]);

        $this->assertNotSame(0, $status,
            'CONTROL 3 did not bite: a null was formatted as a duration instead of refusing, '
            .'which is how a seat with no sample comes to claim a measurement at this instant');
        $this->assertStringContainsString('never 0s', $stderr);
    }
}
