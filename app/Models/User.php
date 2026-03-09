<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Admin\TierPermissionSyncService;
use App\Support\SubscriptionTierCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    private ?SubscriptionTier $resolvedSubscriptionTier = null;

    private bool $subscriptionTierResolved = false;

    private ?bool $resolvedFoundingAccess = null;

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
        if ($this->subscriptionTierResolved) {
            return $this->resolvedSubscriptionTier;
        }

        /** @var SubscriptionTierCache $tierCache */
        $tierCache = app(SubscriptionTierCache::class);

        if ($this->hasFoundingAccess()) {
            $foundingTier = $this->foundingTier();
            if ($foundingTier) {
                return $this->rememberResolvedSubscriptionTier($foundingTier);
            }
        }

        if (! $this->subscribed()) {
            return $this->rememberResolvedSubscriptionTier($tierCache->defaultTier());
        }

        $subscription = $this->subscription();
        $tier = $tierCache->tierByStripePrice($subscription?->stripe_price);

        return $this->rememberResolvedSubscriptionTier($tier ?? $tierCache->defaultTier());
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function syncRoleFromTier(): void
    {
        if ($this->hasFoundingAccess()) {
            return;
        }

        $tier = $this->subscriptionTier();

        if ($tier) {
            $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
            $tierSlugs = array_keys(config('subscriptions.tiers', []));
            $nonTierRoles = $this->getRoleNames()
                ->reject(fn ($name) => in_array($name, $tierSlugs, true))
                ->values()
                ->all();

            $this->syncRoles(array_values(array_unique([...$nonTierRoles, $role->name])));
            $this->resetAccessCaches();
        }
    }

    public function hasFoundingAccess(): bool
    {
        if ($this->resolvedFoundingAccess !== null) {
            return $this->resolvedFoundingAccess;
        }

        if (! config('founding_users.enabled', false)) {
            $this->resolvedFoundingAccess = false;

            return false;
        }

        $roleName = (string) config('founding_users.role', 'founding_user');
        if ($roleName === '') {
            $this->resolvedFoundingAccess = false;

            return false;
        }

        $this->resolvedFoundingAccess = $this->hasRole($roleName);

        return $this->resolvedFoundingAccess;
    }

    public function foundingTier(): ?SubscriptionTier
    {
        $foundingTierSlug = (string) config('founding_users.tier_slug', 'premium');
        if ($foundingTierSlug === '') {
            return null;
        }

        /** @var SubscriptionTierCache $tierCache */
        $tierCache = app(SubscriptionTierCache::class);
        $tier = $tierCache->tierBySlug($foundingTierSlug);

        if (! $tier?->is_active) {
            return null;
        }

        return $tier;
    }

    public function bets(): HasMany
    {
        return $this->hasMany(UserBet::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function alertPreference()
    {
        return $this->hasOne(UserAlertPreference::class);
    }

    public function alertsSent(): HasMany
    {
        return $this->hasMany(UserAlertSent::class);
    }

    public function webPushSubscriptions(): HasMany
    {
        return $this->hasMany(WebPushSubscription::class);
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

    private function rememberResolvedSubscriptionTier(?SubscriptionTier $tier): ?SubscriptionTier
    {
        $this->subscriptionTierResolved = true;
        $this->resolvedSubscriptionTier = $tier;

        return $tier;
    }

    private function resetAccessCaches(): void
    {
        $this->subscriptionTierResolved = false;
        $this->resolvedSubscriptionTier = null;
        $this->resolvedFoundingAccess = null;
    }
}
