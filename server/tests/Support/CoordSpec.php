<?php

namespace Tests\Support;

/**
 * `spec()` — the ONE way a coordination test builds a delivery, a header set, a body or a feed envelope
 * (`docs/design/COORD-STORE.md § 3.5` item 3, card#12239).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ HELPERS TAKE SPEC KEYS, NEVER FREE STRINGS. Every value this class hands a test is read from
 * `server/tests/fixtures/coord/spec.json` or from the generated tree the synthesizer wrote from it; a
 * name outside the spec's enumerations, a body-line key it does not list, or an override string this
 * class did not build THROWS. So a pasted body cannot enter a coordination test through a helper — and
 * the narrow lint (`tools/coord/synthesize-fixtures.py --lint`) holds this file and every test that
 * uses it to carrying no header name and no `FROM:`/`TO:` line of their own: the header names and the
 * body-line templates are the spec's, read here, and spelled nowhere in a test file.
 *
 * What this cannot stop is named in COORD-STORE.md § 3.5's *cannot catch* list: a literal split or
 * assembled at runtime by a test that bypasses this class.
 */
final class CoordSpec
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $spec;

    /** Every string `body()` built: an override string must be one of them, or it is a free string. */
    private array $built = [];

    private function __construct()
    {
        $this->spec = json_decode((string) file_get_contents(self::dir().'/spec.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function spec(): self
    {
        return self::$instance ??= new self;
    }

    public static function dir(): string
    {
        return dirname(__DIR__).'/fixtures/coord';
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->spec;
    }

    /** A repository's `owner/repo`, by the spec's binding key (`install` or `fleet`). */
    public function repository(string $which): string
    {
        return $this->spec['repositories'][$which]['full_name'] ?? throw new \InvalidArgumentException("no repository {$which} in the spec");
    }

    /** A spec name, refused if the spec does not enumerate it. */
    public function name(string $name): string
    {
        $n = $this->spec['names'];
        $all = array_merge($n['install'], $n['roundtable'], [$n['broadcast'], $n['unhoused']], $n['malformed'] ?? []);

        if (! in_array($name, $all, true)) {
            throw new \InvalidArgumentException("{$name} is not a name the coordination spec enumerates");
        }

        return $name;
    }

    /** The EXACT signed bytes of a generated delivery. */
    public function delivery(string $key): string
    {
        $file = self::dir()."/generated/deliveries/{$key}.body.json";

        if (! is_file($file)) {
            throw new \InvalidArgumentException("no generated delivery {$key}");
        }

        return (string) file_get_contents($file);
    }

    /** @return array<string, mixed> a generated delivery, decoded */
    public function payload(string $key): array
    {
        return json_decode($this->delivery($key), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A generated delivery with fields replaced — each path `a.b.c` set to a value this class built
     * (a `body()` string, a spec name, a repository) or a scalar that is not a string. Returns the
     * re-encoded bytes, which the caller signs with `headers()`.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function variant(string $key, array $overrides): string
    {
        $payload = $this->payload($key);

        foreach ($overrides as $path => $value) {
            if (is_string($value) && ! in_array($value, $this->built, true) && ! $this->isSpecValue($value)) {
                throw new \InvalidArgumentException("override {$path}: a free string is not a spec value");
            }

            $ref = &$payload;

            foreach (explode('.', $path) as $part) {
                $ref = &$ref[$part];
            }

            $ref = $value;
            unset($ref);
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A body composed from the spec's body-line keys: each argument is `[key, …args]` where `from-line`
     * takes a name, `to-line` a list of names, and `plain` / `close-token` a sentence index.
     */
    public function body(array ...$lines): string
    {
        $out = [];

        foreach ($lines as [$key, $arg]) {
            $template = $this->spec['body_lines'][$key] ?? throw new \InvalidArgumentException("{$key} is not a body-line key");
            $out[] = strtr($template, [
                '{from}' => $key === 'from-line' ? $this->name($arg) : '',
                '{to}' => $key === 'to-line' ? implode(', ', array_map(fn ($n) => $this->name($n), $arg)) : '',
                '{sentence}' => is_int($arg) ? ($this->spec['sentences'][$arg] ?? throw new \InvalidArgumentException("no sentence {$arg}")) : '',
            ]);
        }

        return $this->built[] = implode("\n", $out);
    }

    /** The header name the spec gives a role (`event`, `delivery`, `signature-256`, …). */
    public function headerName(string $role): string
    {
        foreach ($this->spec['headers'] as $h) {
            if ($h['role'] === $role) {
                return $h['name'];
            }
        }

        throw new \InvalidArgumentException("no header role {$role} in the spec");
    }

    /**
     * The request headers for a generated delivery, signed over `$body` under `$secret` — the
     * generated header set with each `sign` row computed. `$values` replaces a role's value (a
     * planted signature, a fresh delivery GUID); `$drop` leaves roles out.
     *
     * @param  array<string, string>  $values  role => value
     * @param  list<string>  $drop
     * @return array<string, string>  header name => value
     */
    public function headers(string $key, string $body, string $secret, array $values = [], array $drop = []): array
    {
        $file = self::dir()."/generated/deliveries/{$key}.headers.json";
        $rows = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $out = [];

        foreach ($rows as $row) {
            if (in_array($row['role'], $drop, true)) {
                continue;
            }

            $value = $values[$row['role']] ?? (isset($row['sign'])
                ? $row['sign'].'='.hash_hmac($row['sign'], $body, $secret)
                : $row['value']);
            $out[$row['name']] = $value;
        }

        return $out;
    }

    /** @return array<string, mixed> a generated `coord.round` feed envelope */
    public function round(string $key): array
    {
        $file = self::dir()."/generated/rounds/{$key}.json";

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function isSpecValue(string $value): bool
    {
        if (in_array($value, array_column($this->spec['repositories'], 'full_name'), true)) {
            return true;
        }

        try {
            $this->name($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
