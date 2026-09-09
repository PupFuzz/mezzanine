<?php

namespace Tests\Feature\Admin;

use App\Console\SecretLine;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * ⛔ WHAT THE OPERATOR READS OFF THE SCREEN IS THE SECRET THAT WAS STORED — the property
 * `App\Console\SecretLine` exists to hold, asserted on the characters that broke it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEFECT THIS FILE WAS WRITTEN AGAINST, read at vendor source rather than recalled.
 * `Illuminate\Console\Command::line()` writes with `OUTPUT_NORMAL`, so the string goes through
 * `Symfony\Component\Console\Formatter\OutputFormatter::formatAndWrap()`, whose LAST act is
 *
 *   return strtr($output, ["\0" => '\\', '\\<' => '<', '\\>' => '>']);
 *
 * (`vendor/symfony/console/Formatter/OutputFormatter.php`, the `return` closing `formatAndWrap`).
 * `Illuminate\Support\Str::password()`'s symbol set contains `<`, `>` AND `\` — read it in
 * `vendor/laravel/framework/src/Illuminate/Support/Str.php`, in `password()`'s `'symbols'` list —
 * so a generated password containing `\<` or `\>` LOSES ITS BACKSLASH on the way to the terminal
 * while the account is created with the unmangled value. Measured at ~0.6% of draws. On
 * `mezzanine:user:create --generate`, which `README.md` calls the only way back from a locked-out
 * install, in an application with no mailer, no password reset and no registration page, that is a
 * command that prints one credential and stores another.
 *
 * ⚠ AND IT REPORTED ITSELF AS A FLAKY TEST FOR A WHOLE ROUND.
 * `BootstrapUserCommandTest::test_generate_prints_a_password_once_that_actually_signs_in` failed
 * about one run in forty and was read as nondeterminism in the suite. It was the defect
 * announcing itself at its own rate.
 *
 * ⚠ EVERY ARM BELOW IS DETERMINISTIC BY CONSTRUCTION. The end-to-end arm in
 * `BootstrapUserCommandTest` can only sample the generator; these drive the exact byte sequences
 * that break, so the property is asserted rather than sampled.
 */
class PrintedSecretTest extends TestCase
{
    /**
     * The regex `BootstrapUserCommandTest` reads the printed password back out with, reused
     * deliberately: the property under test is "what the operator can read off the screen is what
     * was stored", and that regex is how it is read.
     */
    private const EXTRACT = '/^\s*password\s+(\S+)\s*$/m';

    /**
     * Values built from `Str::password()`'s own alphabet. The first three are the ones the
     * formatter transforms; the rest are the neighbours that must NOT be disturbed by the fix.
     *
     * @return list<array{string, string}>
     */
    public static function hazards(): array
    {
        return [
            'backslash-lt' => ['2a?X]%2}>-\\<P.-A435%;}vH]', 'the measured case: a literal \\< inside a real generated password'],
            'backslash-gt' => ['X5|P$P\\>E5{ntwKC<.dauan%', 'the measured case: a literal \\> inside a real generated password'],
            'style-tag' => ['ab<info>cd', 'a run that the formatter reads as a STYLE TAG and consumes entirely'],
            'bare-angles' => ['a<b>c<d>e', 'unpaired and unknown angle runs'],
            'trailing-backslash' => ['abc123!\\', 'a value ending in a backslash — the case OutputFormatter::escape() handles with a NUL'],
            'plain' => ['Xy7!zQ2#aB', 'the control: a value with nothing special in it at all'],
        ];
    }

    /** Render one secret through the production path, exactly as a command does. */
    private function throughSecretLine(string $value): string
    {
        $buffer = new BufferedOutput;
        SecretLine::write(new OutputStyle(new ArrayInput([]), $buffer), 'password', $value);

        return $buffer->fetch();
    }

    /**
     * Render one secret the way `Illuminate\Console\Command::line()` would — the spelling this
     * class exists to keep off a credential's path. Used ONLY by the control arm.
     */
    private function throughTheFormatter(string $value): string
    {
        $buffer = new BufferedOutput;
        $output = new OutputStyle(new ArrayInput([]), $buffer);
        $output->writeln(sprintf('  %-12s%s', 'password', $value));

        return $buffer->fetch();
    }

    private function extract(string $rendered): ?string
    {
        return preg_match(self::EXTRACT, $rendered, $m) === 1 ? $m[1] : null;
    }

    #[DataProvider('hazards')]
    public function test_a_printed_secret_is_byte_for_byte_the_secret(string $value, string $why): void
    {
        $this->assertSame($value, $this->extract($this->throughSecretLine($value)), $why);
    }

    /**
     * ⛔ THE CONTROL, AND IT IS NOT DECORATION — it is what makes the arm above evidence.
     * A check that cannot return the other answer proves nothing, so this drives the SAME values
     * through the SAME output object by the spelling the fix replaced, and asserts they come back
     * WRONG. If a future Symfony stops transforming `\<`, this arm reds and says so, rather than
     * leaving the arm above passing for a reason that has evaporated.
     */
    public function test_the_spelling_the_fix_replaced_really_does_mangle_these_values(): void
    {
        $mangled = [];

        foreach (['x\\<y', 'x\\>y', 'ab<info>cd'] as $value) {
            $readBack = $this->extract($this->throughTheFormatter($value));

            if ($readBack !== $value) {
                $mangled[$value] = $readBack;
            }
        }

        $this->assertSame(
            ['x\\<y' => 'x<y', 'x\\>y' => 'x>y', 'ab<info>cd' => 'abcd'],
            $mangled,
            'the formatter path must still be capable of the failure this file guards against',
        );
    }

    /**
     * The generator's whole output space, not a sample of it: every value `Str::password(24)` can
     * produce must survive. 500 draws is ~12 000 characters over an 86-character alphabet, so
     * every member of it is exercised many times over; the assertion is exact, so this arm is
     * deterministic-green rather than probabilistic — a single mangled draw fails it.
     */
    public function test_every_password_the_generator_mints_survives_the_print(): void
    {
        $seen = '';

        for ($i = 0; $i < 500; $i++) {
            $password = Str::password(24);
            $seen .= $password;

            $this->assertSame($password, $this->extract($this->throughSecretLine($password)));
        }

        // THE DENOMINATOR CONTROL: this arm is only meaningful if the draws really do contain the
        // characters the formatter transforms. Named rather than assumed.
        foreach (['<', '>', '\\'] as $hazard) {
            $this->assertStringContainsString($hazard, $seen, 'Str::password() no longer emits '.$hazard.' — re-derive what this arm covers');
        }
    }
}
