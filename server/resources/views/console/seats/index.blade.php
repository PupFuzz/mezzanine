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
                        <form method="POST"
                              action="{{ route('admin.agents.retire', [$seat->install_id, $seat->seat_id]) }}">
                            @csrf
                            <label for="reason-{{ $seat->install_id }}-{{ $seat->seat_id }}">Reason</label>
                            <input id="reason-{{ $seat->install_id }}-{{ $seat->seat_id }}"
                                   name="reason" type="text" required maxlength="255">
                            <button type="submit">Retire</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No seat has reported to this install yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p>
        Retiring a seat is the one operator act on this page and it is not a deletion. The floor is
        told in the same transaction and <strong>the desk goes immediately</strong> — a removal is
        a deliberate act, and it is not the silence of a seat that stopped reporting, which keeps
        its desk and renders as degraded (<code>docs/design/FLOOR.md § 3.5</code>).
    </p>

    {{--
        THE RETIREMENT RECORD'S HOME (card#9078). The desk goes at `retired_at`; the record does
        not go with it. It is bounded by no window — `seats` is retained forever
        (`docs/design/FLEET-STATE.md § 6.7`) and the disappearance is a read filter and never a
        deletion — so every retirement this install has ever performed is listed below.
    --}}
    <h2>Retired seats</h2>

    <table>
        <thead>
            <tr>
                <th>Install</th><th>Seat</th><th>Retired</th><th>By</th><th>Reason</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($retired as $seat)
                <tr>
                    <td>{{ $seat->install_id }}</td>
                    <td>{{ $seat->seat_id }}</td>
                    <td>{{ $seat->retired_at }}</td>
                    <td>{{ $seat->retired_by }}</td>
                    <td>{{ $seat->retired_reason }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No seat has been retired on this install.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p>
        The row, its author and its reason are kept for good, and the seat's events stay queryable
        for as long as the retention window holds them
        (<code>docs/design/FLEET-STATE.md § 6.7</code>). There is no un-retire on this console: a
        seat that reports again after being retired is a misconfiguration, not a return.
    </p>
@endsection
