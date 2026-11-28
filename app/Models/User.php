<?php

namespace App\Models;

use App\Services\Admin\TierPermissionSyncService;
use App\Services\Billing\BillingRoleEntitlements;
use App\Support\SubscriptionTierCache;
use App\Support\UserTierResolver;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    use HasRoles {
        hasPermissionTo as private spatieHasPermissionTo;
        getPermissionsViaRoles as private spatiePermissionsViaRoles;
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        // Wildcard indexes otherwise outlive a canonical epoch boundary on a reused User instance.
        $this->forgetWildcardPermissionIndex();

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    protected function hasPermissionViaRole(Permission $permission): bool
    {
        return $this->getPermissionsViaRoles()->contains($permission->getKeyName(), $permission->getKey());
    }

    public function getPermissionsViaRoles(): Collection
    {
        $entitlements = app(BillingRoleEntitlements::class);
        if (! $entitlements->hasRecorded($this)) {
            return $this->spatiePermissionsViaRoles();
        }

        return $entitlements->effectiveRoles($this)->flatMap(fn ($role) => $role->permissions)->sort()->values();
    }

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
        'email_verified_at',
        'password',
        'age_verified_at',
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
            'age_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function subscription(string $type = 'default'): ?CashierSubscription
    {
        $rows = $this->subscriptions->where('type', $type);
        if ($rows->isEmpty() || $rows->contains(fn ($row) => ($row->getAttributes()['billing_projection_attempt_id'] ?? null) === null)) {
            // Unobserved legacy rows retain the original Cashier ordering and interpretation.
            return $rows->first();
        }
        if ($rows->contains(fn ($row) => $row->getAttribute('billing_created_epoch') === null)) {
            return null;
        }
        $latest = $rows->max(fn ($row) => $row->getAttribute('billing_created_epoch'));
        $candidates = $rows->filter(fn ($row) => $row->getAttribute('billing_created_epoch') === $latest);

        // Provider-created seconds are not a total order; an ambiguous canonical default cannot grant access.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    public function subscriptionTier(): ?SubscriptionTier
    {
        if (app(BillingRoleEntitlements::class)->hasRecorded($this)) {
            return app(UserTierResolver::class)->resolveTier($this);
        }
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

    public function syncRoleFromTier(?SubscriptionTier $resolvedTier = null): void
    {
        if (app(BillingRoleEntitlements::class)->hasRecorded($this)) {
            throw new \LogicException('Recorded tier grants require explicit billing or manual provenance admission.');
        }
        $this->resetAccessCaches();
        $this->unsetRelation('subscriptions');

        if ($this->hasFoundingAccess()) {
            return;
        }

        // Billing reconciliation supplies a freshly resolved/locked tier; ordinary callers retain their cache contract.
        $tier = $resolvedTier ?? $this->subscriptionTier();

        if ($tier) {
            $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
            // Database-managed tiers can be retired while their roles still exist.
            // Preserve independently assigned roles, but never retain a former tier.
            $tierSlugs = array_unique([
                ...array_keys(config('subscriptions.tiers', [])),
                ...($resolvedTier !== null ? SubscriptionTier::query()->pluck('slug')->all() : app(SubscriptionTierCache::class)->allSlugs()),
            ]);
            $reserved = ['admin', 'super-admin', 'super_admin', strtolower((string) config('founding_users.role', 'founding_user'))];
            $tierSlugs = array_values(array_filter($tierSlugs, static fn ($slug) => ! in_array(strtolower(trim($slug)), $reserved, true)));
            if ($resolvedTier !== null) {
                // Detach only derived tier roles. Never replace an independently attached role from an old snapshot.
                $managedIds = $role->newQuery()->where('guard_name', $role->guard_name)->whereIn('name', $tierSlugs)->pluck('id');
                $this->roles()->detach($managedIds->reject(fn ($id) => (string) $id === (string) $role->id)->all());
                $this->roles()->syncWithoutDetaching([$role->id]);
                $this->unsetRelation('roles');
                $this->forgetWildcardPermissionIndex();
                $this->resetAccessCaches();

                return;
            }
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

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OauthAccount::class);
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

    public function routeNotificationForVonage(Notification $notification): ?string
    {
        $phoneNumber = $this->alertPreference?->phone_number;

        if (! is_string($phoneNumber) || trim($phoneNumber) === '') {
            return null;
        }

        return $phoneNumber;
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

    public function hasCompletedRequiredOnboarding(): bool
    {
        return $this->age_verified_at !== null;
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
