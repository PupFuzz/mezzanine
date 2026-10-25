<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use App\Ingest\SchemaVersions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * **AT-D2-30 — an out-of-date reporter is badged, a current one is not, and nothing is refused.**
 * `docs/design/FLEET-STATE.md § 7.2`'s `update_required` and its four-answer predicate
 * (`SchemaVersions::reporterIsOutdated()`), card#11818 part 2, through the real ingest, fold and sweeper.
 *
 * Every version below is DERIVED from `SchemaVersions::CURRENT_REPORTER_VERSION` as the class under test
 * declares it, so the test holds the rule and not the value the constant has today: a lower `x.y.z`, the
 * constant itself, the constant's minor plus ten — a TWO-DIGIT component, the value a string comparison
 * misorders — and the constant with a pre-release suffix, which D1 § 4.2's 1…24-byte bound admits and the
 * version grammar rejects.
 *
 * ⛔ THE DEPLOY LEG REBINDS NO CONSTANT. What a raised constant leaves behind is exactly a stored version
 * that is now lower than it, with no batch to recompute on; so the leg writes a lower version straight into
 * a quiet seat's `seat_state.reporter_version` and runs ONE sweep pass. The predicate has no test seam.
 *
 * The first three REDs AT-D2-30 names are run by hand, each a plant in the shipped class: `strcmp` in
 * `SchemaVersions::reporterIsOutdated()` reds the first GREEN on the higher seat; an unreadable version
 * answered *not outdated* reds it on the unreadable seat; the badge dropped from every recompute with no
 * event (`StateRecompute`, the sweeper's path) reds the deploy leg. The build's report on card#11818
 * carries each plant and its outcome. The fourth RED, the drifting constant, is
 * `Tests\Unit\Ingest\CurrentReporterVersionIsPinnedToTheArtifactTest` and the guard's
 * `plant-bump-server-constant-stale`.
 */
class AnOutOfDateReporterIsBadgedTest extends FeedTestCase
{
    private const BADGE = 'update_required';

    /** @var array<string, array{string, int}> seat id => [token, seat_ref] */
    private array $seats = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['aimla-lower', 'aimla-equal', 'aimla-higher', 'aimla-never-fed', 'aimla-unreadable'] as $seat) {
            $this->seats[$seat] = $this->issueToken(self::INSTALL, $seat);
        }
    }

    public function test_green_a_lower_or_unreadable_version_is_badged_and_an_equal_higher_or_absent_one_is_not(): void
    {
        $mark = $this->wire->mark();

        foreach ($this->versions() as $seat => $version) {
            $this->feed($seat, $version);
        }

        $this->fold();

        foreach (['aimla-lower', 'aimla-unreadable'] as $seat) {
            $this->assertContains(self::BADGE, $this->badges($seat), "{$seat} ({$this->versions()[$seat]}) is not badged");
            $this->assertNotSame([], $this->raisingDeltas($seat, $mark), "no delta naming `badges` raised the badge on {$seat}");
        }

        foreach (['aimla-equal', 'aimla-higher', 'aimla-never-fed'] as $seat) {
            $this->assertNotContains(self::BADGE, $this->badges($seat), "{$seat} is badged");
        }

        // Nothing was refused: every batch was `202` (`deliver()` asserts it) and no refusal counter moved.
        $this->assertSame([], DB::table('seat_counters')->where('name', 'like', 'batches_refused.%')->pluck('name')->all(),
            'a reporter-version comparison refused a batch');
    }

    /** The two-digit component is what makes the string-order RED reachable: the higher seat's minor has two. */
    public function test_the_higher_version_carries_a_two_digit_component_and_sorts_below_the_constant_as_a_string(): void
    {
        $higher = $this->versions()['aimla-higher'];

        $this->assertMatchesRegularExpression('/\.\d{2,}\./', $higher);
        $this->assertLessThan(0, strcmp($higher, SchemaVersions::CURRENT_REPORTER_VERSION),
            'the fixture cannot see a string comparison: the higher version also sorts higher as a string');
    }

    public function test_green_the_badge_clears_on_the_first_event_at_a_current_version_before_any_sweep(): void
    {
        $this->feed('aimla-lower', $this->versions()['aimla-lower']);
        $this->fold();
        $this->assertContains(self::BADGE, $this->badges('aimla-lower'));

        $mark = $this->wire->mark();
        $this->feed('aimla-lower', SchemaVersions::CURRENT_REPORTER_VERSION, [$this->event('turn.start', ['prompt_chars' => 12])]);
        $this->fold();   // the fold alone: no sweep pass runs in this test

        $this->assertNotContains(self::BADGE, $this->badges('aimla-lower'), "the event's recompute left the badge");
        $cleared = array_filter($this->deltasNamingBadges('aimla-lower', $mark),
            static fn (array $d): bool => ! in_array(self::BADGE, $d['payload']['patch']['badges'], true));
        $this->assertNotSame([], $cleared, 'no delta naming `badges` carried the cleared array');
    }

    public function test_green_a_lower_stored_version_is_badged_by_one_sweep_pass_with_no_batch(): void
    {
        foreach ($this->versions() as $seat => $version) {
            $this->feed($seat, $version);
        }

        $this->fold();
        $this->assertNotContains(self::BADGE, $this->badges('aimla-equal'));
        $batches = DB::table('batches')->count();

        // What a deploy that raised the constant leaves behind: the stored version is now lower.
        DB::table('seat_state')->where('seat_ref', $this->seats['aimla-equal'][1])
            ->update(['reporter_version' => $this->versions()['aimla-lower']]);

        $this->advanceServerClock(1);
        $pass = Clock::sql(now());
        $mark = $this->wire->mark();
        $this->sweep();

        $this->assertSame($batches, DB::table('batches')->count(), 'a batch arrived — the leg is not the deploy case');
        $this->assertContains(self::BADGE, $this->badges('aimla-equal'), 'one sweep pass did not re-judge the quiet seat');
        $this->assertNotSame([], $this->raisingDeltas('aimla-equal', $mark), 'the sweep raised the badge and announced nothing');
        $firstSeen = json_decode((string) $this->state($this->seats['aimla-equal'][1])->badge_first_seen, true);
        $this->assertSame(Clock::toMs($pass), Clock::toMs($firstSeen[self::BADGE]),
            "badge_first_seen.update_required is not the sweep pass's clock");
    }

    public function test_green_the_health_body_carries_the_constant(): void
    {
        $this->call('GET', '/api/ingest/health', server: [
            'REMOTE_ADDR' => self::REPORTER_IP,
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->seats['aimla-lower'][0],
        ])->assertOk()->assertJson(['current_reporter_version' => SchemaVersions::CURRENT_REPORTER_VERSION]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Each fed seat's version, derived from the constant (the never-fed seat is absent: it has none).
     *
     * @return array<string, string>
     */
    private function versions(): array
    {
        $current = SchemaVersions::CURRENT_REPORTER_VERSION;
        [$x, $y, $z] = array_map('intval', explode('.', $current));
        // The last non-zero component, one lower: below the constant by numeric precedence.
        $lower = $z > 0 ? [$x, $y, $z - 1] : ($y > 0 ? [$x, $y - 1, 0] : [$x - 1, 0, 0]);

        return [
            'aimla-lower' => implode('.', $lower),
            'aimla-equal' => $current,
            'aimla-higher' => "{$x}.".($y + 10).'.'.$z,
            'aimla-unreadable' => "{$current}-dev",
        ];
    }

    /** @param  ?list<array<string, mixed>>  $events */
    private function feed(string $seat, string $version, ?array $events = null): void
    {
        $this->deliver($events ?? $this->heartbeats(1), ['reporter_version' => $version],
            token: $this->seats[$seat][0], seat: $seat);
    }

    /** @return list<string> */
    private function badges(string $seat): array
    {
        return json_decode((string) $this->state($this->seats[$seat][1])->server_badges, true) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function deltasNamingBadges(string $seat, int $mark): array
    {
        return array_values(array_filter($this->wire->ofTypeFrom('seat.delta', $mark),
            static fn (array $d): bool => $d['payload']['seat_id'] === $seat && in_array('badges', $d['payload']['changed'], true)));
    }

    /** @return list<array<string, mixed>> */
    private function raisingDeltas(string $seat, int $mark): array
    {
        return array_values(array_filter($this->deltasNamingBadges($seat, $mark),
            static fn (array $d): bool => in_array(self::BADGE, $d['payload']['patch']['badges'], true)));
    }
}
