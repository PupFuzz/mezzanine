@extends('layouts.app')
@section('title', 'Mezzanine — floor in 3-D')
@section('page-class', 'page-floor page-floor3d')

{{--
    THE 3-D FLOOR — `docs/design/FLOOR.md` § 4.4's `/floor/{floor}/3d` (card#13033 stage 2 slice 4; the design is
    `docs/design/plan-13033-stage2-3d-floor.md`): the floor page's own body (`partials/floor-page.blade.php`, the
    2-D page's too) with the 3-D stage in the drawing's place, and the 3-D entry, `public/js/floor3d/main.js`. It
    declares no `data-ride-reveals`: no ride arrives on this route before the plan's slice 6.
--}}
@section('header')
    @include('partials.floor-header')
@endsection

@section('content')
    @include('partials.floor-page', ['route' => '3d'])

    {{-- Native ES modules, no bundler — the lobby's reason (`dashboard.blade.php`). --}}
    <script type="module" src="{{ \App\Support\PublicAsset::url('js/floor3d/main.js') }}"></script>
@endsection
