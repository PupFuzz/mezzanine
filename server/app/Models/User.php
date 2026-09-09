<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
}
