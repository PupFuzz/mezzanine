<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The one writer of a user account — card#9070 D1's `mezzanine:user:create` and the console's
 * create form both land here to CREATE one, and the console's edit form lands in `update()` to
 * change one. Being the one writer is what lets D2's "a retired record does not change" be a rule
 * held in a single place instead of a check every caller has to remember.
 *
 * ⛔ WHY THE EMAIL IS LOWERCASED ON WRITE, AND WHY THAT IS A CORRECTNESS FIX RATHER THAN TIDINESS.
 * `config/fortify.php` sets `lowercase_usernames => true`, so `Laravel\Fortify\Actions\
 * CanonicalizeUsername` lowercases the submitted address before the credential lookup on EVERY
 * login. An account stored as `Ops@Example.com` is therefore looked up as `ops@example.com` and
 * — on any case-sensitive collation, which includes the SQLite the suite runs on — is never
 * found. The account would be created successfully, report success, and be unable to log in:
 * exactly the "looks like a working console until somebody tries it" failure this card exists to
 * close. Canonicalising at the WRITE site is the fix, because it is the only site that can make
 * the stored value and the looked-up value the same string.
 *
 * ⛔ NOTHING HERE TOUCHES A PASSWORD IN CLEAR EXCEPT TO HAND IT TO THE FRAMEWORK'S HASHER.
 * `User::$casts` declares `password => 'hashed'`, so assignment hashes it with the configured
 * driver; there is no hand-rolled hashing and no comparison anywhere in this application.
 */
