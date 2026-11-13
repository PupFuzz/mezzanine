@extends('layouts.app')
@section('title', 'Mezzanine — floor')
@section('page-class', 'page-floor')
{{-- § 4.5's ride arrives here with the cab's snapshot held until the first drawn scene (`layouts/app.blade.php`). --}}
@section('document-attributes', 'data-ride-reveals')

{{-- The header's right-hand side, and the page's body: both the floor pages' (`partials/floor-header`, `partials/floor-page`). --}}
@section('header')
    @include('partials.floor-header')
@endsection

@section('content')
    @include('partials.floor-page', ['route' => '2d'])

    {{-- Native ES modules, no bundler — the lobby's reason (`dashboard.blade.php`). --}}
    <script type="module" src="{{ \App\Support\PublicAsset::url('js/floor/main.js') }}"></script>
@endsection
