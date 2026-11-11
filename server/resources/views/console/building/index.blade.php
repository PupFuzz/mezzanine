@extends('console.layout')
@section('title', 'Floors')
@section('console')
    <p>
        <strong>A floor is a set of rooms with a name</strong>, and its web address is its name: <em>The Burrow</em>
        is at <code>/floor/the-burrow</code>. Renaming a floor keeps its old address as a <strong>former
        address</strong> that still opens it; a new name whose address another floor used to have takes that
        address over, and its links then open the renamed floor. Rooms are created and retired in the
        <a href="{{ route('admin.rooms.index') }}">Rooms</a> module; a room no floor holds is drawn on the
        <em>Unassigned</em> floor. A planned floor's room positions and hallway are written in the
        <a href="{{ route('admin.layout.edit') }}">building layout</a> editor, where every change made here is also a
        revision.
    </p>

    @if ($unreadable !== null)
        <p role="alert">
            The stored building layout cannot be read, so nothing can be changed here until it is repaired in the
            <a href="{{ route('admin.layout.edit') }}">building layout</a> editor or a revision is restored:
            {{ $unreadable }}
        </p>
    @else
        <div data-floors="{{ json_encode(array_map(fn (array $f) => ['key' => $f['key'], 'name' => $f['name'], 'aliases' => $f['aliases']], $floors)) }}">
            <h2>Create a floor</h2>

            @if ($rooms === [])
                <p>A floor is created holding a room, and there is no live room yet: create one in the
                    <a href="{{ route('admin.rooms.index') }}">Rooms</a> module first.</p>
            @else
            <form method="POST" action="{{ route('admin.building.floors.store') }}">
                @csrf
                <label for="new-floor-name">Name</label>
                <input id="new-floor-name" name="name" type="text" required value="{{ old('name') }}"
                       data-floor-name="new-floor-address" aria-describedby="new-floor-address">
                <output id="new-floor-address"></output>

                <label for="new-floor-theme">Theme</label>
                <select id="new-floor-theme" name="theme">
                    <option value="">The house theme ({{ $house }})</option>
                    @foreach ($themes as $theme)
                        <option value="{{ $theme }}" @selected(old('theme') === $theme)>{{ $theme }}</option>
                    @endforeach
                </select>

                <label for="new-floor-room">Its first room</label>
                <select id="new-floor-room" name="room" required>
                    @foreach ($rooms as $room)
                        <option value="{{ $room['key'] }}" @selected(old('room') === $room['key'])>
                            {{ $room['name'] ?? $room['key'] }} ({{ $room['key'] }}{{ $room['floor'] === null ? ', on no floor' : ', now on /floor/'.$room['floor'] }})
                        </option>
                    @endforeach
                </select>

                <button type="submit">Create</button>
            </form>
            @endif

            <p>
                A floor always holds a room, so a new floor is created with one, taken off the floor it is on. A room
                that is the only one on its floor is refused: rename that floor instead, and its old links keep
                working.
            </p>

            <h2>The floors</h2>

            @forelse ($floors as $floor)
                <section aria-labelledby="floor-{{ $floor['key'] }}">
                    <h3 id="floor-{{ $floor['key'] }}">
                        {{ $floor['name'] }} — <a href="{{ url('/floor/'.$floor['key']) }}"><code>/floor/{{ $floor['key'] }}</code></a>
                    </h3>

                    <form method="POST" action="{{ route('admin.building.floors.update', $floor['key']) }}">
                        @csrf
                        @method('PATCH')
                        <label for="name-{{ $floor['key'] }}">Name</label>
                        <input id="name-{{ $floor['key'] }}" name="name" type="text" required value="{{ $floor['name'] }}"
                               data-floor-name="address-{{ $floor['key'] }}" data-floor-self="{{ $floor['key'] }}"
                               aria-describedby="address-{{ $floor['key'] }}">
                        <output id="address-{{ $floor['key'] }}"></output>
                        <button type="submit">Rename</button>
                    </form>

                    <form method="POST" action="{{ route('admin.building.floors.update', $floor['key']) }}">
                        @csrf
                        @method('PATCH')
                        <label for="theme-{{ $floor['key'] }}">Theme</label>
                        <select id="theme-{{ $floor['key'] }}" name="theme">
                            <option value="" @selected($floor['theme'] === null)>The house theme ({{ $house }})</option>
                            @foreach ($themes as $theme)
                                <option value="{{ $theme }}" @selected($floor['theme'] === $theme)>{{ $theme }}</option>
                            @endforeach
                        </select>
                        <button type="submit">Set the theme</button>
                    </form>

                    @if ($floor['aliases'] !== [])
                        <p>Former addresses, each still opening this floor:</p>
                        <ul>
                            @foreach ($floor['aliases'] as $alias)
                                <li>
                                    <code>/floor/{{ $alias }}</code>
                                    <form method="POST" action="{{ route('admin.building.floors.update', $floor['key']) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="forget" value="{{ $alias }}">
                                        <button type="submit">Forget</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <p>
                        Rooms: {{ implode(', ', array_map(fn (array $r) => ($r['name'] ?? $r['key']).' ('.$r['key'].')', $floor['rooms'])) }}
                        @if ($floor['planned'])
                            — a <em>planned</em> floor: placing a room here clears its positions and its hallway.
                        @endif
                    </p>

                    @php($placeable = array_values(array_filter($rooms, fn (array $r) => $r['floor'] !== $floor['key'])))
                    @if ($placeable !== [])
                        <form method="POST" action="{{ route('admin.building.floors.place', $floor['key']) }}">
                            @csrf
                            <label for="place-{{ $floor['key'] }}">Place a room here</label>
                            <select id="place-{{ $floor['key'] }}" name="room" required>
                                @foreach ($placeable as $room)
                                    <option value="{{ $room['key'] }}">
                                        {{ $room['name'] ?? $room['key'] }} ({{ $room['key'] }}{{ $room['floor'] === null ? ', on no floor' : ', now on /floor/'.$room['floor'] }})
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit">Place</button>
                        </form>
                    @endif
                </section>
            @empty
                <p>No floor exists yet: every room is drawn on the <em>Unassigned</em> floor.</p>
            @endforelse

            <p>
                Placing a room takes it off the floor it is on; a floor left with no room is removed, and its
                addresses then open nothing. Forgetting a former address stops it opening the floor.
            </p>
        </div>

        <script type="module" src="{{ \App\Support\PublicAsset::url('js/console/floor-name.js') }}"></script>
    @endif
@endsection
