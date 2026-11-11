@extends('console.layout')
@section('title', 'Rooms')
@section('console')
    <p>
        <strong>A room is where seats are drawn together</strong>: each room has its own desk map (the
        <a href="{{ route('admin.floors.index') }}">Floors</a> module) and sits on a floor of the
        <a href="{{ route('admin.layout.edit') }}">building layout</a>. A room's <strong>key</strong> is typed once and
        never changes — its map history is kept under it — and its <strong>name</strong> is what you rename. Seats are
        placed in rooms from the <a href="{{ route('admin.agents.index') }}">Agents</a> page; a new seat starts in none
        (<code>docs/design/FLEET-STATE.md § 4.10</code>).
    </p>

    @if ($unreadable !== null)
        <p role="alert">The stored building layout cannot be read, so no room's floor is shown: {{ $unreadable }}</p>
    @endif

    <h2>Create a room</h2>

    <form method="POST" action="{{ route('admin.rooms.store') }}">
        @csrf
        <label for="room-key">Key</label>
        <input id="room-key" name="key" type="text" required value="{{ old('key') }}" maxlength="32">

        <label for="room-name">Name</label>
        <input id="room-name" name="name" type="text" required value="{{ old('name') }}" maxlength="{{ $nameMax }}">

        <label for="room-floor">Floor</label>
        <select id="room-floor" name="floor">
            <option value="">No floor</option>
            @foreach ($floors as $floor)
                <option value="{{ $floor['key'] }}" @selected(old('floor') === $floor['key'])>
                    {{ $floor['name'] }} (/floor/{{ $floor['key'] }})
                </option>
            @endforeach
        </select>

        <button type="submit">Create</button>
    </form>

    <p>
        The key is lower-case letters, digits and hyphens, and a key the room cannot have is refused by name;
        <code>unassigned</code> is reserved for the seats in no room. Placing the room on a floor writes one layout revision with it. A floor laid out with positions
        (a <em>planned</em> floor) refuses a room without one: create the room on no floor, then place it in the
        layout document.
    </p>

    <h2>Every room</h2>

    <table>
        <thead>
            <tr><th>Key</th><th>Name</th><th>Floor</th><th>Seats</th><th>Map</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($rooms as $room)
                <tr>
                    <td><code>{{ $room['key'] }}</code></td>
                    <td>
                        @if ($room['retired_at'] === null)
                            <form method="POST" action="{{ route('admin.rooms.update', $room['key']) }}">
                                @csrf
                                @method('PATCH')
                                <label for="name-{{ $room['key'] }}">Name</label>
                                <input id="name-{{ $room['key'] }}" name="name" type="text" required
                                       maxlength="{{ $nameMax }}" value="{{ $room['name'] ?? $room['key'] }}">
                                <button type="submit">Rename</button>
                            </form>
                        @else
                            {{ $room['name'] ?? $room['key'] }}
                        @endif
                    </td>
                    <td>{{ $room['floor'] ?? '—' }}</td>
                    <td>{{ $room['seats'] === [] ? '—' : implode(', ', $room['seats']) }}</td>
                    <td>{{ $room['map_version'] === null ? 'shipped default' : 'revision '.$room['map_version'] }}</td>
                    <td>
                        @if ($room['retired_at'] === null)
                            <form method="POST" action="{{ route('admin.rooms.retire', $room['key']) }}">
                                @csrf
                                <button type="submit">Retire</button>
                            </form>
                        @else
                            retired <x-utc-time :at="$room['retired_at']" />
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No room exists yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p>
        Retiring a room is refused while a live seat is in it or a coordination binding is bound to it; it takes the
        room off its floor in the same act. A retired room is drawn nowhere and is listed here as a record. There is
        no un-retire: a room key is never reused.
    </p>
@endsection
