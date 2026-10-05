<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ THE ONLY WRITER OF AN EXISTING ACCOUNT'S `users.role` — card#9415. The console's edit form and
 * `mezzanine:user:role` both land here; `App\Admin\UserProvisioning::create()` sets the role of a NEW
 * account and nothing else writes the column.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE LAST ACTIVE OPERATOR CANNOT BE DEMOTED, and that includes an operator demoting themselves.
 * Only an operator reaches the admin console (`routes/web.php` puts `can:operate` on its group), so an
 * install whose active accounts are all observers cannot create, promote or retire anybody from the
 * web — the same lockout card#9070's D4 refuses for retirement, reached by a different act. The
 * refusal is `leavesNoOperator()`, and `App\Admin\UserRetirement` asks the same question before it
 * retires anyone, so the two acts cannot disagree about what "the last operator" means.
 *
 * ⛔ THE COUNT IS TAKEN UNDER A ROW LOCK OVER EVERY ACTIVE ACCOUNT, the same rows
 * `UserRetirement::retire()` locks. Two operators demoting each other at once would otherwise each
 * see two operators, each pass, and leave none; and a demotion racing a retirement must serialise
 * with it for the same reason. As `UserRetirement` records of its own lock, MariaDB honours it and the
 * single-connection suite has no second session to race it, so that leg is reasoned, not executed.
 *
 * ⚠ A RETIRED ACCOUNT IS REFUSED BY THROWING, as `UserProvisioning::update()` refuses one: a retired
 * record does not change (card#9070's D2), and a caller reaching here with one skipped a check it
 * owns the message for.
 */
final class UserRoles
{
    /** The role was written. */
    public const ASSIGNED = 'assigned';

    /** The account already had that role; nothing was written. */
    public const UNCHANGED = 'unchanged';

    /** The account is the last active operator, and the new role is not `operator`. Refused. */
    public const REFUSED_LAST_OPERATOR = 'refused_last_operator';

    /**
     * @return self::ASSIGNED|self::UNCHANGED|self::REFUSED_LAST_OPERATOR
     *
     * @throws \InvalidArgumentException if the role is not one of `User::ROLES`, or the account is retired
     */
    public static function assign(User $target, string $role): string
    {
        if (! in_array($role, User::ROLES, true)) {
            throw new \InvalidArgumentException('not a role: '.$role);
        }

        return DB::transaction(function () use ($target, $role): string {
            $active = self::lockActive();
            $current = $active->firstWhere('id', $target->getKey());

            if ($current === null) {
                throw new \InvalidArgumentException(
                    'a retired account is not writable: its record is what survives the act (D2)'
                );
            }

            if ($current->role === $role) {
                return self::UNCHANGED;
            }

            if (self::leavesNoOperator($active, $target)) {
                return self::REFUSED_LAST_OPERATOR;
            }

            User::query()->whereKey($target->getKey())->update(['role' => $role]);
            $target->role = $role;
            $target->syncOriginalAttribute('role');

            return self::ASSIGNED;
        });
    }

    /**
     * Every active account's id and role, locked for the rest of the caller's transaction.
     *
     * @return Collection<int, User>
     */
    public static function lockActive(): Collection
    {
        return User::query()->active()->lockForUpdate()->get(['id', 'role']);
    }

    /**
     * Would taking `$target` out of the operator tier — by demoting or by retiring it — leave the
     * install with no active operator? `$active` is `lockActive()`'s answer, read in the same
     * transaction as the write it guards.
     *
     * @param  Collection<int, User>  $active
     */
    public static function leavesNoOperator(Collection $active, User $target): bool
    {
        $operators = $active->filter(fn (User $user) => $user->isOperator());

        return $operators->contains('id', $target->getKey()) && $operators->count() <= 1;
    }
}
