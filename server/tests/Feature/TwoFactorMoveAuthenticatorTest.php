<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Card#9471 — a signed-in account with a confirmed second factor can move to a new authenticator.
 *
 * ⛔ THE GAP WAS AN ACCIDENT'S REMOVAL. The enrolment page's "Start over" button (Fortify's
 * `DELETE /user/two-factor-authentication`) used to be drawn for confirmed accounts too, and that
 * was the only in-app way to enrol a replacement device. Card#9445 correctly took it away from
 * confirmed accounts, which left a user who had replaced their phone, or signed in with a recovery
 * code, spending one recovery code per sign-in until none were left — and the emailed reset needs
 * outbound mail. The recovery codes page now offers the move, through that same Fortify route.
 *
 * ⚠ THE ACTION ARM DRIVES THE FORM THE PAGE RENDERS. It reads the form's target and method out of
 * the HTML before sending anything, because the route itself has been live since the 2FA work
 * landed: an arm that only called the route would have passed while no user could reach it.
 *
 * ⚠ THE TWO REFUSAL ARMS CANNOT GO RED ON THE CODE BEFORE THIS CARD. The gates are Fortify's route
 * middleware — `auth`, and `password.confirm` because `config/fortify.php` sets
 * `confirmPassword => true` — and this card adds neither. They pin that the action this page now
 * advertises stays behind both.
 */
class TwoFactorMoveAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    private function confirmPassword(): void
    {
        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();
    }

    private function secretOf(User $user): string
    {
        return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    }

    /**
     * ⛔ THE CARD'S CORE ARM. A confirmed account re-proves its password, finds the move on the
     * recovery codes page, uses it, and the next page it reaches is a fresh enrolment; finishing that
     * enrolment leaves a new secret and a new set of codes, and a code from the old set no longer
     * signs in.
     */
    public function test_a_confirmed_account_moves_to_a_new_authenticator_from_the_codes_page(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $oldSecret = $this->secretOf($user);
        $oldCode = $user->recoveryCodes()[0];

        $this->actingAs($user);
        $this->confirmPassword();

        $page = $this->get(route('two-factor.codes'))->assertOk();

        $html = $page->getContent();

        // The page states the consequence before the button, in the words a user acts on. Whitespace
        // is collapsed first so the view's line wrapping is free to move.
        $flat = preg_replace('/\s+/', ' ', $html);
        $offset = 0;
        foreach ([
            '<h2>Move to a new authenticator</h2>',
            'the entry in your current authenticator app stops working',
            'every recovery code on this page stops working',
            'your password alone signs in to this account',
            '<button type="submit">Move to a new authenticator</button>',
        ] as $phrase) {
            $at = strpos($flat, $phrase, $offset);
            $this->assertNotFalse($at, "the codes page says, in this order: {$phrase}");
            $offset = $at + strlen($phrase);
        }
        $this->assertSame(1, preg_match(
            '#<form method="POST" action="([^"]+)">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="_method" value="([A-Z]+)">\s*<button type="submit">Move to a new authenticator</button>#',
            $html,
            $form,
        ), 'the codes page renders the move form');
        [, $action, $method] = $form;
        $this->assertSame(route('two-factor.disable'), $action);
        $this->assertSame('DELETE', $method);

        $landing = $this->from(route('two-factor.codes'))
            ->followingRedirects()
            ->call($method, $action)
            ->assertOk();

        // The landing is the enrolment page's UNenrolled state — not the confirmed state, and not the
        // raw status key Fortify's stock response flashes.
        $landing->assertSee('Your account does not have one');
        $landing->assertSee('Generate a secret');
        $landing->assertDontSee('Two-factor authentication is on');
        $landing->assertDontSee('two-factor-authentication-disabled');
        $this->assertSame(route('two-factor.enroll'), url()->current());

        $disabled = $user->fresh();
        $this->assertNull($disabled->two_factor_secret);
        $this->assertNull($disabled->two_factor_recovery_codes);
        $this->assertFalse($disabled->hasCompletedTwoFactorEnrolment());

        // The `mfa` gate holds the account at enrolment: nothing else is reachable un-enrolled.
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.enroll'));

        // A fresh enrolment: a new secret, not confirmed until a code from it is entered.
        $this->post(route('two-factor.enable'))->assertSessionHasNoErrors();
        $pending = $user->fresh();
        $newSecret = $this->secretOf($pending);
        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertFalse($pending->hasCompletedTwoFactorEnrolment());
        $this->assertNotContains($oldCode, $pending->recoveryCodes());

        $this->post(route('two-factor.confirm'), [
            'code' => app(Google2FA::class)->getCurrentOtp($newSecret),
        ])->assertSessionHasNoErrors();
        $moved = $user->fresh();
        $this->assertTrue($moved->hasCompletedTwoFactorEnrolment());

        // The old recovery code no longer signs in; a new one does (the control).
        $this->post(route('logout'));
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::password()])
            ->assertRedirect(route('two-factor.login'));

        $this->post('/two-factor-challenge', ['recovery_code' => $oldCode]);
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => $moved->recoveryCodes()[0]]);
        $this->assertAuthenticatedAs($moved);
    }

    /**
     * ⛔ A SESSION ALONE MUST NOT REMOVE THE SECOND FACTOR — neither one that never re-proved the
     * password nor one whose confirmation has aged past `auth.password_timeout`. Without this, a
     * stolen session cookie would turn two-factor off and enrol the thief's own device.
     */
    public function test_the_move_is_refused_without_a_recent_password_confirmation(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $secret = $user->two_factor_secret;

        $this->actingAs($user)
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));
        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());
        $this->assertSame($secret, $user->fresh()->two_factor_secret);

        // A confirmation that has expired is no confirmation.
        $this->confirmPassword();
        $this->travel(config('auth.password_timeout') + 1)->seconds();

        $this->delete(route('two-factor.disable'))->assertRedirect(route('password.confirm'));
        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_a_guest_cannot_use_the_move(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        $this->delete(route('two-factor.disable'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue($user->fresh()->hasCompletedTwoFactorEnrolment());
    }
}
