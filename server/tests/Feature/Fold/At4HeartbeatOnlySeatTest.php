<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;

/**
 * AT-D2-4 — a heartbeat-only seat never looks busy.
 *
 * "The mechanised form of § 3 (delivery is not activity), and the maxim's test."
 *
 * The maxim, quoted verbatim in § 3.1 from roundtable #341: *a stamp that refreshes only when a
 * seat posts corroborates; it cannot exonerate.* Mezzanine's entire value proposition is the
 * difference between those two readings.
 */
class At4HeartbeatOnlySeatTest extends FoldTestCase
{
    public function test_an_hour_of_heartbeats_moves_the_receipt_age_and_not_the_activity_age(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $activityAfterWork = $this->state()->last_activity_received_at;
        $this->assertNotNull($activityAfterWork);

        // § 11's `heartbeat_only`: 60 heartbeats, ONE PER MINUTE, no activity event of any kind.
        // Delivered one per minute of REAL server clock rather than as one batch, because the two
        // ages this test separates are both server-clock quantities and a single batch would move
        // neither of them by an hour.
        for ($minute = 0; $minute < 60; $minute++) {
            $this->advanceServerClock(60);
            $this->deliver($this->heartbeats(1, uptimeStart: 86_213 + ($minute * 60)));
            $this->fold();
        }

        $state = $this->state();

        // ⛔ THE ACTIVITY COLUMN DID NOT MOVE AT ALL. That single line — writing it from the
        // heartbeat — is the whole defect, and this is the test that makes shipping it impossible.
        $this->assertSame($activityAfterWork, $state->last_activity_received_at);
        // `turn.end` — the last member of § 3.2's activity set in `clean_turn`, and NOT
        // `reporter.heartbeat`, which is not in that set at all.
        $this->assertSame('turn.end', $state->last_activity_kind, 'a heartbeat became the last activity');

        // Delivery advanced every minute.
        $this->assertNotNull($state->last_heartbeat_received_at);

        $nowMs = Clock::toMs(Clock::sql(now()));
        $receiptAgeS = ($nowMs - Clock::toMs($state->last_receipt_at)) / 1000;
        $quietAgeS = ($nowMs - Clock::toMs($state->last_activity_received_at)) / 1000;

        // § 3.3's two ages: "the quiet age grows to 60 minutes while the receipt age stays under
        // 60 s". Both are on the wire SEPARATELY, so no consumer has to guess which one it holds.
        $this->assertLessThan(60, $receiptAgeS, 'the receipt age grew — the seat stopped reporting');
        $this->assertGreaterThanOrEqual(3600, $quietAgeS, 'the quiet age did not grow');

        // § 3.1 rule 4 and § 4.4: an idle seat that goes quiet STAYS `idle` while it keeps
        // heartbeating, and becomes `stale` only when the heartbeat stops. Idle is a positive
        // observation; its expiry is a transport fact, not an activity fact.
        $this->assertSame('idle', $state->render_state);
        $this->assertSame('live', $state->link_state);
    }

    public function test_the_discriminating_control_one_tool_start_resets_the_activity_age_exactly_once(): void
    {
        // § 11: "an interleaved fixture (heartbeats PLUS one `tool.start` at minute 30) → the
        // activity age resets exactly once, at minute 30. WITHOUT THIS CONTROL THE RED COULD BE
        // PASSED BY A COLUMN THAT NEVER UPDATES AT ALL."
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->state()->last_activity_received_at;

        for ($minute = 0; $minute < 6; $minute++) {
            $this->advanceServerClock(60);

            if ($minute === 3) {
                $this->deliver([$this->event('tool.start', [
                    'call_id' => $this->ulid(), 'tool_name' => 'Read', 'descriptor' => 'Read: PLAN.md',
                    'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                    'harness_call_ref' => null, 'open_calls_before' => 0,
                ])]);
                $this->fold();

                $atReset = $this->state()->last_activity_received_at;
                $this->assertNotSame($before, $atReset, 'a tool.start did not move the activity age');

                continue;
            }

            $this->deliver($this->heartbeats(1, uptimeStart: 86_213 + ($minute * 60)));
            $this->fold();
        }

        // Moved once, at minute 3, and never again — the heartbeats after it moved nothing.
        $this->assertSame($atReset, $this->state()->last_activity_received_at);
        $this->assertSame('tool.start', $this->state()->last_activity_kind);

        // And the seat with an open call is WORKING, which is D1 § 8.6's last row: a seat with any
        // open call is working regardless of turn state.
        $this->assertSame('working', $this->state()->activity_state);
    }

    public function test_a_context_sample_is_not_activity_either(): void
    {
        // § 3.2's one row a reviewer should push on. `context.sample` is sampled by the statusLine
        // integration ON A RENDER, not on an agent action — it correlates with activity and is not
        // produced by it, so treating it as activity would make the gauge's own refresh look like
        // work: a stamp corroborating itself.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->state();

        $this->advanceServerClock(120);
        $this->deliver([$this->event('context.sample', [
            'used_pct' => 73.2, 'used_tokens' => 146401, 'total_tokens' => 200000,
            'used_pct_source' => 'harness', 'model_label' => 'claude-opus-5',
            'sample_reason' => 'threshold_cross',
        ])]);
        $this->fold();

        $after = $this->state();

        $this->assertSame($before->last_activity_received_at, $after->last_activity_received_at);
        $this->assertSame($before->last_activity_kind, $after->last_activity_kind);

        // It DOES move the gauge and the sample age, which is the whole of its effect.
        $this->assertSame('73.2', (string) $after->context_used_pct);
        $this->assertSame('harness', $after->context_source);
        $this->assertSame('claude-opus-5', $after->model_label);
    }
}
