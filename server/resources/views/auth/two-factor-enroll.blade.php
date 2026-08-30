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
