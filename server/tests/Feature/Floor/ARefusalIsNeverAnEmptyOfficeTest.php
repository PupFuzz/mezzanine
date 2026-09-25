<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-8 — a refusal is never an empty office.** `docs/design/FLOOR.md § 11`, gated at Appendix B
 * **step 8**, and one of the three hard requirements before anything downstream may treat the floor
 * as honest. card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `fx-refusals`, EACH RESPONSE IN ITS OWN RUN, COLD AND WARM. Every run is the floor screen over
 * the shipped client protocol WITH its stream recovery (`recovery: true`), and every assertion reads
 * what the screen drew — its failure renders, its status strip and its desks — plus the streams the
 * client opened and closed and the requests it issued.
 *
 * ⛔ THE WORDS ARE THE DOCUMENT's. F4's statement, *last known good*, F8's banner and F1's strip
 * words are read out of § 9's own cells; the reload grace and the cadence out of § 2.2's.
 *
 * ⛔ EVERY GREEN IS A FUNCTION RETURNING ITS DEFECTS, and each RED runs the same function over a
 * planted tree (or, for the Third RED, the planted FIXTURE the RED names) and requires it to fail
 * on the clause the RED names.
 */
class ARefusalIsNeverAnEmptyOfficeTest extends TestCase
{
    use DrivesTheFloorScreen;

    /** First RED — a `503` rendered as a floor with no statement: an empty office. */
    private const NO_STATEMENT = ['failure-render.js', '    if (feed.store !== null) {', '    if (false) {'];

    /** Second RED — the floor keeps animating behind the sign-in prompt. */
    private const ANIMATES_BEHIND = ['../desk/desk-render.js', ' && facts.stilled !== true;', ';'];

    /** Fourth RED — F8's banner on every deploy, whatever `feed_version` it carries. */
    private const BANNER_EVERY_DEPLOY = ['fleet-client.js',
        'if (envelope.feed_version !== FEED_VERSION) {',
        "if (envelope.feed_version !== FEED_VERSION || envelope.t === 'fleet.reload') {"];

    /** Fifth RED — a grace with no end. */
    private const ENDLESS_GRACE = ['fleet-client.js',
        'this.#graceTimer = this.#after(RELOAD_GRACE_MS, () => this.#graceEnded());',
        'this.#graceTimer = null;'];

    /** Sixth RED — the drain's end (no `feed.close`) given the reload grace. */
    private const DRAIN_GRACED = ['fleet-client.js',
        "        this.#line(stream.opened ? 'the stream ended without a reason — feed presumed dead' : 'the stream could not be opened');\n        this.#presumeDead();",
        "        this.#mode = 'grace';\n        this.#schedule(CADENCE_MS);"];

    // ── GREEN ────────────────────────────────────────────────────────────────────────────────

    public function test_green_a_503_renders_the_store_statement_over_the_floor_kept_or_in_words(): void
    {
        $this->assertSame([], $this->storeDefects('refusal_503_cold', false));
        $this->assertSame([], $this->storeDefects('refusal_503_warm', true));
    }

    public function test_green_a_401_renders_the_sign_in_over_a_dimmed_still_floor_and_closes_the_stream(): void
    {
        $this->assertSame([], $this->signInDefects('refusal_401_cold', false));
        $this->assertSame([], $this->signInDefects('refusal_401_warm', true));
    }

    public function test_green_db_down_renders_the_store_statement_and_the_stream_ends(): void
    {
        $this->assertSame([], $this->dbDownDefects('db_down_cold', false));
        $this->assertSame([], $this->dbDownDefects('db_down_warm', true));
    }

    public function test_green_an_unknown_feed_version_raises_the_banner_stops_deltas_and_reopens_nothing(): void
    {
        $this->assertSame([], $this->unknownVersionDefects('reload_unknown'));
    }

    public function test_green_a_known_feed_version_renders_nothing_and_reopens_on_the_cadence_inside_the_grace(): void
    {
        $this->assertSame([], $this->graceDefects('reload_known_8s'));
        $this->assertSame([], $this->graceDefects('reload_known_42s'));
    }

