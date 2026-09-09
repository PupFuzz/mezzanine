@extends('layouts.app')
@section('title', 'Set up two-factor authentication')

@section('content')
    <p>
        Every page of this application requires a second factor. Your account does not have one
        yet, so nothing else is reachable until this is finished.
    </p>

    @if (is_null(auth()->user()->two_factor_secret))
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button type="submit">Generate a secret</button>
        </form>
    @else
        <p>Scan this with your authenticator app, then enter the code it shows.</p>

        {!! auth()->user()->twoFactorQrCodeSvg() !!}

        {{--
            ⛔ CARD#9077's DEFECT, FIXED HERE. The recovery codes have existed in the store since the
            2FA migration and `auth/two-factor-challenge.blade.php` has always offered to accept
            one — but nothing displayed them, so the challenge screen offered a path the user had
            never been given the means to take, and losing the authenticator was a permanent
            lockout.

            ⚠ THIS IS NOT A LESSER GATE THAN THE PAGE THAT SHOWS THEM LATER, even though the route
            carries only `auth`. Reaching this branch at all means `two-factor.enable` succeeded,
            and that route is `auth` + `password.confirm` (`config/fortify.php` sets
            `confirmPassword => true`), so the session in front of these codes has proved the
            password within the confirmation window.
        --}}
        <h2>Recovery codes — write these down now</h2>

        <p>
            <strong>Each of these signs you in once if you lose your authenticator.</strong> Store
            them somewhere that is not the device you are about to enrol; without them, and without
            outbound mail configured on this host, a lost device is a locked account.
        </p>

        <ul>
            @foreach (auth()->user()->recoveryCodes() as $code)
                <li><code>{{ $code }}</code></li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            <button type="submit">Confirm</button>
        </form>

        <form method="POST" action="{{ route('two-factor.disable') }}">
            @csrf
            @method('DELETE')
            <button type="submit">Start over</button>
        </form>
    @endif
@endsection
