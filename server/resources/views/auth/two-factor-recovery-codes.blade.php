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

        The button STARTS a move and changes nothing on the account. The current authenticator and
        these codes keep working until a code from the new authenticator is confirmed on the next
        page, and only that confirmation replaces them.
        `App\Http\Controllers\Auth\TwoFactorMoveController` owns why the move never passes through
        Fortify's disable route. The paragraphs below state both halves before the button.
    --}}
    <h2>Move to a new authenticator</h2>

    <p>
        Use this if you have replaced or reset the device your authenticator app is on, or want to use
        a different app. You scan a new entry and enter a code from it. <strong>Your current
        authenticator keeps working until the new one is confirmed.</strong>
    </p>

    <p>
        <strong>Once the move is confirmed, the entry in your current authenticator app stops working,
        and every recovery code on this page stops working.</strong> You get a new set of recovery
        codes in their place.
    </p>

    <form method="POST" action="{{ route('two-factor.move.start') }}">
        @csrf
        <button type="submit">Move to a new authenticator</button>
    </form>

    <p><a href="{{ route('dashboard') }}">Back to the floor</a></p>
@endsection
