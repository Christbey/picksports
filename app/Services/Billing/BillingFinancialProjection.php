<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Runs within the existing customer→User→mutation SQL boundary; price equality never proves a payment. */
final class BillingFinancialProjection
{
    public function __construct(private readonly BillingMutationJournal $journal) {}

    public function guard(User $user, array $observation): void
    {
        $tuple = $this->current($user, $observation);
        if ($tuple === null) {
            return;
        }
        [$state, $intent, $snapshot] = $tuple;
        if ($intent->kind === 'swap' && $snapshot !== null && ($snapshot['operation_uuid'] ?? null) === $intent->operation_uuid
            && ($snapshot['invoice_status'] ?? null) !== 'paid'
            && ! (is_int($snapshot['trial_end'] ?? null) && $snapshot['trial_end'] > BillingClock::now()->getTimestamp())) {
            throw new BillingProviderFailure('financial_payment_unconfirmed');
        }
    }

    public function complete(User $user, array $observation): void
    {
        $tuple = $this->current($user, $observation);
        if ($tuple === null) {
            return;
        }
        [$state, $intent, $snapshot] = $tuple;
        if ($snapshot === null || ($snapshot['operation_uuid'] ?? null) !== $intent->operation_uuid) {
            return;
        }
        $ack = BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', $intent->kind === 'checkout' ? 'checkout_observed' : 'acknowledged_'.$intent->kind)->orderByDesc('id')->first();
        if ($ack === null || ($ack->facts['subscription_id'] ?? null) !== $snapshot['id']) {
            return;
        }
        $matches = match ($intent->kind) {
            'checkout' => count($snapshot['items']) === 1 && $snapshot['items'][0]['price'] === $intent->desired_price_id
                && in_array($snapshot['status'], ['active', 'trialing'], true) && in_array($ack->facts['remote_state'] ?? null, ['paid', 'no_payment_required'], true),
            'cancel' => $snapshot['cancel_at_period_end'] === true || $snapshot['status'] === 'canceled',
            'resume' => $snapshot['cancel_at_period_end'] === false && in_array($snapshot['status'], ['active', 'trialing'], true),
            'swap' => count($snapshot['items']) === 1 && $snapshot['items'][0]['price'] === $intent->desired_price_id
                && (($snapshot['invoice_status'] ?? null) === 'paid' && ($ack->facts['invoice_id'] ?? null) === ($snapshot['invoice_id'] ?? null)
                    || is_int($snapshot['trial_end'] ?? null) && $snapshot['trial_end'] > BillingClock::now()->getTimestamp()),
            default => false,
        };
        if (! $matches) {
            return;
        }
        $this->journal->record($state, $intent, 'projection_confirmed', ['subscription_id' => $snapshot['id'],
            'invoice_id' => $snapshot['invoice_id'] ?? null, 'remote_state' => $snapshot['status']]);
        $state->update(['active_intent_id' => null, 'status' => 'completed', 'owner_token' => null, 'lease_expires_at' => null,
            'failure_category' => null, 'result' => ['intent_id' => $intent->id, 'kind' => $intent->kind],
            'result_expires_at' => BillingClock::now()->addMinutes(15)]);
    }

    private function current(User $user, array $observation): ?array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial outcome requires atomic projection.');
        }
        $state = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
        if ($state?->active_intent_id === null || ! in_array($state->status, ['payment_pending', 'awaiting_reconciliation'], true)) {
            return null;
        }
        $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
        if ($intent->subject_user_id !== (int) $user->id || $intent->account_id !== $observation['account_id']
            || $intent->livemode !== $observation['livemode'] || $state->customer_id !== $observation['customer_id']
            || ! in_array($intent->kind, ['cancel', 'resume', 'swap', 'checkout'], true)) {
            return null;
        }
        $subscriptionId = $intent->requested_subscription_id;
        if ($intent->kind === 'checkout') {
            $observed = BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'checkout_observed')->orderByDesc('id')->first();
            $subscriptionId = $observed?->facts['subscription_id'] ?? null;
        }
        $snapshot = collect($observation['subscriptions'])->first(fn ($row) => $row['id'] === $subscriptionId);

        return [$state, $intent, $snapshot];
    }
}
