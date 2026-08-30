@extends('layouts.app')
@section('title', 'Confirm your password')

@section('content')
    <p>Confirm your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
        <button type="submit">Confirm</button>
    </form>
@endsection
