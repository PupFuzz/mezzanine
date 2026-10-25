@extends('console.layout')
@section('title', 'Seat tokens')
@section('console')
    {{--
        card#12331 · `App\Http\Controllers\Admin\SeatTokenController` owns the argument. The plaintext is
        on this page ONLY in the issue POST's own response (`$issued`); nothing else renders it, and the
        response is `no-store`.
    --}}
    @if ($issued !== null)
        <section aria-labelledby="issued-heading">
            <h2 id="issued-heading">Token issued for {{ $issued->installId }} / {{ $issued->seatId }}</h2>
            <p><strong>Copy it now. It is shown once</strong>, on this page only, and cannot be shown again: only its SHA-256 is stored.</p>
            <p>Prefix <code>{{ $issued->prefix }}</code></p>
            <p><code id="seat-token-plaintext" data-seat-token>{{ $issued->plaintext }}</code></p>
            <p>Put it into the seat's reporter config — the token file the install guide names (<code>fleet-reporter/INSTALL-LINUX.md</code>, Step 2). If you did not copy it, revoke this prefix below and issue another.</p>
            @if ($issued->priorActive !== [])
                <p>
                    This is a <strong>rotation</strong>: the seat still holds
                    @foreach ($issued->priorActive as $prior)<code>{{ $prior }}</code>@if (! $loop->last), @endif @endforeach
                    and both stay valid. Revoke the old one only <em>after</em> the seat's config holds this one — revoking first leaves the seat with no working token (D1 § 3.3's order).
                </p>
            @endif
        </section>
    @endif

    @if (session('refusedRetired'))
        @php($refused = session('refusedRetired'))
        <p role="alert">
            {{ $refused['seat'] }} was retired <x-utc-time :at="$refused['at']" /> by {{ $refused['by'] ?? 'an author not recorded' }}
            (reason: {{ $refused['reason'] ?? 'not recorded' }}). Its record is on the Agents page, under retired seats.
        </p>
    @endif

    <h2>Issue a token</h2>
    <p>
        Issuing a token for a new install or seat creates it: the seat is on the floor from this moment, as
        <em>no data yet</em>, until its reporter sends its first batch. A seat that already holds a token gets
        a second one beside it — that is a rotation, and nothing is revoked.
    </p>
    <form method="POST" action="{{ route('admin.tokens.issue') }}">
        @csrf
        {{-- ⛔ ALWAYS THE FRESHLY MINTED KEY, NEVER `old('issue_key')`: a validation failure flashes the
             input, and re-rendering the spent key would refuse every retry. --}}
        <input type="hidden" name="issue_key" value="{{ $issueKey }}">

        <label for="install_id">Install id</label>
        <input id="install_id" name="install_id" type="text" required list="known-installs"
               pattern="{{ str_replace('-]', '\-]', $installPattern) }}" value="{{ old('install_id') }}"
               autocomplete="off" spellcheck="false">
        <datalist id="known-installs">
            @foreach ($installs as $install)
                <option value="{{ $install }}"></option>
            @endforeach
        </datalist>

        <label for="seat_id">Seat id</label>
        <input id="seat_id" name="seat_id" type="text" required list="known-seats"
               pattern="{{ str_replace('-]', '\-]', $seatPattern) }}" value="{{ old('seat_id') }}"
               autocomplete="off" spellcheck="false">
        <datalist id="known-seats">
            @foreach ($seats as $seat)
                <option value="{{ $seat }}"></option>
            @endforeach
        </datalist>
        <p>Lowercase letters, digits and hyphens, starting with a letter or digit.</p>

        <button type="submit">Issue token</button>
    </form>

    <h2>Tokens</h2>
    <table>
        <thead>
            <tr>
                <th>Install / seat</th><th>Prefix</th><th>Issued</th><th>By</th><th>Last used</th><th>Revoked</th><th></th>
            </tr>
        </thead>
        @forelse ($tokens->groupBy(fn ($t) => $t->install_id.' / '.$t->seat_id) as $seat => $rows)
            <tbody>
                @foreach ($rows as $token)
                    <tr>
                        <td>
                            {{ $seat }}
                            @if ($token->seat_retired_at !== null) <em>(retired)</em> @endif
                        </td>
                        <td><code>{{ $token->prefix }}</code></td>
                        <td><x-utc-time :at="$token->created_at" /></td>
                        <td>{{ $token->created_by }}</td>
                        <td>
                            @if ($token->last_used_at === null)
                                never
                            @else
                                <x-utc-time :at="$token->last_used_at" />
                            @endif
                        </td>
                        <td>
                            @if ($token->revoked_at === null)
                                active
                            @else
                                <x-utc-time :at="$token->revoked_at" />
                                by {{ $token->revoked_by ?? 'not recorded' }}
                                @if ($token->revoked_reason !== null) — {{ $token->revoked_reason }} @endif
                            @endif
                        </td>
                        <td>
                            @if ($token->revoked_at === null)
                                <form method="POST" action="{{ route('admin.tokens.revoke', $token->prefix) }}">
                                    @csrf
                                    <label for="reason-{{ $token->prefix }}">Reason</label>
                                    <input id="reason-{{ $token->prefix }}" name="reason" type="text" required maxlength="255">
                                    <button type="submit">Revoke {{ $token->prefix }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        @empty
            <tbody><tr><td colspan="7">No token has been issued on this install yet.</td></tr></tbody>
        @endforelse
    </table>

    <p>
        Revoking is permanent and takes effect on the seat's next request: its reporter is refused
        (<code>401</code>), stops sending to this site and records why, and the seat goes stale and then offline
        on the floor. A seat with no active token cannot post. Revoked tokens stay listed with who revoked them,
        when and why.
    </p>

    <script type="module" src="{{ \App\Support\PublicAsset::url('js/console/seat-token.js') }}"></script>
@endsection
