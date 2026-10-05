<?php

namespace App\Board;

/**
 * One degraded poll — `docs/design/BOARD-TASK.md § 6.3` / § 9 — carrying exactly what § 5.1 lets
 * reach a log line: the failure CLASS, the board id, the HTTP status, and the URL with its
 * userinfo already redacted. Never a response body, never a request header.
 *
 * ⛔ THERE IS NO MESSAGE PARAMETER, deliberately. An exception that took a free-text message is
 * the channel through which a caller passes `$e->getMessage()` of a transport exception — which
 * names the URL — or a response excerpt, and § 5.1's whole rule is that the question is *what does
 * this resolve*. The message this class builds is made of the four fields below and nothing else.
 */
final class BoardPollFailed extends \RuntimeException
{
    /** The closed set of classes a degraded poll is reported under. */
    public const CONFIG = 'config';

    public const CREDENTIAL = 'credential';

    public const AUTH = 'auth';

    public const STATUS = 'status';

    public const TRANSPORT = 'transport';

    public const SHAPE = 'shape';

    public const PAGINATION = 'pagination';

    public const STORE = 'store';

    public function __construct(
        public readonly string $class,
        public readonly ?int $boardId = null,
        public readonly ?int $status = null,
        public readonly ?string $redactedUrl = null,
    ) {
        parent::__construct(sprintf(
            'board poll degraded: class=%s board=%s',
            $class,
            $boardId === null ? '-' : (string) $boardId,
        ));
    }
}
