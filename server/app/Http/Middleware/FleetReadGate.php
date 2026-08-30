<?php

namespace App\Http\Middleware;

use App\Http\Middleware\EnsureTwoFactorSatisfied as Mfa;
use App\Ingest\Counters;
use App\Ingest\RateLimiter;
use App\Ingest\TokenResolver;
use App\Read\ReadGrant;
use App\Read\ReadRefusal;
use App\Read\ReadTokens;
use App\Support\FixedWindow;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * `docs/design/FLEET-STATE.md § 9`'s read-side authentication, as ONE gate over all four
 * endpoints — because § 9's rules are per-CREDENTIAL, not per-endpoint, and four copies of
 * "revocation is checked per request" is four places for one of them to grow a cache.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * TWO CREDENTIALS, AND WHICH ENDPOINTS ACCEPT WHICH (§ 8.2's table, § 9's table):
 *
 *   session + MFA           the floor, the drill-down, the websocket handshake, and REST from a
 *                           browser — i.e. ALL FOUR endpoints
 *   `mzr_` fleet_read       `/snapshot`, `/seats/{i}/{s}` and `/health` only.
 *                           `/timeline` is session+MFA ONLY in § 8.2's table, and that asymmetry
 *                           is preserved rather than smoothed: the timeline is D3's drill-down
 *                           window, the known machine consumer is the bridge's autonomy watchdog
 *                           whose interface is the snapshot (§ 8.2), and widening a surface
 *                           nothing asks for is how a read grant grows.
 *
 * ⛔ THE ORDER OF THE THREE CHECKS IS LOAD-BEARING, and it is the token-before-session order.
 *
 * A request carrying `Authorization: Bearer …` is a MACHINE request; if it also happens to carry
 * a session cookie (a browser with a stale header, a misconfigured proxy), resolving the session
 * first would let a REVOKED token read the fleet — the credential the caller actually presented
 * would never be examined. So a presented bearer is always adjudicated, and only its ABSENCE
 * falls through to the session. `ReadTokens::resolve()` returns null for "no bearer at all",
 * which is the one case that may fall through.
 *
 * ⚠ WHY THE MFA CHECK IS INLINE HERE RATHER THAN THE `mfa` MIDDLEWARE ALIAS. The alias REDIRECTS
 * an unsatisfied browser to the challenge, which is right for a page and right for this gate's
 * session branch — but it is reached only if the request is not a token request, so it cannot
 * run BEFORE this gate without refusing every machine consumer with a redirect to a login page.
 * That redirect-to-a-200-login-page is the exact failure shape § 2.2 forbids for a machine
 * reader, so the two branches are adjudicated here, in one place, in the right order.
 */
class FleetReadGate
{
    /** § 9: "Rate limit, token — **120 req/min** … the same ceiling D1 sets for a seat's ingest". */
    public const TOKEN_LIMIT = 120;

    /** § 9: "Rate limit, session — **600 req/min** … a browser opening drill-downs bursts". */
    public const SESSION_LIMIT = 600;

    public const WINDOW_S = 60;

    public const RETRY_AFTER_S = 30;

    /**
     * D1 § 12.3's FOURTH limit — "failed authentications, keyed on source IP, 60/hour" — applied
     * to this plane's refusal path. Not `=> 60` re-typed: the numbers are read from the one place
     * that owns them, so a change to D1's limit cannot leave two planes disagreeing about what it
     * is. § 9 does not require this on the read side; it is HARDENING, and D1 is honest about how
     * little it buys — "it bounds log volume, CPU spent on hash comparisons, and the noise floor
     * an operator reads — nothing more. It is not a defence against guessing", which against a
     * 256-bit token it could not be. What it does bound here is the unauthenticated DB work a
     * loop can provoke: one indexed `feed_tokens` SELECT plus a hot-row `global_counters` UPDATE
     * per attempt, neither of which took a slot before.
     */
    public const FAILED_AUTH_LIMIT = TokenResolver::FAILED_AUTH_LIMIT;

    public const FAILED_AUTH_WINDOW_S = TokenResolver::FAILED_AUTH_WINDOW_S;

