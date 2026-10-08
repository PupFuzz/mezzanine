<?php

namespace Tests\Feature\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * card#9446 — `public/js/wire/clock.js`, the one time converter, shows an instant in the viewer's
 * zone: across a half-hour zone, a 45-minute zone, a negative half-hour zone, UTC, and both
 * daylight-saving boundaries — and in the runtime's OWN zone when no zone is named, which is the
 * path every shipped caller takes (a browser's zone on a page, `TZ` under node).
 *
 * The expectations are written out rather than computed: a converter checked against a second
 * converter in the test is two implementations agreeing with each other.
 */
class TheConverterShowsTheViewersZoneTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const AT = '2026-08-23T18:30:00.000Z';

    private ?string $zone = null;

    protected function moduleDir(): string
    {
        return $this->jsRoot();
    }

    protected function probeScript(): string
    {
        return __DIR__.'/clock-probe.mjs';
    }

    protected function probeZone(): ?string
    {
        return $this->zone;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function zones(): array
    {
        return [
            'half-hour zone' => ['Asia/Kolkata', self::AT, '00:00:00', '2026-08-24 00:00:00 GMT+5:30'],
            '45-minute zone' => ['Asia/Kathmandu', self::AT, '00:15:00', '2026-08-24 00:15:00 GMT+5:45'],
            'negative half-hour zone' => ['America/St_Johns', self::AT, '16:00:00', '2026-08-23 16:00:00 GMT-2:30'],
            'negative zone' => ['America/Los_Angeles', self::AT, '11:30:00', '2026-08-23 11:30:00 GMT-7'],
            'UTC' => ['UTC', self::AT, '18:30:00', '2026-08-23 18:30:00 UTC'],
            'the second before spring-forward' => ['America/New_York', '2026-03-08T06:59:59.000Z', '01:59:59', '2026-03-08 01:59:59 GMT-5'],
            'spring-forward' => ['America/New_York', '2026-03-08T07:00:00.000Z', '03:00:00', '2026-03-08 03:00:00 GMT-4'],
            'the first 01:00 of fall-back' => ['America/New_York', '2026-11-01T05:00:00.000Z', '01:00:00', '2026-11-01 01:00:00 GMT-4'],
            'the repeated 01:00 of fall-back' => ['America/New_York', '2026-11-01T06:00:00.000Z', '01:00:00', '2026-11-01 01:00:00 GMT-5'],
        ];
    }

    #[DataProvider('zones')]
    public function test_an_instant_is_shown_in_the_named_zone(string $zone, string $wire, string $clock, string $label): void
    {
        $out = $this->probe(['cases' => [[$wire, $zone]], 'zone_at_ms' => 0, 'zone' => 'UTC']);

        $this->assertSame(['clock' => $clock, 'label' => $label], $out['cases'][0]);
    }

    /** The path every shipped caller takes: no zone named, so the runtime's own. */
    public function test_with_no_zone_named_it_is_the_viewers_own(): void
    {
        $this->zone = 'Asia/Kolkata';

        $out = $this->probe(['cases' => [[self::AT, null]], 'zone_at_ms' => 1787509800000, 'zone' => null]);

        $this->assertSame(['clock' => '00:00:00', 'label' => '2026-08-24 00:00:00 GMT+5:30'], $out['cases'][0]);
        $this->assertSame('GMT+5:30', $out['zone_label']);
    }

    /** A value it cannot place in time is the caller's absence render, never digits. */
    public function test_an_unreadable_or_unzoned_value_is_null(): void
    {
        $out = $this->probe(['cases' => [[null, 'UTC'], ['2026-08-23 18:30:00.000', 'UTC'], ['not a time', 'UTC']], 'zone_at_ms' => 0, 'zone' => 'UTC']);

        $this->assertSame(array_fill(0, 3, ['clock' => null, 'label' => null]), $out['cases'],
            'the store spelling carries no zone, and showing it as if it did is a confident wrong time');
    }

    /**
     * CONTROL — the converter as it was before card#9446, reading the wire's UTC digits and converting
     * nothing. The zone assertion must red on it, or it is not measuring a conversion.
     */
    public function test_the_unconverted_reading_reds(): void
    {
        $this->zone = 'Asia/Kolkata';

        $planted = $this->mutatedModules([
            'wire/clock.js',
            "    const p = parts(ms, zone);\n\n    return `\${p.hour}:\${p.minute}:\${p.second}`;",
            "    const m = wireTime.match(/T(\\d{2}):(\\d{2}):(\\d{2})/);\n\n    return `\${m[1]}:\${m[2]}:\${m[3]}`;",
        ]);

        $out = $this->probe(['cases' => [[self::AT, null]], 'zone_at_ms' => 0, 'zone' => null], $planted);

        $this->assertSame('18:30:00', $out['cases'][0]['clock'], 'the plant did not take');
        $this->assertNotSame('00:00:00', $out['cases'][0]['clock']);
    }
}
