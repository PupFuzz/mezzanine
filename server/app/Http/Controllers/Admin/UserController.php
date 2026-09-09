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
 * ⚠ AND THAT SENTENCE ONLY BECAME TRUE IN CARD#9070's FIRST REVIEW ROUND. As first written, the
 * console's `edit` accepted a RETIRED subject: the erasure WAS the button beside "edit", because
 * renaming a retired row off its address and then creating a new account on the freed address does
 * everything a delete does, in two authenticated requests, while leaving a row behind that names
 * the wrong person. The refusal now lives at the write (`App\Admin\UserProvisioning::update()`),
 * because the only thing that had ever stopped it was the `@unless` hiding the Edit link — a
 * read-time guard for a write-site rule, which is the same shape the retired-account filter was
 * deliberately put in the PROVIDER rather than the login route to avoid.
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
        $this->canonicaliseEmailInput($request);

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

    public function edit(User $user): View|RedirectResponse
    {
        if ($user->isRetired()) {
            return $this->refuseRetired($user);
        }

        return view('console.users.edit', ['active' => 'users', 'user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // ⛔ BEFORE VALIDATION, BECAUSE THE ANSWER DOES NOT DEPEND ON THE INPUT. A retired account
        // is not editable at all (D2), so telling the operator their new address is malformed
        // would be answering the wrong question. `UserProvisioning::update()` refuses the same
        // subject at the WRITE — this branch exists to say WHY in words, not to be the guard.
        if ($user->isRetired()) {
            return $this->refuseRetired($user);
        }

        $this->canonicaliseEmailInput($request);

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

        UserProvisioning::update($user, $validated['name'], $validated['email'], $validated['password'] ?? null);

        return redirect()->route('admin.users.index')->with('status', $user->email.' updated.');
    }

    /**
     * D2's refusal, in the words an operator can act on. The refusal itself is
     * `App\Admin\UserProvisioning::update()`'s; this is the message.
     */
    private function refuseRetired(User $user): RedirectResponse
    {
        return redirect()->route('admin.users.index')->withErrors([
            'edit' => 'Refused: '.$user->email.' is retired, and a retired account\'s record does '
                .'not change. The name and the address are the record — they are what a later '
                .'reader resolves an old reference against, and freeing the address would let a '
                .'different person be created under it and become indistinguishable from this one '
                .'in every earlier record. If this person needs an account again, create one under '
                .'a different address.',
        ]);
    }

    /**
     * ⚠ CANONICALISE ONLY WHAT IS ALREADY A STRING. `email[]=a@b.com` on either write route used to
     * cast an ARRAY to a string here, which PHP raises `Array to string conversion` for and the
     * handler turns into a 500 — on a form that has a validator for exactly this. Left alone, the
     * `string` rule in `UserProvisioning::identityRules()` refuses it as the input error it is.
     *
     * The canonicalisation must still happen BEFORE validation and not after: `Rule::unique()`
     * compares the value it is GIVEN, so validating `Ops@Example.com` against a stored
     * `ops@example.com` passes on a case-sensitive collation and then collides on the write.
     */
    private function canonicaliseEmailInput(Request $request): void
    {
        if (is_string($raw = $request->input('email'))) {
            $request->merge(['email' => UserProvisioning::canonicalEmail($raw)]);
        }
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
