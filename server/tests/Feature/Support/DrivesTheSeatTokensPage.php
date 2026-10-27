<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;

/**
 * Drives the console's *Seat tokens* page (card#12331) as a browser would: an enrolled operator who
 * has confirmed their password, a GET that mints the form's one-time key, and a POST carrying the key
 * read out of that page — never a key the test made up, so a page that rendered the wrong key reds.
 */
trait DrivesTheSeatTokensPage
{
    /** An enrolled operator, signed in, inside the `password.confirm` window. */
    protected function confirmedOperator(array $attributes = []): User
    {
        $user = User::factory()->twoFactorConfirmed()->create($attributes);

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();

        return $user;
    }

    protected function issueKeyIn(TestResponse $page): string
    {
        $this->assertSame(1, preg_match('/name="issue_key" value="([^"]*)"/', (string) $page->getContent(), $m),
            'the page carries no issue_key field');

        return $m[1];
    }

    /** GET the page, then POST the issue form with the key that page rendered. */
    protected function issueOnPage(string $installId, string $seatId): TestResponse
    {
        $key = $this->issueKeyIn($this->get(route('admin.tokens.index'))->assertOk());

        return $this->post(route('admin.tokens.issue'), [
            'issue_key' => $key, 'install_id' => $installId, 'seat_id' => $seatId,
        ]);
    }

    /** The one plaintext a response carries, or null. */
    protected function plaintextIn(TestResponse $response): ?string
    {
        $count = preg_match_all('/mzn_[A-Za-z0-9_-]{43}/', (string) $response->getContent(), $m);
        $this->assertLessThanOrEqual(1, count(array_unique($m[0])), 'a response carries more than one token');

        return $count > 0 ? $m[0][0] : null;
    }
}
