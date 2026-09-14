{{--
    Card#9471. The secret this page shows is PENDING: it lives in the session, not on the account,
    and `App\Http\Controllers\Auth\TwoFactorMoveController` owns why. Leaving without confirming
    changes nothing.
--}}
@extends('layouts.app')
@section('title', 'Move to a new authenticator')

@section('content')
    <p>
        Scan this with your new authenticator app, then enter the code it shows. Your current
        authenticator keeps working until you do.
    </p>

    {!! $qrCode !!}

    <p>Setup key: <code>{{ $secret }}</code></p>

    <p>
        <strong>Confirming replaces your current authenticator entry and every current recovery
        code.</strong> The next page shows your new recovery codes.
    </p>

    {{-- A rejected code is reported above the page by the layout, which reads every error bag. --}}
    <form method="POST" action="{{ route('two-factor.move.confirm') }}">
        @csrf
        <label for="code">Authentication code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        <button type="submit">Confirm</button>
    </form>

    <p><a href="{{ route('two-factor.codes') }}">Cancel and keep your current authenticator</a></p>
@endsection
