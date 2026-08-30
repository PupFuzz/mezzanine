<?php

namespace App\Read;

use App\Fold\Clock;
use App\Ingest\Counters;
use App\Ingest\TokenResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 9`'s machine-consumer credential: `Authorization: Bearer mzr_…`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ROW IS READ PER REQUEST AND NEVER CACHED, AND THAT IS THE WHOLE OF AT-D2-19's FIRST RED.
 *
 * § 9: revocation is "checked PER REQUEST, never cached — a revoked credential that keeps working
 * for a cache TTL is a revocation that did not happen". AT-D2-19 drives it: "cache the token row
 * for 60 s → a revoked credential keeps reading the fleet for a minute". There is therefore no
 * memoisation in this class, not even within one request, and the absence is load-bearing rather
 * than an omission — a `static $row` here would pass every test in the suite and fail the one
 * property the section names.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE PREFIXES COME FROM `App\Ingest\TokenResolver`, WHICH ALREADY DECLARED BOTH.
 *
 * That class has carried `INGEST_PREFIX`/`READ_PREFIX` since card #7338 and already counts
 * `token_wrong_surface` for the mirror case (an `mzr_` read token presented to the INGEST). This
 * is the other direction of the same rule, and it reads the same two constants rather than
 * minting a second pair — the distinction between the prefixes is precisely "what makes
 * `token_wrong_surface` detectable rather than a mystery `401`" (§ 9), so two copies of it free
 * to drift would be a defect in the instrument itself.
 *
 * ⚠ NO FALLBACK ON A STORE FAILURE. § 2.2: read-token verification with the token store
 * unreachable is CLOSED — "`503`, never a cached or assumed grant. A read token gates the whole
 * fleet's activity picture. There is no posture in which 'we could not check, so we allowed it'
 * is correct." A `QueryException` therefore propagates out of here to the gate, which turns it
 * into `fleet_unavailable`; it is never caught into an allow.
 */
final class ReadTokens
{
    /** § 9: 90 days — "rotation is quarterly rather than constant … a forgotten token dies". */
    public const LIFETIME_DAYS = 90;

    /**
     * § 9's one scope. There is deliberately NO runtime check that a resolved row carries it:
     * `feed_tokens.scope` is an ENUM with this as its only member, so the store is the guard and a
     * branch here would be defending a state the schema cannot produce. The day § 9 gains a
     * second scope, the check arrives with it.
     */
    public const SCOPE = 'fleet_read';

    /**
     * Resolve a presented bearer credential.
     *
     * @return ReadGrant|ReadRefusal|null null when NO bearer credential was presented at all —
     *                                    the one case that may fall through to the session path
     */
    public static function resolve(Request $request): ReadGrant|ReadRefusal|null
    {
        $presented = self::bearer($request);

        if ($presented === null) {
            return null;
        }

        // § 9's wrong-surface rule, and it is checked BEFORE the hash lookup on purpose: an
        // ingest token is a real credential that simply does not belong here, so answering it
        // with the generic "never existed" refusal would hide a live misconfiguration behind the
        // same 401 as a probe. § 7.2 marks this counter **operator alert** for that reason — it
        // "is either a misconfiguration that will otherwise present as a mysterious dark seat, or
        // a probe", and both want naming.
        if (str_starts_with($presented, TokenResolver::INGEST_PREFIX)) {
            Counters::global(Counters::TOKEN_WRONG_SURFACE);
            Log::warning('mezzanine.read: an INGEST token (mzn_) was presented to a read endpoint', [
                'source_ip' => (string) $request->ip(),
            ]);

            return ReadRefusal::tokenWrongSurface();
        }

        $row = DB::table('feed_tokens')
            ->where('token_hash', hash('sha256', $presented))
            ->first(['id', 'prefix', 'name', 'scope', 'expires_at', 'revoked_at']);

        if ($row === null) {
            Counters::global(Counters::AUTH_FAILED_BY_IP);

            return ReadRefusal::unauthenticated();
        }

        // REVOKED IS TESTED BEFORE EXPIRED, and the order is a decision rather than an accident:
        // a token can be both, and an operator who revoked a credential wants to see that they
        // revoked it, not that it would have aged out anyway.
        if ($row->revoked_at !== null) {
            Counters::global(Counters::REVOKED_TOKEN_PRESENTED);
            Log::warning('mezzanine.read: a REVOKED fleet_read token was presented', [
                'token_prefix' => $row->prefix,
                'source_ip' => (string) $request->ip(),
            ]);

            // § 8.6: `last_used_at` / `last_used_ip` are NOT touched here, "so a revoked row
            // cannot be made to look live".
            return ReadRefusal::tokenRevoked((string) $row->revoked_at);
        }

        if (Clock::toMs((string) $row->expires_at) <= Clock::toMs(Clock::sql(now()))) {
            return ReadRefusal::tokenExpired((string) $row->expires_at);
        }

        self::touch((int) $row->id, (string) $request->ip());

        return new ReadGrant((int) $row->id, (string) $row->prefix, (string) $row->name);
    }

    /** Mint a `mzr_` credential. Returns the PLAINTEXT — the only moment it exists outside a seat. */
    public static function issue(string $name, string $createdBy): string
    {
        $token = TokenResolver::READ_PREFIX
            .rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('feed_tokens')->insert([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 12),
            'scope' => self::SCOPE,
            'created_at' => Clock::sql(now()),
            'created_by' => $createdBy,
            'expires_at' => Clock::sql(now()->copy()->addDays(self::LIFETIME_DAYS)),
        ]);

        return $token;
    }

    private static function bearer(Request $request): ?string
    {
        if (! preg_match('/^Bearer\s+(\S+)$/', (string) $request->header('Authorization', ''), $m)) {
            return null;
        }

        return $m[1];
    }

    private static function touch(int $tokenId, string $ip): void
    {
        try {
            DB::table('feed_tokens')->where('id', $tokenId)->update([
                'last_used_at' => Clock::sql(now()),
                'last_used_ip' => @inet_pton($ip) ?: null,
            ]);
        } catch (\Throwable $e) {
            // The same posture `App\Ingest\TokenResolver::touch()` takes: bookkeeping that fails
            // must not refuse a request that authenticated. This is NOT the store-unreachable
            // case — that one raises out of the SELECT above, before any grant is decided.
            Log::warning('mezzanine.read: could not record feed token last_used_at', [
                'token_id' => $tokenId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
