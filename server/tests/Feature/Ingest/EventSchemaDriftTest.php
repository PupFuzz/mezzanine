<?php

namespace Tests\Feature\Ingest;

use App\Ingest\KindRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `App\Ingest\KindRegistry` is a transcription of `docs/design/EVENT-SCHEMA.md` § 6. This test
 * re-derives the same population from the document and asserts the two agree, in both directions.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A GUARD AND NOT A POINTER. The engineering rule is that a restatement gets a pointer where
 * the consumer can follow one and a drift check where it cannot. A PHP request path cannot follow
 * a link to a markdown table, so the registry has to hold the values — which makes it a second
 * copy, free to diverge from the first.
 *
 * D1 § 6.0 records this exact shape costing that document two review rounds: "The first review
 * found two hand-transcribed hook facts wrong. The fix corrected those two instances — and built
 * new designs on five more hand-transcribed facts, which the second review found wrong or absent.
 * Correcting instance N without binding the transcription to a source leaves instance N+1 to be
 * minted by the very next edit … So the fix is the binding, not the corrections."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A MISS COSTS, which is why this is a test rather than a review note. A wrong enum member
 * set makes a conforming seat's event a `422 invalid_event`; § 12.4 takes its ≤ 199 neighbours;
 * § 11.5 quarantines the batch permanently. For `reporter.heartbeat.degraded` that lands on the
 * liveness backstop at the moment a seat becomes degraded — the one thing § 9.2 says it may never
 * do.
 */
class EventSchemaDriftTest extends TestCase
{
    private const DOC = __DIR__.'/../../../../docs/design/EVENT-SCHEMA.md';

    /**
     * D1 § 6.0's classification table is the authority for which side each enum falls on, and
     * therefore for whether an unrecognised value coerces or refuses. It is parsed rather than
     * restated: the "Unknown member" column IS `KindRegistry`'s `unknown` key.
     */
    public function test_every_enum_field_has_the_side_and_unknown_member_section_6_0_gives_it(): void
    {
        $doc = $this->doc();

        // Rows look like:  | `session.start.source` | harness — … | `unknown` | § 6.1 |
        //                  | `turn.end.end_reason`  | reporter    | —         | § 6.4 |
        preg_match_all(
            '/^\|\s*`([a-z_]+(?:\.[a-z_]+)*)`[^|]*\|\s*(harness|reporter)[^|]*\|\s*(`[a-z_]+`|—|-)\s*\|/m',
            $doc,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertGreaterThanOrEqual(
            23,
            count($matches),
            'the § 6.0 classification table did not parse; the guard would pass vacuously'
        );

        $seen = [];

        foreach ($matches as [, $field, $side, $unknown]) {
            $expected = str_starts_with($unknown, '`') ? trim($unknown, '`') : null;
            $seen[$field] = true;

            [$kind, $name] = $this->split($field);

            if ($kind === null) {
                // `reporter_platform` — the batch envelope's one enum.
                $this->assertSame(
                    $expected,
                    KindRegistry::BATCH_ENUM_REPORTER_PLATFORM['unknown'],
                    "batch enum {$field}",
                );

                continue;
            }

            $this->assertArrayHasKey($kind, KindRegistry::KINDS, "kind {$kind} (from § 6.0 row {$field})");
            $this->assertArrayHasKey(
                $name,
                KindRegistry::KINDS[$kind]['enums'],
                "§ 6.0 declares {$field} an enum; KindRegistry does not",
            );

            $this->assertSame(
                $expected,
                KindRegistry::KINDS[$kind]['enums'][$name]['unknown'],
                sprintf(
                    '%s: § 6.0 says %s, KindRegistry says %s. A null unknown member means the ingest '
                    .'REFUSES an unrecognised value (422) and a non-null one means it COERCES — '
                    .'getting this backwards is either a swallowed reporter bug or a permanently '
                    .'quarantined batch.',
                    $field,
                    $expected ?? 'reporter-minted (no unknown member)',
                    KindRegistry::KINDS[$kind]['enums'][$name]['unknown'] ?? 'reporter-minted',
                ),
            );
        }

        // And the reverse direction: nothing in the registry that § 6.0 does not classify. Without
        // this half, an invented enum field would sit in the registry unchallenged, refusing
        // values D1 never closed.
        foreach (KindRegistry::KINDS as $kind => $spec) {
            foreach (array_keys($spec['enums']) as $name) {
                $this->assertArrayHasKey(
                    "{$kind}.{$name}",
                    $seen,
                    "KindRegistry declares {$kind}.{$name} an enum; § 6.0's classification table does not list it",
                );
            }
        }
    }

    /**
     * The MEMBER SETS, read out of each kind's own `data` field table.
     */
    #[DataProvider('enumMemberRows')]
    public function test_every_enum_member_set_matches_the_documents_field_table(string $kind, string $field, array $documented): void
    {
        $this->assertSame(
            $documented,
            KindRegistry::KINDS[$kind]['enums'][$field]['members'],
            sprintf('%s.%s member set has drifted from docs/design/EVENT-SCHEMA.md', $kind, $field),
        );
    }

    public static function enumMemberRows(): array
    {
        $cases = [];

        foreach (self::enumRows() as [$kind, $field, $members]) {
            // A row with no members of its own defers to another section: § 6.8 does it for all
            // three of its enums — "by reference and never restated — this event is a second
            // projection of that call's close, so a value set written twice is a value set free
            // to drift" — and § 6.13's `resolution_source` says "as the table above". The
            // referenced rows carry the values; `deferredEnumRows()` accounts for these.
            if ($members !== []) {
                $cases["{$kind}.{$field}"] = [$kind, $field, $members];
            }
        }

        // § 9.3's own table is `reporter.heartbeat.degraded`'s value set — "defined here and
        // nowhere else", because an earlier draft typed the field `array<enum>` and pointed at a
        // section that had no such list.
        $doc = (string) file_get_contents(self::DOC);

        if (preg_match('/#### `reporter\.heartbeat\.degraded`.*?\n\n(\|\s*Member.*?)\n\n/ms', $doc, $table)) {
            preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $table[1], $m);
            $cases['reporter.heartbeat.degraded'] = ['reporter.heartbeat', 'degraded', $m[1]];
        }

        return $cases;
    }

