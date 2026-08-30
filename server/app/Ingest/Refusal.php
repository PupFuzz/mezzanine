<?php

namespace App\Ingest;

use Illuminate\Http\JsonResponse;

/**
 * D1 § 12.2's error body, in one place.
 *
 * "Every error body has the same shape — `{"error": <code>, "message": <human string>, …context}`
 * — so a reporter can branch on `error` and a human can read `message`." The named constructors
 * below are the complete row set of that section's table; there is no general-purpose one,
 * because an error code minted at a call site is a code no reporter was told to expect.
 *
 * `batch_id` rides every refusal that has one. § 12.2's worked `unsupported_schema_version`
 * example carries it, and it is what lets a reporter tie the refusal in its `REJECTED.txt` to
 * the batch it quarantined (§ 11.5).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ONE DECISION THE DOCUMENT DID NOT SETTLE: there are TWO 422 codes, not one.
 *
 * § 12.1 step 8 names `422 invalid_batch` for "`events` is a non-empty array of ≤ 200 elements",
 * and steps 9–10 name `422 invalid_event`. § 12.2's table has a single row covering both —
 * "batch/event validation failure | 422 | invalid_event" — whose "Extra body keys" are
 * `index`, `field`, `reason`, and an `index` is meaningless for a batch-level failure. Read
 * together, § 12.2's row is a coarse summary of a two-code reality rather than a third code.
 *
 * This ingest implements both, literally as § 12.1 names them, because `error` is the field a
 * reporter branches on and collapsing them would make a malformed envelope indistinguishable
 * from a bad event 137. Nothing observable changes for the reporter either way — `classify()`
 * in `fleet-reporter.js` treats any 422 as permanent — so the cost of getting it wrong is paid
 * by a human reading the code, not by the fleet. The discrepancy is corrected in
 * `docs/design/EVENT-SCHEMA.md` § 12.2 in this same change.
 */
final class Refusal
{
    private function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly string $message,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {}

    // ── step 1 ───────────────────────────────────────────────────────────────────────────────

    public static function unsupportedMediaType(string $received): self
    {
        return new self(415, 'unsupported_media_type', sprintf(
            'Content-Type must be application/json; received %s',
            $received === '' ? '(none)' : $received,
        ), ['expected' => 'application/json']);
    }

    // ── step 2 ───────────────────────────────────────────────────────────────────────────────

    public static function batchTooLarge(int $maxBytes, int $receivedBytes): self
    {
        return new self(413, 'batch_too_large', sprintf(
            'Batch body is %d bytes; the limit is %d.',
            $receivedBytes,
            $maxBytes,
        ), ['max_bytes' => $maxBytes, 'received_bytes' => $receivedBytes]);
    }

    // ── step 3 ───────────────────────────────────────────────────────────────────────────────

    public static function malformedBody(string $detail): self
    {
        return new self(400, 'malformed_body', 'The request body is not parseable JSON.', [
            'detail' => $detail,
        ]);
    }

    // ── step 4 ───────────────────────────────────────────────────────────────────────────────

    public static function unauthenticated(): self
    {
        // No context keys, per § 12.2, and deliberately no distinction between "absent",
        // "unknown" and "revoked" in the body: the three are one answer to the presenter and
        // three different counters on the server (§ 12.7). Telling an unauthenticated caller
        // which of the three it hit is an oracle, and D1 gives it no purpose.
        return new self(401, 'unauthenticated', 'The Authorization header is missing or does not resolve to an active token.');
    }

    // ── steps 4 and 5 ────────────────────────────────────────────────────────────────────────

    public static function rateLimited(int $retryAfterS, int $limit, int $windowS, string $what): self
    {
        return new self(429, 'rate_limited', sprintf(
            'Rate limit exceeded: %s. Retry after %d s.',
            $what,
            $retryAfterS,
        ), ['retry_after_s' => $retryAfterS, 'limit' => $limit, 'window_s' => $windowS]);
    }

    // ── step 6 ───────────────────────────────────────────────────────────────────────────────

    public static function unsupportedSchemaVersion(mixed $received): self
    {
        $accepted = SchemaVersions::ACCEPTED;

        return new self(400, 'unsupported_schema_version', sprintf(
            'schema_version %s is not accepted; this ingest accepts %s',
            is_scalar($received) ? var_export($received, true) : gettype($received),
            implode(', ', $accepted),
        ), ['received_version' => $received, 'accepted_versions' => $accepted]);
    }

    // ── step 7 ───────────────────────────────────────────────────────────────────────────────

    public static function identityMismatch(string $expectedInstall, string $expectedSeat): self
    {
        return new self(403, 'identity_mismatch', sprintf(
            'This token is bound to (%s, %s); the batch claims a different identity.',
            $expectedInstall,
            $expectedSeat,
        ), ['expected_install_id' => $expectedInstall, 'expected_seat_id' => $expectedSeat]);
    }

    // ── step 8 ───────────────────────────────────────────────────────────────────────────────

    public static function invalidBatch(string $field, string $reason): self
    {
        return new self(422, 'invalid_batch', sprintf('Batch field %s: %s', $field, $reason), [
            'field' => $field,
            'reason' => $reason,
        ]);
    }

    // ── steps 9 and 10 ───────────────────────────────────────────────────────────────────────

    public static function invalidEvent(int $index, string $field, string $reason): self
    {
        return new self(422, 'invalid_event', sprintf(
            'Event %d, field %s: %s',
            $index,
            $field,
            $reason,
        ), ['index' => $index, 'field' => $field, 'reason' => $reason]);
    }

    public function withBatchId(?string $batchId): self
    {
        if ($batchId === null) {
            return $this;
        }

        return new self($this->status, $this->error, $this->message, $this->context + ['batch_id' => $batchId]);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->error, 'message' => $this->message] + $this->context,
            $this->status,
        );
    }
}
