<?php

namespace App\Ingest;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * D1 § 12.1 STEP 4, entire — including the one rate limit that lives inside it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE FAILED-AUTH LIMIT IS IN THIS CLASS AND NOT IN `RateLimiter`.
 *
 * § 12.1: "That limit is evaluated here, at step 4, and not with the others at step 5 — it is the
 * one exception to the ordering and it needs to be, because a request that fails this step never
 * reaches step 5. An earlier draft placed it at step 5, where the only requests that could reach
 * it had already authenticated successfully, so a limit whose entire subject is *failed*
 * authentications could never fire."
 *
 * § 12.3 is blunter still: "Both halves of a limit — what it is keyed on and where it runs — have
 * to be right for it to be a check rather than a decoration, and this one got each wrong once."
 * Putting it back beside the others would re-mint the defect the document records fixing twice,
 * so it lives here, inside the step whose failures it counts.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS FOR, honestly. § 12.3: it bounds log volume, CPU spent on hash comparisons, and an
 * operator's noise floor. "It is not a defence against guessing" — a brute-forcer sends a
 * different string every attempt, and the actual defence is the token's 256 bits of entropy.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * NO PLAINTEXT TOKEN LEAVES THIS CLASS. It is never logged, never returned in an error body,
 * never written to a column. Only the SHA-256 and a 12-character identification prefix exist
 * past the header read, which is D1 § 3.3's rule ("A token table an app can read is a fleet-wide
 * credential dump the first time any read primitive leaks") applied to every other surface too.
 */
final class TokenResolver
{
    /** § 12.3: failed authentications, keyed on SOURCE IP, 60 per hour. */
    public const FAILED_AUTH_LIMIT = 60;

    public const FAILED_AUTH_WINDOW_S = 3600;

    public const FAILED_AUTH_RETRY_AFTER_S = 60;

    /** D1 § 3.3 — the ingest credential. */
    public const INGEST_PREFIX = 'mzn_';

    /** D2 § 8's read-plane credential; never valid here. */
    public const READ_PREFIX = 'mzr_';

    public function __construct(private readonly RateLimiter $limiter) {}

    public function resolve(Request $request): TokenBinding|Refusal
    {
        $presented = $this->bearer($request);
        $ip = (string) $request->ip();

        if ($presented === null) {
            return $this->fail($ip);
        }

        // D2 § 7.2: an `mzr_` read token presented to the ingest is counted and alerted on,
        // because it "is either a misconfiguration that will otherwise present as a mysterious
        // dark seat, or a probe". It still takes the ordinary 401 below — the counter is a
        // diagnosis, not a different answer.
        if (str_starts_with($presented, self::READ_PREFIX)) {
            Counters::global(Counters::TOKEN_WRONG_SURFACE);
            Log::warning('mezzanine.ingest: a read-plane token (mzr_) was presented to the ingest', [
                'source_ip' => $ip,
            ]);
        }

        $hash = hash('sha256', $presented);

        $row = DB::table('ingest_tokens')
            ->join('seats', 'seats.id', '=', 'ingest_tokens.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('ingest_tokens.token_hash', $hash)
            ->select([
                'ingest_tokens.id as token_id',
                'ingest_tokens.prefix',
                'ingest_tokens.revoked_at',
                'seats.id as seat_ref',
                'seats.seat_id',
                'installs.install_id',
            ])
            ->first();

        if ($row === null) {
            // "a token that resolves to nothing" — § 12.7's `auth_failed_by_ip`. No seat is
            // named, so no seat counter and no badge: the token named none.
            return $this->fail($ip);
        }

        if ($row->revoked_at !== null) {
            // A DIFFERENT fact from the one above, and § 12.3 separates them deliberately:
            // "Separately, and as a diagnostic rather than a limit: a presented token that
            // resolves to a *revoked* row is counted per token row and alerted on, because that
            // is a real signal with a real owner — a seat still holding a dead credential, which
            // nobody else can see." It is NOT counted as a failed authentication, so it does not
            // consume the log-volume budget above, and it does not degrade the seat: § 12.1's
            // attribution table gives step 4 no seat badge at all.
            Counters::global(Counters::REVOKED_TOKEN_PRESENTED);
            Log::warning('mezzanine.ingest: a REVOKED seat token was presented — that seat is holding a dead credential', [
                'token_prefix' => $row->prefix,
                'install_id' => $row->install_id,
                'seat_id' => $row->seat_id,
                'source_ip' => $ip,
            ]);

            return Refusal::unauthenticated();
        }

        $this->touch((int) $row->token_id, $ip);

        return new TokenBinding(
            tokenId: (int) $row->token_id,
            prefix: (string) $row->prefix,
            seatRef: (int) $row->seat_ref,
            installId: (string) $row->install_id,
            seatId: (string) $row->seat_id,
        );
    }

    /**
     * The failed-authentication path, in § 12.1 step 4's stated order: increment first, THEN
     * decide between 429 and 401. The order matters — the request that crosses the limit is
     * itself a failure and must be counted before the limit is read, or the 61st request is
     * evaluated against a count of 59.
     */
    private function fail(string $ip): Refusal
    {
        Counters::global(Counters::AUTH_FAILED_BY_IP);

        $count = $this->limiter->hitFailedAuth($ip);

        if ($count > self::FAILED_AUTH_LIMIT) {
            return Refusal::rateLimited(
                self::FAILED_AUTH_RETRY_AFTER_S,
                self::FAILED_AUTH_LIMIT,
                self::FAILED_AUTH_WINDOW_S,
                'failed authentications from this source address',
            );
        }

        return Refusal::unauthenticated();
    }

    /**
     * `Authorization: Bearer <token>`, and nothing else. No query parameter, no cookie, no
     * custom header: D1 § 4.1 says the endpoint "accepts no cookies and no session", and a
     * second accepted carrier is a second thing to get wrong.
     */
    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Best-effort, and outside the ingest transaction on purpose: `last_used_at` answers "is this
     * credential in use, and from where", which is a rotation question, not a correctness one. A
     * failure to write it must never cost a batch, so it is not allowed to abort anything.
     */
    private function touch(int $tokenId, string $ip): void
    {
        try {
            DB::table('ingest_tokens')->where('id', $tokenId)->update([
                'last_used_at' => now()->format('Y-m-d H:i:s.v'),
                'last_used_ip' => @inet_pton($ip) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('mezzanine.ingest: could not record token last_used_at', [
                'token_id' => $tokenId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
