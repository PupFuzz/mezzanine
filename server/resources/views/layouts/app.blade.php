<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
</head>
<body>
    <main>
        <h1>@yield('title', config('app.name'))</h1>

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
