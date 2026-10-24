<?php

namespace App\Console;

use Symfony\Component\Process\Process;

/**
 * A file a credential is written to must lie OUTSIDE every git worktree — COORD-STORE.md § 3.2's rule
 * for `mezzanine:coord-hook:issue --secret-out` / `--hook-request-out`, and the same rule
 * `tools/coord/capture-delivery.py` applies to a capture (card#12239).
 *
 * ⛔ FAIL CLOSED. The path's directory is accepted ONLY when `git -C <dir> rev-parse
 * --is-inside-work-tree` exits 128 saying it is not a git repository. Exit 0 (inside a worktree), any
 * other error, an unreadable directory, or `git` missing all REFUSE: a check that cannot run must not
 * read as "outside".
 */
final class OutsideAnyWorktree
{
    /** @return string|null null when `$path` may be written, otherwise why not */
    public static function refusal(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            return sprintf('%s is not an absolute path', json_encode($path));
        }

        $dir = dirname($path);

        if (! is_dir($dir)) {
            return sprintf('the directory %s does not exist', $dir);
        }

        try {
            $git = new Process(['git', '-C', $dir, 'rev-parse', '--is-inside-work-tree']);
            $git->run();
        } catch (\Throwable $e) {
            return 'git could not be run, so whether the path is inside a worktree is unknown';
        }

        if ($git->getExitCode() === 128 && str_contains($git->getErrorOutput(), 'not a git repository')) {
            return null;
        }

        return $git->getExitCode() === 0
            ? sprintf('%s is inside a git worktree, and a secret written there is one `git add` from a commit', $dir)
            : sprintf('git did not confirm %s is outside every worktree (exit %s)', $dir, var_export($git->getExitCode(), true));
    }
}
