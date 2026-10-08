<?php

namespace Tests\Feature\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * card#9446 — every time this product shows a person is in the viewer's browser zone, because every
 * one of them goes through ONE converter: `public/js/wire/clock.js`, which the server-rendered pages
 * reach through `App\Support\UtcTime` (`<x-utc-time>`) and `public/js/local-times.js`. This guard
 * reds when a source file outside the converter prints an instant in one of the shapes below. What
 * the converter itself does with a zone is `TheConverterShowsTheViewersZoneTest`'s, not this one's.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE POPULATION IS DERIVED FROM THE TREE ON EVERY RUN — every `.js` under `public/js`, every
 * `.blade.php` under `resources/views`, every `.php` under `app/Http` — and each scan asserts it
 * read a non-trivial number of files, so a moved directory cannot turn this into a clean report over
 * nothing.
 *
 * ⛔ EACH RULE IS SEEN TO FAIL ON EVERY RUN. `test_each_rule_reds_on_a_planted_raw_print` feeds
 * every rule a planted raw print of the shape it exists to catch — the console's raw prints and the
 * retirement flash as they were before card#9446 among them — and requires a finding.
 *
 * ⚠ WHAT THE SCAN CANNOT SEE, STATED RATHER THAN IMPLIED. It reads source by pattern. A time
 * formatted by a route the patterns do not name — a `sprintf('%s', $x->updated_at)` with the
 * member on another line, a new date library, a value renamed so it ends in none of `_at`,
 * `_since`, `_time`, `At` — is outside it. Widen the patterns here when the tree gains such a shape.
 */
class EveryTimeGoesThroughTheConverterTest extends TestCase
{
    use DrivesAShippedClientModule;

    /** The converter itself — the one file the client rules do not apply to. */
    private const CONVERTER = 'wire/clock.js';

    /**
     * Client-side ways to turn an instant into clock digits without the converter. Each is
     * `[name, regex]`, run over comment-stripped source.
     */
    private const JS_RULES = [
        ['Intl.DateTimeFormat', '/\bIntl\.DateTimeFormat\b/'],
        ['toLocale*String', '/\.toLocale(?:Date|Time)?String\(/'],
        ['toISOString/toUTCString', '/\.to(?:ISO|UTC)String\(/'],
        ['getUTC*', '/\.getUTC\w*\(/'],
        ['Date clock getter', '/\.get(?:Hours|Minutes|Seconds|Date|Month|FullYear)\(/'],
        ['wire digits cut by position', '/\.(?:slice|substring|substr)\(\s*1[0-9]\b/'],
        ['wire split at T', '/\.split\(\s*[\'"]T[\'"]\s*\)/'],
        // A CAPTURE of the clock digits: `wire/duration.js`'s `wireMs()` matches the same shape uncaptured, to
        // validate a value it then parses to an epoch, which is a reading of the instant and not of its digits.
        ['wire digits matched by pattern', '/T\(\\\\d\{2\}\)/'],
        ['a time member interpolated raw', '/\$\{(?![^}]*\b(?:clockTime|seatClock)\()[^}]*(?:\w_at|_since|_time|\.at|as_of)\b[^}]*\}/'],
        ['a time member written to the page raw', '/\.textContent\s*=(?![^;]*\b(?:clockTime|seatClock|dateTimeLabel)\()[^;]*(?:\w_at|_since|_time|\.at|as_of)\b/'],
    ];

    /**
     * The client sites that read a Date's clock getters on purpose, each with why. It is checked
     * both ways: an allowance that no longer matches anything reds, so it cannot outlive its site.
     */
    private const JS_ALLOWED = [
        ['floor/floor-layout.js', 'Date clock getter',
            '§ 6.2 A17\'s wall clock reads the VIEWER\'S OWN machine in its own zone — a fact about the '
            .'viewer\'s environment, never a wire instant (FLOOR.md § 2.4, § 5.5)'],
    ];