    public const FAILED_AUTH_RETRY_AFTER_S = TokenResolver::FAILED_AUTH_RETRY_AFTER_S;

    /** § 8.2's table: the one endpoint a `mzr_` token may not read. */
    public const SESSION_ONLY_ROUTES = ['fleet.timeline'];

    /** § 7.2: the counter pair is snapshot-scoped — "a REST snapshot was served / refused". */
    public const COUNTED_ROUTE = 'fleet.snapshot';

    public function handle(Request $request, Closure $next): Response
    {
        $refusal = $this->authorise($request);

        if ($refusal instanceof ReadRefusal) {
            return $this->count($request, $refusal->response());
        }

        if ($refusal instanceof Response) {          // the session branch's MFA redirect
            return $this->count($request, $refusal);
        }

        return $this->count($request, $next($request));
    }

    /** @return ReadRefusal|Response|null  null ⇒ authorised */
    private function authorise(Request $request): ReadRefusal|Response|null
    {
        try {
            $token = ReadTokens::resolve($request);
        } catch (\Throwable $e) {
            // § 2.2, read-token verification: the token store unreachable is CLOSED — "`503`,
            // never a cached or assumed grant. There is no posture in which 'we could not check,
            // so we allowed it' is correct."
            Log::error('mezzanine.read: the token store could not be read; refusing', [
                'error' => $e->getMessage(),
            ]);

            return ReadRefusal::fleetUnavailable();
        }

        if ($token instanceof ReadRefusal) {
            return $this->failedAuth($request, $token);
        }

        if ($token instanceof ReadGrant) {
            if (in_array($request->route()?->getName(), self::SESSION_ONLY_ROUTES, true)) {
                return ReadRefusal::unauthenticated();
            }

            return $this->limit('read:tok:'.$token->id, self::TOKEN_LIMIT);
        }

        $user = $request->user();

        if ($user === null) {
            return ReadRefusal::unauthenticated();
        }

        // `docs/PLAN.md § 3`: MFA gates the page, the websocket handshake AND the REST snapshot.
        // The redirect is `EnsureTwoFactorSatisfied`'s own — reused rather than re-derived, so
        // "what an unsatisfied browser sees" has one home and one enrolment-vs-challenge rule.
        $mfa = app(Mfa::class)->refusalFor($request);

        if ($mfa !== null) {
            return $mfa;
        }

        return $this->limit('read:ses:'.$user->getAuthIdentifier(), self::SESSION_LIMIT);
    }

    private function limit(string $key, int $limit): ?ReadRefusal
    {
        $hits = FixedWindow::hit(app('cache')->store(), $key, self::WINDOW_S, 1);

        return $hits > $limit
            ? ReadRefusal::rateLimited(self::RETRY_AFTER_S, $limit, self::WINDOW_S)
            : null;
    }

