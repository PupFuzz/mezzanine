{{--
    An instant on a server-rendered page — card#9446. `App\Support\UtcTime` owns the markup: labelled
    UTC inside a `<time datetime="…Z" data-utc>`, which `public/js/local-times.js` rewrites to the
    viewer's browser zone.
--}}
@props(['at'])
{{ \App\Support\UtcTime::html($at) }}