    public function test_green_the_grace_ends_sixty_seconds_after_the_close_in_step_7s_render(): void
    {
        $this->assertSame([], $this->graceEndDefects('reload_never'));
    }

    public function test_green_a_stream_ending_with_no_reason_takes_f1_at_once(): void
    {
        $this->assertSame([], $this->drainDefects('drain_end'));
    }

    /** The discriminating control: a `200` renders the floor, and nothing above fires on it. */
    public function test_control_a_200_renders_the_floor_normally(): void
    {
        $frame = $this->lastFloor($this->floorRun('refusal_control'));

        $this->assertNull($frame['failure']['statement']);
        $this->assertNull($frame['failure']['kept']);
        $this->assertNull($frame['failure']['sign_in']);
        $this->assertNull($frame['failure']['banner']);
        $this->assertSame('live', $frame['strip']['feed']);
        $this->assertSame('connected', $frame['strip']['connection']);
        $this->assertCount(count($this->snapshotSeats('refusal_control')), $frame['desks']['desks']);
    }

    // ── RED ──────────────────────────────────────────────────────────────────────────────────

    public function test_red_a_503_drawn_as_an_empty_office_is_caught(): void
    {
        $dir = $this->mutatedModules(self::NO_STATEMENT);

        $this->assertArrayHasKey('statement', $this->storeDefects('refusal_503_cold', false, $dir),
            'the RED did not bite: a 503 with no statement passed');
    }

    public function test_red_a_floor_animating_behind_the_sign_in_is_caught(): void
    {
        $defects = $this->signInDefects('refusal_401_warm', true, $this->mutatedModules(self::ANIMATES_BEHIND));

        $this->assertSame(['motion'], array_keys($defects), 'the RED failed some other clause, or none: '.json_encode($defects));
    }

    /** Third RED — the held-open stream, planted in the FIXTURE: the statement renders, the end does not happen. */
    public function test_red_a_held_open_stream_rendered_as_live_is_caught(): void
    {
        $defects = $this->dbDownDefects('db_down_held_open', true);

        $this->assertArrayNotHasKey('statement', $defects, 'the Third RED must pass the statement — that is what makes it dangerous');
        $this->assertArrayHasKey('ended', $defects, 'the RED did not bite: a held-open stream passed the end assertion');
        $this->assertArrayHasKey('connection', $defects, 'the RED did not bite: the indicator still read *connected* and passed');
    }

    public function test_red_the_banner_on_every_deploy_is_caught(): void
    {
        $this->assertArrayHasKey('banner', $this->graceDefects('reload_known_8s', $this->mutatedModules(self::BANNER_EVERY_DEPLOY)),
            'the RED did not bite: a banner over a deploy that moved nothing passed');
    }

    public function test_red_a_grace_with_no_end_is_caught(): void
    {
        $defects = $this->graceEndDefects('reload_never', $this->mutatedModules(self::ENDLESS_GRACE));

        $this->assertSame(['grace_end'], array_keys($defects), json_encode($defects));
    }

    public function test_red_the_drains_end_given_the_grace_is_caught(): void
    {
        $defects = $this->drainDefects('drain_end', $this->mutatedModules(self::DRAIN_GRACED));

        $this->assertArrayHasKey('strip', $defects, 'the RED did not bite: '.json_encode($defects));
    }

    // ── The GREENs, as defect lists ──────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function storeDefects(string $run, bool $warm, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $frame = $this->lastFloor($result);
        $defects = [];
        $refusal = $this->fixture($run)['http']['/api/fleet/snapshot'][$warm ? 1 : 0]['body'];

        if ($frame['failure']['statement'] !== $this->documentStoreStatement($refusal['server_time'])) {
            $defects['statement'] = 'the statement reads '.json_encode($frame['failure']['statement']);
        }

        $defects += $this->keptDefects($frame, $warm, $run);

