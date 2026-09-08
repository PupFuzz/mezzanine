@extends('console.layout')
@section('title', 'Users')
@section('console')
    <p>
        <a href="{{ route('admin.users.create') }}">Create a user</a>
        — {{ $activeCount }} account(s) can currently sign in.
    </p>

    <table>
        <thead>
            <tr>
                <th>Name</th><th>Email</th><th>Second factor</th><th>State</th><th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->hasCompletedTwoFactorEnrolment() ? 'enrolled' : 'not enrolled' }}</td>
                    <td>
                        @if ($user->isRetired())
                            {{-- D2: the row stays, and so does the whole record of the act. --}}
                            retired {{ $user->retired_at }} by {{ $user->retired_by }}
                            — {{ $user->retired_reason }}
                        @else
                            active
                        @endif
                    </td>
                    <td>
                        @unless ($user->isRetired())
                            <a href="{{ route('admin.users.edit', $user) }}">Edit</a>

                            <form method="POST" action="{{ route('admin.users.retire', $user) }}">
                                @csrf
                                <label for="reason-{{ $user->id }}">Reason</label>
                                <input id="reason-{{ $user->id }}" name="reason" type="text" required
                                       maxlength="255">
                                <button type="submit">Retire</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>
        Retiring an account stops it signing in and keeps its record — nothing here deletes a row.
        The last account that can still sign in cannot be retired; create its replacement first.
    </p>
@endsection
