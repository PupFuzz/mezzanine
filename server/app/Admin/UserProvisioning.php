<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The one writer of a user account — card#9070 D1's `mezzanine:user:create` and the console's
 * create form both land here.
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
