<?php

namespace Tests\Feature\Admin;

use PHPUnit\Framework\TestCase;

/**
 * ⛔ NOTHING THAT MINTS A KNOWN CREDENTIAL IS LOADABLE ON A DEPLOYED HOST — the CLASS behind the
 * seeder bug card#9070 found, closed here in its first review round.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THE ORIGINAL BUG WAS AND WHY FIXING IT WAS NOT ENOUGH. `database/seeders/DatabaseSeeder.php`
 * shipped Laravel's stock body — `User::factory()->create(['email' => 'test@example.com'])` — and
 * `Database\Factories\UserFactory::definition()` hashes the literal `password`. So
 * `php artisan db:seed` on a deployed host put an account with a publicly known password behind
 * the login page. The seeder was emptied, which closes the INSTANCE.
 *
 * The MECHANISM the change itself named — the minter sitting in composer's PRODUCTION `autoload`
 * block rather than in `autoload-dev` — was untouched, so the N+1th caller re-mints the bug for
 * free (canon #2/#5: one upstream fix, not N downstream patches). This file is that upstream fix's
 * check, and it has two arms because the fix has two halves:
 *
 *   1. the two `database/` namespaces are DEV-ONLY, so `composer install --no-dev` on a host —
 *      which `docs/PLAN.md § 5` now makes a deployment obligation — cannot load them at all;
 *   2. and no file under any namespace that IS in the production block mints a credential from a
 *      literal, which is the property arm 1 exists to serve. Arm 1 alone would pass a build that
 *      moved a `Hash::make('password')` into `app/`.
 *
 * ⚠ IT READS `composer.json` RATHER THAN THE GENERATED `vendor/composer/autoload_psr4.php`, and
 * that is deliberate: the generated map is whatever the last `dump-autoload` happened to be run
 * with (a dev dump lists autoload-dev too, by design), so asserting on it would assert on this
 * machine's last command instead of on what a production install resolves. `composer.json` is the
 * committed statement, and it is what a `--no-dev` install reads.
 *
 * ⚠ PLAIN `PHPUnit\Framework\TestCase` — no application, no database. This is a statement about
 * files in the repository, and booting a Laravel app to make it would only add ways for it to be
 * wrong.
 */
class ProductionAutoloadTest extends TestCase
{
    /** Namespaces whose whole purpose is to mint test/development data. */
    private const DEV_ONLY_NAMESPACES = ['Database\\Factories\\', 'Database\\Seeders\\'];

    /**
     * A credential minted from a LITERAL, in the spellings this application could use. A VARIABLE
     * argument is deliberately not matched: passing a password in to be hashed is what the real
     * provisioning path does all day (`App\Admin\UserProvisioning::create()`), so matching it would
     * make this arm red on correct code and be turned off by the first person it failed for.
     *
     * ⚠ WHAT IT DOES NOT CATCH, SAID PLAINLY RATHER THAN LEFT TO BE ASSUMED: a bare
     * `$someHasher->make('literal')` through a variable, or a pre-computed `$2y$…` literal pasted
     * in whole. It is narrow ON PURPOSE — a detector with false positives stops being run — and it
     * is not the only thing standing between a literal credential and a deployed host: the arm above
     * is, by keeping the whole minting namespace off a production install.
     */
    private const LITERAL_CREDENTIAL_RE = '/(?:Hash::make|bcrypt|Hash::driver\([^)]*\)->make)\(\s*[\'"]/';

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $path = dirname(__DIR__, 3).'/composer.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'composer.json did not parse — the arms below would be vacuous');

        return $decoded;
    }

    public function test_the_factory_and_seeder_namespaces_are_not_in_the_production_autoload_block(): void
    {
        $composer = $this->composerJson();

        $production = array_keys($composer['autoload']['psr-4'] ?? []);
        $dev = array_keys($composer['autoload-dev']['psr-4'] ?? []);

        // THE CONTROL — a production block that had lost `App\` would make every assertion below
        // pass for the wrong reason.
        $this->assertContains('App\\', $production, 'the production autoload block is not what this thinks it is');

        foreach (self::DEV_ONLY_NAMESPACES as $namespace) {
            $this->assertNotContains($namespace, $production, $namespace.' is loadable on a deployed host');
            $this->assertContains($namespace, $dev, $namespace.' must still resolve for the suite');
        }
    }

    /**
     * The population arm: EVERY php file under EVERY production psr-4 root, re-derived from
     * `composer.json` on each run rather than listed here, so a namespace added to that block is
     * covered without an edit to this file.
     */
    public function test_no_file_in_the_production_autoload_roots_mints_a_credential_from_a_literal(): void
    {
        $server = dirname(__DIR__, 3);
        $roots = array_values($this->composerJson()['autoload']['psr-4'] ?? []);

        $this->assertNotSame([], $roots, 'no production autoload roots were found at all');

        $files = [];

        foreach ($roots as $root) {
            $dir = $server.'/'.trim((string) $root, '/');
            $this->assertDirectoryExists($dir, 'a production autoload root that is not on disk');

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        // The denominator, printed rather than assumed: a sweep over an empty set is a pass that
        // measured nothing.
        $this->assertGreaterThan(20, count($files), 'total='.count($files).' files swept');

        $offenders = array_values(array_filter(
            $files,
            fn (string $f) => preg_match(self::LITERAL_CREDENTIAL_RE, (string) file_get_contents($f)) === 1,
        ));

        $this->assertSame([], $offenders, sprintf(
            'total=%d of %d production-autoloadable files mint a credential from a literal',
            count($offenders), count($files),
        ));
    }
}
