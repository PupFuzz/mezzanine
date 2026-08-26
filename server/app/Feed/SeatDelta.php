<?php

namespace App\Feed;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`seat.delta`** — "a seat's `state_version` advanced".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `ShouldDispatchAfterCommit` IS § 6.5's PSEUDOCODE, NOT A PRECAUTION.
 *
 * § 6.5's fold loop ends `COMMIT` / `if state_version changed: enqueue a delta (§ 8.3)` — the
 * publish is OUTSIDE the transaction and after it. `App\Fold\StateRecompute` dispatches this
 * event from inside the transaction so the publish is ORDERED BY the act that bumped the
 * version, and the contract defers delivery until that act commits. A delta published from a
 * transaction that then rolls back is a client told a seat changed when it did not, with nothing
 * to recall it; `App\Events\SeatRetired` (card #7712) took the same shape for the same reason and
 * this is not a second decision.
 *
 * ⛔ `ShouldBroadcastNow`, NOT `ShouldBroadcast`: ORDER IS THE CONTRACT.
 *
 * § 8.5's whole gap-detection rule is `delta.state_version == local.state_version + 1`. A queued
 * broadcast with more than one worker can deliver v48220 after v48221, and every reordering costs
 * that client a resync of the seat — so the publish is synchronous in the writer, which is the
 * process that already serialises a seat's passes (§ 6.5's `FOR UPDATE SKIP LOCKED` claim gives
 * one seat to one worker). The cost, stated: the broadcaster's round trip sits inside the fold
 * pass. At § 8.3's own volume figure — 0.104 msg/s/seat before coalescing — that is not a
 * throughput question.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ NO COALESCING, AND THIS IS A REPORTED D2 INCOHERENCE RATHER THAN AN OMISSION.
 *
 * § 8.3 says "**Coalescing: one delta per seat per 250 ms.** A seat's changes inside a tick are
 * merged into one message." § 8.5 says a client "applies a delta iff `delta.state_version ==
 * local.state_version + 1`. If it is greater, DELTAS WERE LOST". Those two cannot both hold:
 * merging two version-bearing changes (v5 and v6) into one message at v6 is, at a client holding
 * v4, byte-indistinguishable from a delta at v5 having been dropped. AT-D2-8 makes the
 * incompatibility explicit from the other side — "the client sees `state_version` JUMP BY 2" is
 * its evidence that exactly one delta was lost, which is only evidence if one delta is always
 * exactly one version.
 *
 * Coalescing is a RATE optimisation; the `+ 1` rule is the correctness property the whole
 * resync machinery rests on, and AT-D2-8 asserts it directly. Worse, under the `+ 1` rule
 * coalescing is self-defeating: every merged burst costs the client a full seat resync, so the
 * optimisation ADDS traffic. So this card ships one delta per version increment and does not
 * coalesce. Closing it properly needs a wire member (`from_version`, so a client can tell a
 * merge from a loss) or a rule that `state_version` counts DELTAS rather than changes — both
 * D2 changes, and D2 is not edited here. Card #7827's PR body carries it.
 *
 * THE COST OF NOT COALESCING, NAMED: a seat's outbound message rate is no longer bounded at
 * § 8.3's 4 msg/s "regardless of what the seat does" — it is bounded by the seat's own
 * version-bearing event rate, which D1 caps at ~8,980/seat-day but does not cap instantaneously.
 */
