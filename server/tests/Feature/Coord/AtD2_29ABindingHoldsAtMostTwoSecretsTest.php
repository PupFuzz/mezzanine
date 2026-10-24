<?php

namespace Tests\Feature\Coord;

use App\Coord\HookBinding;
use Illuminate\Support\Facades\DB;

/**
 * D2 AT-D2-29 — a binding holds at most two unrevoked secrets (r1 F16), and the provisioning
 * commands' own rules (COORD-STORE.md § 3.2, § 9 step 1). card#12239.
 */
class AtD2_29ABindingHoldsAtMostTwoSecretsTest extends CoordTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/coord-cmd-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        foreach (glob($this->dir.'/repo/.git', GLOB_ONLYDIR) ?: [] as $g) {
            exec('rm -rf '.escapeshellarg($this->dir.'/repo'));
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $options */
    private function issue(array $options, string $tag)
    {
        return $this->artisan('mezzanine:coord-hook:issue', $options + [
            '--secret-out' => "{$this->dir}/{$tag}.secret",
            '--hook-request-out' => "{$this->dir}/{$tag}.request.json",
        ]);
    }

    private function secretFile(string $tag): string
    {
        return trim((string) file_get_contents("{$this->dir}/{$tag}.secret"));
    }

    public function test_green_a_second_secret_is_added_a_third_refused_and_after_a_revoke_added(): void
    {
        $this->issue(['--hook' => $this->install['hook']], 'second')->assertSuccessful();
        $this->issue(['--hook' => $this->install['hook']], 'third')
            ->expectsOutputToContain('already holds 2 unrevoked secrets')->assertFailed();
        $this->assertFileDoesNotExist("{$this->dir}/third.secret", 'a refused issue wrote a secret file');
        $this->assertSame(2, DB::table('coord_hook_secrets')->whereNull('revoked_at')->where('hook_ref', $this->hookRef())->count());

        // Verification accepts either unrevoked secret.
        $second = $this->secretFile('second');
        $this->deliver('comment', $this->install)->assertStatus(202);
        $this->deliver('comment-close', $this->install, secret: $second)->assertStatus(202);

        // Revoke the first; it is refused with 401 and the second still verifies.
        $prefix = substr($this->install['secret'], 0, 8);
        $this->artisan('mezzanine:coord-hook:revoke', ['hook' => $this->install['hook'], '--secret' => $prefix])->assertSuccessful();
        $this->deliver('comment-no-to', $this->install)->assertStatus(401);
        $this->deliver('comment-no-to', $this->install, secret: $second)->assertStatus(202);

        $this->issue(['--hook' => $this->install['hook']], 'after-revoke')->assertSuccessful();
    }

    public function test_the_secret_reaches_no_stream_and_no_column_and_the_files_are_0600(): void
    {
        $this->artisan('mezzanine:coord-hook:revoke', ['hook' => $this->fleet['hook']])->assertSuccessful();

        $command = $this->issue(['repository' => $this->spec()->repository('fleet'), '--scope' => 'fleet', '--shared-identity' => 'true'], 'fresh');
        $command->assertSuccessful();
        $command->run();

        $secret = $this->secretFile('fresh');
        $this->assertSame(43, strlen($secret), 'the secret file does not hold a 43-character base64url secret');

        // No column of the coordination store holds the plaintext.
        foreach (['coord_hooks', 'coord_hook_secrets', 'coord_deliveries'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString($secret, json_encode((array) $row, JSON_INVALID_UTF8_SUBSTITUTE), "{$table} holds the plaintext secret");
            }
        }

        foreach (['fresh.secret', 'fresh.request.json'] as $f) {
            $this->assertSame('600', substr(sprintf('%o', fileperms("{$this->dir}/{$f}")), -3), "{$f} is not mode 0600");
        }

        $request = json_decode((string) file_get_contents("{$this->dir}/fresh.request.json"), true);
        $this->assertSame(['issues', 'issue_comment'], $request['events']);
        $this->assertSame('json', $request['config']['content_type']);
        $this->assertSame($secret, $request['config']['secret']);
        $this->assertMatchesRegularExpression('#/api/ingest/github/mzh_[A-Za-z0-9_-]{22}$#', $request['config']['url']);
    }

    public function test_the_secret_is_absent_from_the_commands_output(): void
    {
        $this->artisan('mezzanine:coord-hook:revoke', ['hook' => $this->fleet['hook']])->assertSuccessful();

        $exit = \Illuminate\Support\Facades\Artisan::call('mezzanine:coord-hook:issue', [
            'repository' => $this->spec()->repository('fleet'), '--scope' => 'fleet', '--shared-identity' => 'false',
            '--secret-out' => "{$this->dir}/out.secret", '--hook-request-out' => "{$this->dir}/out.request.json",
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);
        $secret = $this->secretFile('out');
        $this->assertStringNotContainsString($secret, $output, 'the command printed the secret');
        $this->assertStringContainsString('/api/ingest/github/mzh_', $output, 'the command did not print the URL');
    }

    public function test_a_path_inside_a_git_worktree_is_refused_and_nothing_is_issued(): void
    {
        mkdir($this->dir.'/repo');
        exec('git -C '.escapeshellarg($this->dir.'/repo').' init -q 2>&1', $o, $rc);
        $this->assertSame(0, $rc, 'git init failed, so the refusal below would prove nothing');

        $before = DB::table('coord_hook_secrets')->count();
        $this->artisan('mezzanine:coord-hook:issue', ['--hook' => $this->install['hook'],
            '--secret-out' => $this->dir.'/repo/secret', '--hook-request-out' => $this->dir.'/request.json'])
            ->expectsOutputToContain('inside a git worktree')->assertFailed();

        $this->assertSame($before, DB::table('coord_hook_secrets')->count());
        $this->assertFileDoesNotExist($this->dir.'/repo/secret');
    }

    public function test_git_absent_is_refused_rather_than_read_as_outside(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');

        try {
            $this->issue(['--hook' => $this->install['hook']], 'nogit')->expectsOutputToContain('Nothing was issued')->assertFailed();
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertFileDoesNotExist("{$this->dir}/nogit.secret");
    }

    public function test_the_scope_and_install_rules(): void
    {
        $this->issue(['repository' => 'synthetic-org/another-fixture', '--scope' => 'fleet', '--install' => 'one', '--shared-identity' => 'true'], 'a')
            ->expectsOutputToContain('names no install')->assertFailed();
        $this->issue(['repository' => 'synthetic-org/another-fixture', '--scope' => 'install', '--shared-identity' => 'true'], 'b')
            ->expectsOutputToContain('needs --install')->assertFailed();
        $this->issue(['repository' => 'synthetic-org/another-fixture', '--scope' => 'fleet', '--shared-identity' => 'maybe'], 'c')
            ->expectsOutputToContain('--shared-identity must be')->assertFailed();
        $this->issue(['repository' => 'not a repository', '--scope' => 'fleet', '--shared-identity' => 'true'], 'd')
            ->expectsOutputToContain('is not `<owner>/<repo>`')->assertFailed();
    }

    private function hookRef(): int
    {
        return (int) DB::table('coord_hooks')->where('hook_id', $this->install['hook'])->value('id');
    }
}