        return $defects;
    }

    /** @return array<string, string> */
    private function keptDefects(array $frame, bool $warm, string $run): array
    {
        if ($warm) {
            $defects = [];

            if ($frame['failure']['kept'] !== $this->documentLastKnownGood()) {
                $defects['kept'] = 'the kept floor is labelled '.json_encode($frame['failure']['kept']);
            }

            if (count($frame['desks']['desks']) !== count($this->snapshotSeats($run))) {
                $defects['floor'] = 'the floor did not keep its last state: '.count($frame['desks']['desks']).' desks';
            }

            return $defects;
        }

        // Cold: no floor to keep, "and the screen says so in words" — never an empty office.
        return $frame['failure']['kept'] === null || $frame['failure']['kept'] === $this->documentLastKnownGood()
            ? ['kept' => 'a cold start says nothing about having no floor to keep: '.json_encode($frame['failure']['kept'])]
            : [];
    }

    /** @return array<string, string> */
    private function signInDefects(string $run, bool $warm, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $defects = [];
        $signedAt = null;
        $signedFrame = null;

        foreach ($result['floor_renders'] as $i => $render) {
            if ($render['frame']['failure']['sign_in'] !== null && $signedAt === null) {
                $signedAt = $render['at'];
                $signedFrame = $i;
            }
        }

        if ($signedAt === null) {
            return ['sign_in' => 'no frame drew the sign-in prompt'];
        }

        $frame = $this->lastFloor($result);
        $signIn = $frame['failure']['sign_in'];

        if ($signIn['dimmed'] !== true || preg_match('/^not live since (\d{2}:\d{2}:\d{2}|not reported)$/', $signIn['label']) !== 1) {
            $defects['dimmed'] = 'the floor beneath is not dimmed and labelled *not live since HH:MM:SS*: '.json_encode($signIn);
        }

        // "and the client closes the stream" — every stream it opened is closed, none opened after.
        foreach ($result['streams'] as $stream) {
            if ($stream['closed_at'] === null || $stream['opened_at'] > $signedAt) {
                $defects['stream'] = 'a stream is open after the 401, or was opened after it: '.json_encode($result['streams']);
            }
        }

        if ($warm) {
            // A frozen floor that still animates is the lie: every held render drawn still once the
            // prompt is up, against a floor that was moving before it (the discriminating half).
            $before = $this->heldMotion(array_slice($result['floor_renders'], 0, $signedFrame));
            $after = $this->heldMotion(array_slice($result['floor_renders'], $signedFrame));

            $this->assertContains(true, $before, "[{$run}] nothing moved before the 401 — the motion clause cannot fail");

            if (in_array(true, $after, true)) {
                $defects['motion'] = 'a desk behind the sign-in prompt still draws its loop';
            }

            $defects += array_diff_key($this->keptDefects($frame, true, $run), ['kept' => 1]);
        }

        return $defects;
    }

    /** @return list<bool> */
    private function heldMotion(array $renders): array
    {
        $out = [];

        foreach ($renders as $render) {
            foreach ($render['frame']['desks']['desks'] as $desk) {
                if ($desk['held'] !== null) {
                    $out[] = $desk['held']['motion'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function dbDownDefects(string $run, bool $warm): array
    {
        $result = $this->floorRun($run);
        $frame = $this->lastFloor($result);
        $health = $this->firstEnvelope($run, 'fleet.health');
        $defects = [];

        $this->assertSame('down', $health['fleet']['db'], "[{$run}] the stream's first message is not `db: \"down\"`");

        if ($frame['failure']['statement'] !== $this->documentStoreStatement($health['server_time'])) {
            $defects['statement'] = 'the statement reads '.json_encode($frame['failure']['statement']);
        }

        // Assert the END itself, and the indicator AFTER it (the Third RED's two clauses).
        if ($result['streams'][0]['closed_at'] === null && $result['streams'][0]['ended_at'] === null) {
            $defects['ended'] = 'the stream is still open';
        }

        if ($frame['strip']['connection'] === 'connected') {
            $defects['connection'] = 'the indicator still reads *connected*';
        }

        if ($frame['strip']['live'] === true) {
            $defects['live'] = 'the strip claims *live*';
        }

        return $defects + $this->keptDefects($frame, $warm, $run);
    }

    /** @return array<string, string> */
    private function unknownVersionDefects(string $run): array
    {
        $result = $this->floorRun($run);
        $reloadAt = $this->messageAt($run, 'fleet.reload');
        $defects = [];

        foreach ($result['floor_renders'] as $render) {
            $banner = $render['frame']['failure']['banner'];

            if (($render['at'] >= $reloadAt) !== ($banner === $this->documentBanner())) {
                $defects['banner'] = "at {$render['at']} the banner is ".json_encode($banner);
            }
        }

        // "delta application stops immediately — assert a delta delivered after it changes nothing"
        $delta = $this->firstEnvelope($run, 'seat.delta');
        $key = "{$delta['install_id']}/{$delta['seat_id']}";

        if ($this->finalSeats($result)[$key] != $this->snapshotSeats($run)[$key]) {
            $defects['delta'] = 'a delta delivered after the banner changed the seat it names';
        }

        if (count($result['streams']) !== 1) {
            $defects['reopen'] = count($result['streams']).' streams were opened; the page reload is the reconnect';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function graceDefects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $fixture = $this->fixture($run);
        $close = $this->messageAt($run, 'feed.close');
        $cadence = $this->documentCadenceMs();
        $silence = 45000;
        $acceptAt = $close + $cadence * (intdiv($fixture['stream_refusals'][0]['until_ms'] - $close, $cadence) + 1);
        $spoke = min(array_filter(array_column($fixture['messages'], 'at_ms'), static fn (int $t): bool => $t > $acceptAt));
        $defects = [];

        foreach ($result['floor_renders'] as $render) {
            $at = $render['at'];
            $frame = $render['frame'];

            if ($frame['failure']['banner'] !== null) {
                $defects['banner'] ??= "at {$at} a banner rendered over a deploy that moved nothing";
            }

            if ($frame['failure']['statement'] !== null) {
                $defects['statement'] ??= "at {$at} a statement rendered inside the grace";
            }

            if ($at < 100) {
                continue;
            }

            // *reconnecting* from 45 s after the last message (the `feed.close`) until the new stream
            // speaks; *live* at every other moment — which on the 8 s run is every moment.
            $want = $at >= $close + $silence && $at < $spoke ? 'reconnecting' : 'live';

            if ($frame['strip']['feed'] !== $want) {
                $defects['strip'] ??= "at {$at} the strip reads `{$frame['strip']['feed']}`, not `{$want}`";
            }
        }

        // The attempts on the 10 s cadence, the accepted one last.
        $attempts = array_values(array_filter(array_column($result['streams'], 'opened_at'), static fn (int $t): bool => $t > 0));

        if ($attempts !== range($close + $cadence, $acceptAt, $cadence)) {
            $defects['cadence'] = 're-opens at ['.implode(', ', $attempts).']';
        }

        // No snapshot poll before the stream re-opened; the re-run's own snapshot after it.
        $polls = $this->snapshotRequestTimes($result);

        if (count($polls) !== 2 || $polls[1] < $acceptAt) {
            $defects['poll'] = 'snapshot requests at ['.implode(', ', $polls).']';
        }

        // And the re-run rendered without animation: no `edge` row after the close.
        $closeAt = $this->ms($this->firstEnvelope($run, 'feed.close')['server_time']);

        foreach ($result['animation_log'] as $row) {
            if ($row['class'] === 'edge' && $row['at'] > $closeAt) {
                $defects['animation'] = "an `edge` row ({$row['animation_id']}) fired after the re-open";
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function graceEndDefects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $end = $this->messageAt($run, 'feed.close') + $this->documentGraceMs();
        $defects = [];
        $reached = false;

        foreach ($result['floor_renders'] as $render) {
            $down = $render['frame']['strip']['feed'] === $this->documentFeedDown();

            if ($render['at'] < $end && $down) {
                $defects['early'] = "at {$render['at']} step 7's render came before the grace ended";
            }

            if ($render['at'] >= $end && ! $reached) {
                $reached = true;

                if (! $down) {
                    $defects['grace_end'] = "at {$render['at']}, the grace's end, the strip reads `{$render['frame']['strip']['feed']}`";
                }
            }
        }

        if (! $reached) {
            $defects['grace_end'] = 'no frame was drawn at or after the grace\'s end';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function drainDefects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir);
        $endAt = $this->endAt($run);
        $defects = [];

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] >= $endAt && $render['frame']['strip']['feed'] !== $this->documentFeedDown()) {
                $defects['strip'] ??= "at {$render['at']} the strip reads `{$render['frame']['strip']['feed']}`";
            }
        }

        if (! in_array($endAt, $this->snapshotRequestTimes($result), true)) {
            $defects['poll'] = 'no poll was issued at the end';
        }

        return $defects;
    }

    // ── Reading the fixture and the document ─────────────────────────────────────────────────

    private function messageAt(string $run, string $type): int
    {
        foreach ($this->fixture($run)['messages'] as $m) {
            if (($m['envelope']['t'] ?? null) === $type) {
                return $m['at_ms'];
            }
        }

        $this->fail("[{$run}] carries no {$type}");
    }

    private function endAt(string $run): int
    {
        foreach ($this->fixture($run)['messages'] as $m) {
            if (($m['end'] ?? false) === true) {
                return $m['at_ms'];
            }
        }

        $this->fail("[{$run}] never ends its stream");
    }

    private function firstEnvelope(string $run, string $type): array
    {
        foreach ($this->fixture($run)['messages'] as $m) {
            if (($m['envelope']['t'] ?? null) === $type) {
                return $m['envelope'];
            }
        }

        $this->fail("[{$run}] carries no {$type}");
    }

    /** @return list<int> */
    private function snapshotRequestTimes(array $result): array
    {
        $times = [];
        $seen = 0;

        foreach ($result['records'] as $record) {
            $count = count(array_filter($record['requests'], static fn (string $p): bool => $p === '/api/fleet/snapshot'));

            for (; $seen < $count; $seen++) {
                $times[] = $record['at'];
            }
        }

        return $times;
    }

    private function nineRow(string $id): string
    {
        $this->assertSame(1, preg_match('/^\| '.$id.' \|.*$/m', $this->floorMd(), $m), "§ 9 {$id} did not parse");

        return $m[0];
    }

    /** F4's statement, with the instant substituted: "fleet state is unavailable — the store could not be read at 14:23:14". */
    private function documentStoreStatement(string $wire): string
    {
        $this->assertSame(1, preg_match('/\*\*(fleet state is unavailable — the store could not be read at) \d{2}:\d{2}:\d{2}\*\*/',
            $this->nineRow('F4'), $m));

        return $m[1].' '.substr($wire, 11, 8);
    }

    private function documentLastKnownGood(): string
    {
        $this->assertSame(1, preg_match('/labelled \*(last known good)\*/', $this->nineRow('F4'), $m));

        return $m[1];
    }

    private function documentBanner(): string
    {
        $this->assertSame(1, preg_match('/a full-width banner: \*\*([^*]+)\*\*/', $this->nineRow('F8'), $m));

        return $m[1];
    }

    private function documentFeedDown(): string
    {
        $this->assertSame(1, preg_match('/the status strip reads \*\*([^*]+)\*\*/', $this->nineRow('F1'), $m));

        return $m[1];
    }

    private function documentGraceMs(): int
    {
        $this->assertSame(1, preg_match('/\*\*The reload grace is (\d+) s from the `feed\.close`/', $this->floorMd(), $m),
            '§ 2.2\'s reload grace did not parse');

        return (int) $m[1] * 1000;
    }

    private function documentCadenceMs(): int
    {
        $this->assertSame(1, preg_match('/feed presumed dead: indicator, poll at (\d+) s/', $this->floorMd(), $m),
            '§ 2.2 step 7\'s cadence did not parse');

        return (int) $m[1] * 1000;
    }
}
