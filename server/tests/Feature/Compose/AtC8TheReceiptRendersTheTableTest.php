<?php

namespace Tests\Feature\Compose;

use App\Compose\ComposeRefused;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * AT-C8 — the compose block renders `docs/design/BOARD-TASK.md § 15.11`'s table and nothing else.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE EXPECTED SENTENCES ARE PARSED OUT OF THE DOCUMENT ON EVERY RUN, never copied here. Each
 * keyed row's italic sentence, with its `{placeholders}` substituted by the fixture's values, is what
 * the shipped client must draw for that row's input — so a sentence edited in the table reds the
 * client until it follows, and the client's own sentence map must hold exactly the table's keys.
 * The server rows' STATUS is read from the table too, so the client's class-to-status map is held to
 * the same column the server's `ComposeRefused::STATUSES` is.
 *
 * ⛔ THE PARSE HAS A FLOOR AND A HEADER CONTROL (design review F1; precedent:
 * `Tests\Feature\DrillDown\DurationFormatMatchesTheDocumentTest`). The rows must cover every class in
 * `ComposeRefused`'s closed set plus every client row this test drives, and a renamed header must make
 * the parse return NOTHING rather than "some rows" — a parse that silently read another table would
 * pass over whatever it found.
 */
class AtC8TheReceiptRendersTheTableTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const HEADER = '| Key | Condition | Class | Status | Panel sentence | Board requests |';

    private const SEAT = ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', 'protocol_agent_name' => null, 'protocol_agent_name_check' => 'undeclared'];

    private const VALUES = [
        'card_a' => 'card#9234', 'card_b' => 'card#9300', 'comment_m' => 'comment#117', 'hh_mm_ss' => '12:00:03',
        'key' => 'BOARD_COMPOSE_TOKEN', 'max_bytes' => '2000', 'task_source' => 'telemetry',
        'name_check' => 'undeclared', 'name' => 'mezzanine',
    ];

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/drilldown') ?: $this->fail('server/public/js/drilldown does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/compose-probe.mjs';
    }

    private function boardTaskMd(): string
    {
        return (string) file_get_contents(realpath(__DIR__.'/../../../../docs/design/BOARD-TASK.md')
            ?: $this->fail('docs/design/BOARD-TASK.md was not found from the test tree'));
    }

    /**
     * § 15.11's keyed rows: key ⇒ `[class, status, sentence]`, the sentence the cell's one italic span.
     *
     * @return array<string, array{string, string, string}>
     */
    private function documentRows(?string $md = null): array
    {
        $md ??= $this->boardTaskMd();
        $open = strpos($md, self::HEADER);

        if ($open === false) {
            return [];
        }

        $rows = [];
        $separator = false;

        foreach (explode("\n", substr($md, $open)) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                if ($separator) {
                    break;
                }

                continue;
            }

            if (preg_match('/^\|\s*-{3,}/', $line) === 1) {
                $separator = true;

                continue;
            }

            $cells = array_map(trim(...), explode('|', $line));

            if (count($cells) < 8 || preg_match('/^`([a-z0-9_]+)`$/', $cells[1], $key) !== 1
                || preg_match('/^\*([^*]+)\*$/', $cells[5], $sentence) !== 1) {
                continue;
            }

            $rows[$key[1]] = [trim($cells[3], '` '), trim($cells[4], '` '), $sentence[1]];
        }

        return $rows;
    }

    private function seat(string $shape): array
    {
        return match ($shape) {
            'card' => ['task' => ['title' => 'x', 'source' => 'board_card', 'ref' => 'card#9234']],
            'thread' => ['task' => ['title' => 'x', 'source' => 'telemetry', 'ref' => null], 'protocol_agent_name' => 'mezzanine', 'protocol_agent_name_check' => 'checked'],
            'none' => ['task' => ['title' => 'x', 'source' => 'telemetry', 'ref' => null]],
        } + self::SEAT;
    }

    /**
     * One case per keyed row: what the client is given, and which slot shows the row.
     *
     * @param  array<string, array{string, string, string}>  $rows
     * @return array<string, array{array<string, mixed>, string}>
     */
    private function cases(array $rows): array
    {
        $server = fn (string $key, array $body) => [
            ['seat' => $this->seat('card'), 'answer' => ['status' => (int) $rows[$key][1], 'body' => $body]], 'receipt',
        ];
        $client = fn (int $status) => [['seat' => $this->seat('card'), 'answer' => ['status' => $status, 'body' => ['message' => 'x']]], 'receipt'];

        return [
            'target_card' => [['seat' => $this->seat('card')], 'target'],
            'button' => [['seat' => $this->seat('card')], 'button'],
            'target_thread' => [['seat' => $this->seat('thread')], 'target'],
            'target_none' => [['seat' => $this->seat('none')], 'target'],
            'target_unconfigured' => [['seat' => $this->seat('card'), 'unconfigured' => ['class' => 'unconfigured', 'key' => 'BOARD_COMPOSE_TOKEN']], 'target'],
            'target_shared' => [['seat' => $this->seat('card'), 'unconfigured' => ['class' => 'credential_shared', 'key' => null]], 'target'],
            'posted' => [['seat' => $this->seat('card'), 'answer' => ['status' => (int) $rows['posted'][1], 'body' => ['ref' => 'comment#117', 'posted_at' => '2026-08-26T12:00:03.000Z']]], 'receipt'],
            'in_flight' => [['seat' => $this->seat('card'), 'answer' => ['status' => 200, 'body' => ['ref' => 'comment#117', 'posted_at' => '2026-08-26T12:00:03.000Z']]], 'in_flight'],
            'client_419' => $client(419),
            'client_401' => $client(401),
            'client_429' => $client(429),
            'client_403' => $client(403),
            'desk_gone' => [['seat' => $this->seat('card'), 'answer' => ['status' => (int) $rows['desk_gone'][1], 'body' => ['error' => 'seat_not_found']]], 'receipt'],
            'unconfigured' => $server('unconfigured', ['class' => 'unconfigured', 'key' => 'BOARD_COMPOSE_TOKEN']),
            'credential_shared' => $server('credential_shared', ['class' => 'credential_shared']),
            'text_empty' => $server('text_empty', ['class' => 'text']),
            'text_bytes' => $server('text_bytes', ['class' => 'text', 'max_bytes' => 2000]),
            'expected_ref' => $server('expected_ref', ['class' => 'expected_ref']),
            'no_target' => $server('no_target', ['class' => 'no_target', 'task_source' => 'telemetry', 'name_check' => 'undeclared']),
            'no_channel_for_agent' => $server('no_channel_for_agent', ['class' => 'no_channel_for_agent', 'ref' => 'mezzanine']),
            'target_moved_desk' => $server('target_moved_desk', ['class' => 'target_moved', 'moved' => 'desk', 'ref' => 'card#9300']),
            'target_moved_board' => $server('target_moved_board', ['class' => 'target_moved', 'moved' => 'board', 'ref' => 'card#9300']),
            'target_moved_board_no_card' => $server('target_moved_board_no_card', ['class' => 'target_moved', 'moved' => 'board']),
            'target_unread' => $server('target_unread', ['class' => 'target_unread']),
            'auth' => $server('auth', ['class' => 'auth', 'board_status' => 403]),
            'status' => $server('status', ['class' => 'status', 'board_status' => 422]),
            'transport' => $server('transport', ['class' => 'transport']),
            'unverified_lost' => $server('unverified_lost', ['class' => 'unverified']),
            'unverified_no_id' => $server('unverified_no_id', ['class' => 'unverified', 'board_status' => 201]),
            'no_response' => [['seat' => $this->seat('card'), 'answer' => 'none'], 'receipt'],
            'unrecognised' => [['seat' => $this->seat('card'), 'answer' => ['status' => 502, 'raw' => '<html>Bad Gateway</html>']], 'receipt'],
        ];
    }

    /** The row's sentence with the fixture's values in its placeholders — the doc's text, filled. */
    private function expected(string $sentence): string
    {
        $values = self::VALUES;

        if (str_contains($sentence, '{board_status}')) {
            $values['board_status'] = str_contains($sentence, 'credential') ? '403' : '422';
        }

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $values[$m[1]] ?? $m[0], $sentence);
    }

    /** @return array<string, array<string, mixed>> the probe's output per key */
    private function drive(array $cases, ?string $dir = null): array
    {
        return $this->probe(['cases' => array_map(
            fn (string $k) => ['name' => $k] + $cases[$k][0], array_keys($cases),
        )], $dir);
    }

    private function shown(array $result, string $slot): ?string
    {
        return $slot === 'in_flight' ? $result['in_flight'] : $result[$slot];
    }

    public function test_the_client_renders_every_keyed_row_of_the_table_and_no_other_text(): void
    {
        $rows = $this->documentRows();
        $cases = $this->cases($rows);

        // ⛔ THE FLOOR: every class of the closed set has a row, and every row this test drives exists.
        $classes = array_keys(ComposeRefused::STATUSES);
        $this->assertSame([], array_values(array_diff($classes, array_column($rows, 0))),
            'a ComposeRefused class has no row in § 15.11 — the parse stopped reading the table, or the table lost a class');
        $this->assertGreaterThanOrEqual(count($classes) + count(array_filter($rows, fn ($r) => $r[0] === '— (client)')), count($rows));
        $this->assertSame([], array_values(array_diff(array_keys($rows), array_keys($cases))), 'a keyed row this test does not drive');
        $this->assertSame([], array_values(array_diff(array_keys($cases), array_keys($rows))), 'a case for a row the table no longer has');

        // Every server class's row carries the status `ComposeRefused` answers it with.
        foreach ($rows as $key => [$class, $status]) {
            if (isset(ComposeRefused::STATUSES[$class])) {
                $this->assertSame((string) ComposeRefused::STATUSES[$class], $status, "{$key}: the table and ComposeRefused disagree on the status");
            }
        }

        $out = $this->drive($cases);

        $keys = $out['__sentence_keys'];
        sort($keys);
        $table = array_keys($rows);
        sort($table);
        $this->assertSame($table, $keys, 'the client carries a sentence the table does not, or lacks one it does');

        foreach ($cases as $key => [, $slot]) {
            $shown = $this->shown($out[$key], $slot);

            $this->assertSame($this->expected($rows[$key][2]), $shown, "{$key}: the client did not render § 15.11's row");
            $this->assertStringNotContainsString('{', (string) $shown, "{$key}: a placeholder was left unfilled");
            $this->assertStringNotContainsString('delivered', (string) $shown, "{$key}: the panel claimed delivery");

            if (isset($out[$key]['text_after'])) {
                $this->assertSame($key === 'posted' || $key === 'in_flight', $out[$key]['text_after'] === '',
                    "{$key}: the text box is cleared on the success row and on no other");
                $this->assertSame($out[$key]['receipt'], $out[$key]['receipt_after_tick'], "{$key}: a later render changed the receipt");
                $this->assertNotSame('', $out[$key]['text_after_tick'], "{$key}: a later render cleared the text box");
                $this->assertFalse($out[$key]['second_press_sent'], "{$key}: a press while in flight sent a second request");
            }
        }

        // The request a press sends: the one path, the label shown, no redirect followed, the CSRF token.
        $request = $out['posted']['request'];
        $this->assertSame('/desk/aimla/aimla-pm/compose', $request['path']);
        $this->assertSame(['text' => 'the message', 'expected_ref' => 'card#9234'], $request['body']);
        $this->assertSame('manual', $request['redirect']);
        $this->assertSame('csrf-probe-token', $request['headers']['X-CSRF-TOKEN']);

        // A refusal the client already knows disables the button; only a card target enables it.
        $this->assertTrue($out['target_card']['enabled']);
        foreach (['target_thread', 'target_none', 'target_unconfigured', 'target_shared'] as $key) {
            $this->assertFalse($out[$key]['enabled'], "{$key}: the button is pressable on a refusal");
            $this->assertTrue($out[$key]['text_disabled'], "{$key}: the box takes a message that cannot be posted");
        }
    }

    public function test_the_catch_all_takes_every_answer_the_client_cannot_place(): void
    {
        $rows = $this->documentRows();
        $card = $this->seat('card');
        $out = $this->probe(['cases' => [
            ['name' => 'class at the wrong status', 'seat' => $card, 'answer' => ['status' => 500, 'body' => ['class' => 'status', 'board_status' => 422]]],
            ['name' => 'unknown class', 'seat' => $card, 'answer' => ['status' => 502, 'body' => ['class' => 'gateway']]],
            ['name' => '200 without a ref', 'seat' => $card, 'answer' => ['status' => 200, 'body' => ['ok' => true]]],
            ['name' => 'post-write 500', 'seat' => $card, 'answer' => ['status' => 500, 'raw' => 'Server Error']],
            ['name' => 'signed-out redirect', 'seat' => $card, 'answer' => ['status' => 0, 'raw' => '']],
        ]]);

        foreach (['class at the wrong status', 'unknown class', '200 without a ref', 'post-write 500'] as $name) {
            $this->assertSame($this->expected($rows['unrecognised'][2]), $out[$name]['receipt'], $name);
        }

        $this->assertSame($this->expected($rows['client_401'][2]), $out['signed-out redirect']['receipt']);
    }

    /** ⛔ THE CONTROLS. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — the header bounds the parse: renamed, the parse returns nothing at all.
        $md = $this->boardTaskMd();
        $renamed = str_replace(self::HEADER, '| Key | When | Class | Status | Sentence | Requests |', $md);
        $this->assertNotSame($renamed, $md, 'CONTROL 1 planted nothing — the header has moved');
        $this->assertSame([], $this->documentRows($renamed), 'CONTROL 1 did not bite: the header was renamed and rows were still found');

        $rows = $this->documentRows();
        $cases = $this->cases($rows);

        // RED 1 (§ 15.12) — a client that writes *delivered to the agent* on success.
        $dir = $this->mutatedModules(['compose-model.js',
            "posted: 'posted as {comment_m} on {card_a} at {hh_mm_ss} — the floor does not confirm delivery',",
            "posted: 'delivered to the agent as {comment_m}',"]);
        $posted = $this->drive(['posted' => $cases['posted']], $dir)['posted']['receipt'];
        $this->assertNotSame($this->expected($rows['posted'][2]), $posted, 'RED 1 did not bite: a *delivered* receipt passed');

        // RED 2 — a client that writes *the message was not posted* on an `unverified` class.
        $dir = $this->mutatedModules(['compose-model.js',
            "return body.board_status === undefined || body.board_status === null ? row('unverified_lost') : row('unverified_no_id');",
            "return row('transport');"]);
        $lost = $this->drive(['unverified_lost' => $cases['unverified_lost']], $dir)['unverified_lost']['receipt'];
        $this->assertStringContainsString('the message was not posted', $lost, 'RED 2 planted nothing');
        $this->assertNotSame($this->expected($rows['unverified_lost'][2]), $lost, 'RED 2 did not bite: *not posted* passed for a write that may have landed');
    }
}
