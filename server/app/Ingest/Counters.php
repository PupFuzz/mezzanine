<?php

namespace App\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * D1 § 12.7's counters, stored where `docs/design/FLEET-STATE.md § 7.1` puts them.
 *
 * The project's standing rule — nothing is discarded uncounted — is what makes this class
 * unconditional rather than best-effort: every rejection, every drop and every rate-limit
 * refusal increments something reachable, and the two tables are the reachable place. D2 § 7.2
 * adds that neither table is ever reset, "because a monotonic counter whose baseline moves is a
 * counter no rate can be computed from".
 *
 * ATTRIBUTION IS THE LOAD-BEARING PART, NOT THE ARITHMETIC. D1 § 12.1's D2 rule: every refusal is
 * attributed to the TOKEN, never to the body. `seat()` therefore takes a `$seatRef` that callers
 * may only ever have obtained from a resolved token binding, and the three counters whose refusals
 * happen before any identity exists — `unattributed_refusals`, `auth_failed_by_ip`,
 * `revoked_token_presented` — have no seat-scoped form at all. Without that, any holder of any
 * valid token could post a bogus `schema_version` naming a colleague's seat and render that desk
 * degraded on the floor.
 */
final class Counters
{
    /**
     * Refusals at validation steps 1–4, before any identity is established — steps 1–3 through
     * `batchRefused(null, …)`, and every step-4 refusal (a token that resolves to nothing, or to a
     * revoked row) counted by its caller beside the specific fact `TokenResolver` records. Global ONLY.
     */
    public const UNATTRIBUTED_REFUSALS = 'unattributed_refusals';

    /** A token that resolves to nothing. Global; it degrades no seat, because the token named none. */
    public const AUTH_FAILED_BY_IP = 'auth_failed_by_ip';

    /** A token that resolves to a REVOKED row — a real signal with a real owner. Operator alert. */
    public const REVOKED_TOKEN_PRESENTED = 'revoked_token_presented';

    /** D2 § 7.2: an `mzr_` read token presented to the ingest. Operator alert. */
    public const TOKEN_WRONG_SURFACE = 'token_wrong_surface';

    /**
     * Increment a per-seat counter. `$seatRef` MUST come from a token binding.
     *
     * `$at` is when the evidence for the increment was RECEIVED, on the server clock, and it moves
     * the row's `last_increased_at` — the time `Badges::serverFor()` windows a counter-derived badge
     * against (D2 § 7.2, card#9491). It defaults to now, which is the receipt for a counter the
     * ingest or the sweeper writes. The fold passes the event's own `received_at`, because the fold
     * runs after the receipt and a rebuild replays it days later: stamped with `now()`, a rebuild
     * would re-date every historical `seq_gap` to the rebuild and re-raise a badge the live fold
     * had already cleared, which is the rebuild disagreeing with the fold (AT-D2-10). The column
     * only ever moves forward, so a replayed receipt older than the stored one changes nothing.
     */
    public static function seat(int $seatRef, string $name, int $by = 1, ?string $at = null): void
    {
        if ($by === 0) {
            return;
        }

        self::upsert('seat_counters', ['seat_ref' => $seatRef, 'name' => $name], $by, $at ?? now()->format('Y-m-d H:i:s.v'));
    }

    /**
     * @param  array<string, int>  $increments  name => delta
     */
    public static function seatMany(int $seatRef, array $increments): void
    {
        foreach ($increments as $name => $by) {
            self::seat($seatRef, $name, $by);
        }
    }

    public static function global(string $name, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }

