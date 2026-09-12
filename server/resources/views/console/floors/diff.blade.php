@extends('console.layout')
@section('title', 'Diff — '.$installId)
@section('console')
    <p>
        <strong>{{ $installId }}</strong>: revision {{ $from }} → revision {{ $to }}.
    </p>

    @include('console.partials.revision-diff', ['diff' => $diff])

    <p><a href="{{ route('admin.floors.revisions', $installId) }}">Back to this room's revisions</a></p>
@endsection
