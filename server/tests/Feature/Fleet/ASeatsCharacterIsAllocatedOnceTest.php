<?php

namespace Tests\Feature\Fleet;

use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Characters\SpeciesList;
use App\Feed\FleetReload;
use App\Feed\SeatDelta;
use App\Fleet\CharacterAllocation;
use App\Fleet\SeatRetirement;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Models\User;
use App\Read\ReadTokens;
use App\Read\SeatObject;
use App\Read\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Rooms;
use Tests\TestCase;

/**
 * AT-D2-31's store-backed legs — the rules, the floor, the unreadable layout and the wire (card#12443,
 * `docs/design/FLEET-STATE.md § 6.13`). Every seat is created through `SeatTokens::issue()`, the one application
 * writer of `seats`, so the species each one holds is the allocation's and nothing a fixture wrote.
 *
 * ⭐ SINCE card#13117 A NEW SEAT IS ON NO FLOOR: issue creates it in no room, and § 6.13 rule 2 is *the seat's floor,
 * or nothing while it has none* (operator ruling 2026-11-10, Q6 (a)), so issue never reads the layout. A fixture
 * seat is then put in its room (`Tests\Support\Rooms`) so the population the next allocation reads has rooms. The
 * floor rules — rule 2 over a floor, rule 3's floor key, the unreadable layout — are still the allocation's, and the
 * species migration's backfill still reaches them, so those legs drive `CharacterAllocation::choose()` over
 * `floorOf()` directly, as that backfill does.
 *
 * ⚠ ON `RefreshDatabase`, and that is the reason this half is its own class: these legs write a second and third
 * install's seats, a refused global `building_layout` row, `authored_revisions` and `global_counters`, and the
 * transaction discards every row they write. The migration leg is `TheCharacterMigrationKeepsEveryUniqueDrawTest`;
 * the concurrency legs, which need real commits on two connections, are `SeatCharacterAllocationLockOrderTest`.
 */
class ASeatsCharacterIsAllocatedOnceTest extends TestCase
{
    use RefreshDatabase;

    private function list(): SpeciesList
    {
        return SpeciesList::current();
    }

    /**
     * The preference order AS PUBLISHED (§ 6.13): the list rotated to start at the key's own draw — derived here from
     * the draw and the list, never through `SpeciesList::preference()`, the code under test.
     *
     * @return list<string>
     */
    private function preferenceOf(string $key): array
    {
        $keys = SpeciesList::current()->keys;
        $at = (int) array_search(SpeciesList::current()->drawOf($key), $keys, true);

        return array_merge(array_slice($keys, $at), array_slice($keys, 0, $at));
    }

    private function issue(string $installId, string $seatId): string
    {
        $issued = SeatTokens::issue($seatId, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued, "{$seatId} was refused a token");
        Rooms::place($seatId, $installId);

        return $this->speciesOf($installId, $seatId);
    }

    /**
     * What the allocation answers for a seat of `$installId` on `$installId`'s floor — `choose()` over `floorOf()`,
     * the species migration's call, which is where the floor rules are still reached (see the class docblock).
     */
    private function allocateOnFloor(string $installId, string $seatId): string
    {
        $live = CharacterAllocation::livePopulation();

        return CharacterAllocation::choose($seatId, $live,
            CharacterAllocation::floorOf($installId, array_map(static fn (object $s): string => (string) $s->install_id, $live)),
            $this->list());
    }

