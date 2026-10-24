<?php

namespace Tests\Feature\Coordination;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig every coordination test shares — the COORDINATION half of it. The generic half (run
 * the shipped modules under `node`, run a mutated copy, assert the anchor exists exactly once)
 * is `Tests\Feature\Support\DrivesAShippedClientModule` and is not restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE MEMBER PARSE READS D2, NOT D3, AND THAT IS THE POINT. `docs/design/FLOOR.md § 5.7`
 * names which members are RENDERED; `docs/design/FLEET-STATE.md § 8.3.3` declares which members
 * EXIST. The client's arrays are a copy of the second, so the second is what they are compared
 * against — comparing them to D3 would let a member D2 publishes and D3 forgot to render slip
 * past both documents and the client at once.
 *
 * ⛔ § 8.3.3 DECLARES TWO OBJECTS UNDER ONE HEADING, so this parser walks EVERY table in the
 * section and keys each by the object its rows name. Reading the first table only would publish
 * half a surface and report clean over the other half — the exact under-read
 * `tools/design/verify-floor.py` grew `table_rows_all` to prevent, arriving here through a
 * different reader.
 */
trait DrivesTheCoordClient
{
    use DrivesAShippedClientModule;

    /** § 8.3.3's own bounds. */
    private const S833_OPEN = '#### 8.3.3 The coordination objects';

    private const S833_CLOSE = '### 8.4 Snapshot-then-deltas';

    /** The shipped client modules — the ones the browser is served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/coord')
            ?: $this->fail('server/public/js/coord does not exist — the client this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/coord-probe.mjs';
    }

    /**
     * D2 § 8.3.3's members, keyed by object prefix, each in that object's own table order.
     *
     * ⛔ `strpos` RESULTS ARE CHECKED BEFORE THEY ARE USED AS BOUNDS. A renamed heading answers
     * `false`, and `false` in arithmetic is `0` — which silently hands the parse the whole
     * document from its start and then finds "some field rows in there".
     * `CoordMemberSetMatchesTheDocumentTest`'s CONTROL 1 plants that rename.
     *
     * `coord_thread` is still parsed: D2 keeps its table as a specified-and-not-published object, and a
     * parse that skipped it could not tell its rows from `coord_round`'s.
     *
     * @return array{coord_thread: list<string>, coord_round: list<string>}
     */
    protected function documentMembers(?string $md = null): array
    {
        $out = ['coord_thread' => [], 'coord_round' => []];
        $md ??= $this->fleetStateMd();
        $open = strpos($md, self::S833_OPEN);

        if ($open === false) {
            return $out;
        }

        $close = strpos($md, self::S833_CLOSE, $open + strlen(self::S833_OPEN));

        if ($close === false) {
            return $out;
        }

        foreach (explode("\n", substr($md, $open, $close - $open)) as $line) {
            // The first cell is `<object>.<member>` in backticks. Rows of any other shape —
            // the prose, the not-published table, the worked JSON — do not match and are
            // skipped, so the walk needs no separator bookkeeping and no contiguity assumption.
            if (preg_match('/^\|\s*`(coord_thread|coord_round)\.([a-z_]+)`\s*\|/', $line, $m) !== 1) {
                continue;
            }

            $out[$m[1]][] = $m[2];
        }

        return $out;
    }

    /**
     * The top-level member names of every worked JSON example in D2 § 8.3.3 that carries `$object`,
     * in order — an INDEPENDENT reading of the object's member set (the examples are prose, the table
     * is the declaration), so the table parse is checked against the document rather than against a
     * figure written here.
     *
     * @return list<list<string>>
     */
    protected function workedExampleMembers(string $object, ?string $md = null): array
    {
        $md ??= $this->fleetStateMd();
        $open = strpos($md, self::S833_OPEN);
        $close = $open === false ? false : strpos($md, self::S833_CLOSE, $open);

        if ($open === false || $close === false) {
            return [];
        }

        preg_match_all('/```json\n(.*?)```/s', substr($md, $open, $close - $open), $blocks);
        $out = [];

        foreach ($blocks[1] as $block) {
            $decoded = json_decode($block, true);

            if (is_array($decoded) && is_array($decoded[$object] ?? null)) {
                $out[] = array_keys($decoded[$object]);
            }
        }

        return $out;
    }

    /**
     * The worked `coord.thread` of D2 § 8.3.3, transcribed field for field — a `closed`
     * delivery that names nobody. Since card#12239 the type is specified and NOT published (D2 § 8.3),
     * so the one use left for it is to show a client that is handed one draws nothing for it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function threadMessage(array $overrides = []): array
    {
        return [
            't' => 'coord.thread',
            'server_time' => '2026-08-27T16:02:11.802Z',
            'coord_thread' => array_merge([
                'thread_ref' => 'AIMLA-org/aimla-coordination#742',
                'scope' => 'install',
                'install_id' => 'aimla',
                'lifecycle' => 'closed',
                'carrier' => 'announce',
                'subject' => 'the coordination-event producer',
                'subject_truncated' => false,
                'opened_by' => null,
                'attribution' => 'unattributable',
                'participants' => ['pm', 'all'],
                'posted_at' => null,
                'received_at' => '2026-08-27T16:02:11.775Z',
            ], $overrides),
        ];
    }

    /**
     * The worked `coord.round` of D2 § 8.3.3, transcribed field for field — except `targets`, which
     * this rig gives three named members so a fan-out has something to resolve (the worked post is a
     * `to:all`, whose `targets` is `null`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function roundMessage(array $overrides = []): array
    {
        return [
            't' => 'coord.round',
            'server_time' => '2026-08-27T09:14:03.501Z',
            'coord_round' => array_merge([
                'post_ref' => 'AIMLA-org/aimla-coordination#742',
                'thread_ref' => 'AIMLA-org/aimla-coordination#742',
                'scope' => 'install',
                'install_id' => 'aimla',
                'from' => 'pm',
                'attribution' => 'resolved',
                'to' => ['all'],
                'targets' => ['magento', 'platform', 'moodle'],
                'carrier' => 'announce',
                'declares_close' => false,
                'posted_at' => '2026-08-27T09:14:02.000Z',
                'received_at' => '2026-08-27T09:14:03.418Z',
            ], $overrides),
        ];
    }

    /**
     * § 5.7's render map, as the set of D2 members its D2-field column names.
     *
     * @return list<string>
     */
    protected function renderedMembers(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, '| Rendered element | D2 field | Example | Null / unresolved render |');

        if ($open === false) {
            return [];
        }

        $members = [];

        foreach (explode("\n", substr($md, $open)) as $i => $line) {
            if ($i > 1 && ! str_starts_with($line, '|')) {
                break;
            }

            $cells = explode('|', $line);

            if (count($cells) < 3) {
                continue;
            }

            preg_match_all('/`(coord_(?:thread|round)\.[a-z_]+)`/', $cells[2], $m);

            foreach ($m[1] as $token) {
                $members[] = $token;
            }
        }

        return array_values(array_unique($members));
    }
}
