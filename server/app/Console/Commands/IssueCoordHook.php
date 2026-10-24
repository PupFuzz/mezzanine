<?php

namespace App\Console\Commands;

use App\Console\OutsideAnyWorktree;
use App\Coord\BindingRefused;
use App\Coord\HookBinding;
use App\Coord\HookSecret;
use Illuminate\Console\Command;

/**
 * Issue a coordination hook BINDING and its secret — or, with `--hook`, a second secret on an existing
 * one — at the OPERATOR's terminal. COORD-STORE.md § 3.2, card#12239.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SECRET NEVER REACHES A STREAM OR AN ARGV. It is written to `--secret-out`, mode 0600, and the
 * complete GitHub hook-creation body — URL, content type, the `issues` + `issue_comment` subscription,
 * the secret — to `--hook-request-out`, mode 0600, so `gh api repos/<owner>/<repo>/hooks --input <file>`
 * registers the hook without the secret ever appearing on a command line. This command PRINTS the
 * URL, the binding and the secret's prefix, and nothing secret. Both writers refuse a path inside any
 * git worktree, failing closed (`App\Console\OutsideAnyWorktree`), and refuse to overwrite a file.
 *
 * ⚠ WHAT THAT DISCIPLINE BUYS, stated narrowly (r3 MINOR-7): transcript and argv hygiene. A seat holding
 * `APP_KEY` and the database can decrypt any secret the store holds.
 *
 * ⛔ MEZZANINE HOLDS NO GITHUB CREDENTIAL FOR ANY OF THIS. Registering the hook is the operator's own
 * act, with their own credential, on another fleet's repository (COORD-STORE.md § 10 NQ1).
 */
class IssueCoordHook extends Command
{
    protected $signature = 'mezzanine:coord-hook:issue
                            {repository? : `<owner>/<repo>` — the repository the hook is registered on}
                            {--scope= : `install` (one install\'s own coordination repository) or `fleet` (the cross-install one)}
                            {--install= : the install_id an `install`-scope binding names; refused with `--scope=fleet`}
                            {--shared-identity= : `true` when every agent posts as one GitHub account, `false` otherwise}
                            {--hook= : an existing binding\'s `mzh_…` id: add a SECOND secret to it (rotation with overlap)}
                            {--by= : who issued it; defaults to the OS user}
                            {--secret-out= : absolute path, outside any git worktree, for the secret (mode 0600)}
                            {--hook-request-out= : absolute path, outside any git worktree, for the hook-creation body (mode 0600)}';

    protected $description = 'Issue a coordination hook binding and its secret, writing the secret to 0600 files and printing none of it';

    public function handle(): int
    {
        $secretOut = (string) $this->option('secret-out');
        $requestOut = (string) $this->option('hook-request-out');

        foreach (['--secret-out' => $secretOut, '--hook-request-out' => $requestOut] as $option => $path) {
            if ($why = OutsideAnyWorktree::refusal($path)) {
                $this->error("{$option}: {$why}. Nothing was issued.");

                return self::FAILURE;
            }

            if (file_exists($path)) {
                $this->error("{$option}: {$path} already exists, and this command never overwrites a file. Nothing was issued.");

                return self::FAILURE;
            }
        }

        try {
            if (is_string($this->option('hook')) && $this->option('hook') !== '') {
                $hookId = (string) $this->option('hook');

                foreach (['repository' => $this->argument('repository'), '--scope' => $this->option('scope'), '--install' => $this->option('install')] as $what => $given) {
                    if ($given !== null) {
                        $this->error("{$what} is the binding's own and is not given with --hook. Nothing was issued.");

                        return self::FAILURE;
                    }
                }

                $secret = HookBinding::addSecret($hookId);
            } else {
                $repository = (string) $this->argument('repository');
                $shared = $this->option('shared-identity');

                if (! in_array($shared, ['true', 'false'], true)) {
                    $this->error('--shared-identity must be `true` or `false` (D1 § 18.3.1). Nothing was issued.');

                    return self::FAILURE;
                }

                [$hookId, $secret] = HookBinding::issue(
                    $repository,
                    (string) $this->option('scope'),
                    $this->option('install') === null ? null : (string) $this->option('install'),
                    $shared === 'true',
                    (string) ($this->option('by') ?: (get_current_user() ?: 'unknown')),
                );
            }
        } catch (BindingRefused $e) {
            $this->error($e->getMessage().'. Nothing was issued.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/').'/api/ingest/github/'.$hookId;

        self::writePrivate($secretOut, $secret."\n");
        self::writePrivate($requestOut, json_encode([
            'name' => 'web',
            'active' => true,
            'events' => ['issues', 'issue_comment'],
            'config' => ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0', 'secret' => $secret],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->newLine();
        $this->line(sprintf('  binding     %s', $hookId));
        $this->line(sprintf('  url         %s', $url));
        $this->line(sprintf('  secret      prefix %s, written to %s (0600)', HookSecret::prefix($secret), $secretOut));
        $this->line(sprintf('  request     written to %s (0600)', $requestOut));
        $this->newLine();
        $this->warn('  Register the hook with your own credential:  gh api repos/<owner>/<repo>/hooks --input '.$requestOut);
        $this->warn('  then shred both files. For a rotation, re-key the hook on GitHub before revoking the old secret.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** Create `$path` exclusively at mode 0600 — never a wider mode, never over an existing file. */
    private static function writePrivate(string $path, #[\SensitiveParameter] string $content): void
    {
        $old = umask(0077);

        try {
            $fh = fopen($path, 'x');

            if ($fh === false) {
                throw new \RuntimeException("could not create {$path}");
            }

            fwrite($fh, $content);
            fclose($fh);
            chmod($path, 0600);
        } finally {
            umask($old);
        }
    }
}
