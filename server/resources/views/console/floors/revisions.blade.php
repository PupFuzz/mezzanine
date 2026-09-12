@extends('console.layout')
@section('title', 'Revisions — '.$installId)
@section('console')
    <p>
        Every save of <strong>{{ $installId }}</strong>'s map, newest first
        (<code>docs/design/FLEET-STATE.md § 6.11</code>). Nothing is ever rewritten here: a
        <strong>restore</strong> is a new revision that copies an old one, so undoing a restore is
        itself a restore, and a <strong>removal</strong> is a revision too — the map it removed is
        still here to restore.
    </p>

    @if ($current === null)
        <p>
            <strong>No map is current for this room</strong>, so it renders the shipped default
            (<code>docs/design/FLOOR.md § 10.3</code>) until one is authored or an old one is
            restored.
        </p>
    @else
        <p>
            Current: <strong>revision {{ $current->map_version }}</strong>, saved
            {{ $current->updated_at }} by {{ $current->updated_by }}.
            <a href="{{ route('admin.floors.edit', $installId) }}">Edit the map</a>
        </p>
    @endif

    @if ($revisions === [])
        <p>This room has no revisions — nobody has authored a map for it.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Revision</th><th>What it records</th><th>Desk slots</th>
                    <th>Authored</th><th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($revisions as $revision)
                    <tr>
                        <td>
                            {{ $revision['revision'] }}
                            @if ($current !== null && (int) $current->map_version === $revision['revision'])
                                <br><strong>current</strong>
                            @endif
                        </td>
                        <td>
                            @if ($revision['removal'])
                                <strong>the map was removed</strong> — the room went back to the
                                shipped default
                            @elseif ($revision['restored_from'] !== null)
                                a restore of revision {{ $revision['restored_from'] }}
                                ({{ number_format($revision['bytes']) }} bytes)
                            @else
                                a save ({{ number_format($revision['bytes']) }} bytes)
                            @endif
                        </td>
                        <td>
                            {{-- `S` is derived from the document by the one parser that owns it,
                                 never stored beside it (§ 10.3). A revision this application can
                                 no longer read says so rather than showing a zero. --}}
                            @if ($revision['removal'])
                                —
                            @elseif ($revision['slots'] === null)
                                <strong>this document can no longer be read</strong>
                            @else
                                {{ $revision['slots'] }}
                            @endif
                        </td>
                        <td>{{ $revision['authored_at'] }}<br>by {{ $revision['authored_by'] }}</td>
                        <td>
                            @unless ($revision['removal'])
                                <a href="{{ route('admin.floors.export', [$installId, $revision['revision']]) }}">Export
                                    <code>.tmj</code></a>
                            @endunless

                            @if ($current === null || (int) $current->map_version !== $revision['revision'])
                                <form method="POST"
                                      action="{{ route('admin.floors.restore', [$installId, $revision['revision']]) }}">
                                    @csrf
                                    <button type="submit">Restore this one</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (count($revisions) > 1)
            <h2>Compare two revisions</h2>
            <form method="GET" action="{{ route('admin.floors.diff', $installId) }}">
                <label for="from">From revision</label>
                <input id="from" name="from" type="number" min="1" required
                       value="{{ $revisions[count($revisions) - 1]['revision'] }}">

                <label for="to">To revision</label>
                <input id="to" name="to" type="number" min="1" required
                       value="{{ $revisions[0]['revision'] }}">

                <button type="submit">Show the diff</button>
            </form>
        @endif
    @endif

    <p>
        An export is your own copy against a lost store — and it is how an authored room becomes
        the repository's shipped default (<code>§ 6.11</code>), vendored with the
        <code>docs/ATTRIBUTION.md</code> row the asset gates require.
    </p>

    <p>
        ⚠ There is <strong>no preview</strong> yet: it draws with the floor's own renderer, which
        is not built (<code>docs/design/FLOOR.md</code> Appendix B step 7). Until it is, a restore
        from this page is the only thing between a bad save and every viewer — which is why it is
        here.
    </p>

    <p><a href="{{ route('admin.floors.index') }}">Back to the floors</a></p>
@endsection
