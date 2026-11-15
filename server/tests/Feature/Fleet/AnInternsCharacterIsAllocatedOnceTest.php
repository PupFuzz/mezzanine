<?php

namespace Tests\Feature\Fleet;

use App\Building\Layouts;
use App\Characters\SpeciesList;
use App\Fleet\InternSpecies;
use App\Fleet\SeatRetirement;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Fold\Clock;
use App\Read\SeatObject;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;
use Tests\Support\Rooms;

/**
 * AT-D2-31's intern legs (card#13258, `docs/design/FLEET-STATE.md § 6.13`'s card#13258 paragraph,
 * `docs/design/plan-13258-intern-species.md § 7` T2, T3, T5a, T5b, T5 and T8's server half).
 *
 * The sites are driven through the real ingest and `Fold::pass()` (`FoldTestCase::fold()`), never through
 * `mezzanine:fold`: that command runs the startup seed first, and the seed would give a row a site skipped its
 * species, so a leg driven through it could not red on a missed site. The seed's own legs drive the command.
 *
 * The legs about which species the rules pick past exhaustion (T3, T5b) plant the population as rows — every
 * species held exactly as the leg needs — and allocate one call through `InternSpecies::ensure()`, the fold's
 * entry point; the rules' inputs are then countable, which forty-eight dispatches through the ingest would not make
 * them any more than this does.
 */
class AnInternsCharacterIsAllocatedOnceTest extends FeedTestCase
{
    protected function tearDown(): void
    {
        InternSpecies::$reader = null;
        InternSpecies::forgetList();

        parent::tearDown();
    }

    private function list(): SpeciesList
    {
        return SpeciesList::current();
    }

    /** What the generator draws for a key — the published preference order's start, not `preference()`. */
    private function draw(string $key): string
    {
        return $this->list()->drawOf($key);
    }

    private function seatSpecies(int $seatRef): string
    {
        return (string) DB::table('seats')->where('id', $seatRef)->value('species');
    }

    private function speciesOf(string $callId, ?int $seatRef = null): ?string
    {
        $species = DB::table('intern_species')->where('seat_ref', $seatRef ?? $this->seatRef)->where('call_id', $callId)->value('species');

        return $species === null ? null : (string) $species;
    }

