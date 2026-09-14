@extends('console.layout')
@section('title', 'Building layout diff')
@section('console')
    <p>The building layout: revision {{ $from }} → revision {{ $to }}.</p>

    @include('console.partials.revision-diff', ['diff' => $diff])

    <p><a href="{{ route('admin.layout.revisions') }}">Back to the layout's revisions</a></p>
@endsection
