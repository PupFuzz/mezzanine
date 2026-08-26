<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * A fixed-window counter in the cache — the one mechanism behind every request limit in this
 * application: D1 § 12.3's four ingest limits (`App\Ingest\RateLimiter`) and
 * `docs/design/FLEET-STATE.md § 9`'s two read-plane limits (`App\Http\Middleware\FleetReadGate`).
 *
 * ⚠ EXTRACTED AT THE SECOND CALLER. The read plane needs the identical mechanism with different
 * numbers, and the window-index trick below is subtle enough that a second hand-written copy is
 * a second chance to get it wrong in a way no test distinguishes — a limit that silently never
 * releases, or one a test can never advance.
 */
final class FixedWindow
{
    /**
     * Add `$by` to the counter for `$key`'s current window and return the window's new total.
     *
     * THE WINDOW INDEX IS PART OF THE KEY, so an expired window cannot be resurrected by a TTL
     * that outlived it and every window starts from zero without a sweeper.
     *
     * `now()` rather than `time()`, for the same reason `IngestPipeline` stamps `received_at`
     * from the application clock: one clock per request. A window index taken from PHP's clock is
     * one no test can advance, which would make "the limit releases after its window" a property
     * nothing ever observed.
     */
    public static function hit(Repository $cache, string $key, int $windowS, int $by): int
    {
        $windowed = sprintf('%s:%d', $key, intdiv(now()->getTimestamp(), $windowS));

        $cache->add($windowed, 0, $windowS * 2);

        if ($by === 0) {
            return (int) $cache->get($windowed, 0);
        }

        $total = $cache->increment($windowed, $by);

        // `increment` returns false on a store that lost the key between `add` and `increment`.
        // Treating that as "no hit recorded" would silently disable the limit, so it is read back
        // instead — and a store that cannot even do that is a broken limit, which is what the
        // `false` here would surface as a `0` and a rising counter rather than a silent pass.
        return is_int($total) ? $total : (int) $cache->get($windowed, $by);
    }
}
