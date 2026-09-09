<?php

namespace App\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * ⛔ THE ONE WAY THIS APPLICATION PRINTS A CREDENTIAL TO AN OPERATOR — the value shown is the
 * value that was stored, byte for byte, whatever characters it contains.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS AT ALL, AND IT IS A MEASURED DEFECT RATHER THAN A PRECAUTION.
 *
 * `Illuminate\Console\Command::line()` writes with `OUTPUT_NORMAL`, so every byte goes through
 * `Symfony\Component\Console\Formatter\OutputFormatter::formatAndWrap()`, whose LAST act is
 *
 *     return strtr($output, ["\0" => '\\', '\\<' => '<', '\\>' => '>']);
 *
 * and whose first act is to find and CONSUME anything shaped like `<style>`.
 * `Illuminate\Support\Str::password()` draws from a symbol set that contains `<`, `>` and `\`
 * (read it in that method's `'symbols'` list). So `mezzanine:user:create --generate` printed a
 * password the operator could not sign in with, while creating the account with the unmangled
 * value: one credential shown, a different one stored, on the command `README.md` calls the only
 * way back from a locked-out install, in an application with no password reset and no
 * registration page.
 *
 * The rate, with its derivation rather than as a bare number: draw N values from `Str::password(24)`,
 * push each through a real `OutputFormatter`, and read it back with the regex
 * `BootstrapUserCommandTest` uses. At N = 20 000 that returned 132 mismatches — 0.66%, about one
 * run in 150. Low enough to read as a flaky test and high enough that a real operator meets it.
 *
 * ⚠ IT WAS VISIBLE AS A "FLAKY TEST" FOR A ROUND BEFORE IT WAS SEEN AS A DEFECT —
 * `Tests\Feature\Admin\BootstrapUserCommandTest`'s end-to-end `--generate` arm failed about one
 * run in forty. That was the bug reporting itself at its own rate.
 *
 * ⛔ WHY `OUTPUT_RAW` AND NOT `OutputFormatter::escape()`. Both are correct today and the review
 * that found this offered either. `escape()` works by making the value survive a transformation —
 * it inserts backslashes that `formatAndWrap()`'s `strtr` is relied upon to take back out again,
 * and for a trailing backslash it round-trips through a NUL byte. That is a two-function inverse
 * relationship held across a vendored dependency's minor versions, and the property wanted here is
 * not "escaped correctly" but "not transformed at all". `OUTPUT_RAW` states exactly that: Symfony's
 * `Output::write()` switches on the type and returns the message unchanged, so the bytes written
 * are the bytes given, and nothing has to stay each other's inverse for that to keep being true.
 * The cost is that this line carries no colour, which a secret should not have anyway.
 *
 * ⚠ VERBOSITY IS UNCHANGED BY THE SWITCH. `OUTPUT_RAW` selects a TYPE, not a verbosity; a message
 * with no verbosity bits still defaults to `VERBOSITY_NORMAL`, which is what `Command::line()`
 * passed. `-q` suppresses this line exactly as it suppressed the old one.
 *
 * ⛔ ONE HELPER RATHER THAN THREE ESCAPES AT THREE CALL-SITES (canon #5). Three commands print a
 * secret this way — `mezzanine:user:create`, `mezzanine:ingest-token:issue` and
 * `mezzanine:feed-token:issue`. Only the first was live, and the reason the other two were safe is
 * an accident of their alphabet: their tokens are base64url
 * (`rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` in `App\Read\ReadTokens::issue`
 * and in `App\Console\Commands\IssueIngestToken`), which carries no `<`, `>` or `\`. A safety that
 * depends on a caller's alphabet is a safety the fourth caller does not inherit, so all three route
 * through here and the property belongs to the print rather than to the secret.
 */
final class SecretLine
{
    /**
     * The label column the three commands already shared: two spaces, then a twelve-wide label.
     * Held here so the fourth caller does not have to count spaces to match the other three.
     */
    private const LABEL_WIDTH = 12;

    public static function write(OutputInterface $output, string $label, #[\SensitiveParameter] string $value): void
    {
        $output->writeln(
            sprintf('  %-'.self::LABEL_WIDTH.'s%s', $label, $value),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
