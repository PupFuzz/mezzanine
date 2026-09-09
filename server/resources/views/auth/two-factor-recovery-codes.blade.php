{{--
    Card#9077 step 1. The gate on this page is `auth` + `mfa` + `password.confirm`
    (`routes/web.php`), and `App\Http\Controllers\Auth\TwoFactorRecoveryCodeController` owns the
    argument for why the codes are displayed here at all rather than shown once and hidden.
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

    <p><a href="{{ route('dashboard') }}">Back to the floor</a></p>
@endsection
