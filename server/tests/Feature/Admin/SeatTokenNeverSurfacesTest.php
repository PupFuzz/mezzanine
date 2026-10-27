<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Tests\Feature\Support\DrivesTheSeatTokensPage;
use Tests\TestCase;

/**
 * ⛔ CANON #20 ON THE SEAT TOKENS PAGE (card#12331): the plaintext a token issue shows once must not
 * reach a log line, the session store or any `ingest_tokens` column — and a render that fails AFTER
 * the token was minted must not write a prefix of it into the log through an exception trace.
 *
 * ⚠ EVERY "ABSENT" BELOW HAS A CONTROL BESIDE IT that proves the surface was live and could have
 * carried the value — `AuthSecretsNeverSurfaceTest`'s rule: "no secret in the log" and "no log" are
 * the same bytes.
 */
class SeatTokenNeverSurfacesTest extends TestCase
{
    use DrivesTheSeatTokensPage;
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'mezzanine-seat-token-').'.log';

        config([
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);

        Log::forgetChannel();
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    private function log(): string
    {
        Log::error('canary-line-proving-this-file-is-live');

        $contents = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';

        $this->assertStringContainsString('canary-line-proving-this-file-is-live', $contents,
            'the log capture is not live, so nothing below is a measurement');

        return $contents;
    }

    public function test_an_issued_token_reaches_no_log_line(): void
    {
        $this->confirmedOperator();

        $token = $this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')->assertOk());
        $this->assertNotNull($token, 'the control: a token was shown');

        $this->assertStringNotContainsString(substr($token, 4), $this->log());
    }

    /**
     * The session arm runs on the DATABASE session driver — the one a deploy runs — because the
     * suite's default `array` driver writes no row anyone could read.
     */
    public function test_an_issued_token_is_stored_in_no_session_and_no_column_but_its_hash(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();

        // A browser sends its session cookie, so every request writes ONE row. The test client sends
        // none unless told, and each request would then write under a fresh id that the handler
        // `UPDATE`s into nothing — a store that looks empty because nothing reached it.
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());

        $this->confirmedOperator();

        $token = $this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')->assertOk());
        $this->assertNotNull($token);

        // Read RIGHT AFTER the issue POST and again after the next GET: a flash lives in the store
        // between the two and is aged out by the second, so the end state alone would hide one.
        $payloads = $this->sessionPayloads();
        $this->get(route('admin.tokens.index'))->assertOk();
        $payloads = $payloads->merge($this->sessionPayloads());
        $this->assertNotEmpty($payloads, 'the control: the database driver wrote sessions');
        $this->assertTrue($payloads->contains(fn (string $p) => str_contains($p, 'password_confirmed_at')),
            'the control: the decoded payloads are the sessions this test drove');

        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString(substr($token, 4), $payload);
        }

        $row = (array) DB::table('ingest_tokens')->first();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(substr($token, 4), (string) $value, "ingest_tokens.$column holds the token");
        }
    }

    /** @return Collection<int, string> every stored session, decoded */
    private function sessionPayloads(): Collection
    {
        return DB::table('web_sessions')->pluck('payload')->map(fn ($p) => (string) base64_decode((string) $p));
    }

    /**
     * r1 minor 4: a render that throws AFTER the insert, under `php.ini-development`'s trace settings
     * (`zend.exception_ignore_args` off, `zend.exception_string_param_max_len` 15 — the production
     * file has them on and 0), logs a trace whose string arguments are printed up to 15 bytes — and
     * `mzn_` plus 11 characters of the secret fits. The view is swapped for one that echoes the
     * plaintext and then throws, so the failure lands while the token is being rendered.
     */
    public function test_a_render_failure_after_the_insert_logs_no_prefix_of_the_token(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        $maxLen = ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');

        try {
            $views = sys_get_temp_dir().'/mezzanine-seat-token-views-'.getmypid();
            @mkdir($views.'/console/tokens', 0700, true);
            file_put_contents($views.'/console/tokens/index.blade.php',
                "@if (\$issued !== null) {{ \$issued->plaintext }} @php(throw new \\RuntimeException('forced render failure')) @endif\n"
                .'<input type="hidden" name="issue_key" value="{{ $issueKey }}">');
            View::getFinder()->prependLocation($views);
            View::getFinder()->flush();

            $this->confirmedOperator();

            $this->issueOnPage('aimla', 'aimla-pm')->assertStatus(500);

            $prefix = (string) DB::table('ingest_tokens')->value('prefix');
            $this->assertSame(12, strlen($prefix), 'the control: the insert happened before the failure');

            $log = $this->log();
            $this->assertStringContainsString('forced render failure', $log, 'the control: the failure was logged');
            // `can:operate`'s frame, `Authorize->handle(…, 'operate')`, is on every console request's
            // stack: its string argument printed proves the trace carries string arguments at all.
            $this->assertStringContainsString("Object(Closure), 'operate')", $log,
                'the control: the logged trace carries string arguments, so an absent prefix is a measurement');

            $this->assertStringNotContainsString($prefix, $log);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', (string) $maxLen);
            @unlink($views.'/console/tokens/index.blade.php');
        }
    }
}
