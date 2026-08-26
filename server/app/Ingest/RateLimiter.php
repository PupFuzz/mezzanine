<?php

namespace App\Ingest;

use App\Support\FixedWindow;
use Illuminate\Contracts\Cache\Repository;

/**
 * D1 § 12.3's limits. Three of the four live here; the failed-authentication one lives in
 * `TokenResolver` because § 12.1 evaluates it inside step 4 — see that class for why moving it
 * back beside these would make it unfireable.
 *
 * | Limit                  | Keyed on       | Value           | Over-limit                 |
 * |------------------------|----------------|-----------------|----------------------------|
 * | Requests               | token binding  | 120 / minute    | 429, `retry_after_s: 30`   |
 * | Events                 | token binding  | 20,000 / hour   | 429, `retry_after_s: 60`   |
 * | Body size              | —              | 256 KiB         | 413  (step 2, not step 5)  |
 * | Failed authentications | source IP      | 60 / hour       | 429  (step 4, TokenResolver)|
 *
 * The `retry_after_s` values are D1's literals, not a computed time-to-window-edge. A computed
 * one would be a second, undeclared number on a surface the reporter clamps and honours
 * (§ 11.5), and D1 states these two outright.
 *
 * FIXED WINDOWS, deliberately. A fixed window admits up to 2× the limit across a window boundary,
 * which for these numbers means 240 requests or 40,000 events in one pathological minute or hour.
 * That is acceptable because of what the numbers are FOR: § 12.3 derives 120/min as "20× headroom
 * — it can only be reached by a spin loop", and 20,000/hour as "~46× headroom" that "bounds one
 * runaway seat's storage to ~10 MB/hour". A 2× boundary effect on a 20–46× headroom does not
 * change what either limit catches, and a sliding window costs a per-request sorted-set write on
 * the request path § 6.1 budgets one query for.
 */
final class RateLimiter
{
    public const REQUESTS_LIMIT = 120;

    public const REQUESTS_WINDOW_S = 60;

    public const REQUESTS_RETRY_AFTER_S = 30;

    public const EVENTS_LIMIT = 20000;

    public const EVENTS_WINDOW_S = 3600;

    public const EVENTS_RETRY_AFTER_S = 60;

    public function __construct(private readonly Repository $cache) {}

    /**
     * § 12.1 step 5 — every limit except the failed-authentication one, which step 4 owns.
     *
     * `$claimedEventCount` is the number of elements the batch's `events` array claims, read
     * WITHOUT validating it: step 8 is where `events` becomes valid or not, and reading a count
     * here does not reorder that. A batch whose `events` is not an array contributes 0 to the
     * hour and is refused at step 8 on its own merits.
     */
    public function check(TokenBinding $binding, int $claimedEventCount): ?Refusal
    {
        $requests = $this->hit(sprintf('ingest:req:%d', $binding->seatRef), self::REQUESTS_WINDOW_S, 1);

        if ($requests > self::REQUESTS_LIMIT) {
            return Refusal::rateLimited(
                self::REQUESTS_RETRY_AFTER_S,
                self::REQUESTS_LIMIT,
                self::REQUESTS_WINDOW_S,
                'requests for this seat',
            );
        }

        $events = $this->hit(
            sprintf('ingest:ev:%d', $binding->seatRef),
            self::EVENTS_WINDOW_S,
            max(0, $claimedEventCount),
        );

        if ($events > self::EVENTS_LIMIT) {
            return Refusal::rateLimited(
                self::EVENTS_RETRY_AFTER_S,
                self::EVENTS_LIMIT,
                self::EVENTS_WINDOW_S,
                'events for this seat',
            );
        }

        return null;
    }

    public function hitFailedAuth(string $ip): int
    {
        return $this->hit(
            'ingest:authfail:'.hash('sha256', $ip),
            TokenResolver::FAILED_AUTH_WINDOW_S,
            1,
        );
    }

    /**
     * @return int the window's running total INCLUDING this hit
     */
    private function hit(string $key, int $windowS, int $by): int
    {
        // `App\Support\FixedWindow` is the one home of the window-index mechanism; the read
        // plane (`docs/design/FLEET-STATE.md` § 9) needs the identical one with different
        // numbers, and two copies would be two chances to lose the property that a window
        // releases and that a test can advance it.
        return FixedWindow::hit($this->cache, $key, $windowS, $by);
    }
}
