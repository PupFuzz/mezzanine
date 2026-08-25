<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * WHAT ACTUALLY HAPPENS WHEN A REPORTER-MINTED, `NOT NULL` FIELD IS ABSENT — measured, because the
 * decision that removed this fold's defensive fallbacks was argued on a claim about the INGEST that
 * is not true.
 *
 * The claim was "they are `NOT NULL` and reporter-minted, so the ingest already refuses a batch
 * missing one". It does not. D1 § 12.1 step 10 checks the per-kind ENUM *values* and nothing else —
 * `EventValidator` says so in terms ("It does not type-check or require the per-kind `data`
 * fields"), and an absent enum is skipped explicitly because § 6.0 makes a missing key and an
 * explicit `null` the same thing. § 12.4's atomic rejection is why: refusing the batch would destroy
 * up to 199 good events over one malformed field, which is the trade D1 refuses to make.
 *
 * So the FOLD is the first plane that sees it, and the mechanism that contains it is § 6.5's
 * poison-event rule rather than any ingest refusal. That is what this test pins, in both halves:
 * the ingest accepts and stores the event, and the fold quarantines exactly that event while the
 * rest of the seat's state stands. Which is what makes removing the fallbacks right — a defaulted
 * `notification_kind` would mint a `blocked` state carrying a notification kind no seat ever sent,
 * and a fabricated state is worse than a badged one.
 */
class MissingRequiredWireFieldTest extends FoldTestCase
{
    public function test_the_ingest_accepts_it_and_the_fold_quarantines_exactly_that_event(): void
    {
        $call = $this->ulid();
        $request = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 20]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Write', 'descriptor' => 'Write: notes.md',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            // `notification_kind` is absent. § 6.4 declares the column `NOT NULL`, D1 mints the
            // value at the reporter, and no default is invented for it anywhere in the fold.
            $this->event('attention.request', [
                'request_id' => $request, 'source' => 'permission_request_hook',
                'call_id' => $call, 'open_calls' => 1,
            ]),
        ]);

        // ⛔ HALF ONE — the ingest ACCEPTED it. `deliver()` already asserted the 202; this asserts
        // the stronger thing, that the event was stored rather than ignored, because a refusal
        // would have cost the two good events beside it.
        $this->assertSame(1, DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', 'attention.request')->count(),
            'the ingest refused or dropped the event, so the decision below has a different premise');

        $this->fold();

        // ⛔ HALF TWO — the fold quarantined that ONE event. The insert raised on the NOT NULL
        // column, § 6.5 retried it alone once, and the cursor then advanced past it.
        $this->assertFalse($this->behind(), 'the seat is wedged on the event instead of past it');
        $this->assertSame(1, $this->counter('fold_error'));
        $this->assertSame(1, (int) $this->state()->fold_errors);
        $this->assertContains('derivation_error', json_decode($this->state()->server_badges, true));

        // NOTHING WAS INVENTED. No row was written with a defaulted kind, so nothing renders
        // `blocked` on a notification the seat never sent.
        $this->assertSame(0, DB::table('attention_requests')->where('seat_ref', $this->seatRef)->count());
        $this->assertNull($this->state()->open_attention_ref);
        $this->assertNotSame('blocked', $this->state()->activity_state);

        // AND THE REST OF THE SEAT STILL STANDS — the two events beside it projected, so the desk
        // reads `working` off its open call rather than collapsing to `unknown`.
        $this->assertSame(1, (int) $this->state()->open_calls);
        $this->assertSame('working', $this->state()->activity_state);
        $this->assertSame(1, DB::table('calls')->where('seat_ref', $this->seatRef)
            ->where('call_id', $call)->count());

        // The event stays in the log, so the fix plus a rebuild recovers the seat exactly.
        $this->assertSame(1, DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', 'attention.request')->count());
    }
}
