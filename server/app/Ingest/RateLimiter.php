<?php

namespace App\Ingest;

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
        // The window index is part of the key, so an expired window cannot be resurrected by a
        // TTL that outlived it and every window starts from zero without a sweeper.
        //
        // `now()` rather than `time()`, for the same reason `IngestPipeline` stamps `received_at`
        // from the application clock: one clock per request. A window index taken from PHP's
        // clock is one no test can advance, which would make "the limit releases after its
        // window" a property nothing ever observed.
        $windowed = sprintf('%s:%d', $key, intdiv(now()->getTimestamp(), $windowS));

        $this->cache->add($windowed, 0, $windowS * 2);

        if ($by === 0) {
            return (int) $this->cache->get($windowed, 0);
        }

        $total = $this->cache->increment($windowed, $by);

        // `increment` returns false on a store that lost the key between `add` and `increment`.
        // Treating that as "no hit recorded" would silently disable the limit, so it is read
        // back instead — and a store that cannot even do that is a broken limit, which is what
        // the `false` here would surface as a `0` and a rising counter rather than a silent pass.
        return is_int($total) ? $total : (int) $this->cache->get($windowed, $by);
    }
}
