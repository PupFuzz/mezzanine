<?php

namespace App\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * ⛔ THE ONE STATEMENT OF WHAT A PASSWORD MAY BE — read by the console's create and edit forms
 * AND by `mezzanine:user:create`, which are the only two writers of a password in this
 * application (card#9070 D1).
 *
 * Two copies would be free to disagree, and the direction they disagree in is the dangerous one:
 * a bootstrap command with a laxer rule than the console mints the FIRST account — the one that
 * can create every other — under the weaker of the two.
 */
final class PasswordPolicy
{
    /**
     * ⚠ THE UPPER BOUND IS NOT COSMETIC. bcrypt hashes at most 72 bytes and ignores the rest, so
     * without a cap a 200-character passphrase would be silently truncated at hashing time and
     * an operator would believe in entropy the store never received. Refusing it is loud; hashing
     * a prefix is not.
     *
     * The lower bound is 12 rather than the framework default of 8: every account here is an
     * operator account behind a public login page (`docs/PLAN.md` D-03), and there is no
     * self-service PASSWORD-reset path to recover from a compromised one (card#9077's emailed
     * reset removes a second factor, not a password). Composition rules
     * (`->mixedCase()`, `->symbols()`) are deliberately absent — they lower entropy in practice
     * by steering everyone to the same shapes, and length is the term that matters.
     *
     * `->uncompromised()` is deliberately absent too: it makes a live HTTP call to a third party
     * on every password write, which would turn account creation on an air-gapped or
     * network-restricted host into a failure with no local explanation.
     *
     * @return list<ValidationRule|string>
     */
    public static function rules(): array
    {
        return ['string', Password::min(self::MIN_LENGTH)->max(self::MAX_LENGTH)];
    }

    /**
     * ⚠ THE BOUNDS ARE CONSTANTS AND `rules()` READS THEM — so the command, which validates the
     * same value through the same `rules()` rather than through a form, cannot end up describing
     * a bound to the operator that the validator does not enforce.
     */
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 72;
}
