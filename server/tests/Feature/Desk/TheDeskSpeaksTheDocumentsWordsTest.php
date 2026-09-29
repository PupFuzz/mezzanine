<?php

namespace Tests\Feature\Desk;

use Tests\TestCase;

/**
 * ⛔ THE DRIFT GUARD FOR EVERY PUBLISHED STRING THE DESK RENDER RESTATES — `public/js/desk/
 * desk-render.js`'s copies of `docs/design/FLOOR.md` § 7.1's state sentences and its seven
 * `unknown_reason` sentences, § 7.6's twelve `api_error_type` phrases, § 7.1's Desk column, and
 * the single words § 7.2, § 7.3 and § 5.6 fix — each RE-DERIVED from the document on every run.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE COPIES EXIST AT ALL: a browser cannot read FLOOR.md, so the desk carries the words, and
 * the rule for a restatement a consumer cannot follow a pointer to is DELETE it or GUARD it. This
 * is the guard. It compares the module against the document, never against a hand-written third
 * list that would be free to agree with the module while D3 says something else.
 *
 * ⛔ § 7.1's LABEL CELLS ARE WORKED INSTANCES, SO WHAT IS HELD IS THEIR FIXED WORDS. § 7.1's own
 * convention says a cell is "a *rendering* of a rule this table does not own", with an age or a
 * timestamp spliced in; the module holds each cell's stem and the guard requires the cell's first
 * italic span to BEGIN with it — and, where the cell completes its sentence with a value, to
 * continue with the separator or the timestamp the module splices after it.
 *
 * ⚠ WHAT THIS DOES NOT CHECK: that a desk DRAWS these words in the right place, which is
 * AT-D3-5's and AT-D3-14's (`Tests\Feature\Floor`). This is the words; those are the desks.
 */
class TheDeskSpeaksTheDocumentsWordsTest extends TestCase
{
    use DrivesTheDeskClient;

    /**
     * The two § 7.1 members with no Label-line stem in the module, each for a reason the document
     * states in its own cell: `unknown`'s cell is a pointer to the seven-sentence table below it,
     * and `retired` has no desk to put a label on (§ 7.1: "none on the floor").
     */
    private const NO_STEM = ['unknown', 'retired'];

    public function test_every_word_the_desk_restates_is_the_documents(): void
    {
        $this->assertSame([], $this->wordDefects($this->probe([])['words'], $this->floorMd()));
    }

