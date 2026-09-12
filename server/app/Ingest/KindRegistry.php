<?php

namespace App\Ingest;

/**
 * D1 § 12.1 step 10's authority: what this ingest knows about each event kind.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT STEP 10 ACTUALLY DOES, because "per-kind `data` validation" reads as more than it is.
 *
 * D1 specifies exactly four behaviours for this step, and this class carries exactly the data
 * those four need:
 *
 *   1. An UNKNOWN KIND skips the step entirely, is ignored, and counts `ignored_unknown_kinds`
 *      (step 10; `docs/VERSIONING.md` rule 7). So `KINDS`' key set is the "known" set.
 *   2. An unrecognised value in a HARNESS-SOURCED closed enum is COERCED to that field's unknown
 *      member and counted in `coerced_enum_values` — "never rejected" (step 10, rule 7).
 *   3. An unrecognised value in a REPORTER-MINTED closed enum is `422 invalid_event`. This is not
 *      an inference: D1 § 6.0 says it outright — "A value outside a reporter-minted set is a
 *      reporter bug, not a harness change, and the ingest refuses it as `422 invalid_event`."
 *   4. A `data` field OVER THE BYTE BOUND § 6 publishes for it is `422 invalid_event` (`bounds`
 *      below). Ruled by the operator on card#9283 against truncating and against accepting-and-
 *      counting: the server never silently edits telemetry — D1 § 7's sanitize-at-the-reporter
 *      rule keeps that in one place — and a bound nothing checks is a suggestion that every
 *      consumer sized to it (D2 § 8.3's worst-case construction) is exposed by.
 *
 * The two sides are distinguished by ONE property, `unknown`: non-null means harness-sourced
 * (coerce), null means reporter-minted (refuse). D1 § 6.0's classification table is the source
 * of every value, and its own framing is that the classification exists *only* to decide this:
 * "Which side a field falls on decides its cross-version rule, and that is the only reason the
 * classification exists."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT STEP 10 DELIBERATELY DOES NOT DO — the decision this card had to make, stated rather than
 * left implicit.
 *
 * It does NOT type-check the non-enum `data` fields, it does NOT check their PATTERNS, and it
 * does NOT require the declared fields to be present. D1 specifies no such rule anywhere, and
 * inventing one is the exact failure D1 § 12.1's own note on step 9 and this card's brief both
 * warn against: a validation rule stricter than the schema turns a benign field into a permanent
 * seat outage, because § 12.4 rejects the whole batch and § 11.5 quarantines it forever. The
 * `harness_label` incident recorded in § 6.1 is that failure having already happened once, on
 * paper, from a pattern one character too narrow.
 *
 * ⛔ `bounds` IS NOT A HOLE IN THAT, AND THE DISTINCTION IS THE WHOLE OF CARD#9283. A byte bound
 * is a number D1 PUBLISHES per field and that every consumer is entitled to rely on; a type or a
 * pattern is a shape D1 leaves to the producer. So the check below refuses a value that is over a
 * PUBLISHED BOUND and refuses nothing else: a field of an unexpected type is measured by no rule
 * here and passes through exactly as it did before, because widening the refusal beyond the
 * bound is the permanent-outage trade above, taken for a rule D1 never wrote. Field-level
 * projection into typed columns is the fold's, and card #7339 owns it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE FIELD SETS ARE HERE AT ALL, given the above. They are the population
 * `ignored_unknown_fields` is counted over (§ 12.7: "an event carried a `data` key this ingest's
 * per-kind schema does not define"), which is a counter, never a refusal. It is TOP-LEVEL keys
 * of `data` only: the three open-keyed objects `reporter.heartbeat.counters`, `.predicates` and
 * `.selftest` have key sets D1 explicitly leaves open at the ingest (§ 6.14: "The keys are
 * declared, not closed at the ingest, and the difference is deliberate"), so nothing descends
 * into them. Their `bounds` entries do not descend either: § 6.14 states each one's cap on the
 * SERIALIZED object ("≤ 1.5 KiB serialized"), so the measurement is of the whole value and says
 * nothing about which keys are in it — which is what lets § 6.14's "a reporter that ships a
 * seventh check ahead of the table takes no `422`" stay true while the cap is enforced.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THIS TABLE IS A RESTATEMENT, SO IT IS GUARDED. Every value below is transcribed from
 * `docs/design/EVENT-SCHEMA.md` § 6.1–6.14 and § 9.3, and a transcription of another document's
 * declarations is the exact defect shape D1 § 6.0 records paying for twice. A pointer is not
 * available — a PHP request path cannot follow a link to a markdown table — so the restatement
 * is guarded instead: `tests/Feature/Ingest/EventSchemaDriftTest` re-derives every field name,
 * every enum member, every unknown member AND every byte bound from the document's own tables on
 * each run and fails if this class and the document disagree in either direction. The bound
 * population is DERIVED there and is deliberately written down nowhere: it is every row of a § 6
 * `data` field table whose bounds cell opens with a `≤ N B`/`≤ N KiB` figure, re-read on every
 * run, so a bound added to the document is a red here rather than a field silently unchecked.
 */
