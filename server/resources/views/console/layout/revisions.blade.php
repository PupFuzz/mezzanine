@extends('console.layout')
@section('title', 'Building layout revisions')
@section('console')
    <p>
        Every save of the building layout, newest first
        (<code>docs/design/FLEET-STATE.md § 6.11</code>). A <strong>restore</strong> is a new
        revision copying an old one — history is never rewritten, and undoing a restore is itself a
        restore. A restore is also <strong>re-checked against today's room maps</strong>, so
        undoing a rearrangement can never re-create an overlap a later map made.
    </p>

    @if ($revisions === [])
        <p>
            No layout has ever been saved, so there is nothing to restore. Every install renders on
            a floor of its own until one is —
            <a href="{{ route('admin.layout.edit') }}">compose the first one</a>.
        </p>
    @else
        <table>
            <thead>
                <tr><th>Revision</th><th>What it records</th><th>Floors</th><th>Authored</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($revisions as $revision)
                    <tr>
                        <td>
                            {{ $revision['revision'] }}
                            @if ($version === $revision['revision'])
                                <br><strong>current</strong>
                            @endif
                        </td>
                        <td>
                            @if ($revision['restored_from'] !== null)
                                a restore of revision {{ $revision['restored_from'] }}
                            @else
                                a save
                            @endif
                            ({{ number_format($revision['bytes']) }} bytes)
                        </td>
                        <td>
                            @if ($revision['floors'] === null)
                                <strong>this document can no longer be read</strong>
                            @else
                                {{ $revision['floors'] }} composed
                            @endif
                        </td>
                        <td>{{ $revision['authored_at'] }}<br>by {{ $revision['authored_by'] }}</td>
                        <td>
                            <a href="{{ route('admin.layout.export', $revision['revision']) }}">Export
                                <code>.json</code></a>

                            @if ($version !== $revision['revision'])
                                <form method="POST" action="{{ route('admin.layout.restore', $revision['revision']) }}">
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
            <form method="GET" action="{{ route('admin.layout.diff') }}">
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

    <p><a href="{{ route('admin.layout.edit') }}">Back to the layout</a></p>
@endsection
