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

        <button type="submit">Save</button>
    </form>

    <p>
        Second factor: {{ $user->hasCompletedTwoFactorEnrolment() ? 'enrolled' : 'not enrolled' }}.
        Enrolment is done by the account holder on their own device and cannot be set from here.
    </p>
@endsection