final class KindRegistry
{
    /**
     * The batch envelope's one enum (D1 § 4.2). Reporter-minted from Node's `process.platform`
     * and still carrying an unknown member — § 6.0 calls this "the one exception, and it is not
     * a hedge": the source is an open set outside D1's control, so `other` is a genuine unknown
     * case rather than a swallowed reporter bug.
     *
     * @var array{members: list<string>, unknown: string}
     */
    public const BATCH_ENUM_REPORTER_PLATFORM = [
        'members' => ['linux', 'win32', 'darwin', 'other'],
        'unknown' => 'other',
    ];

    /**
     * @var array<string, array{fields: list<string>, bounds: array<string, int>, enums: array<string, array{members: list<string>, unknown: ?string, array: bool}>}>
     */
    public const KINDS = [
        // ── § 6.1 ────────────────────────────────────────────────────────────────────────────
        'session.start' => [
            'fields' => ['source', 'project_label', 'harness_label', 'previous_session_id'],
            'bounds' => [
                'project_label' => 48,
                'harness_label' => 32,
                'previous_session_id' => 128,
            ],
            'enums' => [
                'source' => [
                    'members' => ['startup', 'resume', 'clear', 'compact', 'fork', 'unknown'],
                    'unknown' => 'unknown',
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.2 ────────────────────────────────────────────────────────────────────────────
        'session.end' => [
            'fields' => ['end_reason', 'duration_ms', 'turns', 'aborted_calls'],
            'bounds' => [],
            'enums' => [
                'end_reason' => [
                    'members' => ['clear', 'resume', 'logout', 'prompt_input_exit', 'other', 'inferred_silence'],
                    'unknown' => 'other',
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.3 ────────────────────────────────────────────────────────────────────────────
        'turn.start' => [
            'fields' => ['prompt_chars', 'project_label'],
            'bounds' => [
                'project_label' => 48,
            ],
            'enums' => [],
        ],

        // ── § 6.4 ────────────────────────────────────────────────────────────────────────────
        'turn.end' => [
            'fields' => [
                'end_reason', 'api_error_type', 'duration_ms', 'open_calls_at_end',
                'aborted_call_ids', 'stop_hook_active', 'background_tasks_open',
                'tool_calls', 'failed_calls',
            ],
            'bounds' => [],
            'enums' => [
                'end_reason' => [
                    'members' => ['stop_hook', 'api_error', 'session_cleared', 'session_ended'],
                    'unknown' => null,          // reporter-minted
                    'array' => false,
                ],
                // Harness-sourced from `StopFailure.error`. Its unknown member is `unrecognised`
                // and NOT `unknown`, because the harness's own set already contains a literal
                // `unknown`: coercing to it would make "the API said unknown" and "we did not
                // recognise what the API said" one wire value (§ 6.0).
                'api_error_type' => [
                    'members' => [
                        'rate_limit', 'overloaded', 'server_error', 'authentication_failed',
                        'billing_error', 'invalid_request', 'model_not_found', 'max_output_tokens',
                        'oauth_org_not_allowed', 'account_on_hold', 'unknown', 'unrecognised',
                    ],
                    'unknown' => 'unrecognised',
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.5 ────────────────────────────────────────────────────────────────────────────
        'tool.start' => [
            'fields' => [
                'call_id', 'tool_name', 'descriptor', 'descriptor_truncated', 'agent_scope',
                'parent_call_id', 'harness_call_ref', 'open_calls_before',
                // `synthesized` is mandated by § 6.6's synthesized-pair rule and was missing from
                // § 6.5's own field table until this card added it. See the PR round.
                'synthesized',
            ],
            'bounds' => [
                'tool_name' => 64,
                'descriptor' => 200,
                'harness_call_ref' => 64,
            ],
            'enums' => [
                'agent_scope' => [
                    'members' => ['main', 'subagent'],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.6 ────────────────────────────────────────────────────────────────────────────
        'tool.end' => [
            'fields' => [
                'call_id', 'tool_name', 'outcome', 'abort_reason', 'duration_ms',
                'duration_source', 'close_source', 'match',
            ],
            'bounds' => [
                'tool_name' => 64,
            ],
            'enums' => [
                'outcome' => [
                    'members' => ['completed', 'failed', 'aborted'],
                    'unknown' => null,
                    'array' => false,
                ],
                // D1's SIX members only. `docs/design/FLEET-STATE.md § 6.4` adds three more
                // (`orphan_timeout`, `seat_offline`, `session_close`) to the STORE column and
                // enumerates them precisely so an implementer validating D1's closed enums at the
                // ingest has a list: they are server-side vocabulary and "nothing here is ever
                // emitted", so a seat sending one is a reporter bug and takes the 422.
                'abort_reason' => [
                    'members' => [
                        'session_cleared', 'session_ended', 'turn_boundary', 'api_error',
                        'interrupted', 'reporter_restart',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
                'duration_source' => [
                    'members' => ['harness', 'index', 'none'],
                    'unknown' => null,
                    'array' => false,
                ],
                // Again D1's set only; the store's `server_orphan`, `server_offline` and
                // `server_session_close` never cross the wire.
                'close_source' => [
                    'members' => [
                        'post_tool_use', 'post_tool_use_failure', 'reap_session_boundary',
                        'reap_turn_boundary', 'reap_reporter_restart', 'subagent_stop_hook',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
                'match' => [
                    'members' => [
                        'harness_ref', 'sole_open', 'lifo_tool_name', 'agent_id',
                        'tombstone_ref', 'synthesized', 'reap',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.7 ────────────────────────────────────────────────────────────────────────────
        'subagent.spawn' => [
            'fields' => ['call_id', 'title', 'title_truncated', 'subagent_type'],
            'bounds' => [
                'title' => 120,
                'subagent_type' => 32,
            ],
            'enums' => [],
        ],

        // ── § 6.8 — every enum here is § 6.6's, by reference and never restated there ────────
        'subagent.stop' => [
            'fields' => ['call_id', 'outcome', 'abort_reason', 'duration_ms', 'close_source'],
            'bounds' => [],
            'enums' => [
                'outcome' => [
                    'members' => ['completed', 'failed', 'aborted'],
                    'unknown' => null,
                    'array' => false,
                ],
                'abort_reason' => [
                    'members' => [
                        'session_cleared', 'session_ended', 'turn_boundary', 'api_error',
                        'interrupted', 'reporter_restart',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
                'close_source' => [
                    'members' => [
                        'post_tool_use', 'post_tool_use_failure', 'reap_session_boundary',
                        'reap_turn_boundary', 'reap_reporter_restart', 'subagent_stop_hook',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.9 ────────────────────────────────────────────────────────────────────────────
        'compaction.start' => [
            'fields' => ['trigger', 'context_used_pct', 'context_used_pct_age_s', 'open_calls'],
            'bounds' => [],
            'enums' => [
                'trigger' => [
                    'members' => ['auto', 'manual', 'unknown'],
                    'unknown' => 'unknown',
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.10 — a DIFFERENT set of three from § 6.6's close_source ─────────────────────
        'compaction.end' => [
            'fields' => ['duration_ms', 'close_source'],
            'bounds' => [],
            'enums' => [
                'close_source' => [
                    'members' => ['post_compact', 'session_start_compact', 'timeout'],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.11 ───────────────────────────────────────────────────────────────────────────
        'context.sample' => [
            'fields' => [
                'used_pct', 'used_tokens', 'total_tokens', 'used_pct_source',
                'model_label', 'sample_reason',
            ],
            'bounds' => [
                'model_label' => 48,
            ],
            'enums' => [
                'used_pct_source' => [
                    'members' => ['harness', 'computed'],
                    'unknown' => null,
                    'array' => false,
                ],
                'sample_reason' => [
                    'members' => ['cadence', 'threshold_cross', 'first_of_session'],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.12 ───────────────────────────────────────────────────────────────────────────
        'attention.request' => [
            'fields' => ['request_id', 'source', 'notification_kind', 'call_id', 'open_calls'],
            'bounds' => [],
            'enums' => [
                'source' => [
                    'members' => ['permission_request_hook', 'notification_hook'],
                    'unknown' => null,
                    'array' => false,
                ],
                // Reporter-minted, three members, no unknown member and no fourth: § 6.12 deletes
                // `other` as structurally unreachable because the emission gate means every
                // surviving member IS a wait on a human.
                'notification_kind' => [
                    'members' => ['permission_required', 'input_awaited', 'elicitation'],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.13 ───────────────────────────────────────────────────────────────────────────
        'attention.resolved' => [
            'fields' => ['request_id', 'resolution', 'resolution_source', 'waited_ms'],
            'bounds' => [],
            'enums' => [
                'resolution' => [
                    'members' => ['granted', 'denied', 'human_input', 'session_ended', 'timeout'],
                    'unknown' => null,
                    'array' => false,
                ],
                'resolution_source' => [
                    'members' => [
                        'permission_denied_hook', 'call_close', 'user_prompt_submit',
                        'session_end', 'timeout',
                    ],
                    'unknown' => null,
                    'array' => false,
                ],
            ],
        ],

        // ── § 6.14 ───────────────────────────────────────────────────────────────────────────
        'reporter.heartbeat' => [
            'fields' => [
                'uptime_s', 'spool_bytes', 'spool_files', 'spool_lag_events',
                'oldest_unsent_age_s', 'last_hook_at', 'open_calls', 'open_sessions',
                'open_attention', 'enabled', 'degraded', 'counters', 'counters_omitted',
                'predicates', 'selftest', 'config_fingerprint',
            ],
            'bounds' => [
                'counters' => 1536,
                'predicates' => 512,
                'selftest' => 256,
            ],
            'enums' => [
                // § 9.3's twelve-member table IS this field's value set, declared there and
                // nowhere else. § 9.3 states plainly what a wrong set costs here: "a guess that
                // misses makes a *degraded* seat's heartbeat a `422 invalid_event` … the liveness
                // backstop dying at the moment the seat becomes interesting". Reporter-minted,
                // no unknown member — so this is one of the few places a 422 is the right answer,
                // and one of the few places getting the set wrong is unrecoverable.
                'degraded' => [
                    'members' => [
                        'lossy', 'batches_rejected', 'harness_contract_moved', 'reporter_behind',
                        'value_clamped', 'counters_omitted', 'index_overflow', 'invalid_tool_name',
                        'bad_session_id', 'config_invalid', 'statusline_degraded', 'epoch_reset',
                    ],
                    'unknown' => null,
                    'array' => true,
                ],
            ],
        ],
    ];

    public static function knows(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }
}
