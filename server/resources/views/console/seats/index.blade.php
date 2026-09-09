@extends('console.layout')
@section('title', 'Agents')
@section('console')
    <p>
        Seats reporting to this install. A seat appears here because it <em>reported</em> — there
        is no "add an agent", by design (<code>docs/design/FLOOR.md § 3.4</code>).
    </p>

    <table>
        <thead>
            <tr>
                <th>Install</th><th>Seat</th><th>Render state</th><th>Link</th>
                <th>Last activity</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($seats as $seat)
                <tr>
                    <td>{{ $seat->install_id }}</td>
                    <td>{{ $seat->seat_id }}</td>
                    <td>{{ $seat->render_state }}</td>
                    <td>{{ $seat->link_state }}</td>
                    <td>{{ $seat->last_activity_received_at ?? '—' }}</td>
                    <td>
                        @if ($seat->retired_at !== null)
                            retired {{ $seat->retired_at }} by {{ $seat->retired_by }}
                            — {{ $seat->retired_reason }}
                        @else
                            <form method="POST"
                                  action="{{ route('admin.agents.retire', [$seat->install_id, $seat->seat_id]) }}">
                                @csrf
                                <label for="reason-{{ $seat->install_id }}-{{ $seat->seat_id }}">Reason</label>
                                <input id="reason-{{ $seat->install_id }}-{{ $seat->seat_id }}"
                                       name="reason" type="text" required maxlength="255">
                                <button type="submit">Retire</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No seat has reported to this install yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p>
        Retiring a seat is the one operator act on this page and it is not a deletion: the row is
        kept, the floor is told immediately, and the desk renders as retired for
        {{ \App\Sweep\Purge::RETENTION_DAYS }} days.
    </p>
@endsection
