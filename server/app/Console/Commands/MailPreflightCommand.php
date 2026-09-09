<?php

namespace App\Console\Commands;

use App\Auth\TwoFactorReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ⛔ "PREFLIGHT, NOT A 3AM DISCOVERY" — card#9077's requirement 8, as a thing an operator can RUN.
 *
 * The emailed two-factor reset has a prerequisite this repository cannot satisfy for you: a
 * configured, working outbound transport. `config/mail.php` defaults to `log` and `.env.example`
 * ships `MAIL_MAILER=log`, so a host that nobody configured has a reset path that
 * `App\Auth\TwoFactorReset::isAvailable()` refuses — correctly, because under `log` the rendered
 * message (reset code and all) goes into `storage/logs/` instead of to anybody.
 *
 * ⚠ THE CONFIG CHECK AND THE SEND ARE DIFFERENT MEASUREMENTS AND BOTH ARE HERE. Config says
 * whether the reset path is switched on; only a send says whether mail LEAVES — a `smtp` mailer
 * pointed at a host that refuses the connection passes the first and fails the second, and it is
 * the second failure that is discovered at 3am. `--to=` is what makes this a real exercise of the
 * boundary rather than a reading of a config file.
 */
class MailPreflightCommand extends Command
{
    protected $signature = 'mezzanine:mail:preflight {--to= : Send a test message to this address}';

    protected $description = 'Report whether outbound mail is configured, and optionally prove it by sending a test message';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        $this->line('Default mailer:  '.$mailer);
        $this->line('Transport:       '.$transport);
        $this->line('From address:    '.(string) config('mail.from.address'));

        if (! TwoFactorReset::isAvailable()) {
            $this->newLine();
            $this->error('Outbound mail is NOT configured, so the two-factor reset path is REFUSED on this host.');
            $this->line('A `'.$transport.'` transport delivers nothing, and `log` additionally writes the whole');
            $this->line('rendered message — reset code included — into storage/logs/.');
            $this->line('Set MAIL_MAILER (and its credentials) in .env, then run this again with --to=.');

            return self::FAILURE;
        }

        $this->info('Outbound mail is configured, so the two-factor reset path is available.');

        $to = $this->option('to');

        if (! is_string($to) || $to === '') {
            $this->newLine();
            $this->warn('Configured is not the same as WORKING. Re-run with --to=you@example.com to prove a send.');

            return self::SUCCESS;
        }

        try {
            Mail::raw(
                'Mezzanine mail preflight. If you are reading this, outbound mail works on this host '
                    ."and the two-factor reset can reach an operator.\n\nSent at ".now()->toIso8601String().'.',
                fn ($message) => $message->to($to)->subject(config('app.name').' — mail preflight'),
            );
        } catch (Throwable $e) {
            // The MESSAGE, not the trace: a transport failure's message names the host and the
            // refusal, which is the useful half, while a trace of a mail send can carry the
            // configured credentials through the transport's constructor arguments.
            $this->newLine();
            $this->error('The send FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('A test message was handed to the transport without error. Check that it ARRIVED at '.$to.'.');
        $this->line('Handed over is not delivered — a silent drop downstream looks identical from here.');

        return self::SUCCESS;
    }
}
