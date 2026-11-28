<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingRoleEntitlementEvent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use LogicException;

/** Explicit role provenance only. Absence is unknown, never evidence of a billing grant. */
final class BillingRoleEntitlements
{
    public function hasRecorded(User $user): bool
    {
        if (! $user->exists) {
            return false;
        }
        $this->assertConnection($user);
        $connection = $user->getConnection();
        $schema = $connection->getSchemaBuilder();
        $existsSql = $schema->getConnection()->getSchemaGrammar()->compileTableExists(null, $connection->getTablePrefix().'billing_role_entitlements');
        if (! is_string($existsSql)) {
            throw new LogicException('Unsupported authoritative billing entitlement metadata connection.');
        }
        if (! (bool) $connection->scalar($existsSql, [], false)) {
            if (config('billing.entitlements.enabled', false)) {
                throw new LogicException('Entitlement admission requires the provenance schema.');
            }

            return false;
        }

        return DB::transaction(function () use ($user): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            return DB::table('billing_role_entitlements')->where('user_id', $user->id)->lockForUpdate()->first() !== null;
        });
    }

    public function sync(User $user, SubscriptionTier $tier, BillingReconciliationAttempt $attempt): void
    {
        $enabled = config('billing.entitlements.enabled', false);
        if (! is_bool($enabled)) {
            throw new LogicException('Entitlement admission activation must be boolean.');
        }
        // Once explicitly classified, the user remains on the typed lifecycle even with new admission OFF.
        if (! $enabled && ! $this->hasRecorded($user)) {
            throw new BillingProviderFailure('entitlement_provenance_required');
        }
        $this->assertConnection($user);
        $closure = $user->stripe_id === null
            && app(BillingCanonicalWriteContext::class)->allowsCustomerClosure((int) $user->id, (int) $attempt->id);
        $state = BillingCustomerReconciliation::query()->whereKey($attempt->reconciliation_id)->lockForUpdate()->first();
        if ($attempt->getConnection()->getName() !== $user->getConnection()->getName()
            || $state === null || (int) $state->user_id !== (int) $user->id
            || (! $closure && $state->customer_id !== $user->stripe_id)) {
            throw new LogicException('Entitlement attempt must belong to the locked user and connection.');
        }
        if (! app(BillingCanonicalWriteContext::class)->allows((int) $user->id, (int) $attempt->id)) {
            throw new LogicException('Billing role provenance requires current canonical ownership.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Billing role admission requires the current user transaction.');
        }
        $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($current->stripe_id !== $user->stripe_id) {
            throw new BillingProviderFailure('entitlement_customer_drift');
        }
        $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
        $roles = $user->roles()->orderBy('id')->lockForUpdate()->get();
        $provenance = DB::table('billing_role_entitlements')->where('user_id', $user->id)->lockForUpdate()->get()->keyBy('role_id');
        $tierSlugs = SubscriptionTier::query()->pluck('slug')->all();
        foreach ($roles as $existing) {
            if (in_array($existing->name, $tierSlugs, true)
                && ! in_array($provenance->get($existing->id)?->origin, ['manual', 'derived', 'system_default'], true)) {
                throw new BillingProviderFailure('entitlement_role_origin_unknown');
            }
        }
        $manual = $provenance->filter(fn ($grant) => $grant->origin === 'manual' && $roles->contains('id', $grant->role_id));
        if ($manual->isNotEmpty()) {
            foreach ($provenance as $grant) {
                if ($grant->origin === 'derived') {
                    $user->roles()->detach($grant->role_id);
                    DB::table('billing_role_entitlements')->where('id', $grant->id)->delete();
                    $this->audit($user, (int) $grant->role_id, (int) $grant->subscription_tier_id, (array) $grant, null, $attempt, null, 'manual_tier_overrides_billing');
                }
            }
            foreach ($manual as $grant) {
                $this->audit($user, (int) $grant->role_id, (int) $grant->subscription_tier_id, (array) $grant, (array) $grant, $attempt, null, 'canonical_manual_tier_preserved');
            }
            $this->invalidate($user);

            return;
        }
        foreach ($provenance as $grant) {
            if (in_array($grant->origin, ['derived', 'system_default'], true) && (int) $grant->role_id !== (int) $role->id) {
                $user->roles()->detach($grant->role_id);
                DB::table('billing_role_entitlements')->where('id', $grant->id)->delete();
                $this->audit($user, (int) $grant->role_id, (int) $grant->subscription_tier_id, (array) $grant, null, $attempt, null, 'canonical_tier_replaced');
            }
        }
        if (($provenance->get($role->id)?->origin) !== 'manual') {
            DB::table('billing_role_entitlements')->updateOrInsert(['user_id' => $user->id, 'role_id' => $role->id],
                ['origin' => 'derived', 'subscription_tier_id' => $tier->id, 'billing_reconciliation_attempt_id' => $attempt->id,
                    'created_at' => now(), 'updated_at' => now()]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        $after = (array) DB::table('billing_role_entitlements')->where('user_id', $user->id)->where('role_id', $role->id)->first();
        $before = $provenance->get($role->id);
        $this->audit($user, (int) $role->id, (int) $tier->id, $before ? (array) $before : null, $after, $attempt, null, 'canonical_tier_admitted');
        $this->invalidate($user);
    }

    public function assertCanonicalAdmission(User $user, bool $lock = false): void
    {
        $this->assertConnection($user);
        $enabled = config('billing.entitlements.enabled', false);
        $durable = config('billing.durable.enabled', false);
        if (! is_bool($enabled) || ! is_bool($durable)) {
            throw new LogicException('Billing entitlement activation must be boolean.');
        }
        $recorded = $this->hasRecorded($user);
        if (! $durable && ! $recorded) {
            return; // Existing unobserved legacy admission retains its separately documented compatibility boundary.
        }
        if (! $enabled && ! $recorded) {
            throw new BillingProviderFailure('entitlement_provenance_required');
        }
        $this->assertClassifiedTierRoles($user, $lock);
    }

    public function assertClassifiedTierRoles(User $user, bool $lock = false): void
    {
        $this->assertConnection($user);
        if ($lock && DB::transactionLevel() < 1) {
            throw new LogicException('Current entitlement validation requires the user transaction.');
        }
        $roles = $user->roles()->orderBy('id');
        $grants = DB::table('billing_role_entitlements')->where('user_id', $user->id);
        if ($lock) {
            $roles->lockForUpdate();
            $grants->lockForUpdate();
        }
        $origins = $grants->get()->keyBy('role_id');
        $slugs = SubscriptionTier::query()->pluck('slug')->all();
        foreach ($roles->get() as $role) {
            if (in_array($role->name, $slugs, true) && ! in_array($origins->get($role->id)?->origin, ['manual', 'derived', 'system_default'], true)) {
                throw new BillingProviderFailure('entitlement_role_origin_unknown');
            }
        }
    }

    public function grantNewAccountDefault(User $user, string $source): void
    {
        $this->assertConnection($user);
        if (! $user->exists || ! $user->wasRecentlyCreated || DB::transactionLevel() < 1
            || ! in_array($source, ['registration', 'oauth'], true)) {
            throw new LogicException('Default provenance requires an explicit newly persisted account creation transaction.');
        }
        $enabled = config('billing.entitlements.enabled', false);
        if (! is_bool($enabled)) {
            throw new LogicException('Entitlement admission activation must be boolean.');
        }
        if (! $enabled) {
            $user->syncRoleFromTier();

            return;
        }
        $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($current->stripe_id !== null || $current->subscriptions()->limit(1)->lockForUpdate()->get()->isNotEmpty()) {
            throw new LogicException('Existing account billing cannot acquire new-account provenance.');
        }
        $slug = (string) config('subscriptions.default_tier', 'free');
        $tier = SubscriptionTier::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
        if ($tier->slug !== $slug) {
            throw new LogicException('The new-account default tier identity must match exactly.');
        }
        if ($current->roles()->limit(1)->lockForUpdate()->get()->isNotEmpty()
            || DB::table('billing_role_entitlements')->where('user_id', $current->id)->lockForUpdate()->first() !== null) {
            throw new LogicException('Existing account grants cannot acquire new-account provenance.');
        }
        $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
        $current->roles()->attach($role->id);
        DB::table('billing_role_entitlements')->insert(['user_id' => $current->id, 'role_id' => $role->id,
            'origin' => 'system_default', 'subscription_tier_id' => $tier->id, 'billing_reconciliation_attempt_id' => null,
            'created_at' => now(), 'updated_at' => now()]);
        $grant = (array) DB::table('billing_role_entitlements')->where('user_id', $current->id)->where('role_id', $role->id)->first();
        BillingRoleEntitlementEvent::query()->create(['user_id' => $current->id, 'role_id' => $role->id,
            'subscription_tier_id' => $tier->id, 'billing_reconciliation_attempt_id' => null, 'actor_user_id' => null,
            'actor_origin' => $source.'_system', 'reason' => 'new_account_default_granted', 'before_grant' => null,
            'after_grant' => $grant, 'observed_at' => BillingClock::now()]);
        $this->invalidate($current);
        $this->invalidate($user);
    }

    public function grantManualTier(User $user, SubscriptionTier $tier, ?User $actor = null): void
    {
        $this->assertConnection($user);
        if ($actor !== null) {
            $this->assertConnection($actor);
        }
        DB::transaction(function () use ($user, $tier, $actor): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertNoUnresolvedCharge($user);
            $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
            $slugs = SubscriptionTier::query()->pluck('slug')->all();
            $roles = $user->roles()->orderBy('id')->lockForUpdate()->get();
            $origins = DB::table('billing_role_entitlements')->where('user_id', $user->id)->lockForUpdate()->get()->keyBy('role_id');
            foreach ($roles as $existing) {
                if ((int) $existing->id === (int) $role->id || ! in_array($existing->name, $slugs, true)) {
                    continue;
                }
                $origin = $origins->get($existing->id);
                if ($origin === null || ! in_array($origin->origin, ['manual', 'derived', 'system_default'], true)) {
                    throw new BillingProviderFailure('entitlement_role_origin_unknown');
                }
                $user->roles()->detach($existing->id);
                DB::table('billing_role_entitlements')->where('id', $origin->id)->delete();
                $this->audit($user, (int) $existing->id, (int) $origin->subscription_tier_id, (array) $origin, null, null, $actor, 'explicit_manual_tier_replaced');
            }
            $before = DB::table('billing_role_entitlements')->where('user_id', $user->id)->where('role_id', $role->id)->lockForUpdate()->first();
            // Explicit admin action classifies this grant; unrelated unknown/manual roles are retained.
            DB::table('billing_role_entitlements')->updateOrInsert(['user_id' => $user->id, 'role_id' => $role->id],
                ['origin' => 'manual', 'subscription_tier_id' => $tier->id, 'billing_reconciliation_attempt_id' => null,
                    'created_at' => now(), 'updated_at' => now()]);
            $user->roles()->syncWithoutDetaching([$role->id]);
            $after = (array) DB::table('billing_role_entitlements')->where('user_id', $user->id)->where('role_id', $role->id)->first();
            $this->audit($user, (int) $role->id, (int) $tier->id, $before ? (array) $before : ($roles->contains('id', $role->id) ? ['origin' => 'legacy_unknown', 'attached' => true, 'user_id' => $user->id, 'role_id' => $role->id] : null), $after, null, $actor, 'explicit_manual_tier');
            $this->invalidate($user);
        });
        $this->invalidate($user);
    }

    public function manualOverrideTier(User $user): ?SubscriptionTier
    {
        $this->assertConnection($user);
        if (! $this->hasRecorded($user)) {
            return null;
        }

        return DB::transaction(function () use ($user): ?SubscriptionTier {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $grants = DB::table('billing_role_entitlements')->where('user_id', $current->id)->where('origin', 'manual')
                ->orderBy('role_id')->limit(101)->lockForUpdate()->get();
            if ($grants->count() > 100) {
                throw new LogicException('Manual entitlement inventory exceeds the supported bound.');
            }
            $tiers = SubscriptionTier::query()->whereIn('id', $grants->pluck('subscription_tier_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $roleKey = $current->roles()->getRelated()->qualifyColumn('id');
            $roles = $current->roles()->whereIn($roleKey, $grants->pluck('role_id'))->orderBy($roleKey)->lockForUpdate()->get();
            $active = $grants->filter(fn ($grant) => $roles->contains('id', $grant->role_id));
            if ($active->isEmpty()) {
                return null;
            }
            if ($active->pluck('subscription_tier_id')->unique()->count() !== 1) {
                throw new LogicException('Manual entitlement tier is ambiguous.');
            }

            return $tiers->get($active->first()->subscription_tier_id)
                ?? throw new LogicException('Manual entitlement tier is unavailable.');
        });
    }

    public function clearManualOverride(User $user, string $reason, ?User $actor = null): int
    {
        $this->assertConnection($user);
        if ($actor !== null) {
            $this->assertConnection($actor);
        }
        if (trim($reason) === '' || mb_strlen($reason) > 256 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new LogicException('An explicit bounded operator reason is required.');
        }

        return DB::transaction(function () use ($user, $reason, $actor): int {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertNoUnresolvedCharge($current);
            $this->manualOverrideTier($current);
            $grants = DB::table('billing_role_entitlements')->where('user_id', $current->id)->where('origin', 'manual')->lockForUpdate()->get();
            foreach ($grants as $grant) {
                $current->roles()->detach($grant->role_id);
                $after = [...(array) $grant, 'origin' => 'revoked', 'updated_at' => (string) now()];
                DB::table('billing_role_entitlements')->where('id', $grant->id)->update(['origin' => 'revoked', 'updated_at' => now()]);
                $this->audit($current, (int) $grant->role_id, (int) $grant->subscription_tier_id, (array) $grant,
                    [...$after, 'operator_reason' => $reason], null, $actor, 'explicit_manual_override_removed');
            }
            $this->invalidate($current);
            $this->invalidate($user);

            return $grants->count();
        });
    }

    public function effectiveRoles(User $user): Collection
    {
        $this->assertConnection($user);

        return DB::transaction(function () use ($user): Collection {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $tier = $this->canonicalTier($current);
            $roles = $current->roles()->with('permissions')->orderBy('id')->lockForUpdate()->get();
            $grants = DB::table('billing_role_entitlements')->where('user_id', $current->id)->lockForUpdate()->get()->keyBy('role_id');

            return $roles->filter(function ($role) use ($grants, $tier): bool {
                $grant = $grants->get($role->id);

                return $grant === null || in_array($grant->origin, ['manual', 'system_default'], true)
                    || ($grant->origin === 'derived' && $tier !== null && (int) $grant->subscription_tier_id === (int) $tier->id);
            });
        });
    }

    public function currentCanonicalTier(User $user): ?SubscriptionTier
    {
        $this->assertConnection($user);

        return DB::transaction(function () use ($user): ?SubscriptionTier {
            return $this->canonicalTier(User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function canonicalTier(User $user): ?SubscriptionTier
    {
        $class = Cashier::$subscriptionModel;
        $mysql = $user->getConnection()->getDriverName() === 'mysql';
        $rows = $class::query()->where('user_id', $user->id)
            ->whereRaw($mysql ? 'CAST(type AS BINARY) = ?' : 'type COLLATE BINARY = ?', ['default'])->orderBy('id')->limit(101)->lockForUpdate()->get();
        if ($rows->count() > 100) {
            return null;
        }
        if ($rows->isEmpty() || $rows->every(fn ($row) => ! $row->valid())) {
            return SubscriptionTier::query()->where('slug', config('subscriptions.default_tier', 'free'))->lockForUpdate()->first();
        }
        if ($rows->contains(fn ($row) => ! $row->hasCanonicalProjection() || $row->billing_created_epoch === null)) {
            return null;
        }
        if ($rows->isNotEmpty()) {
            $latest = $rows->where('billing_created_epoch', $rows->max('billing_created_epoch'));
            if ($latest->count() !== 1) {
                return null;
            }
            $subscription = $latest->first();
            if ($subscription->type !== 'default') {
                return null;
            }
            if ($subscription->valid()) {
                if (! is_string($subscription->stripe_price) || $subscription->stripe_price === '') {
                    return null;
                }
                $predicate = $mysql ? '(CAST(stripe_price_id_monthly AS BINARY) = ? OR CAST(stripe_price_id_yearly AS BINARY) = ?)'
                    : '(stripe_price_id_monthly COLLATE BINARY = ? OR stripe_price_id_yearly COLLATE BINARY = ?)';
                $tiers = SubscriptionTier::query()->whereRaw($predicate, [$subscription->stripe_price, $subscription->stripe_price])->orderBy('id')->limit(2)->lockForUpdate()->get();

                if ($tiers->count() !== 1 || ! in_array($subscription->stripe_price,
                    [$tiers->first()->stripe_price_id_monthly, $tiers->first()->stripe_price_id_yearly], true)) {
                    return null;
                }

                return $tiers->first();
            }
        }

        return SubscriptionTier::query()->where('slug', config('subscriptions.default_tier', 'free'))->lockForUpdate()->first();
    }

    private function assertNoUnresolvedCharge(User $user): void
    {
        $state = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
        if ($state?->active_intent_id === null) {
            return;
        }
        $intent = BillingMutationIntent::query()->whereKey($state->active_intent_id)->lockForUpdate()->first();
        if ($intent === null || (int) $intent->subject_user_id !== (int) $user->id
            || ! in_array($intent->kind, ['cancel', 'resume', 'portal', 'legacy_cancel', 'legacy_resume'], true)) {
            throw ValidationException::withMessages([
                'tier_slug' => 'An unresolved financial change prevents manual override changes; resolve it explicitly first.',
            ]);
        }
    }

    private function audit(User $user, int $roleId, int $tierId, ?array $before, ?array $after,
        ?BillingReconciliationAttempt $attempt, ?User $actor, string $reason): void
    {
        BillingRoleEntitlementEvent::query()->create([
            'user_id' => $user->id, 'role_id' => $roleId, 'subscription_tier_id' => $tierId,
            'billing_reconciliation_attempt_id' => $attempt?->id, 'actor_user_id' => $actor?->id,
            'actor_origin' => $attempt ? 'billing_system' : ($actor ? 'manual_operator' : 'manual_system'),
            'reason' => $reason, 'before_grant' => $before, 'after_grant' => $after, 'observed_at' => BillingClock::now(),
        ]);
    }

    private function assertConnection(User $user): void
    {
        if ($user->getConnection()->getName() !== DB::connection()->getName()
            || $user->roles()->getRelated()->getConnection()->getName() !== DB::connection()->getName()
            || (new SubscriptionTier)->getConnection()->getName() !== DB::connection()->getName()
            || (new (Cashier::$subscriptionModel))->getConnection()->getName() !== DB::connection()->getName()
            || (new BillingMutationState)->getConnection()->getName() !== DB::connection()->getName()
            || (new BillingMutationIntent)->getConnection()->getName() !== DB::connection()->getName()
            || (new (config('permission.models.permission')))->getConnection()->getName() !== DB::connection()->getName()) {
            throw new LogicException('Entitlement models must use the billing connection.');
        }
    }

    private function invalidate(User $user): void
    {
        $user->unsetRelation('roles')->unsetRelation('permissions')->unsetRelation('subscriptions');
        $user->forgetWildcardPermissionIndex();
    }
}
