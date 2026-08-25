@extends('layouts.app')
@section('title', 'Mezzanine')

@section('content')
    {{-- The floor itself is card #7341. This page exists because requirement 3 of card #7334
         needs a browser surface to gate, and it stays empty so that #7341 has nothing to
         delete before it can start. --}}
    <p>Signed in as {{ auth()->user()->email }}, with a confirmed second factor.</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
