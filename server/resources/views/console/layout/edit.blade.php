@extends('console.layout')
@section('title', 'The building layout')
@section('console')
    <p>
        <strong>A room is an install; a floor is a set of rooms you compose</strong>
        (<code>docs/design/FLOOR.md § 4.6</code>, operator ruling card#9267). This document is the
        composition, and it is the only place that says which rooms share a floor. Everything else
        about a room — how big it is, where its desks are, what it looks like — is that room's own
        map, in the <a href="{{ route('admin.floors.index') }}">floors</a> module.
    </p>

    <p>
        @if ($version === 0)
            <strong>No layout has ever been saved</strong>, which is a meaningful state and not an
            empty one: every install renders on a floor of its own, alone, in the <code>open</code>
            form — exactly the building this deployment drew before floors could be composed.
        @else
            Current: <strong>revision {{ $version }}</strong>, saved {{ $updatedAt }} by
            {{ $updatedBy }}. <a href="{{ route('admin.layout.revisions') }}">Revisions, diffs and
            restores</a>
        @endif
    </p>

    <form method="POST" action="{{ route('admin.layout.update') }}">
        @csrf
        @method('PATCH')

        <label for="layout">The layout document (JSON)</label>
        <textarea id="layout" name="layout" rows="24" cols="100" required>{{ old('layout', $document) }}</textarea>

        <button type="submit">Save</button>
    </form>

    <h2>What goes in it</h2>

    <p>
        A <code>floors</code> list. Each entry is a record carrying its <code>rooms</code> — a
        mapping of <code>install_id</code> to that room's record, <code>{"form": "open"}</code> or
        <code>{"form": "office"}</code> — and optionally a <code>label</code>, the name a viewer
        sees. On a <strong>planned</strong> floor each room also carries an <code>origin</code>,
        where its top-left corner is drawn, and the floor may carry a <code>hallway</code>: a Tiled
        document for the space no room occupies.
    </p>

    <pre>{
    "floors": [
        { "rooms": { "aimla": { "form": "open" } } },
        { "label": "the solos",
          "rooms": { "sola": { "form": "office", "origin": { "x": 0,   "y": 160 } },
                     "zeta": { "form": "office", "origin": { "x": 288, "y": 160 } } } }
    ]
}</pre>

    <ul>
        <li>
            <strong>A floor has no id and you cannot give it one.</strong> Its key is derived — the
            lexically least <code>install_id</code> on it — so floor keys and install ids can never
            collide in one link namespace. A <code>label</code> is what a person reads and is never
            a key: editing one moves no floor and breaks no link.
        </li>
        <li>
            <strong>An install this document does not place still renders</strong>, on a floor of
            its own, alone, <code>open</code>. So this is a departure from a default and never a
            list that has to be complete: provisioning an install needs no save here to draw it.
        </li>
        <li>
            <strong>Position lives here; size lives in the room's map.</strong> An
            <code>origin</code> says where a room is drawn and never how big it is — a big room is
            a room whose map is big (<code>§ 10.3</code>). Two rooms on one floor may share an
            <em>edge</em> and never a pixel, and a save that would overlap them is refused naming
            both.
        </li>
        <li>
            <strong>A floor is planned or it is not.</strong> Give every room on it an
            <code>origin</code> or none of them; a floor with some rooms placed is refused. A
            <code>hallway</code> needs a planned floor — a corridor with no rooms along it is a
            picture of nothing.
        </li>
    </ul>

    <p>
        ⚠ Every save is a <strong>revision</strong> and any of them can be restored
        (<code>docs/design/FLEET-STATE.md § 6.11</code>). A save that changes nothing is refused
        rather than recorded, so a revision always records a change. There is no
        <strong>preview</strong> yet — it draws with the floor's own renderer, which is not built —
        so until then the restore is what stands between a bad save and every viewer.
    </p>
@endsection
