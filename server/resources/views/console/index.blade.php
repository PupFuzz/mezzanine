@extends('console.layout')
@section('title', 'Admin console')

@section('console')
    <p>
        Signed in as {{ auth()->user()->email }}. Every account that can reach this page can
        administer this install.
    </p>

    <ul>
        @foreach ($modules as $module)
            <li>
                <a href="{{ route($module['route']) }}">{{ $module['label'] }}</a>
                — {{ $module['blurb'] }}
            </li>
        @endforeach
    </ul>
@endsection
