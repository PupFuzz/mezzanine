<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\TwoFactorIssuer;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Card#9070 D2's retirement, mirroring `seats` — the account stops working, the record stays.
     *
     * ⛔ `retired_at`, `retired_by` and `retired_reason` are ABSENT from `#[Fillable]` above, and
     * that is the guard rather than an omission: retirement is an act with an author and a reason
     * (`docs/design/FLEET-STATE.md § 4.5` says so for a seat and the same holds for an account),
     * so it is written by `App\Admin\UserRetirement` and by nothing else. A mass-assignable
     * `retired_at` would let an edit form retire an account as a side effect of saving a name.
     */
    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * ⛔ THE ONE SPELLING OF "STILL A USER". Every count, list and credential lookup that means
     * *active* reads it from here, so the console's list, D4's last-active-operator refusal and
     * `App\Auth\ActiveUserProvider`'s credential filter cannot drift into three predicates that
     * disagree about whether a retired account exists.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * Whether this account has completed second-factor enrolment.
     *
     * Reads `two_factor_confirmed_at` — a plain column — rather than
     * `hasEnabledTwoFactorAuthentication()`, which decrypts `two_factor_secret` and returns
     * false when the decrypt raises (see the fail-posture note on EnsureTwoFactorSatisfied).
     */
    public function hasCompletedTwoFactorEnrolment(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * The enrolment page's QR code: the account's STORED secret, through `twoFactorQrCodeSvgFor()`.
     *
     * Overrides Fortify's `TwoFactorAuthenticatable::twoFactorQrCodeSvg()`, which can only draw the
     * stored secret, so that the enrolment page and card#9471's move page (which draws a secret that
     * is not stored yet) share one renderer and one issuer.
     */
    public function twoFactorQrCodeSvg(): string
    {
        return $this->twoFactorQrCodeSvgFor(Fortify::currentEncrypter()->decrypt($this->two_factor_secret));
    }

    /**
     * The otpauth URL for the account's STORED secret, through `twoFactorQrCodeUrlFor()`.
     */
    public function twoFactorQrCodeUrl(): string
    {
        return $this->twoFactorQrCodeUrlFor(Fortify::currentEncrypter()->decrypt($this->two_factor_secret));
    }

    /**
     * ⛔ THE ONE QR RENDERER, for any plaintext secret this account is being shown.
     *
     * ⚠ The body restates Fortify 1.x's `TwoFactorAuthenticatable::twoFactorQrCodeSvg()` (read at
     * v1.38.0) with the secret as a parameter, so a Fortify upgrade that changes that method must be
     * re-read against this one.
     */
    public function twoFactorQrCodeSvgFor(string $secret): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
                new SvgImageBackEnd
            )
        ))->writeString($this->twoFactorQrCodeUrlFor($secret));

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * The otpauth URL a QR code encodes, for any plaintext secret this account is being shown.
     *
     * Differs from Fortify's `TwoFactorAuthenticatable::twoFactorQrCodeUrl()` in two ways: the secret
     * is a parameter rather than the stored column, and the issuer comes from
     * `App\Auth\TwoFactorIssuer` (the site's hostname label) instead of `config('app.name')`.
     * ⚠ The rest restates Fortify 1.x's body, so a Fortify upgrade that changes that method must be
     * re-read against this one.
     */
    public function twoFactorQrCodeUrlFor(string $secret): string
    {
        return app(TwoFactorAuthenticationProvider::class)->qrCodeUrl(
            TwoFactorIssuer::resolve(),
            $this->{Fortify::username()},
            $secret
        );
    }
}
