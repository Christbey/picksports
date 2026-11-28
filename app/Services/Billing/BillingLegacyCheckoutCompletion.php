<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Legacy compatibility evidence only: does not certify payment, ordering, mode or canonical projection. */
final class BillingLegacyCheckoutCompletion
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal) {}

    public function linked(User $lockedUser, array $event): void
    {
        $this->database->assert($lockedUser, false);
        if (DB::transactionLevel() === 0 || config('billing.durable.enabled', false)) {
            throw new \LogicException('Legacy linking requires its user-locked webhook transaction.');
        }
        $data = $event['data']['object'] ?? [];
        if (! is_string($event['id'] ?? null) || preg_match('/\Aevt_[A-Za-z0-9_]{1,187}\z/', $event['id']) !== 1
            || ($data['customer'] ?? null) !== $lockedUser->stripe_id || ! in_array($data['status'] ?? null, ['active', 'trialing'], true)
            || ! is_string($data['id'] ?? null) || preg_match('/\Asub_[A-Za-z0-9_]{1,187}\z/', $data['id']) !== 1) {
            return;
        }
        $state = BillingMutationState::query()->where('subject_user_id', $lockedUser->id)->lockForUpdate()->first();
        if ($state?->active_intent_id === null || $state->status !== 'session_open' || $state->customer_id !== $lockedUser->stripe_id) {
            return;
        }
        $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
        if ($intent->subject_user_id !== (int) $lockedUser->id || $intent->kind !== 'checkout_guard'
            || ($data['metadata']['picksports_operation'] ?? null) !== $intent->operation_uuid) {
            return;
        }
        $ack = BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'checkout_acknowledged')->first();
        if (($ack?->facts['customer_id'] ?? null) !== $state->customer_id || ! is_string($ack?->facts['checkout_session_id'] ?? null)) {
            return;
        }
        $subscription = $lockedUser->subscriptions()->where('stripe_id', $data['id'])->lockForUpdate()->first();
        if ($subscription === null || $subscription->stripe_id !== $data['id'] || $subscription->billing_projection_attempt_id !== null) {
            return;
        }
        $this->journal->record($state, $intent, 'legacy_subscription_linked', ['customer_id' => $state->customer_id,
            'checkout_session_id' => $ack->facts['checkout_session_id'], 'subscription_id' => $data['id'], 'provider_request_id' => $event['id'],
            'remote_state' => $data['status'], 'policy' => 'legacy_signed_subscription_link']);
        $state->update(['active_intent_id' => null, 'status' => 'completed', 'owner_token' => null, 'lease_expires_at' => null,
            'failure_category' => null, 'result' => null, 'result_expires_at' => null]);
    }
}
