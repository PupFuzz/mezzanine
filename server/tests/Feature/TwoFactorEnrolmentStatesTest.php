<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Card#9445 — what the enrolment page says after each answer to its code form.
 *
 * ⛔ THE DEFECT WAS FOUND ON A REAL SIGN-IN, NOT IN A TEST. The page chose what to render from
 * `two_factor_secret` alone, so after a CORRECT code it drew the QR code, the recovery codes and the
 * code form again, and the user read that as a failed enrolment — with "Start over", which disables
 * the second factor and discards the codes they had just written down, as the next button on the
 * page. After a WRONG code it drew the same page with no message, because Fortify puts that error in
 * the `confirmTwoFactorAuthentication` bag and the layout read only the default bag.
 *
 * ⚠ EVERY ARM ASSERTS ON THE PAGE THE BROWSER ACTUALLY LANDS ON. The confirm POST is sent `from` the
 * enrolment page and its redirects are followed, because Fortify's stock response is `back()`: an
 * arm that asserted on the session or on the model would have passed against the page that misled
 * the user.
 */
class TwoFactorEnrolmentStatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An account part-way through enrolment: password re-proved and a secret generated, through
     * Fortify's own routes, so the confirm route's `password.confirm` gate is satisfied the way a
     * browser satisfies it.
     */
    private function midEnrolment(): User
    {
        $user = User::factory()->twoFactorUnenrolled()->create();

        $this->actingAs($user);
        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();
        $this->post(route('two-factor.enable'))->assertSessionHasNoErrors();

        return $user->fresh();
    }

    private function secretOf(User $user): string
    {
        return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    }

    /**
     * The markers of the enrolment form, each of which must be absent once enrolment is complete.
     */
    private function assertNotTheEnrolmentForm($response, User $user): void
    {
        $response->assertDontSee(route('two-factor.confirm'), false);
        $response->assertDontSee(route('two-factor.disable'), false);
        $response->assertDontSee('Start over');
        $response->assertDontSee('Scan this with your authenticator app');

        foreach ($user->recoveryCodes() as $code) {
            $response->assertDontSee($code);
        }
    }

    public function test_a_rejected_code_is_explained_on_the_enrolment_page(): void
    {
        $user = $this->midEnrolment();
        $secret = $this->secretOf($user);

        // A six-digit code this secret does NOT accept at this moment, chosen rather than assumed:
        // a fixed literal is a valid code for some secret at some instant.
        $wrong = collect(range(0, 9))
            ->map(fn (int $digit) => str_repeat((string) $digit, 6))
            ->first(fn (string $code) => ! app(Google2FA::class)->verifyKey($secret, $code));

        $response = $this->from(route('two-factor.enroll'))
            ->followingRedirects()
            ->post(route('two-factor.confirm'), ['code' => $wrong])
            ->assertOk();

        $response->assertSee('The provided two factor authentication code was invalid.');

        // The form is still there to try again, and nothing was confirmed.
        $response->assertSee(route('two-factor.confirm'), false);
        $this->assertFalse($user->fresh()->hasCompletedTwoFactorEnrolment());
    }

    public function test_a_correct_code_does_not_land_on_the_enrolment_form(): void
    {
        $user = $this->midEnrolment();

        $response = $this->from(route('two-factor.enroll'))
            ->followingRedirects()
            ->post(route('two-factor.confirm'), [
                'code' => app(Google2FA::class)->getCurrentOtp($this->secretOf($user)),
            ])
            ->assertOk();

        // THE CONTROL — the code really was accepted, so the page below is the confirmed outcome.
        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());

        $this->assertNotTheEnrolmentForm($response, $user->fresh());

        // It lands on the floor, and says why: the status sentence is
        // `App\Http\Responses\TwoFactorConfirmedResponse`'s and the second line is the dashboard's.
        $response->assertSee('Two-factor authentication is on. Each sign-in now asks for a code from your authenticator app.');
        $response->assertSee('with a confirmed second factor');
    }

    /**
     * The rebind changes the BROWSER landing only. An API client confirming with
     * `Accept: application/json` still gets Fortify's own empty 200.
     */
    public function test_a_json_client_confirming_a_correct_code_still_gets_fortifys_empty_200(): void
    {
        $user = $this->midEnrolment();

        $this->postJson(route('two-factor.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($this->secretOf($user)),
        ])->assertOk()->assertContent('""');

        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());
    }

    /**
     * The back button, a bookmark, or a typed URL: a confirmed account that reaches the enrolment
     * route by any path other than the confirm POST sees the confirmed state too.
     */
    public function test_a_confirmed_account_visiting_the_enrolment_page_sees_no_enrolment_form(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        $response = $this->actingAs($user)
            ->get(route('two-factor.enroll'))
            ->assertOk();

        $this->assertNotTheEnrolmentForm($response, $user);
        $response->assertSee('Two-factor authentication is on');
        $response->assertSee(route('dashboard'), false);
        $response->assertSee(route('two-factor.codes'), false);
    }
}
