{{--
    The console SHELL. Every console page extends this, and this extends `layouts.app`, so the
    status/error rendering and the page chrome are stated once for the whole application.

    The nav is generated from `App\Admin\ConsoleModules` rather than written out below: the next
    module (`card#9072`'s floors) is then an entry in that list rather than an edit here, in the
    landing page, and in whatever else happened to carry a link.
--}}
@extends('layouts.app')

@section('content')
    <nav aria-label="Admin console">
        <ul>
            @foreach (\App\Admin\ConsoleModules::all() as $module)
                <li>
                    <a href="{{ route($module['route']) }}"
                       @if (($active ?? null) === $module['key']) aria-current="page" @endif>
                        {{ $module['label'] }}
                    </a>
                </li>
            @endforeach
            <li><a href="{{ route('dashboard') }}">Back to the floor</a></li>
        </ul>
    </nav>

    @yield('console')
@endsection