    /** A Blade interpolation that prints an instant raw. */
    private const BLADE_RULES = [
        ['a time member interpolated raw', '/\{\{(?:(?!\}\}).)*?(?:\w_at\b|\w_since\b|\w_time\b|\w[a-z]At\b|->at\b)(?:(?!\}\}).)*\}\}/s'],
        ['a time member interpolated raw, unescaped', '/\{!!(?:(?!!!\}).)*?(?:\w_at\b|\w_since\b|\w_time\b|\w[a-z]At\b|->at\b)(?:(?!!!\}).)*!!\}/s'],
        ['a date formatted in a view', '/->format\(|toDateTimeString|toTimeString|diffForHumans|\bdate\(/'],
    ];

    /** A controller or response class that concatenates an instant into text a page will print. */
    private const HTTP_RULES = [
        ['a time member concatenated into text', '/\.\s*\$[\w>\-\[\]\'"]*(?:\w_at\b|->at\b|[a-z]At\b)|(?:\w_at\b|->at\b|[a-z]At\b)[\]\'"]*\s*\.\s*[\'"]/'],
    ];

    protected function moduleDir(): string
    {
        return $this->jsRoot();
    }

    protected function probeScript(): string
    {
        return '';
    }

    public function test_the_shipped_client_formats_no_instant_outside_the_converter(): void
    {
        $files = $this->files($this->jsRoot(), '.js');
        $this->assertGreaterThan(30, count($files), 'the client scan read almost nothing — public/js has moved');
        $this->assertArrayHasKey(self::CONVERTER, $files, 'the converter is not where this guard says it is');

        $findings = [];
        $allowed = array_fill_keys(array_map(static fn (array $a): string => $a[0].' :: '.$a[1], self::JS_ALLOWED), 0);

        foreach ($files as $relative => $path) {
            if ($relative === self::CONVERTER) {
                continue;
            }

            foreach ($this->scan(self::JS_RULES, $this->moduleSource($relative)) as $rule) {
                $key = $relative.' :: '.$rule;

                if (array_key_exists($key, $allowed)) {
                    $allowed[$key]++;

                    continue;
                }

                $findings[] = $key;
            }
        }

        $this->assertSame([], $findings,
            "a client module formats an instant without public/js/wire/clock.js — the viewer would read it in some zone other than their own:\n"
            .implode("\n", $findings));
        $this->assertSame([], array_keys(array_filter($allowed, static fn (int $n): bool => $n === 0)),
            'an allowance in JS_ALLOWED matches nothing any more — remove it, so it cannot excuse the next site');
    }

    public function test_the_views_print_every_instant_through_the_converter(): void
    {
        $files = $this->files(resource_path('views'), '.blade.php');
        $this->assertGreaterThan(20, count($files), 'the view scan read almost nothing — resources/views has moved');

        $findings = [];

        foreach ($files as $relative => $path) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

            foreach ($this->scan(self::BLADE_RULES, $source) as $rule) {
                $findings[] = $relative.' :: '.$rule;
            }
        }

