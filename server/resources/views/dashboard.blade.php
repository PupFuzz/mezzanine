@extends('layouts.app')
@section('title', 'Mezzanine')

@section('content')
    {{-- The floor itself is card #7341. This page exists because requirement 3 of card #7334
         needs a browser surface to gate, and it stays empty so that #7341 has nothing to
         delete before it can start. --}}
    <p>Signed in as {{ auth()->user()->email }}, with a confirmed second factor.</p>

    {{-- The console is reachable from here rather than by knowing its URL (card#9070). --}}
    <p><a href="{{ route('admin.index') }}">Admin console</a></p>

    {{-- And the recovery codes from here, for the same reason (card#9077): a page nobody can find
         is the same defect as a page that does not exist, which is how the codes came to be stored,
         accepted at the challenge, and never once displayed. --}}
    <p><a href="{{ route('two-factor.codes') }}">Two-factor recovery codes</a></p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
