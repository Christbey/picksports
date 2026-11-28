<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use RuntimeException;

/** Explicit CLI intent only: whole-scope preflight, then one atomic admission per customer. */
final class BillingOperatorAdmission
{
    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly BillingCustomerIdentity $customers) {}

    public function plan(array $stateIds, array $userIds, array $subscriptionIds, bool $resetExhausted = false, bool $resumeClassifiedRoleOrigin = false, bool $resumeEntitlementAdmission = false): array
    {
        $this->database->assert();
        if (! config('billing.durable.enabled', false)) {
            throw new InvalidArgumentException('Durable billing is disabled.');
        }
        $context = new BillingAccountContext;
        foreach ([$stateIds, $userIds, $subscriptionIds] as $ids) {
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new InvalidArgumentException('Scope IDs must be positive local integers.');
                }
            }
        }
        $total = count($stateIds) + count($userIds) + count($subscriptionIds);
        if ($total < 1 || $total > 100) {
            throw new InvalidArgumentException('Select 1 through 100 explicit local IDs.');
        }
        $scope = [];
        foreach (array_unique($stateIds) as $id) {
            $state = BillingCustomerReconciliation::query()->find($id);
            if ($state === null || $state->account_id !== $context->accountId || $state->livemode !== $context->livemode) {
                throw new InvalidArgumentException('Selected billing state is missing or belongs to another context.');
            }
            $users = $this->customers->users($state->customer_id, false);
            if ($users->count() !== 1) {
                throw new InvalidArgumentException('Selected customer requires an exact unique local linkage.');
            }
            $scope[$users->first()->id] = ['wide' => true, 'subscription_ids' => []];
        }
        foreach (array_unique($userIds) as $id) {
            if (User::query()->find($id) === null) {
                throw new InvalidArgumentException('Selected user is missing.');
            }
            $scope[$id] = ['wide' => true, 'subscription_ids' => $scope[$id]['subscription_ids'] ?? []];
        }
        $class = Cashier::$subscriptionModel;
        foreach (array_unique($subscriptionIds) as $id) {
            $row = $class::query()->find($id);
            if ($row === null) {
                throw new InvalidArgumentException('Selected local subscription is missing.');
            }
            $scope[$row->user_id] ??= ['wide' => false, 'subscription_ids' => []];
            $scope[$row->user_id]['subscription_ids'][] = $id;
        }
        $plans = [];
        foreach ($scope as $userId => $selection) {
            $user = User::query()->find($userId);
            $this->user($user);
            $state = BillingCustomerReconciliation::query()->where([...$context->scope(), 'customer_id' => $user->stripe_id])->first();
            if ($resumeClassifiedRoleOrigin && $state?->failure_category === 'entitlement_role_origin_unknown') {
                app(BillingRoleEntitlements::class)->assertClassifiedTierRoles($user);
            }
            if ($resumeEntitlementAdmission && $state?->failure_category === 'entitlement_provenance_required') {
                app(BillingRoleEntitlements::class)->assertCanonicalAdmission($user);
            }
            $this->available($state, $resetExhausted, $resumeClassifiedRoleOrigin, $resumeEntitlementAdmission);
            $targets = $this->targets($user, $state, $selection, false);
            $plans[] = ['user_id' => $user->id, 'customer_id' => $user->stripe_id, 'state_id' => $state?->id,
                'selection' => $selection, 'targets' => $targets, 'reset_exhausted' => $resetExhausted, 'resume_classified_role_origin' => $resumeClassifiedRoleOrigin, 'resume_entitlement_admission' => $resumeEntitlementAdmission];
        }
        usort($plans, fn ($a, $b) => strcmp($a['customer_id'], $b['customer_id']));

        return $plans;
    }

    public function admit(array $plan, string $reason, ?User $actor = null): BillingReconciliationAttempt
    {
        $this->database->assert();
        if ($actor !== null && $actor->getConnection()->getName() !== DB::connection()->getName()) {
            throw new InvalidArgumentException('Operator actor must use the checked billing connection.');
        }
        if (! config('billing.durable.enabled', false) || $reason === '' || mb_strlen($reason) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidArgumentException('A bounded plain-text operator reason is required.');
        }
        $context = new BillingAccountContext;

        return DB::transaction(function () use ($plan, $reason, $context, $actor): BillingReconciliationAttempt {
            $key = [...$context->scope(), 'customer_id' => $plan['customer_id']];
            $state = BillingCustomerReconciliation::query()->where($key)->lockForUpdate()->first();
            $state ??= BillingCustomerReconciliation::query()->create($key)->refresh();
            if ($state->account_id !== $context->accountId || $state->livemode !== $context->livemode || $state->customer_id !== $plan['customer_id']) {
                throw new RuntimeException('Customer context changed after preflight.');
            }
            $users = $this->customers->users($state->customer_id);
            if ($users->count() !== 1 || (int) $users->first()->id !== (int) $plan['user_id']) {
                throw new RuntimeException('Customer linkage changed after preflight.');
            }
            if (($plan['resume_classified_role_origin'] ?? false) && $state->failure_category === 'entitlement_role_origin_unknown') {
                app(BillingRoleEntitlements::class)->assertClassifiedTierRoles($users->first(), true);
            }
            if (($plan['resume_entitlement_admission'] ?? false) && $state->failure_category === 'entitlement_provenance_required') {
                app(BillingRoleEntitlements::class)->assertCanonicalAdmission($users->first(), true);
            }
            $this->available($state, $plan['reset_exhausted'], $plan['resume_classified_role_origin'] ?? false, $plan['resume_entitlement_admission'] ?? false);
            $targets = $this->targets($users->first(), $state, $plan['selection'], true);
            if ($targets !== $plan['targets']) {
                throw new RuntimeException('Customer targets changed after preflight; review a new dry run.');
            }
            if ($state->owner_token !== null) {
                $expired = BillingReconciliationAttempt::query()->where('reconciliation_id', $state->id)
                    ->where('owner_token', $state->owner_token)->lockForUpdate()->first();
                if ($expired?->status === 'running') {
                    $expired->update(['status' => 'stale', 'completed_at' => BillingClock::now(), 'failure_category' => 'operator_replaced_expired_lease']);
                }
            }
            $generation = $state->generation + 1;
            $intent = BillingReconciliationAttempt::query()->create(['reconciliation_id' => $state->id,
                'generation' => $generation, 'lease_sequence' => $state->lease_sequence, 'owner_token' => (string) Str::uuid(),
                'kind' => 'operator_request', 'status' => 'requested', 'started_at' => BillingClock::now(), 'completed_at' => BillingClock::now(),
                'targets' => $targets, 'details' => ['actor' => $actor === null ? 'cli' : 'admin', 'actor_user_id' => $actor?->id, 'reason' => $reason, 'user_id' => $users->first()->id,
                    'customer_id' => $state->customer_id, 'supersedes_request_id' => $state->operator_request_id,
                    'prior_failure_category' => $state->failure_category, 'prior_attempt_count' => $state->attempts,
                    'retry_exhaustion_reset' => $state->failure_category === 'retry_exhausted',
                    'classified_role_origin_resume' => $state->failure_category === 'entitlement_role_origin_unknown' && ($plan['resume_classified_role_origin'] ?? false),
                    'entitlement_admission_resume' => $state->failure_category === 'entitlement_provenance_required' && ($plan['resume_entitlement_admission'] ?? false)]]);
            $state->update(['generation' => $generation, 'user_id' => $users->first()->id, 'status' => 'pending',
                'owner_token' => null, 'lease_expires_at' => null, 'attempts' => 0, 'next_attempt_at' => BillingClock::now(), 'failure_category' => null,
                'requested_targets' => $targets, 'operator_request_id' => $intent->id, 'operator_generation' => $generation]);

            return $intent;
        }, attempts: 3);
    }

    private function user(?User $user): void
    {
        if ($user === null || ! is_string($user->stripe_id) || preg_match('/\Acus_[A-Za-z0-9_]{1,187}\z/', $user->stripe_id) !== 1
            || $this->customers->users($user->stripe_id, false)->count() !== 1) {
            throw new InvalidArgumentException('User requires an exact unique Stripe customer binding.');
        }
    }

    private function available(?BillingCustomerReconciliation $state, bool $resetExhausted, bool $resumeClassifiedRoleOrigin = false, bool $resumeEntitlementAdmission = false): void
    {
        if ($state === null) {
            return;
        }
        if ($state->owner_token !== null && $state->lease_expires_at?->isFuture()) {
            throw new InvalidArgumentException('Customer has a live reconciliation lease.');
        }
        if ($state->status === 'attention' && ! ($resetExhausted && $state->failure_category === 'retry_exhausted')
            && ! ($resumeClassifiedRoleOrigin && $state->failure_category === 'entitlement_role_origin_unknown')
            && ! ($resumeEntitlementAdmission && $state->failure_category === 'entitlement_provenance_required')) {
            throw new InvalidArgumentException('Customer attention requires separate resolution; hash-conflict holds cannot be reset here.');
        }
    }

    private function targets(User $user, ?BillingCustomerReconciliation $state, array $selection, bool $current): array
    {
        $query = $user->subscriptions()->reorder('id');
        if (! $selection['wide']) {
            $query->whereIn('id', $selection['subscription_ids']);
        }
        if ($current) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(9)->get();
        if (! $selection['wide'] && $rows->count() !== count(array_unique($selection['subscription_ids']))) {
            throw new InvalidArgumentException('Selected subscriptions no longer belong to this customer.');
        }
        $targets = $rows->pluck('stripe_id')->all();
        $deletion = false;
        if ($state !== null) {
            $pending = BillingWebhookReceipt::query()->where('reconciliation_id', $state->id)->where('status', '!=', 'applied');
            $targets = [...$targets, ...(clone $pending)->whereNotNull('subscription_id')->distinct()->limit(9)->pluck('subscription_id')->all(), ...BillingOperatorIntent::targets($state, $current)];
            $deletion = (clone $pending)->where('event_type', 'customer.deleted')->exists();
        }
        foreach ($targets as $target) {
            if (! is_string($target) || preg_match('/\Asub_[A-Za-z0-9_]{1,187}\z/', $target) !== 1) {
                throw new InvalidArgumentException('Subscription identity is invalid.');
            }
        }
        $targets = array_values(array_unique($targets, SORT_STRING));
        sort($targets, SORT_STRING);
        if (count($targets) > min(8, max(1, (int) config('billing.maximum_targets', 4))) || ($targets === [] && ! $deletion)) {
            throw new InvalidArgumentException('Review a bounded explicit subscription scope with an authoritative mode-bearing target.');
        }

        return $targets;
    }
}
