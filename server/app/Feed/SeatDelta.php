<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`seat.delta`** — "a seat's `state_version` advanced".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ENQUEUED INSIDE THE TRANSACTION THAT BUMPED THE VERSION, AND WRITTEN AS ITS LAST STATEMENT.
 *
 * § 6.5's per-writer rule: "any process that changes a version-bearing field bumps `state_version`
 * and enqueues a delta in the same transaction". `App\Fold\StateRecompute` hands this message to
 * `App\Feed\Outbox::enqueue()` from inside the writer's transaction, and `Outbox::transaction()`
 * inserts the row immediately before the COMMIT (card#9300) — so a delta and the state it announces
 * commit together or not at all, and a rolled-back pass tells no client anything. The previous
 * transport's `ShouldDispatchAfterCommit` + `ShouldBroadcastNow` pair is retired with it.
 *
 * ORDER IS STILL THE CONTRACT (§ 8.5's `== local + 1`), and it is now the outbox's `id` order: one
 * seat is folded by one worker at a time (§ 6.5's `FOR UPDATE SKIP LOCKED` claim), each pass's row is
 * inserted in its own COMMIT's last statement, and every stream delivers rows in `id` order as a
 * visible prefix that never moves its cursor past an id still to become visible (AT-D2-25,
 * `App\Feed\VisiblePrefix`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ NO COALESCING — ONE DELTA PER `state_version` INCREMENT.
 *
 * A merged message at v6 is, to a client holding v4, byte-indistinguishable from a lost delta at
 * v5 under § 8.5's `== local + 1` rule, so every merged burst would cost that client a full seat
 * resync and the optimisation would ADD traffic. This class shipped that way on card #7827 against
 * a D2 that still promised coalescing; D2 § 8.3 withdrew the promise on card#9287 and reconciled to
 * this code, so the incoherence card #7827 reported is closed. The cost, named: a seat's outbound
 * message rate is bounded by its own version-bearing event rate, not by the 250 ms tick.
 */
final class SeatDelta implements FeedMessage
{
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
     * `SeatObjectMatchesTheDocumentTest::test_the_delta_map_covers_every_version_bearing_member`
     * asserts this array's key set is EXACTLY `SeatFacts::versionBearing()`'s, so a fingerprint
     * member added by a later card cannot silently stop reaching the wire — which is the one way
     * this map can be wrong and no test notice: a seat's state would change, `state_version` would
     * bump, a delta would be emitted, and it would not carry the thing that changed.
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
        'blocked_since' => 'blocked_since',
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
        'protocol_agent_name' => 'protocol_agent_name',
        'protocol_agent_name_check' => 'protocol_agent_name_check',
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