        self::upsert('global_counters', ['name' => $name], $by, null);
    }

    /** D1 § 12.7's `batches_failed.<detail>`, completed by a `ServerFault`'s value. */
    public const BATCHES_FAILED = 'batches_failed.';

    /**
     * `batches_refused.<error>`, keyed by error code, counted against the token's binding. A 4xx
     * refusal only: a request the server could not finish is `batchFailed()`.
     *
     * D2 § 7.1 gives this counter NO badge, and says why: the reporter observes the same refusal
     * from the other side and raises D1 § 9.3's `batches_rejected` from its own counter. "A second
     * badge for one condition would be a second home for one fact, and the two counts disagreeing
     * is itself the signal."
     */
    public static function batchRefused(?int $seatRef, string $errorCode): void
    {
        if ($seatRef === null) {
            // Steps 1–3: no identity, so no seat may be named. This is the whole reason the
            // parameter is nullable rather than the callers each remembering to branch.
            self::global(self::UNATTRIBUTED_REFUSALS);

            return;
        }

        self::seat($seatRef, 'batches_refused.'.$errorCode);
    }

    /**
     * `batches_failed.<detail>`: a request the ingest could not finish, answered `server_error`
     * (D1 § 12.2), keyed by its fault class (card#9465).
     *
     * NOT A REFUSAL, so neither `batches_refused.<error>` nor `unattributed_refusals`. The reporter
     * retries every `5xx` and counts that in its own `batches_retried`, and a retry commits once; a
     * refusal counter would put a batch the retry stored into the floor's `batches_rejected`
     * drill-down, and make the reporter/server disagreement D2 § 7.1 reads as a signal routine.
     *
     * Attribution is D1 § 12.1's all the same: against the token's binding once step 4 has resolved
     * one, and under the same key in `global_counters` before it, because no seat may be named yet.
     */
    public static function batchFailed(?int $seatRef, ServerFault $fault): void
    {
        $name = self::BATCHES_FAILED.$fault->value;

        if ($seatRef === null) {
            self::global($name);

            return;
        }

        self::seat($seatRef, $name);
    }

    /**
     * One statement, no read-modify-write, so concurrent ingest requests for one seat cannot lose
     * an increment. The two dialects differ only in the conflict clause; the increment is bound
     * rather than expressed through `VALUES(value)` (MySQL) or `excluded.value` (SQLite) so that
     * one statement shape serves the § 6.1 version floor and everything above it.
     *
     * `$increasedAt` is `seat_counters.last_increased_at`, and `null` for `global_counters`, which
     * has no such column because no badge is windowed on a fleet counter. It is the LATER of the
     * stored value and the one bound, in the same statement, so two writers racing on one row
     * cannot move it backwards. A stored NULL (a row an older build inserted after a rollback, see
     * the column's migration) takes the bound value.
     *
     * @param  array<string, mixed>  $key
     */
    private static function upsert(string $table, array $key, int $by, ?string $increasedAt): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $columns = array_keys($key) + [];
        $conflict = implode(', ', $columns);
        $stamped = $increasedAt !== null;

        $sql = sprintf(
            'INSERT INTO %s (%s, value, updated_at%s) VALUES (%s, ?, ?%s)',
            $table,
            $conflict,
            $stamped ? ', last_increased_at' : '',
            implode(', ', array_fill(0, count($columns), '?')),
            $stamped ? ', ?' : '',
        );

        $bindings = array_values($key);
        $bindings[] = $by;
        $bindings[] = $now;

        if ($stamped) {
            $bindings[] = $increasedAt;
        }

        $driver = DB::connection()->getDriverName();
        $set = 'value = value + ?, updated_at = ?'.($stamped
            ? ', last_increased_at = CASE WHEN last_increased_at IS NULL OR last_increased_at < ? THEN ? ELSE last_increased_at END'
            : '');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $sql .= ' ON DUPLICATE KEY UPDATE '.$set;
        } else {
            $sql .= sprintf(' ON CONFLICT (%s) DO UPDATE SET %s', $conflict, $set);
        }

        $bindings[] = $by;
        $bindings[] = $now;

        if ($stamped) {
            $bindings[] = $increasedAt;
            $bindings[] = $increasedAt;
        }

        DB::statement($sql, $bindings);
    }
}
