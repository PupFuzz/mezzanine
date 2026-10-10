<?php

namespace App\Board;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The board's HTTP client, shared by its two callers — `docs/design/BOARD-TASK.md § 15.7`'s
 * "shared client": the poller's read (`CandidateRead`, through `BoardPoll`) and the compose path's
 * one write (`App\Compose\BoardCommentChannel`). Hoisted out of `BoardPoll` at the second real
 * caller rather than written twice (canon #5): the base rule, the token's shape rule, the request
 * builder and the redaction are each stated once, here, for both tokens.
 *
 * ⛔ § 5.1 HOLDS FOR BOTH TOKENS, AND THIS CLASS IS WHERE IT HOLDS. The token is a HEADER and never a
 * query parameter; redirects are not followed (a followed redirect is a request to a host nobody
 * configured carrying a bearer token); every URL a log line carries goes through `redact()`. A
 * caller that needs a token in a URL has no way to say so here, which is the point.
 *
 * It decides no failure class. `base()` and `token()` answer `null` for a value that cannot be used,
 * and each caller names the refusal in its own vocabulary — the poller's `BoardPollFailed`, the
 * compose path's `ComposeRefused` — because the two paths refuse the same defect for different
 * readers (a degraded poll; a sentence in front of an operator).
 */
final class BoardHttp
{
    /** § 12 — per request, connect and read. One figure for every request either caller sends. */
    public const TIMEOUT_S = 20;

    /**
     * § 5: `BOARD_API_BASE` is HTTPS with a host and no trailing slash, or it is unusable — a token
     * sent over plain HTTP is a token sent to anyone on the path.
     */
    public static function base(mixed $base): ?string
    {
        if (! is_string($base) || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || (string) parse_url($base, PHP_URL_HOST) === '' || str_ends_with($base, '/')) {
            return null;
        }

        return $base;
    }

    /** A bearer token is visible ASCII or it is unusable: anything else cannot ride a header verbatim. */
    public static function token(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[\x21-\x7E]+$/', $token) === 1 ? $token : null;
    }

    /** The one request builder: the token as a header, JSON accepted, § 12's timeouts, no redirects. */
    public static function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(self::TIMEOUT_S)
            ->timeout(self::TIMEOUT_S)
            ->withoutRedirecting();
    }

    /**
     * A URL with its userinfo replaced — § 5.1: a base URL is allowed to carry one, and a logged
     * URL is the easiest accidental sink there is.
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1[redacted]@', $url);
    }
}
