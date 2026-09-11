@extends('console.layout')
@section('title', 'Floors')
@section('console')
    <p>
        A <strong>room</strong> is an install and a <strong>floor</strong> is an
        operator-composed set of rooms (<code>docs/design/FLOOR.md § 3.1</code>, § 4.6 — operator
        ruling, card#9267). Each row below is a ROOM, and its map declares how many desks it has
        and where they sit. <strong>Which seat sits at which desk is not stored anywhere</strong>
        — it is derived from the seats the room renders (§ 3.2), so two browsers and two restarts
        agree without anyone placing anybody.
    </p>

    <p>
        <strong>Which rooms share a floor is not authored here.</strong> It is
        <code>config/building.php</code>, a deploy-time document (§ 4.6), so rearranging the
        building is a deploy and gets a diff and a review. Rooms sharing a <em>Floor</em> value
        below are drawn on one screen; a room the layout does not place gets a floor of its own.
    </p>

    <table>
        <thead>
            <tr>
                <th>Room</th><th>Floor</th><th>Seats rendered</th><th>Map</th>
                <th>Last authored</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>
                        {{ $row['install_id'] }}
                        @unless ($row['renders'])
                            <br><em>no seat in this room renders</em>
                        @endunless
                    </td>
                    <td>
                        @if ($row['floor'] === null)
                            {{-- § 4.6: placed nowhere and reporting nothing, so nothing draws it. --}}
                            <strong>on no floor</strong> — the layout places this room nowhere and
                            the fleet reports no seat for it
                        @else
                            {{ $row['floor'] }}
                            <br>{{ $row['form'] }}
                            @unless ($row['renders'])
                                <br><strong>no seats reported for this room</strong> — the floor
                                draws it and says so, never omits it
                            @endunless
                        @endif
                    </td>
                    <td>{{ $row['seats'] }} {{ $row['seats'] === 1 ? 'seat' : 'seats' }}</td>
                    <td>
                        @if (! $row['authored'])
                            no map authored
                        @elseif ($row['unreadable'] !== null)
                            <strong>this map can no longer be read:</strong> {{ $row['unreadable'] }}
                        @else
                            {{ $row['slots'] }} desk slots
                            @if ($row['short_by'] > 0)
                                <br><strong>floor map is short {{ $row['short_by'] }} desks</strong>
                                — every seat past the {{ $row['slots'] }}th is drawn in § 3.2's
                                overflow row until this map declares more.
                            @endif
                        @endif
                    </td>
                    <td>
                        @if ($row['authored'])
                            {{ $row['updated_at'] }} by {{ $row['updated_by'] }}
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($row['authored'])
                            <a href="{{ route('admin.floors.edit', $row['install_id']) }}">Edit map</a>
                            <form method="POST"
                                  action="{{ route('admin.floors.remove', $row['install_id']) }}">
                                @csrf
                                <button type="submit">Remove map</button>
                            </form>
                        @elseif ($row['renders'])
                            <a href="{{ route('admin.floors.create') }}">Author a map</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    No install has been provisioned yet, so there is no room to give a map to.
                    An install exists once a seat is provisioned for it with
                    <code>php artisan mezzanine:ingest-token:issue</code>.
                </td></tr>
            @endforelse
        </tbody>
    </table>

    <p><a href="{{ route('admin.floors.create') }}">Author a room's map</a></p>

    <p>
        Removing a map removes the room and nothing else: the install, its seats and their state
        are the fleet's, and this console does not write them.
    </p>
@endsection
