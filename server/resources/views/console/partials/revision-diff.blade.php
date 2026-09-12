{{--
    THE DIFF, RENDERED — `docs/design/FLEET-STATE.md § 6.11`: "the console shows two revisions side
    by side and names what moved: the tile layers whose data differ, the desk count `S` before and
    after, and a line diff of the two documents pretty-printed."

    ONE PARTIAL FOR BOTH SUBJECTS. A room's map and the building layout are diffed by the same
    `App\Building\RevisionDiff`, so they are RENDERED by one template too: two copies would drift
    the first time one of them learned to show something.

    The structural half is hidden for a document that has none — a layout has no tile layers and no
    `S` — rather than printed as zeroes, because a zero there would be a claim about a room.
--}}
@if ($diff['structural'] && $diff['layers'] !== [])
    <h3>What moved</h3>
    <ul>
        @foreach ($diff['layers'] as $layer)
            <li>
                <code>{{ $layer['name'] }}</code> — {{ $layer['change'] }}
            </li>
        @endforeach
    </ul>
@endif

@if ($diff['slots']['before'] !== null || $diff['slots']['after'] !== null)
    <p>
        <strong>Desk slots (<code>S</code>):</strong>
        {{ $diff['slots']['before'] ?? '—' }} → {{ $diff['slots']['after'] ?? '—' }}.
        @if ($diff['slots']['before'] !== null && $diff['slots']['after'] !== null && $diff['slots']['before'] !== $diff['slots']['after'])
            A save that changes <code>S</code> re-slots <strong>every</strong> desk in that room —
            the slot function is <code>h mod S</code> (<code>docs/design/FLOOR.md § 3.2</code>) — and
            a save that keeps it moves no desk at all.
        @endif
    </p>
@endif

@if ($diff['truncated'])
    <p>
        <strong>These two documents differ by more lines than this diff pairs up</strong>
        ({{ \App\Building\RevisionDiff::LCS_CAP }}), so what follows is the two blocks rather than
        a line-by-line pairing. What changed structurally is above, and it is the half that reads.
    </p>
@endif

<h3>Line diff</h3>
<pre>@foreach ($diff['lines'] as $line){{ $line['op'] }} {{ $line['text'] }}
@endforeach</pre>
