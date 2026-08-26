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
        return [
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('tool.start', [
                'call_id' => $this->ulid(), 'tool_name' => $tool, 'descriptor' => 'Bash: sleep 9000',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
        ];
    }
}
