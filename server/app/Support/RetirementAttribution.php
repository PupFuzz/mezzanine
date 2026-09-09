<?php

namespace App\Support;

/**
 * ⛔ ONE PREDICATE FOR "an act with an AUTHOR and a REASON" (`docs/design/FLEET-STATE.md § 4.5`),
 * read by every caller and by both acts.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS, WHICH IS A DEFECT AND NOT A TIDY-UP. Card#9070's FIRST review round hoisted this
 * rule out of the two callers and into the two acts — `App\Fleet\SeatRetirement::retire()` and
 * `App\Admin\UserRetirement::retire()` — so that a third caller would inherit it. The callers kept
 * their own refusal messages, which are better than an exception, and `SeatRetirement`'s class
 * docblock said so: "Each still refuses first, with a message better than an exception."
 *
 * That sentence was FALSE, and the SECOND review round measured it. The act tested `trim($by)`;
 * `App\Console\Commands\RetireCommand` tested `$by === ''`. So `mezzanine:retire --by="   "` walked
 * past the caller's refusal, reached the act, and gave the operator an uncaught
 * `InvalidArgumentException` and a full stack trace at exit code 1 — where the documented answer is
 * `INVALID`, exit code 2.
 *
 * ⛔ SO THE CONSOLIDATION WAS ONE LEVEL SHORT: hoisting the ENFORCEMENT while leaving each caller
 * to re-derive the PREDICATE it enforces on is not one rule, it is two rules that agree until they
 * do not, and the disagreement surfaces only on the input nobody types in a test. What is shared
 * here is the predicate itself and the sentence that explains it; what each caller keeps is HOW it
 * refuses, which is genuinely different at a shell, in an HTTP form and inside an act.
 *
 * ⚠ THE CONSOLE'S FORM VALIDATION IS DELIBERATELY NOT ROUTED THROUGH HERE.
 * `App\Http\Controllers\Admin\UserController::retire()` validates `'reason' => ['required']`, and
 * Laravel's `required` already rejects a whitespace-only string — the same predicate, expressed in
 * the vocabulary the framework renders field errors from. Restating it as a hand-rolled check would
 * cost the operator the field error and buy nothing.
 */
final class RetirementAttribution
{
    /**
     * The one sentence, so a caller's message and the act's exception cannot drift apart. Both
     * `Tests\Feature\Admin\SeatConsoleTest` and `Tests\Feature\Admin\UserManagementTest` assert
     * on the substring "author and a reason".
     */
    public const MESSAGE = 'retirement is an act with an author and a reason (§ 4.5); neither may be empty';

    /**
     * THE PREDICATE. `trim()` rather than `=== ''` because a space is not an author: a record
     * whose author reads as blank on every later screen carries no attribution, and the whole
     * point of § 4.5 is that somebody can be asked about the act afterwards.
     */
    public static function missing(string $by, string $reason): bool
    {
        return trim($by) === '' || trim($reason) === '';
    }

    /**
     * The act's half: a caller that reaches an act without both has skipped a check it was
     * supposed to make, so this raises rather than returning a verdict.
     *
     * @throws \InvalidArgumentException
     */
    public static function demand(string $by, string $reason): void
    {
        if (self::missing($by, $reason)) {
            throw new \InvalidArgumentException(self::MESSAGE);
        }
    }
}