        $this->assertSame([], $findings,
            "a view prints an instant without <x-utc-time> — the viewer would read raw UTC:\n".implode("\n", $findings));
    }

    public function test_no_controller_builds_an_instant_into_a_message(): void
    {
        $files = $this->files(app_path('Http'), '.php');
        $this->assertGreaterThan(10, count($files), 'the app/Http scan read almost nothing — it has moved');

        $findings = [];

        foreach ($files as $relative => $path) {
            $source = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string) file_get_contents($path));

            foreach ($this->scan(self::HTTP_RULES, $source) as $rule) {
                $findings[] = $relative.' :: '.$rule;
            }
        }

        $this->assertSame([], $findings,
            "a controller concatenates an instant into text a page prints as-is — it reaches the viewer as raw UTC:\n"
            .implode("\n", $findings));
    }

    /**
     * Every rule above, fed the raw print it exists to catch. A rule that finds nothing here would
     * report clean over any tree, so this is what makes the three scans evidence.
     */
    public function test_each_rule_reds_on_a_planted_raw_print(): void
    {
        $plants = [
            [self::JS_RULES, 'Intl.DateTimeFormat', "const t = new Intl.DateTimeFormat('en', {}).format(d);"],
            [self::JS_RULES, 'toLocale*String', 'el.textContent = new Date(ms).toLocaleTimeString();'],
            [self::JS_RULES, 'toISOString/toUTCString', 'return new Date(ms).toISOString();'],
            [self::JS_RULES, 'getUTC*', 'const h = d.getUTCHours();'],
            [self::JS_RULES, 'Date clock getter', 'const h = d.getHours();'],
            [self::JS_RULES, 'wire digits cut by position', 'return wire.slice(11, 19);'],
            [self::JS_RULES, 'wire split at T', "const [, time] = wire.split('T');"],
            // `wire/clock.js`'s own reading before card#9446 — the wire's UTC digits, converted nowhere.
            [self::JS_RULES, 'wire digits matched by pattern', 'const m = wireTime.match(/T(\d{2}):(\d{2}):(\d{2})/);'],
            [self::JS_RULES, 'a time member interpolated raw', 'return `last run ${health.sweep_last_run_at}`;'],
            [self::JS_RULES, 'a time member written to the page raw', 'face.textContent = seat.delivery.no_data_since;'],
            // The eight console sites as they printed before card#9446.
            [self::BLADE_RULES, 'a time member interpolated raw', "{{ \$row['updated_at'] }} by {{ \$row['updated_by'] }}"],
            [self::BLADE_RULES, 'a time member interpolated raw', '{{ $current->updated_at }} by'],
            [self::BLADE_RULES, 'a time member interpolated raw', "<td>{{ \$revision['authored_at'] }}<br>"],
            [self::BLADE_RULES, 'a time member interpolated raw', 'saved {{ $updatedAt }} by'],
            [self::BLADE_RULES, 'a time member interpolated raw', "<td>{{ \$seat->last_activity_received_at ?? '—' }}</td>"],
            [self::BLADE_RULES, 'a time member interpolated raw', '<td>{{ $seat->retired_at }}</td>'],
            [self::BLADE_RULES, 'a time member interpolated raw', 'retired {{ $user->retired_at }} by'],
            [self::BLADE_RULES, 'a time member interpolated raw, unescaped', '{!! $seat->retired_at !!}'],
            [self::BLADE_RULES, 'a date formatted in a view', "{{ \$x->format('H:i') }}"],
            // The retirement flash as it was built before card#9446.
            [self::HTTP_RULES, 'a time member concatenated into text', "\$seat.' retired at '.\$outcome->at.' — state_version '"],
            [self::HTTP_RULES, 'a time member concatenated into text', "'saved '.\$row->updated_at"],
        ];

        foreach ($plants as [$rules, $rule, $source]) {
            $this->assertContains($rule, $this->scan($rules, $source),
                "the rule `{$rule}` did not red on its planted raw print, so it reports clean over anything: {$source}");
        }

        // And the converter's own output reds nothing — a guard that flagged the fix would be routed around.
        foreach ([
            [self::JS_RULES, 'return `last run ${clockTime(health.sweep_last_run_at) ?? NOT_REPORTED}`;'],
            [self::BLADE_RULES, '<x-utc-time :at="$seat->retired_at" />'],
            [self::BLADE_RULES, '{{ \App\Support\UtcTime::html($at) }}'],
            [self::HTTP_RULES, "'authored_at' => (string) \$row->authored_at,"],
        ] as [$rules, $source]) {
            $this->assertSame([], $this->scan($rules, $source), "a converted print was flagged: {$source}");
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rules
     * @return list<string> the names of the rules `$source` breaks
     */
    private function scan(array $rules, string $source): array
    {
        $hit = [];

        foreach ($rules as [$name, $regex]) {
            if (preg_match($regex, $source) === 1) {
                $hit[] = $name;
            }
        }

        return $hit;
    }

    /** @return array<string, string> relative path => absolute path, every file under `$root` ending in `$suffix` */
    private function files(string $root, string $suffix): array
    {
        $out = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;

            if (str_ends_with($path, $suffix)) {
                $out[substr($path, strlen($root) + 1)] = $path;
            }
        }

        ksort($out);

        return $out;
    }
}