final class SeatDelta implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use FeedEnvelope;

    /**
     * ⛔ THE MAP FROM `SeatFacts::versionBearing()`'s FINGERPRINT KEYS TO § 8.2.1's TOP-LEVEL WIRE
     * MEMBERS — a restatement that cannot be deleted, so it is GUARDED instead.
     *
     * The fingerprint and the wire object are deliberately different shapes: the fingerprint is
     * flat where the object nests (`no_data_since` and `seq_epoch` are members of `delivery`;
     * `reporter_version` / `reporter_platform` / `selftest_failed` are members of `reporter`),
     * because the fingerprint's job is to compare and the object's is to render. Something has to
     * say which fingerprint member moves which wire member, and this is it.
     *
     * `SeatDeltaMapCoversTheFingerprintTest` asserts this array's key set is EXACTLY
     * `SeatFacts::versionBearing()`'s, so a fingerprint member added by a later card cannot
     * silently stop reaching the wire — which is the one way this map can be wrong and no test
     * notice: a seat's state would change, `state_version` would bump, a delta would be emitted,
     * and it would not carry the thing that changed.
     *
     * § 8.3.1's shallow-merge rule is what makes the many-to-one entries correct: "a nested
     * object is replaced WHOLE, never deep-merged" — so a patch that touches
     * `delivery.no_data_since` re-sends all of `delivery`, which § 6.5 notes "refreshes the
     * bookkeeping members for free and is why no separate refresh rule is needed".
     *
     * @var array<string, string>
     */
    public const WIRE_MEMBER = [
        'render_state' => 'render_state',
        'link_state' => 'link_state',
        'activity_state' => 'activity_state',
        'unknown_reason' => 'unknown_reason',
        'api_error_type' => 'api_error_type',
        'action' => 'action',
        'open_calls' => 'open_calls',
        'open_turn' => 'open_turn',
        'subagents' => 'subagents',
        'subagents_open' => 'subagents_open',
        'task' => 'task',
        'context' => 'context',
        'model_label' => 'model_label',
        'session' => 'session',
        'activity' => 'activity',
        'no_data_since' => 'delivery',
        'seq_epoch' => 'delivery',
        'badges' => 'badges',
        'badges_since' => 'badges_since',
        'enabled' => 'enabled',
        'reporter_version' => 'reporter',
        'reporter_platform' => 'reporter',
        'selftest_failed' => 'reporter',
        'retired' => 'retired',
    ];

    /**
     * @param  list<string>  $changed
     * @param  array<string, mixed>  $patch
     */
    public function __construct(
        private readonly string $installId,
        public readonly string $seatId,
        public readonly int $stateVersion,
        public readonly string $at,
        public readonly array $changed,
        public readonly array $patch,
    ) {}

    /**
     * Build the message from the two fingerprints `StateRecompute::settle()` already holds and
     * the wire object as it now stands.
     *
     * @param  array<string, mixed>  $before  `SeatFacts::versionBearing()` before the writes
     * @param  array<string, mixed>  $after  the same, after
     * @param  array<string, mixed>  $object  § 8.2.1's object, built from the post-write state
     */
    public static function between(array $before, array $after, array $object): self
    {
        $members = [];

        foreach (self::WIRE_MEMBER as $fingerprintKey => $wireMember) {
            // A fingerprint key MISSING from either side is a defect in the map, not a change —
            // and the guard test is what catches it. Comparing a missing key against a present
            // one would silently mark every member changed on the first pass after a schema move.
            if (($before[$fingerprintKey] ?? null) !== ($after[$fingerprintKey] ?? null)) {
                $members[$wireMember] = true;
            }
        }

        $changed = array_keys($members);
        sort($changed);

        $patch = [];

        foreach ($changed as $member) {
            $patch[$member] = $object[$member];
        }

        return new self(
            installId: $object['install_id'],
            seatId: $object['seat_id'],
            stateVersion: $object['state_version'],
            // § 8.3's `at`: WHEN THE STATE TOOK THIS VALUE, which is the fold/sweep pass's own
            // `state_computed_at`, not when the message happened to be serialized. The envelope's
            // `server_time` already answers the second question, and a message that answered it
            // twice would let a consumer compute an age from the wrong one.
            at: $object['derivation']['computed_at'],
            changed: $changed,
            patch: $patch,
        );
    }

    public function type(): string
    {
        return 'seat.delta';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return [
            'install_id' => $this->installId,
            'seat_id' => $this->seatId,
            'state_version' => $this->stateVersion,
            'at' => $this->at,
            // § 8.3.1: "`changed` is redundant with `patch`'s keys ON PURPOSE: a client applies
            // `patch` and uses `changed` to decide what to animate, and a delta that patches a
            // field to the value it already held (possible after a resync) is distinguishable
            // from one that did not touch it."
            'changed' => $this->changed,
            // An EMPTY patch must serialize as `{}` and not `[]`. PHP's empty array is a JSON
            // list, and a client merging a list into its seat object gets a type error rather
            // than a no-op. The cast is the fix at the one place the value is produced.
            'patch' => (object) $this->patch,
        ];
    }
}
