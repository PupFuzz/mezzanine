<?php

/*
 * ⛔ THE SUITE REFUSES TO RUN AGAINST AN `App\` IT DOES NOT CONTAIN (card#9499). Composer computes
 * the `App\` base from the autoloader's OWN location, and PHP resolves that location through a
 * symlink — so a `vendor` linked in from another checkout loads THAT checkout's `app/` while
 * phpunit.xml and the tests come from this one. Every result is then a statement about code the
 * tree under test does not hold, and a test "seen red first" proves nothing about the branch. The
 * base is read from Composer's own PSR-4 map rather than from one class, so any cause that points
 * `App\` elsewhere is refused, not only a symlink.
 */

$loader = require __DIR__.'/../vendor/autoload.php';

$root = realpath(dirname(__DIR__));

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
