{{--
    Card#9077 step 2, screen 2. The code arrives by EMAIL and is TYPED here — it is never in a URL,
    so it reaches no access log, no `Referer` header and no browser history
    (`App\Notifications\TwoFactorResetCode` owns that argument).

    This page is also where every request lands, whether or not an account matched: the status
    message is flashed by the controller and is the same sentence either way.
--}}
@extends('layouts.app')
@section('title', 'Enter your reset code')

@section('content')
    <form method="POST" action="{{ route('two-factor.reset.consume') }}">
        @csrf
        <label for="code">Reset code</label>
        {{-- ⛔ NO `value="{{ old('code') }}"`. The code is a live credential for its TTL, and a
             re-populated field writes it back into the HTML of the rejected page. `bootstrap/app.php`
             also excludes it from the flashed input; this is the half that means there is nothing
             to render even if that list is ever edited. --}}
        <input id="code" name="code" type="text" required autofocus
               autocomplete="off" spellcheck="false" inputmode="text">
        <button type="submit">Remove my second factor</button>
    </form>

    <p>Hyphens, spacing and capitalisation do not matter.</p>

    <p>
        <a href="{{ route('two-factor.reset') }}">Send another code</a> —
        this invalidates any code already sent.
    </p>

    <p><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