    /**
     * The `data` KEY SETS, which are the population `ignored_unknown_fields` is counted over.
     */
    #[DataProvider('kindSections')]
    public function test_every_kinds_data_field_set_matches_the_document(string $kind, array $documented): void
    {
        $this->assertSame(
            $documented,
            KindRegistry::KINDS[$kind]['fields'],
            sprintf(
                "%s's data key set has drifted. A key the document declares and the registry does "
                .'not is counted as `ignored_unknown_fields` on every event carrying it, which '
                .'renders the seat `reporter_ahead` for a field D1 has always had.',
                $kind,
            ),
        );
    }

    public static function kindSections(): array
    {
        $doc = (string) file_get_contents(self::DOC);
        $cases = [];

        preg_match_all('/^### 6\.\d+ `([a-z]+\.[a-z_]+)`\n(.*?)(?=\n### |\n## )/ms', $doc, $sections, PREG_SET_ORDER);

        foreach ($sections as [, $kind, $body]) {
            // Only rows of a table whose first header cell is "`data` field" or "`data` field |
            // Source key…" — § 6.11's table has an extra column.
            if (! preg_match('/^\|\s*`data` field\s*\|.*?\n\|[-\s|]+\|\n(.*?)(?=\n\n|\n[^|])/ms', $body, $table)) {
                continue;
            }

            preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $table[1], $rows);

            $cases[$kind] = [$kind, $rows[1]];
        }

