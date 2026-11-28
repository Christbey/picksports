<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use Throwable;

/** Durable SQL-only recovery for an acknowledged mutation whose refresh handoff failed or predated its send. */
final class BillingFinancialRefreshRepair
{
    public function __construct(private readonly BillingOperatorAdmission $requests) {}

    public function repair(int $id): bool
    {
        $state = BillingMutationState::query()->find($id);
        if ($state?->active_intent_id === null || ! in_array($state->status, ['awaiting_reconciliation', 'payment_pending'], true)) {
            return false;
        }
        $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
        if ($intent->subject_user_id !== $state->subject_user_id || ($intent->requested_customer_id !== $state->customer_id
            && ! ($intent->requested_customer_id === null && BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'acknowledged_customer')->get()
                ->contains(fn ($row) => ($row->facts['customer_id'] ?? null) === $state->customer_id)))) {
            return false;
        }
        try {
            $context = new BillingAccountContext;
            if ($context->accountId !== $intent->account_id || $context->livemode !== $intent->livemode) {
                return false;
            }
            $reconciliation = BillingCustomerReconciliation::query()->where([...$context->scope(), 'customer_id' => $state->customer_id])->first();
            if ($reconciliation === null || $reconciliation->status === 'idle') {
                $plans = $this->requests->plan([], [$state->subject_user_id], []);
                $this->requests->admit($plans[0], 'Refresh acknowledged billing mutation '.$intent->operation_uuid);
            }

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            BillingMutationState::query()->whereKey($id)->where('active_intent_id', $intent->id)
                ->whereIn('status', ['awaiting_reconciliation', 'payment_pending'])
                ->update(['next_attempt_at' => BillingClock::now()->addMinute()->format('Y-m-d H:i:s.u'), 'updated_at' => BillingClock::now()->format('Y-m-d H:i:s.u')]);
        }
    }
}
