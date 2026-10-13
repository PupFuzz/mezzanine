<?php

namespace App\Compose;

use App\Board\BoardHttp;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;

/**
 * `docs/design/BOARD-TASK.md § 15.7` — the floor's ONE write to the board: a comment on the card the
 * seat is joined to, with the compose token, and nothing else.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS IS THE ONE HOME OF THE `comments.json` REQUEST BUILDER. AT-C10 holds that no other file under
 * `server/app` names the path, so the one-verb rule (§ 15.3) is a property of where the write CAN be
 * built from rather than of who remembers not to call it. No `PATCH`, no second `POST`, no read-back.
 *
 * ⛔ NO RETRY, ANYWHERE. A retried comment is a duplicate comment, and the operator is present to decide.
 *
 * ⛔ WHAT LEFT AND WHAT DID NOT IS DECIDED BY THE TRANSPORT's OWN CLASS, NEVER GUESSED. Laravel wraps
 * every transport failure as one `ConnectionException`; its previous is Guzzle's typed exception, and
 * only Guzzle's connect-class `ConnectException` (name resolution, connection refused, connect timeout,
 * a TLS handshake) proves the request never left. Everything else — a read timeout, a connection lost
 * mid-exchange, a failure with no typed cause — is `unverified`: the comment may be on the card.
 *
 * ⛔ A NON-2xx IS ITS STATUS ALONE (§ 15.11). The body is never read on a refusal, so a board that
 * echoes the request into an error body cannot put the message or a header into the panel or the log.
 */
final class BoardCommentChannel implements ComposeChannel
{
    public function __construct(
        private readonly string $base,
        private readonly string $composeToken,
    ) {}

    public function kind(): string
    {
        return ComposeTarget::BOARD_COMMENT;
    }

    public function post(ComposeTarget $target, ComposeMessage $message): ComposeReceipt
    {
        $url = $this->base.'/tasks/'.(int) $target->address.'/comments.json';
        $logged = BoardHttp::redact($url);

        try {
            // § 15.7: the flat body `{"content": …}` (the wrapped form `422`s), the token as a header.
            $response = BoardHttp::client($this->composeToken)->post($url, ['content' => self::content($message)]);
        } catch (ConnectionException $e) {
            $class = $e->getPrevious() instanceof ConnectException ? ComposeRefused::TRANSPORT : ComposeRefused::UNVERIFIED;

            throw new ComposeRefused($class, $logged);
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new ComposeRefused(ComposeRefused::AUTH, $logged, boardStatus: $status);
        }

        if ($status < 200 || $status > 299) {
            throw new ComposeRefused(ComposeRefused::STATUS, $logged, boardStatus: $status);
        }

        $body = json_decode($response->body(), false);
        $id = is_object($body) && is_object($body->data ?? null) ? ($body->data->id ?? null) : null;

        // The receipt is the board's own integer id and nothing else: a 2xx without one may have
        // landed, and says so.
        if (! is_int($id) || $id < 1) {
            throw new ComposeRefused(ComposeRefused::UNVERIFIED, $logged, boardStatus: $status);
        }

        return new ComposeReceipt($this->kind(), $target->address, 'comment#'.$id, $message->composedAt);
    }

    /**
     * § 15.7's content: one attribution line, a blank line, the operator's text verbatim. The name is
     * `users.name`, never the email; the time is the server's, to the second, in UTC.
     */
    public static function content(ComposeMessage $message): string
    {
        return sprintf(
            "Posted from the Mezzanine floor by %s at %s\n\n%s",
            $message->authorName,
            $message->composedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $message->text,
        );
    }
}
