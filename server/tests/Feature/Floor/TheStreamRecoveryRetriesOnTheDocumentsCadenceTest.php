<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **§ 2.2 steps 7–9's cadences and § 9's strip words for why a stream ended** — the parts of Appendix
 * B row 8's **stream recovery** no acceptance test names: the BACKED-OFF cadence after
 * `feed.close{reason:"unavailable"}` (10, 20, 40, 80, and 80 thereafter, reset by the first stream
 * that opens and delivers a message), F3 `stalled`'s *reconnecting* on the 10 s cadence, and F19's
 * specific words for a stream that opened and never spoke. card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ATTEMPTS ARE READ OFF THE STREAMS THE CLIENT ACTUALLY CONSTRUCTED, and the words off the
 * strip it rendered. The cadence figures are § 2.2's own sentence; F19's words are § 9's.
 */
class TheStreamRecoveryRetriesOnTheDocumentsCadenceTest extends TestCase
{
    use DrivesTheFloorScreen;

    /** RED — the flat cadence where § 2.2 says back off: a stampede against an unreadable store. */
    private const FLAT = ['fleet-client.js',
        '        this.#backoff = Math.min(this.#backoff * 2, BACKOFF_CEILING_MS);',
        '        this.#backoff = CADENCE_MS;'];

    /** RED — the browser's own reconnect: the errored stream is left to the browser, and nothing re-runs. */
    private const BROWSER_RECONNECT = ['fleet-client.js',
        "        this.#closeStream();\n\n        // Inside a retry loop",
        "        return;\n\n        // Inside a retry loop"];

    /** RED — F19 read as F1's bare *feed down*, which sends an operator after a dead daemon. */
    private const BARE = ['fleet-client.js',
        "(stream.opened && !stream.spoke ? 'never-spoke' : 'silent')",
        "'silent'"];

    /**
     * § 2.2: "the client **closes** an errored `EventSource` and re-opens one" — it owns the
     * reconnect, and the browser's own, which re-runs none of steps 1–5, is never inherited.
     */
    public function test_green_an_errored_stream_is_closed_by_the_client_and_replaced_by_the_rerun(): void
    {
        $this->assertSame([], $this->ownedReconnectDefects(null));
    }

    public function test_red_the_browsers_own_reconnect_is_caught(): void
    {
        $this->assertArrayHasKey('closed', $this->ownedReconnectDefects($this->mutatedModules(self::BROWSER_RECONNECT)));
    }

    /** @return array<string, string> */
    private function ownedReconnectDefects(?string $dir): array
    {
        $result = $this->floorRun('drain_end', $dir);
        $end = 2000;
        $defects = [];

        if (($result['streams'][0]['closed_at'] ?? null) !== $end) {
            $defects['closed'] = 'the errored stream was left open — the browser would re-open it on its own schedule';
        }

        if (($result['streams'][1]['opened_at'] ?? null) !== $end) {
            $defects['rerun'] = 'no stream was constructed by the re-run from step 1';
        }

        return $defects;
    }

    public function test_green_unavailable_backs_off_by_doubling_to_its_ceiling_and_resets_on_a_live_stream(): void
    {
        $this->assertSame([], $this->backoffDefects(null));
    }

    public function test_red_a_flat_cadence_after_unavailable_is_caught(): void
    {
        $this->assertArrayHasKey('backoff', $this->backoffDefects($this->mutatedModules(self::FLAT)));
    }

    public function test_green_a_stream_that_opened_and_never_spoke_says_so(): void
    {
        $this->assertSame([], $this->neverSpokeDefects(null));
    }

    public function test_red_f19_rendered_as_a_bare_feed_down_is_caught(): void
    {
        $this->assertArrayHasKey('words', $this->neverSpokeDefects($this->mutatedModules(self::BARE)));
    }

    public function test_green_stalled_reads_reconnecting_and_reruns_on_the_ten_second_cadence(): void
    {
        $result = $this->floorRun('stalled');
        $close = 1000;

        $this->assertSame([0, $close + $this->cadenceMs()], array_column($result['streams'], 'opened_at'),
            'the stalled stream was not re-opened once, 10 s after the server ended it');

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] > $close && $render['at'] < 11100) {
                $this->assertSame('reconnecting', $render['frame']['strip']['feed'], "at {$render['at']}");
                $this->assertNull($render['frame']['failure']['statement'], 'a stalled stream is not a failure to state');
            }
        }

        $this->assertSame('live', $this->lastFloor($result)['strip']['feed']);
    }

    /** @return array<string, string> */
    private function backoffDefects(?string $dir): array
    {
        $result = $this->floorRun('unavailable_backoff', $dir);
        $cadence = $this->cadenceMs();
        $ceiling = $this->ceilingMs();
        $opened = array_column($result['streams'], 'opened_at');
        $defects = [];

        // The first close at 60 ms, the attempts after it doubling to the ceiling until one is
        // accepted; then the second close's first attempt one plain cadence after it.
        $want = [0];
        $at = 60;
        $gap = $cadence;

        while (count($want) < 6) {
            $at += $gap;
            $want[] = $at;
            $gap = min($gap * 2, $ceiling);
        }

        $want[] = 231000 + $cadence;

        if ($opened !== $want) {
            $defects['backoff'] = 'attempts at ['.implode(', ', $opened).'], not ['.implode(', ', $want).']';
        }

        // The statement stands from the close until a read of the store answers — the accepted
        // attempt's own snapshot, which follows its `open` (F5: "the snapshot follows").
        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] >= 60 && $render['at'] < $want[5] && $render['frame']['failure']['statement'] === null) {
                $defects['statement'] ??= "at {$render['at']} the store statement is gone while the store is still unreadable";
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function neverSpokeDefects(?string $dir): array
    {
        $result = $this->floorRun('never_spoke', $dir);
        $words = $this->documentNeverSpoke();
        $defects = [];

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] >= 45000 && $render['frame']['strip']['feed'] !== $words) {
                $defects['words'] ??= "at {$render['at']} the strip reads `{$render['frame']['strip']['feed']}`";
            }
        }

        return $defects;
    }

    private function documentNeverSpoke(): string
    {
        $this->assertSame(1, preg_match('/^\| F19 \|.*?the strip\'s words specific: \*\*([^*]+)\*\*/m', $this->floorMd(), $m),
            '§ 9 F19\'s words did not parse');

        return $m[1];
    }

    private function cadenceMs(): int
    {
        $this->assertSame(1, preg_match('/feed presumed dead: indicator, poll at (\d+) s/', $this->floorMd(), $m));

        return (int) $m[1] * 1000;
    }

    private function ceilingMs(): int
    {
        $this->assertSame(1, preg_match('/to a ceiling of (\d+) s/', $this->floorMd(), $m));

        return (int) $m[1] * 1000;
    }
}
