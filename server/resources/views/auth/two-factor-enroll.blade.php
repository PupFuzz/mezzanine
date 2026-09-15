{{--
    ⛔ CARD#9445. The branch is chosen by `hasCompletedTwoFactorEnrolment()` FIRST and by
    `two_factor_secret` only after it. The page used to test the secret alone, and a secret is present
    both mid-enrolment and after it — so a confirmed account was shown the QR code, the recovery codes,
    the code form and "Start over" again, and read that as a failed enrolment. A confirmed account
    sees none of those controls here; the confirm POST itself lands on the dashboard
    (`App\Http\Responses\TwoFactorConfirmedResponse`), and this state is what the back button or a
    bookmark reaches.
--}}
@extends('layouts.app')
@section('title', auth()->user()->hasCompletedTwoFactorEnrolment()
    ? 'Two-factor authentication is set up'
    : 'Set up two-factor authentication')

@section('content')
    @if (auth()->user()->hasCompletedTwoFactorEnrolment())
        <p>
            Two-factor authentication is on for your account. Each sign-in asks for a code from your
            authenticator app.
        </p>

        <p>
            Your recovery codes are on <a href="{{ route('two-factor.codes') }}">the recovery codes page</a>,
            behind your password. That page is also where you move to a new authenticator.
        </p>

        <p><a href="{{ route('dashboard') }}">On to the floor</a></p>
    @else
        <p>
            Every page of this application requires a second factor. Your account does not have one
            yet, so nothing else is reachable until this is finished.
        </p>

        @if (is_null(auth()->user()->two_factor_secret))
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit">Generate a secret</button>
            </form>
        @else
            <p>Scan this with your authenticator app, then enter the code it shows.</p>

            {!! auth()->user()->twoFactorQrCodeSvg() !!}

            {{--
                ⛔ CARD#9077's DEFECT, FIXED HERE. The recovery codes have existed in the store since the
                2FA migration and `auth/two-factor-challenge.blade.php` has always offered to accept
                one — but nothing displayed them, so the challenge screen offered a path the user had
                never been given the means to take, and losing the authenticator was a permanent
                lockout.

                ⚠ THE GATE IN FRONT OF THESE CODES IS `two-factor.enable`'s, AND IT HOLDS FOR THE SESSION
                THAT RAN IT. The route here carries only `auth`. Reaching this branch means
                `two-factor.enable` succeeded at some point, and that route is `auth` +
                `password.confirm` (`config/fortify.php` sets `confirmPassword => true`) — so the
                session that generated the secret proved the password within the confirmation window.
                A LATER session of an account that enabled and never confirmed reaches this branch on
                `auth` alone. A confirmed account never reaches it (card#9445): the branch above
                serves it, and its codes are behind `password.confirm` at `two-factor.codes`.
            --}}
            <h2>Recovery codes — write these down now</h2>

            <p>
                <strong>Each of these signs you in once if you lose your authenticator.</strong> Store
                them somewhere that is not the device you are about to enrol; without them, and without
                outbound mail configured on this host, a lost device is a locked account.
            </p>

            <ul>
                @foreach (auth()->user()->recoveryCodes() as $code)
                    <li><code>{{ $code }}</code></li>
                @endforeach
            </ul>

            {{-- A rejected code is reported above the page by the layout, which reads every error bag. --}}
            <form method="POST" action="{{ route('two-factor.confirm') }}">
                @csrf
                <label for="code">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                <button type="submit">Confirm</button>
            </form>

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <button type="submit">Start over</button>
            </form>
        @endif
    @endif
@endsection
