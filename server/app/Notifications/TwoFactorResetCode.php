<?php

namespace App\Notifications;

use App\Auth\TwoFactorReset;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one message this application sends — card#9077's reset code, to the address on the account.
 *
 * ⛔ IT CARRIES A CODE, NOT A LINK, AND THE DIFFERENCE IS CANON #20. A signed reset URL puts the
 * credential in a path or a query string, where it is written to the web server's access log, to
 * `Referer` on every asset the landing page loads, and into browser history — none of which the
 * application controls or can redact. A code typed into a form travels in a POST body and reaches
 * none of them. The `action()` button below therefore goes to the confirm FORM and carries nothing.
 *
 * ⚠ IT IS NOT `ShouldQueue`, AND THAT IS A MEASURED CHOICE RATHER THAN AN OVERSIGHT: nothing in
 * this application implements `ShouldQueue` and no host runs `queue:work` (`routes/console.php`
 * schedules `mezzanine:purge` and nothing else), so against `.env.example`'s
 * `QUEUE_CONNECTION=database` a queued notification would sit in the `jobs` table forever while the
 * user waits for mail that never arrives. `App\Auth\TwoFactorReset`'s docblock names the timing
 * residual that sending synchronously leaves open.
 *
 * ⛔ THE ADDRESS IS NEVER CHOSEN HERE. `Notifiable::routeNotificationForMail()` resolves it from
 * `$notifiable->email`, which is the stored column. This class has no addressing parameter, so
 * there is no way to pass it a destination — the property the card requires is held by the shape of
 * the code rather than by a check somebody must remember to write.
 */
class TwoFactorResetCode extends Notification
{
    /**
     * `#[\SensitiveParameter]` because this value is a live credential for the length of its TTL:
     * the attribute keeps it out of the argument list PHP renders into a stack trace if anything
     * below this frame raises.
     */
    public function __construct(#[\SensitiveParameter] private readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').' — code to remove your second factor')
            ->line('Somebody asked to remove two-factor authentication from your '.config('app.name').' account.')
            ->line('Your code is: '.TwoFactorReset::format($this->code))
            ->line('It can be used once and expires in '.TwoFactorReset::TTL_MINUTES.' minutes.')
            ->action('Enter the code', route('two-factor.reset.confirm'))
            ->line('Using it removes your second factor and nothing else — it does not sign anyone in. '
                .'You will be asked to enrol a new authenticator the next time you sign in.')
            // ⚠ NOT "if this was not you, ignore this" AND NOTHING STRONGER, deliberately: the
            // honest instruction is the one an operator can act on. There is no self-service way to
            // revoke a code, so the action is to tell whoever administers the install.
            ->line('If you did not ask for this, the code above is the only thing that can be used and it '
                .'expires on its own — but tell whoever administers this install, because somebody knows '
                .'your address and is trying to weaken your account.');
    }
}
