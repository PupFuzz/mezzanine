<?php

namespace App\Feed;

use Illuminate\Http\Request;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

/**
 * `docs/design/FLEET-STATE.md § 9`'s 15 s re-check of an OPEN stream: "re-runs the check the route's
 * middleware ran at connect — the session is live and its MFA enrolment holds".
 *
 * ⛔ IT RE-READS BOTH FROM THE STORE and never re-inspects the copy the request loaded at connect: "a
 * re-check of an in-memory session is a check that cannot fail" (AT-D2-19's in-memory RED).
 *
 *   · THE SESSION is read back through the configured session HANDLER by the id the stream connected
 *     with, into a fresh `Store` — the handler is what applies `SESSION_LIFETIME` (the database
 *     handler answers an expired row with nothing), and the store is what decodes the payload under
 *     `session.serialization` / `session.encrypt`. What it must still hold is the guard's login key
 *     naming the user the stream connected as: a logged-out or expired session holds none.
 *   · THE USER is resolved again through the guard's own provider — `App\Auth\ActiveUserProvider`,
 *     which a retired account is NOT FOUND by — and must still have a confirmed second factor, which
 *     is `App\Http\Middleware\EnsureTwoFactorSatisfied`'s rule, read off the same model method.
 *
 * ⚠ THE FRESH `Store` RESTATES `Illuminate\Session\SessionManager::buildSession()`'s four lines,
 * because that method is protected and the manager's own store is the request's copy — the one this
 * class must not read. A session driver that stops going through a handler would need this revisited.
 *
 * THE THREE OUTCOMES (§ 9), and why this answers only two of them:
 *   `true`  — the store answered and the session and its MFA hold;
 *   `false` — the store answered, and what it said is that the session or its MFA is gone;
 *   a THROWABLE — the read did not come back with an answer about the session. It is NOT caught here:
 *   the handler catches it as `default` → `feed.close{reason:"unavailable"}`, so no exception class
 *   (a lock, a permission refusal, a corrupt page) can match no branch and end the stream silently.
 */
final class SessionRecheck
{
    public function __construct(
        private readonly string $sessionId,
        private readonly int|string $userId,
    ) {}

    /** The identity the route's middleware admitted at connect. */
    public static function for(Request $request): self
    {
        return new self($request->session()->getId(), $request->user()->getAuthIdentifier());
    }

    public function stillValid(): bool
    {
        $manager = app('session');
        $config = $manager->getSessionConfig();
        $handler = $manager->driver()->getHandler();
        $serialization = $config['serialization'] ?? 'php';

        $store = ($config['encrypt'] ?? false)
            ? new EncryptedStore($config['cookie'], $handler, app('encrypter'), $this->sessionId, $serialization)
            : new Store($config['cookie'], $handler, $this->sessionId, $serialization);

        $store->start();

        $guard = Auth::guard();

        if ((string) $store->get($guard->getName()) !== (string) $this->userId) {
            return false;
        }

        $user = $guard->getProvider()->retrieveById($this->userId);

        return $user !== null && $user->hasCompletedTwoFactorEnrolment();
    }
}
