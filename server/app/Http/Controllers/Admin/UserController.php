<?php

namespace App\Http\Controllers\Admin;

use App\Admin\PasswordPolicy;
use App\Admin\UserProvisioning;
use App\Admin\UserRetirement;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's USER module — card#9070's second scope item.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ CANON #20, AND IT IS THE REASON THIS CONTROLLER LOOKS THIN. A password reaches exactly one
 * place from here: the validator, and then `App\Admin\UserProvisioning`, which hands it to the
 * framework's hasher. It is never logged, never put in a message, never returned to the view.
 * The one non-obvious leg is the VALIDATION FAILURE path — Laravel's exception handler flashes
 * the request's input back to the form, and it excludes `password`, `password_confirmation` and
 * `current_password` from that flash by default (`Illuminate\Foundation\Exceptions\Handler
 * ::$dontFlash`). This application depends on that behaviour rather than on nobody noticing, so
 * `Tests\Feature\Admin\AuthSecretsNeverSurfaceTest` asserts the flashed input directly: if a
 * future upgrade changed the default, a rejected create form would start re-rendering the
 * password into the HTML and only that assertion would say so.
 *
 * ⛔ THERE IS NO `destroy` (D2). Retirement is the act and the record survives it;
 * `App\Admin\UserRetirement` owns the rule, including D4's refusal. If an erasure is ever
 * genuinely wanted — the GDPR-shaped kind — D2 puts it in its own louder command, never on a
 * console button beside "edit".
 *
 * ⚠ NO `Authorize`/policy CALLS ANYWHERE IN THIS CLASS — D3: every authenticated user is an
 * operator, and the whole authorization statement is the route group's middleware.
 * `routes/admin.php` carries the decision and the trigger that would void it.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('console.users.index', [
            // `active` is the console shell's nav highlight — view data rather than a variable set
            // inside the child template, because a child's locals are not in scope when the
            // layout renders.
            'active' => 'users',
            'users' => User::query()
                // Active accounts first, then the retired ones, each group by name. The retired
                // rows are kept ON THE PAGE rather than filtered out, because a console that hid
                // them would give an operator the same view a DELETE would have — which is the
                // entire thing D2 refuses.
                ->orderByRaw('retired_at is not null')
                ->orderBy('name')
                ->get(),
            'activeCount' => User::query()->active()->count(),
        ]);
    }

    public function create(): View
    {
        return view('console.users.create', ['active' => 'users']);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => UserProvisioning::canonicalEmail((string) $request->input('email', ''))]);

        $validated = $request->validate(
            UserProvisioning::identityRules() + [
                'password' => array_merge(['required', 'confirmed'], PasswordPolicy::rules()),
            ]
        );

        $user = UserProvisioning::create($validated['name'], $validated['email'], $validated['password']);

        return redirect()
            ->route('admin.users.index')
            ->with('status', sprintf(
                '%s created. They must complete second-factor enrolment on their first sign-in '
                .'before anything else is reachable.',
                $user->email,
            ));
    }

    public function edit(User $user): View
    {
        return view('console.users.edit', ['active' => 'users', 'user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $request->merge(['email' => UserProvisioning::canonicalEmail((string) $request->input('email', ''))]);

        // ⚠ THE PASSWORD IS `nullable` ON EDIT AND REQUIRED ON CREATE, and the difference is not
        // cosmetic: this application has no mailer and therefore no password-reset flow
        // (`config/fortify.php` states why), so an operator setting a colleague's password here is
        // the ONLY recovery path for a forgotten one. Leaving the field empty must mean "leave it
        // alone" rather than "set it to nothing".
        $validated = $request->validate(
            UserProvisioning::identityRules($user->getKey()) + [
                'password' => array_merge(['nullable', 'confirmed'], PasswordPolicy::rules()),
            ]
        );

        $user->name = trim($validated['name']);
        $user->email = $validated['email'];

        if (($validated['password'] ?? '') !== '') {
            // `User::$casts` declares `password => 'hashed'`; the assignment hashes it.
            $user->password = $validated['password'];
        }

        $user->save();

        return redirect()->route('admin.users.index')->with('status', $user->email.' updated.');
    }

    public function retire(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            // Required for the same reason `mezzanine:retire` requires it of a seat
            // (`docs/design/FLEET-STATE.md § 4.5`): retirement is an act with an author and a
            // reason, and a record that carries neither cannot be read later by the person who
            // needs it.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $outcome = UserRetirement::retire(
            $user,
            (string) $request->user()->email,
            $validated['reason'],
        );

        if ($outcome === UserRetirement::REFUSED_LAST_ACTIVE) {
            return redirect()->route('admin.users.index')->withErrors([
                'retire' => 'Refused: '.$user->email.' is the last account that can still sign in. '
                    .'Retiring it would lock every operator out of this install — there is no '
                    .'self-service registration and no password reset, so the only way back would '
                    .'be shell access and `php artisan mezzanine:user:create`. Create the '
                    .'replacement account first, then retire this one.',
            ]);
        }

        $message = $outcome === UserRetirement::ALREADY_RETIRED
            ? $user->email.' was already retired — nothing was changed, and the original author '
                .'and reason are kept.'
            : $user->email.' retired. The account can no longer sign in; its record stays.';

        return redirect()->route('admin.users.index')->with('status', $message);
    }
}
