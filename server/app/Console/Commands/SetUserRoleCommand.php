<?php

namespace App\Console\Commands;

use App\Admin\UserProvisioning;
use App\Admin\UserRoles;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * `mezzanine:user:role` — card#9415: make an existing account an observer or an operator from the
 * host's shell.
 *
 * ⛔ IT IS THE CLI HALF OF THE WAY BACK FROM A LOCKOUT, beside `mezzanine:user:create`. The console
 * refuses to demote or retire the last active operator, but an install can still reach a state where
 * the one person who should administer it is an observer — an operator retired by some path nobody
 * foresaw, or the wrong account promoted and the right one demoted. Shell access on the host is
 * already the trust boundary for creating accounts and issuing tokens, so promoting from here adds
 * no new one, and like `mezzanine:user:create` it needs no session, no mailer and no existing
 * operator. `README.md § The first account, and the way back from a lockout` documents it.
 *
 * The write and its refusals are `App\Admin\UserRoles::assign()`'s, the same act the console's edit
 * form calls, so the shell cannot demote the last active operator either.
 */
class SetUserRoleCommand extends Command
{
    protected $signature = 'mezzanine:user:role
        {--email= : the address the account signs in with}
        {--role= : observer (reads the floor) or operator (also the admin console)}';

    protected $description = 'Make an existing account an observer or an operator — the way back when nobody can open the admin console';

    public function handle(): int
    {
        $email = UserProvisioning::canonicalEmail((string) $this->option('email'));
        $role = (string) $this->option('role');

        if (! in_array($role, User::ROLES, true)) {
            $this->error(sprintf('--role must be one of: %s. Nothing was changed.', implode(', ', User::ROLES)));

            return self::INVALID;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(sprintf('No account signs in as %s. Nothing was changed.', $email));

            return self::FAILURE;
        }

        if ($user->isRetired()) {
            $this->error(sprintf(
                '%s is retired, and a retired account\'s record does not change. Create a new account '
                .'with `mezzanine:user:create --role=%s` instead. Nothing was changed.',
                $email, $role,
            ));

            return self::FAILURE;
        }

        $outcome = UserRoles::assign($user, $role);

        if ($outcome === UserRoles::REFUSED_LAST_OPERATOR) {
            $this->error(sprintf(
                '%s is the last active operator; as an observer nobody could administer this install. '
                .'Make another account an operator first. Nothing was changed.',
                $email,
            ));

            return self::FAILURE;
        }

        $this->info($outcome === UserRoles::UNCHANGED
            ? sprintf('  %s is already an %s. Nothing was changed.', $email, $role)
            : sprintf('  %s is now an %s.', $email, $role));

        return self::SUCCESS;
    }
}
