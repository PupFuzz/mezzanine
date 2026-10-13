<?php

namespace App\Compose;

use Illuminate\Http\JsonResponse;

/**
 * One refused compose — `docs/design/BOARD-TASK.md § 15.11`, whose table owns every class, its status
 * and the sentence the panel renders for it. This class carries the class and exactly the members a
 * row substitutes, and builds the `desk.compose` response body from them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THERE IS NO MESSAGE PARAMETER, on `BoardPollFailed`'s model and for its reason (§ 5.1): an
 * exception that took free text is the channel through which a transport exception's message (which
 * names the URL) or a response excerpt reaches a log line or the panel. The client renders the
 * sentence from the class; the server sends none.
 *
 * ⛔ THE STATUS IS THE CLASS's, ONE PER MEANING, never a caller's choice — so a class cannot be
 * answered at a status the client would read as another row.
 */
final class ComposeRefused extends \RuntimeException
{
    public const UNCONFIGURED = 'unconfigured';

    public const CREDENTIAL_SHARED = 'credential_shared';

    public const TEXT = 'text';

    public const EXPECTED_REF = 'expected_ref';

    public const NO_TARGET = 'no_target';

    public const NO_CHANNEL_FOR_AGENT = 'no_channel_for_agent';

    public const TARGET_MOVED = 'target_moved';

    public const TARGET_UNREAD = 'target_unread';

    public const AUTH = 'auth';

    public const STATUS = 'status';

    public const TRANSPORT = 'transport';

    public const UNVERIFIED = 'unverified';

    /** § 15.11's closed class set, each at its one status. */
    public const STATUSES = [
        self::UNCONFIGURED => 503,
        self::CREDENTIAL_SHARED => 503,
        self::TEXT => 422,
        self::EXPECTED_REF => 422,
        self::NO_TARGET => 422,
        self::NO_CHANNEL_FOR_AGENT => 422,
        self::TARGET_MOVED => 409,
        self::TARGET_UNREAD => 502,
        self::AUTH => 502,
        self::STATUS => 502,
        self::TRANSPORT => 502,
        self::UNVERIFIED => 502,
    ];

    /** The response status this refusal is answered with. */
    public readonly int $status;

    /**
     * @param  string|null  $ref  `target_moved`: the label resolved or the live winner; `no_channel_for_agent`: the declared name
     * @param  string|null  $moved  `target_moved`: `desk` (step C) or `board` (step D)
     */
    public function __construct(
        public readonly string $class,
        public readonly ?string $redactedUrl = null,
        public readonly ?string $ref = null,
        public readonly ?int $boardStatus = null,
        public readonly ?string $key = null,
        public readonly ?string $moved = null,
        public readonly ?int $maxBytes = null,
        public readonly ?string $taskSource = null,
        public readonly ?string $nameCheck = null,
    ) {
        $this->status = self::STATUSES[$class] ?? throw new \LogicException('no § 15.11 class named '.$class);

        parent::__construct(sprintf('compose refused: class=%s', $class));
    }

    /** § 15.11's response body: the class and the members its row substitutes — nothing else. */
    public function response(): JsonResponse
    {
        return new JsonResponse(array_filter([
            'class' => $this->class,
            'ref' => $this->ref,
            'board_status' => $this->boardStatus,
            'key' => $this->key,
            'moved' => $this->moved,
            'max_bytes' => $this->maxBytes,
            'task_source' => $this->taskSource,
            'name_check' => $this->nameCheck,
        ], fn ($v) => $v !== null), $this->status);
    }
}