    private function speciesOf(string $installId, string $seatId): string
    {
        return (string) DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $installId)->where('seats.seat_id', $seatId)->value('seats.species');
    }

    /** @return array<string, int> species → live seats holding it, over `$installs` (null: every install) */
    private function held(?array $installs = null): array
    {
        $q = DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')->whereNull('seats.retired_at');

        if ($installs !== null) {
            $q->whereIn('installs.install_id', $installs);
        }

        return array_count_values($q->pluck('seats.species')->all());
    }

    /**
     * Fill the fleet to exactly one live seat per species, every seat created through the allocation. `$where` says
     * which install each seat goes to by its index.
     *
     * @param  callable(int): string  $where
     * @return list<array{0: string, 1: string}> the seats, in creation order
     */
    private function fill(callable $where): array
    {
        $seats = [];

        foreach ($this->list()->keys as $i => $_) {
            $seats[] = [$where($i), "seat-{$i}"];
            $this->issue(...$seats[$i]);
        }

        return $seats;
    }

    /** A stored layout row its reader refuses — `"floors": {}` is an object where § 4.6 admits a list. */
    private function storeARefusedLayout(): void
    {
        $at = now()->format('Y-m-d H:i:s.v');
        DB::table('authored_revisions')->insert([
            'kind' => Revisions::LAYOUT, 'subject' => Revisions::LAYOUT_SUBJECT, 'revision' => 1, 'document' => '{"floors": {}}',
            'restored_from' => null, 'authored_by' => 'suite', 'authored_at' => $at,
        ]);
        DB::table('building_layout')->insert(['id' => 1, 'document' => '{"floors": {}}', 'layout_version' => 1, 'updated_by' => 'suite', 'updated_at' => $at]);

        try {
            Layouts::read();
            $this->fail('the planted layout row is one the reader accepts — the leg would read nothing about a refused one');
        } catch (InvalidBuildingLayout) {
            // the precondition: the reader refuses it
        }
    }

    private function floorUnread(): int
    {
        return (int) (DB::table('global_counters')->where('name', CharacterAllocation::FLOOR_UNREAD)->value('value') ?? 0);
    }

    // ── rule 1 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * GREEN — rule 1, and the lazy layout read: the first `SPECIES_KEYS.length` seats are all distinct, each whose own
     * draw was free at its creation holds exactly that draw, and with a refused layout row stored throughout, nothing
     * is refused and nothing is counted — so the layout was never read while rule 1 answered.
     */
    public function test_rule_one_every_seat_gets_a_species_no_live_seat_holds_and_the_layout_is_never_read(): void
    {
        $this->storeARefusedLayout();
        $list = $this->list();
        $ownDraws = 0;

        foreach ($list->keys as $i => $_) {
            $install = ['alpha', 'beta'][$i % 2];
            $seat = "seat-{$i}";
            // A new seat's preference key is its room's — `unassigned` at issue (card#13117 S1).
            $own = $list->drawOf($seat);
            $free = ! isset($this->held()[$own]);

            $got = $this->issue($install, $seat);

            if ($free) {
                $ownDraws++;
                $this->assertSame($own, $got, "{$install}/{$seat}'s own draw {$own} was free and it was given {$got}");
            }
        }

        $this->assertSame(count($list->keys), count($this->held()), 'the first SPECIES_KEYS.length seats do not hold all-distinct species');
        $this->assertGreaterThan(0, $ownDraws, 'no seat had a free own draw — the leg read nothing about the preference order');
        $this->assertLessThan(count($list->keys), $ownDraws, 'every seat had a free own draw — the leg read nothing about a taken one');
        $this->assertSame(0, $this->floorUnread(), 'the layout was read (and refused) while rule 1 still answered');
    }

    // ── rules 2 and 3 ─────────────────────────────────────────────────────────────────────────────

    /**
     * GREEN — a NEW seat is on no floor (card#13117, § 6.13 rule 2 *or nothing while it has none*): rule 2 is skipped,
     * and the over-full seat takes the fleet's least used, then its preference order — not the first of its order,
     * which is what an empty floor read as a floor nobody is on would give (every species passes rule 2 there). The
     * seat id is chosen so the two answers differ, and that is asserted, so the leg tells them apart.
     */
    public function test_a_new_seat_is_on_no_floor_so_rule_two_is_skipped(): void
    {
        $this->fill(static fn (int $i): string => $i === 0 ? 'alpha' : 'beta');
        $twice = $this->issue('alpha', 'over-0');
        $this->assertSame(2, $this->held()[$twice]);

        $seat = null;

        for ($k = 1; $seat === null; $k++) {
            $seat = $this->preferenceOf("over-{$k}")[0] === $twice ? "over-{$k}" : null;
        }

        $got = SeatTokens::issue($seat, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $got);
        $species = (string) DB::table('seats')->where('id', $got->seatRef)->value('species');
        Rooms::place($seat, 'alpha');

        $this->assertNotSame($twice, $species, "the roomless seat took {$species}, its first preference, which two live seats hold");
        // Every other species was held once before it, so the least used one is now held twice — by it and one other.
        $this->assertSame(2, $this->held()[$species], "the roomless seat took {$species}, which is not among the fleet's least used");
    }

    /**
     * T9's server half (card#13117, plan § 3.4): the seat's first preference is the body the BROWSER draws for it —
     * `creatures.js`'s `speciesOf()` over `seed.js`'s `seatKey()` of its `seat_id`, run under `node` — so a seat
     * whose own draw is free wears, from the first allocation, the body the generator would have drawn. A server that
     * keyed the preference by any other string (the pre-S2 `unassigned/<seat_id>`) gives another body; the seat id is
     * chosen so the two keys draw differently, and that is asserted, so this leg tells them apart.
     */
    public function test_an_uncontended_seat_is_allocated_the_body_the_browser_draws_for_its_seat_id(): void
    {
        $list = $this->list();
        $seat = null;

        for ($k = 0; $seat === null; $k++) {
            $seat = $list->drawOf("agree-{$k}") !== $list->drawOf("unassigned/agree-{$k}") ? "agree-{$k}" : null;
        }

        $tree = realpath(__DIR__.'/../../../../resources/characters') ?: $this->fail('resources/characters is not in the tree');
        $script = sprintf('const seed = await import(%s); const C = await import(%s); console.log(JSON.stringify(C.speciesOf(seed.seatKey(%s))));',
            json_encode('file://'.$tree.'/seed.js'), json_encode('file://'.$tree.'/creatures.js'), json_encode($seat));
        $browser = json_decode((string) shell_exec('node --input-type=module -e '.escapeshellarg($script).' 2>&1'), true);
        $this->assertIsString($browser, 'node did not print the species the generator draws');

        $this->assertSame($browser, $this->issue('alpha', $seat), "an empty fleet allocated {$seat} a body other than the one the browser draws for it");
    }

    /**
     * GREEN — rule 2 with no layout: the over-full seat is its install's own floor, and takes a species no live seat
     * of its install holds.
     */
    public function test_rule_two_with_no_layout_the_floor_is_the_install(): void
    {
        $this->fill(static fn (int $i): string => $i === 0 ? 'alpha' : 'beta');

        $got = $this->allocateOnFloor('alpha', 'over');

        $this->assertArrayNotHasKey($got, $this->held(['alpha']), "the over-full seat took {$got}, which another seat of its install holds");
    }

    /**
     * GREEN — rule 2 over a composed floor, and the THIRD RED's discrimination: `alpha` and `beta` share a floor and
     * `gamma` is a floor of its own. Every species is held; only `gamma`'s are free on the floor. The over-full seat
     * of `alpha` takes one of them — never one its floor-mate `beta` holds, which is what reading its install alone
     * would give (asserted, so this leg can tell the two apart).
     */
    public function test_rule_two_over_a_composed_floor_takes_only_what_the_floor_does_not_hold(): void
    {
        $n = count($this->list()->keys);
        $this->fill(static fn (int $i): string => $i === 0 ? 'alpha' : ($i === $n - 1 ? 'gamma' : 'beta'));
        $gamma = array_keys($this->held(['gamma']));

        Layouts::save('{"floors": [{"name": "alpha","rooms": {"alpha": {"form": "open"}, "beta": {"form": "open"}}}]}', 'suite', Layouts::version());
        $this->assertSame(Layouts::layout()->floorOf('alpha'), Layouts::layout()->floorOf('beta'));
        $this->assertNotSame(Layouts::layout()->floorOf('alpha'), Layouts::layout()->floorOf('gamma'));

        // The seat id is chosen so that its install alone would give a species `beta` holds (rule 2 over `alpha` only).
        $seat = null;

        for ($k = 0; $seat === null; $k++) {
            $alone = array_values(array_filter($this->preferenceOf("alpha/over-{$k}"), fn (string $s): bool => ! isset($this->held(['alpha'])[$s])))[0];
            $seat = in_array($alone, $gamma, true) ? null : "over-{$k}";
        }

        $got = $this->allocateOnFloor('alpha', $seat);

        $this->assertContains($got, $gamma, "the over-full seat took {$got}, which a seat on its own floor holds");
    }

    /**
     * GREEN — rule 3: with every species held on the floor, the next seat holds the one the fewest live seats on the
     * floor hold, then the fewest fleet-wide, then the first in its preference order — re-derived here from the
     * population the store holds, and the ranking shown to have more than one level in play.
     */
    public function test_rule_three_the_least_used_on_the_floor_then_the_fleet_then_the_order(): void
    {
        $this->fill(static fn (int $i): string => $i % 3 === 2 ? 'gamma' : 'alpha');
        Layouts::save('{"floors": [{"name": "alpha","rooms": {"alpha": {"form": "open"}}}]}', 'suite', Layouts::version());

        // Fill the floor: every species `gamma` holds gets an `alpha` seat too, so the floor holds every species.
        $k = 0;

        while (count($this->held(['alpha'])) < count($this->list()->keys)) {
            $this->issue('alpha', 'fill-'.$k++);
        }

        $floor = $this->held(['alpha']);
        $fleet = $this->held();
        $pref = $this->preferenceOf('alpha/next');
        $ranked = $pref;
        usort($ranked, static fn (string $a, string $b): int => [$floor[$a], $fleet[$a]] <=> [$floor[$b], $fleet[$b]]);

        $min = min($floor);
        $tied = array_values(array_filter($pref, static fn (string $s): bool => $floor[$s] === $min));
        $this->assertGreaterThan(1, count(array_unique(array_map(static fn (string $s): int => $fleet[$s], $tied))),
            'the floor\'s least-used tie carries no fleet-wide difference — the second key of rule 3 is not exercised');

        $this->assertSame($ranked[0], $this->allocateOnFloor('alpha', 'next'));
    }

    // ── the unreadable layout ─────────────────────────────────────────────────────────────────────

    /**
     * GREEN — with the refused row stored, the under-full issue allocates under rule 1 with no count; the over-full
     * allocation on a floor treats each install as its own floor, `character_floor_unread` reads one higher on
     * `GET /api/fleet/health`, one log line names the refusal, and nothing is refused.
     */
    public function test_an_unreadable_layout_never_refuses_an_issue_and_is_counted_once_when_it_was_needed(): void
    {
        $this->storeARefusedLayout();
        Log::spy();

        $this->fill(static fn (int $i): string => $i === 0 ? 'alpha' : 'beta');
        $this->assertSame(0, $this->health()['counters'][CharacterAllocation::FLOOR_UNREAD], 'an under-full issue counted the layout');

        $got = $this->allocateOnFloor('alpha', 'over');

        $this->assertArrayNotHasKey($got, $this->held(['alpha']), "with the layout unreadable, the over-full seat took {$got}, which its own install holds");
        $this->assertSame(1, $this->health()['counters'][CharacterAllocation::FLOOR_UNREAD]);
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $line): bool => str_starts_with($line, 'mezzanine.character:')
            && str_contains($line, 'treats every install as its own floor') && str_contains($line, 'floors'))->once();
    }

    // ── rules 4 and 5 ─────────────────────────────────────────────────────────────────────────────

    /**
     * GREEN — rule 4: a re-issue changes nothing; rule 5: retirement frees `SPECIES_KEYS[0]` by the read filter alone
     * — the next new seat, the only free species being that one, holds it — and the retired row keeps its species.
     */
    public function test_a_seat_keeps_its_species_for_life_and_retirement_frees_it_by_no_write(): void
    {
        $seats = $this->fill(static fn (int $i): string => ['alpha', 'beta'][$i % 2]);
        $first = $this->list()->keys[0];
        $holder = array_values(array_filter($seats, fn (array $s): bool => $this->speciesOf(...$s) === $first))[0];

        $versionBefore = (int) DB::table('seat_state')->max('state_version');
        $before = DB::table('seats')->orderBy('id')->pluck('species', 'id')->all();
        $this->issue(...$holder);
        $this->assertSame($before, DB::table('seats')->orderBy('id')->pluck('species', 'id')->all(), 'a re-issue moved a stored species');
        $this->assertSame($versionBefore, (int) DB::table('seat_state')->max('state_version'), 'a re-issue bumped a state_version');

        app(SeatRetirement::class)->retire($holder[1], 'ops@example.com', 'freeing its character');

        $this->assertSame($first, $this->speciesOf(...$holder), 'the retired row lost the species it wore — retirement wrote it');
        $this->assertSame($first, $this->issue('gamma', 'after-retirement'), 'the species a retired seat held was not free for the next new seat');
    }

    // ── the wire and the console ──────────────────────────────────────────────────────────────────

    /**
     * GREEN — the snapshot, the detail and the delta's map carry `character.species` for every live seat; the feed's
     * version is the bumped one; the console's agents page lists each seat's species, and labels *today's draw* on a
     * row the migration marked.
     */
    public function test_the_wire_and_the_console_carry_the_species(): void
    {
        $seats = $this->fill(static fn (int $i): string => ['alpha', 'beta'][$i % 2]);

        foreach (Snapshot::build(now()->getTimestampMs())['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $this->assertSame($this->speciesOf($seat['install_id'], $seat['seat_id']), $seat['character']['species']);
            }
        }

        $ref = (int) DB::table('seats')->orderBy('id')->value('id');
        $this->assertSame((string) DB::table('seats')->where('id', $ref)->value('species'), SeatObject::forSeatRef($ref, now()->getTimestampMs())['character']['species']);
        $this->assertSame('character', SeatDelta::WIRE_MEMBER['species']);
        $this->assertGreaterThanOrEqual(2, FleetReload::FEED_VERSION, 'the release that ships character.species bumped feed_version to 2 (§ 6.13), and no later release lowers it');

        // A seat retired before the migration, as the migration marks it.
        app(SeatRetirement::class)->retire($seats[0][1], 'ops@example.com', 'gone before the migration');
        DB::table('seats')->where('id', $ref)->update(['species_drawn_at' => now()->format('Y-m-d H:i:s.v')]);

        $page = $this->actingAs(User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']))
            ->get(route('admin.agents.index'))->assertOk();

        foreach ($seats as $seat) {
            $page->assertSee($this->speciesOf(...$seat));
        }

        $this->assertSame(1, substr_count((string) $page->getContent(), "(today's draw)"), 'the console did not label exactly the marked row');
    }

    /** @return array<string, mixed> `GET /api/fleet/health`'s `fleet` */
    private function health(): array
    {
        return $this->call('GET', '/api/fleet/health', server: [
            'REMOTE_ADDR' => '203.0.113.99',
            'HTTP_AUTHORIZATION' => 'Bearer '.ReadTokens::issue('health-'.bin2hex(random_bytes(3)), 'suite'),
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk()->json('fleet');
    }
}
