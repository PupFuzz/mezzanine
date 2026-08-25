@extends('layouts.app')
@section('title', 'Sign in')

@section('content')
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <label for="remember"><input id="remember" name="remember" type="checkbox"> Remember me</label>

        <button type="submit">Sign in</button>
    </form>
@endsection
