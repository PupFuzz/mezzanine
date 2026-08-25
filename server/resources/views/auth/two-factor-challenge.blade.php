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
@endsection
