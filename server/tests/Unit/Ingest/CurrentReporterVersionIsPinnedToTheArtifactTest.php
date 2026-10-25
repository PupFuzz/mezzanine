<?php

namespace Tests\Unit\Ingest;

use App\Ingest\SchemaVersions;
use PHPUnit\Framework\TestCase;

/**
 * card#11818 part 2 — `SchemaVersions::CURRENT_REPORTER_VERSION` is the reporter build this server was
 * built with, so it must equal `REPORTER_VERSION` in the artifact of the same tree
 * (`docs/design/FLEET-STATE.md § 7.2`, `docs/VERSIONING.md § The reporter's own version`).
 *
 * A server whose constant lags the artifact badges every seat that applied the update as current and
 * none that skipped it; one whose constant leads it badges every seat in the fleet `update_required`.
 * `bin/reporter-version-guard.py` holds the same equality on every PR; this holds it in the suite, read
 * from the repository tree with the guard's own pattern for the artifact's one declaration line.
 */
class CurrentReporterVersionIsPinnedToTheArtifactTest extends TestCase
{
    private const ARTIFACT = __DIR__.'/../../../../fleet-reporter/fleet-reporter.js';

    /** `bin/reporter-version-guard.py`'s `VERSION_RE`, the artifact's one declaration line. */
    private const VERSION_LINE = "/^const REPORTER_VERSION = '([^']*)';$/m";

    public function test_the_server_constant_is_the_artifacts_reporter_version(): void
    {
        $artifact = $this->artifactVersion((string) file_get_contents(self::ARTIFACT));

        $this->assertSame($artifact, SchemaVersions::CURRENT_REPORTER_VERSION,
            "fleet-reporter/fleet-reporter.js declares REPORTER_VERSION '{$artifact}' and SchemaVersions declares "
            ."CURRENT_REPORTER_VERSION '".SchemaVersions::CURRENT_REPORTER_VERSION."': they move together, in the same PR");
    }

    /**
     * CONTROL: the reading counts the declaration lines, so a source with the line twice, or with none,
     * is refused by `artifactVersion()` rather than read.
     */
    public function test_the_reading_refuses_a_second_declaration_and_a_missing_one(): void
    {
        $source = (string) file_get_contents(self::ARTIFACT);
        $line = "const REPORTER_VERSION = '".$this->artifactVersion($source)."';";
        $this->assertSame(2, preg_match_all(self::VERSION_LINE, $source."\n".$line."\n"));
        $this->assertSame(0, preg_match_all(self::VERSION_LINE, str_replace($line, '', $source)));
    }

    private function artifactVersion(string $source): string
    {
        $this->assertNotSame('', $source, 'fleet-reporter/fleet-reporter.js was not read from the repository tree');
        $this->assertSame(1, preg_match_all(self::VERSION_LINE, $source, $m),
            'fleet-reporter/fleet-reporter.js does not declare REPORTER_VERSION on exactly one line');

        return $m[1][0];
    }
}
