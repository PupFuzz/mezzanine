@extends('console.layout')
@section('title', 'Create a user')
@section('console')
    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf

        <label for="name">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required>

        {{-- ⛔ NEVER `value="{{ old('password') }}"`. The framework does not flash a password back
             (Handler::$dontFlash), and re-rendering one here would put a credential in the HTML
             of every rejected form — the exact surface canon #20 names. --}}
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required
               autocomplete="new-password">

        <button type="submit">Create</button>
    </form>

    <p>
        The new account signs in with this password and is then sent straight to second-factor
        enrolment; nothing else is reachable until they finish there.
    </p>
@endsection
