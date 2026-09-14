{{--
    Card#9077 step 1. The gate on this page is `auth` + `mfa` + `password.confirm`
    (`routes/web.php`), and `App\Http\Controllers\Auth\TwoFactorRecoveryCodeController` owns the
    argument for why the codes are displayed here at all rather than shown once and hidden.

    Card#9471 added the move to a new authenticator, below the codes.
--}}
@extends('layouts.app')
@section('title', 'Recovery codes')

@section('content')
    <p>
        <strong>Store these somewhere you can reach without this device.</strong> Each code signs you in
        once at the two-factor challenge if you lose your authenticator, and each one works exactly
        once.
    </p>

    <ul>
        @foreach ($codes as $code)
            <li><code>{{ $code }}</code></li>
        @endforeach
    </ul>

    <p>
        These are stored encrypted rather than hashed, so this page can show them again — which also
        means anyone who has your password and a session can read them. If you think a set has been
        seen, generate a new one: that invalidates every code above, immediately.
    </p>

    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
        @csrf
        <button type="submit">Generate a new set</button>
    </form>

    {{--
        ⛔ CARD#9471 · MOVE TO A NEW AUTHENTICATOR. This is the only in-app way for a signed-in account
        with a confirmed second factor to enrol a replacement device: card#9445 removed "Start over"
        from the enrolment page for confirmed accounts, and the emailed reset needs outbound mail.

        It posts to FORTIFY'S OWN disable route (`DELETE /user/two-factor-authentication`,
        `two-factor.disable`), which clears the secret, the recovery codes and
        `two_factor_confirmed_at` together. There is no application-owned copy of that route, for the
        reason `App\Http\Responses\RecoveryCodesGeneratedResponse` gives. The route carries `auth` and
        `password.confirm` of its own (`config/fortify.php` sets `confirmPassword => true`), so
        reaching this page does not stand in for that check: the DELETE is judged on its own request.
        Where it lands is Fortify's stock `back()`, which is this page, and `mfa` sends an account
        with no confirmed factor from here to the enrolment page — the same gate that holds every
        other page. `Tests\Feature\TwoFactorMoveAuthenticatorTest` follows that chain.

        ⚠ NO SECOND CONFIRM STEP, DELIBERATELY. The security control is the password re-entry, which
        the route enforces. The consequences are stated above the button, and the button is a plain
        form submit. A mistaken press costs a re-scan and a new set of codes in the same session,
        which is recoverable. A JavaScript confirm dialog would be neither a security control nor a
        clearer statement than the paragraph.
    --}}
    <h2>Move to a new authenticator</h2>

    <p>
        Use this if you have replaced or reset the device your authenticator app is on, or want to use
        a different app. It turns two-factor authentication off for your account and takes you straight
        to setting it up again. <strong>As soon as you press it, the entry in your current authenticator
        app stops working, and every recovery code on this page stops working.</strong> Setting up again
        gives you a new entry to scan and a new set of recovery codes.
    </p>

    <p>
        Until you finish setting up again, your password alone signs in to this account, so finish it
        straight away.
    </p>

    <form method="POST" action="{{ route('two-factor.disable') }}">
        @csrf
        @method('DELETE')
        <button type="submit">Move to a new authenticator</button>
    </form>

    <p><a href="{{ route('dashboard') }}">Back to the floor</a></p>
@endsection
