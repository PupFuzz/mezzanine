<?php

namespace Tests\Feature\Sweep;

use App\Sweep\Sweep;
use App\Sweep\SweepPass;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\FoldTestCase;

/**
 * Shared rig for the sweeper's tests — `docs/design/FLEET-STATE.md § 2.1`'s third process.
 *
 * EXTENDS THE FOLD'S RIG RATHER THAN COPYING IT. § 11's fixtures are the fold's ("every test below
 * drives the fold with EVENT FIXTURES — arrays of wire events in D1's exact shape, replayed through
 * the real ingest path into the real store"), and every sweep job acts on facts only the fold can
 * have projected. A rig that inserted rows straight into `calls` would prove the sweeper reads its
 * own writes.
 */
abstract class SweepTestCase extends FoldTestCase
{
    /** One sweep pass, on the current server clock. */
    protected function sweep(): SweepPass
    {
        return app(Sweep::class)->pass();
    }

    /**
     * Keep a seat's TRANSPORT alive while its facts age.
     *
     * Most of the ceilings under test (15 min, 60 min) are longer than § 4.5's `offline` threshold
     * (900 s), so advancing the clock far enough for a ceiling to be due also takes the seat
     * offline — and § 2.1's job order then has offline quiescence closing the very fact the ceiling
     * job exists to close. That is CORRECT behaviour and one of the tests below asserts the
     * precedence directly; it is useless for isolating a single job, so these tests deliver a real
     * heartbeat to refresh `last_receipt_at`, which is what a live seat actually does every 60 s
     * (D1 § 9.1). Back-dating a column instead would be the suite writing state the ingest owns.
     */
    protected function stayAlive(): void
    {
        $this->deliver($this->heartbeats(1));
        $this->fold();
    }

    /**
     * § 4.10's one act, by its one producer.
     *
     * ⚠ HOISTED HERE AT ITS SECOND CALLER (card #7827's feed tests). § 11's AT-D2-23 BUILD is
     * explicit that the mechanism under test is the COMMAND — "not by writing the columns
     * directly, because the command *is* the mechanism under test" — so a second test file
     * reaching for retirement must reach for the same three arguments, not for an UPDATE.
     */
    protected function retire(?string $seatId = null): void
    {
        $this->artisan('mezzanine:retire', [
            '--seat' => self::INSTALL.'/'.($seatId ?? self::SEAT),
            '--by' => 'operator@aimla',
            '--reason' => 'decommissioned',
        ])->assertSuccessful();
    }

    /** @return list<string> the `cause` of every transition row, in order */
    protected function causes(?int $seatRef = null): array
    {
        return array_column($this->transitions($seatRef), 'cause');
    }

    protected function predicate(string $name, ?int $seatRef = null): ?object
    {
        return DB::table('seat_predicates')
            ->where('seat_ref', $seatRef ?? $this->seatRef)->where('name', $name)->first();
    }

    protected function callRow(): object
    {
        return DB::table('calls')->where('seat_ref', $this->seatRef)->orderBy('id')->first();
    }

    protected function sessionRow(): object
    {
        return DB::table('sessions')->where('seat_ref', $this->seatRef)->orderBy('id')->first();
    }

    protected function requestRow(): object
    {
        return DB::table('attention_requests')->where('seat_ref', $this->seatRef)->orderBy('id')->first();
    }

    /** A `turn.start` plus one open `tool.start` of the given tool, and nothing that closes it. */
    protected function openCall(string $tool = 'Bash'): array
    {
        $this->lastOpenCallId = $this->ulid();

        return [
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('tool.start', [
                'call_id' => $this->lastOpenCallId, 'tool_name' => $tool, 'descriptor' => 'Bash: sleep 9000',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
        ];
    }

    protected ?string $lastOpenCallId = null;

    /**
     * The `tool.end` that closes the call `openCall()` last opened — a WIRE close, on the ledger
     * discipline D1 built, and not an UPDATE.
     *
     * ⚠ ADDED BY CARD #7827 BESIDE `openCall()` RATHER THAN IN A TEST FILE, because the pair is
     * one fixture: a test that opens a call through the real ingest and closes it by writing
     * `calls.closed_at` would be a test of its own UPDATE.
     */
    protected function closeOpenCall(string $tool = 'Bash'): array
    {
        return [
            $this->event('tool.end', [
                'call_id' => $this->lastOpenCallId, 'tool_name' => $tool, 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 900, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'harness_ref',
            ]),
        ];
    }
}
