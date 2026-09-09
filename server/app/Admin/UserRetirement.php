<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ THE ONLY WRITER OF `users.retired_at` — card#9070's D2 and D4 in one place.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * D4 · SELF-LOCKOUT IS A REFUSAL, NOT A WARNING. An install whose last active account is retired
 * cannot be administered by anyone: there is no self-service registration
 * (`config/fortify.php` says so and `AuthSurfaceTest` holds it), no mailer and therefore no
 * password-reset path, and every console route is behind `auth`. The only way back is shell
 * access and `mezzanine:user:create`, which is why that command is documented as the escape
 * hatch in `README.md`. A confirm dialog was rejected for this: a dialog is a READ, and it is
 * the operator — the one person already sure — who clicks through it.
 *
 * ⛔ THE COUNT IS TAKEN UNDER A ROW LOCK IN THE SAME TRANSACTION AS THE WRITE. Two operators
 * retiring the last two accounts at the same moment would each see a count of 2, each pass the
 * check, and leave zero — the exact state D4 exists to make unreachable. `lockForUpdate()` is a
 * no-op on the SQLite the suite runs on and is honoured by the MySQL this deploys to
 * (`docs/PLAN.md` D-15), so the guard is correct on the store that can actually race.
 *
 * ⛔ AN AUTHOR AND A REASON ARE THIS ACT'S OBLIGATION, NOT ITS CALLERS'. § 4.5 calls retirement
 * "an act with an AUTHOR and a REASON", and until card#9070's first review round both callers held
 * that rule independently — the console with a validation error, `mezzanine:user:create`'s sibling
 * command with `INVALID`. Two copies of one rule is one copy too many the moment there is a third
 * caller, and a default would be worse still: it would put a fabricated author on an administrative
 * record. The callers keep their own messages, which are better than an exception; what they no
 * longer keep is the rule.
 *
 * ⚠ RE-RETIRING IS A NO-OP, NOT AN ERROR — the same answer `mezzanine:retire` gives for a seat,
 * for the same two reasons: an operator re-running an act they are unsure landed must not be told
 * the system is broken, and a second write would OVERWRITE the original author, reason and
 * timestamp with the re-run's. Who retired an account is recorded once.
 */
final class UserRetirement
{
    /** The act happened: the account is retired as of now. */
    public const RETIRED = 'retired';

    /** The account was already retired; nothing was written and nothing was overwritten. */
    public const ALREADY_RETIRED = 'already_retired';

    /** D4: this is the last account that can still administer the install. Refused. */
    public const REFUSED_LAST_ACTIVE = 'refused_last_active';

    /**
     * @return self::RETIRED|self::ALREADY_RETIRED|self::REFUSED_LAST_ACTIVE
     *
     * @throws \InvalidArgumentException if the author or the reason is empty
     */
    public static function retire(User $target, string $by, string $reason): string
    {
        if (trim($by) === '' || trim($reason) === '') {
            throw new \InvalidArgumentException(
                'retirement is an act with an author and a reason (§ 4.5); neither may be empty'
            );
        }

        return DB::transaction(function () use ($target, $by, $reason): string {
            // Re-read the target INSIDE the transaction and under the same lock as the count:
            // the model handed in was loaded before the request reached here, so its
            // `retired_at` is a value from the past, and deciding a no-op on a stale read is how
            // a second act gets written.
            $active = User::query()->active()->lockForUpdate()->get(['id']);

            if (! $active->contains('id', $target->getKey())) {
                return self::ALREADY_RETIRED;
            }

            if ($active->count() <= 1) {
                return self::REFUSED_LAST_ACTIVE;
            }

            User::query()->whereKey($target->getKey())->update([
                'retired_at' => now(),
                'retired_by' => $by,
                'retired_reason' => $reason,
            ]);

            return self::RETIRED;
        });
    }
}
