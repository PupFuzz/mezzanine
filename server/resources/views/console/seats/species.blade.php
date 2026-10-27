{{--
    A seat's character (card#12443, docs/design/FLEET-STATE.md § 6.13): the species it was allocated, read-only.
    A seat already retired when the migration ran was written the species its key draws today, marked by
    `species_drawn_at`, and is labelled so — it is what a floor showed it as only while the draw has not moved.
--}}
{{ $seat->species }}@if ($seat->species_drawn_at !== null) <em>(today's draw)</em>@endif
