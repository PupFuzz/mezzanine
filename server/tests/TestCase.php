<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The store isolation guard required by docs/design/FLEET-STATE.md § 6.2.
     *
     * It runs here, in createApplication(), because that is after the container has resolved
     * configuration and before any trait has had a chance to migrate. It aborts rather than
     * skips: a suite that cannot prove which database it is about to write to must not run
     * at all, and skipping would report that decision as a pass.
     *
     * The values asserted are RESOLVED values from config(), never the declarations in
     * phpunit.xml — all three mechanisms § 6.2 records (an exported variable beating an
     * unforced <env>, force="true" missing $_SERVER, a URL's path replacing the database)
     * leave the declaration looking correct while the resolved value is something else.
     * DatabasePinTest covers the declarations separately.
     *
     * @var array<string, string>
     */
    private const PINS = [
        'database.connections.mysql.database' => 'mezzanine_test',
        'database.redis.default.database' => '11',
        'database.redis.cache.database' => '10',
    ];

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (self::PINS as $key => $expected) {
            $actual = config($key);

            if ((string) $actual !== $expected) {
                throw new RuntimeException(sprintf(
                    'Store isolation guard: config(%s) resolved to %s, expected %s. '.
                    'Aborting before any migration — see docs/design/FLEET-STATE.md § 6.2. '.
                    'The usual cause is an exported environment variable beating the pin in phpunit.xml.',
                    $key,
                    var_export($actual, true),
                    var_export($expected, true),
                ));
            }
        }

        return $app;
    }
}
