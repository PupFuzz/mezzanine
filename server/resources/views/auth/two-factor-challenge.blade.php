@extends('layouts.app')
@section('title', 'Two-factor challenge')

@section('content')
    <p>Enter the six-digit code from your authenticator app.</p>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <label for="code">Authentication code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <button type="submit">Verify</button>
    </form>

    <p>Lost the device? Use a recovery code instead.</p>

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <label for="recovery_code">Recovery code</label>
        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code">
        <button type="submit">Verify</button>
    </form>

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
