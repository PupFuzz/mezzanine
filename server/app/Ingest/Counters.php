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
    /** Refusals at validation steps 1–3, before any identity is established. Global ONLY. */
    public const UNATTRIBUTED_REFUSALS = 'unattributed_refusals';

    /** A token that resolves to nothing. Global; it degrades no seat, because the token named none. */
    public const AUTH_FAILED_BY_IP = 'auth_failed_by_ip';

    /** A token that resolves to a REVOKED row — a real signal with a real owner. Operator alert. */
    public const REVOKED_TOKEN_PRESENTED = 'revoked_token_presented';

    /** D2 § 7.2: an `mzr_` read token presented to the ingest. Operator alert. */
    public const TOKEN_WRONG_SURFACE = 'token_wrong_surface';

    /**
     * Increment a per-seat counter. `$seatRef` MUST come from a token binding.
     */
    public static function seat(int $seatRef, string $name, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }

        self::upsert('seat_counters', ['seat_ref' => $seatRef, 'name' => $name], $by);
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

        self::upsert('global_counters', ['name' => $name], $by);
    }

    /**
     * `batches_refused.<error>`, keyed by error code, counted against the token's binding.
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
     * One statement, no read-modify-write, so concurrent ingest requests for one seat cannot lose
     * an increment. The two dialects differ only in the conflict clause; the increment is bound
     * rather than expressed through `VALUES(value)` (MySQL) or `excluded.value` (SQLite) so that
     * one statement shape serves the § 6.1 version floor and everything above it.
     *
     * @param  array<string, mixed>  $key
     */
    private static function upsert(string $table, array $key, int $by): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $columns = array_keys($key) + [];
        $conflict = implode(', ', $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s, value, updated_at) VALUES (%s, ?, ?)',
            $table,
            $conflict,
            implode(', ', array_fill(0, count($columns), '?')),
        );

        $bindings = array_values($key);
        $bindings[] = $by;
        $bindings[] = $now;

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $sql .= ' ON DUPLICATE KEY UPDATE value = value + ?, updated_at = ?';
        } else {
            $sql .= sprintf(' ON CONFLICT (%s) DO UPDATE SET value = value + ?, updated_at = ?', $conflict);
        }

        $bindings[] = $by;
        $bindings[] = $now;

        DB::statement($sql, $bindings);
    }
}
