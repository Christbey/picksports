<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use LogicException;

final class BillingOperatorIntent
{
    public static function targets(BillingCustomerReconciliation $state, bool $current = true): array
    {
        if ($state->operator_request_id === null && $state->operator_generation === null && $state->requested_targets === null) {
            return [];
        }
        if ($state->operator_request_id === null || $state->operator_generation === null || ! is_array($state->requested_targets)
            || ! array_is_list($state->requested_targets) || count($state->requested_targets) > 8
            || $state->operator_generation > $state->generation) {
            throw new LogicException('Invalid billing operator intent tuple.');
        }
        $query = BillingReconciliationAttempt::query()->whereKey($state->operator_request_id);
        if ($current) {
            $query->lockForUpdate();
        }
        $intent = $query->first();
        if ($intent === null || (int) $intent->reconciliation_id !== (int) $state->id
            || $intent->generation !== $state->operator_generation || $intent->kind !== 'operator_request'
            || $intent->status !== 'requested' || $intent->targets !== $state->requested_targets) {
            throw new LogicException('Billing operator intent provenance does not match its customer.');
        }
        foreach ($state->requested_targets as $target) {
            if (! is_string($target) || preg_match('/\Asub_[A-Za-z0-9_]{1,187}\z/', $target) !== 1) {
                throw new LogicException('Invalid billing operator target.');
            }
        }
        if (count(array_unique($state->requested_targets, SORT_STRING)) !== count($state->requested_targets)) {
            throw new LogicException('Duplicate billing operator target.');
        }

        return $state->requested_targets;
    }
}
