<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * The drill-down's two GUARDED COPIES — `drilldown/drilldown-model.js`'s `BADGE_LINE` (`docs/design/
 * FLOOR.md § 7.2`'s **Origin** and **Drill-down line** columns) and `RAISED_BY` (`docs/design/
 * EVENT-SCHEMA.md § 9.3`'s **Raised by** column for `reporter.heartbeat.degraded`) — re-derived from the
 * documents on every run and set-differenced both directions. Appendix B row 10, card#7342.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY A GUARD AND NOT A POINTER. A browser cannot read either document, so the panel has to carry the
 * words it draws; a copy a program loads is a copy no pointer keeps honest. This is the same guard
 * `Tests\Feature\Desk\TheDeskSpeaksTheDocumentsWordsTest` holds the desk's § 7.1 sentences to.
 *
 * ⛔ WHAT A CELL CONTRIBUTES IS STATED: the Drill-down line is the cell's FIRST italic span, with the code
 * quotes the document sets inside it dropped; the Raised-by cell is every backticked counter name in it —
 * a family written `x.<y>` included — and a span that is an expression (`counters_omitted > 0`) is prose,
 * not a name.
 */
class TheDrillDownSpeaksTheDocumentsBadgeLinesTest extends TestCase
{
    use DrivesTheDrillDown;

    public function test_every_badge_line_and_origin_is_section_7_2s(): void
    {
        $this->assertSame($this->documentLines(), $this->shipped()['lines']);
    }

    public function test_every_raised_by_cell_is_d1_section_9_3s(): void
    {
        $this->assertSame($this->documentRaisedBy(), $this->shipped()['raised_by']);
    }

    /** ⛔ THE CONTROLS — a drift planted on each side of each copy, and each seen to red. */
    public function test_the_guard_goes_red_on_a_drift_in_either_copy_or_either_document(): void
    {
        $edited = $this->shipped($this->mutatedModules(['../drilldown/drilldown-model.js',
            "line: 'two events claimed one sequence number'", "line: 'two events claimed one sequence'"]));
        $this->assertNotSame($this->documentLines(), $edited['lines'], 'CONTROL 1 did not bite: an edited line matched § 7.2');

        $dropped = $this->shipped($this->mutatedModules(['../drilldown/drilldown-model.js',
            "    statusline_degraded: Object.freeze(['wrapped_statusline_failures']),\n", '']));
        $this->assertNotSame($this->documentRaisedBy(), $dropped['raised_by'], 'CONTROL 2 did not bite: a dropped member matched D1');

        $doc = str_replace('| *two events claimed one sequence number* |', '| *two events claimed one number* |', $this->floorMd());
        $this->assertNotSame($doc, $this->floorMd());
        $this->assertNotSame($this->documentLines($doc), $this->shipped()['lines'], 'CONTROL 3 did not bite: a document edit went unseen');

        $schema = str_replace('| `statusline_degraded` | `wrapped_statusline_failures` |', '| `statusline_degraded` | `wrapped_statusline_failures`, `statusline_timeouts` |', $this->eventSchemaMd());
        $this->assertNotSame($schema, $this->eventSchemaMd());
        $this->assertNotSame($this->documentRaisedBy($schema), $this->shipped()['raised_by'], 'CONTROL 4 did not bite: a D1 edit went unseen');
    }

    /** @return array{lines: array<string, array{origin: string, line: string}>, raised_by: array<string, list<string>>} */
    private function shipped(?string $wireDir = null): array
    {
        $model = dirname($wireDir ?? $this->moduleDir()).'/drilldown/drilldown-model.js';
        $script = 'const m = await import('.json_encode('file://'.$model).');'
            .'console.log(JSON.stringify({ lines: m.BADGE_LINE, raised_by: m.RAISED_BY }));';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script));

        $this->assertIsString($out, 'node could not read the drill-down model');

        $decoded = json_decode($out, true);
        ksort($decoded['lines']);
        ksort($decoded['raised_by']);

        return $decoded;
    }

    /** @return array<string, array{origin: string, line: string}> */
    private function documentLines(?string $doc = null): array
    {
        $doc ??= $this->floorMd();
        $start = strpos($doc, '| Badge | Origin | Rendered on the desk | Drill-down line |');

        $this->assertNotFalse($start, "§ 7.2's badge table was not found");

        $lines = [];

        foreach (explode("\n", substr($doc, $start)) as $i => $row) {
            if ($i > 1 && ! str_starts_with($row, '|')) {
                break;
            }

            if (preg_match('/^\| `([a-z_]+)` \| (D1|D2|both) \| [^|]+ \| \*([^*]+)\*/', $row, $m) === 1) {
                $lines[$m[1]] = ['origin' => $m[2], 'line' => str_replace('`', '', $m[3])];
            }
        }

        $this->assertCount(18, $lines, "§ 7.2's table did not parse to its 18 badges");
        ksort($lines);

        return $lines;
    }

    /** @return array<string, list<string>> */
    private function documentRaisedBy(?string $doc = null): array
    {
        $doc ??= $this->eventSchemaMd();
        $start = strpos($doc, '| Member | Raised by | What a consumer should render |');

        $this->assertNotFalse($start, "D1 § 9.3's Raised-by table was not found");

        $raised = [];

        foreach (explode("\n", substr($doc, $start)) as $i => $row) {
            if ($i > 1 && ! str_starts_with($row, '|')) {
                break;
            }

            if (preg_match('/^\| `([a-z_]+)` \| ([^|]+) \|/', $row, $m) === 1) {
                preg_match_all('/`([^`]+)`/', $m[2], $names);
                $raised[$m[1]] = array_values(array_filter($names[1],
                    static fn (string $n): bool => preg_match('/^[a-z_.]+(<[a-z ]+>)?[a-z_.]*$/', $n) === 1));
            }
        }

        $this->assertCount(12, $raised, "D1 § 9.3's Raised-by table did not parse to its twelve members");
        ksort($raised);

        return $raised;
    }

    private function eventSchemaMd(): string
    {
        $path = realpath(__DIR__.'/../../../../docs/design/EVENT-SCHEMA.md')
            ?: $this->fail('docs/design/EVENT-SCHEMA.md was not found from the test tree');

        return (string) file_get_contents($path);
    }
}
