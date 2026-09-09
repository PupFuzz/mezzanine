{{--
    Card#9077 step 2, screen 1. `$available` is `App\Auth\TwoFactorReset::isAvailable()` — a
    HOST-level fact about outbound mail, identical for every visitor, so showing it reveals nothing
    about any account.
--}}
@extends('layouts.app')
@section('title', 'Reset two-factor authentication')

@section('content')
    @unless ($available)
        <p role="alert">
            <strong>This install has no outbound mail configured, so a reset cannot be sent.</strong>
            An operator with shell access must set <code>MAIL_MAILER</code> in <code>.env</code> and
            prove it with <code>php artisan mezzanine:mail:preflight --to=…</code>. Until then, the
            ways back into an account are a recovery code at the two-factor challenge, or another
            operator.
        </p>
    @else
        <p>
            Lost your authenticator <em>and</em> your recovery codes? We can remove the second factor
            from your account and let you enrol a new device.
        </p>

        <p>
            The code goes to the address already on the account and nowhere else. Removing the second
            factor does <strong>not</strong> sign you in — you will still need your password.
        </p>

        <form method="POST" action="{{ route('two-factor.reset.send') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            <button type="submit">Send a reset code</button>
        </form>
    @endunless

    <p><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
