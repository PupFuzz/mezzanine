<?php

namespace Tests\Feature\Floor;

use App\Feed\FleetReload;
use Tests\TestCase;

/**
 * The stream recovery's constants, held to the documents that own them — `docs/design/FLOOR.md
 * § 2.2` and § 9, and D2 § 8.1's `feed_version`. card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A GUARD, NOT A SECOND COPY. `wire/fleet-client.js` must carry these numbers — a browser cannot
 * read this document — so each is re-derived here from the sentence that states it and compared,
 * and a control plants a drifted constant and watches the comparison red (canon: a copy a program
 * loads gets a guard).
 *
 * ⛔ `FEED_VERSION` CROSSES A REPOSITORY-INTERNAL SEAM: the client's is a JavaScript constant and the
 * server's is `App\Feed\FeedEnvelope::FEED_VERSION`. They ship in one deploy (D2 § 8.1: "the client
 * JavaScript is served by the same deploy that serves the feed"), and a client whose constant
 * disagreed with its own release would raise § 9 F8's banner on every message it ever received.
 */
class TheStreamRecoveryIsTheDocumentsTest extends TestCase
{
    use DrivesTheFleetClientModule;

    public function test_every_constant_is_the_documents(): void
    {
        $this->assertSame([], $this->defects((string) file_get_contents($this->moduleDir().'/fleet-client.js')));
    }

    public function test_each_comparison_reds_on_a_drifted_constant(): void
    {
        $source = (string) file_get_contents($this->moduleDir().'/fleet-client.js');

        foreach ([
            'FEED_VERSION' => ['export const FEED_VERSION = 1;', 'export const FEED_VERSION = 2;'],
            'SILENCE_MS' => ['export const SILENCE_MS = 45000;', 'export const SILENCE_MS = 30000;'],
            'CADENCE_MS' => ['export const CADENCE_MS = 10000;', 'export const CADENCE_MS = 3000;'],
            'BACKOFF_CEILING_MS' => ['export const BACKOFF_CEILING_MS = 80000;', 'export const BACKOFF_CEILING_MS = 160000;'],
            'RELOAD_GRACE_MS' => ['export const RELOAD_GRACE_MS = 60000;', 'export const RELOAD_GRACE_MS = 90000;'],
        ] as $name => [$anchor, $plant]) {
            $this->assertSame(1, substr_count($source, $anchor), "the control's anchor for {$name} is gone");
            $this->assertArrayHasKey($name, $this->defects(str_replace($anchor, $plant, $source)),
                "CONTROL {$name} did not bite: a drifted constant still matched");
        }
    }

    /** @return array<string, string> */
    private function defects(string $source): array
    {
        $md = $this->floorMd();
        $want = [
            'FEED_VERSION' => FleetReload::FEED_VERSION,
            'SILENCE_MS' => $this->figure('/^\| F1 \| \*\*Feed silent\*\* \| no message of any kind for \*\*(\d+) s\*\*/m', $md) * 1000,
            'CADENCE_MS' => $this->figure('/feed presumed dead: indicator, poll at (\d+) s/', $md) * 1000,
            'BACKOFF_CEILING_MS' => $this->figure('/DOUBLES on each consecutive attempt that fails the\s+same way, to a ceiling of (\d+) s/', $md) * 1000,
            'RELOAD_GRACE_MS' => $this->figure('/\*\*The reload grace is (\d+) s from the `feed\.close`/', $md) * 1000,
        ];
        $defects = [];

        foreach ($want as $name => $value) {
            if (preg_match('/export const '.$name.' = (\d+);/', $source, $m) !== 1 || (int) $m[1] !== $value) {
                $defects[$name] = "{$name} is not the document's {$value}";
            }
        }

        return $defects;
    }

    private function figure(string $pattern, string $md): int
    {
        $this->assertSame(1, preg_match($pattern, $md, $m), "a figure did not parse: {$pattern}");

        return (int) $m[1];
    }
}
