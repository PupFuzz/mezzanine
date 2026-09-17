<?php

/*
 * ⛔ TWO REFUSALS RUN HERE, BEFORE ANY TEST DOES, and they are the same shape on purpose: a named
 * message on STDERR and `exit(1)`. `phpunit.xml` names this file as its bootstrap, so this is the
 * last point at which the suite can say WHY it will not report a result. Past it the only way it
 * can speak is a test failure, and a test failure attributes the cause to whichever test happened
 * to be running — which is what card#9687 cost: a bootstrap-time diagnostic reported as a defect in
 * a class that had nothing to do with it, and a different class each run.
 *
 * The order is the order a reader needs the answers in: which tree's code is under test, and only
 * then whether this host has configured it. A credentials refusal raised about someone else's
 * `app/` would send its reader to the wrong checkout's `.env`.
 */

$loader = require __DIR__.'/../vendor/autoload.php';

$root = realpath(dirname(__DIR__));

/*
 * ⛔ 1. THE SUITE REFUSES TO RUN AGAINST AN `App\` IT DOES NOT CONTAIN (card#9499). Composer
 * computes the `App\` base from the autoloader's OWN location, and PHP resolves that location
 * through a symlink — so a `vendor` linked in from another checkout loads THAT checkout's `app/`
 * while phpunit.xml and the tests come from this one. Every result is then a statement about code
 * the tree under test does not hold, and a test "seen red first" proves nothing about the branch.
 * The base is read from Composer's own PSR-4 map rather than from one class, so any cause that
 * points `App\` elsewhere is refused, not only a symlink.
 */

// array_filter drops bases that do not exist on disk (Pint registers an unshipped
// vendor/laravel/pint/app). It is load-bearing: realpath returns false for them, and
// false.'/' is '/', which would refuse every tree, CI's included.

foreach (array_filter(array_map('realpath', $loader->getPrefixesPsr4()['App\\'])) as $base) {
    if (! str_starts_with($base.'/', $root.'/')) {
        fwrite(STDERR, sprintf(
            "Autoload guard: App\\ classes resolve to %s, outside the tree under test %s. server/vendor must be a real directory inside this tree; run composer install.\n",
            $base,
            $root,
        ));

        exit(1);
    }
}

/*
 * ⛔ 2. THE SUITE REFUSES TO RUN WITH NO `server/.env` (card#9754). It cannot run without one:
 * `phpunit.xml` pins the test DATABASE and no credentials, because credentials are per-host by
 * nature and a committed file must never carry them. So with no `.env` the store credentials fall
 * back to `config/database.php`'s `env('DB_USERNAME', 'root')` and an empty password, and the run
 * reports a cause that is TRUE AND NOT ACTIONABLE — measured on this tree at `dev` 90f9663, 612 of
 * 977 tests error with `Access denied for user 'root'@'localhost' (using password: NO)`. A first
 * contributor reads that and goes to fix MariaDB grants; the remedy is to copy `.env.example`.
 *
 * ⚠ ONE PREDICATE, THE FILE'S PRESENCE — deliberately not "unless the credentials are exported".
 * Nothing in this repository runs the suite that way: `README.md` § Running the server locally and
 * `server/composer.json`'s own `setup` script both create the file, and `php-tests.yml` copies
 * `.env.example` to `.env` before the run it judges. A branch nothing exercises is a decoration,
 * and this one would be worse than inert: `php-tests.yml` exports `DB_*` at the JOB level, so an
 * exports branch would make the no-`.env` lane pass on the strength of variables the configuration
 * under test does not have. A 12-factor runner gets its own opt-out on the day one exists.
 *
 * ⛔ AND IT IS HERE RATHER THAN IN `Tests\TestCase`, which holds the store-isolation guard. That
 * guard runs inside `createApplication()`, which is per test and AFTER the framework has booted —
 * and booting is what raises the diagnostic: `LoadEnvironmentVariables` calls phpdotenv, which
 * reads the absent file through `@file_get_contents()`, and the suppressed warning still reaches
 * PHPUnit's global error handler (card#9687's mechanism). A refusal that fires before any test
 * exists is the only one whose message is the whole output.
 */

$env = $root.'/.env';

if (! file_exists($env)) {
    fwrite(STDERR, sprintf(
        "Environment guard: %s is missing, and the suite refuses rather than run without it.\n\n".
        'That file is how this repository supplies PER-HOST credentials. It is gitignored and never committed, '.
        'phpunit.xml pins the test database but no credentials, and without it the store credentials fall back to '.
        "user 'root' with an empty password — so the run errors with an access-denied that points at MariaDB grants ".
        "instead of at this file.\n\n".
        'Remedy, in server/: cp .env.example .env && php artisan key:generate, then set DB_PASSWORD in the new file. '.
        "README.md's \"Running the server locally\" section is the one place that says which keys are yours to fill and ".
        "which databases and account to create for them; mezzanine_test is the database the suite rebuilds on every run.\n",
        $env,
    ));

    exit(1);
}