    /** ⛔ THE CONTROLS — the parsers read a real population, and each direction reds when broken. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $md = $this->floorMd();

        // CONTROL 1 — the populations are the document's own counts: ten members, seven reasons,
        // twelve error members, each stated in its section's own words.
        $this->assertCount(10, $this->labelCells($md), '§ 7.1\'s table did not parse to its ten members');
        $this->assertCount(7, $this->italicTable($md, '**The seven `unknown_reason` members', '### 7.2'),
            '§ 7.1\'s unknown_reason table did not parse to its seven members');
        $this->assertCount(12, $this->italicTable($md, '| `api_error_type` | The line beside the raw value |', 'The last two rows are one distinction'),
            '§ 7.6\'s api_error_type table did not parse to its twelve members');

        // CONTROL 2 — a module phrase edited away from the document reds.
        $edited = $this->probe([], $this->mutatedModules(['desk-render.js', "rate_limit: 'rate limit',", "rate_limit: 'rate-limit',"]))['words'];

        $this->assertNotSame([], $this->wordDefects($edited, $md), 'CONTROL 2 did not bite: a phrase drifted from § 7.6 and the guard passed');

        // CONTROL 3 — a state sentence edited away from the document reds.
        $label = $this->probe([], $this->mutatedModules(['desk-render.js', "catching_up: 'replaying history',", "catching_up: 'replaying',"]))['words'];

        $this->assertNotSame([], $this->wordDefects($label, $md), 'CONTROL 3 did not bite: a state sentence drifted from § 7.1 and the guard passed');

        // CONTROL 4 — a document that gained a reason the module does not carry reds (direction two).
        $gained = str_replace('| `no_data_yet` | *no data yet — this seat has never reported* |',
            "| `no_data_yet` | *no data yet — this seat has never reported* |\n| `turn_lost` | *the turn was lost* |", $md);

        $this->assertNotSame($md, $gained, 'CONTROL 4 planted nothing — its anchor is gone from § 7.1');
        $this->assertNotSame([], $this->wordDefects($this->probe([])['words'], $gained),
            'CONTROL 4 did not bite: § 7.1 gained an unknown_reason and the guard passed');

        // CONTROL 5 — a dark desk drawn as the sleeper in the module reds against the Desk column.
        $sleeper = $this->probe([], $this->mutatedModules(['desk-render.js',
            "stale: { pose: 'empty-chair',", "stale: { pose: 'asleep',"]))['words'];

        $this->assertNotSame([], $this->wordDefects($sleeper, $md), 'CONTROL 5 did not bite: the stale desk was drawn asleep and the guard passed');
    }

    /**
     * Every disagreement between the module's words and the document's.
     *
     * @param  array<string, mixed>  $words  the probe's `words`
     * @return list<string>
     */
    private function wordDefects(array $words, string $md): array
    {
        $d = [];
        $cells = $this->labelCells($md);
        $stems = array_values(array_diff(array_keys($cells), self::NO_STEM));

        // § 7.1's Label line: both directions over the members, then each stem against its cell.
        if (array_diff($stems, array_keys($words['label'])) !== [] || array_diff(array_keys($words['label']), $stems) !== []) {
            $d[] = 'the module\'s state sentences cover '.json_encode(array_keys($words['label'])).', § 7.1 has '.json_encode($stems);
        }

        foreach ($words['label'] as $member => $stem) {
            $span = $this->firstItalic($cells[$member] ?? '');

            if ($span === null || ! str_starts_with($span, $stem)) {
                $d[] = "§ 7.1's {$member} cell reads *{$span}*, which does not begin with the module's `{$stem}`";

                continue;
            }

            $rest = substr($span, strlen($stem));

            // What may follow a stem is what the module splices after one: § 7.6's dash before a
            // value, or a timestamp. Anything else means the stem is a truncation of the sentence.
            if ($rest !== '' && ! str_starts_with($rest, $words['dash']) && preg_match('/^ \d{2}:\d{2}/', $rest) !== 1) {
                $d[] = "§ 7.1's {$member} cell continues `{$rest}` after the stem, which the module does not splice";
            }
        }

        if (! str_contains($cells['offline'] ?? '', '***'.$words['no_data_yet'].'***')) {
            $d[] = "§ 7.1's offline cell does not read ***{$words['no_data_yet']}*** for the never-reported seat";
        }

        // § 7.1's Desk column: every member but `retired` has a picture, and the empty chair is
        // exactly the characterless desks the column names.
        $members = array_keys($cells);
        $pictured = array_merge(array_keys($words['desk']), ['retired']);

        if (array_diff($members, $pictured) !== [] || array_diff($pictured, $members) !== []) {
            $d[] = 'the module pictures '.json_encode(array_keys($words['desk'])).', § 7.1\'s Desk column has '.json_encode($members);
        }

        $empty = array_keys(array_filter($words['desk'], static fn (array $p): bool => $p['pose'] === 'empty-chair'));
        $documented = array_values(array_diff($this->documentNoCharacterStates($md), ['retired']));

        if ($empty !== $documented) {
            $d[] = 'the module draws the empty chair for '.json_encode($empty).', § 7.1\'s Desk column for '.json_encode($documented);
        }

        // § 7.1's seven reasons and § 7.6's twelve phrases: the whole table, key and words.
        foreach ([
            'unknown_reason' => ['**The seven `unknown_reason` members', '### 7.2'],
            'api_error_phrase' => ['| `api_error_type` | The line beside the raw value |', 'The last two rows are one distinction'],
        ] as $set => [$open, $close]) {
            $document = $this->italicTable($md, $open, $close);

            if ($document !== $words[$set]) {
                $d[] = "the module's {$set} is ".json_encode($words[$set]).', the document\'s is '.json_encode($document);
            }
        }

        // The single words: § 7.3's `config_invalid` row and § 7.2's cluster line.
        foreach ([$words['sending_nothing'], $words['oldest_badge_since']] as $word) {
            if (! str_contains($md, '*'.$word)) {
                $d[] = "the document nowhere renders *{$word}…*";
            }
        }

        return $d;
    }

    /**
     * § 7.1's ten rows: member → its Label line cell.
     *
     * @return array<string, string>
     */
    private function labelCells(string $md): array
    {
        $open = strpos($md, '| `render_state` | Desk | Label line | Animation | Never |');
        $close = strpos($md, '**The seven `unknown_reason` members');

        if ($open === false || $close === false || $close < $open) {
            return [];
        }

        $out = [];

        foreach (explode("\n", substr($md, $open, $close - $open)) as $line) {
            $cells = explode(' | ', trim($line, " |\t"));

            if (count($cells) === 5 && preg_match('/^`([a-z_]+)`$/', $cells[0], $m) === 1 && $m[1] !== 'render_state') {
                $out[$m[1]] = $cells[2];
            }
        }

        return $out;
    }

    /**
     * A `| `member` | *words* … |` table between two anchors: member → its first italic span, with
     * the code ticks a rendered sentence does not draw removed.
     *
     * @return array<string, string>
     */
    private function italicTable(string $md, string $open, string $close): array
    {
        $from = strpos($md, $open);
        $to = $from === false ? false : strpos($md, $close, $from);

        if ($from === false || $to === false) {
            return [];
        }

        preg_match_all('/^\| `([a-z_]+)` \| (.+) \|$/m', substr($md, $from, $to - $from), $rows, PREG_SET_ORDER);

        $out = [];

        foreach ($rows as [, $member, $cell]) {
            $span = $this->firstItalic($cell);

            if ($span !== null) {
                $out[$member] = str_replace('`', '', $span);
            }
        }

        return $out;
    }

    /** The first single-asterisk italic span of a cell, or `null`. */
    private function firstItalic(string $cell): ?string
    {
        return preg_match('/(?<!\*)\*([^*]+)\*(?!\*)/', $cell, $m) === 1 ? $m[1] : null;
    }
}