final class UserProvisioning
{
    /**
     * @return string the canonical form of an address as it will be STORED and looked up
     */
    public static function canonicalEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * ⚠ TAKES THE PLAINTEXT AND RETURNS THE MODEL, AND IS THE ONLY THING THAT SEES IT. The
     * caller's job is to obtain the value in a way that keeps it out of argv, a log and a
     * rendered page; this method's job is to make sure the only place it lands is the hasher.
     */
    public static function create(string $name, string $email, #[\SensitiveParameter] string $password): User
    {
        return User::create([
            'name' => trim($name),
            'email' => self::canonicalEmail($email),
            'password' => $password,
        ]);
    }

    /**
     * ⛔ THE ONE WRITER OF AN EXISTING ACCOUNT'S IDENTITY, AND THE PLACE D2's RECORD IS MADE
     * IMMUTABLE — added in card#9070's first review round, which measured the hole.
     *
     * A RETIRED ACCOUNT IS REFUSED HERE, AT THE WRITE, and the reason it is here rather than in the
     * controller is the reason the hole existed: the only thing that stopped an operator rewriting
     * a retired row was `console/users/index.blade.php`'s `@unless`, which hides the Edit link. A
     * hidden link is a READ-TIME guard for a WRITE-SITE rule, and two authenticated requests walked
     * straight past it — rename the retired row off its address, then create a fresh account on the
     * freed address. That is exactly the erasure `App\Http\Controllers\Admin\UserController` says
     * this console does not have ("never on a console button beside 'edit'"), performed by the
     * button beside "edit".
     *
     * WHAT THE REFUSAL BUYS, stated as the properties it makes true rather than as a rule:
     *   · `users.email` really is unique across retired rows FOREVER, so the address of a retired
     *     account cannot be handed to a new one — the migration and `mezzanine:user:create` both
     *     state that, and neither was true before this guard;
     *   · the record a later reader resolves an old reference against — who the account WAS, not
     *     merely who retired it — survives the act, which is the whole difference between
     *     retirement and deletion.
     *
     * ⚠ IT THROWS RATHER THAN RETURNING A VERDICT, because a caller that reaches it with a retired
     * subject has skipped a check it was supposed to make; the console refuses first and keeps its
     * own message. If an erasure is ever genuinely wanted, D2 puts it in its own louder command —
     * and that command changes this class, visibly, rather than slipping through a form.
     *
     * ⛔ THE SUBJECT IS RE-READ UNDER A ROW LOCK, INSIDE THE TRANSACTION THAT WRITES — added in
     * card#9070's SECOND review round, which measured that the guard above was reading a snapshot.
     * `$user` arrives from implicit route-model binding, resolved at the top of the request, so
     * `$user->isRetired()` answers a question about the past. Two operators, one retiring `alice`
     * and one saving her edit form: B binds an active `alice`, A's retirement commits, and B's
     * `save()` lands on a retired row — the same erasure the refusal above exists to prevent,
     * through a narrower door. Testing the STORE instead of the snapshot closes the stale read; the
     * lock closes the race, and it is the same lock `App\Admin\UserRetirement::retire()` takes over
     * the same rows, so the two acts serialise against each other rather than interleaving.
     *
     * ⚠ IT IS A NO-OP ON SQLITE AND HONOURED BY MYSQL (`docs/PLAN.md` D-15), exactly as
     * `UserRetirement` records of its own lock. The suite therefore drives the stale-read leg —
     * `UserManagementTest::test_the_provisioning_act_refuses_a_subject_retired_after_the_request_bound_it`
     * fails without this — and the race leg is reasoned, not executed. Said here rather than left
     * to be assumed.
     *
     * ⚠ THE WRITE STILL GOES THROUGH `$user`, NOT THROUGH THE RE-READ. `save()` sends only DIRTY
     * attributes, so a snapshot cannot carry a stale `retired_at` back over the row; and the caller
     * — `App\Http\Controllers\Admin\UserController::update()` — reads `$user->email` afterwards
     * for the flash message, which must be the NEW address.
     *
     * ⛔ A PASSWORD RESET ALSO ENDS THE SESSIONS AND THE REMEMBER-ME COOKIE THE OLD PASSWORD
     * BOUGHT — added in card#9070's THIRD review round, which measured that this method wrote the
     * hash and nothing else.
     *
     * This console is the ONLY compromise-recovery path the product has for a PASSWORD: there is no
     * self-service password reset (`App\Admin\UserRetirement`'s D4 block establishes that, and
     * card#9077's emailed reset clears a second factor rather than a password), so
     * "reset the password" is the whole of "get this account back". It did not recover it, because
     * neither thing a live session actually runs on is the password:
     *
     *   · A SIGNED-IN BROWSER'S AUTHORITY IS A ROW. `config/session.php` resolves `SESSION_DRIVER`
     *     to `database`, which is also what `.env.example` ships, and the table name to
     *     `web_sessions` from its OWN default — no `SESSION_TABLE` is set anywhere, which is why
     *     the config expression and not the env var is what the code below reads. Nothing in the
     *     framework removes that row when the hash changes, and Laravel's opt-in for it,
     *     `Illuminate\Session\Middleware\AuthenticateSession`, is NOT on this application's stack:
     *     `bootstrap/app.php` aliases `mfa` and `fleet.read` and adds nothing to `web`, and
     *     `route:list` carries it on ZERO of this application's routes. There was no second
     *     mechanism doing this elsewhere.
     *   · REMEMBER-ME IS LIVE END TO END. The checkbox in `resources/views/auth/login.blade.php`,
     *     `rememberToken()` on the users migration, and `App\Auth\ActiveUserProvider` resolving a
     *     user BY that token. A stolen remember cookie therefore re-authenticates after the reset
     *     and mints a fresh session, which is the same access back through a second door.
     *
     * ⛔ EVERY ROW GOES, INCLUDING THE ONE THIS REQUEST IS ON. "Log the other devices out but keep
     * mine" is not available to a recovery act: a stolen session cookie IS this session's id, so
     * the row an operator resetting their OWN password would be keeping is the row an attacker is
     * holding a copy of. The cost is one sign-in, paid only on a self-reset, with the password just
     * set.
     *
     * ⚠ `Auth::logoutOtherDevices()` IS NOT THE MECHANISM AND WOULD NOT HAVE WORKED HERE. It takes
     * the ACTOR's password and re-authenticates the ACTOR, whereas the subject of this act is
     * normally somebody else; and what it invalidates other sessions WITH is the password hash
     * `AuthenticateSession` compares — the middleware this application does not run. It would have
     * been a no-op with a reassuring name.
     *
     * ⚠ THE TABLE NAME IS READ FROM THE CONFIG, WHICH IS WHERE THE MIGRATION READS IT —
     * `config('session.table', 'web_sessions')`, both halves. The rename off Laravel's stock
     * `sessions` is forced (`docs/design/FLEET-STATE.md` § 6.4 owns that name for the fold's own
     * projection), so a literal here would be a third copy free to disagree with the store.
     *
     * ⚠ WHAT THIS CANNOT DO, SAID RATHER THAN LEFT TO BE ASSUMED: it invalidates sessions only for
     * a session store that IS this database. Under `SESSION_DRIVER=file` or `redis` the rows live
     * somewhere this delete cannot reach and it removes nothing, silently. `config/session.php`'s
     * default and `.env.example` both say `database`; a deployment that changes that takes this
     * property with it.
     *
     * @throws \InvalidArgumentException if the account is retired
     */
    public static function update(User $user, string $name, string $email, #[\SensitiveParameter] ?string $password = null): User
    {
        return DB::transaction(function () use ($user, $name, $email, $password): User {
            // The STORE's answer, not the request's — see the docblock. `firstOrFail()` rather than
            // a null branch: nothing in this application deletes a user (D2 — accounts retire), so
            // a missing row is not a state to handle, it is one to fail loudly on.
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($current->isRetired()) {
                throw new \InvalidArgumentException(
                    'a retired account is not writable: its record is what survives the act (D2)'
                );
            }

            $user->name = trim($name);
            $user->email = self::canonicalEmail($email);

            // An empty new password means "leave the current one alone" — never "clear it". The
            // console's edit form states the same thing to the operator; `User::$casts` declares
            // `password => 'hashed'`, so the assignment below is the only place a plaintext goes.
            if ($password !== null && $password !== '') {
                $user->password = $password;

                // The two credentials the old password bought, taken back in the same transaction
                // that replaces it — see the docblock for why each one outlives the hash and why
                // this one deletes the current session's row too.
                $user->setRememberToken(Str::random(60));

                DB::table(config('session.table', 'web_sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->save();

            return $user;
        });
    }

    /**
     * What a valid user IS — one statement, read by the console's create form, the console's edit
     * form and `mezzanine:user:create`.
     *
     * ⛔ THE UNIQUENESS RULE IS THE FRAMEWORK'S AND IS DELIBERATELY NOT SCOPED TO ACTIVE USERS.
     * `users.email` is unique across the whole table, so an "available" answer that ignored
     * retired rows would be a promise the store then refuses with an integrity violation — a 500
     * on a form the operator filled in correctly, for a reason the page could not explain. The
     * migration that added the retirement columns states the same consequence from the schema
     * side.
     *
     * ⚠ THE CALLER MUST CANONICALISE THE ADDRESS BEFORE VALIDATING, with `canonicalEmail()`. The
     * unique rule compares the value it is GIVEN, so validating `Ops@Example.com` against a stored
     * `ops@example.com` passes on a case-sensitive collation and then collides on the INSERT.
     *
     * @return array<string, list<mixed>>
     */
    public static function identityRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($ignoreId),
            ],
        ];
    }
}
