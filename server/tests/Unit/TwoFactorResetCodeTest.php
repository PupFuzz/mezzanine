<?php

namespace Tests\Unit;

use App\Auth\TwoFactorReset;
use PHPUnit\Framework\TestCase;

/**
 * The reset CODE itself — its symbol set, its shape, and what a submitted string is taken to mean.
 *
 * ⛔ THESE ARE THE ARMS OF CARD#9077 THAT NEED NO DATABASE, and that is the only reason they are
 * split out into `tests/Unit`. It is not a tidiness split: `docs/design/FLEET-STATE.md § 6.2` pins
 * the suite to a store the deploy host does not carry a driver for, so on that host these arms run
 * and the feature arms cannot — see the PR body, which does not pretend otherwise.
 *
 * ⚠ NO APPLICATION IS BOOTED. Every method exercised here reads no config and touches no container,
 * so `PHPUnit\Framework\TestCase` is the right base and `Tests\TestCase` would only add a store
 * guard for a store nothing here uses.
 */
class TwoFactorResetCodeTest extends TestCase
{
    /** Crockford base32 — the alphabet `TwoFactorReset` mints from, restated here as the ORACLE. */
    private const EXPECTED_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * ⛔ THE ONE PROPERTY THE WHOLE MECHANISM RESTS ON: the code is long enough that guessing it is
     * not a strategy. 20 symbols over 32 is 100 bits, which is the number the class's docblock
     * claims and the reason the confirm route's rate limit is a load shield rather than the
     * defence.
     *
     * ⚠ ASSERTED OVER MANY MINTS RATHER THAN ONE, because the defect this is written against is a
     * generator that is USUALLY right — an off-by-one in a loop bound shows up in every sample, but
     * an alphabet index that can fall off the end shows up in one sample in thirty-two.
     */
    public function test_a_minted_code_is_twenty_symbols_from_crockfords_base32(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $code = TwoFactorReset::mint();

            $this->assertSame(20, strlen($code), 'a minted code is not 20 symbols long');
            $this->assertSame('', ltrim($code, self::EXPECTED_ALPHABET),
                'a minted code contains a symbol outside Crockford\'s base32: '.$code);

            $seen[$code] = true;
        }

        // The control that makes the two assertions above a measurement of a GENERATOR rather than
        // of a constant: a mint that returned the same value every time would satisfy both.
        $this->assertCount(200, $seen, 'minting is not producing distinct values');
    }

    /**
     * ⛔ CROCKFORD'S DECODING EQUIVALENCES ARE THE POINT OF THE ALPHABET, so they are asserted
     * rather than assumed. A person reading a code off an email and typing it into a form will
     * type `O` for zero and `I` or `l` for one; the alphabet omits all three precisely so that
     * those transcriptions have exactly one correct meaning.
     */
    public function test_a_transcription_a_person_would_call_correct_is_correct(): void
    {
        $canonical = 'ABCDE12345FGHJK67890';

        foreach ([
            'lower case' => 'abcde12345fghjk67890',
            'hyphenated' => 'ABCDE-12345-FGHJK-67890',
            'spaced' => 'ABCDE 12345 FGHJK 67890',
            'pasted with whitespace' => "  ABCDE12345FGHJK67890\n",
            'letter O typed for zero' => 'ABCDE12345FGHJK6789O',
            'letter I typed for one' => 'ABCDE-I2345-FGHJK-67890',
            'lower L typed for one' => 'ABCDE-l2345-FGHJK-67890',
        ] as $why => $submitted) {
            $this->assertSame($canonical, TwoFactorReset::normalise($submitted), "normalise() mishandles: {$why}");
        }
    }

    /**
     * The control for the arm above, and it is what makes it a test rather than a demonstration:
     * normalise() must NOT be so forgiving that a different code normalises to the same value.
     */
    public function test_a_genuinely_different_code_does_not_normalise_to_the_same_value(): void
    {
        $this->assertNotSame(
            TwoFactorReset::normalise('ABCDE12345FGHJK67890'),
            TwoFactorReset::normalise('ABCDE12345FGHJK67891'),
        );
    }

    /**
     * ⛔ THE STORED VALUE IS A DIGEST AND CONTAINS NO PART OF THE CODE. The property is trivial to
     * state and easy to lose in an edit — "store the code, we hash on read" is one line away — so
     * it is pinned: 64 hex characters, and the plaintext is not a substring of them.
     */
    public function test_the_stored_fingerprint_is_a_sha256_and_carries_no_part_of_the_code(): void
    {
        $code = 'ABCDE12345FGHJK67890';
        $fingerprint = TwoFactorReset::fingerprint($code);

        $this->assertSame(64, strlen($fingerprint));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertStringNotContainsString(strtolower($code), strtolower($fingerprint));

        // Deterministic, or the lookup in `consume()` could never find the row it wrote.
        $this->assertSame($fingerprint, TwoFactorReset::fingerprint($code));

        // And a DIFFERENT code fingerprints differently — the control, without which the two
        // assertions above would pass against a function returning a constant.
        $this->assertNotSame($fingerprint, TwoFactorReset::fingerprint('ABCDE12345FGHJK67891'));
    }

    /**
     * ⚠ FORMATTING IS PRESENTATION AND MUST NOT SURVIVE INTO A COMPARISON. The round trip is the
     * assertion: what an email shows, typed back in, is the value that was minted.
     */
    public function test_the_formatted_code_round_trips_through_normalise(): void
    {
        $code = TwoFactorReset::mint();
        $shown = TwoFactorReset::format($code);

        $this->assertSame('ABCDE-12345-FGHJK-67890', TwoFactorReset::format('ABCDE12345FGHJK67890'));
        $this->assertSame($code, TwoFactorReset::normalise($shown));
    }

    /**
     * A submitted value of the wrong LENGTH must never reach the store. `consume()` returns before
     * the query on that condition, which is why the length is a property of `normalise()`'s output
     * rather than of the raw input: a hyphen-laden 24-character string is a 20-symbol code.
     */
    public function test_a_string_of_the_wrong_length_normalises_to_the_wrong_length(): void
    {
        $this->assertNotSame(20, strlen(TwoFactorReset::normalise('')));
        $this->assertNotSame(20, strlen(TwoFactorReset::normalise('ABCDE')));
        $this->assertNotSame(20, strlen(TwoFactorReset::normalise(str_repeat('A', 21))));
        $this->assertNotSame(20, strlen(TwoFactorReset::normalise('!!!!!!!!!!!!!!!!!!!!')));
    }
}