    /**
     * A PRESENTED BEARER THAT FAILED — the one refusal that costs the store real work before it
     * is refused, and the only one that takes a slot.
     *
     * ⛔ SCOPED TO A PRESENTED CREDENTIAL, NOT TO EVERY 401. A request with no credential at all
     * never reaches here (`ReadTokens::resolve()` returns null for "no bearer" and the session
     * branch adjudicates it), and that exclusion is deliberate rather than an oversight: an
     * ordinary browser hitting the floor before logging in is unauthenticated, is not a failed
     * authentication, and throttling it per source IP would refuse a shared office its login page.
     * A valid token on `/timeline` is excluded for the same reason from the other direction — the
     * credential authenticated fine, § 8.2's table simply does not admit it on that route.
     *
     * ⛔ INCREMENT FIRST, THEN DECIDE, in D1 § 12.1 step 4's stated order and for its stated
     * reason: "the request that crosses the limit is itself a failure and must be counted before
     * the limit is read, or the 61st request is evaluated against a count of 59".
     *
     * ⚠ ONE BUDGET FOR BOTH PLANES, and it is `RateLimiter::hitFailedAuth()`'s — the existing
     * primitive, key and window included, not a second one keyed `read:`. D1 § 12.3 states the
     * limit as a property of the SOURCE ADDRESS ("keyed on source IP") bounding host-wide things
     * — log volume, hash-comparison CPU, "the noise floor an operator reads" — none of which is
     * per-plane, so one bucket per address is the faithful reading and a second bucket would be a
     * second implementation of one concept.
     *
     * ⚠ A DRAFT OF THIS DELIBERATELY SPLIT THE BUCKET, on the theory that read-plane failures
     * could exhaust the budget and start refusing a co-located SEAT's telemetry. That theory is
     * FALSE and the code says so: `TokenResolver::resolve()` reaches `fail()` — the only caller
     * of `hitFailedAuth()` — solely when no bearer was presented or the token resolves to nothing.
     * A seat holding a VALID token never consults this limit at all, so no amount of read-side
     * failure can darken it. What sharing actually costs is that a request which was going to be
     * refused anyway is refused `429` rather than `401`, on either plane. That is the whole of it.
     *
     * ⚠ THE READ PLANE FEEDS THIS BUCKET A NARROWER SET than the ingest does, and the asymmetry is
     * deliberate: the ingest counts "no bearer presented" as a failure (`TokenResolver:60`),
     * because on that surface there is no such thing as a browser. Here there is, and the
     * no-credential case is excluded above.
     */
    private function failedAuth(Request $request, ReadRefusal $refusal): ReadRefusal
    {
        // ⛔ A CREDENTIAL THAT RESOLVED TO A REAL ROW IS NOT A FAILED AUTHENTICATION, and this is
        // D1 § 12.3's own rule rather than a preference of this gate's: a presented token that
        // resolves to a REVOKED row "is counted per token row and alerted on, because that is a
        // real signal with a real owner — a seat still holding a dead credential … It is NOT
        // counted as a failed authentication, so it does not consume the log-volume budget."
        // Expiry is the same shape one plane over (§ 9's 90 days), and it earns the same answer.
        //
        // It matters twice over now that the bucket is shared: counting a revoked READ token here
        // would spend the INGEST's budget on precisely the case D1 refuses to spend it on. And a
        // `429` would be the worse answer anyway — `401 token_expired` names the fault to the
        // operator whose token it is; a rate-limit refusal hides it behind a retry.
        //
        // An ALLOWLIST and not a denylist: a refusal type added later is not counted until someone
        // decides it should be, which is the direction a hardening measure should fail in.
        if (! in_array($refusal->error, ['unauthenticated', 'token_wrong_surface'], true)) {
            return $refusal;
        }

        $hits = app(RateLimiter::class)->hitFailedAuth((string) $request->ip());

        return $hits > self::FAILED_AUTH_LIMIT
            ? ReadRefusal::rateLimited(
                self::FAILED_AUTH_RETRY_AFTER_S, self::FAILED_AUTH_LIMIT, self::FAILED_AUTH_WINDOW_S,
            )
            : $refusal;
    }

    /**
     * § 7.2's `snapshot_served` / `snapshot_denied`, decided from the RESPONSE rather than from
     * the branch that produced it.
     *
     * § 8.2: "The pair read together is what tells a fleet-health reader that a read plane
     * refusing EVERYTHING is refusing rather than idle." That property only holds if every
     * outcome of the snapshot endpoint lands in exactly one of the two — the gate's `401`, the
     * limiter's `429` and the controller's `503` alike — so the decision is made once, here, at
     * the one point all of them pass through, and never at the several points that produce them.
     */
    private function count(Request $request, Response $response): Response
    {
        if ($request->route()?->getName() !== self::COUNTED_ROUTE) {
            return $response;
        }

        $name = $response->getStatusCode() === 200 ? 'snapshot_served' : 'snapshot_denied';

        try {
            Counters::global($name);
        } catch (\Throwable $e) {
            // The store being unreachable is the one reason a snapshot is refused that also makes
            // this counter unwritable — the same reason § 8.2.4 reports `counters: null` rather
            // than zero in that posture. Refusing the response over a failed counter write would
            // turn a legible 503 into a 500.
            Log::warning('mezzanine.read: could not record '.$name, ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
