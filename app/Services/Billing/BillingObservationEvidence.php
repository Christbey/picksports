<?php

namespace App\Services\Billing;

/** Minimal bounded normalized facts; provider bodies, metadata and headers never enter this ledger. */
final class BillingObservationEvidence
{
    public static function capture(array $observation): array
    {
        $result = [];
        foreach (['account_id', 'customer_id'] as $field) {
            $result[$field] = self::identifier($observation[$field] ?? null);
        }
        foreach (['livemode', 'customer_deleted'] as $field) {
            if (! is_bool($observation[$field] ?? null)) {
                throw new BillingProviderFailure('observation_evidence_invalid');
            }
            $result[$field] = $observation[$field];
        }
        foreach (['fetch_started_at', 'fetch_completed_at'] as $field) {
            $result[$field] = self::epoch($observation[$field] ?? null);
        }
        $rows = $observation['subscriptions'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 8) {
            throw new BillingProviderFailure('observation_evidence_invalid');
        }
        $result['subscriptions'] = [];
        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new BillingProviderFailure('observation_evidence_invalid');
            }
            $entry = [];
            foreach (['id', 'customer_id'] as $field) {
                $entry[$field] = self::identifier($row[$field] ?? null);
            }
            foreach (['type', 'status'] as $field) {
                $value = $row[$field] ?? null;
                if (! is_string($value) || strlen($value) > 1020 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $entry[$field] = $value;
            }
            foreach (['created', 'trial_end', 'cancel_at', 'canceled_at', 'ended_at'] as $field) {
                $entry[$field] = self::epoch($row[$field] ?? null);
            }
            foreach (['livemode', 'cancel_at_period_end'] as $field) {
                if (! is_bool($row[$field] ?? null)) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $entry[$field] = $row[$field];
            }
            if (! is_array($row['items'] ?? null) || ! array_is_list($row['items'])) {
                throw new BillingProviderFailure('observation_evidence_invalid');
            }
            $entry['items'] = [];
            foreach ($row['items'] as $item) {
                if (++$count > 100 || ! is_array($item)) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $value = [];
                foreach (['id', 'price', 'product'] as $field) {
                    $value[$field] = self::identifier($item[$field] ?? null);
                }
                $quantity = $item['quantity'] ?? null;
                if ($quantity !== null && (! is_int($quantity) || $quantity < 0 || $quantity > 2147483647)) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $value['quantity'] = $quantity;
                $value['current_period_end'] = self::epoch($item['current_period_end'] ?? null);
                $entry['items'][] = $value;
            }
            if (($row['operation_uuid'] ?? null) !== null) {
                if (! is_string($row['operation_uuid']) || preg_match('/\A[0-9a-f-]{36}\z/', $row['operation_uuid']) !== 1) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $entry['operation_uuid'] = $row['operation_uuid'];
                $entry['invoice_id'] = ($row['invoice_id'] ?? null) !== null ? self::identifier($row['invoice_id']) : null;
                if (($row['invoice_status'] ?? null) !== null && ! in_array($row['invoice_status'], ['draft', 'open', 'paid', 'void', 'uncollectible'], true)) {
                    throw new BillingProviderFailure('observation_evidence_invalid');
                }
                $entry['invoice_status'] = $row['invoice_status'] ?? null;
            }
            $result['subscriptions'][] = $entry;
        }
        if (($observation['customer_deleted'] ?? false) === true) {
            $proof = $observation['deletion_proof'] ?? [];
            $result['deletion_proof'] = ['event_id' => self::identifier($proof['event_id'] ?? null),
                'account_id' => self::identifier($proof['account_id'] ?? null), 'customer_id' => self::identifier($proof['customer_id'] ?? null),
                'livemode' => $result['livemode'], 'customer_object' => 'customer', 'deleted' => true];
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR, 16)) > 131072) {
            throw new BillingProviderFailure('observation_evidence_invalid');
        }

        return $result;
    }

    private static function identifier(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_]{1,191}\z/', $value) !== 1) {
            throw new BillingProviderFailure('observation_evidence_invalid');
        }

        return $value;
    }

    private static function epoch(mixed $value): ?int
    {
        if ($value !== null && (! is_int($value) || $value < 0 || $value > 253402300799)) {
            throw new BillingProviderFailure('observation_evidence_invalid');
        }

        return $value;
    }
}
