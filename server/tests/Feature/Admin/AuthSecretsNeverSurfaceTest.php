<?php

namespace Tests\Feature\Admin;

use App\Auth\ActiveUserProvider;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use Mockery;
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
     * ⛔ THE OTHER HALF OF "INDISTINGUISHABLE": THE WORK DONE. Stock Laravel returns from
     * `retrieveByCredentials()` before any hashing when the address is unknown, so a miss costs
     * no bcrypt and a hit costs one — tens of milliseconds apart at production cost factors, which
     * is an enumeration oracle in timing even though both answers say the same words.
     *
     * Asserted by COUNTING HASH OPERATIONS rather than by measuring a clock: a timing assertion is
     * flaky by construction and would be turned off by the first person it failed for. One
     * `check()` on the miss path, one on the hit path.
     */
    public function test_a_login_for_an_unknown_address_still_performs_a_hash_comparison(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $hasher = Mockery::spy(Hasher::class);
        $hasher->shouldReceive('make')->andReturn('a-dummy-hash-value');
        $hasher->shouldReceive('check')->andReturn(false);

        $provider = new ActiveUserProvider($hasher, User::class);

        $provider->retrieveByCredentials(['email' => 'nobody@example.com', 'password' => self::SECRET]);
        $hasher->shouldHaveReceived('check')->once();

        // THE CONTROL — the hit path must do the SAME one comparison, or the equalisation would
        // simply have moved the difference. `retrieveByCredentials` finds the row without hashing;
        // the guard's next step, `validateCredentials`, is the one comparison a hit pays for.
        $hasher2 = Mockery::spy(Hasher::class);
        $hasher2->shouldReceive('make')->andReturn('a-dummy-hash-value');
        $hasher2->shouldReceive('check')->andReturn(true);

        $provider2 = new ActiveUserProvider($hasher2, User::class);

        $user = $provider2->retrieveByCredentials(['email' => 'known@example.com', 'password' => self::SECRET]);
        $this->assertNotNull($user);
        $hasher2->shouldNotHaveReceived('check');

        $provider2->validateCredentials($user, ['password' => self::SECRET]);
        $hasher2->shouldHaveReceived('check')->once();
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
