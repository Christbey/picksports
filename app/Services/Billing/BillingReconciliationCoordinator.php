<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Customer -> user -> operational projection -> ledger locks. Provider work never belongs in these callbacks. */
final class BillingReconciliationCoordinator
{
    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly BillingCustomerIdentity $customers) {}

    public function claim(int $stateId): ?BillingReconciliationAttempt
    {
        $this->database->assert();
        $context = new BillingAccountContext;
        $lease = (int) config('billing.lease_seconds', 120);
        if ($lease < (int) config('billing.fetch_budget_seconds', 60) + 30 || $lease > 600) {
            throw new LogicException('Billing lease must include the bounded fetch budget and commit margin.');
        }

        return DB::transaction(function () use ($stateId, $context, $lease): ?BillingReconciliationAttempt {
            $state = BillingCustomerReconciliation::query()->lockForUpdate()->findOrFail($stateId);
            $this->scope($state, $context);
            if (! in_array($state->status, ['pending', 'pending_linkage', 'processing'], true) || $state->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($state->owner_token !== null && $state->lease_expires_at?->isFuture()) {
                return null;
            }
            if ($state->owner_token !== null) {
                $expired = BillingReconciliationAttempt::query()->where('reconciliation_id', $state->id)
                    ->where('owner_token', $state->owner_token)->lockForUpdate()->first();
                if ($expired?->status === 'running') {
                    $expired->update(['status' => 'stale', 'completed_at' => BillingClock::now(), 'failure_category' => 'lease_expired']);
                }
                $state->fill(['owner_token' => null, 'lease_expires_at' => null]);
            }
            $users = $this->customers->users($state->customer_id);
            if ($users->count() !== 1) {
                $state->fill(['status' => $users->isEmpty() ? 'pending_linkage' : 'attention', 'user_id' => null,
                    'failure_category' => $users->isEmpty() ? 'unknown_customer' : 'ambiguous_customer',
                    'next_attempt_at' => $users->isEmpty() ? BillingClock::now()->addMinutes(5) : null])->save();

                return null;
            }
            $receipts = BillingWebhookReceipt::query()->where('reconciliation_id', $state->id)
                ->where('generation', '<=', $state->generation)->where('status', '!=', 'applied')
                ->orderBy('id')->limit(101)->lockForUpdate()->get();
            $operatorTargets = BillingOperatorIntent::targets($state);
            if ($receipts->isEmpty() && $state->operator_request_id === null) {
                $state->fill(['status' => 'idle', 'user_id' => $users->first()->id, 'next_attempt_at' => null])->save();

                return null;
            }
            if ($state->attempts >= min(20, max(1, (int) config('billing.maximum_attempts', 8)))) {
                $state->fill(['status' => 'attention', 'failure_category' => 'retry_exhausted', 'next_attempt_at' => null])->save();

                return null;
            }
            $limit = (int) config('billing.maximum_targets', 4);
            if ($limit < 1 || $limit > 8) {
                throw new LogicException('Invalid billing target limit.');
            }
            $pending = BillingWebhookReceipt::query()->where('reconciliation_id', $state->id)
                ->where('generation', '<=', $state->generation)->where('status', '!=', 'applied');
            $ids = (clone $pending)->whereNotNull('subscription_id')->select('subscription_id')->distinct()
                ->orderBy('subscription_id')->limit($limit + 1)->pluck('subscription_id');
            $ids = $ids->concat($operatorTargets)->uniqueStrict()->sort(SORT_STRING)->values();
            $defaults = $users->first()->subscriptions()->reorder('id');
            $typePredicate = $defaults->getRelated()->getConnection()->getDriverName() === 'mysql' ? 'CAST(type AS BINARY) = ?' : 'type COLLATE BINARY = ?';
            $defaultIds = $defaults->whereRaw($typePredicate, ['default'])->limit($limit + 1)->lockForUpdate()->pluck('stripe_id');
            $ids = $ids->concat($defaultIds)->uniqueStrict()->sort(SORT_STRING)->values();
            $deleted = (clone $pending)->where('event_type', 'customer.deleted')->orderBy('id')->lockForUpdate()->first();
            if ($deleted !== null) {
                $ids = $ids->concat($users->first()->subscriptions()->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
                    ->limit($limit + 1)->pluck('stripe_id'))->uniqueStrict()->sort(SORT_STRING)->values();
            }
            if ($ids->count() > $limit) {
                if ($deleted === null) {
                    $state->fill(['status' => 'attention', 'failure_category' => 'target_limit_exceeded', 'next_attempt_at' => null])->save();

                    return null;
                }
                // A bound current customer tombstone can cover all targets without fetching each subscription.
                // If the customer is still live, these unfetched subscription receipts remain pending.
                $ids = collect();
            }
            $targets = $ids->take($limit)->all();
            $owner = (string) Str::uuid();
            $state->fill(['status' => 'processing', 'user_id' => $users->first()->id,
                'owner_token' => $owner, 'lease_sequence' => $state->lease_sequence + 1,
                'lease_expires_at' => BillingClock::now()->addSeconds($lease), 'attempts' => $state->attempts + 1,
                'failure_category' => null, 'next_attempt_at' => null])->save();

            return BillingReconciliationAttempt::query()->create([
                'reconciliation_id' => $state->id, 'generation' => $state->generation,
                'lease_sequence' => $state->lease_sequence, 'owner_token' => $owner,
                'kind' => 'reconcile', 'status' => 'running', 'started_at' => BillingClock::now(),
                'targets' => $targets, 'details' => ['customer_deletion_event_id' => $deleted?->event_id,
                    'operator_request_id' => $state->operator_request_id, 'operator_generation' => $state->operator_generation,
                    'receipt_ids' => $receipts->take(100)->modelKeys(), 'has_receipt_overflow' => $receipts->count() > 100],
            ]);
        }, attempts: 3);
    }

    /** Apply only a current observation; the callback performs reviewed same-connection local SQL. */
    public function commit(BillingReconciliationAttempt $owner, array $observation, Closure $project): bool
    {
        $this->database->assert();
        $context = new BillingAccountContext;

        return DB::transaction(function () use ($owner, $observation, $project, $context): bool {
            [$state, $attempt] = $this->owned($owner);
            $this->scope($state, $context);
            if (! $this->active($state, $attempt)) {
                $this->stale($state, $attempt);

                return false;
            }
            if (($observation['account_id'] ?? null) !== $state->account_id
                || ($observation['livemode'] ?? null) !== $state->livemode
                || ($observation['customer_id'] ?? null) !== $state->customer_id
                || ! is_bool($observation['customer_deleted'] ?? null)
                || ! is_array($observation['subscriptions'] ?? null) || ! array_is_list($observation['subscriptions'])) {
                throw new RuntimeException('Billing observation scope does not match its claimed customer.');
            }
            if (! $observation['customer_deleted']) {
                $observedIds = array_column($observation['subscriptions'], 'id');
                $expectedIds = $attempt->targets;
                sort($observedIds, SORT_STRING);
                sort($expectedIds, SORT_STRING);
                if ($observedIds !== $expectedIds) {
                    throw new RuntimeException('Billing observation does not cover the claimed subscription targets.');
                }
            } else {
                $proof = $observation['deletion_proof'] ?? [];
                if (($proof['event_id'] ?? null) !== ($attempt->details['customer_deletion_event_id'] ?? null)
                    || ! is_string($proof['event_id'] ?? null)
                    || ($proof['account_id'] ?? null) !== $state->account_id
                    || ($proof['livemode'] ?? null) !== $state->livemode
                    || ($proof['customer_id'] ?? null) !== $state->customer_id
                    || ($proof['customer_object'] ?? null) !== 'customer' || ($proof['deleted'] ?? null) !== true) {
                    throw new RuntimeException('Billing customer deletion lacks bound authority evidence.');
                }
            }
            $users = $this->customers->users($state->customer_id);
            if ($users->count() !== 1 || (int) $users->first()->id !== (int) $state->user_id) {
                throw new RuntimeException('Billing customer linkage changed during observation.');
            }
            $evidence = BillingObservationEvidence::capture($observation);
            $result = $project($users->first(), $state, $attempt, $observation);
            // Time may pass while waiting for local locks; an expired owner cannot commit.
            if (! $this->active($state, $attempt)) {
                throw new RuntimeException('Billing observation lease expired during projection.');
            }
            $fingerprint = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
            $attempt->update(['status' => 'succeeded', 'completed_at' => BillingClock::now(),
                'fetch_started_at' => BillingClock::fromEpoch($observation['fetch_started_at'] ?? null),
                'fetch_completed_at' => BillingClock::fromEpoch($observation['fetch_completed_at'] ?? null),
                'source_fingerprint' => $fingerprint, 'observation_evidence' => $evidence,
                'before_projection' => $result['before'], 'after_projection' => $result['after']]);
            $receipts = BillingWebhookReceipt::query()->where('reconciliation_id', $state->id)
                ->where('generation', '<=', $attempt->generation)->where('status', '!=', 'applied')
                ->whereIn('id', (array) ($attempt->details['receipt_ids'] ?? []))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($receipts as $receipt) {
                $covered = ($observation['customer_deleted'] ?? false)
                    || ($receipt->subscription_id !== null && in_array($receipt->subscription_id, $attempt->targets, true))
                    || $receipt->event_type === 'customer.deleted';
                // Keep the deletion trigger/binding until bounded batches drain all covered receipts.
                if (($observation['customer_deleted'] ?? false) && ($attempt->details['has_receipt_overflow'] ?? false)
                    && $receipt->event_id === ($attempt->details['customer_deletion_event_id'] ?? null)) {
                    $covered = false;
                }
                if ($covered) {
                    $receipt->update(['status' => 'applied', 'processed_at' => BillingClock::now(), 'failure_category' => null]);
                }
            }
            $pending = BillingWebhookReceipt::query()->where('reconciliation_id', $state->id)->where('status', '!=', 'applied')->exists();
            $operatorComplete = $state->operator_request_id !== null
                && $state->operator_request_id === ($attempt->details['operator_request_id'] ?? null)
                && $state->operator_generation === ($attempt->details['operator_generation'] ?? null)
                && ($observation['customer_deleted'] || array_diff(BillingOperatorIntent::targets($state), $attempt->targets) === []);
            if ($operatorComplete) {
                $state->fill(['operator_request_id' => null, 'operator_generation' => null, 'requested_targets' => null]);
            }
            $pending = $pending || $state->operator_request_id !== null;
            $state->update(['status' => $pending ? 'pending' : 'idle', 'owner_token' => null, 'lease_expires_at' => null,
                'attempts' => 0, 'next_attempt_at' => $pending ? BillingClock::now() : null, 'failure_category' => null,
                'last_observed_at' => BillingClock::now(), 'last_projection_hash' => $fingerprint, 'cache_invalidation_pending' => true,
                'cache_generation' => $attempt->generation, 'cache_lease_sequence' => $attempt->lease_sequence]);

            return true;
        }, attempts: 3);
    }

    /** At-least-once queue delivery cannot start the same provider observation twice. */
    public function begin(BillingReconciliationAttempt $owner): bool
    {
        $this->database->assert();
        $lease = (int) config('billing.lease_seconds', 120);
        if ($lease < (int) config('billing.fetch_budget_seconds', 60) + 30 || $lease > 600) {
            throw new LogicException('Billing execution lease must cover its bounded fetch and commit margin.');
        }

        return DB::transaction(function () use ($owner, $lease): bool {
            [$state, $attempt] = $this->owned($owner);
            $this->scope($state, new BillingAccountContext);
            if (! $this->active($state, $attempt) || $attempt->fetch_started_at !== null) {
                return false;
            }
            $attempt->update(['fetch_started_at' => BillingClock::now()]);
            // One permitted extension at execution start; queue waiting cannot consume the fetch budget.
            $state->update(['lease_expires_at' => BillingClock::now()->addSeconds($lease)]);

            return true;
        }, attempts: 3);
    }

    public function fail(BillingReconciliationAttempt $owner, string $category, ?array $observation = null): bool
    {
        $this->database->assert();
        $context = new BillingAccountContext;

        return DB::transaction(function () use ($owner, $category, $context, $observation): bool {
            [$state, $attempt] = $this->owned($owner);
            $this->scope($state, $context);
            if (! $this->active($state, $attempt)) {
                $this->stale($state, $attempt);

                return false;
            }
            if (preg_match('/\A[a-z_]{1,100}\z/', $category) !== 1) {
                $category = 'unexpected_failure';
            }
            $evidence = null;
            if ($observation !== null) {
                try {
                    $evidence = BillingObservationEvidence::capture($observation);
                } catch (BillingProviderFailure) { /* Never persist malformed or raw provider evidence. */
                }
            }
            $classificationRequired = in_array($category, ['entitlement_role_origin_unknown', 'entitlement_provenance_required'], true);
            $attempt->update(['status' => $classificationRequired ? 'attention' : 'retry', 'completed_at' => BillingClock::now(), 'failure_category' => $category,
                'observation_evidence' => $evidence]);
            $exhausted = $state->attempts >= min(20, max(1, (int) config('billing.maximum_attempts', 8)));
            $state->update(['status' => ($exhausted || $classificationRequired) ? 'attention' : 'pending', 'owner_token' => null, 'lease_expires_at' => null,
                'next_attempt_at' => ($exhausted || $classificationRequired) ? null : BillingClock::now()->addSeconds(min(3600, 30 * (2 ** min(7, $state->attempts - 1)))),
                'failure_category' => $exhausted ? 'retry_exhausted' : $category]);

            return true;
        }, attempts: 3);
    }

    private function owned(BillingReconciliationAttempt $owner): array
    {
        $state = BillingCustomerReconciliation::query()->lockForUpdate()->findOrFail($owner->reconciliation_id);
        $attempt = BillingReconciliationAttempt::query()->where('reconciliation_id', $state->id)->whereKey($owner->id)->lockForUpdate()->firstOrFail();
        if (! hash_equals($attempt->owner_token, $owner->owner_token)) {
            throw new RuntimeException('Billing attempt owner does not match.');
        }

        return [$state, $attempt];
    }

    private function active(BillingCustomerReconciliation $state, BillingReconciliationAttempt $attempt): bool
    {
        return $state->status === 'processing' && $attempt->status === 'running' && $state->owner_token !== null
            && hash_equals($state->owner_token, $attempt->owner_token)
            && $state->lease_sequence === $attempt->lease_sequence
            && $state->generation === $attempt->generation && $state->lease_expires_at?->isFuture();
    }

    private function stale(BillingCustomerReconciliation $state, BillingReconciliationAttempt $attempt): void
    {
        if ($attempt->status === 'running') {
            $attempt->update(['status' => 'stale', 'completed_at' => BillingClock::now(), 'failure_category' => 'stale_observation']);
        }
        if ($state->owner_token !== null && hash_equals($state->owner_token, $attempt->owner_token)) {
            $state->update(['status' => $state->status === 'attention' ? 'attention' : 'pending', 'owner_token' => null,
                'lease_expires_at' => null, 'next_attempt_at' => $state->status === 'attention' ? null : BillingClock::now()]);
        }
    }

    private function scope(BillingCustomerReconciliation $state, BillingAccountContext $context): void
    {
        if ($state->account_id !== $context->accountId || $state->livemode !== $context->livemode) {
            throw new LogicException('Billing customer belongs to a different configured account or mode.');
        }
    }
}