        return $cases;
    }

    /**
     * THE NON-VACUITY GUARD, and it is not ceremony — it is the defect this file already hit.
     *
     * The two providers below are regexes over a markdown document. When they were first written
     * they returned zero cases (PHPUnit 12 removed `@dataProvider` doc-comment metadata, so the
     * attribute was never read) and the suite reported the drift checks as having RUN. A parser
     * that stops matching — a heading renumbered, a table column added — fails exactly that way:
     * silently, as a green.
     *
     * So the population is asserted to have the size the document says it has, and the numbers
     * are re-derived from the document rather than written down here.
     */
    public function test_the_drift_providers_are_not_empty(): void
    {
        $doc = $this->doc();

        preg_match_all('/^### 6\.\d+ `([a-z]+\.[a-z_]+)`$/m', $doc, $kinds);

        $this->assertCount(
            count($kinds[1]),
            self::kindSections(),
            'the data-field provider did not extract one table per § 6 kind',
        );

        // THE PARTITION, re-derived rather than written down. Every enum field the registry
        // declares is either extracted with its own members or is a row the document defers to
        // another section — and nothing may be in neither, which is how a field silently stops
        // being checked.
        //
        // The count is deliberately NOT a literal. An earlier version of this assertion carried
        // `- 3` for "subagent.stop's three deferred rows" and was wrong: § 6.13's
        // `resolution_source` defers too, so the real number was four. A written figure stops
        // living in the loop and starts living in an artifact the loop cites; a re-derivation
        // survives the document moving.
        $extracted = array_keys(self::enumMemberRows());
        $deferred = self::deferredEnumRows();

        $registryEnums = [];

        foreach (KindRegistry::KINDS as $kind => $spec) {
            foreach (array_keys($spec['enums']) as $field) {
                $registryEnums[] = "{$kind}.{$field}";
            }
        }

        sort($registryEnums);
        $covered = array_merge($extracted, $deferred);
        sort($covered);

        $this->assertSame($registryEnums, array_values(array_unique($covered)), sprintf(
            'the § 6 field tables and the registry do not partition the same enum population. '
            .'extracted=%d deferred=%d registry=%d',
            count($extracted),
            count($deferred),
            count($registryEnums),
        ));

        $this->assertNotEmpty($extracted, 'the enum-member provider extracted nothing at all');
    }

    /**
     * Enum rows in § 6's field tables whose bounds cell states no members of its own, because it
     * points at another section instead.
     *
     * @return list<string>
     */
    /**
     * Walk every enum-typed row of § 6's `data` field tables once.
     *
     * Extracted at the SECOND caller rather than the Nth: `enumMemberRows()` and
     * `deferredEnumRows()` are the two halves of one partition, and two copies of the parser that
     * defines that partition is two chances for the halves to stop partitioning anything.
     *
     * @return \Generator<array{string, string, list<string>}> kind, field, members (may be empty)
     */
    private static function enumRows(): \Generator
    {
        $doc = (string) file_get_contents(self::DOC);

        preg_match_all('/^### 6\.\d+ `([a-z]+\.[a-z_]+)`\n(.*?)(?=\n### |\n## )/ms', $doc, $sections, PREG_SET_ORDER);

        foreach ($sections as [, $kind, $body]) {
            foreach (explode("\n", $body) as $line) {
                if (! str_starts_with(trim($line), '|')) {
                    continue;
                }

                // Split into cells rather than pattern-matching the whole row. The tables do not
                // all have the same columns — § 6.11's carries an extra "Source key in the
                // statusLine payload" — so a regex that assumed a column count silently skipped
                // `context.sample`'s two enums and reported a clean run over 16 of 19 fields.
                $cells = array_map(trim(...), explode('|', trim($line, "| \t")));

                if (count($cells) < 3 || ! preg_match('/^`([a-z_]+)`$/', $cells[0], $name)) {
                    continue;
                }

                $typeCell = null;

                foreach ($cells as $i => $cell) {
                    if ($cell === 'enum' || $cell === 'array\<enum\>' || $cell === 'array<enum>') {
                        $typeCell = $i;

                        break;
                    }
                }

                if ($typeCell === null) {
                    continue;
                }

                preg_match_all('/`([a-z_]+)`/', implode('|', array_slice($cells, $typeCell + 1)), $members);

                // `null` in a bounds cell is NULLABILITY, not a member — § 6.5's `agent_scope`
                // reads "`main` \| `subagent` \| `null`" and § 6.4's `api_error_type` opens with
                // "`null` unless `end_reason == "api_error"`". Treating it as a member would put
                // the string "null" into three enum sets, and the registry would then accept the
                // literal `"null"` as a valid `agent_scope`.
                yield [$kind, $name[1], array_values(array_diff(array_unique($members[1]), ['null']))];
            }
        }
    }

    public static function deferredEnumRows(): array
    {
        $deferred = [];

        foreach (self::enumRows() as [$kind, $field, $members]) {
            if ($members === []) {
                $deferred[] = "{$kind}.{$field}";
            }
        }

        return $deferred;
    }

    public function test_the_registry_knows_exactly_the_fourteen_kinds_section_6_declares(): void
    {
        $doc = $this->doc();

        preg_match_all('/^### 6\.\d+ `([a-z]+\.[a-z_]+)`$/m', $doc, $m);

        $documented = $m[1];
        sort($documented);

        $registered = array_keys(KindRegistry::KINDS);
        sort($registered);

        $this->assertSame($documented, $registered);

        // § 6's own count, stated in § 4.3: "the 14 currently-defined kinds are listed in § 6".
        $this->assertCount(14, $registered);
    }

    private function doc(): string
    {
        $this->assertFileExists(self::DOC);

        return (string) file_get_contents(self::DOC);
    }

    /**
     * @return array{?string, ?string}
     */
    private function split(string $dotted): array
    {
        $parts = explode('.', $dotted);

        if (count($parts) < 3) {
            return [null, null];
        }

        return [$parts[0].'.'.$parts[1], implode('.', array_slice($parts, 2))];
    }
}
