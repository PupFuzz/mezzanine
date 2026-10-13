<?php

namespace Tests\Feature\Compose;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * AT-C10 — the capability population (`docs/design/BOARD-TASK.md § 15.12`): who can READ the compose
 * token, and who can BUILD or CALL the write. Each population is listed by name, so an empty answer
 * would be a measurement and a new member is a red.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ (a) SELECTS READS, NOT NAME MENTIONS (design review F3). A read is the config key's dotted path or
 * the `env()` of the variable; the key's NAME may appear anywhere it is said — a refusal names it to
 * the operator — and that is not a capability.
 *
 * ⛔ THE SCAN IS A WALK OF THE TREE, NOT `git grep`, so the controls below can point the SAME matcher
 * at a planted tree and see it bite. It walks every `.php` under the named roots, tracked or not.
 *
 * ⚠ WHAT IT CANNOT SEE, said rather than implied: a read through a computed key
 * (`config('mezzanine.board.'.$k)`), or of the whole `mezzanine.board` array, matches neither pattern.
 * No such read exists today; a reviewer of a change that adds one is the check.
 */
class AtC10TheCapabilityPopulationTest extends TestCase
{
    /** (a): a read of the compose token. */
    private const READS = '/mezzanine\.board\.compose_token|env\(\s*[\'"]BOARD_COMPOSE_TOKEN/';

    /** (b): the write's path literal. */
    private const BUILDS = '/comments\.json/';

    /** (b): any reference to the compose package — `use App\Compose;` included, which is what `(\\|;)` is for. */
    private const CALLS = '/App\\\\Compose(\\\\|;)/';

    private function repo(): string
    {
        return dirname(base_path());
    }

    /**
     * Every `.php` under `$roots` (relative to `$base`) whose content matches `$pattern`, relative.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function members(string $base, array $roots, string $pattern, array $exclude = []): array
    {
        $out = [];

        foreach ($roots as $root) {
            $dir = $base.'/'.$root;

            if (! is_dir($dir)) {
                continue;
            }

            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                $rel = substr($file->getPathname(), strlen($base) + 1);

                if ($file->getExtension() !== 'php' || array_filter($exclude, fn ($x) => str_starts_with($rel, $x)) !== []) {
                    continue;
                }

                if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $out[] = $rel;
                }
            }
        }

        sort($out);

        return $out;
    }

    public function test_a_only_the_credential_class_reads_the_compose_token(): void
    {
        $this->assertSame(
            ['server/app/Compose/ComposeCredential.php', 'server/config/mezzanine.php'],
            $this->members($this->repo(), ['server/app', 'server/config', 'server/routes', 'server/bootstrap', 'server/resources', 'server/database'], self::READS),
        );
    }

    public function test_b_only_the_channel_builds_the_write_and_only_the_route_side_calls_the_package(): void
    {
        $this->assertSame(['server/app/Compose/BoardCommentChannel.php'],
            $this->members($this->repo(), ['server/app'], self::BUILDS));

        // The two callers outside the package: the route's controller, and the floor page's route,
        // which asks `ComposeCredential::refusal()` what the panel renders (§ 15.5, F3). No command,
        // job, scheduler entry, listener or provider binding.
        $this->assertSame(
            ['server/app/Http/Controllers/DeskComposeController.php', 'server/routes/web.php'],
            $this->members($this->repo(), ['server/app', 'server/routes', 'server/bootstrap'], self::CALLS, ['server/app/Compose/']),
        );
    }

    /** ⛔ THE CONTROLS — each of § 15.12's three planted REDs, planted in a scratch tree, bites. */
    public function test_the_matchers_bite_on_each_planted_defect(): void
    {
        $tree = sys_get_temp_dir().'/at-c10-'.bin2hex(random_bytes(4));
        $plant = function (string $rel, string $php) use ($tree) {
            @mkdir(dirname($tree.'/'.$rel), 0700, true);
            file_put_contents($tree.'/'.$rel, "<?php\n".$php."\n");
        };

        $plant('server/app/Board/BoardPoll.php', "\$t = config('mezzanine.board.compose_token');");
        $plant('server/app/Console/Commands/Spam.php', "\$u = \$base.'/tasks/1/comments.json';");
        $plant('server/app/Console/Commands/Uses.php', 'use App\Compose;');
        $plant('server/app/Console/Commands/Mentions.php', '// set BOARD_COMPOSE_TOKEN to enable compose');

        try {
            $this->assertSame(['server/app/Board/BoardPoll.php'], $this->members($tree, ['server/app'], self::READS),
                'CONTROL 1: a read of the compose token in the poller was not found — and a bare name mention must not be one');
            $this->assertSame(['server/app/Console/Commands/Spam.php'], $this->members($tree, ['server/app'], self::BUILDS),
                'CONTROL 2: a comments.json URL built in a command was not found');
            $this->assertSame(['server/app/Console/Commands/Uses.php'], $this->members($tree, ['server/app'], self::CALLS),
                'CONTROL 3: a bare `use App\Compose;` was not found');
        } finally {
            (new Filesystem)->deleteDirectory($tree);
        }
    }
}
