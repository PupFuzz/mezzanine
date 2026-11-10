<?php

namespace Tests\Feature\Ingest;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T4–T5 — `docs/design/plan-13117-seat-identity.md § 3.2`, card#13117: the token binds a SEAT, the batch's
 * `seat_id` must equal it, and the envelope's and each event's `install_id` are accepted and NOT READ —
 * present, absent, equal to a room, equal to nothing, or off D1's slug. A seat's room is a console fact
 * the operator changes at will, so a reporter sending the room it was installed under must not start
 * failing on the first move.
 */
class TheIngestReadsNoInstallIdTest extends IngestTestCase
{
    /** @return array<string, array{0: string|null}> */
    public static function installIds(): array
    {
        return [
            'absent' => [null],
            'a room' => ['aimla'],
            'no room at all' => ['zzz-not-a-room'],
            'not a slug' => ['Not A Slug'],
        ];
    }

    /** @dataProvider installIds */
    #[DataProvider('installIds')]
    public function test_a_batch_is_accepted_whatever_install_id_it_carries(?string $installId): void
    {
        $shape = fn (array $a): array => $installId === null
            ? array_diff_key($a, ['install_id' => true])
            : ['install_id' => $installId] + $a;

        $events = [$shape($this->event()), $shape($this->event(['seq' => 48210]))];
        $response = $this->postBatch($shape($this->validBatch($events)));

        $response->assertStatus(202)->assertJsonPath('accepted', 2);
        $this->assertSame(2, $this->storedEvents());
    }

    public function test_a_batch_claiming_another_seat_is_refused_on_the_seat_alone(): void
    {
        $response = $this->postBatch($this->validBatch(null, ['seat_id' => 'aimla-other']));

        $response->assertStatus(403)
            ->assertJsonPath('error', 'identity_mismatch')
            ->assertJsonPath('expected_seat_id', self::SEAT)
            ->assertJsonMissingPath('expected_install_id');

        $this->assertSame(1, $this->seatCounter('batches_refused.identity_mismatch'));
        $this->assertSame(0, $this->storedEvents());
    }
}
