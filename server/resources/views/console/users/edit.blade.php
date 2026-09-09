@extends('console.layout')
@section('title', 'Edit user')
@section('console')
    <form method="POST" action="{{ route('admin.users.update', $user) }}">
        @csrf
        @method('PATCH')

        <label for="name">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>

        <label for="password">New password (leave empty to keep the current one)</label>
        <input id="password" name="password" type="password" autocomplete="new-password">

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password"
               autocomplete="new-password">

        <p>
            Setting a new password signs this account out everywhere it is currently signed in and
            invalidates its &ldquo;remember me&rdquo; cookie. That is what makes it a recovery from
            a stolen session rather than only a change of secret. Resetting your own password signs
            you out here too &mdash; sign back in with the one you just set.
        </p>

        <button type="submit">Save</button>
    </form>

    <p>
        Second factor: {{ $user->hasCompletedTwoFactorEnrolment() ? 'enrolled' : 'not enrolled' }}.
        Enrolment is done by the account holder on their own device and cannot be set from here.
    </p>
@endsection
