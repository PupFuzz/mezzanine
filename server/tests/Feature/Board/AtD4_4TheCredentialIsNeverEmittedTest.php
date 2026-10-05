<?php

namespace Tests\Feature\Board;

use App\Board\BoardPollFailed;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AT-D4-4 — the credential is never emitted (`docs/design/BOARD-TASK.md § 11`, § 5.1).
 *
 * Every § 9 failure path runs at maximum verbosity, capturing what the command printed (stdout and
 * its error lines, which the buffered output receives both of), every application log record —
 * message AND context, serialized — and the command's argv. The token value must occur ZERO times
 * in all of them, and each failure's log line must carry a class, a status where there was one,
 * and a URL with no userinfo.
 *
 * ⛔ THE BOARD ECHOES THE TOKEN IN ITS ERROR BODIES (`arrangeDegraded()`), the way § 5.1 warns a
 * board may — so a poller that logged a response body would be caught here, not merely one that
 * logged a header.
 *
 * ⚠ AND THE CHECK HAS SEEN THE STRING PRESENT. § 11 makes that a requirement: "move the token into
 * the URL query string. The check must find it in the logged URL." That planted defect was run
 * against this file and went red (the PR records it); `test_the_check_finds_a_token_that_is_present`
 * keeps a standing half of it — the same search, over the same captures, finding a planted token.
 */
class AtD4_4TheCredentialIsNeverEmittedTest extends BoardTaskTestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });
    }

    /** What § 9 says each degraded path is reported as. */
    private const EXPECTED_CLASS = [
        'connection refused' => BoardPollFailed::TRANSPORT,
        '401' => BoardPollFailed::AUTH,
        '403' => BoardPollFailed::AUTH,
        '500 on page 2 of 2' => BoardPollFailed::STATUS,
        'no meta.last_page (§ 6.5)' => BoardPollFailed::PAGINATION,
        'string id (§ 6.5)' => BoardPollFailed::SHAPE,
        'data is an object' => BoardPollFailed::SHAPE,
        'more rows than the page size' => BoardPollFailed::SHAPE,
        'page cap' => BoardPollFailed::PAGINATION,
        'redirect' => BoardPollFailed::STATUS,
        'assignee is a string' => BoardPollFailed::SHAPE,
        'assigned card missing updated_at' => BoardPollFailed::SHAPE,
        'assigned card with an impossible updated_at' => BoardPollFailed::SHAPE,
        'credential missing' => BoardPollFailed::CREDENTIAL,
        'credential malformed' => BoardPollFailed::CREDENTIAL,
        'plain-http base' => BoardPollFailed::CONFIG,
        'malformed BOARD_IDS' => BoardPollFailed::CONFIG,
        'store refuses the write' => BoardPollFailed::STORE,
    ];

    /** @return list<string> every capture this test searches, one string each */
    private function captures(string $printed): array
    {
        return [
            'output' => $printed,
            'log' => implode("\n", $this->logged),
            'argv' => json_encode($this->argv, JSON_UNESCAPED_SLASHES),
        ];
    }

    private function assertTokenAbsent(string $printed, string $scenario): void
    {
        foreach ($this->captures($printed) as $where => $text) {
            $this->assertSame(0, substr_count($text, self::TOKEN), sprintf('%s: the token reached the %s', $scenario, $where));
        }
    }

    #[DataProvider('degradedReads')]
    public function test_no_failure_path_emits_the_token(string $scenario): void
    {
        $this->map();
        $this->arrangeDegraded($scenario);

        [$code, $printed] = $this->poll(['-vvv' => true]);

        $this->assertNotSame(0, $code);
        $this->assertTokenAbsent($printed, $scenario);

        // The log line names the class — and the class is the one § 9 gives this path, which is
        // what makes it a diagnosis rather than a line saying something went wrong.
        $line = collect($this->logged)->first(fn (string $l) => str_contains($l, 'poll degraded'));
        $this->assertNotNull($line, $scenario.': no log line for a degraded poll');
        $this->assertStringContainsString('"class":"'.self::EXPECTED_CLASS[$scenario].'"', $line, $scenario);
        $this->assertStringNotContainsString('denied', $line, $scenario.': a response body reached the log');

        // § 5.1: the command's own output names the board id and the class, nothing else.
        $this->assertStringContainsString('class='.self::EXPECTED_CLASS[$scenario], $printed);
        $this->assertStringNotContainsString('http', $printed, $scenario.': the command printed a URL');
    }

    public function test_a_logged_url_has_its_userinfo_redacted(): void
    {
        $this->map();
        $this->configure('https://reader:'.self::TOKEN.'@board.example.test/api/v3', self::TOKEN, (string) self::BOARD);
        $this->board([self::BOARD => [1 => [500, 'x']]]);

        [$code, $printed] = $this->poll(['-vvv' => true]);

        $this->assertNotSame(0, $code);
        $this->assertTokenAbsent($printed, 'userinfo');

        $line = collect($this->logged)->first(fn (string $l) => str_contains($l, 'poll degraded'));
        $this->assertStringContainsString('https://[redacted]@board.example.test/api/v3/tasks/search.json?q=board_id%3D14', $line);
        $this->assertStringContainsString('"status":500', $line);
    }

    public function test_the_check_finds_a_token_that_is_present(): void
    {
        // The standing control: the same captures and the same search, over a log that DOES carry
        // the token. A search that cannot find the string when it is there is a decoration.
        $this->logged[] = 'warning mezzanine.board: poll degraded {"url":"https://board.example.test/api/v3/tasks/search.json?token='.self::TOKEN.'"}';

        $found = array_sum(array_map(fn (string $t) => substr_count($t, self::TOKEN), $this->captures('')));

        $this->assertSame(1, $found);
    }

    public function test_the_token_has_no_command_line_route_in(): void
    {
        // argv is one of § 11's four captures, and the strongest form of its assertion is that no
        // option of either command can carry the value at all — it is read from configuration.
        foreach (['mezzanine:board-poll', 'mezzanine:seat-board-user'] as $name) {
            $options = array_keys(Artisan::all()[$name]->getDefinition()->getOptions());

            $this->assertSame([], array_values(array_filter($options, fn (string $o) => str_contains($o, 'token'))), $name);
        }
    }
}
