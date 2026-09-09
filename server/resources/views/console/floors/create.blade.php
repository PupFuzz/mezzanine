@extends('console.layout')
@section('title', "Author a floor's map")
@section('console')
    @if ($installs === [])
        <p>
            Every floor this deploy renders already has a map. To give a map to a floor that does
            not render yet, provision a seat for its install first
            (<code>php artisan mezzanine:ingest-token:issue</code>) — the console does not create
            an install, because issuing the credential and creating the row are one act
            (<code>card#9071</code>'s ruling).
        </p>
    @else
        <form method="POST" action="{{ route('admin.floors.store') }}">
            @csrf

            <label for="install_id">Floor</label>
            <select id="install_id" name="install_id" required>
                @foreach ($installs as $install)
                    <option value="{{ $install }}" @selected(old('install_id') === $install)>
                        {{ $install }}
                    </option>
                @endforeach
            </select>

            <label for="map">Tiled map (<code>.tmj</code>)</label>
            <textarea id="map" name="map" rows="20" cols="100" required>{{ old('map') }}</textarea>

            <button type="submit">Author</button>
        </form>
    @endif

    <p>
        Export from Tiled as a <strong>JSON map</strong> (<code>.tmj</code>) with the
        <strong>tile layer format set to CSV</strong>, and reference the tileset by file rather
        than embedding its image — an embedded image has no path, so nothing can record where it
        came from (<code>docs/design/FLOOR.md § 10.1</code>). The desk slots are the objects of an
        object layer named <code>desks</code>; their count is the floor's <code>S</code>
        (<code>§ 10.3</code>). Size <code>S</code> above the seats you plan to run: a floor with
        more seats than slots still draws every one of them, in an overflow row with a notice
        (<code>§ 3.2</code>).
    </p>
@endsection
