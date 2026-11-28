<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BillingMutationJournal
{
    private const FACTS = ['customer_id', 'checkout_session_id', 'subscription_id', 'desired_price_id', 'policy',
        'category', 'operation_uuid', 'requested_kind', 'remote_state', 'provider_request_id', 'payment_intent_id',
        'invoice_id', 'requires_action', 'prepared_request_hash'];

    public function record(BillingMutationState $state, BillingMutationIntent $intent, string $phase, array $facts = [],
        ?string $stepKey = null, ?string $payloadHash = null, ?int $causeReceipt = null): BillingMutationReceipt
    {
        if (DB::transactionLevel() === 0 || $state->active_intent_id !== (int) $intent->id
            || $state->subject_user_id !== $intent->subject_user_id || preg_match('/\A[a-z_]{1,50}\z/', $phase) !== 1
            || array_diff(array_keys($facts), self::FACTS) !== []) {
            throw new LogicException('Billing journal admission is invalid.');
        }
        foreach ($facts as $key => $value) {
            if ($key === 'requires_action' && $value !== null && ! is_bool($value)) {
                throw new LogicException('Billing action requirement must be boolean.');
            }
            if ($key !== 'requires_action' && $value !== null && (! is_string($value) || strlen($value) > 191 || preg_match('/[\x00-\x20\x7f]/', $value))) {
                throw new LogicException('Billing journal accepts bounded identifiers and categories only.');
            }
        }
        foreach ([$stepKey, $payloadHash] as $hash) {
            if ($hash !== null && preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw new LogicException('Billing journal hash is invalid.');
            }
        }
        $receipt = BillingMutationReceipt::query()->create(['state_id' => $state->id, 'intent_id' => $intent->id,
            'lease_sequence' => $state->lease_sequence, 'phase' => $phase, 'owner_token' => $state->owner_token,
            'provider_step_key' => $stepKey, 'payload_hash' => $payloadHash, 'cause_receipt_id' => $causeReceipt,
            'facts' => $facts, 'recorded_at' => BillingClock::now()]);
        $state->update(['last_receipt_id' => $receipt->id]);

        return $receipt;
    }
}
