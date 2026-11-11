@extends('console.layout')
@section('title', 'The building layout')
@section('console')
    <p>
        <strong>A room is an install; a floor is a set of rooms you compose</strong>
        (<code>docs/design/FLOOR.md § 4.6</code>, operator ruling card#9267). Rooms are created, named and
        retired in the <a href="{{ route('admin.rooms.index') }}">Rooms</a> module, which can also place a new
        room on a floor here as a revision of this document. This document is the composition, and it is the
        only place that says which rooms share a floor. Everything else
        about a room — how big it is, where its desks are, what it looks like — is that room's own
        map, in the <a href="{{ route('admin.floors.index') }}">floors</a> module.
    </p>

    <p>
        @if ($version === 0)
            <strong>No layout has ever been saved</strong>, which is a meaningful state and not an
            empty one: every room with a seat or a map is drawn on the <em>Unassigned</em> floor,
            in the <code>open</code> form, until a floor here places it.
        @else
            Current: <strong>revision {{ $version }}</strong>, saved <x-utc-time :at="$updatedAt" /> by
            {{ $updatedBy }}. <a href="{{ route('admin.layout.revisions') }}">Revisions, diffs and
            restores</a>
        @endif
    </p>

    <form method="POST" action="{{ route('admin.layout.update') }}">
        @csrf
        @method('PATCH')
        {{-- The revision this form was rendered from: a save is refused if the layout moved since (card#13117). --}}
        <input type="hidden" name="base_layout_version" value="{{ old('base_layout_version', $version) }}">

        <label for="layout">The layout document (JSON)</label>
        <textarea id="layout" name="layout" rows="24" cols="100" required>{{ old('layout', $document) }}</textarea>

        <button type="submit">Save</button>
    </form>

    <h2>What goes in it</h2>

    <p>
        A <code>floors</code> list. Each entry is a record carrying its <code>name</code> — what a viewer
        reads, and the floor's web address — and its <code>rooms</code>: a mapping of <code>install_id</code>
        to that room's record, <code>{"form": "open"}</code> or <code>{"form": "office"}</code>. It may carry
        <code>aliases</code>, the addresses it had before a rename. On a <strong>planned</strong> floor each
        room also carries an <code>origin</code>,
        where its top-left corner is drawn, and the floor may carry a <code>hallway</code>: a Tiled
        document for the space no room occupies.
    </p>

    <pre>{
    "floors": [
        { "name": "aimla", "rooms": { "aimla": { "form": "open" } } },
        { "name": "The Solos", "aliases": ["sola"],
          "rooms": { "sola": { "form": "office", "origin": { "x": 0,   "y": 160 } },
                     "zeta": { "form": "office", "origin": { "x": 288, "y": 160 } } } }
    ]
}</pre>

    <ul>
        <li>
            <strong>A floor's web address is its name.</strong> <code>The Solos</code> is at
            <code>/floor/the-solos</code>: accents folded, letters lowered, every run of anything but
            a–z and 0–9 one hyphen. Two floors whose names make one address are refused, and so is a name
            with no letter or digit a–z 0–9, and the name <code>Unassigned</code>, which is taken.
        </li>
        <li>
            <strong>A rename keeps the old address working only if you keep it.</strong> Put the old
            address in <code>aliases</code> and its links redirect to the floor; leave it out and they
            reach nothing. An alias is never another floor's current address, and one address is in one
            floor's list at most: to give a former address to another floor, take it out of the first
            floor's list in the same save. A link naming a room still reaches the floor that holds it.
        </li>
        <li>
            <strong>A room this document does not place still renders</strong>, on the
            <em>Unassigned</em> floor, until you place it. So a new room needs no save here to be drawn.
        </li>
        <li>
            <strong>Position lives here; size lives in the room's map.</strong> An
            <code>origin</code> says where a room is drawn and never how big it is — a big room is
            a room whose map is big (<code>§ 10.3</code>). Two rooms on one floor may share an
            <em>edge</em> and never a pixel, and a save that would overlap them is refused naming
            both.
        </li>
        <li>
            <strong>A floor may name its design</strong> with <code>"theme"</code> — one of the designs
            this build ships:
            {!! collect($themes)->map(fn (string $theme): string => '<code>'.e($theme).'</code>')->implode(', ') !!}.
            A floor that names none is drawn in the house design, <code>{{ $houseTheme }}</code>, and
            every room on the floor is drawn in the floor's design. For example
            <code>{ "name": "aimla", "theme": "{{ $houseTheme }}", "rooms": { "aimla": { "form": "open" } } }</code>. A
            design is only the room's look: every desk, fact and notice is where it is in any other.
            A name this build does not ship is refused at the save, naming the ones it does.
        </li>
        <li>
            <strong>A floor is planned or it is not.</strong> Give every room on it an
            <code>origin</code> or none of them; a floor with some rooms placed is refused. A
            <code>hallway</code> needs a planned floor — a corridor with no rooms along it is a
            picture of nothing.
        </li>
    </ul>

    <p>
        {{-- Revisions and restore: docs/design/FLEET-STATE.md § 6.11 (citation kept off-screen by
             the operator's wording ruling, 2026-09-25, card#7341 c6585). --}}
        ⚠ Every save is a <strong>revision</strong> and any of them can be restored. A save that
        changes nothing is refused rather than recorded, so a revision always records a change. A save is
        refused if the layout changed since this page was opened — by another save or a Rooms action — so
        reload and re-apply the edit. A layout that places a retired room is refused. There is no
        <strong>preview</strong> yet — the floor's own renderer is built, and this console does not
        draw with it yet — so until then the restore is what stands between a bad save and every
        viewer.
    </p>
@endsection
