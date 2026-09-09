<?php

namespace Tests\Feature\Admin;

use App\Auth\ActiveUserProvider;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ⛔ CANON #20 ON THIS APPLICATION'S AUTH SURFACE: a password must never reach a rendered page, a
 * flashed input, a validation message, a log line or a command's output — and a failed login must
 * not tell a caller whether the address exists.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ EVERY ARM BELOW ASSERTS ON A SURFACE THAT COULD ACTUALLY CARRY THE VALUE, and the log arm
 * carries a CANARY: an empty log file proves nothing until the file has been shown to capture
 * something, because "no secret in the log" and "no log" are the same bytes.
 */
class AuthSecretsNeverSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive enough that a substring search for it cannot match anything incidental. */
    private const SECRET = 'zaphod-beeblebrox-42-improbability';

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'mezzanine-canon20-').'.log';

        config([
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);

        Log::forgetChannel();
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    /**
     * Every message the last response flashed, flattened.
     *
     * ⚠ `session('errors')` is a plain ARRAY under this suite's `SESSION_DRIVER=array` rather than
     * the `ViewErrorBag` a live request rehydrates, so the shape is normalised here instead of
     * being assumed at four call sites.
     *
     * @return list<string>
     */
    private function messages(): array
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default')->all();
        }

        return collect((array) $errors)->flatten()->map(fn ($m) => (string) $m)->all();
    }

    private function log(): string
    {
        // THE CANARY — written after the exercise, read back with it. If this string is missing,
        // the file is not the log this application writes to and every "absent" assertion beside
        // it is meaningless rather than reassuring.
        Log::error('canary-line-proving-this-file-is-live');

        $contents = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';

        $this->assertStringContainsString('canary-line-proving-this-file-is-live', $contents,
            'the log capture is not live, so nothing below is a measurement');

        return $contents;
    }

    // ── The console's create form ───────────────────────────────────────────────────────────

    public function test_a_rejected_create_puts_no_password_in_the_response_the_session_or_the_log(): void
    {
        $operator = User::factory()->twoFactorConfirmed()->create();

        // A password that fails the policy for LENGTH, so the rejection is about the value itself
        // — the failure mode most likely to quote it back.
        $response = $this->actingAs($operator)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'New Operator',
                'email' => 'new@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response->assertSessionHasErrors('password');

        foreach ($this->messages() as $message) {
            $this->assertStringNotContainsString('short', $message,
                'a validation message must name the attribute, never the value');
        }

        // The flashed input is what the form re-renders from. Laravel excludes the password keys
        // by default (`Handler::$dontFlash`); this application DEPENDS on that, so it is asserted
        // rather than trusted — an upgrade that changed the default would otherwise start
        // rendering credentials into the HTML of every rejected form with nothing to say so.
        $flashed = session('_old_input', []);
        $this->assertArrayHasKey('email', $flashed, 'the control: the non-secret input IS flashed');
        $this->assertArrayNotHasKey('password', $flashed);
        $this->assertArrayNotHasKey('password_confirmation', $flashed);

        $this->assertStringNotContainsString('short', $this->log());
    }

    public function test_the_rerendered_form_after_a_rejected_create_contains_no_password(): void
    {
        $operator = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($operator)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'New Operator',
                'email' => 'new@example.com',
                'password' => self::SECRET,
                'password_confirmation' => 'something else entirely',
            ])->assertSessionHasErrors('password');

        // Following the redirect back is the surface an operator actually looks at.
        $this->actingAs($operator)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertDontSee(self::SECRET);
    }

    // ── The login form ──────────────────────────────────────────────────────────────────────

    public function test_a_failed_login_is_word_for_word_identical_for_an_unknown_and_a_known_address(): void
    {
        $known = User::factory()->twoFactorUnenrolled()->create();

        // Each answer is read IMMEDIATELY after its own request: the two share one session in the
        // harness, so reading both at the end would compare the second answer with itself.
        $wrongPassword = $this->post('/login', [
            'email' => $known->email, 'password' => 'not this account\'s password',
        ]);
        $wrongPasswordErrors = $this->messages();

        $this->flushSession();

        $unknownAddress = $this->post('/login', [
            'email' => 'nobody-has-this-address@example.com', 'password' => 'not this account\'s password',
        ]);
        $unknownAddressErrors = $this->messages();

        $this->assertSame($wrongPassword->getStatusCode(), $unknownAddress->getStatusCode());
        $this->assertSame($wrongPassword->headers->get('Location'), $unknownAddress->headers->get('Location'));

        $this->assertNotSame([], $wrongPasswordErrors, 'the control: there IS a message to compare');
        $this->assertSame(
            $wrongPasswordErrors,
            $unknownAddressErrors,
            'the two answers must be indistinguishable — anything else is a user-enumeration oracle',
        );

        $this->assertGuest();
        $this->assertStringNotContainsString('not this account\'s password', $this->log());
    }

    /**
     * A real hasher with a counter around it. Mockery counts CALLS BY NAME, which is how the arm
     * this replaces came to be blind: it asserted `check()` once and merely STUBBED `make()`, so a
     * build that paid for an extra bcrypt on the miss path passed it. What the property is about
     * is TOTAL hashing work, so that is what gets counted.
     */
    private function countingHasher(): Hasher
    {
        return new class(app('hash')) implements Hasher
        {
            public int $makes = 0;

            public int $checks = 0;

            public function __construct(private Hasher $inner) {}

            public function total(): int
            {
                return $this->makes + $this->checks;
            }

            public function reset(): void
            {
                $this->makes = $this->checks = 0;
            }

            public function info($hashedValue)
            {
                return $this->inner->info($hashedValue);
            }

            public function make(#[\SensitiveParameter] $value, array $options = [])
            {
                $this->makes++;

                return $this->inner->make($value, $options);
            }

            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }
        };
    }

    /**
     * ⛔ THE OTHER HALF OF "INDISTINGUISHABLE": THE WORK DONE, COUNTED IN FULL. Stock Laravel
     * returns from `retrieveByCredentials()` before any hashing when the address is unknown, so a
     * miss costs no bcrypt and a hit costs one — tens of milliseconds apart at production cost
     * factors, which is an enumeration oracle in timing even though both answers say the same
     * words.
     *
     * ⚠ THIS ARM COUNTS `make()` AND `check()` TOGETHER, AND THE REASON IS A MEASURED DEFECT RATHER
     * THAN THOROUGHNESS. The version of this arm that shipped in the first round counted `check()`
     * alone and stubbed `make()` with no count at all — so it was structurally unable to observe
     * the build it was written to defend: the dummy hash was memoised into an INSTANCE property of
     * a provider the container rebuilds every request, which made a miss cost TWO bcrypts and a
     * wrong password on an existing account cost ONE. The oracle's magnitude was unchanged; only
     * its sign flipped, and the arm was green throughout. A test that cannot observe the defect it
     * names is not evidence.
     *
     * Asserted by COUNTING rather than by measuring a clock: a timing assertion is flaky by
     * construction and would be turned off by the first person it failed for.
     */
    public function test_a_miss_and_a_hit_cost_the_same_total_hashing_work(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $hasher = $this->countingHasher();

        // The dummy hash is minted once per DEPLOYMENT, so the state this arm is about is the one
        // every request after the first sees. Warming it here — through a provider that is then
        // thrown away — is also what makes the comparison below fair rather than an argument about
        // which path happened to run first.
        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'warming@example.com', 'password' => self::SECRET]);

        $hasher->reset();
        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'nobody@example.com', 'password' => self::SECRET]);
        $miss = $hasher->total();

        $hasher->reset();
        $provider = new ActiveUserProvider($hasher, User::class);
        $user = $provider->retrieveByCredentials(['email' => 'known@example.com', 'password' => self::SECRET]);
        $this->assertNotNull($user, 'the control: the hit path really did find the account');
        // The guard's next step, and the one comparison a hit pays for.
        $provider->validateCredentials($user, ['password' => self::SECRET]);
        $hit = $hasher->total();

        $this->assertGreaterThan(0, $hit, 'the control: hashing happened at all');
        $this->assertSame($hit, $miss, sprintf(
            'a miss and a hit must cost the same hashing work — hit=%d, miss=%d', $hit, $miss,
        ));
    }

    /**
     * ⛔ AND THE DUMMY HASH IS NOT MINTED AGAIN FOR EVERY REQUEST, which is the property the arm
     * above depends on and cannot itself see (it warms the value first, so it would pass against a
     * build that re-minted per request as long as both paths did).
     *
     * ⚠ WHAT STANDS IN FOR "A SECOND REQUEST" HERE, AND WHAT THAT DOES NOT ESTABLISH. A freshly
     * CONSTRUCTED provider is the stand-in: `server/public/index.php` is the stock non-Octane
     * bootstrap and there is no `laravel/octane`, swoole or roadrunner in `composer.lock`, so the
     * container is rebuilt per request and `App\Providers\AppServiceProvider`'s `Auth::provider()`
     * closure returns a new instance — which is exactly why memoising into an instance property
     * amortised nothing. This arm does NOT execute two php-fpm requests; it asserts the property
     * that made the memo useless, in the place it can be observed.
     */
    public function test_the_dummy_hash_survives_the_request_that_minted_it(): void
    {
        $hasher = $this->countingHasher();

        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'nobody@example.com', 'password' => self::SECRET]);

        $this->assertSame(1, $hasher->makes, 'the first miss on a cold deployment mints it');

        // A NEW provider — the one the next request's container builds.
        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'nobody-else@example.com', 'password' => self::SECRET]);

        $this->assertSame(1, $hasher->makes, 'and no request after that pays to mint it again');
        $this->assertSame(2, $hasher->checks, 'the control: both misses did compare');
    }

    /**
     * The other half of `dummyHash()`'s keep-or-mint decision, so the branch that re-mints is
     * exercised rather than merely written: raising the cost factor on a running install must not
     * leave every miss comparing against a hash at the OLD cost forever — that is the same
     * enumeration oracle, moving more slowly.
     */
    public function test_a_kept_dummy_hash_at_a_stale_cost_factor_is_re_minted(): void
    {
        // The suite runs at BCRYPT_ROUNDS=4 (`phpunit.xml`); this is a hash from before someone
        // raised it.
        Cache::forever('auth.dummy_hash', app('hash')->make(Str::random(40), ['rounds' => 5]));

        $hasher = $this->countingHasher();

        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'nobody@example.com', 'password' => self::SECRET]);

        $this->assertSame(1, $hasher->makes, 'a hash at the wrong cost is replaced');

        // THE CONTROL — the replacement is at the configured cost, so the next request keeps it.
        (new ActiveUserProvider($hasher, User::class))
            ->retrieveByCredentials(['email' => 'nobody-else@example.com', 'password' => self::SECRET]);

        $this->assertSame(1, $hasher->makes, 'and is then kept, or this would re-mint forever');
    }

    // ── The bootstrap command ───────────────────────────────────────────────────────────────

    /**
     * ⚠ `doesntExpectOutputToContain()` RATHER THAN A SEARCH OF `Artisan::output()`, and the
     * difference is the whole assertion. `$this->artisan()` returns a `PendingCommand`, which
     * buffers its output into the expectation mock and leaves `Artisan::output()` EMPTY — so the
     * obvious spelling of this test asserts that a secret is absent from an empty string and
     * passes against a command that prints the password on every line. Measured, not assumed: a
     * mutant that appends the password to the mismatch message left the `Artisan::output()`
     * version of this arm green.
     */
    public function test_the_command_never_prints_a_password_it_was_given(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', self::SECRET)
            ->expectsQuestion('Confirm password', 'a different value')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertFailed();

        $this->assertStringNotContainsString(self::SECRET, $this->log());
    }

    public function test_the_commands_validation_failure_names_the_attribute_and_not_the_value(): void
    {
        // Short enough to fail the policy, and distinctive enough that quoting it back would show.
        $tooShort = 'sh0rt-'.substr(self::SECRET, 0, 4);

        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', $tooShort)
            ->expectsQuestion('Confirm password', $tooShort)
            ->doesntExpectOutputToContain($tooShort)
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
