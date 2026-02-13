<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

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
            'is_admin' => 'boolean',
        ];
    }

    public function subscriptionTier(): ?SubscriptionTier
    {
        if (! $this->subscribed()) {
            return SubscriptionTier::default()->first();
        }

        $subscription = $this->subscription();

        $tier = SubscriptionTier::where(function ($query) use ($subscription) {
            $query->where('stripe_price_id_monthly', $subscription->stripe_price)
                ->orWhere('stripe_price_id_yearly', $subscription->stripe_price);
        })->first();

        return $tier ?? SubscriptionTier::default()->first();
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function syncRoleFromTier(): void
    {
        $tier = $this->subscriptionTier();

        if ($tier) {
            $this->syncRoles([$tier->slug]);
        }
    }

    public function bets(): HasMany
    {
        return $this->hasMany(UserBet::class);
    }

    public function alertPreference()
    {
        return $this->hasOne(UserAlertPreference::class);
    }

    public function alertsSent(): HasMany
    {
        return $this->hasMany(UserAlertSent::class);
    }

    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(UserOnboardingProgress::class);
    }

    public function hasTierFeature(string $feature): bool
    {
        $tier = $this->subscriptionTier();

        if (! $tier) {
            return false;
        }

        return $tier->features[$feature] ?? false;
    }

    public function canAccessSport(string $sport): bool
    {
        $tier = $this->subscriptionTier();

        if (! $tier) {
            return false;
        }

        $allowedSports = $tier->features['sports_access'] ?? [];

        return in_array(strtoupper($sport), array_map('strtoupper', $allowedSports));
    }

    public function getDailyAlertLimit(): ?int
    {
        $tier = $this->subscriptionTier();

        if (! $tier) {
            return 0;
        }

        return $tier->features['predictions_per_day'] ?? null;
    }

    public function hasReachedDailyAlertLimit(): bool
    {
        $limit = $this->getDailyAlertLimit();

        // null means unlimited
        if ($limit === null) {
            return false;
        }

        $todayCount = UserAlertSent::getTodayCountForUser($this->id);

        return $todayCount >= $limit;
    }
}
