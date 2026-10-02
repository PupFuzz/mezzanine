<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    {{--
        THE PAGE CHROME — card#11045 PR-A: one stylesheet for every page, plain CSS served static (no
        bundler), versioned by the file's own mtime so a deploy that changes it is never served from a
        browser's cache. `Tests\Feature\ThePageChromeIsOneLinkedStylesheetTest` holds this link.
    --}}
    <link rel="stylesheet" href="{{ asset('css/mezzanine.css') }}?v={{ filemtime(public_path('css/mezzanine.css')) }}">
</head>
<body class="@yield('page-class')">
    <main>
        {{--
            The page's one <h1>, on every page — most views have no heading of their own — styled as the
            header's title. A view adds to the header's right-hand side with `@section('header')`.
        --}}
        <header class="app-header">
            <h1>@yield('title', config('app.name'))</h1>
            @yield('header')
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        {{--
            EVERY error bag, not only the default one. `$errors->any()` and `$errors->all()` read the
            `default` bag alone, and Fortify reports a rejected enrolment code in the
            `confirmTwoFactorAuthentication` bag, so the enrolment page used to answer a wrong code
            with no message at all (card#9445).
        --}}
        @php($errorMessages = collect($errors->getBags())->flatMap(fn ($bag) => $bag->all()))
        @if ($errorMessages->isNotEmpty())
            <ul role="alert">
                @foreach ($errorMessages as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @yield('content')
    </main>
</body>
</html>
