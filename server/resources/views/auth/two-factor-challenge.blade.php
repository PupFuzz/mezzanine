@extends('layouts.app')
@section('title', 'Two-factor challenge')

@section('content')
    <p>Enter the six-digit code from your authenticator app.</p>

    {{--
        Card#12179. Each form carries its own box, because only the form that is submitted is sent:
        a box shared between the two would reach the server only with one of them. A ticked box on an
        accepted code lets this browser sign this account in on its password alone until the fixed
        expiry; `App\Auth\RememberedBrowser` owns the rest.
    --}}

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <label for="code">Authentication code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <label for="remember_browser_code"><input id="remember_browser_code" name="{{ \App\Auth\RememberedBrowser::FIELD }}" type="checkbox" value="1"> Remember this browser for {{ \App\Auth\RememberedBrowser::LIFETIME_DAYS }} days</label>
        <button type="submit">Verify</button>
    </form>

    <p>Lost the device? Use a recovery code instead.</p>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <label for="recovery_code">Recovery code</label>
        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code">
        <label for="remember_browser_recovery"><input id="remember_browser_recovery" name="{{ \App\Auth\RememberedBrowser::FIELD }}" type="checkbox" value="1"> Remember this browser for {{ \App\Auth\RememberedBrowser::LIFETIME_DAYS }} days</label>
        <button type="submit">Verify</button>
    </form>

    {{-- Card#9471: a recovery code is single-use, so a user who keeps signing in with them runs out. --}}
    <p>
        Each recovery code works once. After signing in with one, use <strong>Move to a new
        authenticator</strong> on the recovery codes page so the next sign-in does not cost another.
    </p>

    {{--
        Card#9077. Until this link existed, the sentence above named the only way out of a lost
        device and the codes were never displayed anywhere, so for most accounts there was no way
        out at all. The page it points at explains itself when the host has no outbound mail — which
        is the default configuration, and is a fact about the host rather than about any account.
    --}}
    <p>
        Lost the device <em>and</em> the recovery codes?
        <a href="{{ route('two-factor.reset') }}">Have a reset code emailed to the account's address.</a>
    </p>
@endsection
