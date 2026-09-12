@extends('console.layout')
@section('title', 'Edit a floor map')
@section('console')
    <p>
        The map for <strong>{{ $installId }}</strong>, revision <strong>{{ $version }}</strong>.
        Replacing it replaces the room — and records a new revision, so this one stays restorable
        (<code>docs/design/FLEET-STATE.md § 6.11</code>).
        <a href="{{ route('admin.floors.revisions', $installId) }}">Revisions, diffs and restores</a>
    </p>

    <form method="POST" action="{{ route('admin.floors.update', $installId) }}">
        @csrf
        @method('PATCH')

        <label for="map">Tiled map (<code>.tmj</code>)</label>
        <textarea id="map" name="map" rows="20" cols="100" required>{{ old('map', $map) }}</textarea>

        <button type="submit">Save</button>
    </form>

    <form method="POST" action="{{ route('admin.floors.remove', $installId) }}">
        @csrf
        <button type="submit">Remove this map</button>
    </form>

    <p>
        Changing the map changes how many desks the floor has and where they sit. It does not move
        any particular seat: <code>docs/design/FLOOR.md § 3.2</code> assigns seats to slots by a
        function of the seats themselves, so a seat's desk can change when the slot count changes —
        deterministically, and the same way in every browser.
    </p>
@endsection
