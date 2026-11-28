<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\BillingReconciliationAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Honest target admission from an owned, mode-bound observed session; never invents a Stripe event. */
final class BillingObservedCheckoutAdmission
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal) {}

    public function admit(int $mutationId): bool
    {
        $this->database->assert();
        $candidate = BillingMutationState::query()->findOrFail($mutationId);
        $context = new BillingAccountContext;
        if (! is_string($candidate->customer_id)) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $context): bool {
            $key = [...$context->scope(), 'customer_id' => $candidate->customer_id];
            $state = BillingCustomerReconciliation::query()->where($key)->lockForUpdate()->first();
            $state ??= BillingCustomerReconciliation::query()->create($key)->refresh();
            $users = app(BillingCustomerIdentity::class)->users($state->customer_id);
            if ($users->count() !== 1 || (int) $users->first()->id !== $candidate->subject_user_id) {
                return false;
            }
            $user = $users->first();
            $mutation = BillingMutationState::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($mutation->subject_user_id !== (int) $user->id || $mutation->customer_id !== $user->stripe_id || $mutation->customer_id !== $state->customer_id
                || $state->account_id !== $context->accountId || $state->livemode !== $context->livemode
                || $mutation->active_intent_id === null || $mutation->status !== 'awaiting_checkout_refresh') {
                return false;
            }
            $intent = BillingMutationIntent::query()->findOrFail($mutation->active_intent_id);
            $observed = BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'checkout_observed')->orderByDesc('id')->firstOrFail();
            $facts = $observed->facts;
            if ($intent->kind !== 'checkout' || $intent->subject_user_id !== (int) $user->id || $intent->account_id !== $context->accountId
                || $intent->livemode !== $context->livemode || ($facts['customer_id'] ?? null) !== $user->stripe_id
                || ! is_string($facts['subscription_id'] ?? null) || preg_match('/\Asub_[A-Za-z0-9_]{1,187}\z/', $facts['subscription_id']) !== 1) {
                throw new LogicException('Observed checkout binding is invalid.');
            }
            if ($state->status === 'attention' || ($state->owner_token !== null && $state->lease_expires_at?->getTimestamp() > BillingClock::now()->getTimestamp())) {
                return false;
            }
            $targets = array_values(array_unique([...BillingOperatorIntent::targets($state), $facts['subscription_id']]));
            sort($targets, SORT_STRING);
            if (count($targets) > min(8, max(1, (int) config('billing.maximum_targets', 4)))) {
                return false;
            }
            if ($state->owner_token !== null) {
                $expired = BillingReconciliationAttempt::query()->where('reconciliation_id', $state->id)->where('owner_token', $state->owner_token)->lockForUpdate()->first();
                if ($expired?->status === 'running') {
                    $expired->update(['status' => 'stale', 'completed_at' => BillingClock::now(), 'failure_category' => 'observed_checkout_replaced_expired_lease']);
                }
            }
            $generation = $state->generation + 1;
            $request = BillingReconciliationAttempt::query()->create(['reconciliation_id' => $state->id,
                'generation' => $generation, 'lease_sequence' => $state->lease_sequence, 'owner_token' => (string) Str::uuid(),
                'kind' => 'operator_request', 'status' => 'requested', 'started_at' => BillingClock::now(), 'completed_at' => BillingClock::now(),
                'targets' => $targets, 'details' => ['actor' => 'financial_checkout', 'mutation_intent_id' => $intent->id,
                    'observed_receipt_id' => $observed->id, 'customer_id' => $state->customer_id, 'user_id' => $user->id]]);
            $state->update(['generation' => $generation, 'user_id' => $user->id, 'status' => 'pending', 'owner_token' => null,
                'lease_expires_at' => null, 'next_attempt_at' => BillingClock::now(), 'attempts' => 0, 'failure_category' => null,
                'operator_request_id' => $request->id, 'operator_generation' => $generation, 'requested_targets' => $targets]);
            $this->journal->record($mutation, $intent, 'checkout_refresh_admitted', ['subscription_id' => $facts['subscription_id']]);
            $mutation->update(['status' => 'awaiting_reconciliation', 'reconciliation_id' => $state->id, 'owner_token' => null,
                'lease_expires_at' => null, 'next_attempt_at' => BillingClock::now()->addMinute()]);

            return true;
        }, attempts: 3);
    }
}