    /** @return array<string, mixed>|null the `intern_species` row */
    private function rowOf(string $callId, ?int $seatRef = null): ?array
    {
        $row = DB::table('intern_species')->where('seat_ref', $seatRef ?? $this->seatRef)->where('call_id', $callId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Every species a live character holds, with its holder count — read with this test's own SQL, not through
     * `CharacterAllocation::characters()`, the code under test.
     *
     * @return array<string, int>
     */
    private function held(): array
    {
        $seats = DB::table('seats')->whereNull('retired_at')->pluck('species')->all();
        $interns = DB::table('calls')
            ->join('seats', 'seats.id', '=', 'calls.seat_ref')
            ->join('intern_species', fn ($j) => $j->on('intern_species.seat_ref', '=', 'calls.seat_ref')->on('intern_species.call_id', '=', 'calls.call_id'))
            ->whereNull('seats.retired_at')->whereNull('calls.closed_at')->where('calls.is_dispatch', true)
            ->pluck('intern_species.species')->all();

        return array_count_values([...$seats, ...$interns]);
    }

    /** A call id whose intern key on `$seatId` draws a species `$want` accepts — found by search, asserted found. */
    private function callDrawing(string $seatId, callable $want): string
    {
        for ($i = 0; $i < 50_000; $i++) {
            $call = $this->ulid();

            if ($want($this->draw($seatId.'~'.$call))) {
                return $call;
            }
        }

        $this->fail('no call id drew a species the leg needs in 50,000 tries');
    }

    /** @return array<string, mixed> */
    private function dispatch(string $call, string $tool = 'Agent'): array
    {
        return $this->event('tool.start', [
            'call_id' => $call, 'tool_name' => $tool, 'descriptor' => null,
            'descriptor_truncated' => false, 'agent_scope' => 'main',
            'parent_call_id' => null, 'harness_call_ref' => 'toolu_d', 'open_calls_before' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function spawn(string $call): array
    {
        return $this->event('subagent.spawn', [
            'call_id' => $call, 'title' => 'draft the schema', 'title_truncated' => false, 'subagent_type' => 'coder',
        ]);
    }

    /** @return array<string, mixed> */
    private function end(string $call, string $tool = 'Agent'): array
    {
        return $this->event('tool.end', [
            'call_id' => $call, 'tool_name' => $tool, 'outcome' => 'completed',
            'abort_reason' => null, 'duration_ms' => 1000, 'duration_source' => 'harness',
            'close_source' => 'post_tool_use', 'match' => 'harness_ref',
        ]);
    }

    /** @return array<string, mixed> */
    private function stop(string $call): array
    {
        return $this->event('subagent.stop', [
            'call_id' => $call, 'outcome' => 'completed', 'abort_reason' => null, 'duration_ms' => 1000,
            'close_source' => 'subagent_stop_hook',
        ]);
    }

    private function pass(array $events, ?string $token = null, ?string $seat = null): void
    {
        $this->deliver($events, token: $token, seat: $seat);
        $this->fold();
    }

    /** @return array<string, ?string> call_id → species, as the seat object delivers it */
    private function served(?int $seatRef = null): array
    {
        $object = SeatObject::forSeatRef($seatRef ?? $this->seatRef, $this->nowMs());

        $served = array_column($object['subagents'], 'species', 'call_id');
        ksort($served);

        return $served;
    }

    /** A dispatch `calls` row written straight to the store, with no `intern_species` row. */
    private function plantCall(int $seatRef, string $callId, bool $open = true): void
    {
        $at = Clock::sql(now());

        DB::table('calls')->insert([
            'seat_ref' => $seatRef, 'call_id' => $callId, 'tool_name' => 'Agent', 'is_dispatch' => true,
            'opened_at' => $at, 'opened_received_at' => $at, 'closed_at' => $open ? null : $at,
            'applied_event_time' => $at, 'applied_seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH', 'applied_seq' => 1,
        ]);
    }

    /** An open intern holding `$species`, written straight to the store. */
    private function plantIntern(int $seatRef, string $species): string
    {
        $call = $this->ulid();
        $this->plantCall($seatRef, $call);
        DB::table('intern_species')->insert([
            'seat_ref' => $seatRef, 'call_id' => $call, 'species' => $species, 'allocated_at' => Clock::sql(now()),
        ]);

        return $call;
    }

    // ── T2 — the three sites, the rules over seats and interns, and what is NOT a site ─────────────────────────────

    public function test_every_open_dispatch_gets_a_species_once_by_the_rules_at_each_of_its_three_sites(): void
    {
        $x = $this->seatSpecies($this->seatRef);
        $keys = $this->list()->keys;

        // #1 — the `tool.start` insert. Its own draw is free and not the seat's, and is not near the list's head,
        // so an allocation that walked the list from its first key instead of the key's own draw would differ.
        $c1 = $this->callDrawing(self::SEAT, fn (string $d): bool => $d !== $x && array_search($d, $keys, true) >= 3);
        $this->pass([$this->dispatch($c1)]);
        $this->assertSame($this->draw(self::SEAT.'~'.$c1), $this->speciesOf($c1),
            "the dispatch's tool.start did not give the intern its own key's draw, which was free");

        // #2 on the same seat: neither the seat's body nor its sibling's.
        $c2 = $this->ulid();
        $this->pass([$this->dispatch($c2)]);
        $this->assertNotNull($this->speciesOf($c2));
        $this->assertNotContains($this->speciesOf($c2), [$x, $this->speciesOf($c1)]);

        // An intern of ANOTHER seat avoids every character in the building.
        [$tokenT, $refT] = $this->secondSeat('aimla-impl');
        $cT = $this->ulid();
        $this->pass([$this->dispatch($cT)], $tokenT, 'aimla-impl');
        $this->assertNotContains($this->speciesOf($cT, $refT),
            [$x, $this->seatSpecies($refT), $this->speciesOf($c1), $this->speciesOf($c2)]);

        // The close frees it: #1 ends, and #3, whose own draw is #1's species, takes it — #1's row still holds it.
        $s1 = $this->speciesOf($c1);
        $this->pass([$this->end($c1)]);
        $c3 = $this->callDrawing(self::SEAT, fn (string $d): bool => $d === $s1);
        $this->pass([$this->dispatch($c3)]);
        $this->assertSame($s1, $this->speciesOf($c3), "a closed intern's species was not freed");
        $this->assertSame($s1, $this->speciesOf($c1), "the closed intern's row lost the body it wore");

        // A late spawn on open #2, and a duplicate tool.start on it, touch nothing.
        $row2 = $this->rowOf($c2);
        $this->advanceServerClock(5);
        $this->pass([$this->spawn($c2)]);
        $duplicates = $this->counter('duplicate_open');
        $this->pass([$this->dispatch($c2)]);
        $this->assertSame($duplicates + 1, $this->counter('duplicate_open'), 'the control: the duplicate open was counted');
        $this->assertSame($row2, $this->rowOf($c2), 'a spawn or a duplicate open on an open dispatch rewrote its species row');

        // #4 — the spawn that arrives FIRST inserts the row, and allocates; its tool.start later changes nothing.
        $c4 = $this->ulid();
        $this->pass([$this->spawn($c4)]);
        $row4 = $this->rowOf($c4);
        $this->assertNotNull($row4, 'the spawn-first insert allocated nothing');
        $this->pass([$this->dispatch($c4)]);
        $this->assertSame($row4, $this->rowOf($c4));

        // #5 — a tool.start outside DISPATCH_TOOLS opens a row that is no intern; the spawn that flips it is the site.
        $c5 = $this->ulid();
        $this->pass([$this->dispatch($c5, 'Bash')]);
        $this->assertNull($this->rowOf($c5), 'a non-dispatch call was allocated a species');
        $this->pass([$this->spawn($c5)]);
        $this->assertNotNull($this->speciesOf($c5), 'the spawn that made an open row a dispatch allocated nothing');

        // A stop that arrives first inserts a CLOSED row: no intern, no species, not on the wire.
        $c6 = $this->ulid();
        $this->pass([$this->stop($c6)]);
        $this->assertSame(1, DB::table('calls')->where('call_id', $c6)->whereNotNull('closed_at')->count());
        $this->assertNull($this->rowOf($c6));

        // Every character distinct while species remain; the wire delivers each open intern's own row.
        $this->assertSame([], array_keys(array_filter($this->held(), fn (int $n): bool => $n > 1)), 'two characters share a species while the list has free ones');
        $served = $this->served();
        $this->assertEqualsCanonicalizing([$c2, $c3, $c4, $c5], array_keys($served));

        foreach ($served as $call => $species) {
            $this->assertSame($this->speciesOf($call), $species, "subagents[] does not deliver {$call}'s species");
        }
    }

    /** T8's server half — the snapshot and the delta that announces an intern both carry its species. */
    public function test_the_snapshot_and_the_delta_announcing_an_intern_carry_its_species(): void
    {
        $call = $this->ulid();
        $mark = $this->wire->mark();
        $this->pass([$this->dispatch($call)]);

        $species = $this->speciesOf($call);
        $this->assertNotNull($species);

        $deltas = array_values(array_filter(
            $this->wire->ofTypeFrom('seat.delta', $mark),
            fn ($d) => in_array('subagents', $d['payload']['changed'], true),
        ));
        $this->assertCount(1, $deltas, 'the open was not one delta on subagents');
        $this->assertSame($species, $deltas[0]['payload']['patch']['subagents'][0]['species']);

        $snapshot = $this->asMachine($this->readToken('snap'), '/api/fleet/snapshot')->assertOk()->json();
        $seat = collect($snapshot['installs'])->flatMap(fn ($i) => $i['seats'])->firstWhere('seat_id', self::SEAT);
        $this->assertSame($species, $seat['subagents'][0]['species']);

        // The member is NULL — never a fallback — when the row is missing (§ 8.2.1).
        DB::table('intern_species')->where('call_id', $call)->delete();
        $this->assertSame([$call => null], $this->served());
    }

    // ── T2 — the startup seed ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * The deploy window's shape — an open dispatch row with no species, written by a worker that allocated nothing —
     * gains one when `mezzanine:fold` starts, before its first pass; nothing else moves, and a second start is a
     * no-op.
     */
    public function test_the_fold_workers_startup_seed_gives_an_open_dispatch_with_no_species_one(): void
    {
        $kept = $this->ulid();
        $this->pass([$this->dispatch($kept)]);
        $before = $this->rowOf($kept);

        $bare = $this->ulid();
        $this->plantCall($this->seatRef, $bare);
        $expected = [$kept => $before['species'], $bare => null];
        ksort($expected);
        $this->assertSame($expected, $this->served(), 'the control: the planted row has no species');

        $this->artisan('mezzanine:fold', ['--once' => true])->assertSuccessful();

        $seeded = $this->rowOf($bare);
        $this->assertNotNull($seeded, 'the startup seed did not reach the open dispatch row');
        $this->assertNotContains($seeded['species'], [$this->seatSpecies($this->seatRef), $before['species']]);
        $this->assertSame($before, $this->rowOf($kept), 'the seed rewrote a row that had its species');

        $this->advanceServerClock(5);
        $this->artisan('mezzanine:fold', ['--once' => true])->assertSuccessful();
        $this->assertSame($seeded, $this->rowOf($bare), 'a second start moved a seeded species');
    }

    /** A seed that throws is logged and counted, and the worker folds on: its interns go without a species. */
    public function test_a_startup_seed_that_throws_never_stops_the_fold(): void
    {
        $bare = $this->ulid();
        $this->plantCall($this->seatRef, $bare);

        InternSpecies::$reader = fn (): SpeciesList => throw new \RuntimeException('the species list is unreadable');
        InternSpecies::forgetList();

        $this->deliver([$this->event('turn.start', ['prompt_chars' => 12])]);
        $this->assertTrue($this->behind(), 'the control: an event is waiting for the fold');

        $this->artisan('mezzanine:fold', ['--once' => true])->assertSuccessful();

        $this->assertFalse($this->behind(), 'the fold did not run after its seed threw');
        $this->assertSame(1, $this->globalCounter(InternSpecies::SEED_FAILED));
        $this->assertSame([$bare => null], $this->served());
    }

    // ── T2 — rebuild ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A rebuild FINDS an open intern's species: with the population moved under it — another seat now holds that
     * species — the replay still delivers the same one.
     */
    public function test_a_rebuild_finds_an_open_interns_species_rather_than_allocating_again(): void
    {
        $call = $this->ulid();
        $this->pass([$this->dispatch($call), $this->spawn($call)]);
        $species = $this->speciesOf($call);

        [, $refT] = $this->secondSeat('aimla-impl');
        DB::table('seats')->where('id', $refT)->update(['species' => $species]);

        $this->artisan('mezzanine:rebuild', ['--seat' => self::SEAT])->assertSuccessful();

        $this->assertSame([$call => $species], $this->served(), "the rebuild moved an open intern's body");
        $this->assertSame(1, $this->counter('state_rebuilds'));
    }

    /** A rebuild over closed dispatch calls that have their rows reads no species list at all. */
    public function test_a_rebuild_over_closed_dispatch_history_allocates_nothing(): void
    {
        $calls = [$this->ulid(), $this->ulid()];
        $this->pass([$this->dispatch($calls[0]), $this->end($calls[0]), $this->dispatch($calls[1]), $this->end($calls[1])]);
        $this->assertNotNull($this->speciesOf($calls[1]), 'the control: the history has its rows');

        $reads = 0;
        InternSpecies::$reader = function () use (&$reads): SpeciesList {
            $reads++;

            return SpeciesList::current();
        };
        InternSpecies::forgetList();

        $this->artisan('mezzanine:rebuild', ['--seat' => self::SEAT])->assertSuccessful();

        $this->assertSame(0, $reads, 'the replay allocated for a dispatch call whose species row exists');
    }

    // ── T3 — past exhaustion ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Every species held: an intern's first repeat is a species held only on ANOTHER floor (rule 2), and an intern
     * whose own draw is its seat's body still never takes it (rule 0) when rule 3 would rank that body first.
     */
    public function test_past_exhaustion_an_intern_repeats_off_its_floor_first_and_never_wears_its_seats_body(): void
    {
        [, $refF] = $this->issueToken('far', 'far-seat');
        Layouts::save('{"floors": [{"name": "one", "rooms": {"'.self::INSTALL.'": {"form": "open"}}}, {"name": "two", "rooms": {"far": {"form": "open"}}}]}', 'suite', Layouts::version());

        $x = $this->seatSpecies($this->seatRef);
        $y = $this->seatSpecies($refF);

        foreach (array_diff($this->list()->keys, [$x, $y]) as $species) {
            $this->plantIntern($this->seatRef, $species);
        }

        $this->assertSame(count($this->list()->keys), count($this->held()), 'the control: every species is held');

        // Its own draw is neither body, so a choice that skipped rule 2 would answer the draw rather than `$y`.
        $a = $this->callDrawing(self::SEAT, fn (string $d): bool => $d !== $x && $d !== $y);
        $this->plantCall($this->seatRef, $a);
        InternSpecies::ensure($this->seatRef, $a);
        $this->assertSame($y, $this->speciesOf($a), 'the first repeat is not the species held only on the other floor');

        // Now every species is held once on this floor and `$y` twice in the building: rule 3 ties on the floor
        // and ranks the rest by the fleet, so an intern whose own draw is `$x` would take `$x` but for rule 0.
        $b = $this->callDrawing(self::SEAT, fn (string $d): bool => $d === $x);
        $this->plantCall($this->seatRef, $b);
        InternSpecies::ensure($this->seatRef, $b);
        $this->assertNotSame($x, $this->speciesOf($b), "an intern took its own seat's body");
    }

    // ── T5a / T5b / T5 — the population ───────────────────────────────────────────────────────────────────────────

    /** T5a — a retired seat's still-open interns hold nothing. */
    public function test_a_retired_seats_open_interns_hold_no_species(): void
    {
        $x = $this->seatSpecies($this->seatRef);
        [, $refT] = $this->secondSeat('aimla-impl');
        $y = array_values(array_diff($this->list()->keys, [$x, $this->seatSpecies($refT)]))[0];
        $this->plantIntern($refT, $y);

        app(SeatRetirement::class)->retire('aimla-impl', 'suite', 'gone');
        $this->assertNotNull(DB::table('calls')->where('seat_ref', $refT)->whereNull('closed_at')->value('call_id'),
            'the control: the retired seat still has an open dispatch row');

        $call = $this->callDrawing(self::SEAT, fn (string $d): bool => $d === $y);
        $this->plantCall($this->seatRef, $call);
        InternSpecies::ensure($this->seatRef, $call);

        $this->assertSame($y, $this->speciesOf($call), "a retired seat's open intern still held its species");
    }

    /**
     * T5b — an intern of a seat in NO room has no floor: past exhaustion it ranks by the building alone, and the
     * room-less seats are not pooled as one floor (seat ruling R4).
     */
    public function test_a_roomless_seats_intern_has_no_floor_and_roomless_seats_are_not_pooled(): void
    {
        // Two room-less seats; this rig's seat is in a room.
        $u1 = SeatTokens::issue('loose-one', 'suite');
        $u2 = SeatTokens::issue('loose-two', 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $u1);
        $this->assertInstanceOf(SeatTokenIssued::class, $u2);

        $placed = $this->seatSpecies($this->seatRef);
        $own = $this->seatSpecies($u1->seatRef);

        // `$placed` is held twice, both in the placed room; every other species once, by the room-less seats.
        $this->plantIntern($this->seatRef, $placed);

        foreach (array_diff($this->list()->keys, array_keys($this->held())) as $species) {
            $this->plantIntern($u2->seatRef, $species);
        }

        $this->assertSame(count($this->list()->keys), count($this->held()), 'the control: every species is held');

        // With no floor, rule 3 ranks the building: `$placed` is the most used, so it is not the answer. Pooled as
        // one floor, the room-less characters would hold everything but `$placed`, and rule 2 would answer it.
        $call = $this->callDrawing('loose-one', fn (string $d): bool => $d !== $own && $d !== $placed);
        $this->plantCall($u1->seatRef, $call);
        InternSpecies::ensure($u1->seatRef, $call);

        $this->assertSame($this->draw('loose-one~'.$call), $this->speciesOf($call, $u1->seatRef),
            "a room-less seat's intern did not rank by the building alone");
    }

    /** T5 — a NEW seat avoids the species an open intern holds (the operator's Q1 (a)). */
    public function test_a_new_seat_avoids_an_open_interns_species(): void
    {
        $x = $this->seatSpecies($this->seatRef);
        $seatId = null;

        for ($i = 0; $i < 50_000 && $seatId === null; $i++) {
            if ($this->draw("fresh-{$i}") !== $x) {
                $seatId = "fresh-{$i}";
            }
        }

        $y = $this->draw($seatId);
        $this->plantIntern($this->seatRef, $y);

        $issued = SeatTokens::issue($seatId, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued);

        $this->assertNotSame($y, $this->seatSpecies($issued->seatRef), "a new seat was allocated an open intern's species");
    }
}
