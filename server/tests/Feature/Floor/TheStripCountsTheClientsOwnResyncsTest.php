<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-7 — a delta gap resyncs exactly one seat, the STRIP half.** `docs/design/FLOOR.md § 11`,
 * gated at Appendix B **step 8** (the protocol half is step 3's,
 * `TheClientProtocolConvergesOnAGapTest`). card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SAME FIXTURE, WITH THE STATUS STRIP RENDERED. `fx-gap` is replayed through the shipped
 * client and every record carries the shipped `floor/status-strip.js` over that client's own `feed`,
 * so *resyncs: N* is read as rendered text, never as a counter's value.
 *
 * ⛔ THE COUNT IS OF THIS CLIENT's OWN REQUESTS (§ 5.5). The GREEN counts the resync requests the
 * client actually issued — `?resync_from=` on the request list — and requires the readout to say that
 * number; the RED counts applied deltas instead, which on a healthy feed is a traffic meter that
 * never reads zero.
 */
class TheStripCountsTheClientsOwnResyncsTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** RED — the counter moves on every applied delta rather than on every resync issued. */
    private const TRAFFIC_METER = [
        ["            this.#resyncs++;\n            this.#line(`resync \${k} from \${resyncFrom}`);",
            "            this.#line(`resync \${k} from \${resyncFrom}`);"],
        ["        this.#seats.set(k, after);\n        this.#confirm(k);",
            "        this.#seats.set(k, after);\n        this.#resyncs++;\n        this.#confirm(k);"],
    ];

    public function test_green_the_readout_increments_by_exactly_the_one_resync_the_client_issued(): void
    {
        $this->assertSame([], $this->defects($this->replay('fx-gap')));
    }

    /** The discriminating control: no delta dropped, no resync, and the readout never moves. */
    public function test_control_with_no_gap_the_readout_stays_at_zero(): void
    {
        $result = $this->replay('fx-gap.no_drop');

        $this->assertSame([], $this->defects($result));
        $this->assertSame('resyncs: 0', $result['final']['strip']['resyncs']);
    }

    public function test_red_a_counter_of_applied_deltas_is_caught(): void
    {
        $defects = $this->defects($this->replay('fx-gap', $this->plantedClientWith(self::TRAFFIC_METER)));

        $this->assertArrayHasKey('count', $defects, 'the RED did not bite: '.json_encode($defects));
    }

    /** @return array<string, string> */
    private function defects(array $result): array
    {
        $defects = [];
        $first = $result['records'][0]['strip']['resyncs'];

        if ($first !== 'resyncs: 0') {
            $defects['start'] = "the readout starts at `{$first}`";
        }

        foreach ($result['records'] as $record) {
            $issued = count(array_filter($record['requests'], static fn (string $p): bool => str_contains($p, '?resync_from=')));

            if ($record['strip']['resyncs'] !== "resyncs: {$issued}") {
                $defects['count'] ??= "at {$record['at']} the strip reads `{$record['strip']['resyncs']}` after {$issued} resync request(s)";
            }
        }

        return $defects;
    }
}
