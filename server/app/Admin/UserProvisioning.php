<?php

namespace App\Admin;

use App\Models\User;
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
     * @throws \InvalidArgumentException if the account is retired
     */
    public static function update(User $user, string $name, string $email, #[\SensitiveParameter] ?string $password = null): User
    {
        if ($user->isRetired()) {
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
        }

        $user->save();

        return $user;
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
